<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = mobileInput();
    $action = (string)($input['action'] ?? $_REQUEST['action'] ?? '');
    if ($action !== 'create') {
        mobileJson(['success' => false, 'error' => 'Neznámá akce.'], 400);
    }

    $firstName = trim((string)($input['first_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $birthDate = trim((string)($input['birth_date'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $gender = trim((string)($input['gender'] ?? 'unknown'));
    $allowedGender = ['unknown', 'female', 'male', 'other', 'prefer_not_say'];
    if (!in_array($gender, $allowedGender, true)) {
        $gender = 'unknown';
    }

    if ($firstName === '' || $lastName === '') {
        mobileJson(['success' => false, 'error' => 'Vyplňte jméno a příjmení.'], 422);
    }
    if ($birthDate === '' || !DateTime::createFromFormat('Y-m-d', $birthDate)) {
        mobileJson(['success' => false, 'error' => 'Zadejte platné datum narození.'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mobileJson(['success' => false, 'error' => 'Zadejte platnou e-mailovou adresu.'], 422);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO athletes (coach_id, first_name, last_name, birth_date, gender, phone_contact, email, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $coach['id'],
            $firstName,
            $lastName,
            $birthDate,
            $gender,
            $phone !== '' ? $phone : null,
            $email !== '' ? $email : null,
            $notes !== '' ? $notes : null,
        ]);
        $newId = (int)$pdo->lastInsertId();
        try {
            $pdo->prepare("INSERT INTO gallery_folders (coach_id, name, folder_type, athlete_id) VALUES (?, ?, 'athlete', ?)")
                ->execute([$coach['id'], $firstName . ' ' . $lastName, $newId]);
        } catch (Throwable $e) {
        }
        mobileJson(['success' => true, 'id' => $newId]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Sportovce se nepodařilo uložit.'], 500);
    }
}

try {
    $hasTrainerId = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'trainer_id'");
        if ($chk && $chk->fetch()) {
            $hasTrainerId = true;
        }
    } catch (Throwable $e) {}

    if ($hasTrainerId) {
        $stmt = $pdo->prepare('SELECT * FROM athletes WHERE coach_id = ? OR trainer_id = ? ORDER BY first_name ASC, last_name ASC');
        $stmt->execute([$coach['id'], $coach['id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM athletes WHERE coach_id = ? ORDER BY first_name ASC, last_name ASC');
        $stmt->execute([$coach['id']]);
    }
    $athletes = $stmt->fetchAll();

    if (empty($athletes)) {
        $stmtAll = $pdo->query('SELECT * FROM athletes ORDER BY first_name ASC, last_name ASC LIMIT 100');
        if ($stmtAll) {
            $athletes = $stmtAll->fetchAll();
        }
    }

    $recentByAthlete = [];
    try {
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $athletes);
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $trainingStmt = $pdo->prepare(
                "SELECT ts.id, ts.athlete_id,
                        COALESCE(ts.completed_at, ts.started_at) AS trained_at,
                        COALESCE(ws.name, 'Trénink') AS set_name
                 FROM training_sessions ts
                 LEFT JOIN workout_sets ws ON ws.id = ts.workout_set_id
                 WHERE ts.athlete_id IN ($placeholders)
                   AND ts.deleted_by_coach_at IS NULL
                   AND COALESCE(ts.completed_at, ts.started_at) IS NOT NULL
                 ORDER BY COALESCE(ts.completed_at, ts.started_at) DESC, ts.id DESC"
            );
            $trainingStmt->execute($ids);
            foreach ($trainingStmt->fetchAll() as $row) {
                $athleteId = (int)$row['athlete_id'];
                if (!isset($recentByAthlete[$athleteId])) {
                    $recentByAthlete[$athleteId] = [];
                }
                if (count($recentByAthlete[$athleteId]) >= 3) {
                    continue;
                }
                $recentByAthlete[$athleteId][] = [
                    'id' => (int)$row['id'],
                    'date' => (string)$row['trained_at'],
                    'set_name' => (string)$row['set_name'],
                ];
            }
        }
    } catch (Throwable $e) {
        $recentByAthlete = [];
    }

    $result = [];
    foreach ($athletes as $ath) {
        $fullName = trim((string)($ath['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim((string)($ath['first_name'] ?? '') . ' ' . (string)($ath['last_name'] ?? ''));
        }
        if ($fullName === '') {
            $fullName = 'Sportovec #' . $ath['id'];
        }

        $email = (string)($ath['email'] ?? '');
        $phone = (string)($ath['phone_contact'] ?? $ath['phone'] ?? $ath['telephone'] ?? '');
        $note = (string)($ath['note'] ?? $ath['detail'] ?? '');
        $photo = mobilePublicPhoto((string)($ath['photo'] ?? $ath['avatar'] ?? $ath['image'] ?? ''), 'athletes');

        $result[] = [
            'id' => (int)$ath['id'],
            'first_name' => (string)($ath['first_name'] ?? ''),
            'last_name' => (string)($ath['last_name'] ?? ''),
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'photo' => $photo,
            'note' => $note,
            'recent_trainings' => $recentByAthlete[(int)$ath['id']] ?? [],
        ];
    }

    mobileJson(['success' => true, 'athletes' => $result]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => 'Chyba serveru: ' . $e->getMessage()], 500);
}
