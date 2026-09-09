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
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) $error = 'Neplatný bezpečnostní token.';
    else try {
        $action = (string)($_POST['action'] ?? '');
        if ($isCoach && $action === 'send') {
            $billingType = (string)($_POST['billing_type'] ?? 'free');
            $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
            if (str_starts_with($billingType, 'subscription:')) {
                $subscriptionId = (int)substr($billingType, 13);
                $billingType = 'subscription';
            }
            onlineTrainingSend($pdo, $id, $coachId, $billingType, $subscriptionId ?: null, (int)($_POST['subscription_total'] ?? 0) ?: null, (float)($_POST['subscription_price'] ?? 0));
            flash('success', 'Online trénink byl odeslán.'); redirect(BASE_URL . '/online_training.php?id=' . $id);
        }
        if ($isCoach && $action === 'note') {
            $pdo->prepare('UPDATE online_trainings SET coach_note = ? WHERE id = ? AND trainer_id = ?')->execute([trim((string)$_POST['coach_note']), $id, $coachId]);
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
                if ($duplicate->fetch()) throw new RuntimeException('Tento cvik už v online tréninku je.');
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
            $attachment = null;
            if (!empty($_FILES['attachment'])) $attachment = onlineTrainingSaveUpload($_FILES['attachment'], $isCoach ? 'trainer' : 'athlete');
            $url = trim((string)($_POST['external_url'] ?? ''));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('Odkaz na video není platný.');
            if (!$attachment && $url === '') throw new RuntimeException('Vyberte soubor nebo zadejte URL.');
            $targetExerciseId = (int)($_POST['online_training_exercise_id'] ?? 0);
            if ($targetExerciseId > 0) {
                $targetCheck = $pdo->prepare('SELECT id FROM online_training_exercises WHERE id = ? AND online_training_id = ?');
                $targetCheck->execute([$targetExerciseId, $id]);
                if (!$targetCheck->fetch()) throw new RuntimeException('Cílový cvik nebyl nalezen.');
            }
            if ($attachment) {
                $pdo->prepare('INSERT INTO online_training_attachments (online_training_id, online_training_exercise_id, attachment_type, file_path, original_name, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $targetExerciseId ?: null, $attachment['type'], $attachment['path'], $attachment['name'], $attachment['uploaded_by']]);
            }
            if ($url !== '') $pdo->prepare("INSERT INTO online_training_attachments (online_training_id, online_training_exercise_id, attachment_type, external_url, uploaded_by) VALUES (?, ?, 'url', ?, ?)")->execute([$id, $targetExerciseId ?: null, $url, $isCoach ? 'trainer' : 'athlete']);
        }
        if ($action === 'delete_attachment') {
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
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$exercises = onlineTrainingLoadExercises($pdo, $id);
$availableExercises = [];
if ($isCoach && $training['status'] === 'created') {
    $availableStmt = $pdo->prepare('SELECT id, name FROM exercises WHERE coach_id = ? OR is_global = 1 ORDER BY name');
    $availableStmt->execute([$coachId]);
    $availableExercises = $availableStmt->fetchAll();
}
$attachmentStmt = $pdo->prepare('SELECT ota.*, ote.exercise_name FROM online_training_attachments ota LEFT JOIN online_training_exercises ote ON ote.id = ota.online_training_exercise_id WHERE ota.online_training_id = ? ORDER BY ota.created_at, ota.id');
$attachmentStmt->execute([$id]);
$attachments = $attachmentStmt->fetchAll();
$subscriptions = [];
if ($isCoach) { $subStmt = $pdo->prepare("SELECT * FROM online_training_subscriptions WHERE trainer_id = ? AND athlete_id = ? AND status = 'active' AND remaining_trainings > 0 ORDER BY purchased_at"); $subStmt->execute([$coachId, (int)$training['athlete_id']]); $subscriptions = $subStmt->fetchAll(); }
if ($isCoach) { require_once __DIR__ . '/includes/header.php'; renderHeader('Online trénink #' . $training['sequence_number'], false, true); } else { require_once __DIR__ . '/includes/athlete_header.php'; renderAthleteHeader('Online trénink #' . $training['sequence_number'], false, true); }
?><div class="d-flex align-items-center gap-3 flex-wrap mb-4"><a href="<?= $back ?>" class="btn btn-outline-secondary btn-sm">Zpět</a><h2 class="mb-0"><i class="fas fa-laptop me-2 text-warning"></i>ONLINE #<?= str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT) ?></h2><span class="badge bg-<?= h(onlineTrainingStatusClass($training['status'])) ?>"><?= h(onlineTrainingStatusLabel($training['status'])) ?></span></div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<div class="row g-3 mb-4"><div class="col-md-8"><div class="card shadow-sm"><div class="card-body"><h4><?= h($training['title']) ?></h4><p class="mb-1"><strong><?= $isCoach ? 'Sportovec' : 'Trenér' ?>:</strong> <?= h($isCoach ? $training['first_name'] . ' ' . $training['last_name'] : $training['coach_name']) ?></p><p class="mb-1"><strong>Odesláno:</strong> <?= h(formatDateTime($training['sent_at'])) ?></p><?php if ($training['started_at']): ?><p class="mb-1"><strong>Začátek:</strong> <?= h(formatDateTime($training['started_at'])) ?></p><?php endif; ?><?php if ($training['completed_at']): ?><p class="mb-1"><strong>Konec:</strong> <?= h(formatDateTime($training['completed_at'])) ?> · <?= formatSeriesDuration((int)$training['duration_seconds']) ?></p><?php endif; ?></div></div></div><div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><strong>Účtování</strong><div><?= $training['billing_type'] === 'subscription' ? 'Čerpání z předplatného' : ($training['billing_type'] === 'single' ? number_format((float)$training['price'], 2, ',', ' ') . ' Kč' : 'Zdarma') ?></div></div></div></div></div>
<?php if (!$isCoach && $training['status'] === 'sent'): ?><form method="post" class="mb-3"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="start"><button class="btn btn-warning btn-lg fw-bold">Zahájit online trénink</button></form><?php endif; ?>
<?php if (!$isCoach && $training['status'] === 'sent'): ?><div class="alert alert-warning text-center py-3 mb-4"><i class="fas fa-lock me-2"></i><strong>Pro zápis váhy a opakování nejdříve zahajte online trénink.</strong><div class="small mt-1">Po zahájení se zpřístupní všechny série.</div></div><?php endif; ?>
<?php if (!empty($training['coach_note'])): ?><div class="alert alert-info"><strong>Poznámka trenéra:</strong><div style="white-space:pre-wrap"><?= h((string)$training['coach_note']) ?></div></div><?php endif; ?>
<?php if ($isCoach && !empty($training['athlete_note'])): ?><div class="alert alert-secondary"><strong>Poznámka sportovce:</strong><div style="white-space:pre-wrap"><?= h((string)$training['athlete_note']) ?></div></div><?php endif; ?>
<?php if ($isCoach && $training['status'] === 'created'): ?><form method="post" class="card shadow-sm mb-4"><div class="card-body"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="send"><h5>Způsob účtování</h5><select name="billing_type" class="form-select mb-2"><option value="auto" selected>Automaticky - <?= $subscriptions ? 'čerpání z aktivního předplatného' : (($training['athlete_online_rate'] ?? null) !== null ? 'jednorázová sazba sportovce' : 'vyžaduje volbu zdarma') ?></option><?php if ($subscriptions): ?><?php foreach ($subscriptions as $sub): ?><option value="subscription:<?= (int)$sub['id'] ?>">Předplatné - zbývá <?= (int)$sub['remaining_trainings'] ?>/<?= (int)$sub['total_trainings'] ?></option><?php endforeach; ?><?php endif; ?><?php if (($training['athlete_online_rate'] ?? null) !== null && (float)$training['athlete_online_rate'] > 0): ?><option value="single">Jednorázově - <?= number_format((float)$training['athlete_online_rate'], 2, ',', ' ') ?> Kč</option><?php endif; ?><option value="free">Zdarma</option></select><div class="form-text">Předplatné má přednost. Pokud není aktivní, použije se uložená sazba sportovce. Nový balík založte v detailu sportovce.</div><button class="btn btn-warning fw-bold mt-3">Odeslat sportovci</button></div></form><?php endif; ?>
<?php if ($isCoach && $training['status'] === 'created'): ?><div class="card border-warning shadow-sm mb-4"><div class="card-body"><div class="row g-3 align-items-end"><form method="post" class="col-md-6 row g-2"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="add_exercise"><div class="col"><label class="form-label small">Přidat cvik</label><select name="exercise_id" class="form-select" required><option value="">Vyberte cvik</option><?php foreach ($availableExercises as $availableExercise): ?><option value="<?= (int)$availableExercise['id'] ?>"><?= h($availableExercise['name']) ?></option><?php endforeach; ?></select></div><div class="col-auto"><button class="btn btn-outline-warning mt-4"><i class="fas fa-plus me-1"></i>Přidat</button></div></form><form method="post" class="col-md-6"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="reorder_exercises"><label class="form-label small">Pořadí cviků</label><?php foreach ($exercises as $exercise): ?><div class="input-group input-group-sm mb-1"><span class="input-group-text flex-grow-1"><?= h($exercise['exercise_name']) ?></span><input name="exercise_order[<?= (int)$exercise['id'] ?>]" type="number" min="1" class="form-control" value="<?= (int)$exercise['exercise_order'] ?>"></div><?php endforeach; ?><button class="btn btn-outline-dark btn-sm mt-1">Uložit pořadí</button></form></div></div></div><?php endif; ?>
<div class="card shadow-sm mb-4"><div class="card-header bg-dark text-white">Cviky a série</div><?php foreach ($exercises as $exercise): ?><div class="p-3 border-bottom"><div class="d-flex justify-content-between align-items-center"><h5 class="mb-2"><?= (int)$exercise['exercise_order'] ?>. <?= h($exercise['exercise_name']) ?></h5><?php if ($isCoach && $training['status'] === 'created'): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="add_series"><input type="hidden" name="online_exercise_id" value="<?= (int)$exercise['id'] ?>"><button class="btn btn-sm btn-outline-warning"><i class="fas fa-plus me-1"></i>Přidat sérii</button></form><?php endif; ?></div><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Série</th><th>Předepsaná váha</th><th>Předepsaná opakování</th><?php if (!$isCoach): ?><th>Moje váha</th><th>Moje opakování</th><?php elseif ($training['status'] === 'created'): ?><th></th><?php endif; ?></tr></thead><tbody><?php foreach ($exercise['series'] as $series): ?><tr><td><?= (int)$series['series_order'] ?></td><td><?php if ($isCoach && $training['status'] === 'created'): ?><input form="prescription-form" name="prescribed[<?= (int)$series['id'] ?>][weight]" class="form-control form-control-sm" type="number" step="0.01" value="<?= h((string)($series['prescribed_weight'] ?? '')) ?>"><?php else: ?><?= $series['prescribed_weight'] !== null ? h((string)$series['prescribed_weight']) . ' kg' : '-' ?><?php endif; ?></td><td><?php if ($isCoach && $training['status'] === 'created'): ?><input form="prescription-form" name="prescribed[<?= (int)$series['id'] ?>][reps]" class="form-control form-control-sm" type="number" value="<?= h((string)($series['prescribed_reps'] ?? '')) ?>"><?php else: ?><?= $series['prescribed_reps'] !== null ? (int)$series['prescribed_reps'] : '-' ?><?php endif; ?></td><?php if (!$isCoach): ?><td><input form="result-form" name="series[<?= (int)$series['id'] ?>][weight]" class="form-control form-control-sm" type="number" step="0.01" value="<?= h((string)($series['actual_weight'] ?? '')) ?>"></td><td><input form="result-form" name="series[<?= (int)$series['id'] ?>][reps]" class="form-control form-control-sm" type="number" value="<?= h((string)($series['actual_reps'] ?? '')) ?>"></td><?php elseif ($training['status'] === 'created'): ?><td><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="delete_series"><input type="hidden" name="online_series_id" value="<?= (int)$series['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Smazat sérii"><i class="fas fa-times"></i></button></form></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></div><?php endforeach; ?></div>
<?php if ($isCoach && $training['status'] === 'created'): ?><form id="prescription-form" method="post" class="mb-4"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="prescription"><button class="btn btn-outline-dark">Uložit předepsané hodnoty</button></form><?php endif; ?>
<div class="card shadow-sm mb-4"><div class="card-header bg-dark text-white">Přílohy k tréninku nebo cviku</div><div class="card-body"><?php if (!$attachments): ?><div class="text-muted">Zatím bez příloh.</div><?php else: ?><div class="d-flex flex-wrap gap-3"><?php foreach ($attachments as $attachment): ?><div class="border rounded p-2"><div class="small text-muted mb-1"><?= !empty($attachment['online_training_exercise_id']) ? 'Ke cviku' : 'K celému tréninku' ?></div><?php if ($attachment['attachment_type'] === 'photo'): ?><a href="<?= BASE_URL ?>/uploads/<?= h($attachment['file_path']) ?>" target="_blank"><img src="<?= BASE_URL ?>/uploads/<?= h($attachment['file_path']) ?>" alt="Příloha" style="width:120px;height:120px;object-fit:cover;border-radius:8px"></a><?php elseif ($attachment['attachment_type'] === 'video'): ?><video controls style="max-width:280px;max-height:180px"><source src="<?= BASE_URL ?>/uploads/<?= h($attachment['file_path']) ?>"></video><?php else: ?><a href="<?= h($attachment['external_url']) ?>" target="_blank" rel="noopener">Otevřít odkaz na video</a><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?><form method="post" enctype="multipart/form-data" class="row g-2 mt-3"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="attachment"><div class="col-md-3"><select name="online_training_exercise_id" class="form-select"><option value="0">K celému online tréninku</option><?php foreach ($exercises as $exercise): ?><option value="<?= (int)$exercise['id'] ?>">Cvik: <?= h($exercise['exercise_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><input type="file" name="attachment" class="form-control" accept="image/*,video/*"></div><div class="col-md-4"><input name="external_url" type="url" class="form-control" placeholder="Odkaz na video"></div><div class="col-md-2"><button class="btn btn-outline-dark w-100">Přidat</button></div></form></div></div>
<?php if (!$isCoach && in_array($training['status'], ['in_progress', 'completed'], true)): ?><form id="result-form" method="post" class="card shadow-sm mb-4"><div class="card-body"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="save"><label class="form-label fw-bold">Moje poznámka</label><textarea name="athlete_note" class="form-control mb-3" rows="3"><?= h((string)($training['athlete_note'] ?? '')) ?></textarea><?php if ($training['status'] === 'in_progress'): ?><span id="online-save-state" class="small text-muted me-2">Výsledky se ukládají po jednotlivých sériích.</span><?php else: ?><button class="btn btn-outline-dark">Uložit poznámku</button><?php endif; ?></div></form><?php endif; ?>
<?php if (!$isCoach && $training['status'] === 'in_progress'): ?><form method="post" action="<?= BASE_URL ?>/api/online_training_complete.php" class="mb-4" onsubmit="return confirm('Opravdu dokončit online trénink? Po dokončení už nepůjdou výsledky upravovat.');"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><button class="btn btn-success">Dokončit online trénink</button></form><?php endif; ?>
<?php if ($isCoach): ?><form method="post" class="card shadow-sm"><div class="card-body"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="note"><label class="form-label fw-bold">Poznámka trenéra</label><textarea name="coach_note" class="form-control" rows="3"><?= h((string)($training['coach_note'] ?? '')) ?></textarea><button class="btn btn-outline-dark mt-2">Uložit poznámku</button></div></form><?php endif; ?>
<?php if ($isCoach && $training['status'] === 'created'): ?><div class="card border-danger shadow-sm mb-4"><div class="card-body"><strong class="d-block mb-2">Odebrat cvik z draftu</strong><div class="d-flex flex-wrap gap-2"><?php foreach ($exercises as $exercise): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="remove_exercise"><input type="hidden" name="online_exercise_id" value="<?= (int)$exercise['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i><?= h($exercise['exercise_name']) ?></button></form><?php endforeach; ?></div></div></div><?php endif; ?>
<?php if ($isCoach || in_array($training['status'], ['in_progress', 'completed'], true)): ?><div class="card border-secondary shadow-sm mb-4"><div class="card-body"><strong class="d-block mb-2">Moje nahrané přílohy</strong><div class="d-flex flex-wrap gap-2"><?php foreach ($attachments as $attachment): ?><?php if ((string)$attachment['uploaded_by'] === ($isCoach ? 'trainer' : 'athlete')): ?><div class="border rounded p-2 bg-light" style="min-width:190px"><div class="small fw-semibold"><?= !empty($attachment['online_training_exercise_id']) ? 'Cvik: ' . h((string)($attachment['exercise_name'] ?? '')) : 'Celý online trénink' ?></div><div class="small text-muted mb-2"><?php if ($attachment['attachment_type'] === 'photo'): ?><i class="fas fa-image me-1"></i>Fotografie<?php elseif ($attachment['attachment_type'] === 'video'): ?><i class="fas fa-video me-1"></i>Video soubor<?php else: ?><i class="fas fa-link me-1"></i>Odkaz na video<?php endif; ?><?= !empty($attachment['original_name']) ? ': ' . h((string)$attachment['original_name']) : '' ?></div><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="training_id" value="<?= $id ?>"><input type="hidden" name="action" value="delete_attachment"><input type="hidden" name="attachment_id" value="<?= (int)$attachment['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Smazat přílohu</button></form></div><?php endif; ?><?php endforeach; ?></div></div></div><?php endif; ?>
<?php if (!$isCoach && in_array($training['status'], ['in_progress', 'completed'], true)): ?><script>
(function () {
    const resultForm = document.getElementById('result-form');
    const saveState = document.getElementById('online-save-state');
    let saveTimer = null;
    let saveRequest = null;
    function saveResults() {
        if (!resultForm) return;
        if (saveRequest) saveRequest.abort();
        const payload = new FormData(resultForm);
        saveRequest = fetch(window.location.href, { method: 'POST', body: payload, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { if (!response.ok) throw new Error('save failed'); if (saveState) saveState.textContent = 'Uloženo ' + new Date().toLocaleTimeString('cs-CZ'); })
            .catch(function () { if (saveState) saveState.textContent = 'Uložení se nepodařilo, zkuste tlačítko Uložit výsledky.'; });
    }
    if (resultForm) {
        const completed = <?= $training['status'] === 'completed' ? 'true' : 'false' ?>;
        document.querySelectorAll('input[form="result-form"][name^="series["]').forEach(function (field) {
            const row = field.closest('tr');
            if (!row || row.dataset.rowPrepared === '1') return;
            row.dataset.rowPrepared = '1';
            const match = field.name.match(/^series\[(\d+)\]/);
            if (!match) return;
            const seriesId = match[1];
            const rowFields = Array.from(row.querySelectorAll('input[name^="series["]'));
            const status = document.createElement('span');
            status.className = 'small text-muted';
            status.textContent = completed ? 'Dokončeno' : 'Průběžné ukládání';
            const actionCell = document.createElement('td');
            actionCell.className = 'online-series-actions text-nowrap';
            actionCell.appendChild(status);
            row.appendChild(actionCell);
            function saveRow() {
                const payload = new FormData();
                payload.append('csrf_token', resultForm.querySelector('[name="csrf_token"]').value);
                payload.append('training_id', resultForm.querySelector('[name="training_id"]').value);
                payload.append('action', 'save_series_row');
                payload.append('series_id', seriesId);
                rowFields.forEach(function (input) {
                    if (input.name.indexOf('[weight]') !== -1) payload.append('weight', input.value);
                    if (input.name.indexOf('[reps]') !== -1) payload.append('reps', input.value);
                    if (input.name.indexOf('[duration]') !== -1) payload.append('duration', input.value);
                    if (input.name.indexOf('[note]') !== -1) payload.append('note', input.value);
                });
                status.textContent = 'Ukládám...';
                status.className = 'small text-muted';
                fetch('<?= BASE_URL ?>/api/online_training_save_series.php', { method: 'POST', body: payload, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function (response) { return response.text().then(function (text) { if (!response.ok) throw new Error(text || 'save failed'); try { return JSON.parse(text); } catch (error) { throw new Error(text || 'save failed'); } }); })
                    .then(function (data) {
                        if (!data.success) throw new Error(data.error || 'save failed');
                        status.textContent = 'Uloženo';
                        status.className = 'small text-success fw-semibold';
                    })
                    .catch(function () { status.textContent = 'Uložení se nepodařilo'; status.className = 'small text-danger fw-semibold'; });
            }
            rowFields.forEach(function (field) {
                field.disabled = completed;
                field.addEventListener('input', function () { clearTimeout(saveTimer); saveTimer = setTimeout(saveRow, 650); });
                field.addEventListener('change', saveRow);
            });
        });
        const bulkSaveButton = resultForm.querySelector('button[type="submit"]:not([name="action"])');
        if (bulkSaveButton) bulkSaveButton.hidden = true;
        if (saveState) saveState.textContent = 'Výsledky se ukládají po jednotlivých sériích.';
    }
})();
</script><?php endif; ?>
<?php if ($isCoach && $training['status'] === 'created'): ?><script>
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
<?php if ($isCoach && in_array($training['status'], ['in_progress', 'completed'], true)): ?><div class="card border-success shadow-sm mb-4"><div class="card-header bg-success text-white"><i class="fas fa-chart-line me-2"></i>Skutečné výsledky sportovce</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead class="table-light"><tr><th>Cvik</th><th>Série</th><th>Skutečná váha</th><th>Skutečná opakování</th><th>Poznámka série</th></tr></thead><tbody><?php foreach ($exercises as $exercise): ?><?php foreach ($exercise['series'] as $series): ?><tr><td><?= h($exercise['exercise_name']) ?></td><td><?= (int)$series['series_order'] ?></td><td><?= $series['actual_weight'] !== null ? h((string)$series['actual_weight']) . ' kg' : '–' ?></td><td><?= $series['actual_reps'] !== null ? (int)$series['actual_reps'] : '–' ?></td><td><?= !empty($series['result_note']) ? h((string)$series['result_note']) : '–' ?></td></tr><?php endforeach; ?><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>
<?php if (!$isCoach && $training['status'] === 'sent'): ?><script>
document.querySelectorAll('input[name^="series["]').forEach(function (field) {
    field.disabled = true;
    field.setAttribute('aria-disabled', 'true');
    field.title = 'Výsledky lze zapisovat až po zahájení online tréninku.';
});
</script><?php endif; ?>
<div id="onlineAttachmentModal" class="online-attachment-modal" hidden aria-hidden="true">
    <button type="button" class="online-attachment-modal__close" aria-label="Zavřít">&times;</button>
    <div class="online-attachment-modal__content" role="dialog" aria-modal="true" aria-label="Příloha online tréninku"></div>
</div>
<style>
.online-attachment-modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 1.5rem; background: rgba(15, 23, 42, .82); }
.online-attachment-modal[hidden] { display: none; }
.online-attachment-modal__content { max-width: min(1100px, 94vw); max-height: 90vh; color: #fff; text-align: center; }
.online-attachment-modal__content img, .online-attachment-modal__content video { max-width: 100%; max-height: 82vh; border-radius: 10px; box-shadow: 0 14px 50px rgba(0, 0, 0, .35); }
.online-attachment-modal__content a { color: #fff; font-weight: 700; }
.online-attachment-modal__close { position: absolute; top: .65rem; right: 1rem; border: 0; background: transparent; color: #fff; font-size: 2.5rem; line-height: 1; cursor: pointer; }
</style>
<script>
(function () {
    const modal = document.getElementById('onlineAttachmentModal');
    if (!modal) return;
    const content = modal.querySelector('.online-attachment-modal__content');
    const closeButton = modal.querySelector('.online-attachment-modal__close');
    const attachmentExerciseLabels = <?= json_encode(array_values(array_map(static fn(array $attachment): string => 'Cvik: ' . (string)($attachment['exercise_name'] ?? ''), array_filter($attachments, static fn(array $attachment): bool => !empty($attachment['online_training_exercise_id'])))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let attachmentLabelIndex = 0;
    document.querySelectorAll('.card .small.text-muted.mb-1').forEach(function (label) {
        if (label.textContent.trim() !== 'Ke cviku') return;
        const exerciseLabel = attachmentExerciseLabels[attachmentLabelIndex++];
        if (exerciseLabel) label.textContent = exerciseLabel;
    });
    function closeModal() { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); content.innerHTML = ''; document.body.style.overflow = ''; }
    document.querySelectorAll('.online-attachment-open, .card a[target="_blank"]').forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            const source = trigger.dataset.mediaSrc || trigger.href;
            const type = trigger.dataset.mediaType || (source.indexOf('/uploads/') !== -1 ? 'photo' : 'url');
            if (type === 'photo') content.innerHTML = '<img src="' + source + '" alt="Příloha online tréninku">';
            else if (type === 'video') content.innerHTML = '<video controls autoplay><source src="' + source + '"></video>';
            else content.innerHTML = '<p>Video je dostupné na externí stránce.</p><a href="' + source + '" target="_blank" rel="noopener">Otevřít video</a>';
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        });
    });
    document.querySelectorAll('.card video').forEach(function (video) {
        const sourceElement = video.querySelector('source');
        if (!sourceElement) return;
        video.addEventListener('click', function (event) {
            event.preventDefault();
            content.innerHTML = '<video controls autoplay><source src="' + sourceElement.src + '"></video>';
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        });
    });
    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) closeModal(); });
})();
</script>
<?php if ($isCoach && in_array($training['status'], ['in_progress', 'completed'], true)): ?><script>
(function () {
    const resultsBySeries = <?= json_encode(array_reduce($exercises, static function (array $result, array $exercise): array { foreach ($exercise['series'] as $series) { $result[(int)$series['id']] = ['weight' => $series['actual_weight'], 'reps' => $series['actual_reps']]; } return $result; }, []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const trainingCard = Array.from(document.querySelectorAll('.card')).find(function (card) {
        const header = card.querySelector('.card-header');
        return header && header.textContent.trim() === 'Cviky a série';
    });
    if (!trainingCard) return;
    const table = trainingCard.querySelector('table');
    if (!table) return;
    const headerRow = table.querySelector('thead tr');
    if (!headerRow) return;
    const weightHeader = document.createElement('th');
    weightHeader.textContent = 'Skutečná váha';
    const repsHeader = document.createElement('th');
    repsHeader.textContent = 'Skutečná opakování';
    headerRow.appendChild(weightHeader);
    headerRow.appendChild(repsHeader);
    const seriesIds = <?= json_encode(array_values(array_merge(...array_map(static fn(array $exercise): array => array_map(static fn(array $series): int => (int)$series['id'], $exercise['series']), $exercises))), JSON_UNESCAPED_UNICODE) ?>;
    let index = 0;
    table.querySelectorAll('tbody tr').forEach(function (row) {
        const series = resultsBySeries[seriesIds[index++]] || {};
        const weightCell = document.createElement('td');
        weightCell.textContent = series.weight !== null && series.weight !== undefined ? series.weight + ' kg' : '–';
        const repsCell = document.createElement('td');
        repsCell.textContent = series.reps !== null && series.reps !== undefined ? series.reps : '–';
        row.appendChild(weightCell);
        row.appendChild(repsCell);
    });
    Array.from(document.querySelectorAll('.card')).forEach(function (card) {
        const header = card.querySelector('.card-header');
        if (header && header.textContent.includes('Skutečné výsledky sportovce')) card.hidden = true;
    });
})();
</script><?php endif; ?>
<?php if ($isCoach) renderFooter(); else renderAthleteFooter();