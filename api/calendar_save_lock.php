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
$lockSeriesAvailable = (bool)$pdo->query("SHOW COLUMNS FROM coach_calendar_locks LIKE 'series_id'")->fetch();

function generateUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

$lockId = (int)($input['lock_id'] ?? 0);
$startsAtRaw = trim((string)($input['starts_at'] ?? ''));
$endsAtRaw = trim((string)($input['ends_at'] ?? ''));
$note = trim((string)($input['note'] ?? ''));
$mode = trim((string)($input['mode'] ?? 'lock'));
$repeatMode = trim((string)($input['repeat_mode'] ?? 'none'));
$repeatUntilRaw = trim((string)($input['repeat_until'] ?? ''));
$updateScope = trim((string)($input['update_scope'] ?? 'single'));

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
    $ownerStmt = $pdo->prepare(
        'SELECT id, ' . ($lockSeriesAvailable ? 'series_id' : 'NULL AS series_id') . ', note, starts_at, ends_at
         FROM coach_calendar_locks WHERE id = ? AND coach_id = ?'
    );
    $ownerStmt->execute([$lockId, $coachId]);
    $ownerLock = $ownerStmt->fetch();
    if (!$ownerLock) {
        echo json_encode(['success' => false, 'error' => 'Uzamčení nenalezeno']);
        exit;
    }

    if ($updateScope === 'series' && ($lockSeriesAvailable ? !empty($ownerLock['series_id']) : true)) {
        if ($lockSeriesAvailable) {
            $seriesStmt = $pdo->prepare(
                'SELECT id, starts_at, ends_at
                 FROM coach_calendar_locks
                 WHERE coach_id = ? AND series_id = ?
                 ORDER BY starts_at ASC, id ASC'
            );
            $seriesStmt->execute([$coachId, $ownerLock['series_id']]);
            $seriesLocks = $seriesStmt->fetchAll();
        } else {
            $seriesStmt = $pdo->prepare(
                'SELECT id, note, starts_at, ends_at
                 FROM coach_calendar_locks WHERE coach_id = ?
                 ORDER BY starts_at ASC, id ASC'
            );
            $seriesStmt->execute([$coachId]);
            $seriesLocks = [];
            $ownerStart = new DateTime((string)$ownerLock['starts_at']);
            $ownerEnd = new DateTime((string)$ownerLock['ends_at']);
            $ownerDuration = $ownerEnd->getTimestamp() - $ownerStart->getTimestamp();
            foreach ($seriesStmt->fetchAll() as $candidateLock) {
                $candidateStart = new DateTime((string)$candidateLock['starts_at']);
                $candidateEnd = new DateTime((string)$candidateLock['ends_at']);
                if ((string)($candidateLock['note'] ?? '') === (string)($ownerLock['note'] ?? '')
                    && $candidateStart->format('N H:i:s') === $ownerStart->format('N H:i:s')
                    && ($candidateEnd->getTimestamp() - $candidateStart->getTimestamp()) === $ownerDuration) {
                    $seriesLocks[] = $candidateLock;
                }
            }
        }
        if (!$seriesLocks) {
            echo json_encode(['success' => false, 'error' => 'Série uzamčení nenalezena']);
            exit;
        }

        $newDurationSeconds = $end->getTimestamp() - $start->getTimestamp();
        $ownerIndex = 0;
        foreach ($seriesLocks as $seriesIndex => $seriesLock) {
            if ((int)$seriesLock['id'] === $lockId) {
                $ownerIndex = $seriesIndex;
                break;
            }
        }
        $seriesLocksToUpdate = array_slice($seriesLocks, $ownerIndex);
        $selectedOriginalStart = new DateTime((string)$seriesLocks[$ownerIndex]['starts_at']);
        $seriesIds = array_map(static fn (array $row): int => (int)$row['id'], $seriesLocks);
        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $eventConflict = $pdo->prepare(
            'SELECT id FROM coach_calendar_events
             WHERE coach_id = ? AND starts_at < ? AND ends_at > ? LIMIT 1'
        );
        $lockConflict = $pdo->prepare(
            "SELECT id FROM coach_calendar_locks
             WHERE coach_id = ? AND starts_at < ? AND ends_at > ? AND id NOT IN ($placeholders) LIMIT 1"
        );

        try {
            $pdo->beginTransaction();
            foreach ($seriesLocksToUpdate as $seriesLock) {
                $originalStart = new DateTime((string)$seriesLock['starts_at']);
                $weekOffset = (int)round(($originalStart->getTimestamp() - $selectedOriginalStart->getTimestamp()) / 604800);
                $occurrenceStart = clone $start;
                $occurrenceStart->modify('+' . ($weekOffset * 7) . ' days');
                $occurrenceEnd = (clone $occurrenceStart)->modify('+' . $newDurationSeconds . ' seconds');
                $occurrenceStartSql = $occurrenceStart->format('Y-m-d H:i:s');
                $occurrenceEndSql = $occurrenceEnd->format('Y-m-d H:i:s');

                $eventConflict->execute([$coachId, $occurrenceEndSql, $occurrenceStartSql]);
                if ($eventConflict->fetch()) {
                    throw new RuntimeException('V zadaném intervalu máte naplánovaný trénink: ' . $occurrenceStart->format('d.m.Y H:i'));
                }
                $lockConflict->execute(array_merge([$coachId, $occurrenceEndSql, $occurrenceStartSql], $seriesIds));
                if ($lockConflict->fetch()) {
                    throw new RuntimeException('Uzamčený interval se překrývá s jiným uzamčením.');
                }
            }

            $updateSeries = $pdo->prepare(
                'UPDATE coach_calendar_locks
                 SET note = ?, starts_at = ?, ends_at = ?
                 WHERE id = ? AND coach_id = ?'
            );
            foreach ($seriesLocksToUpdate as $seriesLock) {
                $originalStart = new DateTime((string)$seriesLock['starts_at']);
                $weekOffset = (int)round(($originalStart->getTimestamp() - $selectedOriginalStart->getTimestamp()) / 604800);
                $occurrenceStart = clone $start;
                $occurrenceStart->modify('+' . ($weekOffset * 7) . ' days');
                $occurrenceEnd = (clone $occurrenceStart)->modify('+' . $newDurationSeconds . ' seconds');
                $updateSeries->execute([
                    $note,
                    $occurrenceStart->format('Y-m-d H:i:s'),
                    $occurrenceEnd->format('Y-m-d H:i:s'),
                    (int)$seriesLock['id'],
                    $coachId,
                ]);
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'id' => $lockId, 'mode' => 'updated_series']);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
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

$insertLock = $lockSeriesAvailable
    ? $pdo->prepare(
        'INSERT INTO coach_calendar_locks (coach_id, series_id, note, starts_at, ends_at)
         VALUES (?, ?, ?, ?, ?)'
    )
    : $pdo->prepare(
        'INSERT INTO coach_calendar_locks (coach_id, note, starts_at, ends_at)
         VALUES (?, ?, ?, ?)'
    );
$seriesId = $repeatMode === 'none' ? null : generateUuidV4();

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

        $insertLock->execute($lockSeriesAvailable
            ? [$coachId, $seriesId, $note, $occStartSql, $occEndSql]
            : [$coachId, $note, $occStartSql, $occEndSql]
        );
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
