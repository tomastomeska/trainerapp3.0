<?php
require_once __DIR__ . '/functions.php';

function onlineTrainingStatusLabel(string $status): string {
    return [
        'created' => 'Vytvořeno',
        'sent' => 'Odesláno',
        'in_progress' => 'Rozpracováno',
        'completed' => 'Dokončeno',
    ][$status] ?? $status;
}

function onlineTrainingStatusClass(string $status): string {
    return [
        'created' => 'secondary',
        'sent' => 'primary',
        'in_progress' => 'warning text-dark',
        'completed' => 'success',
    ][$status] ?? 'secondary';
}

function onlineTrainingAthleteStatusLabel(string $status): string {
    return $status === 'sent' ? 'Nový' : onlineTrainingStatusLabel($status);
}

function onlineTrainingAthleteStatusClass(string $status): string {
    return $status === 'sent' ? 'warning text-dark' : onlineTrainingStatusClass($status);
}

function onlineTrainingNextSequence(PDO $pdo, int $athleteId): int {
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM online_trainings WHERE athlete_id = ?');
    $stmt->execute([$athleteId]);
    return max(1, (int)$stmt->fetchColumn());
}

function onlineTrainingLoadForCoach(PDO $pdo, int $id, int $coachId): ?array {
    $stmt = $pdo->prepare('SELECT ot.*, a.first_name, a.last_name, a.email AS athlete_email, a.online_training_rate AS athlete_online_rate, c.name AS coach_name, ws.name AS set_name,
        ots.total_trainings, ots.used_trainings, ots.remaining_trainings
        FROM online_trainings ot
        JOIN athletes a ON a.id = ot.athlete_id
        JOIN coaches c ON c.id = ot.trainer_id
        JOIN workout_sets ws ON ws.id = ot.training_set_id
        LEFT JOIN online_training_subscriptions ots ON ots.id = ot.subscription_id
        WHERE ot.id = ? AND ot.trainer_id = ? LIMIT 1');
    $stmt->execute([$id, $coachId]);
    return $stmt->fetch() ?: null;
}

function onlineTrainingLoadForAthlete(PDO $pdo, int $id, int $athleteId): ?array {
    $stmt = $pdo->prepare('SELECT ot.*, a.first_name, a.last_name, c.name AS coach_name, ws.name AS set_name,
        ots.total_trainings, ots.used_trainings, ots.remaining_trainings
        FROM online_trainings ot
        JOIN athletes a ON a.id = ot.athlete_id
        JOIN coaches c ON c.id = ot.trainer_id
        JOIN workout_sets ws ON ws.id = ot.training_set_id
        LEFT JOIN online_training_subscriptions ots ON ots.id = ot.subscription_id
        WHERE ot.id = ? AND ot.athlete_id = ? AND ot.status <> \'created\' LIMIT 1');
    $stmt->execute([$id, $athleteId]);
    return $stmt->fetch() ?: null;
}

function onlineTrainingLoadExercises(PDO $pdo, int $trainingId): array {
    $stmt = $pdo->prepare('SELECT * FROM online_training_exercises WHERE online_training_id = ? ORDER BY exercise_order, id');
    $stmt->execute([$trainingId]);
    $exercises = $stmt->fetchAll();
    $seriesStmt = $pdo->prepare('SELECT s.*, r.actual_weight, r.actual_reps, r.actual_duration_seconds, r.note AS result_note
        FROM online_training_series s LEFT JOIN online_training_results r ON r.id = (
            SELECT MAX(r2.id) FROM online_training_results r2
            WHERE r2.online_training_series_id = s.id AND r2.online_training_id = ?
        )
        WHERE s.online_training_exercise_id = ? ORDER BY s.series_order, s.id');
    foreach ($exercises as &$exercise) {
        $seriesStmt->execute([$trainingId, (int)$exercise['id']]);
        $exercise['series'] = $seriesStmt->fetchAll();
    }
    unset($exercise);
    return $exercises;
}

function onlineTrainingCreateFromSet(PDO $pdo, int $coachId, int $athleteId, int $setId, string $title, string $note): int {
    $setAccessSql = (workoutSetsHasColumn('is_global') && workoutSetsHasColumn('description')) ? '(coach_id = ? OR is_global = 1)' : 'coach_id = ?';
    $setStmt = $pdo->prepare('SELECT id, name FROM workout_sets WHERE id = ? AND ' . $setAccessSql . (workoutSetArchivingEnabled() ? ' AND is_active = 1' : '') . ' LIMIT 1');
    $setStmt->execute([$setId, $coachId]);
    $set = $setStmt->fetch();
    if (!$set) {
        throw new RuntimeException('Sada nebyla nalezena nebo je archivovaná.');
    }
    $athleteStmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
    $athleteStmt->execute([$athleteId, $coachId]);
    if (!$athleteStmt->fetch()) {
        throw new RuntimeException('Sportovec nebyl nalezen.');
    }
    $pdo->beginTransaction();
    try {
        $lockAthlete = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ? FOR UPDATE');
        $lockAthlete->execute([$athleteId, $coachId]);
        if (!$lockAthlete->fetch()) throw new RuntimeException('Sportovec nebyl nalezen.');
        $sequence = onlineTrainingNextSequence($pdo, $athleteId);
        $stmt = $pdo->prepare('INSERT INTO online_trainings (trainer_id, athlete_id, training_set_id, sequence_number, title, coach_note) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$coachId, $athleteId, $setId, $sequence, $title !== '' ? $title : (string)$set['name'], $note !== '' ? $note : null]);
        $trainingId = (int)$pdo->lastInsertId();
        $exerciseStmt = $pdo->prepare('SELECT wse.exercise_id, wse.exercise_order, e.name, e.sport_type, e.is_timed FROM workout_set_exercises wse JOIN exercises e ON e.id = wse.exercise_id WHERE wse.workout_set_id = ? ORDER BY wse.exercise_order');
        $exerciseStmt->execute([$setId]);
        $insertExercise = $pdo->prepare('INSERT INTO online_training_exercises (online_training_id, exercise_id, exercise_order, exercise_name, sport_type, is_timed) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($exerciseStmt->fetchAll() as $exercise) {
            $insertExercise->execute([$trainingId, $exercise['exercise_id'], $exercise['exercise_order'], $exercise['name'], $exercise['sport_type'] ?? 'standard', (int)($exercise['is_timed'] ?? 0)]);
            $onlineExerciseId = (int)$pdo->lastInsertId();
            // Sady obsahuji cviky, jejich serie se vytvari az v konkretni session.
            // Pro online pripravu vytvorime jednu serii; trener ji muze rozsirit v editoru.
            $pdo->prepare('INSERT INTO online_training_series (online_training_exercise_id, series_order) VALUES (?, 1)')->execute([$onlineExerciseId]);
        }
        $copyAttachments = $pdo->prepare('SELECT * FROM workout_set_attachments WHERE workout_set_id = ?');
        $copyAttachments->execute([$setId]);
        $insertAttachment = $pdo->prepare('INSERT INTO online_training_attachments (online_training_id, online_training_exercise_id, attachment_type, file_path, original_name, external_url, uploaded_by) VALUES (?, (SELECT id FROM online_training_exercises WHERE online_training_id = ? AND exercise_id <=> ? LIMIT 1), ?, ?, ?, ?, \'trainer\')');
        foreach ($copyAttachments->fetchAll() as $attachment) {
            $insertAttachment->execute([$trainingId, $trainingId, $attachment['exercise_id'], $attachment['attachment_type'], $attachment['file_path'], $attachment['original_name'], $attachment['external_url']]);
        }
        $pdo->commit();
        return $trainingId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function onlineTrainingSaveUpload(array $file, string $uploadedBy): ?array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > 50 * 1024 * 1024) {
        throw new RuntimeException('Příloha je příliš velká nebo se ji nepodařilo nahrát.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $type = str_starts_with((string)$mime, 'image/') ? 'photo' : (str_starts_with((string)$mime, 'video/') ? 'video' : null);
    if (!$type) throw new RuntimeException('Povoleny jsou pouze fotografie a video soubory.');
    $dir = __DIR__ . '/../uploads/online_trainings';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Úložiště příloh není dostupné.');
    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $name = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . preg_replace('/[^a-z0-9]+/i', '', $extension) : '');
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Přílohu se nepodařilo uložit.');
    return ['type' => $type, 'path' => 'online_trainings/' . $name, 'name' => (string)$file['name'], 'uploaded_by' => $uploadedBy];
}

function onlineTrainingResolveBillingMonth(PDO $pdo, int $coachId, int $athleteId): string {
    $month = new DateTimeImmutable('first day of this month 00:00:00');
    $monthSql = $month->format('Y-m-01');
    $releasedStmt = $pdo->prepare("SELECT status FROM coach_billing_month_athletes WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? LIMIT 1");
    $releasedStmt->execute([$coachId, $athleteId, $monthSql]);
    $athleteReleased = $releasedStmt->fetchColumn() === 'released';
    $monthReleasedStmt = $pdo->prepare("SELECT status FROM coach_billing_months WHERE coach_id = ? AND billing_month = ? LIMIT 1");
    $monthReleasedStmt->execute([$coachId, $monthSql]);
    $monthReleased = $monthReleasedStmt->fetchColumn() === 'released';
    return ($athleteReleased || $monthReleased) ? $month->modify('+1 month')->format('Y-m-01') : $monthSql;
}

function onlineTrainingBillingMonthAvailable(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) return $available;
    try {
        $column = $pdo->query("SHOW COLUMNS FROM online_training_billing LIKE 'billing_month'");
        $available = $column !== false && (bool)$column->fetch();
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function onlineTrainingBillingAmount(PDO $pdo, int $coachId, int $athleteId, string $billingMonth): float {
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM online_training_billing WHERE trainer_id = ? AND athlete_id = ? AND billing_month = ?');
        $stmt->execute([$coachId, $athleteId, $billingMonth]);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function onlineTrainingSend(PDO $pdo, int $trainingId, int $coachId, string $billingType, ?int $subscriptionId = null, ?int $subscriptionTotal = null, ?float $subscriptionPrice = null): void {
    $training = onlineTrainingLoadForCoach($pdo, $trainingId, $coachId);
    if (!$training || $training['status'] !== 'created') throw new RuntimeException('Online trénink nelze odeslat.');
    $billingType = in_array($billingType, ['auto', 'free', 'single', 'subscription'], true) ? $billingType : '';
    if ($billingType === '') throw new RuntimeException('Vyberte způsob účtování online tréninku.');
    $price = 0.0;
    $pdo->beginTransaction();
    try {
        $activeSubscriptionStmt = $pdo->prepare('SELECT * FROM online_training_subscriptions WHERE trainer_id = ? AND athlete_id = ? AND status = \'active\' AND remaining_trainings > 0 ORDER BY purchased_at ASC, id ASC LIMIT 1 FOR UPDATE');
        $activeSubscriptionStmt->execute([$coachId, (int)$training['athlete_id']]);
        $activeSubscription = $activeSubscriptionStmt->fetch() ?: null;
        if ($billingType === 'auto') {
            if ($activeSubscription) {
                $billingType = 'subscription';
                $subscriptionId = (int)$activeSubscription['id'];
            } elseif ($training['athlete_online_rate'] !== null && (float)$training['athlete_online_rate'] > 0) {
                $billingType = 'single';
            } else {
                throw new RuntimeException('Sportovec nemá aktivní předplatné ani sazbu. Zvolte výslovně možnost Zdarma.');
            }
        }
        if ($billingType === 'single') {
            $price = max(0, (float)($training['athlete_online_rate'] ?? 0));
            if ($price <= 0) throw new RuntimeException('Sportovec nemá nastavenou jednorázovou online sazbu. Zvolte Zdarma nebo vytvořte předplatné v detailu sportovce.');
        }
        if ($billingType === 'subscription') {
            if (!$subscriptionId) $subscriptionId = $activeSubscription ? (int)$activeSubscription['id'] : null;
            if (!$subscriptionId) throw new RuntimeException('Sportovec nemá aktivní předplatné.');
            $subStmt = $pdo->prepare('SELECT * FROM online_training_subscriptions WHERE id = ? AND trainer_id = ? AND athlete_id = ? AND status = \'active\' AND remaining_trainings > 0 FOR UPDATE');
            $subStmt->execute([$subscriptionId, $coachId, (int)$training['athlete_id']]);
            $sub = $subStmt->fetch();
            if (!$sub) throw new RuntimeException('Předplatné nemá volný trénink.');
            $pdo->prepare('UPDATE online_training_subscriptions SET used_trainings = used_trainings + 1, remaining_trainings = remaining_trainings - 1, status = IF(remaining_trainings <= 1, \'exhausted\', \'active\') WHERE id = ?')->execute([$subscriptionId]);
        }
        $pdo->prepare('UPDATE online_trainings SET status = \'sent\', sent_at = NOW(), billing_type = ?, price = ?, subscription_id = ? WHERE id = ?')
            ->execute([$billingType, $price, $subscriptionId, $trainingId]);
        if ($billingType === 'single' && $price > 0) {
            $billingMonth = onlineTrainingResolveBillingMonth($pdo, $coachId, (int)$training['athlete_id']);
            $billingSql = onlineTrainingBillingMonthAvailable($pdo)
                ? 'INSERT INTO online_training_billing (online_training_id, trainer_id, athlete_id, billing_type, description, amount, billing_date, billing_month) VALUES (?, ?, ?, \'single\', ?, ?, CURDATE(), ?)'
                : 'INSERT INTO online_training_billing (online_training_id, trainer_id, athlete_id, billing_type, description, amount, billing_date) VALUES (?, ?, ?, \'single\', ?, ?, CURDATE())';
            $billingParams = [$trainingId, $coachId, (int)$training['athlete_id'], 'Online trénink #' . str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT), $price];
            if (onlineTrainingBillingMonthAvailable($pdo)) $billingParams[] = $billingMonth;
            $pdo->prepare($billingSql)->execute($billingParams);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $url = BASE_URL . '/online_training.php?id=' . $trainingId;
    $messageId = createAthleteNotification((int)$training['athlete_id'], 'Nový online trénink', 'Trenér vám odeslal nový online trénink. Otevřít: ' . $url);
    $athleteStmt = $pdo->prepare('SELECT first_name, last_name, email FROM athletes WHERE id = ?');
    $athleteStmt->execute([(int)$training['athlete_id']]);
    $athlete = $athleteStmt->fetch();
    if ($athlete && !empty($athlete['email'])) {
        $exerciseStmt = $pdo->prepare('SELECT exercise_order, exercise_name FROM online_training_exercises WHERE online_training_id = ? ORDER BY exercise_order, id');
        $exerciseStmt->execute([$trainingId]);
        $exerciseRows = $exerciseStmt->fetchAll();
        $exerciseLines = array_map(static fn(array $row): string => ((int)$row['exercise_order']) . '. ' . (string)$row['exercise_name'], $exerciseRows);
        $message = 'Trenér vám odeslal nový online trénink: ' . (string)$training['title'] . '.';
        if ($exerciseLines) $message .= "\n\nCviky:\n" . implode("\n", $exerciseLines);
        $message .= "\n\nOtevřete trénink v aplikaci: " . $url;
        sendAthleteCalendarNotificationEmail((string)$athlete['email'], trim($athlete['first_name'] . ' ' . $athlete['last_name']), 'Nový online trénink', $message);
        // Online trénink má sportovec obdržet hned; cron zůstává zálohou pro nezpracované joby.
        processEmailNotificationQueue(10, 'athlete_calendar_notification');
    }
}

function onlineTrainingNotifyCompleted(PDO $pdo, array $training): void {
    $number = str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT);
    $subject = 'Dokončený online trénink #' . $number;
    $body = 'Sportovec ' . trim($training['first_name'] . ' ' . $training['last_name']) . ' dokončil online trénink #' . $number . '. Otevřít: ' . BASE_URL . '/online_training.php?id=' . (int)$training['id'];
    createCoachSystemMessage((int)$training['trainer_id'], $subject, $body, true);
    // Dokončení online tréninku má trenéra upozornit bez čekání na cron.
    processEmailNotificationQueue(10, 'coach_message_notification');
}
