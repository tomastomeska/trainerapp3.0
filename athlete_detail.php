<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/online_training.php';
require_once __DIR__ . '/includes/payment_billing.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/health_questionnaire.php';
require_once __DIR__ . '/includes/calculators.php';
require_once __DIR__ . '/includes/athlete_analysis.php';

requireLogin();

$coachId   = getCurrentCoachId();
$athleteId = intParam($_GET, 'id');
$pdo       = getDB();
ensurePasswordAuditColumns($pdo);

// Ověření vlastnictví
$stmt = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ?');
$stmt->execute([$athleteId, $coachId]);
$athlete = $stmt->fetch();
$athleteAge = calculateAge($athlete['birth_date'] ?? null);

if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$healthStatus = healthQuestionnaireFetchStatus($pdo, $athleteId);
$healthLatestSubmission = healthQuestionnaireFetchLatestSubmission($pdo, $athleteId);
$healthUpdates = healthQuestionnaireFetchUpdatesForCoach($pdo, $athleteId, 8);
$newAthleteFilesCount = 0;
try {
    $newAthleteFilesStmt = $pdo->prepare('SELECT COUNT(*) FROM athlete_files WHERE athlete_id = ? AND coach_id = ? AND shared_with_coach = 1 AND coach_viewed_at IS NULL');
    $newAthleteFilesStmt->execute([$athleteId, $coachId]);
    $newAthleteFilesCount = (int)$newAthleteFilesStmt->fetchColumn();
} catch (Throwable $e) {
    $newAthleteFilesCount = 0;
}

// Kalkulačky – výsledky sdílené sportovcem
$calcCoachSettings = calculatorGetAthleteSettings($pdo, $athleteId);
$calcCoachResults = $calcCoachSettings['share_with_coach'] ? calculatorFetchResults($pdo, $athleteId, null, 50) : [];
$calcCoachUnseen = count(array_filter($calcCoachResults, static fn(array $row): bool => $row['coach_viewed_at'] === null));
$calcCoachVisible = $calcCoachResults !== [] || calculatorAppVisibleForUser('athlete', $athleteId);
$calcCoachHeight = ['height_cm' => null, 'source' => null];
$calcCoachTrend = [];
if ($calcCoachVisible && $calcCoachSettings['share_with_coach']) {
    $calcCoachHeight = calculatorFetchAthleteHeightCm($pdo, $athleteId);
    $calcCoachTrend = calculatorBmiTrend($athleteId, $calcCoachHeight['height_cm']);
}
if ($calcCoachUnseen > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    calculatorMarkSeenByCoach($pdo, $athleteId);
}

// Analýza sportovce – poslední viditelná analýza
$analysisEnabled = calculatorIsEnabled('analysis') && analysisEnsureSchema($pdo);
$analysisLatest = null;
$analysisCount = 0;
if ($analysisEnabled) {
    $analysisRows = analysisFilterForCoach($pdo, $athleteId, analysisFetchFinals($pdo, $athleteId, 50));
    $analysisLatest = $analysisRows[0] ?? null;
    $analysisCount = count($analysisRows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_online_subscription') {
        $notificationWarnings = [];
        try {
            $created = onlineTrainingCreateSubscription($pdo, (int)$coachId, $athleteId, (string)($_POST['subscription_price'] ?? ''), (string)($_POST['starts_on'] ?? ''), (string)($_POST['ends_on'] ?? ''));
        } catch (Throwable $e) {
            error_log('Create online subscription: ' . $e->getMessage());
            flash('danger', 'Předplatné se nepodařilo vytvořit: ' . $e->getMessage());
            redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
            exit;
        }
        try {
            if ($created['price'] > 0) {
                createAthleteNotification($athleteId, 'Výzva k platbě: online tréninky', $created['description'] . '. Výzvu na ' . number_format($created['amount'], 2, ',', ' ') . ' Kč najdete na hlavním panelu a případně také v sekci Platby.');
            } else {
                createAthleteNotification($athleteId, 'Online předplatné', $created['description'] . '. Předplatné je zdarma.');
            }
        } catch (Throwable $e) {
            error_log('Online subscription notification: ' . $e->getMessage());
            $notificationWarnings[] = 'Upozornění v aplikaci se nepodařilo odeslat.';
        }
        if ($created['price'] > 0) {
            try {
                $coachStmt = $pdo->prepare('SELECT name, bank_account FROM coaches WHERE id = ?');
                $coachStmt->execute([$coachId]);
                $coach = $coachStmt->fetch();
                $account = accountForSpd(strtoupper(preg_replace('/\s+/', '', (string)($coach['bank_account'] ?? ''))));
                $email = trim((string)($created['athlete']['email'] ?? ''));
                if ($account && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $subscriptionForNote = [
                        'starts_on' => (string)$created['starts_on'],
                        'ends_on' => (string)$created['ends_on'],
                    ];
                    $note = onlineTrainingSubscriptionPaymentNote(
                        $subscriptionForNote,
                        trim((string)($created['athlete']['first_name'] . ' ' . $created['athlete']['last_name']))
                    );
                    $spd = 'SPD*1.0*ACC:' . $account . '*CC:CZK*AM:' . number_format($created['amount'], 2, '.', '') . '*MSG:' . str_replace('*', ' ', $note);
                    $sent = sendPaymentRequestEmail($email, [
                        'athlete_name' => trim($created['athlete']['first_name'] . ' ' . $created['athlete']['last_name']),
                        'coach_name' => (string)($coach['name'] ?? 'Trenér'),
                        'month_label' => $created['description'],
                        'amount_text' => number_format($created['amount'], 2, ',', ' ') . ' Kč',
                        'account' => $account,
                        'note' => $note,
                        'sender_note' => $note,
                        'qr_url' => 'https://quickchart.io/qr?size=220&text=' . rawurlencode($spd),
                    ]);
                    if (!$sent) $notificationWarnings[] = 'E-mail s výzvou se nepodařilo odeslat.';
                } else {
                    $notificationWarnings[] = 'E-mail nelze odeslat bez platného e-mailu sportovce a bankovního účtu trenéra.';
                }
            } catch (Throwable $e) {
                error_log('Online subscription payment email: ' . $e->getMessage());
                $notificationWarnings[] = 'E-mail s výzvou se nepodařilo odeslat.';
            }
        }
        flash($notificationWarnings ? 'warning' : 'success', 'Online předplatné bylo vytvořeno. ' . implode(' ', $notificationWarnings));
        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }
    if ($action === 'cancel_online_subscription') {
        $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
        $cancelStmt = $pdo->prepare("UPDATE online_training_subscriptions SET status = 'cancelled' WHERE id = ? AND trainer_id = ? AND athlete_id = ? AND status = 'active'");
        $cancelStmt->execute([$subscriptionId, $coachId, $athleteId]);
        flash($cancelStmt->rowCount() === 1 ? 'success' : 'warning', $cancelStmt->rowCount() === 1 ? 'Čerpání předplatného bylo ukončeno. Účetní historie zůstala zachována.' : 'Aktivní předplatné nebylo nalezeno.');
        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }
    if ($action === 'save_weight' || $action === 'update_weight') {
        $weightInput = str_replace(',', '.', trim((string)($_POST['weight_kg'] ?? '')));
        $measuredAt = preg_replace('/[^0-9\-]/', '', (string)($_POST['measured_at'] ?? date('Y-m-d')));
        $weightKg = is_numeric($weightInput) ? (float)$weightInput : 0.0;
        $weightLogId = (int)($_POST['weight_log_id'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
            flash('danger', 'Zadejte platné datum vážení.');
        } elseif ($weightKg < 20 || $weightKg > 400) {
            flash('danger', 'Zadejte platnou hmotnost v kg.');
        } elseif ($action === 'update_weight' && $weightLogId <= 0) {
            flash('danger', 'Vybraný záznam váhy nebyl nalezen.');
        } else {
            if ($action === 'save_weight') {
                $existingWeight = getAthleteWeightLogForDate($athleteId, $measuredAt);
                if ($existingWeight !== null || addAthleteWeightLog($athleteId, $measuredAt, $weightKg, 'coach', $coachId, null) <= 0) {
                    flash('danger', 'Na ' . date('j. n. Y', strtotime($measuredAt)) . ' už sportovec váhu má. Znovu ji zadat nejde.');
                } else {
                    flash('success', 'Tělesná hmotnost byla uložena.');
                }
            } elseif (updateAthleteWeightLog($weightLogId, $athleteId, $measuredAt, $weightKg)) {
                flash('success', 'Záznam hmotnosti byl upraven.');
            } else {
                flash('danger', 'Záznam hmotnosti se nepodařilo upravit.');
            }
        }

        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    if ($action === 'delete_weight') {
        $weightLogId = (int)($_POST['weight_log_id'] ?? 0);

        if ($weightLogId <= 0) {
            flash('danger', 'Vybraný záznam váhy nebyl nalezen.');
        } elseif (deleteAthleteWeightLog($weightLogId, $athleteId)) {
            flash('success', 'Záznam hmotnosti byl smazán.');
        } else {
            flash('danger', 'Záznam hmotnosti se nepodařilo smazat.');
        }

        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    if ($action === 'send_weight_invite') {
        if (empty($athlete['email'])) {
            flash('danger', 'Sportovec nemá vyplněný e-mail.');
            redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
        }

        $invite = createAthleteWeightInvite($athleteId, $coachId, (string)$athlete['email'], 72);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $baseUrlAbsolute = $host !== '' ? ($scheme . '://' . $host . BASE_URL) : BASE_URL;
        $inviteUrl = rtrim($baseUrlAbsolute, '/') . '/athlete_weight_entry.php?token=' . urlencode((string)$invite['token']);

        $coach = getCurrentCoach();
        $coachName = ($coach['name'] ?? '') !== '' ? (string)$coach['name'] : (string)($coach['username'] ?? 'Váš trenér');
        $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);

        $sent = sendAthleteWeightInviteEmail(
            (string)$athlete['email'],
            $athleteName,
            $coachName,
            $inviteUrl,
            (string)$invite['expires_at']
        );

        if ($sent) {
            flash('success', 'Výzva k zadání hmotnosti byla odeslána na e-mail sportovce.');
        } else {
            flash('danger', 'E-mail s výzvou se nepodařilo odeslat.');
        }

        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    if ($action === 'create_login_credentials') {
        if (empty($athlete['email'])) {
            flash('danger', 'Sportovec nemá vyplněný e-mail.');
            redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
        }

        if (!filter_var((string)$athlete['email'], FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Sportovec má neplatný e-mail.');
            redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
        }

        $tempPassword = generateRandomPassword(12);
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

        $updLogin = $pdo->prepare(
            'UPDATE athletes
             SET password = ?,
                 password_changed_at = NOW(),
                 login_enabled = 1,
                 force_password_change = 1
             WHERE id = ? AND coach_id = ?'
        );
        $updLogin->execute([$passwordHash, $athleteId, $coachId]);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $loginUrl = $host !== ''
            ? ($scheme . '://' . $host . rtrim(BASE_URL, '/') . '/login.php')
            : (BASE_URL . '/login.php');

        $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
        $sent = sendAthleteWelcomeEmail((string)$athlete['email'], $athleteName, $tempPassword, $loginUrl);

        // Heslo zobrazíme vždy – při neúspěšném emailu (lokální testování) je to jediný způsob jak ho zjistit.
        $emailStatus = $sent ? ' E-mail byl odeslán.' : ' <strong>E-mail se nepodařilo odeslat</strong> – použij heslo níže.';
        flash('success', 'Přístup vytvořen.' . $emailStatus
            . '<br>Přihlašovací e-mail: <code>' . h((string)$athlete['email']) . '</code>'
            . '<br>Dočasné heslo: <code>' . h($tempPassword) . '</code>'
            . '<br><small class="text-muted">Sportovec bude po přihlášení vyzván ke změně hesla.</small>', true);

        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    if ($action === 'revoke_login_access') {
        $upd = $pdo->prepare(
            'UPDATE athletes
             SET login_enabled = 0,
                 password = NULL,
                 password_changed_at = NULL,
                 force_password_change = 1
             WHERE id = ? AND coach_id = ?'
        );
        $upd->execute([$athleteId, $coachId]);

        flash('success', 'Přístup sportovce do aplikace byl zrušen.');
        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    if ($action === 'send_message_to_athlete') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            flash('danger', 'Vyplňte prosím předmět i text zprávy.');
            redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
        }

        $subject = mb_substr($subject, 0, 200, 'UTF-8');
        $body = mb_substr($body, 0, 4000, 'UTF-8');
        createAthleteNotification($athleteId, $subject, $body);

        flash('success', 'Zpráva byla odeslána sportovci.');
        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    if ($action === 'ack_health_updates') {
        healthQuestionnaireMarkUpdatesSeen($pdo, $athleteId, $coachId);
        flash('success', 'Zdravotní změny byly označeny jako přečtené.');
        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId . '#health-questionnaire');
    }
}

// Tréninkové záznamy sportovce
$stmt = $pdo->prepare(
    'SELECT ts.*, ws.name AS set_name,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
     FROM training_sessions ts
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
    WHERE ts.athlete_id = ? AND ts.deleted_by_coach_at IS NULL
     ORDER BY ts.started_at DESC'
);
$stmt->execute([$athleteId]);
$sessions = $stmt->fetchAll();

$weightHistory = getAthleteWeightHistory($athleteId, 500);
usort($weightHistory, static function (array $a, array $b): int {
    $dateCompare = strcmp((string)($b['measured_at'] ?? ''), (string)($a['measured_at'] ?? ''));
    if ($dateCompare !== 0) {
        return $dateCompare;
    }

    return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
});
$weightStats = getAthleteWeightStats($athleteId);
$weightPreviewLimit = 5;
$weightVisibleRows = array_slice($weightHistory, 0, $weightPreviewLimit);
$weightCollapsedRows = array_slice($weightHistory, $weightPreviewLimit);

$editWeightLogId = intParam($_GET, 'edit_weight');
$editingWeightLog = $editWeightLogId > 0 ? getAthleteWeightLogById($editWeightLogId, $athleteId) : null;
$weightShouldExpandAll = $editingWeightLog !== null;
$weightFormAction = $editingWeightLog ? 'update_weight' : 'save_weight';
$weightFormDate = $editingWeightLog['measured_at'] ?? date('Y-m-d');
$weightFormValue = $editingWeightLog['weight_kg'] ?? ($weightStats['current_weight'] !== null ? (string)$weightStats['current_weight'] : '');

// Přidání aktuální váhy a indikace vývoje
$currentWeight = $weightStats['current_weight'] ?? null;
$initialWeight = $weightStats['initial_weight'] ?? null;
$lastUpdated = $weightStats['last_updated'] ?? null;
$weightTrend = null;

if ($currentWeight !== null && $initialWeight !== null) {
    if ($currentWeight > $initialWeight) {
        $weightTrend = 'up';
    } elseif ($currentWeight < $initialWeight) {
        $weightTrend = 'down';
    }
}

$weightWarning = null;
if ($lastUpdated !== null) {
    $daysSinceUpdate = (new DateTime())->diff(new DateTime($lastUpdated))->days;
    if ($daysSinceUpdate > 7) {
        $weightWarning = 'Váha nebyla aktualizována déle než 7 dní.';
    }
}

// Historie tréninků
$stmt = $pdo->prepare(
    'SELECT ts.*, ws.name AS set_name,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
     FROM training_sessions ts
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
    WHERE ts.athlete_id = ? AND ts.deleted_by_coach_at IS NULL
     ORDER BY ts.started_at DESC'
);
$stmt->execute([$athleteId]);
$sessions = $stmt->fetchAll();

$weightHistory = getAthleteWeightHistory($athleteId, 500);
usort($weightHistory, static function (array $a, array $b): int {
    $dateCompare = strcmp((string)($b['measured_at'] ?? ''), (string)($a['measured_at'] ?? ''));
    if ($dateCompare !== 0) {
        return $dateCompare;
    }

    return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
});
$weightStats = getAthleteWeightStats($athleteId);

// Poslední trénink
$lastSession = null;
foreach ($sessions as $s) {
    if ($s['completed_at']) { $lastSession = $s; break; }
}

$currentMonthKey = date('Y-m');
$currentMonthSessions = [];
$olderSessionsByMonth = [];
foreach ($sessions as $s) {
    $monthKey = date('Y-m', strtotime($s['started_at']));
    if ($monthKey === $currentMonthKey) {
        $currentMonthSessions[] = $s;
    } else {
        $olderSessionsByMonth[$monthKey][] = $s;
    }
}

$currentMonthPreviewLimit = 5;
$currentMonthVisibleSessions = array_slice($currentMonthSessions, 0, $currentMonthPreviewLimit);
$currentMonthCollapsedSessions = array_slice($currentMonthSessions, $currentMonthPreviewLimit);

// Sady dostupné pro trénink (pro dropdown "Spustit trénink")
ensureFlexibleWorkoutSet($coachId);
$activeSetFilter = workoutSetArchivingEnabled() ? ' AND ws.is_active = 1' : '';
$stmtSets = $pdo->prepare(
    'SELECT ws.*, COUNT(wse.id) AS exercise_count,
            GROUP_CONCAT(e.name ORDER BY wse.exercise_order SEPARATOR ", ") AS exercise_names
     FROM workout_sets ws
     LEFT JOIN workout_set_exercises wse ON ws.id = wse.workout_set_id
     LEFT JOIN exercises e ON e.id = wse.exercise_id
    WHERE ' . ((workoutSetsHasColumn('is_global') && workoutSetsHasColumn('description')) ? '(ws.coach_id = ? OR ws.is_global = 1)' : 'ws.coach_id = ?') . $activeSetFilter . '
     GROUP BY ws.id
      ORDER BY ' . ((workoutSetsHasColumn('is_global') && workoutSetsHasColumn('description')) ? 'ws.is_global ASC, ' : '') . 'ws.name'
);
$stmtSets->execute([$coachId]);
$workoutSets = $stmtSets->fetchAll();

$activeTrainingStmt = $pdo->prepare(
        'SELECT ts.id, ts.paired_session_id, ts.started_at
         FROM training_sessions ts
         WHERE ts.athlete_id = ?
             AND ts.completed_at IS NULL
             AND ts.deleted_by_coach_at IS NULL
         ORDER BY ts.started_at ASC
         LIMIT 1'
);
$activeTrainingStmt->execute([$athleteId]);
$activeTraining = $activeTrainingStmt->fetch() ?: null;
$onlineSubscriptionStmt = $pdo->prepare("SELECT s.*, p.status AS payment_status FROM online_training_subscriptions s LEFT JOIN online_training_billing b ON b.subscription_id = s.id LEFT JOIN athlete_monthly_payments p ON p.coach_id = s.trainer_id AND p.athlete_id = s.athlete_id AND p.billing_month = b.billing_month AND p.issue_number = b.issue_number WHERE s.trainer_id = ? AND s.athlete_id = ? ORDER BY s.purchased_at DESC, s.id DESC");
$onlineSubscriptionStmt->execute([$coachId, $athleteId]);
$onlineSubscriptions = $onlineSubscriptionStmt->fetchAll();
$activeOnlineSubscription = onlineTrainingActiveSubscription($pdo, (int)$coachId, $athleteId);
$futureOnlineSubscription = $activeOnlineSubscription ? null : onlineTrainingFuturePaidSubscription($pdo, (int)$coachId, $athleteId);

$athleteDisplayName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']) . ' (ID: ' . $athleteId . ')';
renderHeader(h($athleteDisplayName), true, true);
?>

<style>
    #athlete-detail-card-list {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: .75rem;
        align-items: start;
    }

    #athlete-detail-card-list .athlete-detail-order-group {
        display: contents;
    }

    #athlete-detail-card-list .athlete-detail-order-group > [class*="col-"] {
        display: contents;
    }

    #athlete-detail-card-list .athlete-detail-order-card {
        grid-column: 1 / -1;
        width: 100%;
        min-width: 0;
        margin: 0 !important;
    }

    #athlete-detail-card-list .athlete-detail-order-card--half {
        grid-column: 1 / -1;
    }

    #athlete-detail-card-list .athlete-detail-order-card--weight {
        grid-column: 1 / -1;
    }

    #athlete-detail-card-list .athlete-detail-order-card--chart {
        grid-column: 1 / -1;
    }

    #athlete-detail-page .btn:not(.btn-close) {
        min-height: 38px;
        padding: .4rem .8rem;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: linear-gradient(145deg, #fff 0%, #f8fafc 100%);
        color: #273449;
        font-weight: 600;
        box-shadow: 0 2px 6px rgba(15, 23, 42, .045);
        transition: background-color 160ms ease, border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
    }

    #athlete-detail-page .btn:not(.btn-close):hover,
    #athlete-detail-page .btn:not(.btn-close):focus-visible {
        border-color: #e8c34f;
        background: #fffaf0;
        color: #1f2937;
        box-shadow: 0 7px 16px rgba(120, 90, 0, .11);
        transform: translateY(-1px);
    }

    #athlete-detail-page .btn:not(.btn-close):focus-visible {
        outline: 3px solid rgba(232, 195, 79, .55);
        outline-offset: 2px;
    }

    #athlete-detail-page .btn:not(.btn-close):disabled,
    #athlete-detail-page .btn:not(.btn-close).disabled {
        border-color: #e2e8f0;
        background: #f1f5f9;
        color: #94a3b8;
        box-shadow: none;
        transform: none;
    }

    #athlete-detail-page .btn:not(.btn-close) i {
        color: #b78100;
    }

    #athlete-detail-page .card-header.athlete-detail-card-header {
        background: transparent;
        color: #273449;
        border: 0;
    }

    #athlete-detail-page .athlete-detail-card-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        width: 100%;
        min-height: 46px;
        padding: .45rem .8rem;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: linear-gradient(145deg, #fff 0%, #f8fafc 100%);
        color: #273449;
        font-weight: 600;
        text-align: left;
        box-shadow: 0 2px 6px rgba(15, 23, 42, .045);
        transition: background-color 160ms ease, border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
    }

    #athlete-detail-page #start-training .athlete-detail-card-toggle {
        border-color: #f0d2a7;
        background: #fff4e5;
    }

    #athlete-detail-page #start-training .athlete-detail-card-toggle:hover,
    #athlete-detail-page #start-training .athlete-detail-card-toggle:focus-visible {
        border-color: #e8c34f;
        background: #fffaf0;
    }

    #athlete-detail-page .athlete-detail-card-toggle:hover,
    #athlete-detail-page .athlete-detail-card-toggle:focus-visible {
        border-color: #e8c34f;
        background: #fffaf0;
        color: #1f2937;
        box-shadow: 0 7px 16px rgba(120, 90, 0, .11);
        transform: translateY(-1px);
    }

    #athlete-detail-page .athlete-detail-card-toggle:focus-visible {
        outline: 3px solid rgba(232, 195, 79, .55);
        outline-offset: 2px;
    }

    #athlete-detail-page .athlete-detail-card-toggle > span > i {
        color: #b78100;
    }

    #athlete-detail-page .card-header > .athlete-detail-card-toggle:not(:only-child) {
        width: auto;
        flex: 1 1 auto;
    }

    #athlete-detail-page .athlete-detail-card-toggle .fa-chevron-down {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border: 1px solid #efd477;
        border-radius: 50%;
        background: #fff3c9;
        color: #765900;
        line-height: 1;
        transition: transform .2s ease;
    }

    #athlete-detail-page .athlete-detail-card-toggle[aria-expanded="true"] .fa-chevron-down {
        transform: rotate(180deg);
    }

    .athlete-detail-card-header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        padding: .5rem .75rem;
    }

    @media (min-width: 768px) {
        #athlete-detail-page .page-header {
            align-items: center;
            flex-wrap: wrap;
        }

        #athlete-detail-page .page-header > .athlete-detail-actions {
            flex: 1 1 100%;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            width: 100%;
            margin-left: 0 !important;
        }

        #athlete-detail-page .athlete-detail-actions > a,
        #athlete-detail-page .athlete-detail-actions > form,
        #athlete-detail-page .athlete-detail-actions > form > .btn {
            width: auto;
        }

        #athlete-detail-page .athlete-detail-actions > a,
        #athlete-detail-page .athlete-detail-actions > form > .btn {
            text-align: center;
        }

        #athlete-detail-page .athlete-detail-actions > form {
            display: inline-flex !important;
        }
    }

    @media (max-width: 575.98px) {
        .athlete-detail-card-header-actions {
            width: 100%;
            padding-top: 0;
        }

        .athlete-detail-card-header-actions .btn {
            flex: 1 1 auto;
        }
    }
</style>

<div id="athlete-detail-page">
<div class="d-flex align-items-center mb-4 gap-3 page-header">
    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0 fw-bold"><?= h($athleteDisplayName) ?></h2>
    <?php if (!empty($athlete['online_only'])): ?><span class="badge bg-warning text-dark">Pouze ONLINE</span><?php endif; ?>
    <div class="ms-auto d-flex gap-2 flex-wrap athlete-detail-actions">
        <a href="<?= BASE_URL ?>/training_manual_add.php?athlete_id=<?= $athleteId ?>"
           class="btn btn-outline-warning btn-sm">
            <i class="fas fa-calendar-plus me-1"></i>Přidat minulý trénink
        </a>
        <a href="<?= BASE_URL ?>/graphs.php?athlete_id=<?= $athleteId ?>"
           class="btn btn-outline-info btn-sm">
            <i class="fas fa-chart-line me-1"></i>Grafy
        </a>
        <a href="<?= BASE_URL ?>/progress_report.php?athlete_id=<?= $athleteId ?>"
           class="btn btn-outline-success btn-sm">
            <i class="fas fa-file-alt me-1"></i>Zpráva o pokroku
        </a>
        <a href="<?= BASE_URL ?>/food_diary.php?athlete_id=<?= $athleteId ?>"
              class="btn btn-outline-primary btn-sm">
            <i class="fas fa-bowl-food me-1"></i>Strava
        </a>
                <a href="<?= BASE_URL ?>/athlete_files_coach.php?athlete_id=<?= $athleteId ?>"
                     class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-folder-open me-1"></i>Soubory<?php if ($newAthleteFilesCount > 0): ?> <span class="badge rounded-pill bg-danger ms-1"><?= $newAthleteFilesCount ?></span><?php endif; ?>
                </a>
        <?php if ($calcCoachVisible): ?>
        <a href="#calculator-results" class="btn btn-outline-dark btn-sm">
            <i class="fas fa-calculator me-1"></i>Kalkulačky<?php if ($calcCoachUnseen > 0): ?> <span class="badge rounded-pill bg-danger ms-1"><?= $calcCoachUnseen ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (calculatorAppVisibleForUser('coach', (int)$coachId) && calculatorIsEnabled('performance')): ?>
        <a href="<?= BASE_URL ?>/performance.php?athlete_id=<?= $athleteId ?>" class="btn btn-outline-dark btn-sm">Výkonnost a regenerace</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/athlete_edit.php?id=<?= $athleteId ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-edit me-1"></i>Upravit
        </a>
        <form method="post" action="<?= BASE_URL ?>/athlete_delete.php" class="d-inline athlete-detail-action-delete-form"
              onsubmit="return confirm('Opravdu smazat tohoto sportovce? Smažou se i všechny tréninky!')">
            <?= csrfField() ?>
            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm athlete-detail-action-delete">
                <i class="fas fa-trash me-1"></i>Smazat sportovce
            </button>
        </form>
    </div>
</div>

<div id="athlete-detail-card-list">
<div class="card border-0 shadow-sm mb-4 athlete-detail-order-card" id="start-training" style="order: 1">
    <div class="card-header athlete-detail-card-header p-0">
        <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailStartTraining" aria-expanded="false" aria-controls="athleteDetailStartTraining">
            <span><i class="fas fa-play me-2"></i>Spustit nový trénink</span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse" id="athleteDetailStartTraining">
        <div class="card-body">
            <?php
            $healthAlertClass = $healthStatus['state'] === 'ok'
                ? 'alert-success'
                : ($healthStatus['state'] === 'warning' ? 'alert-warning' : 'alert-danger');
            ?>
            <div class="alert <?= h($healthAlertClass) ?> py-2 mb-3">
                <div class="fw-semibold"><i class="fas fa-heart-pulse me-1"></i><?= h((string)$healthStatus['label']) ?></div>
                <?php if (!empty($healthStatus['filled_at'])): ?>
                <div class="small">Poslední vyplnění: <?= h(formatDateTime((string)$healthStatus['filled_at'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($healthStatus['alerts'])): ?>
                <div class="small mt-1">
                    <?php foreach ($healthStatus['alerts'] as $alert): ?>
                    <div>• <?= h((string)($alert['alert_text'] ?? 'Upozornění')) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($lastSession): ?>
            <div class="alert alert-light border mb-3 py-2">
                <small class="text-muted">Poslední trénink:</small><br>
                <strong><?= h($lastSession['set_name']) ?></strong>
                – <?= formatDateTime($lastSession['completed_at']) ?>
                <?php if ($lastSession['location']): ?>
                <span class="text-muted ms-2"><i class="fas fa-map-marker-alt"></i> <?= h($lastSession['location']) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($athlete['online_only'])): ?>
            <div class="alert alert-warning">Sportovec je pouze ONLINE. Nový klasický trénink nelze zahájit.<?php if ($activeTraining): ?> <a href="<?= BASE_URL ?>/<?= $activeTraining['paired_session_id'] ? 'training_paired_session.php?id=' . (int)$activeTraining['paired_session_id'] : 'training_session.php?id=' . (int)$activeTraining['id'] ?>">Pokračovat v rozpracovaném tréninku</a>.<?php endif; ?></div>
            <?php elseif (empty($workoutSets)): ?>
            <div class="alert alert-warning py-2 mb-0">
                <i class="fas fa-exclamation-triangle me-1"></i>
                Nejprve vytvořte <a href="<?= BASE_URL ?>/sady.php" class="alert-link">sady</a> a
                <a href="<?= BASE_URL ?>/exercises.php" class="alert-link">cviky</a>.
            </div>
            <?php else: ?>
            <form action="<?= BASE_URL ?>/training_start.php" method="post" id="startTrainingForm"<?= $activeTraining ? ' onsubmit="return openActiveTrainingWarning();"' : '' ?>>
                <?= csrfField() ?>
                <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="workout_set_id">Vyberte sadu</label>
                    <select name="workout_set_id" id="workout_set_id" class="form-select" required>
                        <option value="">– vyberte sadu –</option>
                        <?php foreach ($workoutSets as $ws): ?>
                        <?php
                            $namesPreview = '';
                            if (!empty($ws['exercise_names'])) {
                                $namesArr = array_filter(array_map('trim', explode(',', (string)$ws['exercise_names'])), fn($n) => $n !== '');
                                $previewCount = 4;
                                if (count($namesArr) > $previewCount) {
                                    $namesPreview = implode(', ', array_slice($namesArr, 0, $previewCount)) . ' a další ' . (count($namesArr) - $previewCount);
                                } else {
                                    $namesPreview = implode(', ', $namesArr);
                                }
                                if (mb_strlen($namesPreview) > 70) {
                                    $namesPreview = rtrim(mb_substr($namesPreview, 0, 70)) . '…';
                                }
                            }
                        ?>
                        <option value="<?= $ws['id'] ?>">
                            <?= !empty($ws['is_global']) ? 'Globální: ' : '' ?><?= h($ws['name']) ?>
                            (<?= $ws['exercise_count'] ?> <?= $ws['exercise_count'] === 1 ? 'cvik' : ($ws['exercise_count'] < 5 ? 'cviky' : 'cviků') ?>)
                            <?php if ($ws['name'] === 'Flexibilní sada'): ?>
                                – přidávání cviků během tréninku
                            <?php endif; ?>
                            <?php if ($namesPreview !== ''): ?>
                                – <?= h($namesPreview) ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-warning fw-bold">
                    <i class="fas fa-play me-1"></i>Zahájit trénink
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 athlete-detail-order-card" style="order: 4">
    <div class="card-header athlete-detail-card-header p-0">
        <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailIntake" aria-expanded="false" aria-controls="athleteDetailIntake">
            <span><i class="fas fa-clipboard-question me-2"></i>Vstupní dotazník</span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse" id="athleteDetailIntake">
        <div class="card-body d-flex justify-content-end">
            <a class="btn btn-outline-dark btn-sm" href="<?= BASE_URL ?>/intake_questionnaire.php?athlete_id=<?= $athleteId ?>">Zobrazit nebo vyplnit</a>
        </div>
    </div>
</div>

<?php if ($calcCoachVisible): ?>
<div class="card border-0 shadow-sm mb-4 athlete-detail-order-card" id="calculator-results" style="order: 8">
    <div class="card-header athlete-detail-card-header p-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailCalculatorResults" aria-expanded="false" aria-controls="athleteDetailCalculatorResults">
            <span>
                <i class="fas fa-calculator me-2"></i>Kalkulačky sportovce
                <?php if ($calcCoachUnseen > 0): ?><span class="badge bg-danger ms-1"><?= $calcCoachUnseen ?> <?= $calcCoachUnseen === 1 ? 'nový výsledek' : ($calcCoachUnseen < 5 ? 'nové výsledky' : 'nových výsledků') ?></span><?php endif; ?>
            </span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
        <?php if ($calcCoachSettings['share_with_coach'] && $calcCoachResults): ?>
        <div class="athlete-detail-card-header-actions">
            <a href="<?= BASE_URL ?>/calculators_print.php?type=report&amp;athlete_id=<?= $athleteId ?>&amp;print=1" target="_blank" rel="noopener" class="btn btn-outline-dark btn-sm fw-semibold"><i class="fas fa-print me-1"></i>Tisk zprávy</a>
        </div>
        <?php endif; ?>
    </div>
    <div class="collapse" id="athleteDetailCalculatorResults">
    <div class="card-body">
        <?php if (!$calcCoachSettings['share_with_coach']): ?>
            <div class="text-muted"><i class="fas fa-lock me-1"></i>Sportovec výsledky kalkulaček se svým trenérem nesdílí.</div>
        <?php elseif (!$calcCoachResults): ?>
            <div class="text-muted"><i class="fas fa-circle-info me-1"></i>Sportovec zatím neuložil žádný výsledek kalkulačky.</div>
        <?php else: ?>
            <?php
            $calcCoachLatestBmi = null;
            foreach ($calcCoachResults as $calcRow) {
                if ($calcRow['calculator_key'] === 'bmi') {
                    $calcCoachLatestBmi = $calcRow;
                    break;
                }
            }
            $calcToneClass = ['info' => 'bg-info text-dark', 'success' => 'bg-success', 'warning' => 'bg-warning text-dark', 'orange' => 'bg-warning text-dark', 'danger' => 'bg-danger', 'dark' => 'bg-dark'];
            $calcDefinitions = calculatorDefinitions();
            ?>
            <div class="row g-4">
                <div class="col-lg-4">
                    <?php if ($calcCoachLatestBmi): ?>
                    <div class="text-muted small text-uppercase fw-semibold">Poslední BMI</div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="display-6 fw-bold"><?= number_format((float)$calcCoachLatestBmi['result_value'], 2, ',', '') ?></span>
                        <span class="badge <?= h($calcToneClass[(string)($calcCoachLatestBmi['inputs']['category_tone'] ?? '')] ?? 'bg-secondary') ?>"><?= h((string)$calcCoachLatestBmi['result_label']) ?></span>
                    </div>
                    <div class="small text-muted mb-2"><?= h(calculatorResultSummary($calcCoachLatestBmi)) ?> · <?= h(date('j. n. Y', strtotime((string)$calcCoachLatestBmi['created_at']))) ?></div>
                    <?php endif; ?>
                    <div class="small text-muted">Výška: <strong><?= $calcCoachHeight['height_cm'] !== null ? number_format((float)$calcCoachHeight['height_cm'], 1, ',', '') . ' cm' : '–' ?></strong><?= $calcCoachHeight['source'] !== null ? ' (' . h((string)$calcCoachHeight['source']) . ')' : '' ?></div>
                    <div class="small text-muted mt-2"><i class="fas fa-dumbbell me-1"></i>BMI nerozlišuje svalovou a tukovou hmotu – u svalnatých sportovců může nadhodnocovat podíl tuku.</div>
                </div>
                <div class="col-lg-8">
                    <div class="text-muted small text-uppercase fw-semibold mb-1">Vývoj BMI z historie vážení</div>
                    <?php if (count($calcCoachTrend) >= 2): ?>
                        <?= calculatorTrendSvg($calcCoachTrend, ['theme' => 'light', 'band' => [18.5, 25.0], 'height' => 200]) ?>
                    <?php else: ?>
                        <div class="text-muted small">Pro graf jsou potřeba výška a alespoň dvě vážení.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Datum</th><th>Kalkulačka</th><th>Vstupy</th><th>Výsledek</th><th>Kategorie</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($calcCoachResults, 0, 10) as $calcRow): ?>
                        <tr class="<?= $calcRow['coach_viewed_at'] === null ? 'table-warning' : '' ?>">
                            <td><?= h(date('j. n. Y H:i', strtotime((string)$calcRow['created_at']))) ?><?php if ($calcRow['coach_viewed_at'] === null): ?> <span class="badge bg-danger">Nové</span><?php endif; ?></td>
                            <td><?= h($calcDefinitions[$calcRow['calculator_key']]['title'] ?? (string)$calcRow['calculator_key']) ?></td>
                            <td class="small text-muted"><?= h(calculatorResultSummary($calcRow)) ?></td>
                            <td class="fw-bold"><?= $calcRow['result_value'] !== null ? number_format((float)$calcRow['result_value'], 2, ',', '') : '–' ?></td>
                            <td><?= h((string)$calcRow['result_label']) ?></td>
                            <td class="text-end"><a href="<?= BASE_URL ?>/calculators_print.php?type=result&amp;id=<?= (int)$calcRow['id'] ?>&amp;athlete_id=<?= $athleteId ?>&amp;print=1" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm" title="Tisk protokolu"><i class="fas fa-print"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($calcCoachResults) > 10): ?><div class="small text-muted mt-2">Zobrazeno posledních 10 výsledků – celou historii obsahuje tisková zpráva.</div><?php endif; ?>
        <?php endif; ?>
    </div>
    </div>
</div>
<?php endif; ?>

<?php if ($analysisEnabled): ?>
<div class="card border-0 shadow-sm mb-4 athlete-detail-order-card" id="athlete-analysis" style="order: 13">
    <div class="card-header athlete-detail-card-header p-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailAnalysis" aria-expanded="false" aria-controls="athleteDetailAnalysis">
            <span><i class="fas fa-dna me-2"></i>Analýza sportovce<?php if ($analysisCount > 0): ?> <span class="badge bg-secondary ms-1"><?= $analysisCount ?></span><?php endif; ?></span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
        <div class="athlete-detail-card-header-actions">
            <?php if ($analysisLatest): ?>
            <a href="<?= BASE_URL ?>/athlete_analysis_print.php?id=<?= (int)$analysisLatest['id'] ?>&amp;athlete_id=<?= $athleteId ?>&amp;print=1" target="_blank" rel="noopener" class="btn btn-outline-dark btn-sm"><i class="fas fa-print me-1"></i>Tisk</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/athlete_analysis.php?athlete_id=<?= $athleteId ?>&amp;view=wizard" class="btn btn-outline-dark btn-sm fw-semibold"><i class="fas fa-plus me-1"></i>Nová analýza</a>
        </div>
    </div>
    <div class="collapse" id="athleteDetailAnalysis">
    <div class="card-body">
        <?php if (!$analysisLatest): ?>
            <div class="text-muted"><i class="fas fa-circle-info me-1"></i><?= $calcCoachSettings['share_with_coach'] ? 'Sportovec zatím nemá dokončenou analýzu.' : 'Sportovec výsledky nesdílí – vidíte jen analýzy vytvořené vámi.' ?> Analýzu můžete vyplnit i za něj.</div>
        <?php else: $al = $analysisLatest; $alr = $al['results']; ?>
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                <div>
                    <strong><?= h(analysisGoalShortLabels()[$al['inputs']['goal'] ?? ''] ?? 'Analýza') ?></strong>
                    <span class="text-muted small ms-1"><?= h(date('j. n. Y', strtotime($al['date']))) ?> · <?= ($al['created_by_type'] ?? '') === 'coach' ? 'vytvořil trenér' : 'vyplnil sportovec' ?></span>
                </div>
                <a href="<?= BASE_URL ?>/athlete_analysis.php?athlete_id=<?= $athleteId ?>&amp;view=result&amp;id=<?= (int)$al['id'] ?>#coach-review" class="btn btn-outline-dark btn-sm"><i class="fas fa-user-tie me-1"></i>Výsledky a poznámka trenéra</a>
            </div>
            <div class="row g-2 text-center">
                <?php foreach ([
                    ['Hmotnost', ($al['metrics']['weight_kg'] ?? null), 1, 'kg'],
                    ['Tuk', ($al['metrics']['body_fat_pct'] ?? null), 1, '%'],
                    ['BMR', ($al['metrics']['bmr'] ?? null), 0, 'kcal'],
                    ['TDEE', ($al['metrics']['tdee'] ?? null), 0, 'kcal'],
                    ['Příjem', ($al['metrics']['kcal'] ?? null), 0, 'kcal'],
                    ['B / S / T', null, 0, ''],
                ] as [$label, $value, $dec, $unit]): ?>
                <div class="col-6 col-md-2">
                    <div class="border rounded-3 p-2 h-100">
                        <div class="text-muted small text-uppercase fw-semibold"><?= h($label) ?></div>
                        <div class="fw-bold">
                            <?php if ($label === 'B / S / T'): ?>
                                <?= !empty($alr['macros']) ? (int)$alr['macros']['protein_g'] . ' / ' . (int)$alr['macros']['carbs_g'] . ' / ' . (int)$alr['macros']['fat_g'] . ' g' : '–' ?>
                            <?php else: ?>
                                <?= $value !== null ? number_format((float)$value, $dec, ',', ' ') . ' ' . h($unit) : '–' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($al['coach_note'])): ?><div class="alert alert-warning small mt-3 mb-0"><i class="fas fa-comment-dots me-1"></i><strong>Vaše poznámka:</strong> <?= nl2br(h((string)$al['coach_note'])) ?></div><?php endif; ?>
            <?php foreach (array_slice($alr['warnings'] ?? [], 0, 3) as $msg): ?><div class="small text-warning-emphasis mt-2"><i class="fas fa-triangle-exclamation me-1"></i><?= h($msg) ?></div><?php endforeach; ?>
            <div class="small mt-2"><a href="<?= BASE_URL ?>/athlete_analysis.php?athlete_id=<?= $athleteId ?>">Historie, grafy a porovnání období →</a></div>
        <?php endif; ?>
    </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4 athlete-detail-order-card" style="order: 5">
    <div class="card-header athlete-detail-card-header p-0">
        <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailOnlineTraining" aria-expanded="false" aria-controls="athleteDetailOnlineTraining">
            <span><i class="fas fa-laptop me-2"></i>Online tréninky a účtování</span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse" id="athleteDetailOnlineTraining">
    <div class="card-body">
        <p><?php if ($activeOnlineSubscription): ?>Platné předplatné do <?= h(formatDate($activeOnlineSubscription['ends_on'])) ?>.
        <?php elseif ($futureOnlineSubscription): ?><?= (float)$futureOnlineSubscription['price'] === 0.0 ? 'Bezplatné' : 'Uhrazené' ?> předplatné začne platit <?= h(formatDate($futureOnlineSubscription['starts_on'])) ?>. Online tréninky lze odesílat až od tohoto data.
        <?php else: ?>Sportovec nemá platné předplatné.<?php endif; ?></p>
        <p class="small text-muted">Po úhradě lze v období platnosti odesílat libovolný počet sad. Cena 0 Kč znamená bezplatné předplatné. <a href="<?= BASE_URL ?>/payments.php?month=<?= h(date('Y-m')) ?>">Aktuální platební výzvy</a></p>
        <form method="post" class="row g-2 align-items-end"><?= csrfField() ?><input type="hidden" name="action" value="create_online_subscription">
            <div class="col-sm-3"><label class="form-label">Cena</label><input name="subscription_price" type="number" min="0" max="99999999" step="0.01" class="form-control" required></div>
            <div class="col-sm-3"><label class="form-label">Platnost od</label><input name="starts_on" type="date" class="form-control" required></div>
            <div class="col-sm-3"><label class="form-label">Platnost do</label><input name="ends_on" type="date" class="form-control" required></div>
            <div class="col-sm-3"><button class="btn btn-warning w-100">Vytvořit předplatné</button></div>
        </form>
        <hr>
        <h6 class="mb-3">Historie předplatných</h6>
        <?php if (!$onlineSubscriptions): ?><div class="text-muted small">Sportovec zatím nemá žádné online předplatné.</div><?php else: ?><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Platnost</th><th>Cena</th><th>Úhrada / stav</th><th></th></tr></thead><tbody>
        <?php foreach ($onlineSubscriptions as $subscription): ?><tr><td><?= $subscription['starts_on'] ? h(formatDate($subscription['starts_on']) . ' – ' . formatDate($subscription['ends_on'])) : 'Historický balík (' . (int)$subscription['total_trainings'] . ' tréninků)' ?></td><td><?= number_format((float)$subscription['price'], 2, ',', ' ') ?> Kč</td><td><?= h(onlineTrainingSubscriptionPaymentLabel($subscription)) ?></td><td><?php if ($subscription['status'] === 'active' && $subscription['starts_on']): ?><form method="post" onsubmit="return confirm('Ukončit předplatné? Platební historie zůstane zachována.');"><?= csrfField() ?><input type="hidden" name="action" value="cancel_online_subscription"><input type="hidden" name="subscription_id" value="<?= (int)$subscription['id'] ?>"><button class="btn btn-sm btn-outline-danger">Ukončit</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div>
    </div>
</div>

<div class="row g-4 mb-4 athlete-detail-order-group">
    <!-- Info karta -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100 athlete-detail-order-card athlete-detail-order-card--half" style="order: 2">
            <div class="card-header athlete-detail-card-header p-0">
                <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailInformation" aria-expanded="false" aria-controls="athleteDetailInformation">
                    <span><i class="fas fa-user me-2"></i>Informace</span>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </button>
            </div>
            <div class="collapse" id="athleteDetailInformation">
            <div class="card-body">
                <?php $athletePhoto = photoUrl($athlete['photo'] ?? null, 'athletes'); ?>
                <div class="text-center mb-3">
                    <?php if ($athlete['photo']): ?>
                    <img src="<?= h($athletePhoto) ?>" alt="<?= h($athlete['first_name']) ?>"
                         class="rounded-circle" style="width:100px;height:100px;object-fit:cover;border:3px solid #ffc107;">
                    <?php else: ?>
                    <?php $initials = strtoupper(mb_substr($athlete['first_name'], 0, 1, 'UTF-8') . mb_substr($athlete['last_name'], 0, 1, 'UTF-8')); ?>
                    <div class="avatar-initials" title="<?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?>">
                        <?= $initials ?>
                    </div>
                    <?php endif; ?>
                </div>
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted fw-semibold" style="width:40%">Věk</td>
                        <td><?= $athleteAge !== null ? h((string)$athleteAge) . ' let' : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Datum narození</td>
                        <td><?= !empty($athlete['birth_date']) ? formatDate($athlete['birth_date']) : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">E-mail</td>
                        <td><?= $athlete['email'] ? '<a href="mailto:'.h($athlete['email']).'">'.h($athlete['email']).'</a>' : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Tel. kontakt</td>
                        <td><?= $athlete['phone_contact'] ? h($athlete['phone_contact']) : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Tréninků</td>
                        <td><span class="badge bg-warning text-dark"><?= count(array_filter($sessions, fn($s) => $s['completed_at'])) ?>×</span></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Počáteční váha</td>
                        <td>
                            <?php if ($weightStats['first_weight'] !== null): ?>
                                <?= number_format((float)$weightStats['first_weight'], 1, ',', '') ?> kg
                                <small class="text-muted d-block"><?= formatDate((string)$weightStats['first_date']) ?></small>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Aktuální váha</td>
                        <td>
                            <?php if ($weightStats['current_weight'] !== null): ?>
                                <?= number_format((float)$weightStats['current_weight'], 1, ',', '') ?> kg
                                <small class="text-muted d-block"><?= formatDate((string)$weightStats['current_date']) ?></small>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Pokrok od startu</td>
                        <td>
                            <?php if ($weightStats['change_kg'] !== null): ?>
                                <?php $delta = (float)$weightStats['change_kg']; ?>
                                <span class="badge <?= $delta <= 0 ? 'bg-success' : 'bg-danger' ?>">
                                    <?= $delta > 0 ? '+' : '' ?><?= number_format($delta, 1, ',', '') ?> kg
                                </span>
                                <?php if ($weightStats['change_percent'] !== null): ?>
                                    <small class="text-muted d-block"><?= $weightStats['change_percent'] > 0 ? '+' : '' ?><?= number_format((float)$weightStats['change_percent'], 1, ',', '') ?> %</small>
                                <?php endif; ?>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Přidán</td>
                        <td><?= formatDate($athlete['created_at']) ?></td>
                    </tr>
                    <?php if ($athlete['notes']): ?>
                    <tr>
                        <td class="text-muted fw-semibold">Poznámky</td>
                        <td><?= h($athlete['notes']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100 athlete-detail-order-card athlete-detail-order-card--half" style="order: 3">
            <div class="card-header athlete-detail-card-header p-0">
                <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailAppAccess" aria-expanded="false" aria-controls="athleteDetailAppAccess">
                    <span><i class="fas fa-key me-2"></i>Přístup do aplikace</span>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </button>
            </div>
            <div class="collapse" id="athleteDetailAppAccess">
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-3">
                        <tbody>
                            <tr>
                                <td class="text-muted fw-semibold">Stav přístupu</td>
                                <td><?= !empty($athlete['login_enabled']) ? '<span class="badge bg-success">Aktivní</span>' : '<span class="badge bg-secondary">Nevytvořen</span>' ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-semibold">Heslo</td>
                                <td>
                                    <?php if (!empty($athlete['password'])): ?>
                                        <span class="badge bg-success">Nastaveno</span>
                                        <?php if (!empty($athlete['force_password_change'])): ?>
                                            <span class="badge bg-warning text-dark ms-1">Vyžaduje změnu</span>
                                        <?php endif; ?>
                                        <small class="text-muted d-block">Poslední změna: <?= !empty($athlete['password_changed_at']) ? h(formatDateTime((string)$athlete['password_changed_at'])) : formatDate($athlete['created_at']) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Bez hesla</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if (!empty($athlete['email'])): ?>
                    <form method="post" class="mb-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_login_credentials">
                        <button type="submit" class="btn btn-outline-dark w-100"
                                onclick="return confirm('Vygenerovat nové přístupové heslo a zobrazit ho zde? (E-mail odeslán jen pokud SMTP funguje.)')">
                            <i class="fas fa-key me-1"></i>
                            <?= !empty($athlete['login_enabled']) ? 'Resetovat heslo sportovce' : 'Vytvořit přístup sportovce' ?>
                        </button>
                        <small class="text-muted d-block mt-2">Login = e-mail sportovce. Heslo se zobrazí na obrazovce i při nefunkčním SMTP.</small>
                    </form>

                    <?php if (!empty($athlete['login_enabled'])): ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="revoke_login_access">
                        <button type="submit" class="btn btn-outline-secondary w-100"
                                onclick="return confirm('Opravdu zrušit přístup tohoto sportovce do aplikace?')">
                            <i class="fas fa-ban me-1"></i>Zrušit přístup sportovce
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="alert alert-light border mb-0">Pro vytvoření přístupu je nejprve potřeba doplnit e-mail sportovce.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100 athlete-detail-order-card" style="order: 6">
            <div class="card-header athlete-detail-card-header p-0">
                <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailCommunication" aria-expanded="false" aria-controls="athleteDetailCommunication">
                    <span><i class="fas fa-comments me-2"></i>Komunikace se sportovcem</span>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </button>
            </div>
            <div class="collapse" id="athleteDetailCommunication">
                <div class="card-body">
                    <?php if (!empty($athlete['email'])): ?>
                    <form method="post" class="mb-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="send_weight_invite">
                        <button type="submit" class="btn btn-outline-dark w-100">
                            <i class="fas fa-envelope me-1"></i>Odeslat výzvu k zadání váhy
                        </button>
                        <small class="text-muted d-block mt-2">Sportovec obdrží e-mail s bezpečným odkazem pro jednorázové zadání.</small>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-light border">Sportovec nemá vyplněný e-mail, výzvu nelze odeslat.</div>
                    <?php endif; ?>

                    <button type="button" class="btn btn-outline-dark w-100 mb-2" data-bs-toggle="modal" data-bs-target="#messageAthleteModal">
                        <i class="fas fa-paper-plane me-1"></i>Napsat sportovci
                    </button>
                    <a href="<?= BASE_URL ?>/athlete_chat.php?id=<?= (int)$athlete['id'] ?>" class="btn btn-outline-dark w-100">
                        <i class="fas fa-comments me-1"></i>Chat se sportovcem
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($activeTraining): ?>
<div class="modal fade" id="activeTrainingWarningModal" tabindex="-1" aria-labelledby="activeTrainingWarningModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="activeTrainingWarningModalLabel">
                    <i class="fas fa-triangle-exclamation me-2"></i>Neukončený trénink
                </h5>
            </div>
            <div class="modal-body">
                <p class="mb-2">Sportovec <strong><?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?></strong> má stále neukončený trénink.</p>
                <p class="mb-0 text-muted">Byl zahájen <?= h(formatDateTime((string)$activeTraining['started_at'])) ?>. Nejprve ho ukončete, teprve potom lze zahájit další.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zpět</button>
                <a href="<?= BASE_URL ?>/<?= $activeTraining['paired_session_id'] ? 'training_paired_session.php?id=' . (int)$activeTraining['paired_session_id'] : 'training_session.php?id=' . (int)$activeTraining['id'] ?>" class="btn btn-danger fw-bold">
                    <i class="fas fa-flag-checkered me-1"></i>Přejít k ukončení
                </a>
            </div>
        </div>
    </div>
</div>
<script>
function openActiveTrainingWarning() {
    var modalElement = document.getElementById('activeTrainingWarningModal');
    if (!modalElement || typeof bootstrap === 'undefined') return true;
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
    return false;
}
</script>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4 athlete-detail-order-card" id="health-questionnaire" style="order: 7">
    <div class="card-header athlete-detail-card-header p-0">
        <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailHealthQuestionnaire" aria-expanded="false" aria-controls="athleteDetailHealthQuestionnaire">
            <span><i class="fas fa-heart-pulse me-2"></i>Zdravotní dotazník sportovce</span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse" id="athleteDetailHealthQuestionnaire">
    <div class="card-body">
        <div class="d-flex justify-content-end mb-3">
            <a href="<?= BASE_URL ?>/health_questionnaire_print.php?athlete_id=<?= (int)$athleteId ?>" target="_blank" rel="noopener" class="btn btn-outline-dark btn-sm">
                <i class="fas fa-print me-1"></i>Ukázat dotazník k tisku
            </a>
        </div>

        <?php if ($healthLatestSubmission): ?>
        <div class="mb-3">
            <div class="small text-muted">Poslední odeslání: <?= h(formatDateTime((string)$healthLatestSubmission['submitted_at'])) ?></div>
            <div class="small text-muted">Počet upozornění: <strong><?= (int)($healthLatestSubmission['alert_count'] ?? 0) ?></strong></div>
        </div>
        <?php if (!empty($healthLatestSubmission['alerts'])): ?>
        <div class="alert alert-warning py-2">
            <div class="fw-semibold mb-1">Aktivní upozornění</div>
            <?php foreach ($healthLatestSubmission['alerts'] as $alert): ?>
            <div>• <?= h((string)($alert['alert_text'] ?? 'Upozornění')) ?></div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-success py-2 mb-3">Dotazník je vyplněný a bez rizikových odpovědí.</div>
        <?php endif; ?>
        <?php else: ?>
        <div class="alert alert-danger py-2 mb-3">Sportovec zatím zdravotní dotazník nevyplnil.</div>
        <?php endif; ?>

        <?php if (!empty($healthUpdates)): ?>
        <h6 class="mb-2">Nahlášené změny zdravotního stavu</h6>
        <div class="table-responsive mb-3">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Typ</th>
                        <th>Důležitost</th>
                        <th>Detail</th>
                        <th>Stav</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($healthUpdates as $update): ?>
                    <tr>
                        <td><?= h(formatDateTime((string)$update['created_at'])) ?></td>
                        <td><?= h((string)$update['change_category']) ?></td>
                        <td>
                            <?php
                            $severity = (string)($update['severity'] ?? 'warning');
                            $severityClass = $severity === 'critical' ? 'danger' : ($severity === 'info' ? 'secondary' : 'warning');
                            ?>
                            <span class="badge bg-<?= h($severityClass) ?><?= $severityClass === 'warning' ? ' text-dark' : '' ?>"><?= h($severity) ?></span>
                        </td>
                        <td><?= nl2br(h((string)$update['change_details'])) ?></td>
                        <td>
                            <?php if (!empty($update['coach_seen_at'])): ?>
                            <span class="badge bg-success">Přečteno</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Nové</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ack_health_updates">
            <button type="submit" class="btn btn-outline-success btn-sm">
                <i class="fas fa-check me-1"></i>Označit změny jako přečtené
            </button>
        </form>
        <?php else: ?>
        <div class="text-muted">Sportovec zatím nenahlásil žádné průběžné změny zdravotního stavu.</div>
        <?php endif; ?>
    </div>
    </div>
</div>

<div class="row g-4 mb-4 athlete-detail-order-group">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100 athlete-detail-order-card athlete-detail-order-card--weight" id="weight-entry" style="order: 9">
            <div class="card-header athlete-detail-card-header p-0">
                <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailWeightInput" aria-expanded="<?= $editingWeightLog ? 'true' : 'false' ?>" aria-controls="athleteDetailWeightInput">
                    <span><i class="fas fa-weight-scale me-2"></i>Tělesná hmotnost</span>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </button>
            </div>
            <div class="collapse<?= $editingWeightLog ? ' show' : '' ?>" id="athleteDetailWeightInput">
            <div class="card-body">
                <form method="post" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= h($weightFormAction) ?>">
                    <?php if ($editingWeightLog): ?>
                    <input type="hidden" name="weight_log_id" value="<?= (int)$editingWeightLog['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" for="measured_at">Datum vážení</label>
                        <input type="date" id="measured_at" name="measured_at" class="form-control"
                               value="<?= h((string)$weightFormDate) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="weight_kg"><?= $editingWeightLog ? 'Upravit váhu (kg)' : 'Aktuální váha (kg)' ?></label>
                        <input type="number" id="weight_kg" name="weight_kg" class="form-control"
                               min="20" max="400" step="0.1"
                               value="<?= h((string)$weightFormValue) ?>"
                               placeholder="např. 78.4" required>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">
                            <i class="fas fa-save me-1"></i><?= $editingWeightLog ? 'Uložit změny' : 'Uložit váhu' ?>
                        </button>
                        <?php if ($editingWeightLog): ?>
                        <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $athleteId ?>#weight-history" class="btn btn-outline-secondary">
                            Zrušit
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100 athlete-detail-order-card athlete-detail-order-card--chart" style="order: 10">
            <div class="card-header athlete-detail-card-header p-0">
                <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailWeightChart" aria-expanded="false" aria-controls="athleteDetailWeightChart">
                    <span><i class="fas fa-chart-line me-2"></i>Vývoj hmotnosti <span class="badge bg-white text-dark border ms-1"><?= (int)$weightStats['entries'] ?> záznamů</span></span>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </button>
            </div>
            <div class="collapse" id="athleteDetailWeightChart">
            <div class="card-body">
                <?php if (empty($weightHistory)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-chart-area fa-2x mb-2 d-block"></i>
                    Zatím nejsou evidované žádné záznamy hmotnosti.
                </div>
                <?php else: ?>
                <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
                    <div>
                        <label for="coachWeightRangeFilter" class="form-label fw-semibold mb-1">Období</label>
                        <select id="coachWeightRangeFilter" class="form-select form-select-sm">
                            <option value="week">Týden</option>
                            <option value="month" selected>1 měsíc</option>
                            <option value="quarter">Čtvrtletí</option>
                            <option value="year">Rok</option>
                            <option value="all">Vše</option>
                        </select>
                    </div>
                    <div class="ms-md-auto">
                        <span class="text-muted fw-semibold me-2">Trend:</span>
                        <span id="coachWeightTrendBadge" class="badge bg-secondary">-</span>
                        <span id="coachWeightTrendValue" class="ms-2 text-muted"></span>
                    </div>
                </div>
                <canvas id="athleteWeightChart" style="max-height:340px"></canvas>
                <?php endif; ?>
            </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="messageAthleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send_message_to_athlete">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-paper-plane me-2 text-warning"></i>Nová zpráva sportovci</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="athlete_message_subject">Předmět</label>
                        <input type="text" id="athlete_message_subject" class="form-control" name="subject" maxlength="200" required placeholder="Např. Úprava tréninku na příští týden">
                    </div>
                    <div>
                        <label class="form-label fw-semibold" for="athlete_message_body">Zpráva</label>
                        <textarea id="athlete_message_body" class="form-control" name="body" rows="5" maxlength="4000" required placeholder="Sem napište text zprávy pro sportovce..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-paper-plane me-1"></i>Odeslat zprávu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 athlete-detail-order-card" id="weight-history" style="order: 11">
    <div class="card-header athlete-detail-card-header p-0">
        <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailWeightHistory" aria-expanded="false" aria-controls="athleteDetailWeightHistory">
            <span><i class="fas fa-clock-rotate-left me-2"></i>Historie hmotnosti <span class="badge bg-white text-dark border ms-1"><?= count($weightHistory) ?> záznamů</span></span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse" id="athleteDetailWeightHistory">
    <div class="card-body p-0">
        <?php if (empty($weightHistory)): ?>
        <div class="text-center text-muted py-4">Zatím nejsou evidované žádné záznamy hmotnosti.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Datum</th>
                        <th>Hmotnost</th>
                        <th>Zdroj</th>
                        <th class="text-end">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($weightVisibleRows as $weightRow): ?>
                    <?php
                        $sourceLabel = 'Ruční záznam';
                        if (($weightRow['source'] ?? '') === 'coach') {
                            $sourceLabel = 'Trenér';
                        } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                            $sourceLabel = 'Sportovec';
                        }
                    ?>
                    <tr class="<?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'table-warning' : '' ?>">
                        <td><?= formatDate((string)$weightRow['measured_at']) ?></td>
                        <td><strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></td>
                        <td><span class="badge bg-secondary"><?= h($sourceLabel) ?></span></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $athleteId ?>&edit_weight=<?= (int)$weightRow['id'] ?>#weight-entry" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-pen me-1"></i>Upravit
                            </a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_weight">
                                <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-trash me-1"></i>Smazat
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if (!empty($weightCollapsedRows)): ?>
                <tbody id="coachWeightHistoryCollapse" class="collapse <?= $weightShouldExpandAll ? 'show' : '' ?>">
                    <?php foreach ($weightCollapsedRows as $weightRow): ?>
                    <?php
                        $sourceLabel = 'Ruční záznam';
                        if (($weightRow['source'] ?? '') === 'coach') {
                            $sourceLabel = 'Trenér';
                        } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                            $sourceLabel = 'Sportovec';
                        }
                    ?>
                    <tr class="<?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'table-warning' : '' ?>">
                        <td><?= formatDate((string)$weightRow['measured_at']) ?></td>
                        <td><strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></td>
                        <td><span class="badge bg-secondary"><?= h($sourceLabel) ?></span></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $athleteId ?>&edit_weight=<?= (int)$weightRow['id'] ?>#weight-entry" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-pen me-1"></i>Upravit
                            </a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_weight">
                                <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-trash me-1"></i>Smazat
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($weightCollapsedRows)): ?>
        <div class="border-top p-3 text-center bg-light">
            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#coachWeightHistoryCollapse" aria-expanded="<?= $weightShouldExpandAll ? 'true' : 'false' ?>" aria-controls="coachWeightHistoryCollapse">
                <i class="fas fa-chevron-down me-1"></i>
                <?= $weightShouldExpandAll ? 'Skrýt starší záznamy' : 'Zobrazit starší záznamy (' . count($weightCollapsedRows) . ')' ?>
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    </div>
</div>

<!-- Historie tréninků -->
<div class="card border-0 shadow-sm athlete-detail-order-card" id="training-history" style="order: 12">
    <div class="card-header athlete-detail-card-header p-0">
        <button class="athlete-detail-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#athleteDetailTrainingHistory" aria-expanded="false" aria-controls="athleteDetailTrainingHistory">
            <span><i class="fas fa-history me-2"></i>Historie tréninků <span class="badge bg-white text-dark border ms-1"><?= count($sessions) ?></span></span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse" id="athleteDetailTrainingHistory">
    <div class="card-body p-0">
        <?php if (empty($sessions)): ?>
        <div class="text-center py-4 text-muted">
            <i class="fas fa-inbox fa-2x mb-2"></i><br>Zatím žádné tréninky.
        </div>
        <?php else: ?>
        <div class="d-flex justify-content-end gap-2 p-3 border-bottom">
            <button type="button" class="btn btn-outline-dark btn-sm" id="toggleSelectTrainings">
                <i class="fas fa-check-square me-1"></i>Označit vše
            </button>
            <button type="submit" form="bulkDeleteTrainingsForm" class="btn btn-outline-dark btn-sm" id="bulkDeleteBtn" disabled>
                <i class="fas fa-trash me-1"></i>Smazat vybrané
            </button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/training_delete.php" id="bulkDeleteTrainingsForm" onsubmit="return confirmBulkDeleteTrainings();">
            <?= csrfField() ?>
            <input type="hidden" name="redirect_to" value="<?= h(BASE_URL . '/athlete_detail.php?id=' . $athleteId) ?>">

            <div class="p-3 border-bottom bg-light">
                <strong>Aktuální měsíc (<?= date('m/Y') ?>)</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle table-sessions" id="currentMonthTrainingsTable">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:42px;">
                                <input class="form-check-input" type="checkbox" id="checkAllCurrent">
                            </th>
                            <th>Datum</th>
                            <th>Sada</th>
                            <th>Místo</th>
                            <th class="text-center">Sérií</th>
                            <th>Stav</th>
                            <th class="text-center" title="Fotografie"><i class="fas fa-camera"></i></th>
                            <th class="text-end">Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($currentMonthSessions)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">V aktuálním měsíci zatím nejsou žádné tréninky.</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($currentMonthVisibleSessions as $s): ?>
                        <tr>
                            <td class="text-center">
                                <input class="form-check-input training-bulk-check" type="checkbox" name="session_ids[]" value="<?= (int)$s['id'] ?>">
                            </td>
                            <td>
                                <strong><?= formatDate($s['started_at']) ?></strong>
                                <br><small class="text-muted"><?= date('H:i', strtotime($s['started_at'])) ?></small>
                            </td>
                            <td><span class="badge bg-secondary fs-6"><?= h($s['set_name']) ?></span></td>
                            <td class="text-muted"><?= $s['location'] ? h($s['location']) : '–' ?></td>
                            <td class="text-center"><?= $s['total_series'] ?></td>
                            <td>
                                <?php if ($s['completed_at']): ?>
                                <span class="badge bg-success">Dokončeno</span>
                                <?php else: ?>
                                <span class="badge bg-warning text-dark">Probíhá</span>
                                <?php endif; ?>
                                <?php if ($s['paired_session_id']): ?>
                                <span class="badge bg-info text-dark mt-1">
                                    <i class="fas fa-people-group me-1"></i>Párový
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if (!empty($s['training_photo'])): ?>
                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>#training-photo"
                                   title="Zobrazit fotografii">
                                    <img src="<?= h(photoUrl($s['training_photo'], 'trainings')) ?>"
                                         alt="foto"
                                         style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid #ffc107">
                                </a>
                                <?php else: ?>
                                <span class="text-muted">–</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (!$s['completed_at']): ?>
                                <a href="<?= BASE_URL ?>/training_session.php?id=<?= $s['id'] ?>"
                                   class="btn btn-warning btn-sm">
                                    <i class="fas fa-play me-1"></i>Pokračovat
                                </a>
                                <?php else: ?>
                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>"
                                   class="btn btn-outline-dark btn-sm">
                                    <i class="fas fa-eye me-1"></i>Detail
                                </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        onclick="deleteSingleTraining(<?= (int)$s['id'] ?>)" title="Smazat trénink">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($currentMonthCollapsedSessions)): ?>
                    <tbody id="coachCurrentMonthTrainingsCollapse" class="collapse">
                        <?php foreach ($currentMonthCollapsedSessions as $s): ?>
                        <tr>
                            <td class="text-center">
                                <input class="form-check-input training-bulk-check" type="checkbox" name="session_ids[]" value="<?= (int)$s['id'] ?>">
                            </td>
                            <td>
                                <strong><?= formatDate($s['started_at']) ?></strong>
                                <br><small class="text-muted"><?= date('H:i', strtotime($s['started_at'])) ?></small>
                            </td>
                            <td><span class="badge bg-secondary fs-6"><?= h($s['set_name']) ?></span></td>
                            <td class="text-muted"><?= $s['location'] ? h($s['location']) : '–' ?></td>
                            <td class="text-center"><?= $s['total_series'] ?></td>
                            <td>
                                <?php if ($s['completed_at']): ?>
                                <span class="badge bg-success">Dokončeno</span>
                                <?php else: ?>
                                <span class="badge bg-warning text-dark">Probíhá</span>
                                <?php endif; ?>
                                <?php if ($s['paired_session_id']): ?>
                                <span class="badge bg-info text-dark mt-1">
                                    <i class="fas fa-people-group me-1"></i>Párový
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if (!empty($s['training_photo'])): ?>
                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>#training-photo"
                                   title="Zobrazit fotografii">
                                    <img src="<?= h(photoUrl($s['training_photo'], 'trainings')) ?>"
                                         alt="foto"
                                         style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid #ffc107">
                                </a>
                                <?php else: ?>
                                <span class="text-muted">–</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (!$s['completed_at']): ?>
                                <a href="<?= BASE_URL ?>/training_session.php?id=<?= $s['id'] ?>"
                                   class="btn btn-warning btn-sm">
                                    <i class="fas fa-play me-1"></i>Pokračovat
                                </a>
                                <?php else: ?>
                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>"
                                   class="btn btn-outline-dark btn-sm">
                                    <i class="fas fa-eye me-1"></i>Detail
                                </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        onclick="deleteSingleTraining(<?= (int)$s['id'] ?>)" title="Smazat trénink">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>
                </table>
            </div>

            <?php if (!empty($currentMonthCollapsedSessions)): ?>
            <div class="border-top p-3 text-center bg-light">
                <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#coachCurrentMonthTrainingsCollapse" aria-expanded="false" aria-controls="coachCurrentMonthTrainingsCollapse">
                    <i class="fas fa-chevron-down me-1"></i>
                    Zobrazit starší tréninky (<?= count($currentMonthCollapsedSessions) ?>)
                </button>
            </div>
            <?php endif; ?>

            <?php if (!empty($olderSessionsByMonth)): ?>
            <div class="accordion" id="olderMonthsAccordion">
                <?php $monthIdx = 0; ?>
                <?php foreach ($olderSessionsByMonth as $monthKey => $monthSessions): ?>
                <?php
                $monthIdx++;
                $accId = 'month-' . $monthIdx;
                $monthLabel = date('m/Y', strtotime($monthSessions[0]['started_at']));
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading-<?= $accId ?>">
                        <button class="accordion-button collapsed" type="button"
                                data-bs-toggle="collapse" data-bs-target="#collapse-<?= $accId ?>"
                                aria-expanded="false" aria-controls="collapse-<?= $accId ?>">
                            <?= h($monthLabel) ?>
                            <span class="badge bg-secondary ms-2"><?= count($monthSessions) ?></span>
                        </button>
                    </h2>
                    <div id="collapse-<?= $accId ?>" class="accordion-collapse collapse"
                         aria-labelledby="heading-<?= $accId ?>" data-bs-parent="#olderMonthsAccordion">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle table-sessions">
                                    <tbody>
                                        <?php foreach ($monthSessions as $s): ?>
                                        <tr>
                                            <td class="text-center" style="width:42px;">
                                                <input class="form-check-input training-bulk-check" type="checkbox" name="session_ids[]" value="<?= (int)$s['id'] ?>">
                                            </td>
                                            <td>
                                                <strong><?= formatDate($s['started_at']) ?></strong>
                                                <br><small class="text-muted"><?= date('H:i', strtotime($s['started_at'])) ?></small>
                                            </td>
                                            <td><span class="badge bg-secondary fs-6"><?= h($s['set_name']) ?></span></td>
                                            <td class="text-muted"><?= $s['location'] ? h($s['location']) : '–' ?></td>
                                            <td class="text-center"><?= $s['total_series'] ?></td>
                                            <td>
                                                <?php if ($s['completed_at']): ?>
                                                <span class="badge bg-success">Dokončeno</span>
                                                <?php else: ?>
                                                <span class="badge bg-warning text-dark">Probíhá</span>
                                                <?php endif; ?>
                                                <?php if ($s['paired_session_id']): ?>
                                                <span class="badge bg-info text-dark mt-1">
                                                    <i class="fas fa-people-group me-1"></i>Párový
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!empty($s['training_photo'])): ?>
                                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>#training-photo"
                                                   title="Zobrazit fotografii">
                                                    <img src="<?= h(photoUrl($s['training_photo'], 'trainings')) ?>"
                                                         alt="foto"
                                                         style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid #ffc107">
                                                </a>
                                                <?php else: ?>
                                                <span class="text-muted">–</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if (!$s['completed_at']): ?>
                                                <a href="<?= BASE_URL ?>/training_session.php?id=<?= $s['id'] ?>"
                                                   class="btn btn-warning btn-sm">
                                                    <i class="fas fa-play me-1"></i>Pokračovat
                                                </a>
                                                <?php else: ?>
                                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>"
                                                   class="btn btn-outline-dark btn-sm">
                                                    <i class="fas fa-eye me-1"></i>Detail
                                                </a>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm"
                                                        onclick="deleteSingleTraining(<?= (int)$s['id'] ?>)" title="Smazat trénink">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
    </div>
</div>

</div>

</div>

<script>
let forceSingleDeleteSubmit = false;
const weightHistoryData = <?= json_encode(array_map(
    static fn(array $row): array => [
        'measured_at' => (string)$row['measured_at'],
        'label' => formatDate((string)$row['measured_at']),
        'weight' => (float)$row['weight_kg'],
    ],
    array_reverse($weightHistory)
), JSON_UNESCAPED_UNICODE) ?>;

function getTrainingChecks() {
    return Array.from(document.querySelectorAll('.training-bulk-check'));
}

function updateBulkDeleteButtonState() {
    const deleteBtn = document.getElementById('bulkDeleteBtn');
    if (!deleteBtn) return;
    const selected = getTrainingChecks().filter((el) => el.checked).length;
    deleteBtn.disabled = selected === 0;
    deleteBtn.innerHTML = selected > 0
        ? '<i class="fas fa-trash me-1"></i>Smazat vybrané (' + selected + ')'
        : '<i class="fas fa-trash me-1"></i>Smazat vybrané';
}

function deleteSingleTraining(sessionId) {
    const checks = getTrainingChecks();
    checks.forEach((el) => {
        el.checked = el.value === String(sessionId);
    });
    updateBulkDeleteButtonState();

    if (!confirm('Opravdu smazat tento trénink? V administraci půjde obnovit.')) {
        return;
    }

    forceSingleDeleteSubmit = true;
    document.getElementById('bulkDeleteTrainingsForm').submit();
}

function confirmBulkDeleteTrainings() {
    if (forceSingleDeleteSubmit) {
        forceSingleDeleteSubmit = false;
        return true;
    }
    const selected = getTrainingChecks().filter((el) => el.checked).length;
    if (selected === 0) {
        alert('Vyberte alespoň jeden trénink.');
        return false;
    }
    return confirm('Opravdu smazat vybrané tréninky (' + selected + '×)? V administraci půjdou obnovit.');
}

document.addEventListener('DOMContentLoaded', function () {
    const weightCanvas = document.getElementById('athleteWeightChart');
    const rangeFilter = document.getElementById('coachWeightRangeFilter');
    const trendBadge = document.getElementById('coachWeightTrendBadge');
    const trendValue = document.getElementById('coachWeightTrendValue');

    function parseWeightDate(dateStr) {
        return new Date(`${dateStr}T00:00:00`);
    }

    function addMonths(dateObj, months) {
        const next = new Date(dateObj);
        next.setMonth(next.getMonth() + months);
        return next;
    }

    function getFilteredWeightRows(rangeKey) {
        if (!Array.isArray(weightHistoryData) || weightHistoryData.length === 0 || rangeKey === 'all') {
            return weightHistoryData;
        }

        const lastRow = weightHistoryData[weightHistoryData.length - 1];
        const lastDate = parseWeightDate(lastRow.measured_at);
        let startDate = new Date(lastDate);

        if (rangeKey === 'week') {
            startDate.setDate(startDate.getDate() - 7);
        } else if (rangeKey === 'month') {
            startDate.setDate(startDate.getDate() - 30);
        } else if (rangeKey === 'quarter') {
            startDate = addMonths(startDate, -4);
        } else if (rangeKey === 'year') {
            startDate = addMonths(startDate, -12);
        }

        return weightHistoryData.filter((row) => parseWeightDate(row.measured_at) >= startDate);
    }

    function updateWeightTrend(rows) {
        if (!trendBadge || !trendValue || !Array.isArray(rows) || rows.length < 2) {
            if (trendBadge) trendBadge.textContent = 'Nedostatek dat';
            if (trendBadge) trendBadge.className = 'badge bg-secondary';
            if (trendValue) trendValue.textContent = '';
            return;
        }

        const firstWeight = Number(rows[0].weight || 0);
        const lastWeight = Number(rows[rows.length - 1].weight || 0);
        const diff = lastWeight - firstWeight;
        const absDiff = Math.abs(diff);

        if (absDiff <= 1.5) {
            trendBadge.textContent = 'Stabilní váha';
            trendBadge.className = 'badge bg-secondary';
        } else if (diff <= -1.51) {
            trendBadge.textContent = 'Hubnutí';
            trendBadge.className = 'badge bg-success';
        } else {
            trendBadge.textContent = 'Přibírání na váze';
            trendBadge.className = 'badge bg-danger';
        }

        const sign = diff > 0 ? '+' : '';
        trendValue.textContent = `${sign}${diff.toFixed(1).replace('.', ',')} kg`;
    }

    if (weightCanvas && Array.isArray(weightHistoryData) && weightHistoryData.length > 0 && window.Chart) {
        const weightChart = new Chart(weightCanvas, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Hmotnost (kg)',
                    data: [],
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13,110,253,0.15)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: (value) => value + ' kg',
                        },
                    },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ' ' + ctx.parsed.y + ' kg',
                        },
                    },
                },
            },
        });

        function applyWeightRange(rangeKey) {
            const filtered = getFilteredWeightRows(rangeKey);
            weightChart.data.labels = filtered.map((p) => p.label);
            weightChart.data.datasets[0].data = filtered.map((p) => p.weight);
            weightChart.update();
            updateWeightTrend(filtered);
        }

        if (rangeFilter) {
            rangeFilter.addEventListener('change', function (event) {
                applyWeightRange(event.target.value);
            });
        }

        applyWeightRange(rangeFilter ? rangeFilter.value : 'month');
    }

    const checks = getTrainingChecks();
    const toggleAllBtn = document.getElementById('toggleSelectTrainings');
    const checkAllCurrent = document.getElementById('checkAllCurrent');

    checks.forEach((el) => {
        el.addEventListener('change', updateBulkDeleteButtonState);
    });

    if (toggleAllBtn) {
        toggleAllBtn.addEventListener('click', function () {
            const hasUnchecked = checks.some((el) => !el.checked);
            checks.forEach((el) => {
                el.checked = hasUnchecked;
            });
            this.innerHTML = hasUnchecked
                ? '<i class="fas fa-minus-square me-1"></i>Odebrat výběr'
                : '<i class="fas fa-check-square me-1"></i>Označit vše';
            if (checkAllCurrent) {
                const currentChecks = Array.from(document.querySelectorAll('#currentMonthTrainingsTable .training-bulk-check'));
                checkAllCurrent.checked = currentChecks.length > 0 && currentChecks.every((cb) => cb.checked);
            }
            updateBulkDeleteButtonState();
        });
    }

    if (checkAllCurrent) {
        checkAllCurrent.addEventListener('change', function () {
            const currentRows = Array.from(document.querySelectorAll('#currentMonthTrainingsTable .training-bulk-check'));
            currentRows.forEach((cb) => {
                cb.checked = this.checked;
            });
            updateBulkDeleteButtonState();
        });
    }

    updateBulkDeleteButtonState();
});
</script>

<?php renderFooter(); ?>
