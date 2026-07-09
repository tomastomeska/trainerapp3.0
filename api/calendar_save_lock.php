<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Neplatná metoda']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

if (!verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný CSRF token']);
    exit;
}

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

$lockId = (int)($input['lock_id'] ?? 0);
$startsAtRaw = trim((string)($input['starts_at'] ?? ''));
$endsAtRaw = trim((string)($input['ends_at'] ?? ''));
$note = trim((string)($input['note'] ?? ''));
$mode = trim((string)($input['mode'] ?? 'lock'));
$repeatMode = trim((string)($input['repeat_mode'] ?? 'none'));
$repeatUntilRaw = trim((string)($input['repeat_until'] ?? ''));

if (!in_array($mode, ['lock', 'unlock'], true)) {
    $mode = 'lock';
}

$allowedRepeatModes = ['none', 'weekly_until_date', 'weekly_end_of_next_month', 'weekly_end_of_year'];
if (!in_array($repeatMode, $allowedRepeatModes, true)) {
    $repeatMode = 'none';
}

function parseRepeatUntil(DateTime $start, string $repeatMode, string $repeatUntilRaw): ?DateTime
{
    if ($repeatMode === 'none') {
        return null;
    }

    if ($repeatMode === 'weekly_until_date') {
        $until = DateTime::createFromFormat('Y-m-d', $repeatUntilRaw);
        if (!$until) {
            return null;
        }
        $until->setTime(23, 59, 59);
        return $until;
    }

    if ($repeatMode === 'weekly_end_of_next_month') {
        $until = clone $start;
        $until->modify('last day of next month')->setTime(23, 59, 59);
        return $until;
    }

    if ($repeatMode === 'weekly_end_of_year') {
        $until = clone $start;
        $until->setDate((int)$start->format('Y'), 12, 31)->setTime(23, 59, 59);
        return $until;
    }

    return null;
}

$start = DateTime::createFromFormat('Y-m-d\TH:i', $startsAtRaw);
$end = DateTime::createFromFormat('Y-m-d\TH:i', $endsAtRaw);
if (!$start || !$end) {
    echo json_encode(['success' => false, 'error' => 'Neplatné datum/čas uzamčení']);
    exit;
}

if ($end <= $start) {
    echo json_encode(['success' => false, 'error' => 'Konec musí být později než začátek']);
    exit;
}

$startSql = $start->format('Y-m-d H:i:s');
$endSql = $end->format('Y-m-d H:i:s');

if ($mode === 'unlock') {
    $repeatMode = 'none';
}

$repeatUntil = parseRepeatUntil($start, $repeatMode, $repeatUntilRaw);
if ($repeatMode !== 'none' && !$repeatUntil) {
    echo json_encode(['success' => false, 'error' => 'Neplatné datum opakování']);
    exit;
}

$conflictStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_events
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     LIMIT 1'
);

$lockOverlapStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_locks
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?'
);

$lockOverlapStmtWithExclude = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_locks
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
       AND id <> ?
     LIMIT 1'
);

if ($note !== '') {
    $note = mb_substr($note, 0, 255, 'UTF-8');
} else {
    $note = null;
}

if ($mode === 'unlock') {
    $selectOverlaps = $pdo->prepare(
        'SELECT id, note, starts_at, ends_at
         FROM coach_calendar_locks
         WHERE coach_id = ?
           AND starts_at < ?
           AND ends_at > ?
         ORDER BY starts_at ASC, id ASC
         FOR UPDATE'
    );

    $deleteLock = $pdo->prepare('DELETE FROM coach_calendar_locks WHERE id = ? AND coach_id = ?');
    $insertLock = $pdo->prepare(
        'INSERT INTO coach_calendar_locks (coach_id, note, starts_at, ends_at)
         VALUES (?, ?, ?, ?)'
    );

    try {
        $pdo->beginTransaction();

        $selectOverlaps->execute([$coachId, $endSql, $startSql]);
        $overlaps = $selectOverlaps->fetchAll();

        if (!$overlaps) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Ve vybraném intervalu není co odemknout']);
            exit;
        }

        $unlockedCount = 0;
        foreach ($overlaps as $lockRow) {
            $lockStart = new DateTime($lockRow['starts_at']);
            $lockEnd = new DateTime($lockRow['ends_at']);

            $deleteLock->execute([(int)$lockRow['id'], $coachId]);
            $unlockedCount++;

            if ($lockStart < $start) {
                $insertLock->execute([
                    $coachId,
                    $lockRow['note'],
                    $lockStart->format('Y-m-d H:i:s'),
                    $start->format('Y-m-d H:i:s'),
                ]);
            }

            if ($lockEnd > $end) {
                $insertLock->execute([
                    $coachId,
                    $lockRow['note'],
                    $end->format('Y-m-d H:i:s'),
                    $lockEnd->format('Y-m-d H:i:s'),
                ]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'mode' => 'unlocked', 'affected_locks' => $unlockedCount]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if ($lockId > 0) {
    $ownerStmt = $pdo->prepare('SELECT id FROM coach_calendar_locks WHERE id = ? AND coach_id = ?');
    $ownerStmt->execute([$lockId, $coachId]);
    if (!$ownerStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Uzamčení nenalezeno']);
        exit;
    }

    $conflictStmt->execute([$coachId, $endSql, $startSql]);
    if ($conflictStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'V zadaném intervalu máte naplánovaný trénink']);
        exit;
    }

    $lockOverlapStmtWithExclude->execute([$coachId, $endSql, $startSql, $lockId]);
    if ($lockOverlapStmtWithExclude->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Uzamčený interval se překrývá s existujícím uzamčením']);
        exit;
    }

    $upd = $pdo->prepare(
        'UPDATE coach_calendar_locks
         SET note = ?, starts_at = ?, ends_at = ?
         WHERE id = ? AND coach_id = ?'
    );
    $upd->execute([$note, $startSql, $endSql, $lockId, $coachId]);

    echo json_encode(['success' => true, 'id' => $lockId, 'mode' => 'updated']);
    exit;
}

$occurrences = [];
$cursorStart = clone $start;
$durationSeconds = $end->getTimestamp() - $start->getTimestamp();
$maxOccurrences = 260;

while (true) {
    $occurrenceEnd = (clone $cursorStart)->modify('+' . $durationSeconds . ' seconds');
    $occurrences[] = [clone $cursorStart, $occurrenceEnd];

    if ($repeatMode === 'none') {
        break;
    }

    if (count($occurrences) >= $maxOccurrences) {
        break;
    }

    $cursorStart->modify('+7 days');
    if ($repeatUntil instanceof DateTime && $cursorStart > $repeatUntil) {
        break;
    }
}

$insertLock = $pdo->prepare(
    'INSERT INTO coach_calendar_locks (coach_id, note, starts_at, ends_at)
     VALUES (?, ?, ?, ?)'
);

try {
    $pdo->beginTransaction();

    foreach ($occurrences as $occurrencePair) {
        $occStart = $occurrencePair[0];
        $occEnd = $occurrencePair[1];
        $occStartSql = $occStart->format('Y-m-d H:i:s');
        $occEndSql = $occEnd->format('Y-m-d H:i:s');

        $conflictStmt->execute([$coachId, $occEndSql, $occStartSql]);
        if ($conflictStmt->fetch()) {
            throw new RuntimeException('V zadaném intervalu máte naplánovaný trénink: ' . $occStart->format('d.m.Y H:i'));
        }

        $lockOverlapStmt->execute([$coachId, $occEndSql, $occStartSql]);
        if ($lockOverlapStmt->fetch()) {
            throw new RuntimeException('Uzamčení se překrývá s existujícím intervalem: ' . $occStart->format('d.m.Y H:i'));
        }

        $insertLock->execute([$coachId, $note, $occStartSql, $occEndSql]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

echo json_encode(['success' => true, 'mode' => $repeatMode === 'none' ? 'created' : 'created_series']);
