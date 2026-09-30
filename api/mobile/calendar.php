<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();

$input = mobileInput();
$action = (string)($_REQUEST['action'] ?? $input['action'] ?? 'get');

if ($action === 'get') {
    $events = [];

    $expandItemPerDay = function (array $row, bool $isLock): array {
        $expanded = [];
        $startTs = strtotime((string)$row['starts_at']);
        $endTs = strtotime((string)$row['ends_at']);

        if (!$startTs || !$endTs) return $expanded;

        $curDate = new DateTime(date('Y-m-d', $startTs));
        $endDate = new DateTime(date('Y-m-d', $endTs));

        while ($curDate <= $endDate) {
            $dateStr = $curDate->format('Y-m-d');

            if ($dateStr === date('Y-m-d', $startTs)) {
                $sHour = (int)date('G', $startTs);
            } else {
                $sHour = 5;
            }

            if ($dateStr === date('Y-m-d', $endTs)) {
                $eHour = (int)date('G', $endTs);
                if ((int)date('i', $endTs) > 0) {
                    $eHour += 1;
                }
            } else {
                $eHour = 22;
            }

            if ($eHour <= $sHour) {
                $eHour = $sHour + 1;
            }

            $timeLabel = sprintf('%02d:00 - %02d:00', $sHour, $eHour);

            if ($isLock) {
                $expanded[] = [
                    'id' => 200000 + (int)$row['id'],
                    'athlete_id' => null,
                    'title' => !empty($row['note']) ? (string)$row['note'] : 'Uzamčený čas',
                    'athlete_name' => 'Uzamčeno',
                    'location' => '',
                    'starts_at' => (string)$row['starts_at'],
                    'ends_at' => (string)$row['ends_at'],
                    'date_label' => $dateStr,
                    'time_label' => $timeLabel,
                    'start_hour' => $sHour,
                    'end_hour' => $eHour,
                    'status' => 'Uzamčeno',
                    'approval_status' => 'approved',
                    'is_locked' => true,
                ];
            } else {
                $name1 = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                $name2 = trim((string)($row['second_first_name'] ?? '') . ' ' . (string)($row['second_last_name'] ?? ''));
                $athleteLabel = implode(' + ', array_filter([$name1, $name2]));
                if ($athleteLabel === '') $athleteLabel = 'Bez sportovce';

                $title = trim((string)($row['custom_title'] ?? ''));
                if ($title === '') $title = 'Trénink';

                $expanded[] = [
                    'id' => (int)$row['id'],
                    'athlete_id' => $row['athlete_id'] !== null ? (int)$row['athlete_id'] : null,
                    'title' => $title,
                    'athlete_name' => $athleteLabel,
                    'location' => trim((string)($row['location'] ?? '')),
                    'starts_at' => (string)$row['starts_at'],
                    'ends_at' => (string)$row['ends_at'],
                    'date_label' => $dateStr,
                    'time_label' => $timeLabel,
                    'start_hour' => $sHour,
                    'end_hour' => $eHour,
                    'status' => (string)($row['approval_status'] ?? 'approved') === 'approved' ? 'Potvrzeno' : 'Čeká na schválení',
                    'approval_status' => (string)($row['approval_status'] ?? 'approved'),
                    'is_locked' => false,
                    'color_key' => (string)($row['color_key'] ?? 'green'),
                    'second_athlete_id' => isset($row['second_athlete_id']) && $row['second_athlete_id'] !== null ? (int)$row['second_athlete_id'] : 0,
                    'series_id' => (string)($row['series_id'] ?? ''),
                ];
            }

            $curDate->modify('+1 day');
        }

        return $expanded;
    };

    try {
        $stmt = $pdo->prepare(
            'SELECT e.id, e.custom_title, e.location, e.starts_at, e.ends_at, e.approval_status,
                    e.color_key, e.series_id, e.athlete_id, e.second_athlete_id,
                    a.first_name, a.last_name,
                    a2.first_name AS second_first_name, a2.last_name AS second_last_name
             FROM coach_calendar_events e
             LEFT JOIN athletes a ON a.id = e.athlete_id
             LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
             WHERE e.coach_id = ?
             ORDER BY e.starts_at ASC'
        );
        $stmt->execute([$coach['id']]);

        foreach ($stmt->fetchAll() as $row) {
            foreach ($expandItemPerDay($row, false) as $item) {
                $events[] = $item;
            }
        }
    } catch (Throwable $e) {}

    $locks = [];
    try {
        $stmtLock = $pdo->prepare(
            'SELECT l.id, l.note, l.starts_at, l.ends_at
             FROM coach_calendar_locks l
             WHERE l.coach_id = ?
             ORDER BY l.starts_at ASC'
        );
        $stmtLock->execute([$coach['id']]);

        foreach ($stmtLock->fetchAll() as $row) {
            foreach ($expandItemPerDay($row, true) as $item) {
                $locks[] = $item;
            }
        }
    } catch (Throwable $e) {}

    mobileJson([
        'success' => true,
        'events' => array_merge($events, $locks),
    ]);
}

if ($action === 'create') {
    $athleteId = isset($input['athlete_id']) ? (int)$input['athlete_id'] : (isset($_REQUEST['athlete_id']) ? (int)$_REQUEST['athlete_id'] : null);
    $title = trim((string)($input['title'] ?? $_REQUEST['title'] ?? ''));
    $location = trim((string)($input['location'] ?? $_REQUEST['location'] ?? ''));
    $startsAt = trim((string)($input['starts_at'] ?? $_REQUEST['starts_at'] ?? ''));
    $endsAt = trim((string)($input['ends_at'] ?? $_REQUEST['ends_at'] ?? ''));

    if ($startsAt === '' || $endsAt === '') {
        mobileJson(['success' => false, 'error' => 'Vyberte začátek a konec události.'], 422);
    }

    try {
        $lockStmt = $pdo->prepare(
            'SELECT id FROM coach_calendar_locks
             WHERE coach_id = ?
               AND (starts_at < ? AND ends_at > ?)
             LIMIT 1'
        );
        $lockStmt->execute([$coach['id'], $endsAt, $startsAt]);
        if ($lockStmt->fetch()) {
            mobileJson(['success' => false, 'error' => 'Tento čas je uzamčen. V uzamčeném čase nelze vytvořit událost.'], 409);
        }
    } catch (Throwable $e) {}

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO coach_calendar_events (coach_id, athlete_id, custom_title, location, starts_at, ends_at, approval_status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, "approved", NOW())'
        );
        $stmt->execute([
            $coach['id'],
            $athleteId && $athleteId > 0 ? $athleteId : null,
            $title ?: null,
            $location ?: null,
            $startsAt,
            $endsAt
        ]);

        mobileJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Chyba vytvoření události: ' . $e->getMessage()], 500);
    }
}

if ($action === 'lock') {
    $startsAt = trim((string)($input['starts_at'] ?? $_REQUEST['starts_at'] ?? ''));
    $endsAt = trim((string)($input['ends_at'] ?? $_REQUEST['ends_at'] ?? ''));
    $note = trim((string)($input['note'] ?? $_REQUEST['note'] ?? ''));
    if ($note === '') {
        $note = 'Uzamčený čas';
    }
    if (function_exists('mb_substr')) {
        $note = mb_substr($note, 0, 255, 'UTF-8');
    }

    $start = DateTime::createFromFormat('Y-m-d H:i:s', $startsAt) ?: DateTime::createFromFormat('Y-m-d H:i', $startsAt);
    $end = DateTime::createFromFormat('Y-m-d H:i:s', $endsAt) ?: DateTime::createFromFormat('Y-m-d H:i', $endsAt);
    if (!$start || !$end) {
        mobileJson(['success' => false, 'error' => 'Neplatné datum nebo čas uzamčení.'], 422);
    }
    if ($end <= $start) {
        mobileJson(['success' => false, 'error' => 'Konec musí být později než začátek.'], 422);
    }

    $startSql = $start->format('Y-m-d H:i:s');
    $endSql = $end->format('Y-m-d H:i:s');

    try {
        $conflict = $pdo->prepare(
            'SELECT id FROM coach_calendar_events
             WHERE coach_id = ? AND starts_at < ? AND ends_at > ?
             LIMIT 1'
        );
        $conflict->execute([$coach['id'], $endSql, $startSql]);
        if ($conflict->fetch()) {
            mobileJson(['success' => false, 'error' => 'V zadaném intervalu máte naplánovanou událost.'], 409);
        }

        $overlap = $pdo->prepare(
            'SELECT id FROM coach_calendar_locks
             WHERE coach_id = ? AND starts_at < ? AND ends_at > ?
             LIMIT 1'
        );
        $overlap->execute([$coach['id'], $endSql, $startSql]);
        if ($overlap->fetch()) {
            mobileJson(['success' => false, 'error' => 'Tento čas už je uzamčený.'], 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO coach_calendar_locks (coach_id, note, starts_at, ends_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$coach['id'], $note, $startSql, $endSql]);
        mobileJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Chyba uzamčení: ' . $e->getMessage()], 500);
    }
}

if ($action === 'approve') {
    $eventId = (int)($input['event_id'] ?? $_REQUEST['event_id'] ?? 0);
    if ($eventId <= 0) {
        mobileJson(['success' => false, 'error' => 'Chybí ID události.'], 422);
    }

    $eventStmt = $pdo->prepare('SELECT * FROM coach_calendar_events WHERE id = ? AND coach_id = ? LIMIT 1');
    $eventStmt->execute([$eventId, $coach['id']]);
    $event = $eventStmt->fetch();
    if (!$event) {
        mobileJson(['success' => false, 'error' => 'Událost nenalezena.'], 404);
    }

    $newStarts = trim((string)($input['starts_at'] ?? ''));
    $newEnds = trim((string)($input['ends_at'] ?? ''));
    if ($newStarts === '') {
        $newStarts = (string)($event['starts_at'] ?? '');
    }
    if ($newEnds === '') {
        $newEnds = (string)($event['ends_at'] ?? '');
    }
    $newTitle = array_key_exists('title', $input) ? trim((string)$input['title']) : trim((string)($event['custom_title'] ?? ''));
    $newLocation = array_key_exists('location', $input) ? trim((string)$input['location']) : trim((string)($event['location'] ?? ''));
    $newAthlete = array_key_exists('athlete_id', $input) ? (int)$input['athlete_id'] : (int)($event['athlete_id'] ?? 0);

    $start = DateTime::createFromFormat('Y-m-d H:i:s', $newStarts) ?: DateTime::createFromFormat('Y-m-d H:i', $newStarts);
    $end = DateTime::createFromFormat('Y-m-d H:i:s', $newEnds) ?: DateTime::createFromFormat('Y-m-d H:i', $newEnds);
    if (!$start || !$end) {
        mobileJson(['success' => false, 'error' => 'Neplatný začátek nebo konec události.'], 422);
    }
    if ($end <= $start) {
        mobileJson(['success' => false, 'error' => 'Konec musí být později než začátek.'], 422);
    }
    $startSql = $start->format('Y-m-d H:i:s');
    $endSql = $end->format('Y-m-d H:i:s');

    if ($newAthlete > 0) {
        $athleteStmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
        $athleteStmt->execute([$newAthlete, $coach['id']]);
        if (!$athleteStmt->fetch()) {
            mobileJson(['success' => false, 'error' => 'Vybraný sportovec nepatří k tomuto trenérovi.'], 422);
        }
    }

    try {
        $lockStmt = $pdo->prepare(
            'SELECT id FROM coach_calendar_locks WHERE coach_id = ? AND starts_at < ? AND ends_at > ? LIMIT 1'
        );
        $lockStmt->execute([$coach['id'], $endSql, $startSql]);
        if ($lockStmt->fetch()) {
            mobileJson(['success' => false, 'error' => 'Navržený čas je uzamčený.'], 409);
        }
    } catch (Throwable $e) {
    }

    $seriesId = trim((string)($event['series_id'] ?? ''));
    $rescheduleSourceId = 0;
    if (preg_match('/^reschedule:(\d+)$/', $seriesId, $matches)) {
        $rescheduleSourceId = (int)$matches[1];
    }

    try {
        $pdo->beginTransaction();
        if ($rescheduleSourceId > 0) {
            $sourceStmt = $pdo->prepare('SELECT id FROM coach_calendar_events WHERE id = ? AND coach_id = ? LIMIT 1');
            $sourceStmt->execute([$rescheduleSourceId, $coach['id']]);
            if (!$sourceStmt->fetch()) {
                $pdo->rollBack();
                mobileJson(['success' => false, 'error' => 'Původní termín pro přesun nebyl nalezen.'], 404);
            }
            $update = $pdo->prepare(
                'UPDATE coach_calendar_events
                 SET athlete_id = ?, custom_title = ?, location = ?, starts_at = ?, ends_at = ?,
                     approval_status = "approved", requested_by_athlete_id = NULL
                 WHERE id = ? AND coach_id = ?'
            );
            $update->execute([
                $newAthlete > 0 ? $newAthlete : null,
                $newTitle !== '' ? $newTitle : null,
                $newLocation !== '' ? $newLocation : null,
                $startSql,
                $endSql,
                $rescheduleSourceId,
                $coach['id'],
            ]);
            $deleteRequest = $pdo->prepare(
                'DELETE FROM coach_calendar_events
                 WHERE coach_id = ? AND approval_status = "pending" AND series_id = ?'
            );
            $deleteRequest->execute([$coach['id'], $seriesId]);
        } else {
            $update = $pdo->prepare(
                'UPDATE coach_calendar_events
                 SET athlete_id = ?, custom_title = ?, location = ?, starts_at = ?, ends_at = ?,
                     approval_status = "approved"
                 WHERE id = ? AND coach_id = ?'
            );
            $update->execute([
                $newAthlete > 0 ? $newAthlete : null,
                $newTitle !== '' ? $newTitle : null,
                $newLocation !== '' ? $newLocation : null,
                $startSql,
                $endSql,
                $eventId,
                $coach['id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mobileJson(['success' => false, 'error' => 'Schválení se nepodařilo.'], 500);
    }

    $notifyAthleteId = $newAthlete > 0 ? $newAthlete : (int)($event['athlete_id'] ?? 0);
    if ($notifyAthleteId > 0 && function_exists('createAthleteNotification')) {
        try {
            $timeLabel = $start->format('d.m.Y H:i');
            $subject = $rescheduleSourceId > 0 ? 'Žádost o změnu byla schválena' : 'Trénink byl schválen';
            $body = $rescheduleSourceId > 0
                ? 'Trenér schválil změnu termínu. Nový termín: ' . $timeLabel . '.'
                : 'Trenér schválil váš termín ' . $timeLabel . '.';
            if ($newLocation !== '') {
                $body .= ' Místo: ' . $newLocation . '.';
            }
            createAthleteNotification($notifyAthleteId, $subject, $body);
        } catch (Throwable $e) {
        }
    }

    mobileJson(['success' => true, 'id' => $rescheduleSourceId > 0 ? $rescheduleSourceId : $eventId, 'approval_status' => 'approved']);
}

$unlockEventId = (int)($input['event_id'] ?? $_REQUEST['event_id'] ?? 0);
$unlockStartsAt = trim((string)($input['starts_at'] ?? $_REQUEST['starts_at'] ?? ''));
if ($action === 'unlock' || ($action === 'delete' && $unlockEventId >= 200000 && $unlockStartsAt !== '')) {
    $startsAt = trim((string)($input['starts_at'] ?? $_REQUEST['starts_at'] ?? ''));
    $endsAt = trim((string)($input['ends_at'] ?? $_REQUEST['ends_at'] ?? ''));
    $eventId = (int)($input['event_id'] ?? $_REQUEST['event_id'] ?? 0);

    if ($startsAt !== '' && $endsAt !== '') {
        try {
            $start = new DateTime($startsAt);
            $end = new DateTime($endsAt);
            $startSql = $start->format('Y-m-d H:i:s');
            $endSql = $end->format('Y-m-d H:i:s');

            $selectOverlaps = $pdo->prepare(
                'SELECT id, note, starts_at, ends_at
                 FROM coach_calendar_locks
                 WHERE coach_id = ?
                   AND starts_at < ?
                   AND ends_at > ?
                 ORDER BY starts_at ASC
                 FOR UPDATE'
            );

            $deleteLock = $pdo->prepare('DELETE FROM coach_calendar_locks WHERE id = ? AND coach_id = ?');
            $insertLock = $pdo->prepare(
                'INSERT INTO coach_calendar_locks (coach_id, note, starts_at, ends_at)
                 VALUES (?, ?, ?, ?)'
            );

            $pdo->beginTransaction();
            $selectOverlaps->execute([$coach['id'], $endSql, $startSql]);
            $overlaps = $selectOverlaps->fetchAll();

            foreach ($overlaps as $lockRow) {
                $lockStart = new DateTime($lockRow['starts_at']);
                $lockEnd = new DateTime($lockRow['ends_at']);

                $deleteLock->execute([(int)$lockRow['id'], $coach['id']]);

                if ($lockStart < $start) {
                    $insertLock->execute([
                        $coach['id'],
                        $lockRow['note'],
                        $lockStart->format('Y-m-d H:i:s'),
                        $start->format('Y-m-d H:i:s'),
                    ]);
                }

                if ($lockEnd > $end) {
                    $insertLock->execute([
                        $coach['id'],
                        $lockRow['note'],
                        $end->format('Y-m-d H:i:s'),
                        $lockEnd->format('Y-m-d H:i:s'),
                    ]);
                }
            }
            $pdo->commit();
            mobileJson(['success' => true, 'unlocked' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            mobileJson(['success' => false, 'error' => 'Chyba odemknutí: ' . $e->getMessage()], 500);
        }
    }
}

if ($action === 'delete' || $action === 'reject') {
    $eventId = (int)($input['event_id'] ?? $_POST['event_id'] ?? $_GET['event_id'] ?? $_REQUEST['event_id'] ?? 0);
    if ($eventId <= 0) mobileJson(['success' => false, 'error' => 'Chybí ID události.'], 422);

    try {
        if ($eventId >= 200000) {
            $lockId = $eventId - 200000;
            $stmt = $pdo->prepare('DELETE FROM coach_calendar_locks WHERE id = ? AND coach_id = ?');
            $stmt->execute([$lockId, $coach['id']]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND coach_id = ?');
            $stmt->execute([$eventId, $coach['id']]);
        }
        mobileJson(['success' => true]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Chyba mazání: ' . $e->getMessage()], 500);
    }
}
