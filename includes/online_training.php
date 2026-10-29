<?php
require_once __DIR__ . '/functions.php';

function onlineTrainingStatusLabel(string $status): string {
    return [
        'created' => 'Vytvořeno',
        'sent' => 'Odesláno',
        'in_progress' => 'Trénink zahájen – probíhá',
        'completed' => 'Dokončený',
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
    return [
        'sent' => 'Nový',
        'in_progress' => 'Rozpracováno',
        'completed' => 'Dokončeno',
    ][$status] ?? onlineTrainingStatusLabel($status);
}

function onlineTrainingAthleteStatusClass(string $status): string {
    return $status === 'sent' ? 'warning text-dark' : onlineTrainingStatusClass($status);
}

function onlineTrainingSubscriptionPaymentLabel(array $subscription): string {
    if ($subscription['status'] === 'cancelled') return 'Ukončeno';
    if (empty($subscription['starts_on'])) return 'Historie';
    if ((float)$subscription['price'] === 0.0) return 'Zdarma';
    return ($subscription['payment_status'] ?? null) === 'paid' ? 'Uhrazeno' : 'Čeká na úhradu';
}

function onlineTrainingSubscriptionValiditySql(): string {
    return "s.status = 'active' AND s.starts_on <= CURDATE() AND s.ends_on >= CURDATE()
        AND (s.price = 0 OR p.status = 'paid')";
}

function onlineTrainingSubscriptionDaysLabel(int $days): string {
    if ($days === 0) return 'Končí dnes';
    if ($days === 1) return 'Zbývá 1 den';
    return ($days >= 2 && $days <= 4 ? 'Zbývají ' . $days . ' dny' : 'Zbývá ' . $days . ' dní');
}

function onlineTrainingActiveSubscription(PDO $pdo, int $coachId, int $athleteId, bool $lock = false): ?array {
    $stmt = $pdo->prepare("SELECT s.*, DATEDIFF(s.ends_on, CURDATE()) AS days_remaining FROM online_training_subscriptions s
        LEFT JOIN online_training_billing b ON b.subscription_id = s.id
        LEFT JOIN athlete_monthly_payments p ON p.coach_id = s.trainer_id AND p.athlete_id = s.athlete_id
            AND p.billing_month = b.billing_month AND p.issue_number = b.issue_number
        WHERE s.trainer_id = ? AND s.athlete_id = ? AND " . onlineTrainingSubscriptionValiditySql() . "
        ORDER BY s.ends_on DESC, s.id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$coachId, $athleteId]);
    return $stmt->fetch() ?: null;
}

function onlineTrainingFuturePaidSubscription(PDO $pdo, int $coachId, int $athleteId): ?array {
    $stmt = $pdo->prepare("SELECT s.starts_on, s.ends_on, s.price FROM online_training_subscriptions s
        LEFT JOIN online_training_billing b ON b.subscription_id = s.id
        LEFT JOIN athlete_monthly_payments p ON p.coach_id = s.trainer_id AND p.athlete_id = s.athlete_id
            AND p.billing_month = b.billing_month AND p.issue_number = b.issue_number
        WHERE s.trainer_id = ? AND s.athlete_id = ? AND s.status = 'active'
            AND s.starts_on > CURDATE() AND s.ends_on >= s.starts_on
            AND (s.price = 0 OR p.status = 'paid')
        ORDER BY s.starts_on ASC, s.id ASC LIMIT 1");
    $stmt->execute([$coachId, $athleteId]);
    return $stmt->fetch() ?: null;
}

function onlineTrainingCoachActiveSubscriptions(PDO $pdo, int $coachId): array {
    $stmt = $pdo->prepare("SELECT DISTINCT s.id, s.athlete_id, s.starts_on, s.ends_on, s.price,
            a.first_name, a.last_name, DATEDIFF(s.ends_on, CURDATE()) AS days_remaining
        FROM online_training_subscriptions s
        JOIN athletes a ON a.id = s.athlete_id AND a.coach_id = s.trainer_id
        LEFT JOIN online_training_billing b ON b.subscription_id = s.id
        LEFT JOIN athlete_monthly_payments p ON p.coach_id = s.trainer_id AND p.athlete_id = s.athlete_id
            AND p.billing_month = b.billing_month AND p.issue_number = b.issue_number
        WHERE s.trainer_id = ? AND " . onlineTrainingSubscriptionValiditySql() . "
        ORDER BY s.ends_on ASC, a.last_name ASC, a.first_name ASC, s.id ASC");
    $stmt->execute([$coachId]);
    return $stmt->fetchAll();
}

function onlineTrainingCoachUpcomingAndUnpaidSubscriptions(PDO $pdo, int $coachId): array {
    $stmt = $pdo->prepare("SELECT DISTINCT s.id, s.athlete_id, s.starts_on, s.ends_on, s.price,
            a.first_name, a.last_name, b.billing_month, b.issue_number,
            CASE WHEN s.price = 0 THEN 'free'
                 WHEN p.status = 'paid' THEN 'paid'
                 WHEN p.status = 'pending' THEN 'pending'
                 ELSE 'missing' END AS payment_state,
            s.starts_on > CURDATE() AS starts_in_future,
            s.ends_on < CURDATE() AS period_expired
        FROM online_training_subscriptions s
        JOIN athletes a ON a.id = s.athlete_id AND a.coach_id = s.trainer_id
        LEFT JOIN online_training_billing b ON b.subscription_id = s.id
        LEFT JOIN athlete_monthly_payments p ON p.coach_id = s.trainer_id AND p.athlete_id = s.athlete_id
            AND p.billing_month = b.billing_month AND p.issue_number = b.issue_number
        WHERE s.trainer_id = ? AND s.status = 'active'
            AND s.starts_on IS NOT NULL AND s.ends_on IS NOT NULL
            AND ((s.starts_on > CURDATE() AND s.ends_on >= CURDATE() AND (s.price = 0 OR p.status = 'paid'))
                OR (s.price > 0 AND (p.status IS NULL OR p.status <> 'paid')))
        ORDER BY s.starts_on ASC, a.last_name ASC, a.first_name ASC, s.id ASC");
    $stmt->execute([$coachId]);
    return $stmt->fetchAll();
}

function onlineTrainingPendingPaymentRequests(PDO $pdo, int $coachId): array {
    $stmt = $pdo->prepare("SELECT p.athlete_id, p.billing_month, p.issue_number, p.billed_amount,
            a.first_name, a.last_name, r.status AS release_status,
            (SELECT GROUP_CONCAT(b.description ORDER BY b.id SEPARATOR '; ')
             FROM online_training_billing b
             WHERE b.trainer_id = p.coach_id AND b.athlete_id = p.athlete_id
                 AND b.billing_month = p.billing_month AND b.issue_number = p.issue_number
                 AND b.billing_type = 'subscription') AS subscription_descriptions
        FROM athlete_monthly_payments p
        JOIN athletes a ON a.id = p.athlete_id AND a.coach_id = p.coach_id
        LEFT JOIN coach_billing_month_athletes r ON r.coach_id = p.coach_id
            AND r.athlete_id = p.athlete_id AND r.billing_month = p.billing_month
        WHERE p.coach_id = ? AND p.status = 'pending'
            AND EXISTS (SELECT 1 FROM online_training_billing b
                WHERE b.trainer_id = p.coach_id AND b.athlete_id = p.athlete_id
                    AND b.billing_month = p.billing_month AND b.issue_number = p.issue_number
                    AND b.billing_type = 'subscription')
        ORDER BY p.billing_month ASC, a.last_name ASC, a.first_name ASC, p.issue_number ASC");
    $stmt->execute([$coachId]);
    return $stmt->fetchAll();
}

function onlineTrainingCreateSubscription(PDO $pdo, int $coachId, int $athleteId, string $priceRaw, string $startsOn, string $endsOn): array {
    $priceRaw = str_replace(',', '.', trim($priceRaw));
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $priceRaw)) {
        throw new RuntimeException('Zadejte platnou cenu předplatného.');
    }
    foreach ([$startsOn, $endsOn] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Zadejte platné datum začátku a konce předplatného.');
        }
    }
    if ($endsOn < $startsOn) throw new RuntimeException('Konec předplatného nesmí být před začátkem.');
    $price = number_format((float)$priceRaw, 2, '.', '');
    $month = date('Y-m-01');
    $pdo->beginTransaction();
    try {
        $athleteStmt = $pdo->prepare('SELECT id, first_name, last_name, email, training_rate FROM athletes WHERE id = ? AND coach_id = ? FOR UPDATE');
        $athleteStmt->execute([$athleteId, $coachId]);
        $athlete = $athleteStmt->fetch();
        if (!$athlete) throw new RuntimeException('Sportovec nebyl nalezen.');
        $issueStmt = $pdo->prepare('SELECT * FROM athlete_monthly_payments WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? ORDER BY issue_number DESC FOR UPDATE');
        $issueStmt->execute([$coachId, $athleteId, $month]);
        $issues = $issueStmt->fetchAll();
        $pending = null;
        foreach ($issues as $issue) {
            if ($issue['status'] === 'pending') { $pending = $issue; break; }
        }
        $issueNumber = $pending ? (int)$pending['issue_number'] : ($issues ? (int)$issues[0]['issue_number'] + 1 : 1);
        $pdo->prepare("INSERT INTO online_training_subscriptions
            (trainer_id, athlete_id, total_trainings, remaining_trainings, price, purchased_at, starts_on, ends_on)
            VALUES (?, ?, 0, 0, ?, NOW(), ?, ?)")->execute([$coachId, $athleteId, $price, $startsOn, $endsOn]);
        $subscriptionId = (int)$pdo->lastInsertId();
        $description = 'Online tréninky: předplatné ' . date('d.m.Y', strtotime($startsOn)) . ' - ' . date('d.m.Y', strtotime($endsOn));
        $pdo->prepare("INSERT INTO online_training_billing
            (subscription_id, trainer_id, athlete_id, billing_type, description, amount, billing_date, billing_month, issue_number)
            VALUES (?, ?, ?, 'subscription', ?, ?, CURDATE(), ?, ?)")
            ->execute([$subscriptionId, $coachId, $athleteId, $description, $price, $month, $issueNumber]);
        $amount = 0.0;
        if ((float)$price > 0) {
            $rate = $pending && $pending['session_rate'] !== null ? (float)$pending['session_rate'] : ($athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null);
            if ($pending) {
                $amount = (float)$pending['billed_amount'] + (float)$price;
                $planned = (int)$pending['planned_sessions'];
                $carryover = (int)$pending['carryover_used_sessions'];
                $snapshotData = !empty($pending['receipt_snapshot_json'])
                    ? json_decode($pending['receipt_snapshot_json'], true)
                    : json_decode((string)paymentBuildReceiptSnapshot($pdo, $coachId, $athleteId, $month, $issueNumber), true);
                if (!is_array($snapshotData) || !is_array($snapshotData['breakdown'] ?? null)) {
                    throw new RuntimeException('Původní přehled výzvy nelze přečíst. Zkontrolujte výzvu před vytvořením předplatného.');
                }
                $snapshotData['breakdown']['amount'] = $amount;
                $snapshotData['display_amount'] = $amount;
                $snapshot = json_encode($snapshotData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $bill = calculateAthleteMonthlyBill($pdo, $coachId, $athleteId, $month, [], $issueNumber);
                if ($bill['billed_amount'] === null) throw new RuntimeException('Pro účtované osobní tréninky nejprve nastavte sazbu.');
                $amount = (float)$bill['billed_amount'];
                $planned = (int)$bill['planned_sessions'];
                $carryover = (int)$bill['carryover_used'];
                $snapshot = paymentBuildReceiptSnapshot($pdo, $coachId, $athleteId, $month, $issueNumber, null, $amount);
            }
            if ($snapshot === false || $snapshot === null) throw new RuntimeException('Nepodařilo se vytvořit přehled výzvy.');
            $pdo->prepare("INSERT INTO athlete_monthly_payments
                (coach_id, athlete_id, billing_month, issue_number, session_rate, planned_sessions, carryover_used_sessions, billed_amount, status, receipt_snapshot_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                ON DUPLICATE KEY UPDATE planned_sessions = VALUES(planned_sessions), carryover_used_sessions = VALUES(carryover_used_sessions),
                    billed_amount = VALUES(billed_amount), receipt_snapshot_json = VALUES(receipt_snapshot_json)")
                ->execute([$coachId, $athleteId, $month, $issueNumber, $rate, $planned, $carryover, $amount, $snapshot]);
            $pdo->prepare("INSERT INTO coach_billing_month_athletes (coach_id, athlete_id, billing_month, status, released_at)
                VALUES (?, ?, ?, 'released', NOW()) ON DUPLICATE KEY UPDATE status = 'released', released_at = NOW()")
                ->execute([$coachId, $athleteId, $month]);
        }
        $pdo->commit();
        return ['athlete' => $athlete, 'month' => $month, 'issue_number' => $issueNumber, 'amount' => $amount, 'price' => (float)$price, 'description' => $description];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function onlineTrainingNextSequence(PDO $pdo, int $coachId, int $athleteId): int {
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM online_trainings WHERE trainer_id = ? AND athlete_id = ?');
    $stmt->execute([$coachId, $athleteId]);
    return max(1, (int)$stmt->fetchColumn());
}

function onlineTrainingLoadForCoach(PDO $pdo, int $id, int $coachId): ?array {
    $stmt = $pdo->prepare('SELECT ot.*, a.first_name, a.last_name, a.email AS athlete_email, a.online_training_rate AS athlete_online_rate, c.name AS coach_name, ws.name AS set_name,
        ots.total_trainings, ots.used_trainings, ots.remaining_trainings,
        ots.starts_on AS subscription_starts_on, ots.ends_on AS subscription_ends_on
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
        ots.total_trainings, ots.used_trainings, ots.remaining_trainings,
        ots.starts_on AS subscription_starts_on, ots.ends_on AS subscription_ends_on
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

function onlineTrainingSaveAthleteResults(PDO $pdo, int $trainingId, int $athleteId, array $data): void {
    if (!isset($data['series_count']) || !array_key_exists('athlete_note', $data) || !is_string($data['athlete_note'])) {
        throw new RuntimeException('Výsledky nejsou úplné. Obnovte stránku a zkuste to znovu.');
    }
    $series = $data['series'] ?? [];
    if (!is_array($series) || !ctype_digit((string)$data['series_count'])) {
        throw new RuntimeException('Neplatné údaje sérií.');
    }
    $stmt = $pdo->prepare('SELECT s.id FROM online_training_series s JOIN online_training_exercises e ON e.id = s.online_training_exercise_id WHERE e.online_training_id = ?');
    $stmt->execute([$trainingId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ((int)$data['series_count'] !== count($ids) || count($series) !== count($ids)) {
        throw new RuntimeException('Nepodařilo se odeslat všechny série. Trénink nebyl dokončen.');
    }
    $save = $pdo->prepare('INSERT INTO online_training_results (online_training_id, online_training_series_id, actual_weight, actual_reps) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE actual_weight = VALUES(actual_weight), actual_reps = VALUES(actual_reps)');
    foreach ($ids as $seriesId) {
        $values = $series[$seriesId] ?? null;
        if (!is_array($values) || !isset($values['weight'], $values['reps']) || !is_string($values['weight']) || !is_string($values['reps'])) {
            throw new RuntimeException('Nepodařilo se odeslat všechny hodnoty sérií.');
        }
        if (($values['weight'] !== '' && !is_numeric($values['weight'])) || ($values['reps'] !== '' && !preg_match('/^\d+$/', $values['reps']))) {
            throw new RuntimeException('Zkontrolujte váhu a počet opakování u všech sérií.');
        }
        $save->execute([$trainingId, $seriesId, $values['weight'] === '' ? null : $values['weight'], $values['reps'] === '' ? null : (int)$values['reps']]);
    }
    $note = trim($data['athlete_note']);
    $pdo->prepare('UPDATE online_trainings SET athlete_note = ? WHERE id = ? AND athlete_id = ?')->execute([$note === '' ? null : $note, $trainingId, $athleteId]);
}

function onlineTrainingValidateLink(string $url, string $description): void {
    if ($url === '' || strlen($url) > 1000 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new RuntimeException('Každý odkaz musí být platná internetová adresa začínající http nebo https a mít nejvýše 1000 znaků.');
    }
    if (strlen($description) > 10000) throw new RuntimeException('Popis odkazu je příliš dlouhý (nejvýše 10 000 bajtů).');
}

function onlineTrainingCreateFromSet(PDO $pdo, int $coachId, int $athleteId, int $setId, string $title, string $note, array $files = [], array $urls = []): int {
    foreach ($urls as &$link) {
        if (is_string($link)) $link = ['url' => $link, 'description' => ''];
        if (!is_array($link) || !isset($link['url'], $link['description']) || !is_string($link['url']) || !is_string($link['description'])) throw new RuntimeException('Neplatný odkaz nebo jeho popis.');
        $link['url'] = trim($link['url']);
        $link['description'] = trim($link['description']);
        onlineTrainingValidateLink($link['url'], $link['description']);
    }
    unset($link);
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
    $uploadedPaths = [];
    $pdo->beginTransaction();
    try {
        $lockAthlete = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ? FOR UPDATE');
        $lockAthlete->execute([$athleteId, $coachId]);
        if (!$lockAthlete->fetch()) throw new RuntimeException('Sportovec nebyl nalezen.');
        if (!onlineTrainingActiveSubscription($pdo, $coachId, $athleteId, true)) {
            throw new RuntimeException('Sportovec nemá platné uhrazené předplatné. Online trénink nelze vytvořit.');
        }
        $sequence = onlineTrainingNextSequence($pdo, $coachId, $athleteId);
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
        $insertFile = $pdo->prepare("INSERT INTO online_training_attachments (online_training_id, attachment_type, file_path, original_name, uploaded_by) VALUES (?, ?, ?, ?, 'trainer')");
        foreach ($files as $file) {
            $uploaded = onlineTrainingSaveUpload($file, 'trainer');
            if ($uploaded === null) continue;
            $uploadedPaths[] = $uploaded['path'];
            $insertFile->execute([$trainingId, $uploaded['type'], $uploaded['path'], $uploaded['name']]);
        }
        if ($urls) {
            $insertUrl = $pdo->prepare("INSERT INTO online_training_attachments (online_training_id, attachment_type, external_url, description, uploaded_by) VALUES (?, 'url', ?, ?, 'trainer')");
            foreach ($urls as $link) $insertUrl->execute([$trainingId, $link['url'], $link['description'] !== '' ? $link['description'] : null]);
        }
        $pdo->commit();
        return $trainingId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($uploadedPaths as $path) {
            $absolutePath = __DIR__ . '/../uploads/' . $path;
            if (is_file($absolutePath) && !unlink($absolutePath)) error_log('Nepodařilo se odstranit nenavázanou přílohu online tréninku: ' . $absolutePath);
        }
        throw $e;
    }
}

function onlineTrainingSaveUpload(array $file, string $uploadedBy): ?array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > 50 * 1024 * 1024) {
        throw new RuntimeException('Příloha je příliš velká nebo se ji nepodařilo nahrát.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $formats = [
        'image/jpeg' => ['photo', 'jpg'],
        'image/png' => ['photo', 'png'],
        'image/gif' => ['photo', 'gif'],
        'image/webp' => ['photo', 'webp'],
        'image/avif' => ['photo', 'avif'],
        'image/heic' => ['photo', 'heic'],
        'image/heif' => ['photo', 'heif'],
        'image/bmp' => ['photo', 'bmp'],
        'video/mp4' => ['video', 'mp4'],
        'video/quicktime' => ['video', 'mov'],
        'video/webm' => ['video', 'webm'],
        'video/x-matroska' => ['video', 'mkv'],
        'video/x-msvideo' => ['video', 'avi'],
        'video/mpeg' => ['video', 'mpeg'],
        'video/3gpp' => ['video', '3gp'],
    ];
    if (!isset($formats[$mime])) throw new RuntimeException('Nepodporovaný formát přílohy. Nahrajte běžnou fotografii nebo video.');
    [$type, $extension] = $formats[$mime];
    $dir = __DIR__ . '/../uploads/online_trainings';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Úložiště příloh není dostupné.');
    $name = bin2hex(random_bytes(16)) . '.' . $extension;
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

function onlineTrainingBillingAmount(PDO $pdo, int $coachId, int $athleteId, string $billingMonth, int $issueNumber = 1): float {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM online_training_billing WHERE trainer_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = ?');
    $stmt->execute([$coachId, $athleteId, $billingMonth, $issueNumber]);
    return (float)$stmt->fetchColumn();
}

function onlineTrainingSend(PDO $pdo, int $trainingId, int $coachId, ?string $coachNote = null): void {
    $training = onlineTrainingLoadForCoach($pdo, $trainingId, $coachId);
    if (!$training || $training['status'] !== 'created') throw new RuntimeException('Online trénink nelze odeslat.');
    $pdo->beginTransaction();
    try {
        $activeSubscription = onlineTrainingActiveSubscription($pdo, $coachId, (int)$training['athlete_id'], true);
        if (!$activeSubscription) throw new RuntimeException('Sportovec nemá platné a uhrazené časové předplatné.');
        $update = $pdo->prepare("UPDATE online_trainings SET status = 'sent', sent_at = NOW(), billing_type = 'subscription', price = 0, subscription_id = ?, coach_note = ? WHERE id = ? AND trainer_id = ? AND status = 'created'");
        $update->execute([(int)$activeSubscription['id'], $coachNote !== null ? trim($coachNote) : $training['coach_note'], $trainingId, $coachId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Online trénink již byl odeslán nebo není dostupný.');
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
