<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$athleteId = (int)($_GET['id'] ?? 0);
if ($athleteId <= 0) mobileJson(['success' => false, 'error' => 'Chybí ID sportovce.'], 422);

$pdo = getDB();

try {
    $stmt = $pdo->prepare('SELECT * FROM athletes WHERE id = ? LIMIT 1');
    $stmt->execute([$athleteId]);
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

    // 1. Tréninky z training_sessions
    try {
        $trainingStmt = $pdo->prepare(
            'SELECT ts.id,
                    COALESCE(ts.completed_at, ts.started_at) AS started_at,
                    ts.completed_at, ts.location, ts.notes,
                    COALESCE(ws.name, "Trénink") AS set_name,
                    (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
             FROM training_sessions ts
             LEFT JOIN workout_sets ws ON ws.id = ts.workout_set_id
             WHERE ts.athlete_id = ?
               AND ts.deleted_by_coach_at IS NULL
             ORDER BY COALESCE(ts.completed_at, ts.started_at) DESC, ts.id DESC
             LIMIT 50'
        );
        $trainingStmt->execute([$athleteId]);

        foreach ($trainingStmt->fetchAll() as $row) {
            $startedAt = (string)($row['started_at'] ?? '');
            $setName = (string)($row['set_name'] ?? 'Trénink');
            $totalSeries = (int)($row['total_series'] ?? 0);
            $seriesText = $totalSeries > 0 ? " ($totalSeries sérií)" : "";

            $trainings[] = [
                'id' => (int)$row['id'],
                'started_at' => $startedAt !== '' ? $startedAt : date('Y-m-d H:i'),
                'set_name' => $setName . $seriesText,
                'location' => (string)($row['location'] ?? ''),
                'notes' => (string)($row['notes'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}

    // 2. Kalendářové tréninky a události z coach_calendar_events
    try {
        $calStmt = $pdo->prepare(
            'SELECT id, custom_title, location, starts_at, approval_status
             FROM coach_calendar_events
             WHERE (athlete_id = ? OR second_athlete_id = ?)
             ORDER BY starts_at DESC
             LIMIT 50'
        );
        $calStmt->execute([$athleteId, $athleteId]);

        foreach ($calStmt->fetchAll() as $row) {
            $startsAt = (string)($row['starts_at'] ?? '');
            $title = trim((string)($row['custom_title'] ?? ''));
            if ($title === '') $title = 'Trénink';

            $trainings[] = [
                'id' => 100000 + (int)$row['id'],
                'started_at' => $startsAt !== '' ? $startsAt : date('Y-m-d H:i'),
                'set_name' => $title,
                'location' => (string)($row['location'] ?? ''),
                'notes' => '',
            ];
        }
    } catch (Throwable $e) {}

    // Sort combined trainings by started_at DESC
    usort($trainings, function($a, $b) {
        return strtotime($b['started_at']) <=> strtotime($a['started_at']);
    });
    $trainings = array_slice($trainings, 0, 50);

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
            'phone' => (string)($athlete['phone_contact'] ?? $athlete['phone'] ?? ''),
            'birth_date' => $birthDate ?: '',
            'age' => $age,
            'photo' => mobilePublicPhoto((string)($athlete['photo'] ?? $athlete['avatar'] ?? ''), 'athletes'),
        ],
        'trainings' => $trainings,
        'weight_logs' => $weightLogs,
    ]);

} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => 'Chyba serveru: ' . $e->getMessage()], 500);
}
