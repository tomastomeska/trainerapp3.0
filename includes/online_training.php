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

function onlineTrainingNextSequence(PDO $pdo, int $athleteId): int {
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM online_trainings WHERE athlete_id = ?');
    $stmt->execute([$athleteId]);
    return max(1, (int)$stmt->fetchColumn());
}

function onlineTrainingLoadForCoach(PDO $pdo, int $id, int $coachId): ?array {
    $stmt = $pdo->prepare('SELECT ot.*, a.first_name, a.last_name, a.email AS athlete_email, a.online_training_rate, c.name AS coach_name, ws.name AS set_name,
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
    $setStmt = $pdo->prepare('SELECT id, name FROM workout_sets WHERE id = ? AND coach_id = ?' . (workoutSetArchivingEnabled() ? ' AND is_active = 1' : '') . ' LIMIT 1');
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

function onlineTrainingSend(PDO $pdo, int $trainingId, int $coachId, string $billingType, ?int $subscriptionId = null, ?int $subscriptionTotal = null, ?float $subscriptionPrice = null): void {
    $training = onlineTrainingLoadForCoach($pdo, $trainingId, $coachId);
    if (!$training || $training['status'] !== 'created') throw new RuntimeException('Online trénink nelze odeslat.');
    $billingType = in_array($billingType, ['free', 'single', 'subscription'], true) ? $billingType : 'free';
    $price = 0.0;
    $pdo->beginTransaction();
    try {
        if ($billingType === 'single') $price = max(0, (float)($training['athlete_online_rate'] ?? 0));
        if ($billingType === 'subscription') {
            if (!$subscriptionId && $subscriptionTotal && $subscriptionPrice !== null) {
                $sub = $pdo->prepare('INSERT INTO online_training_subscriptions (trainer_id, athlete_id, total_trainings, remaining_trainings, price, purchased_at) VALUES (?, ?, ?, ?, ?, NOW())');
                $sub->execute([$coachId, (int)$training['athlete_id'], $subscriptionTotal, $subscriptionTotal, max(0, $subscriptionPrice)]);
                $subscriptionId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO online_training_billing (subscription_id, trainer_id, athlete_id, billing_type, description, amount, billing_date) VALUES (?, ?, ?, \'subscription\', ?, ?, CURDATE())')
                    ->execute([$subscriptionId, $coachId, (int)$training['athlete_id'], 'Online tréninky - balík ' . $subscriptionTotal . ' tréninků', max(0, $subscriptionPrice)]);
            }
            if (!$subscriptionId) throw new RuntimeException('Vyberte aktivní předplatné nebo zadejte nový balík.');
            $subStmt = $pdo->prepare('SELECT * FROM online_training_subscriptions WHERE id = ? AND trainer_id = ? AND athlete_id = ? AND status = \'active\' AND remaining_trainings > 0 FOR UPDATE');
            $subStmt->execute([$subscriptionId, $coachId, (int)$training['athlete_id']]);
            $sub = $subStmt->fetch();
            if (!$sub) throw new RuntimeException('Předplatné nemá volný trénink.');
            $pdo->prepare('UPDATE online_training_subscriptions SET used_trainings = used_trainings + 1, remaining_trainings = remaining_trainings - 1, status = IF(remaining_trainings <= 1, \'exhausted\', \'active\') WHERE id = ?')->execute([$subscriptionId]);
        }
        $pdo->prepare('UPDATE online_trainings SET status = \'sent\', sent_at = NOW(), billing_type = ?, price = ?, subscription_id = ? WHERE id = ?')
            ->execute([$billingType, $price, $subscriptionId, $trainingId]);
        if ($billingType === 'single' && $price > 0) {
            $pdo->prepare('INSERT INTO online_training_billing (online_training_id, trainer_id, athlete_id, billing_type, description, amount, billing_date) VALUES (?, ?, ?, \'single\', ?, ?, CURDATE())')
                ->execute([$trainingId, $coachId, (int)$training['athlete_id'], 'Online trénink #' . str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT), $price]);
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
        sendAthleteCalendarNotificationEmail((string)$athlete['email'], trim($athlete['first_name'] . ' ' . $athlete['last_name']), 'Nový online trénink', 'Trenér vám odeslal nový online trénink. Otevřete jej v aplikaci: ' . $url);
    }
}

function onlineTrainingNotifyCompleted(PDO $pdo, array $training): void {
    $number = str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT);
    $subject = 'Dokončený online trénink #' . $number;
    $body = 'Sportovec ' . trim($training['first_name'] . ' ' . $training['last_name']) . ' dokončil online trénink #' . $number . '. Otevřít: ' . BASE_URL . '/online_training.php?id=' . (int)$training['id'];
    createCoachSystemMessage((int)$training['trainer_id'], $subject, $body, true);
}
