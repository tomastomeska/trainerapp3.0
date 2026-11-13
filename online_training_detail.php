<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_training.php';
$id = (int)($_GET['id'] ?? $_POST['training_id'] ?? 0); $pdo = getDB();
$isCoach = isLoggedIn();
if ($isCoach) {
    requireLogin(); $coachId = (int)getCurrentCoachId(); $training = onlineTrainingLoadForCoach($pdo, $id, $coachId); $back = BASE_URL . '/online_training.php';
} else {
    requireAthleteLogin(); $athleteId = (int)getCurrentAthleteId(); $training = onlineTrainingLoadForAthlete($pdo, $id, $athleteId); $back = BASE_URL . '/online_training.php';
}
if (!$training) { flash('danger', 'Online trénink nebyl nalezen.'); redirect($back); }
if ($isCoach && $training['status'] === 'completed') {
    $pdo->prepare("UPDATE online_trainings SET trainer_reviewed_at = NOW() WHERE id = ? AND trainer_id = ? AND status = 'completed' AND trainer_reviewed_at IS NULL")
        ->execute([$id, $coachId]);
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) $error = 'Neplatný bezpečnostní token.';
    else try {
        $action = (string)($_POST['action'] ?? '');
        if ($isCoach && $action === 'send') {
            onlineTrainingSend($pdo, $id, $coachId, isset($_POST['coach_note']) ? (string)$_POST['coach_note'] : null);
            flash('success', 'Online trénink byl odeslán.'); redirect(BASE_URL . '/online_training.php?id=' . $id);
        }
        if ($isCoach && $action === 'note') {
            $pdo->prepare('UPDATE online_trainings SET coach_note = ? WHERE id = ? AND trainer_id = ?')->execute([trim((string)$_POST['coach_note']), $id, $coachId]);
        }
        if ($isCoach && $action === 'coach_reaction') {
            if ($training['status'] !== 'completed') throw new RuntimeException('Na dokončený trénink můžete reagovat až po jeho ukončení sportovcem.');
            $reaction = trim((string)($_POST['coach_reaction'] ?? ''));
            if ($reaction === '') throw new RuntimeException('Napište reakci před jejím odesláním sportovci.');
            $pdo->prepare("UPDATE online_trainings SET coach_reaction = ?, trainer_reviewed_at = COALESCE(trainer_reviewed_at, NOW()) WHERE id = ? AND trainer_id = ? AND status = 'completed'")
                ->execute([$reaction, $id, $coachId]);
            $number = str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT);
            createAthleteNotification(
                (int)$training['athlete_id'],
                'Reakce trenéra k online tréninku',
                'Trenér vám poslal reakci k online tréninku #' . $number . ': ' . $reaction . ' Otevřít: ' . BASE_URL . '/online_training.php?id=' . $id
            );
            flash('success', 'Reakce byla odeslána sportovci.');
            redirect(BASE_URL . '/online_training.php?id=' . $id);
        }
        if ($isCoach && $action === 'prescription') {
            if ((string)$training['status'] !== 'created') throw new RuntimeException('Předepsané hodnoty lze měnit jen před odesláním.');
            $updateSeries = $pdo->prepare('UPDATE online_training_series s JOIN online_training_exercises e ON e.id = s.online_training_exercise_id SET s.prescribed_weight = ?, s.prescribed_reps = ?, s.prescribed_duration_seconds = ? WHERE s.id = ? AND e.online_training_id = ?');
            foreach ((array)($_POST['prescribed'] ?? []) as $seriesId => $values) {
                $updateSeries->execute([
                    ($values['weight'] ?? '') === '' ? null : (float)$values['weight'],
                    ($values['reps'] ?? '') === '' ? null : (int)$values['reps'],
                    ($values['duration'] ?? '') === '' ? null : (int)$values['duration'],
                    (int)$seriesId,
                    $id,
                ]);
            }
        }
        if ($isCoach && $action === 'save_prescription_row') {
            if ((string)$training['status'] !== 'created') throw new RuntimeException('Předepsané hodnoty lze měnit jen před odesláním.');
            $seriesId = (int)($_POST['series_id'] ?? 0);
            $seriesCheck = $pdo->prepare('SELECT s.id FROM online_training_series s JOIN online_training_exercises e ON e.id = s.online_training_exercise_id WHERE s.id = ? AND e.online_training_id = ?');
            $seriesCheck->execute([$seriesId, $id]);
            if (!$seriesCheck->fetch()) throw new RuntimeException('Série nebyla nalezena.');
            $weight = ($_POST['weight'] ?? '') === '' ? null : (float)$_POST['weight'];
            $reps = ($_POST['reps'] ?? '') === '' ? null : (int)$_POST['reps'];
            $duration = ($_POST['duration'] ?? '') === '' ? null : (int)$_POST['duration'];
            $savePrescription = $pdo->prepare('UPDATE online_training_series SET prescribed_weight = ?, prescribed_reps = ?, prescribed_duration_seconds = ? WHERE id = ?');
            $savePrescription->execute([$weight, $reps, $duration, $seriesId]);
        }
        if ($isCoach && in_array($action, ['add_series', 'delete_series'], true)) {
            if ((string)$training['status'] !== 'created') throw new RuntimeException('Série lze měnit jen před odesláním.');
            $exerciseId = (int)($_POST['online_exercise_id'] ?? 0);
            $seriesId = (int)($_POST['online_series_id'] ?? 0);
            if ($action === 'add_series') {
                $check = $pdo->prepare('SELECT id FROM online_training_exercises WHERE id = ? AND online_training_id = ?');
                $check->execute([$exerciseId, $id]);
                if (!$check->fetch()) throw new RuntimeException('Cvik nebyl nalezen.');
                $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(series_order), 0) + 1 FROM online_training_series WHERE online_training_exercise_id = ?');
                $orderStmt->execute([$exerciseId]);
                $pdo->prepare('INSERT INTO online_training_series (online_training_exercise_id, series_order) VALUES (?, ?)')->execute([$exerciseId, (int)$orderStmt->fetchColumn()]);
            } else {
                $pdo->prepare('DELETE s FROM online_training_series s JOIN online_training_exercises e ON e.id = s.online_training_exercise_id WHERE s.id = ? AND e.online_training_id = ?')->execute([$seriesId, $id]);
            }
        }
        if ($isCoach && $action === 'set_series_count') {
            if ((string)$training['status'] !== 'created') throw new RuntimeException('Počet sérií lze měnit jen před odesláním.');
            $onlineExerciseId = (int)($_POST['online_exercise_id'] ?? 0);
            $targetCount = max(1, min(20, (int)($_POST['series_count'] ?? 1)));
            $exerciseCheck = $pdo->prepare('SELECT id FROM online_training_exercises WHERE id = ? AND online_training_id = ?');
            $exerciseCheck->execute([$onlineExerciseId, $id]);
            if (!$exerciseCheck->fetch()) throw new RuntimeException('Cvik nebyl nalezen.');
            $seriesStmt = $pdo->prepare('SELECT id, series_order FROM online_training_series WHERE online_training_exercise_id = ? ORDER BY series_order, id');
            $seriesStmt->execute([$onlineExerciseId]);
            $seriesRows = $seriesStmt->fetchAll();
            $currentCount = count($seriesRows);
            if ($targetCount > $currentCount) {
                $insertSeries = $pdo->prepare('INSERT INTO online_training_series (online_training_exercise_id, series_order) VALUES (?, ?)');
                for ($order = $currentCount + 1; $order <= $targetCount; $order++) $insertSeries->execute([$onlineExerciseId, $order]);
            } elseif ($targetCount < $currentCount) {
                $deleteSeries = $pdo->prepare('DELETE FROM online_training_series WHERE id = ? AND online_training_exercise_id = ?');
                foreach (array_slice($seriesRows, $targetCount) as $seriesRow) $deleteSeries->execute([(int)$seriesRow['id'], $onlineExerciseId]);
            }
        }
        if ($isCoach && in_array($action, ['add_exercise', 'remove_exercise', 'reorder_exercises'], true)) {
            if ((string)$training['status'] !== 'created') throw new RuntimeException('Cviky lze měnit jen před odesláním.');
            if ($action === 'add_exercise') {
                $exerciseId = (int)($_POST['exercise_id'] ?? 0);
                $exerciseStmt = $pdo->prepare('SELECT id, name, sport_type, is_timed FROM exercises WHERE id = ? AND (coach_id = ? OR is_global = 1) LIMIT 1');
                $exerciseStmt->execute([$exerciseId, $coachId]);
                $exercise = $exerciseStmt->fetch();
                if (!$exercise) throw new RuntimeException('Cvik nebyl nalezen.');
                $duplicate = $pdo->prepare('SELECT id FROM online_training_exercises WHERE online_training_id = ? AND exercise_id = ? LIMIT 1');
                $duplicate->execute([$id, $exerciseId]);
                if ($duplicate->fetch()) {
                    flash('success', 'Cvik už v online tréninku byl, proto nebyl přidán znovu.');
                    redirect(BASE_URL . '/online_training.php?id=' . $id);
                }
                $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(exercise_order), 0) + 1 FROM online_training_exercises WHERE online_training_id = ?');
                $orderStmt->execute([$id]);
                $pdo->prepare('INSERT INTO online_training_exercises (online_training_id, exercise_id, exercise_order, exercise_name, sport_type, is_timed) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$id, $exerciseId, (int)$orderStmt->fetchColumn(), $exercise['name'], $exercise['sport_type'] ?? 'standard', (int)($exercise['is_timed'] ?? 0)]);
                $newExerciseId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO online_training_series (online_training_exercise_id, series_order) VALUES (?, 1)')->execute([$newExerciseId]);
            } elseif ($action === 'remove_exercise') {
                $onlineExerciseId = (int)($_POST['online_exercise_id'] ?? 0);
                $pdo->prepare('DELETE FROM online_training_exercises WHERE id = ? AND online_training_id = ?')->execute([$onlineExerciseId, $id]);
                $pdo->prepare('SET @online_order := 0')->execute();
                $orderRows = $pdo->prepare('SELECT id FROM online_training_exercises WHERE online_training_id = ? ORDER BY exercise_order, id');
                $orderRows->execute([$id]);
                $reorder = $pdo->prepare('UPDATE online_training_exercises SET exercise_order = ? WHERE id = ? AND online_training_id = ?');
                $order = 1;
                foreach ($orderRows->fetchAll() as $row) $reorder->execute([$order++, (int)$row['id'], $id]);
            } else {
                $submittedOrder = (array)($_POST['exercise_order'] ?? []);
                if ($submittedOrder !== [] && array_keys($submittedOrder) !== range(0, count($submittedOrder) - 1)) {
                    asort($submittedOrder, SORT_NUMERIC);
                    $orderedIds = array_map('intval', array_keys($submittedOrder));
                } else {
                    $orderedIds = array_values(array_filter(array_map('intval', $submittedOrder), static fn(int $value): bool => $value > 0));
                }
                $allowedStmt = $pdo->prepare('SELECT id FROM online_training_exercises WHERE online_training_id = ?');
                $allowedStmt->execute([$id]);
                $allowed = array_map('intval', array_column($allowedStmt->fetchAll(), 'id'));
                if (count($orderedIds) !== count($allowed) || array_diff($orderedIds, $allowed) || array_diff($allowed, $orderedIds)) throw new RuntimeException('Neplatné pořadí cviků.');
                $reorder = $pdo->prepare('UPDATE online_training_exercises SET exercise_order = ? WHERE id = ? AND online_training_id = ?');
                foreach ($orderedIds as $index => $onlineExerciseId) $reorder->execute([$index + 1, $onlineExerciseId, $id]);
            }
        }
        if ($action === 'attachment') {
            if ($isCoach && $training['status'] === 'completed') {
                throw new RuntimeException('K dokončenému tréninku už nelze přidávat přílohy.');
            }
            if (!$isCoach && !in_array($training['status'], ['in_progress', 'completed'], true)) {
                throw new RuntimeException('Přílohu můžete přidat po zahájení online tréninku.');
            }
            if ($training['status'] === 'completed' && ($isCoach || !empty($training['trainer_reviewed_at']) || trim((string)($training['coach_reaction'] ?? '')) !== '')) {
                throw new RuntimeException('K již zkontrolovanému tréninku nelze přidávat přílohy.');
            }
            $attachment = null;
            $url = trim((string)($_POST['external_url'] ?? ''));
            $description = $isCoach ? trim((string)($_POST['link_description'] ?? '')) : '';
            $libraryVideoId = (int)($_POST['video_file_id'] ?? 0);
            if ($url !== '') onlineTrainingValidateLink($url, $description);
            elseif ($description !== '' && $libraryVideoId <= 0) throw new RuntimeException('K popisu odkazu zadejte také internetovou adresu.');
            $targetExerciseId = (int)($_POST['online_training_exercise_id'] ?? 0);
            if (!$isCoach && $targetExerciseId > 0) {
                throw new RuntimeException('Přílohu můžete přidat pouze k celému tréninku, ne ke konkrétnímu cviku.');
            }
            if ($targetExerciseId > 0) {
                $targetCheck = $pdo->prepare('SELECT id FROM online_training_exercises WHERE id = ? AND online_training_id = ?');
                $targetCheck->execute([$targetExerciseId, $id]);
                if (!$targetCheck->fetch()) throw new RuntimeException('Cílový cvik nebyl nalezen.');
            }
            $libraryVideo = null;
            if ($libraryVideoId > 0) {
                if (!$isCoach || $targetExerciseId <= 0) {
                    throw new RuntimeException('Video z knihovny lze přidat pouze k cviku jako trenér.');
                }
                $videoStmt = $pdo->prepare('SELECT id, original_name FROM video_files WHERE id = ? AND coach_id = ? AND archived_at IS NULL LIMIT 1');
                $videoStmt->execute([$libraryVideoId, $coachId]);
                $libraryVideo = $videoStmt->fetch();
                if (!$libraryVideo) throw new RuntimeException('Vybrané video nebylo nalezeno ve vaší knihovně.');
                if (strlen($description) > 10000) throw new RuntimeException('Vysvětlení videa je příliš dlouhé (nejvýše 10 000 bajtů).');
                $hasUploadedFile = !empty($_FILES['attachment'])
                    && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if ($hasUploadedFile || $url !== '') {
                    throw new RuntimeException('Vyberte video z knihovny, nebo přidejte soubor či odkaz. Tyto možnosti nelze kombinovat.');
                }
            }
            try {
                $pdo->beginTransaction();
                if ($libraryVideo) {
                    $pdo->prepare("INSERT INTO online_training_attachments (online_training_id, online_training_exercise_id, attachment_type, video_file_id, original_name, description, uploaded_by) VALUES (?, ?, 'video', ?, ?, ?, 'trainer')")
                        ->execute([$id, $targetExerciseId, $libraryVideoId, $libraryVideo['original_name'], $description !== '' ? $description : null]);
                } else {
                    if (!empty($_FILES['attachment'])) $attachment = onlineTrainingSaveUpload($_FILES['attachment'], $isCoach ? 'trainer' : 'athlete');
                    if (!$attachment && $url === '') throw new RuntimeException('Vyberte soubor nebo zadejte URL.');
                }
                if ($attachment) {
                    $pdo->prepare('INSERT INTO online_training_attachments (online_training_id, online_training_exercise_id, attachment_type, file_path, original_name, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $targetExerciseId ?: null, $attachment['type'], $attachment['path'], $attachment['name'], $attachment['uploaded_by']]);
                }
                if (!$libraryVideo && $url !== '') $pdo->prepare("INSERT INTO online_training_attachments (online_training_id, online_training_exercise_id, attachment_type, external_url, description, uploaded_by) VALUES (?, ?, 'url', ?, ?, ?)")->execute([$id, $targetExerciseId ?: null, $url, $description !== '' ? $description : null, $isCoach ? 'trainer' : 'athlete']);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($attachment) {
                    $uploadedPath = __DIR__ . '/uploads/' . $attachment['path'];
                    if (is_file($uploadedPath) && !unlink($uploadedPath)) error_log('Nepodařilo se odstranit nenavázanou přílohu online tréninku: ' . $uploadedPath);
                }
                throw $e;
            }
            flash('success', 'Příloha byla přidána k online tréninku.');
            $attachmentAnchor = $targetExerciseId > 0 ? '#online-exercise-attachments-' . $targetExerciseId : '#online-attachments';
            redirect(BASE_URL . '/online_training.php?id=' . $id . $attachmentAnchor);
        }
        if ($action === 'delete_attachment') {
            if ($training['status'] === 'completed' && ($isCoach || !empty($training['trainer_reviewed_at']) || trim((string)($training['coach_reaction'] ?? '')) !== '')) {
                throw new RuntimeException('Přílohy již zkontrolovaného tréninku nelze upravovat.');
            }
            if (!$isCoach && !in_array($training['status'], ['in_progress', 'completed'], true)) {
                throw new RuntimeException('Přílohy můžete upravovat po zahájení online tréninku.');
            }
            $attachmentId = (int)($_POST['attachment_id'] ?? 0);
            $attachmentStmt = $pdo->prepare('SELECT file_path FROM online_training_attachments WHERE id = ? AND online_training_id = ? AND uploaded_by = ?');
            $attachmentStmt->execute([$attachmentId, $id, $isCoach ? 'trainer' : 'athlete']);
            $attachmentRow = $attachmentStmt->fetch();
            if ($attachmentRow) {
                $pdo->prepare('DELETE FROM online_training_attachments WHERE id = ? AND online_training_id = ?')->execute([$attachmentId, $id]);
                if (!empty($attachmentRow['file_path'])) @unlink(__DIR__ . '/uploads/' . ltrim((string)$attachmentRow['file_path'], '/'));
            }
        }
        if (!$isCoach && $action === 'start') {
            $pdo->prepare("UPDATE online_trainings SET status = 'in_progress', started_at = COALESCE(started_at, NOW()) WHERE id = ? AND athlete_id = ? AND status = 'sent'")->execute([$id, $athleteId]);
        }
        if (!$isCoach && $action === 'save_all') {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT id FROM online_trainings WHERE id = ? AND athlete_id = ? AND status = 'in_progress' FOR UPDATE");
            $lock->execute([$id, $athleteId]);
            if (!$lock->fetch()) throw new RuntimeException('Trénink není rozpracovaný nebo k němu nemáte přístup.');
            onlineTrainingSaveAthleteResults($pdo, $id, $athleteId, $_POST);
            $pdo->commit();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $isCompletingTraining = !$isCoach && ($action === 'complete' || isset($_POST['complete_training']));
        if (!$isCoach && in_array($action, ['save', 'complete'], true)) {
            if ((string)$training['status'] === 'completed') throw new RuntimeException('Dokončený online trénink už nelze upravovat.');
            $result = $pdo->prepare('INSERT INTO online_training_results (online_training_id, online_training_series_id, actual_weight, actual_reps, actual_duration_seconds, note) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE actual_weight = VALUES(actual_weight), actual_reps = VALUES(actual_reps), actual_duration_seconds = VALUES(actual_duration_seconds), note = VALUES(note)');
            foreach ((array)($_POST['series'] ?? []) as $seriesId => $values) {
                $seriesId = (int)$seriesId;
                $seriesCheck = $pdo->prepare('SELECT s.id FROM online_training_series s JOIN online_training_exercises e ON e.id = s.online_training_exercise_id WHERE s.id = ? AND e.online_training_id = ?');
                $seriesCheck->execute([$seriesId, $id]);
                if ($seriesCheck->fetch()) {
                    $result->execute([$id, $seriesId, ($values['weight'] ?? '') === '' ? null : (float)$values['weight'], ($values['reps'] ?? '') === '' ? null : (int)$values['reps'], ($values['duration'] ?? '') === '' ? null : (int)$values['duration'], trim((string)($values['note'] ?? '')) ?: null]);
                }
            }
            $pdo->prepare('UPDATE online_trainings SET athlete_note = ? WHERE id = ? AND athlete_id = ?')->execute([trim((string)$_POST['athlete_note']) ?: null, $id, $athleteId]);
        }
        if (!$isCoach && $action === 'save_series_row') {
            if ((string)$training['status'] === 'completed') throw new RuntimeException('Dokončený online trénink už nelze upravovat.');
            $seriesId = (int)($_POST['series_id'] ?? 0);
            $seriesCheck = $pdo->prepare('SELECT s.id FROM online_training_series s JOIN online_training_exercises e ON e.id = s.online_training_exercise_id WHERE s.id = ? AND e.online_training_id = ?');
            $seriesCheck->execute([$seriesId, $id]);
            if (!$seriesCheck->fetch()) throw new RuntimeException('Série nebyla nalezena.');
            $weight = ($_POST['weight'] ?? '') === '' ? null : (float)$_POST['weight'];
            $reps = ($_POST['reps'] ?? '') === '' ? null : (int)$_POST['reps'];
            $duration = ($_POST['duration'] ?? '') === '' ? null : (int)$_POST['duration'];
            $note = trim((string)($_POST['note'] ?? '')) ?: null;
            $existingResult = $pdo->prepare('SELECT id FROM online_training_results WHERE online_training_id = ? AND online_training_series_id = ? ORDER BY id DESC LIMIT 1');
            $existingResult->execute([$id, $seriesId]);
            if ($existingResult->fetch()) {
                $updateResult = $pdo->prepare('UPDATE online_training_results SET actual_weight = ?, actual_reps = ?, actual_duration_seconds = ?, note = ?, updated_at = NOW() WHERE online_training_id = ? AND online_training_series_id = ?');
                $updateResult->execute([$weight, $reps, $duration, $note, $id, $seriesId]);
            } else {
                $insertResult = $pdo->prepare('INSERT INTO online_training_results (online_training_id, online_training_series_id, actual_weight, actual_reps, actual_duration_seconds, note) VALUES (?, ?, ?, ?, ?, ?)');
                $insertResult->execute([$id, $seriesId, $weight, $reps, $duration, $note]);
            }
            $savedStmt = $pdo->prepare('SELECT actual_weight, actual_reps, actual_duration_seconds FROM online_training_results WHERE online_training_id = ? AND online_training_series_id = ? ORDER BY id DESC LIMIT 1');
            $savedStmt->execute([$id, $seriesId]);
            $saved = $savedStmt->fetch();
            if (!$saved || ($weight !== null && (float)$saved['actual_weight'] !== $weight) || ($reps !== null && (int)$saved['actual_reps'] !== $reps)) {
                throw new RuntimeException('Databáze nevrátila právě uložené hodnoty série.');
            }
        }
        if ($isCompletingTraining) {
            $startedStmt = $pdo->prepare('SELECT started_at FROM online_trainings WHERE id = ? AND athlete_id = ? AND status = \'in_progress\''); $startedStmt->execute([$id, $athleteId]); $startedAt = $startedStmt->fetchColumn();
            if (!$startedAt) throw new RuntimeException('Trénink musí být nejdříve zahájen.');
            $duration = max(0, time() - (strtotime((string)$startedAt) ?: time()));
            $pdo->prepare("UPDATE online_trainings SET status = 'completed', completed_at = NOW(), duration_seconds = ? WHERE id = ? AND athlete_id = ? AND status = 'in_progress'")->execute([$duration, $id, $athleteId]);
            $completed = onlineTrainingLoadForCoach($pdo, $id, (int)$training['trainer_id']);
            if ($completed) onlineTrainingNotifyCompleted($pdo, $completed);
            flash('success', 'Online trénink byl dokončen.'); redirect(BASE_URL . '/online_training.php?id=' . $id);
        }
        $training = $isCoach ? onlineTrainingLoadForCoach($pdo, $id, $coachId) : onlineTrainingLoadForAthlete($pdo, $id, $athleteId);
        if (!$isCoach && in_array($action, ['save', 'save_series_row'], true) && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($isCoach && in_array($action, ['add_series', 'set_series_count'], true) && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($isCoach && $action === 'save_prescription_row' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$isCoach && $action === 'save_all') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $error = $e->getMessage();
    }
}
$exercises = onlineTrainingLoadExercises($pdo, $id);
$lastCompletedByExercise = [];
if ($isCoach && $training['status'] === 'created') {
    foreach ($exercises as $exercise) {
        $lastCompletedByExercise[(int)$exercise['id']] = onlineTrainingLastCompletedExercise(
            $pdo,
            (int)$training['athlete_id'],
            (int)$exercise['exercise_id']
        );
    }
}
$availableExercises = [];
if ($isCoach && $training['status'] === 'created') {
    $availableStmt = $pdo->prepare('SELECT e.id, e.name FROM exercises e WHERE (e.coach_id = ? OR e.is_global = 1) AND NOT EXISTS (SELECT 1 FROM online_training_exercises ote WHERE ote.online_training_id = ? AND ote.exercise_id = e.id) ORDER BY e.name');
    $availableStmt->execute([$coachId, $id]);
    $availableExercises = $availableStmt->fetchAll();
}
$libraryVideos = [];
if ($isCoach) {
    $libraryVideoStmt = $pdo->prepare('SELECT id, original_name FROM video_files WHERE coach_id = ? AND archived_at IS NULL ORDER BY original_name, id');
    $libraryVideoStmt->execute([$coachId]);
    $libraryVideos = $libraryVideoStmt->fetchAll();
}
$attachmentStmt = $pdo->prepare('SELECT ota.*, ote.exercise_name FROM online_training_attachments ota LEFT JOIN online_training_exercises ote ON ote.id = ota.online_training_exercise_id WHERE ota.online_training_id = ? ORDER BY ota.created_at, ota.id');
$attachmentStmt->execute([$id]);
$attachments = $attachmentStmt->fetchAll();
$canViewAthleteAttachments = !$isCoach || $training['status'] === 'completed';
$coachHasReacted = trim((string)($training['coach_reaction'] ?? '')) !== '';
$canManageAttachments = ($isCoach && $training['status'] !== 'completed')
    || (!$isCoach && in_array($training['status'], ['in_progress', 'completed'], true) && empty($training['trainer_reviewed_at']) && !$coachHasReacted);
$attachments = array_values(array_filter(
    $attachments,
    static fn(array $attachment): bool => $canViewAthleteAttachments || $attachment['uploaded_by'] !== 'athlete'
));
$activeSubscription = $isCoach ? onlineTrainingActiveSubscription($pdo, $coachId, (int)$training['athlete_id']) : null;
$ownRole = $isCoach ? 'trainer' : 'athlete';
$renderAttachmentCards = static function (array $items) use ($id, $ownRole, $canManageAttachments): void {
    foreach ($items as $attachment):
?>
<div class="online-training-attachment">
<?php if ($attachment['attachment_type'] === 'photo'): ?>
<a href="<?= BASE_URL ?>/uploads/<?= h($attachment['file_path']) ?>" class="online-attachment-open" data-media-type="photo" target="_blank" rel="noopener">
<img src="<?= BASE_URL ?>/uploads/<?= h($attachment['file_path']) ?>" alt="Fotografie k online tréninku">
</a>
<?php elseif ($attachment['attachment_type'] === 'video'): ?>
<?php $videoSrc = !empty($attachment['video_file_id']) ? BASE_URL . '/video_stream.php?id=' . (int)$attachment['video_file_id'] : BASE_URL . '/uploads/' . (string)$attachment['file_path']; ?>
<button type="button" class="online-video-open" data-video-src="<?= h($videoSrc) ?>" aria-label="Přehrát video<?= !empty($attachment['original_name']) ? ': ' . h((string)$attachment['original_name']) : '' ?>">
<video muted playsinline preload="metadata"><source src="<?= h($videoSrc) ?>"></video>
<span class="online-video-open__play" aria-hidden="true"><i class="fas fa-play"></i></span>
</button>
<?php if (!empty($attachment['description'])): ?><div class="small mt-2 text-break" style="white-space:pre-wrap"><?= h((string)$attachment['description']) ?></div><?php endif; ?>
<?php else: ?>
<a href="<?= h($attachment['external_url']) ?>" target="_blank" rel="noopener noreferrer">Otevřít internetový odkaz</a>
<?php if (!empty($attachment['description'])): ?><div class="small mt-2 text-break" style="white-space:pre-wrap"><?= h((string)$attachment['description']) ?></div><?php endif; ?>
<?php endif; ?>
<?php if (!empty($attachment['original_name'])): ?><div class="small text-break mt-1"><?= h((string)$attachment['original_name']) ?></div><?php endif; ?>
<?php if ($attachment['uploaded_by'] === $ownRole && $canManageAttachments): ?>
<form method="post" class="mt-2">
<?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>">
<input type="hidden" name="action" value="delete_attachment"><input type="hidden" name="attachment_id" value="<?= (int)$attachment['id'] ?>">
<button class="btn btn-sm btn-outline-danger">Smazat přílohu</button>
</form>
<?php endif; ?>
</div>
<?php
    endforeach;
};
if ($isCoach) { require_once __DIR__ . '/includes/header.php'; renderHeader('Online trénink #' . $training['sequence_number'], false, true); } else { require_once __DIR__ . '/includes/athlete_header.php'; renderAthleteHeader('Online trénink #' . $training['sequence_number'], false, true); }
?><style>
.online-draft-step { border-left: 4px solid #e6b800; }
.online-draft-step__number { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 50%; background: #fff3cd; color: #664d03; font-weight: 800; margin-right: .55rem; }
.online-builder-grid { display: grid; grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); gap: 1.25rem; }
.online-builder-panel { min-width: 0; padding: 1rem; border: 1px solid #dee2e6; border-radius: .65rem; background: #fff; }
.online-builder-panel__title { margin-bottom: .85rem; font-size: 1rem; font-weight: 700; }
.online-builder-order { display: grid; grid-template-columns: minmax(0, 1fr) 5rem; gap: .5rem; margin-bottom: .5rem; }
.online-builder-order .form-control { text-align: center; }
.online-exercise-card { border: 2px solid #c6d1de; border-radius: .75rem; overflow: hidden; box-shadow: 0 .15rem .45rem rgba(33, 37, 41, .08); }
.online-exercise-card__header { background: #f8f9fa; }
.online-results-table thead th { white-space: nowrap; }
.online-results-table .online-results-table__prescribed { background-color: #fff7df; }
.online-results-table .online-results-table__actual { background-color: #e8f4ff; }
.online-coach-results-table thead .online-results-table__coach-group { background: #f5e8c5; color: #795900; text-align: center; }
.online-coach-results-table thead .online-results-table__athlete-group { background: #d7e8f7; color: #084b83; text-align: center; }
.online-coach-results-table thead .online-results-table__prescribed { background: #fff1c2; }
.online-coach-results-table thead .online-results-table__actual { background: #deedfb; }
.online-coach-results-table .online-results-table__athlete-start { border-left: 3px solid #9db8d1; }
.online-athlete-results-table thead .online-results-table__coach-group { background: #f5e8c5; color: #795900; text-align: center; }
.online-athlete-results-table thead .online-results-table__athlete-group { background: #d7e8f7; color: #084b83; text-align: center; }
.online-athlete-results-table thead .online-results-table__athlete-start,
.online-athlete-results-table tbody .online-results-table__athlete-start { border-left: 3px solid #9db8d1; }
.online-athlete-results-table thead .online-results-table__prescribed { background: #fff1c2; }
.online-athlete-results-table thead .online-results-table__actual { background: #deedfb; }
.online-athlete-results-table > tbody > tr.online-exercise-row--shaded > * { background: #f1f3f5 !important; box-shadow: none !important; }
.online-athlete-results-table > tbody > tr.online-exercise-row--plain > * { background: #fff !important; box-shadow: none !important; }
.online-athlete-results-table > tbody > tr.online-exercise-row--shaded > .online-results-table__prescribed { background: #f3ead4 !important; }
.online-athlete-results-table > tbody > tr.online-exercise-row--plain > .online-results-table__prescribed { background: #fff7df !important; }
.online-athlete-results-table > tbody > tr.online-exercise-row--shaded > .online-results-table__actual { background: #dce8f2 !important; }
.online-athlete-results-table > tbody > tr.online-exercise-row--plain > .online-results-table__actual { background: #e8f4ff !important; }
.online-coach-results-table .online-result-difference { color: #b42318 !important; font-weight: 700 !important; }
.online-coach-results-table > tbody > tr.online-exercise-row--shaded > * { background: #f1f3f5 !important; box-shadow: none !important; }
.online-coach-results-table > tbody > tr.online-exercise-row--plain > * { background: #fff !important; box-shadow: none !important; }
.online-coach-results-table > tbody > tr.online-exercise-row--shaded > .online-results-table__prescribed { background: #f3ead4 !important; }
.online-coach-results-table > tbody > tr.online-exercise-row--plain > .online-results-table__prescribed { background: #fff7df !important; }
.online-coach-results-table > tbody > tr.online-exercise-row--shaded > .online-results-table__actual { background: #dce8f2 !important; }
.online-coach-results-table > tbody > tr.online-exercise-row--plain > .online-results-table__actual { background: #e8f4ff !important; }
.online-results-table thead .online-results-table__prescribed { color: #795900; }
.online-results-table thead .online-results-table__actual { color: #084b83; }
.online-exercise-attachments { border-top: 1px solid #dee2e6; background: #fbfcfd; padding: 1rem; }
.online-exercise-attachments__disclosure { border: 1px solid #dee2e6; border-radius: .5rem; background: #fff; }
.online-exercise-attachments__disclosure summary { padding: .65rem .85rem; color: #155eef; font-weight: 600; cursor: pointer; }
.online-exercise-attachments__disclosure[open] summary { border-bottom: 1px solid #dee2e6; }
.online-exercise-attachments__form { padding: .85rem; }
.online-training-attachment { width: 200px; max-width: 100%; padding: .5rem; border: 1px solid #dee2e6; border-radius: .5rem; background: #fff; }
.online-training-attachment img, .online-training-attachment video { width: 100%; max-height: 150px; object-fit: cover; border-radius: .4rem; }
.online-video-open { position: relative; display: block; width: 100%; padding: 0; overflow: hidden; border: 0; border-radius: .4rem; background: #111827; cursor: pointer; }
.online-video-open__play { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; font-size: 2rem; background: rgba(15, 23, 42, .22); pointer-events: none; }
.online-send-step { border: 2px solid #e6b800; }
@media (max-width: 767.98px) { .online-builder-grid { grid-template-columns: 1fr; } }
@media print {
    @page { size: A4 landscape; margin: 12mm; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    html, body { background: #fff !important; color: #1f2937 !important; font-size: 9pt; }
    body, body.dashboard-sidebar-layout { margin: 0 !important; padding-left: 0 !important; }
    .dashboard-sidebar, .dashboard-sidebar-controls, .navbar, .footer,
    .athlete-compact-topbar, .online-no-print, form, button,
    .online-attachment-modal, .online-exercise-attachments__disclosure,
    .online-draft-step, .online-send-step { display: none !important; }
    .container-fluid { width: 100% !important; max-width: none !important; padding: 0 !important; margin: 0 !important; }
    .online-print-meta { display: block !important; margin-bottom: 5mm; color: #64748b; font-size: 8pt; }
    .online-training-page-header { border-bottom: 2px solid #e6b800; padding-bottom: 3mm; margin-bottom: 5mm !important; }
    .online-training-page-header h2 { color: #111827 !important; font-size: 18pt; }
    .card, .online-exercise-card { border: 1px solid #cbd5e1 !important; box-shadow: none !important; break-inside: avoid; }
    .card-header { background: #f1f5f9 !important; color: #111827 !important; border-bottom: 1px solid #cbd5e1 !important; font-weight: 700; }
    .table-responsive { overflow: visible !important; }
    .online-results-table { width: 100% !important; font-size: 8pt; }
    .online-results-table th, .online-results-table td { padding: .3rem .4rem !important; border: 1px solid #cbd5e1 !important; }
    .online-results-table thead { display: table-header-group; }
    .online-results-table tr { break-inside: avoid; }
    .online-results-table__prescribed { background: #fff7df !important; background-color: #fff7df !important; }
    .online-results-table__actual { background: #e8f4ff !important; background-color: #e8f4ff !important; }
    .alert { background: #fff !important; color: #1f2937 !important; border: 1px solid #cbd5e1 !important; border-left: 4px solid #94a3b8 !important; break-inside: avoid; }
    .online-training-attachment { width: 42mm; border-color: #cbd5e1 !important; break-inside: avoid; }
    .online-training-attachment img { max-height: 32mm; object-fit: contain; }
    .online-training-attachment video { display: none !important; }
    input, textarea, select { border: 0 !important; background: transparent !important; box-shadow: none !important; padding: 0 !important; color: #111827 !important; }
    a { color: #1f2937 !important; text-decoration: none !important; }
    .online-exercise-attachments { padding: 3mm !important; background: #fff !important; }
}
.online-print-meta { display: none; }
</style>
<div class="online-print-meta">TRAINERAPP · Online trénink #<?= str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT) ?> · Vytištěno <?= h(date('d.m.Y H:i')) ?></div>
<div class="online-training-page-header d-flex align-items-center gap-3 flex-wrap mb-4"><a href="<?= $back ?>" class="btn btn-outline-secondary btn-sm online-no-print">Zpět</a><h2 class="mb-0"><i class="fas fa-laptop me-2 text-warning"></i>ONLINE #<?= str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT) ?></h2><span class="badge bg-<?= h(onlineTrainingStatusClass($training['status'])) ?>"><?= h($isCoach || $training['status'] === 'sent' ? onlineTrainingStatusLabel($training['status']) : onlineTrainingAthleteStatusLabel($training['status'])) ?></span><button type="button" class="btn btn-outline-secondary btn-sm ms-auto online-no-print" onclick="window.print()"><i class="fas fa-print me-1"></i>Tisk / PDF</button></div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php
$subscriptionStartsOn = $training['subscription_starts_on'] ?? ($activeSubscription['starts_on'] ?? null);
$subscriptionEndsOn = $training['subscription_ends_on'] ?? ($activeSubscription['ends_on'] ?? null);
$isSubscriptionBilling = $training['billing_type'] === 'subscription' || ($isCoach && $training['status'] === 'created');
?>
<div class="row g-3 mb-4 align-items-stretch">
<div class="col-lg-8"><div class="card shadow-sm h-100"><div class="card-body">
<h4 class="mb-3"><?= h($training['title']) ?></h4>
<div class="row g-2 small">
<div class="col-sm-6"><strong><?= $isCoach ? 'Sportovec' : 'Trenér' ?>:</strong> <?= h($isCoach ? $training['first_name'] . ' ' . $training['last_name'] : $training['coach_name']) ?></div>
<?php if (!empty($training['sent_at'])): ?><div class="col-sm-6"><strong>Odesláno:</strong> <?= h(formatDateTime($training['sent_at'])) ?></div><?php endif; ?>
<?php if (!empty($training['started_at'])): ?><div class="col-sm-6"><strong>Začátek:</strong> <?= h(formatDateTime($training['started_at'])) ?></div><?php endif; ?>
<?php if (!empty($training['completed_at'])): ?><div class="col-sm-6"><strong>Konec:</strong> <?= h(formatDateTime($training['completed_at'])) ?> · <?= formatSeriesDuration((int)$training['duration_seconds']) ?></div><?php endif; ?>
</div>
</div></div></div>
<div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body">
<strong><i class="fas fa-receipt me-2 text-warning"></i>Účtování</strong>
<?php if ($isSubscriptionBilling): ?>
<div class="mt-2 fw-semibold">V rámci předplatného</div>
<?php if ($subscriptionStartsOn && $subscriptionEndsOn): ?><div class="small text-muted">Platnost <?= h(formatDate($subscriptionStartsOn)) ?>–<?= h(formatDate($subscriptionEndsOn)) ?></div><?php elseif ($isCoach && !$activeSubscription): ?><div class="small text-danger">Pro odeslání je nutné platné předplatné.</div><?php endif; ?>
<?php elseif ($training['billing_type'] === 'single'): ?><div class="mt-2"><?= number_format((float)$training['price'], 2, ',', ' ') ?> Kč</div>
<?php else: ?><div class="mt-2">Zdarma</div><?php endif; ?>
</div></div></div>
</div>
<?php if (!$isCoach && $training['status'] === 'sent'): ?><form method="post" class="mb-3"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="start"><button class="btn btn-warning btn-lg fw-bold">Zahájit online trénink</button></form><?php endif; ?>
<?php if (!$isCoach && $training['status'] === 'sent'): ?><div class="alert alert-warning text-center py-3 mb-4"><i class="fas fa-lock me-2"></i><strong>Pro zápis váhy a opakování nejdříve zahajte online trénink.</strong><div class="small mt-1">Po zahájení se zpřístupní všechny série.</div></div><?php endif; ?>
<?php if (!($isCoach && $training['status'] === 'created') && (!empty($training['coach_note']) || ($isCoach && $training['status'] === 'completed'))): ?>
<div class="alert alert-info">
<strong>Instrukce trenéra:</strong>
<?php if (!empty($training['coach_note'])): ?><div class="mt-1" style="white-space:pre-wrap"><?= h((string)$training['coach_note']) ?></div>
<?php elseif ($isCoach): ?><div class="mt-1 text-muted">K tomuto tréninku nebyly uloženy žádné původní instrukce.</div><?php endif; ?>
</div>
<?php endif; ?>
<?php if (!$isCoach && $training['status'] === 'completed' && !empty($training['athlete_note'])): ?><div class="alert alert-secondary"><strong>Poznámka sportovce:</strong><div style="white-space:pre-wrap"><?= h((string)$training['athlete_note']) ?></div></div><?php endif; ?>
<?php if ($isCoach && !empty($training['athlete_note'])): ?><div class="alert alert-secondary"><strong>Poznámka sportovce:</strong><div style="white-space:pre-wrap"><?= h((string)$training['athlete_note']) ?></div></div><?php endif; ?>
<?php if (!$isCoach && !empty($training['coach_reaction'])): ?><div class="alert alert-success"><strong>Reakce trenéra:</strong><div style="white-space:pre-wrap"><?= h((string)$training['coach_reaction']) ?></div></div><?php endif; ?>
<?php if ($isCoach && $training['status'] === 'completed' && !empty($training['coach_reaction'])): ?><div class="alert alert-success"><strong>Vaše reakce na dokončený trénink:</strong><div style="white-space:pre-wrap"><?= h((string)$training['coach_reaction']) ?></div></div><?php endif; ?>
<?php if ($isCoach && $training['status'] === 'created'): ?>
<form method="post" class="card shadow-sm mb-4 online-draft-step">
<div class="card-body"><?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="note">
<h5><span class="online-draft-step__number">1</span>Instrukce pro sportovce</h5>
<p class="text-muted">Napište sportovci, na co se má při samostatném tréninku zaměřit.</p>
<textarea id="online-send-note" name="coach_note" class="form-control mb-3" rows="4" placeholder="Například tempo, technika nebo doporučená intenzita..."><?= h((string)($training['coach_note'] ?? '')) ?></textarea>
<button class="btn btn-outline-dark"><i class="fas fa-save me-1"></i>Uložit instrukce</button>
</div>
</form>
<?php endif; ?>
<?php if ($isCoach && $training['status'] === 'created'): ?>
<section class="card border-warning shadow-sm mb-4 online-draft-step">
<div class="card-body">
<h5><span class="online-draft-step__number">2</span>Sestavte trénink</h5>
<p class="text-muted">Cviky ze zvolené sady už jsou v tréninku. Přidejte další cviky a případně upravte jejich pořadí.</p>
<div class="online-builder-grid">
<?php if ($availableExercises): ?>
<form method="post" class="online-builder-panel">
<?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="add_exercise">
<div class="online-builder-panel__title">Přidat cvik</div>
<label class="form-label small" for="online-add-exercise">Vyberte cvik ze seznamu</label>
<select id="online-add-exercise" name="exercise_id" class="form-select mb-3" required><option value="">Vyberte cvik</option><?php foreach ($availableExercises as $availableExercise): ?><option value="<?= (int)$availableExercise['id'] ?>"><?= h($availableExercise['name']) ?></option><?php endforeach; ?></select>
<button class="btn btn-outline-warning"><i class="fas fa-plus me-1"></i>Přidat do tréninku</button>
</form>
<?php else: ?>
<div class="online-builder-panel"><div class="online-builder-panel__title">Přidat cvik</div><p class="text-muted mb-0">Všechny dostupné cviky už jsou v tomto tréninku.</p></div>
<?php endif; ?>
<form method="post" class="online-builder-panel">
<?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="reorder_exercises">
<div class="online-builder-panel__title">Pořadí cviků</div>
<?php if ($exercises): ?>
<?php foreach ($exercises as $exercise): ?><label class="visually-hidden" for="exercise-order-<?= (int)$exercise['id'] ?>"><?= h($exercise['exercise_name']) ?> – pořadí</label><div class="online-builder-order"><div class="form-control bg-light text-truncate" title="<?= h($exercise['exercise_name']) ?>"><?= (int)$exercise['exercise_order'] ?>. <?= h($exercise['exercise_name']) ?></div><input id="exercise-order-<?= (int)$exercise['id'] ?>" name="exercise_order[<?= (int)$exercise['id'] ?>]" type="number" min="1" max="<?= count($exercises) ?>" class="form-control" value="<?= (int)$exercise['exercise_order'] ?>" aria-label="Pořadí cviku"></div><?php endforeach; ?>
<button class="btn btn-outline-dark btn-sm mt-2"><i class="fas fa-sort me-1"></i>Uložit pořadí</button>
<?php else: ?><p class="text-muted mb-0">Nejprve přidejte do tréninku alespoň jeden cvik.</p><?php endif; ?>
</form>
</div>
</div>
</section>
<?php endif; ?>
<div class="card shadow-sm mb-4">
<div class="card-header bg-dark text-white"><?= $isCoach && $training['status'] === 'created' ? '<span class="online-draft-step__number">3</span>Předepsané cviky, série a přílohy' : ($isCoach ? 'Předepsané a skutečně provedené série' : 'Cviky a série') ?></div>
<?php if ($isCoach && $training['status'] !== 'created'): ?>
<div class="table-responsive">
<table class="table table-sm align-middle mb-0 online-results-table online-coach-results-table">
<thead>
<tr><th colspan="2"></th><th colspan="2" class="online-results-table__coach-group">Zadání trenéra</th><th colspan="3" class="online-results-table__athlete-group online-results-table__athlete-start">Výsledek sportovce</th></tr>
<tr><th>Cvik</th><th>Série</th><th class="online-results-table__prescribed">Předepsaná váha</th><th class="online-results-table__prescribed">Předepsaná opakování</th><th class="online-results-table__actual online-results-table__athlete-start">Skutečná váha</th><th class="online-results-table__actual">Skutečná opakování</th><th class="online-results-table__actual">Poznámka série</th></tr>
</thead>
<tbody>
<?php foreach ($exercises as $exerciseIndex => $exercise): ?>
<?php $exerciseRowClass = $exerciseIndex % 2 === 0 ? 'online-exercise-row--shaded' : 'online-exercise-row--plain'; ?>
<?php if (!$exercise['series']): ?><tr class="<?= $exerciseRowClass ?>"><td><?= (int)$exercise['exercise_order'] ?>. <?= h($exercise['exercise_name']) ?></td><td colspan="6" class="text-muted">Bez sérií</td></tr><?php endif; ?>
<?php foreach ($exercise['series'] as $series): ?>
<?php
$weightDiffers = $series['prescribed_weight'] !== null && $series['actual_weight'] !== null
    && abs((float)$series['prescribed_weight'] - (float)$series['actual_weight']) > 0.0001;
$repsDiffer = $series['prescribed_reps'] !== null && $series['actual_reps'] !== null
    && (int)$series['prescribed_reps'] !== (int)$series['actual_reps'];
?>
<tr class="<?= $exerciseRowClass ?>">
<td><?= (int)$exercise['exercise_order'] ?>. <?= h($exercise['exercise_name']) ?></td>
<td><?= (int)$series['series_order'] ?></td>
<td class="online-results-table__prescribed"><?= $series['prescribed_weight'] !== null ? h((string)$series['prescribed_weight']) . ' kg' : '–' ?></td>
<td class="online-results-table__prescribed"><?= $series['prescribed_reps'] !== null ? (int)$series['prescribed_reps'] : '–' ?></td>
<td class="online-results-table__actual online-results-table__athlete-start"><?= $series['actual_weight'] !== null ? ($weightDiffers ? '<span class="online-result-difference">' . h((string)$series['actual_weight']) . ' kg</span>' : '<strong>' . h((string)$series['actual_weight']) . ' kg</strong>') : '–' ?></td>
<td class="online-results-table__actual"><?= $series['actual_reps'] !== null ? ($repsDiffer ? '<span class="online-result-difference">' . (int)$series['actual_reps'] . '</span>' : '<strong>' . (int)$series['actual_reps'] . '</strong>') : '–' ?></td>
<td class="online-results-table__actual fw-bold"><?= !empty($series['result_note']) ? h((string)$series['result_note']) : '–' ?></td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php foreach ($exercises as $exercise): ?>
<?php
$exerciseAttachments = array_values(array_filter(
    $attachments,
    static fn(array $attachment): bool => (int)($attachment['online_training_exercise_id'] ?? 0) === (int)$exercise['id']
));
?>
<section class="online-exercise-attachments mx-3 mb-3 rounded" id="online-exercise-attachments-<?= (int)$exercise['id'] ?>">
<h6 class="fw-bold mb-3"><i class="fas fa-paperclip me-2 text-warning"></i><?= (int)$exercise['exercise_order'] ?>. <?= h($exercise['exercise_name']) ?> — přílohy k cviku</h6>
<?php if ($exerciseAttachments): ?><div class="d-flex flex-wrap gap-2 mb-3"><?php $renderAttachmentCards($exerciseAttachments); ?></div><?php else: ?><p class="text-muted small">Zatím žádné přílohy k tomuto cviku.</p><?php endif; ?>
<?php if ($isCoach && $canManageAttachments): ?>
<details class="online-exercise-attachments__disclosure">
<summary><i class="fas fa-plus me-1"></i>Přidat přílohu</summary>
<form data-online-attachment-form data-exercise-name="<?= h($exercise['exercise_name']) ?>" method="post" enctype="multipart/form-data" class="border rounded bg-white p-3">
<?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="attachment"><input type="hidden" name="online_training_exercise_id" value="<?= (int)$exercise['id'] ?>">
<div class="row g-2 align-items-end">
<?php if ($isCoach): ?><div class="col-12"><label class="form-label small" for="exercise-video-library-<?= (int)$exercise['id'] ?>">Vybrat z Moje videa (volitelné)</label><select id="exercise-video-library-<?= (int)$exercise['id'] ?>" name="video_file_id" class="form-select"><option value="0">Nevybírat video z knihovny</option><?php foreach ($libraryVideos as $libraryVideo): ?><option value="<?= (int)$libraryVideo['id'] ?>"><?= h($libraryVideo['original_name']) ?></option><?php endforeach; ?></select><?php if (!$libraryVideos): ?><div class="form-text">V knihovně zatím nemáte žádná videa. <a href="<?= BASE_URL ?>/video_upload.php" target="_blank" rel="noopener">Nahrát video</a></div><?php else: ?><div class="form-text">Při výběru z knihovny nepřidávejte současně soubor ani odkaz.</div><?php endif; ?></div><?php endif; ?>
<div class="col-md-5"><label class="form-label small" for="exercise-file-<?= (int)$exercise['id'] ?>">Fotografie nebo video</label><input id="exercise-file-<?= (int)$exercise['id'] ?>" type="file" name="attachment" class="form-control" accept="image/*,video/*"></div>
<div class="col-md-5"><label class="form-label small" for="exercise-url-<?= (int)$exercise['id'] ?>">Odkaz (volitelně)</label><input id="exercise-url-<?= (int)$exercise['id'] ?>" name="external_url" type="url" maxlength="1000" class="form-control" placeholder="https://..."></div>
<div class="col-md-2"><button type="submit" class="btn btn-outline-primary w-100 online-attachment-submit"><i class="fas fa-paperclip me-1"></i>Přidat</button></div>
<?php if ($isCoach): ?><div class="col-12"><label class="form-label small" for="exercise-link-description-<?= (int)$exercise['id'] ?>" data-attachment-description-label>Vysvětlení odkazu (volitelné)</label><textarea id="exercise-link-description-<?= (int)$exercise['id'] ?>" name="link_description" class="form-control" rows="2"></textarea></div><?php endif; ?>
</div>
<div class="form-text">Příloha se zobrazí sportovci přímo u cviku.</div>
</form>
</details>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php elseif (!$isCoach): ?>
<div class="table-responsive">
<table class="table table-sm align-middle mb-0 online-results-table online-athlete-results-table">
<thead>
<tr><th colspan="2"></th><th colspan="2" class="online-results-table__coach-group">Zadání trenéra</th><th colspan="3" class="online-results-table__athlete-group online-results-table__athlete-start">Výsledek sportovce</th></tr>
<tr><th>Cvik</th><th>Série</th><th class="online-results-table__prescribed">Předepsaná váha</th><th class="online-results-table__prescribed">Předepsaná opakování</th><th class="online-results-table__actual online-results-table__athlete-start">Moje váha</th><th class="online-results-table__actual">Moje opakování</th><th class="online-results-table__actual">Moje poznámka k sérii</th></tr>
</thead>
<tbody>
<?php foreach ($exercises as $exerciseIndex => $exercise): ?>
<?php $exerciseRowClass = $exerciseIndex % 2 === 0 ? 'online-exercise-row--shaded' : 'online-exercise-row--plain'; ?>
<?php if (!$exercise['series']): ?><tr class="<?= $exerciseRowClass ?>"><td><?= (int)$exercise['exercise_order'] ?>. <?= h($exercise['exercise_name']) ?></td><td colspan="5" class="text-muted">Bez sérií</td></tr><?php endif; ?>
<?php foreach ($exercise['series'] as $series): ?>
<tr class="<?= $exerciseRowClass ?>">
<td><?= (int)$exercise['exercise_order'] ?>. <?= h($exercise['exercise_name']) ?></td>
<td><?= (int)$series['series_order'] ?></td>
<td class="online-results-table__prescribed"><?= $series['prescribed_weight'] !== null ? h((string)$series['prescribed_weight']) . ' kg' : '–' ?></td>
<td class="online-results-table__prescribed"><?= $series['prescribed_reps'] !== null ? (int)$series['prescribed_reps'] : '–' ?></td>
<?php if ($training['status'] === 'completed'): ?>
<td class="online-results-table__actual online-results-table__athlete-start fw-bold"><?= $series['actual_weight'] !== null ? h((string)$series['actual_weight']) . ' kg' : '–' ?></td>
<td class="online-results-table__actual fw-bold"><?= $series['actual_reps'] !== null ? (int)$series['actual_reps'] : '–' ?></td>
<td class="online-results-table__actual"><?= !empty($series['result_note']) ? h((string)$series['result_note']) : '–' ?></td>
<?php else: ?>
<td class="online-results-table__actual online-results-table__athlete-start"><input form="result-form" name="series[<?= (int)$series['id'] ?>][weight]" class="form-control form-control-sm" type="number" step="0.01" value="<?= h((string)($series['actual_weight'] ?? '')) ?>" aria-label="Moje váha, série <?= (int)$series['series_order'] ?>"></td>
<td class="online-results-table__actual"><input form="result-form" name="series[<?= (int)$series['id'] ?>][reps]" class="form-control form-control-sm" type="number" value="<?= h((string)($series['actual_reps'] ?? '')) ?>" aria-label="Moje opakování, série <?= (int)$series['series_order'] ?>"></td>
<td class="online-results-table__actual"><input form="result-form" name="series[<?= (int)$series['id'] ?>][note]" class="form-control form-control-sm" type="text" maxlength="10000" value="<?= h((string)($series['result_note'] ?? '')) ?>" aria-label="Moje poznámka, série <?= (int)$series['series_order'] ?>"></td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php foreach ($exercises as $exercise): ?>
<?php
$exerciseAttachments = array_values(array_filter(
    $attachments,
    static fn(array $attachment): bool => (int)($attachment['online_training_exercise_id'] ?? 0) === (int)$exercise['id']
));
$trainerExerciseAttachments = array_values(array_filter($exerciseAttachments, static fn(array $attachment): bool => $attachment['uploaded_by'] === 'trainer'));
$athleteExerciseAttachments = array_values(array_filter($exerciseAttachments, static fn(array $attachment): bool => $attachment['uploaded_by'] === 'athlete'));
?>
<section class="online-exercise-attachments mx-3 mb-3 rounded" id="online-exercise-attachments-<?= (int)$exercise['id'] ?>">
<h6 class="fw-bold mb-3"><i class="fas fa-paperclip me-2 text-warning"></i><?= (int)$exercise['exercise_order'] ?>. <?= h($exercise['exercise_name']) ?> — přílohy k cviku</h6>
<?php foreach ([['items' => $trainerExerciseAttachments, 'label' => 'Přílohy od trenéra'], ['items' => $athleteExerciseAttachments, 'label' => 'Moje přílohy']] as $attachmentGroup): ?>
<?php if ($attachmentGroup['items']): ?><div class="mb-3"><div class="small fw-semibold mb-2"><?= h($attachmentGroup['label']) ?></div><div class="d-flex flex-wrap gap-2"><?php $renderAttachmentCards($attachmentGroup['items']); ?></div></div><?php endif; ?>
<?php endforeach; ?>
<?php if ($isCoach && $canManageAttachments): ?>
<details class="online-exercise-attachments__disclosure">
<summary><i class="fas fa-plus me-1"></i>Přidat přílohu</summary>
<form data-online-attachment-form data-exercise-name="<?= h($exercise['exercise_name']) ?>" method="post" enctype="multipart/form-data" class="border rounded bg-white p-3">
<?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="attachment"><input type="hidden" name="online_training_exercise_id" value="<?= (int)$exercise['id'] ?>">
<div class="row g-2 align-items-end">
<?php if ($isCoach): ?><div class="col-12"><label class="form-label small" for="exercise-video-library-<?= (int)$exercise['id'] ?>">Vybrat z Moje videa (volitelné)</label><select id="exercise-video-library-<?= (int)$exercise['id'] ?>" name="video_file_id" class="form-select"><option value="0">Nevybírat video z knihovny</option><?php foreach ($libraryVideos as $libraryVideo): ?><option value="<?= (int)$libraryVideo['id'] ?>"><?= h($libraryVideo['original_name']) ?></option><?php endforeach; ?></select><?php if (!$libraryVideos): ?><div class="form-text">V knihovně zatím nemáte žádná videa. <a href="<?= BASE_URL ?>/video_upload.php" target="_blank" rel="noopener">Nahrát video</a></div><?php else: ?><div class="form-text">Při výběru z knihovny nepřidávejte současně soubor ani odkaz.</div><?php endif; ?></div><?php endif; ?>
<div class="col-md-5"><label class="form-label small" for="exercise-file-<?= (int)$exercise['id'] ?>">Fotografie nebo video</label><input id="exercise-file-<?= (int)$exercise['id'] ?>" type="file" name="attachment" class="form-control" accept="image/*,video/*"></div>
<div class="col-md-5"><label class="form-label small" for="exercise-url-<?= (int)$exercise['id'] ?>">Odkaz (volitelně)</label><input id="exercise-url-<?= (int)$exercise['id'] ?>" name="external_url" type="url" maxlength="1000" class="form-control" placeholder="https://..."></div>
<div class="col-md-2"><button type="submit" class="btn btn-outline-primary w-100 online-attachment-submit"><i class="fas fa-paperclip me-1"></i>Přidat</button></div>
</div>
</form>
</details>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php else: ?>
<?php foreach ($exercises as $exercise): ?>
<div class="online-exercise-card m-3" id="online-exercise-<?= (int)$exercise['id'] ?>">
<div class="p-3">
<div class="d-flex justify-content-between align-items-center">
<h5 class="mb-2">
<?= (int)$exercise['exercise_order'] ?>. <?= h($exercise['exercise_name']) ?>
<?php $lastCompleted = $lastCompletedByExercise[(int)$exercise['id']] ?? null; ?>
<?php if ($isCoach && $training['status'] === 'created' && $lastCompleted): ?>
<button type="button" class="btn btn-sm btn-outline-secondary ms-2" data-bs-toggle="modal" data-bs-target="#onlinePreviousExercise<?= (int)$exercise['id'] ?>" title="Zobrazit poslední dokončený trénink tohoto cviku" aria-label="Zobrazit poslední dokončený trénink cviku <?= h($exercise['exercise_name']) ?>"><i class="fas fa-history"></i></button>
<?php endif; ?>
</h5>
<?php if ($isCoach && $training['status'] === 'created'): ?><div class="d-flex gap-2">
<form method="post" class="d-inline">
<input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="training_id" value="<?= $id ?>">
<input type="hidden" name="action" value="add_series">
<input type="hidden" name="online_exercise_id" value="<?= (int)$exercise['id'] ?>">
<button class="btn btn-sm btn-outline-warning"><i class="fas fa-plus me-1"></i>Přidat sérii</button>
</form>
<form method="post" class="d-inline" onsubmit="return confirm('Odebrat tento cvik včetně předepsaných sérií?');">
<?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="remove_exercise"><input type="hidden" name="online_exercise_id" value="<?= (int)$exercise['id'] ?>">
<button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Odebrat cvik</button>
</form></div><?php endif; ?>
</div>
<div class="table-responsive">
<table class="table table-sm align-middle">
<thead>
<tr>
<th>Série</th>
<th>Předepsaná váha</th>
<th>Předepsaná opakování</th>
<?php if (!$isCoach): ?>
<th>Moje váha</th>
<th>Moje opakování</th>
<th>Moje poznámka k sérii</th>
<?php elseif ($training['status'] === 'created'): ?>
<th>
</th>
<?php endif; ?>
</tr>
</thead>
<tbody>
<?php foreach ($exercise['series'] as $series): ?>
<tr>
<td>
<?= (int)$series['series_order'] ?>
</td>
<td>
<?php if ($isCoach && $training['status'] === 'created'): ?>
<input form="prescription-form" name="prescribed[<?= (int)$series['id'] ?>][weight]" class="form-control form-control-sm" type="number" step="0.01" value="<?= h((string)($series['prescribed_weight'] ?? '')) ?>">
<?php else: ?>
<?= $series['prescribed_weight'] !== null ? h((string)$series['prescribed_weight']) . ' kg' : '-' ?>
<?php endif; ?>
</td>
<td>
<?php if ($isCoach && $training['status'] === 'created'): ?>
<input form="prescription-form" name="prescribed[<?= (int)$series['id'] ?>][reps]" class="form-control form-control-sm" type="number" value="<?= h((string)($series['prescribed_reps'] ?? '')) ?>">
<?php else: ?>
<?= $series['prescribed_reps'] !== null ? (int)$series['prescribed_reps'] : '-' ?>
<?php endif; ?>
</td>
<?php if (!$isCoach): ?>
<td>
<input form="result-form" name="series[<?= (int)$series['id'] ?>][weight]" class="form-control form-control-sm" type="number" step="0.01" value="<?= h((string)($series['actual_weight'] ?? '')) ?>">
</td>
<td>
<input form="result-form" name="series[<?= (int)$series['id'] ?>][reps]" class="form-control form-control-sm" type="number" value="<?= h((string)($series['actual_reps'] ?? '')) ?>">
</td>
<?php elseif ($training['status'] === 'created'): ?>
<td>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="training_id" value="<?= $id ?>">
<input type="hidden" name="action" value="delete_series">
<input type="hidden" name="online_series_id" value="<?= (int)$series['id'] ?>">
<button class="btn btn-sm btn-outline-danger" title="Smazat sérii">
<i class="fas fa-times">
</i>
</button>
</form>
</td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php
$exerciseAttachments = array_values(array_filter(
    $attachments,
    static fn(array $attachment): bool => (int)($attachment['online_training_exercise_id'] ?? 0) === (int)$exercise['id']
));
$trainerExerciseAttachments = array_values(array_filter($exerciseAttachments, static fn(array $attachment): bool => $attachment['uploaded_by'] === 'trainer'));
$athleteExerciseAttachments = array_values(array_filter($exerciseAttachments, static fn(array $attachment): bool => $attachment['uploaded_by'] === 'athlete'));
?>
<div class="online-exercise-attachments" id="online-exercise-attachments-<?= (int)$exercise['id'] ?>">
<h6 class="fw-bold mb-3"><i class="fas fa-paperclip me-2 text-warning"></i>Přílohy k tomuto cviku</h6>
<?php foreach ([['items' => $trainerExerciseAttachments, 'label' => $isCoach ? 'Vaše přílohy' : 'Přílohy od trenéra'], ['items' => $athleteExerciseAttachments, 'label' => $isCoach ? 'Přílohy sportovce' : 'Vaše přílohy']] as $attachmentGroup): ?>
<?php if ($attachmentGroup['items']): ?><div class="mb-3"><div class="small fw-semibold mb-2"><?= h($attachmentGroup['label']) ?></div><div class="d-flex flex-wrap gap-2"><?php $renderAttachmentCards($attachmentGroup['items']); ?></div></div><?php endif; ?>
<?php endforeach; ?>
<?php if ($isCoach && $canManageAttachments): ?>
<details class="online-exercise-attachments__disclosure">
<summary><i class="fas fa-plus me-1"></i>Přidat přílohu</summary>
<form data-online-attachment-form data-exercise-name="<?= h($exercise['exercise_name']) ?>" method="post" enctype="multipart/form-data" class="border rounded bg-white p-3">
<?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="attachment"><input type="hidden" name="online_training_exercise_id" value="<?= (int)$exercise['id'] ?>">
<div class="row g-2 align-items-end">
<?php if ($isCoach): ?><div class="col-12"><label class="form-label small" for="exercise-video-library-<?= (int)$exercise['id'] ?>">Vybrat z Moje videa (volitelné)</label><select id="exercise-video-library-<?= (int)$exercise['id'] ?>" name="video_file_id" class="form-select"><option value="0">Nevybírat video z knihovny</option><?php foreach ($libraryVideos as $libraryVideo): ?><option value="<?= (int)$libraryVideo['id'] ?>"><?= h($libraryVideo['original_name']) ?></option><?php endforeach; ?></select><?php if (!$libraryVideos): ?><div class="form-text">V knihovně zatím nemáte žádná videa. <a href="<?= BASE_URL ?>/video_upload.php" target="_blank" rel="noopener">Nahrát video</a></div><?php else: ?><div class="form-text">Při výběru z knihovny nepřidávejte současně soubor ani odkaz.</div><?php endif; ?></div><?php endif; ?>
<div class="col-md-5"><label class="form-label small" for="exercise-file-<?= (int)$exercise['id'] ?>">Fotografie nebo video</label><input id="exercise-file-<?= (int)$exercise['id'] ?>" type="file" name="attachment" class="form-control" accept="image/*,video/*"></div>
<div class="col-md-5"><label class="form-label small" for="exercise-url-<?= (int)$exercise['id'] ?>">Odkaz (volitelně)</label><input id="exercise-url-<?= (int)$exercise['id'] ?>" name="external_url" type="url" maxlength="1000" class="form-control" placeholder="https://..."></div>
<div class="col-md-2"><button type="submit" class="btn btn-outline-primary w-100 online-attachment-submit"><i class="fas fa-paperclip me-1"></i>Přidat</button></div>
<?php if ($isCoach): ?><div class="col-12"><label class="form-label small" for="exercise-link-description-<?= (int)$exercise['id'] ?>" data-attachment-description-label>Vysvětlení odkazu (volitelné)</label><textarea id="exercise-link-description-<?= (int)$exercise['id'] ?>" name="link_description" class="form-control" rows="2"></textarea></div><?php endif; ?>
</div>
<div class="form-text">Příloha se zobrazí sportovci přímo u cviku.</div>
</form>
</details>
<?php endif; ?>
</div>
</div>
</div>
<?php if ($isCoach && $training['status'] === 'created' && $lastCompleted): ?>
<?php $exerciseMode = (int)($exercise['is_timed'] ?? 0); ?>
<div class="modal fade" id="onlinePreviousExercise<?= (int)$exercise['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <div>
                    <h5 class="modal-title"><i class="fas fa-history me-2"></i>Poslední trénink cviku: <?= h($exercise['exercise_name']) ?></h5>
                    <div class="small text-white-50"><?= h(formatDateTime($lastCompleted['completed_at'])) ?> · <?= h($lastCompleted['title']) ?></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle text-center mb-0">
                        <thead><tr>
                            <th>Série</th>
                            <?php if ($exerciseMode === 1): ?><th>Čas</th><th>Váha (kg)</th>
                            <?php elseif ($exerciseMode === 2): ?><th>Vzdálenost (m)</th><th>Váha (kg)</th>
                            <?php elseif ($exerciseMode === 3): ?><th>Vzdálenost (m)</th><th>Čas</th><th>Váha (kg)</th>
                            <?php else: ?><th>Váha (kg)</th><th>Opakování</th><?php endif; ?>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($lastCompleted['series'] as $previousSeries): ?>
                            <tr>
                                <td class="fw-bold text-muted"><?= (int)$previousSeries['series_order'] ?></td>
                                <?php if ($exerciseMode === 1): ?>
                                <td><?= !empty($previousSeries['actual_duration_seconds']) ? h(formatSeriesDuration((int)$previousSeries['actual_duration_seconds'])) : '–' ?></td>
                                <td><?= number_format((float)($previousSeries['actual_weight'] ?? 0) + (float)($previousSeries['actual_equipment_weight'] ?? 0), 1, ',', '') ?></td>
                                <?php elseif ($exerciseMode === 2): ?>
                                <td><?= (int)($previousSeries['actual_reps'] ?? 0) > 0 ? number_format((int)$previousSeries['actual_reps'], 0, ',', ' ') : '–' ?></td>
                                <td><?= number_format((float)($previousSeries['actual_weight'] ?? 0) + (float)($previousSeries['actual_equipment_weight'] ?? 0), 1, ',', '') ?></td>
                                <?php elseif ($exerciseMode === 3): ?>
                                <td><?= (int)($previousSeries['actual_reps'] ?? 0) > 0 ? number_format((int)$previousSeries['actual_reps'], 0, ',', ' ') : '–' ?></td>
                                <td><?= !empty($previousSeries['actual_duration_seconds']) ? h(formatSeriesDuration((int)$previousSeries['actual_duration_seconds'])) : '–' ?></td>
                                <td><?= number_format((float)($previousSeries['actual_weight'] ?? 0) + (float)($previousSeries['actual_equipment_weight'] ?? 0), 1, ',', '') ?></td>
                                <?php else: ?>
                                <td><?= number_format((float)($previousSeries['actual_weight'] ?? 0) + (float)($previousSeries['actual_equipment_weight'] ?? 0), 1, ',', '') ?></td>
                                <td><?= $previousSeries['actual_reps'] !== null ? (int)$previousSeries['actual_reps'] : '–' ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php if ($isCoach && $training['status'] === 'created'): ?><form id="prescription-form" method="post" class="mb-4"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="prescription"><button class="btn btn-outline-dark">Uložit předepsané hodnoty</button></form><?php endif; ?>
<div id="online-attachments" class="card shadow-sm mb-4">
<div class="card-header bg-dark text-white">Přílohy k celému tréninku</div>
<div class="card-body">
<?php
$groups = $isCoach ? ['athlete' => 'Přílohy sportovce', 'trainer' => 'Vaše přílohy pro sportovce'] : ['trainer' => 'Přílohy od trenéra', 'athlete' => 'Moje přílohy pro trenéra'];
foreach ($groups as $role => $heading):
    if ($role === 'athlete' && !$canViewAthleteAttachments) continue;
    $groupAttachments = array_values(array_filter($attachments, static fn(array $attachment): bool => $attachment['uploaded_by'] === $role && empty($attachment['online_training_exercise_id'])));
?>
<section class="mb-4" aria-label="<?= h($heading) ?>">
<h5 class="mb-2"><?= h($heading) ?> <span class="badge bg-secondary"><?= count($groupAttachments) ?></span></h5>
<?php if (!$groupAttachments): ?>
<p class="text-muted small">Zatím žádné přílohy.</p>
<?php else: ?>
<div class="d-flex flex-wrap gap-3 mb-3">
<?php $renderAttachmentCards($groupAttachments); ?>
</div>
<?php endif; ?>
<?php if ($role === $ownRole && $canManageAttachments): ?>
<form data-online-attachment-form data-exercise-name="celému tréninku" method="post" enctype="multipart/form-data" class="border rounded bg-light p-3">
<input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
<input type="hidden" name="training_id" value="<?= $id ?>">
<input type="hidden" name="action" value="attachment">
<strong class="d-block mb-2"><?= $isCoach ? 'Přidat přílohu pro sportovce' : 'Přidat fotografii nebo video pro trenéra' ?></strong>
<input type="hidden" name="online_training_exercise_id" value="0">
<div class="row g-3">
<div class="col-md-4">
<label for="online-training-attachment-file" class="form-label">Fotografie nebo video</label>
<input id="online-training-attachment-file" type="file" name="attachment" class="form-control" accept="image/*,video/*">
</div>
<div class="col-md-4">
<label for="online-training-attachment-url" class="form-label">Internetový odkaz (volitelné)</label>
<input id="online-training-attachment-url" name="external_url" type="url" maxlength="1000" class="form-control" placeholder="https://...">
</div>
<?php if ($isCoach): ?><div class="col-12"><label for="online-training-link-description" class="form-label">Popis a vysvětlení odkazu (volitelné)</label><textarea id="online-training-link-description" name="link_description" class="form-control" rows="2"></textarea></div><?php endif; ?>
</div>
<div class="form-text">Tyto přílohy budou viditelné u celého tréninku. Přílohy ke konkrétním cvikům přidejte přímo v jeho kartě.</div>
<button type="submit" class="btn btn-primary mt-3 online-attachment-submit"><i class="fas fa-upload me-2"></i>Nahrát přílohu</button>
</form>
<?php endif; ?>
</section>
<?php endforeach; ?>
</div>
</div>
<?php if ($isCoach && $training['status'] === 'created'): ?>
<div class="card shadow-sm mb-4 online-send-step">
<div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
<div>
<h5 class="mb-1"><span class="online-draft-step__number">4</span>Zkontrolujte a odešlete</h5>
<div class="text-muted"><?= count($exercises) ?> <?= count($exercises) === 1 ? 'cvik' : (count($exercises) < 5 ? 'cviky' : 'cviků') ?> · <?= count($attachments) ?> <?= count($attachments) === 1 ? 'příloha' : (count($attachments) < 5 ? 'přílohy' : 'příloh') ?></div>
<?php if ($activeSubscription): ?><div class="small mt-1">Předplatné platí do <?= h(formatDate($activeSubscription['ends_on'])) ?>. Počet tréninků není omezen.</div><?php else: ?><div class="text-danger small mt-1">Sportovec nemá platné a uhrazené předplatné. <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$training['athlete_id'] ?>">Spravovat předplatné</a> nebo <a href="<?= BASE_URL ?>/payments.php">zkontrolovat platby</a>.</div><?php endif; ?>
</div>
<form method="post" class="flex-shrink-0">
<?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="coach_note" value="<?= h((string)($training['coach_note'] ?? '')) ?>">
<button name="action" value="send" class="btn btn-warning btn-lg fw-bold" <?= !$activeSubscription ? 'disabled' : '' ?>><i class="fas fa-paper-plane me-2"></i>Odeslat sportovci</button>
</form>
</div>
</div>
<?php endif; ?>
<?php if (!$isCoach && $training['status'] === 'in_progress'): ?><form id="result-form" method="post" action="<?= BASE_URL ?>/api/online_training_complete.php" class="card shadow-sm mb-4"><div class="card-body"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="series_count" value="<?= array_sum(array_map(static fn(array $exercise): int => count($exercise['series']), $exercises)) ?>"><label class="form-label fw-bold">Moje poznámka</label><textarea name="athlete_note" class="form-control mb-3" rows="3"><?= h((string)($training['athlete_note'] ?? '')) ?></textarea><span id="online-save-state" class="small text-muted me-2" aria-live="polite">Výsledky a poznámka se průběžně ukládají.</span><div class="mt-3"><button type="submit" class="btn btn-success">Dokončit online trénink</button></div></div></form><?php endif; ?>
<?php if ($isCoach && $training['status'] === 'completed' && empty($training['coach_reaction'])): ?>
<form method="post" class="card border-primary shadow-sm mb-4">
<div class="card-body">
<?= csrfField() ?><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="coach_reaction">
<label for="online-coach-reaction" class="form-label fw-bold">Reakce na dokončený trénink</label>
<textarea id="online-coach-reaction" name="coach_reaction" class="form-control" rows="4" required placeholder="Napište sportovci zpětnou vazbu k odcvičenému tréninku..."><?= h((string)($training['coach_reaction'] ?? '')) ?></textarea>
<div class="form-text">Po odeslání dostane sportovec upozornění.</div>
<button class="btn btn-primary mt-2"><i class="fas fa-paper-plane me-1"></i>Odeslat reakci sportovci</button>
</div>
</form>
<?php elseif ($isCoach && $training['status'] === 'completed'): ?>
<div class="d-flex justify-content-end mb-4">
<a href="<?= BASE_URL ?>/online_training.php?athlete_id=<?= (int)$training['athlete_id'] ?>" class="btn btn-outline-secondary"><i class="fas fa-times me-2"></i>Zavřít</a>
</div>
<?php endif; ?>
<?php if (!$isCoach && in_array($training['status'], ['in_progress', 'completed'], true)): ?><script>
(function () {
    const resultForm = document.getElementById('result-form');
    const saveState = document.getElementById('online-save-state');
    const completed = <?= $training['status'] === 'completed' ? 'true' : 'false' ?>;
    const fields = Array.from(document.querySelectorAll('input[form="result-form"][name^="series["]'));
    if (completed) {
        fields.forEach(function (field) { field.disabled = true; });
        return;
    }
    const note = resultForm.querySelector('[name="athlete_note"]');
    const attachmentForms = Array.from(document.querySelectorAll('[data-online-attachment-form]'));
    let timer;
    let pending = Promise.resolve();
    let busy = false;
    function setBusy(value) {
        busy = value;
        fields.forEach(function (field) { field.readOnly = value; });
        note.readOnly = value;
        resultForm.querySelector('button[type="submit"]').disabled = value;
    }
    async function send(url, payload) {
        const response = await fetch(url, { method: 'POST', body: payload, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
        let data;
        try { data = await response.json(); } catch (error) { throw new Error('Server nevrátil platnou odpověď.'); }
        if (!response.ok || !data.success) throw new Error(data.error || 'Uložení se nezdařilo.');
        return data;
    }
    function saveResults() {
        clearTimeout(timer);
        pending = pending.catch(function () {}).then(async function () {
            saveState.textContent = 'Ukládám výsledky a poznámku...';
            const payload = new FormData(resultForm);
            payload.set('action', 'save_all');
            await send(window.location.href, payload);
            saveState.textContent = 'Výsledky a poznámka jsou uložené.';
            saveState.className = 'small text-success me-2';
        }).catch(function (error) {
            saveState.textContent = 'Uložení se nepodařilo: ' + error.message;
            saveState.className = 'small text-danger me-2';
            throw error;
        });
        return pending;
    }
    fields.concat([note]).forEach(function (field) {
        field.addEventListener('input', function () {
            clearTimeout(timer);
            saveState.textContent = 'Neuložené změny...';
            saveState.className = 'small text-muted me-2';
            timer = setTimeout(function () { saveResults().catch(function () {}); }, 650);
        });
    });
    resultForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy) return;
        const hasUnsubmittedAttachment = attachmentForms.some(function (form) {
            const file = form.querySelector('[name="attachment"]');
            const url = form.querySelector('[name="external_url"]');
            const libraryVideo = form.querySelector('[name="video_file_id"]');
            return (file && file.files.length)
                || (url && url.value.trim())
                || (libraryVideo && libraryVideo.value !== '' && libraryVideo.value !== '0');
        });
        if (hasUnsubmittedAttachment) {
            alert('Vybrané video, přílohu nebo odkaz nejdříve přidejte tlačítkem Nahrát přílohu.');
            return;
        }
        if (!confirm('Opravdu dokončit online trénink? Po dokončení už nepůjdou výsledky upravovat.')) return;
        setBusy(true);
        try {
            await saveResults();
            const data = await send(resultForm.action, new FormData(resultForm));
            window.location.href = data.redirect;
        } catch (error) {
            saveState.textContent = 'Trénink se nepodařilo dokončit: ' + error.message;
            saveState.className = 'small text-danger me-2';
            setBusy(false);
        }
    });
    document.querySelectorAll('form').forEach(function (form) {
        const action = form.querySelector('input[name="action"]');
        if (!action || (action.value !== 'attachment' && action.value !== 'delete_attachment')) return;
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy) return;
            setBusy(true);
            try {
                await saveResults();
                HTMLFormElement.prototype.submit.call(form);
            } catch (error) {
                alert('Před změnou příloh se nepodařilo uložit výsledky: ' + error.message);
                setBusy(false);
            }
        });
    });
})();
</script><?php endif; ?>
<?php if ($isCoach && $canManageAttachments): ?>
<div id="onlineAttachmentWarning" class="online-attachment-warning" hidden aria-hidden="true">
    <section class="online-attachment-warning__dialog" role="dialog" aria-modal="true" aria-labelledby="onlineAttachmentWarningTitle" aria-describedby="onlineAttachmentWarningMessage">
        <div class="online-attachment-warning__icon"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></div>
        <h2 id="onlineAttachmentWarningTitle">Příloha ještě není nahraná</h2>
        <p id="onlineAttachmentWarningMessage">Před pokračováním nahrajte připravenou přílohu:</p>
        <ul id="onlineAttachmentWarningList"></ul>
        <div class="online-attachment-warning__actions">
            <button type="button" id="onlineAttachmentWarningGo" class="btn btn-primary"><i class="fas fa-upload me-1"></i>Přejít k příloze</button>
            <button type="button" id="onlineAttachmentWarningContinue" class="btn btn-outline-secondary">Pokračovat bez nahrání</button>
        </div>
    </section>
</div>
<style>
.online-attachment-submit.is-pending { animation: online-attachment-pulse .9s ease-in-out infinite; border-color: #b45309; background: #fbbf24; color: #1f2937; font-weight: 700; }
@keyframes online-attachment-pulse { 50% { transform: scale(1.05); box-shadow: 0 0 0 .45rem rgba(251, 191, 36, .3); } }
@media (prefers-reduced-motion: reduce) { .online-attachment-submit.is-pending { animation: none; outline: 3px solid rgba(251, 191, 36, .45); } }
.online-attachment-warning { position: fixed; inset: 0; z-index: 2100; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, .72); }
.online-attachment-warning[hidden] { display: none; }
.online-attachment-warning__dialog { width: min(100%, 34rem); padding: 2rem; border-radius: 1rem; background: #fff; box-shadow: 0 1.5rem 4rem rgba(0, 0, 0, .35); text-align: center; }
.online-attachment-warning__icon { margin-bottom: .75rem; color: #d97706; font-size: 2.5rem; }
.online-attachment-warning__dialog h2 { font-size: 1.4rem; }
.online-attachment-warning__dialog ul { margin: 1rem 0 1.5rem; padding-left: 1.25rem; text-align: left; }
.online-attachment-warning__actions { display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem; }
</style>
<script>
(function () {
    const restoreKey = 'online-training-scroll-<?= (int)$id ?>';
    const savedScroll = sessionStorage.getItem(restoreKey);
    if (savedScroll !== null) {
        sessionStorage.removeItem(restoreKey);
        window.requestAnimationFrame(function () { window.scrollTo(0, parseInt(savedScroll, 10) || 0); });
    }
    document.querySelectorAll('form').forEach(function (form) {
        const action = form.querySelector('input[name="action"][value="add_series"]');
        const exercise = form.querySelector('input[name="online_exercise_id"]');
        if (!action || !exercise) return;
        action.value = 'set_series_count';
        const button = form.querySelector('button');
        if (button) button.innerHTML = '<i class="fas fa-check me-1"></i>Nastavit';
        const select = document.createElement('select');
        select.name = 'series_count';
        select.className = 'form-select form-select-sm d-inline-block ms-1';
        select.style.width = 'auto';
        for (let count = 1; count <= 20; count++) {
            const option = document.createElement('option');
            option.value = String(count);
            option.textContent = count + ' sérií';
            select.appendChild(option);
        }
        const currentSeries = form.closest('.p-3')?.querySelectorAll('tbody tr').length || 1;
        select.value = String(Math.min(20, Math.max(1, currentSeries)));
        form.insertBefore(select, button);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            sessionStorage.setItem(restoreKey, String(window.scrollY));
            fetch(window.location.href, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { if (!response.ok) throw new Error('save failed'); return response.json(); })
                .then(function (data) { if (!data.success) throw new Error(data.error || 'save failed'); window.location.reload(); })
                .catch(function () { sessionStorage.removeItem(restoreKey); button.textContent = 'Zkusit znovu'; });
        });
    });
})();
</script><?php endif; ?>
<?php if ($isCoach && $training['status'] === 'created'): ?><script>
(function () {
    const csrf = <?= json_encode(csrfToken()) ?>;
    const trainingId = <?= (int)$id ?>;
    const timers = new Map();
    document.querySelectorAll('input[form="prescription-form"][name^="prescribed["]').forEach(function (input) {
        const match = input.name.match(/^prescribed\[(\d+)\]\[(weight|reps|duration)\]$/);
        if (!match) return;
        const seriesId = match[1];
        const row = input.closest('tr');
        if (!row || row.dataset.autosaveReady === '1') return;
        row.dataset.autosaveReady = '1';
        const status = document.createElement('span');
        status.className = 'small text-muted ms-2';
        status.textContent = 'Průběžné ukládání';
        const cell = row.lastElementChild;
        if (cell) cell.appendChild(status);
        function save() {
            const payload = new FormData();
            payload.append('csrf_token', csrf);
            payload.append('training_id', trainingId);
            payload.append('action', 'save_prescription_row');
            payload.append('series_id', seriesId);
            row.querySelectorAll('input[form="prescription-form"]').forEach(function (field) {
                if (field.name.indexOf('[weight]') !== -1) payload.append('weight', field.value);
                if (field.name.indexOf('[reps]') !== -1) payload.append('reps', field.value);
                if (field.name.indexOf('[duration]') !== -1) payload.append('duration', field.value);
            });
            status.textContent = 'Ukládám...';
            fetch('<?= BASE_URL ?>/api/online_training_save_prescription.php', { method: 'POST', body: payload, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                .then(function (response) { if (!response.ok) throw new Error('save failed'); return response.json(); })
                .then(function (data) { if (!data.success) throw new Error(data.error || 'save failed'); status.textContent = 'Uloženo'; status.className = 'small text-success ms-2'; })
                .catch(function () { status.textContent = 'Uložení se nepodařilo'; status.className = 'small text-danger ms-2'; });
        }
        row.querySelectorAll('input[form="prescription-form"]').forEach(function (field) {
            field.addEventListener('input', function () { clearTimeout(timers.get(seriesId)); timers.set(seriesId, setTimeout(save, 600)); });
            field.addEventListener('change', save);
        });
    });
})();
</script><?php endif; ?>
<?php if ($isCoach && $training['status'] === 'created'): ?><script>
(function () {
    const attachmentForms = Array.from(document.querySelectorAll('[data-online-attachment-form]'));
    if (!attachmentForms.length) return;
    const warningModal = document.getElementById('onlineAttachmentWarning');
    const warningList = document.getElementById('onlineAttachmentWarningList');
    const goToAttachmentButton = document.getElementById('onlineAttachmentWarningGo');
    const continueButton = document.getElementById('onlineAttachmentWarningContinue');
    let focusTarget = null;
    let allowFocusTarget = null;
    let pendingAction = null;
    let skipSubmitForm = null;
    let submittingAttachment = false;

    function pendingItems() {
        return attachmentForms.flatMap(function (form) {
            const file = form.querySelector('[name="attachment"]');
            const url = form.querySelector('[name="external_url"]');
            const description = form.querySelector('[name="link_description"]');
            const libraryVideo = form.querySelector('[name="video_file_id"]');
            const items = [];
            const exerciseName = form.dataset.exerciseName || 'tréninku';
            if (libraryVideo && libraryVideo.value !== '' && libraryVideo.value !== '0') {
                const selectedVideo = libraryVideo.selectedOptions[0];
                items.push({ form: form, label: exerciseName + ': video z knihovny „' + selectedVideo.textContent + '“' });
            }
            if (file && file.files.length > 0) {
                const selectedFile = file.files[0];
                const kind = selectedFile.type.indexOf('video/') === 0 ? 'video' : (selectedFile.type.indexOf('image/') === 0 ? 'obrázek' : 'soubor');
                items.push({ form: form, label: exerciseName + ': ' + kind + ' „' + selectedFile.name + '“' });
            }
            if (url && url.value.trim() !== '') items.push({ form: form, label: exerciseName + ': odkaz' });
            else if (!libraryVideo?.value || libraryVideo.value === '0') {
                if (description && description.value.trim() !== '') items.push({ form: form, label: exerciseName + ': popis odkazu bez URL' });
            }
            return items;
        });
    }

    function updateButton(form) {
        const button = form.querySelector('.online-attachment-submit');
        if (!button) return;
        const pending = pendingItems().some(function (item) { return item.form === form; });
        button.classList.toggle('is-pending', pending);
        button.setAttribute('aria-label', pending ? 'Příloha čeká na nahrání. Kliknutím ji přidáte.' : 'Přidat přílohu');
        button.innerHTML = pending
            ? '<i class="fas fa-upload me-1"></i>Nahrát přílohu!'
            : '<i class="fas fa-paperclip me-1"></i>Přidat';
    }

    function updateButtons() {
        attachmentForms.forEach(updateButton);
    }

    function closeWarning() {
        warningModal.hidden = true;
        warningModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function showWarning(target, action) {
        const items = pendingItems();
        if (!items.length || !warningModal || !warningList || !warningModal.hidden) return;
        focusTarget = target instanceof HTMLElement ? target : null;
        pendingAction = action || null;
        warningList.replaceChildren();
        items.forEach(function (item) {
            const listItem = document.createElement('li');
            listItem.textContent = item.label;
            warningList.appendChild(listItem);
        });
        warningModal.hidden = false;
        warningModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        goToAttachmentButton.focus();
    }

    attachmentForms.forEach(function (form) {
        form.addEventListener('input', updateButtons);
        form.addEventListener('change', updateButtons);
        const libraryVideo = form.querySelector('[name="video_file_id"]');
        const descriptionLabel = form.querySelector('[data-attachment-description-label]');
        if (libraryVideo && descriptionLabel) {
            const updateDescriptionLabel = function () {
                descriptionLabel.textContent = libraryVideo.value !== '' && libraryVideo.value !== '0'
                    ? 'Vysvětlení videa (volitelné)'
                    : 'Vysvětlení odkazu (volitelné)';
            };
            libraryVideo.addEventListener('change', updateDescriptionLabel);
            updateDescriptionLabel();
        }
    });
    updateButtons();

    goToAttachmentButton.addEventListener('click', function () {
        const nextItem = pendingItems()[0];
        closeWarning();
        if (!nextItem) return;
        const details = nextItem.form.closest('details');
        if (details) details.open = true;
        nextItem.form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const fileInput = nextItem.form.querySelector('[name="attachment"]');
        const urlInput = nextItem.form.querySelector('[name="external_url"]');
        const libraryVideo = nextItem.form.querySelector('[name="video_file_id"]');
        const target = libraryVideo && libraryVideo.value !== '' && libraryVideo.value !== '0'
            ? libraryVideo
            : (fileInput && fileInput.files.length ? fileInput : (urlInput && urlInput.value.trim() ? urlInput : nextItem.form.querySelector('.online-attachment-submit')));
        if (target) target.focus({ preventScroll: true });
    });

    continueButton.addEventListener('click', function () {
        const target = focusTarget;
        const action = pendingAction;
        closeWarning();
        focusTarget = null;
        pendingAction = null;
        if (action && action.submitForm && action.submitForm.isConnected) {
            skipSubmitForm = action.submitForm;
            if (typeof action.submitForm.requestSubmit === 'function') {
                if (action.submitter) action.submitForm.requestSubmit(action.submitter);
                else action.submitForm.requestSubmit();
                if (skipSubmitForm === action.submitForm) skipSubmitForm = null;
            } else {
                skipSubmitForm = null;
                HTMLFormElement.prototype.submit.call(action.submitForm);
            }
        } else if (action && action.href) {
            window.location.assign(action.href);
        } else if (target && target.isConnected) {
            allowFocusTarget = target;
            target.focus({ preventScroll: true });
        }
    });

    document.addEventListener('focusin', function (event) {
        const target = event.target;
        if (!(target instanceof HTMLElement) || warningModal.contains(target)) return;
        if (allowFocusTarget === target) {
            allowFocusTarget = null;
            return;
        }
        const pendingForm = target.closest('[data-online-attachment-form]');
        if (pendingItems().some(function (item) { return item.form !== pendingForm; })) showWarning(target);
    }, true);

    document.addEventListener('click', function (event) {
        const target = event.target;
        if (!(target instanceof Element) || target.closest('#onlineAttachmentWarning')) return;
        const link = target.closest('a[href]');
        if (!link || !pendingItems().length) return;
        event.preventDefault();
        showWarning(target, { href: link.href });
    }, true);

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (skipSubmitForm === form) {
            skipSubmitForm = null;
            return;
        }
        const remainingItems = pendingItems().filter(function (item) { return item.form !== form; });
        if (!remainingItems.length) {
            const action = form.querySelector('input[name="action"]');
            if (action && action.value === 'attachment' && pendingItems().some(function (item) { return item.form === form; })) {
                submittingAttachment = true;
            }
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        showWarning(form, { submitForm: form, submitter: event.submitter || null });
    }, true);

    window.addEventListener('beforeunload', function (event) {
        if (submittingAttachment) return;
        if (!pendingItems().length) return;
        event.preventDefault();
        event.returnValue = '';
    });
})();
</script>
</script><?php endif; ?>
<?php if (!$isCoach && $training['status'] === 'sent'): ?><script>
document.querySelectorAll('input[name^="series["]').forEach(function (field) {
    field.disabled = true;
    field.setAttribute('aria-disabled', 'true');
    field.title = 'Výsledky lze zapisovat až po zahájení online tréninku.';
});
</script><?php endif; ?>
<div id="onlineAttachmentModal" class="online-attachment-modal" hidden aria-hidden="true">
    <button type="button" class="online-attachment-modal__close" aria-label="Zavřít">&times;</button>
    <button type="button" class="online-attachment-modal__fullscreen" hidden><i class="fas fa-expand me-1"></i>Celá obrazovka</button>
    <div class="online-attachment-modal__content" role="dialog" aria-modal="true" aria-label="Příloha online tréninku"></div>
</div>
<style>
.online-attachment-modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 1.5rem; background: rgba(15, 23, 42, .82); }
.online-attachment-modal[hidden] { display: none; }
.online-attachment-modal__content { width: min(1200px, 94vw); max-height: 90vh; color: #fff; text-align: center; }
.online-attachment-modal__content img, .online-attachment-modal__content video { max-width: 100%; max-height: 82vh; border-radius: 10px; box-shadow: 0 14px 50px rgba(0, 0, 0, .35); }
.online-attachment-modal__content video { width: min(1200px, 92vw); height: auto; background: #000; }
.online-attachment-modal__content a { color: #fff; font-weight: 700; }
.online-attachment-modal__close { position: absolute; top: .65rem; right: 1rem; border: 0; background: transparent; color: #fff; font-size: 2.5rem; line-height: 1; cursor: pointer; }
.online-attachment-modal__fullscreen { position: absolute; top: 1rem; left: 1rem; border: 1px solid rgba(255,255,255,.7); border-radius: .4rem; padding: .5rem .75rem; background: rgba(15,23,42,.7); color: #fff; cursor: pointer; }
.online-attachment-modal__fullscreen[hidden] { display: none; }
</style>
<script>
(function () {
    const modal = document.getElementById('onlineAttachmentModal');
    if (!modal) return;
    const content = modal.querySelector('.online-attachment-modal__content');
    const closeButton = modal.querySelector('.online-attachment-modal__close');
    const fullscreenButton = modal.querySelector('.online-attachment-modal__fullscreen');
    let activeVideo = null;
    function closeModal() {
        if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen();
        if (activeVideo) {
            activeVideo.pause();
            activeVideo.removeAttribute('src');
            activeVideo.load();
            activeVideo = null;
        }
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        fullscreenButton.hidden = true;
        content.replaceChildren();
        document.body.style.overflow = '';
    }
    document.querySelectorAll('.online-attachment-open').forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            const image = document.createElement('img');
            image.src = trigger.href;
            image.alt = 'Fotografie k online tréninku';
            closeModal();
            content.replaceChildren(image);
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        });
    });
    document.querySelectorAll('.online-video-open').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            closeModal();
            const video = document.createElement('video');
            video.controls = true;
            video.playsInline = true;
            video.preload = 'metadata';
            video.src = trigger.dataset.videoSrc;
            activeVideo = video;
            content.replaceChildren(video);
            fullscreenButton.hidden = false;
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            video.play().catch(function () {});
        });
    });
    fullscreenButton.addEventListener('click', function () {
        if (!activeVideo) return;
        const requestFullscreen = activeVideo.requestFullscreen || activeVideo.webkitRequestFullscreen;
        if (!requestFullscreen) {
            alert('Celá obrazovka není v tomto prohlížeči podporována.');
            return;
        }
        const result = requestFullscreen.call(activeVideo);
        if (result && typeof result.catch === 'function') {
            result.catch(function () { alert('Přehrávač se nepodařilo zvětšit na celou obrazovku.'); });
        }
    });
    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) closeModal(); });
})();
</script>
<?php if (!$isCoach && $training['status'] === 'completed'): ?>
<div class="d-flex justify-content-end mb-4">
<a href="<?= h($back) ?>" class="btn btn-outline-secondary"><i class="fas fa-times me-2"></i>Zavřít</a>
</div>
<?php endif; ?>
<?php if ($isCoach) renderFooter(); else renderAthleteFooter();