<?php
// admin/coach_export.php – stáhne SQL zálohu dat trenéra
require_once __DIR__ . '/../includes/admin_auth.php';

requireAdminLogin();

$coachId = intParam($_GET, 'id');
$pdo     = getDB();

// Ověřit, že trenér existuje
$stmt = $pdo->prepare('SELECT * FROM coaches WHERE id = ?');
$stmt->execute([$coachId]);
$coach = $stmt->fetch();

if (!$coach) {
    flash('danger', 'Trenér nenalezen.');
    redirect(BASE_URL . '/admin/coaches.php');
}

// ── Sestavit SQL zálohu ───────────────────────────────────────────────────────

$safeUsername = preg_replace('/[^a-z0-9_]/i', '_', $coach['username']);
$filename     = 'zaloha_trenera_' . $safeUsername . '_' . date('Ymd_His') . '.sql';

$lines = [];
$lines[] = '-- ============================================================';
$lines[] = '-- Záloha dat trenéra: ' . $coach['username'];
$lines[] = '-- Exportováno: ' . date('d.m.Y H:i:s');
$lines[] = '-- ============================================================';
$lines[] = '';
$lines[] = 'SET NAMES utf8mb4;';
$lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$lines[] = '';

/**
 * Obalí hodnotu do SQL literálu (NULL nebo escaped string).
 */
function sqlVal(PDO $pdo, mixed $v): string {
    if ($v === null) return 'NULL';
    return $pdo->quote((string)$v);
}

/**
 * Generuje INSERT řádky pro výsledek dotazu.
 */
function exportRows(PDO $pdo, string $table, array $rows, array &$lines): void {
    if (empty($rows)) {
        $lines[] = '-- Tabulka ' . $table . ' neobsahuje žádná data.';
        $lines[] = '';
        return;
    }
    $lines[] = '-- Tabulka: ' . $table;
    foreach ($rows as $row) {
        $cols = implode(', ', array_map(fn($k) => '`' . $k . '`', array_keys($row)));
        $vals = implode(', ', array_map(fn($v) => sqlVal($pdo, $v), array_values($row)));
        $lines[] = "INSERT INTO `{$table}` ({$cols}) VALUES ({$vals});";
    }
    $lines[] = '';
}

// 1. Trenér
$lines[] = '-- ── Trenér ──────────────────────────────────────────────────';
exportRows($pdo, 'coaches', [$coach], $lines);

// 2. Sportovci
$athletes = $pdo->prepare('SELECT * FROM athletes WHERE coach_id = ?');
$athletes->execute([$coachId]);
$athleteRows = $athletes->fetchAll();
$lines[] = '-- ── Sportovci ───────────────────────────────────────────────';
exportRows($pdo, 'athletes', $athleteRows, $lines);

// 3. Cviky
$exercises = $pdo->prepare('SELECT * FROM exercises WHERE coach_id = ?');
$exercises->execute([$coachId]);
$exerciseRows = $exercises->fetchAll();
$lines[] = '-- ── Cviky ───────────────────────────────────────────────────';
exportRows($pdo, 'exercises', $exerciseRows, $lines);

// 4. Sady
$sets = $pdo->prepare('SELECT * FROM workout_sets WHERE coach_id = ?');
$sets->execute([$coachId]);
$setRows = $sets->fetchAll();
$lines[] = '-- ── Tréninkové sady ─────────────────────────────────────────';
exportRows($pdo, 'workout_sets', $setRows, $lines);

// 5. Cviky v sadách
if (!empty($setRows)) {
    $setIds       = array_column($setRows, 'id');
    $placeholders = implode(',', array_fill(0, count($setIds), '?'));
    $wse = $pdo->prepare("SELECT * FROM workout_set_exercises WHERE workout_set_id IN ($placeholders)");
    $wse->execute($setIds);
    $wseRows = $wse->fetchAll();
    $lines[] = '-- ── Cviky v sadách ──────────────────────────────────────────';
    exportRows($pdo, 'workout_set_exercises', $wseRows, $lines);
}

// 6. Tréninkové session
$athleteIds = array_column($athleteRows, 'id');
if (!empty($athleteIds)) {
    $placeholders = implode(',', array_fill(0, count($athleteIds), '?'));
    $sessions = $pdo->prepare("SELECT * FROM training_sessions WHERE athlete_id IN ($placeholders)");
    $sessions->execute($athleteIds);
    $sessionRows = $sessions->fetchAll();
    $lines[] = '-- ── Tréninkové záznamy ──────────────────────────────────────';
    exportRows($pdo, 'training_sessions', $sessionRows, $lines);

    // 7. Série
    if (!empty($sessionRows)) {
        $sessionIds   = array_column($sessionRows, 'id');
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $series = $pdo->prepare("SELECT * FROM session_series WHERE session_id IN ($placeholders)");
        $series->execute($sessionIds);
        $seriesRows = $series->fetchAll();
        $lines[] = '-- ── Série cviků ─────────────────────────────────────────────';
        exportRows($pdo, 'session_series', $seriesRows, $lines);
    }
}

$lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
$lines[] = '';
$lines[] = '-- Konec zálohy';

$sql = implode("\n", $lines);

// ── Odeslat jako soubor ke stažení ───────────────────────────────────────────
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($sql));
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $sql;
exit;
