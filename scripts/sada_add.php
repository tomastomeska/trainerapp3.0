<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Neplatný požadavek.');
    redirect(BASE_URL . '/sady.php');
}

$coachId = getCurrentCoachId();
$pdo     = getDB();
$name    = trim($_POST['name'] ?? '');
$exIds   = array_map('intval', $_POST['exercises'] ?? []);
// Odstranit prázdné (value="")
$exIds   = array_filter($exIds);

if ($name === '') {
    flash('danger', 'Zadejte název sady.');
    redirect(BASE_URL . '/sady.php');
}
if (empty($exIds)) {
    flash('danger', 'Přidejte alespoň jeden cvik.');
    redirect(BASE_URL . '/sady.php');
}

// Ověř, že všechny cviky patří trenérovi
$inClause = implode(',', array_fill(0, count($exIds), '?'));
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM exercises WHERE id IN ($inClause) AND (coach_id = ? OR is_global = 1)"
);
$stmt->execute([...$exIds, $coachId]);
if ((int)$stmt->fetchColumn() !== count($exIds)) {
    flash('danger', 'Neplatné cviky.');
    redirect(BASE_URL . '/sady.php');
}

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO workout_sets (coach_id, name) VALUES (?, ?)')
        ->execute([$coachId, $name]);
    $setId = (int)$pdo->lastInsertId();

    $stmtInsert = $pdo->prepare(
        'INSERT INTO workout_set_exercises (workout_set_id, exercise_id, exercise_order) VALUES (?, ?, ?)'
    );
    foreach (array_values($exIds) as $order => $exId) {
        $stmtInsert->execute([$setId, $exId, $order + 1]);
    }
    $pdo->commit();
    flash('success', "Sada \"$name\" byla vytvořena.");
} catch (Exception $e) {
    $pdo->rollBack();
    flash('danger', 'Chyba při vytváření sady.');
}

redirect(BASE_URL . '/sady.php');
