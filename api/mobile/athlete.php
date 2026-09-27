<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$athleteId = (int)($_GET['id'] ?? 0);
if ($athleteId <= 0) mobileJson(['success' => false, 'error' => 'Chybí ID sportovce.'], 422);

$pdo = getDB();

try {
    $stmt = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
    $stmt->execute([$athleteId, $coach['id']]);
    $athlete = $stmt->fetch();
    if (!$athlete) mobileJson(['success' => false, 'error' => 'Sportovec nebyl nalezen.'], 404);

    $birthDate = !empty($athlete['birth_date']) ? (string)$athlete['birth_date'] : null;
    $age = null;
    if ($birthDate) {
        try {
            $dob = new DateTimeImmutable($birthDate);
            $now = new DateTimeImmutable('today');
            $age = $dob->diff($now)->y;
        } catch (Throwable $e) {}
    }

    $trainings = [];
    try {
        $trainingStmt = $pdo->prepare(
            'SELECT ts.id, ts.started_at, ts.completed_at, ts.workout_set_id, ts.location, ts.notes,
                    COALESCE(ws.name, "Trénink") AS set_name,
                    (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
             FROM training_sessions ts
             LEFT JOIN workout_sets ws ON ws.id = ts.workout_set_id
             WHERE ts.athlete_id = ? AND ts.deleted_by_coach_at IS NULL
             ORDER BY COALESCE(ts.started_at, ts.created_at) DESC, ts.id DESC
             LIMIT 50'
        );
        $trainingStmt->execute([$athleteId]);

        foreach ($trainingStmt->fetchAll() as $row) {
            $trainings[] = [
                'id' => (int)$row['id'],
                'started_at' => (string)($row['started_at'] ?? ''),
                'completed_at' => (string)($row['completed_at'] ?? ''),
                'workout_set_id' => $row['workout_set_id'] !== null ? (int)$row['workout_set_id'] : null,
                'set_name' => (string)($row['set_name'] ?? 'Trénink'),
                'total_series' => (int)($row['total_series'] ?? 0),
                'location' => (string)($row['location'] ?? ''),
                'notes' => (string)($row['notes'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}

    $weightLogs = [];
    try {
        $wStmt = $pdo->prepare('SELECT id, measured_at, weight_kg, source FROM athlete_weight_logs WHERE athlete_id = ? ORDER BY measured_at DESC, id DESC LIMIT 50');
        $wStmt->execute([$athleteId]);
        foreach ($wStmt->fetchAll() as $w) {
            $weightLogs[] = [
                'id' => (int)$w['id'],
                'measured_at' => (string)$w['measured_at'],
                'weight_kg' => (float)$w['weight_kg'],
                'source' => (string)($w['source'] ?? 'coach'),
            ];
        }
    } catch (Throwable $e) {}

    $fullName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
    if ($fullName === '') $fullName = 'Sportovec';

    mobileJson([
        'success' => true,
        'athlete' => [
            'id' => (int)$athlete['id'],
            'first_name' => (string)($athlete['first_name'] ?? ''),
            'last_name' => (string)($athlete['last_name'] ?? ''),
            'full_name' => $fullName,
            'email' => (string)($athlete['email'] ?? ''),
            'phone' => (string)($athlete['phone_contact'] ?? ''),
            'birth_date' => $birthDate ?: '',
            'age' => $age,
            'gender' => (string)($athlete['gender'] ?? ''),
        ],
        'trainings' => $trainings,
        'weight_logs' => $weightLogs,
    ]);

} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => 'Chyba serveru: ' . $e->getMessage()], 500);
}
