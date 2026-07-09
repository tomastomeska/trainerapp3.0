<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo = getDB();
$mealTypes = mealTypeOptions();
$mealDays = mealDayOptions();
$cheatDayMessage = 'V tento den si můžeš dopřát, ale všeho s mírou :)';

$isOwnedPlan = static function (PDO $pdo, int $planId, int $coachId): bool {
    if ($planId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT id FROM coach_meal_plans WHERE id = ? AND coach_id = ?');
    $stmt->execute([$planId, $coachId]);
    return (bool)$stmt->fetchColumn();
};

$getOwnedPlanName = static function (PDO $pdo, int $planId, int $coachId): ?string {
    $stmt = $pdo->prepare('SELECT name FROM coach_meal_plans WHERE id = ? AND coach_id = ?');
    $stmt->execute([$planId, $coachId]);
    $name = $stmt->fetchColumn();
    return $name !== false ? (string)$name : null;
};

$notifyAssignedAthletesAboutPlanUpdate = static function (
    PDO $pdo,
    int $planId,
    int $coachId,
    string $planName,
    string $changeSummary
): void {
    $athleteStmt = $pdo->prepare(
        'SELECT athlete_id
         FROM athlete_meal_plans
         WHERE meal_plan_id = ?
           AND coach_id = ?
           AND removed_at IS NULL'
    );
    $athleteStmt->execute([$planId, $coachId]);

    foreach ($athleteStmt->fetchAll() as $row) {
        $athleteId = (int)($row['athlete_id'] ?? 0);
        if ($athleteId <= 0) {
            continue;
        }

        createAthleteNotification(
            $athleteId,
            'Změna jídelníčku',
            'Trenér upravil jídelníček "' . $planName . '". ' . $changeSummary . ' Otevřete sekci Jídelníčky pro aktuální verzi.'
        );
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/meal_plans.php');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_plan') {
        $name = trim((string)($_POST['plan_name'] ?? ''));
        if ($name === '') {
            flash('danger', 'Název jídelníčku je povinný.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        $ins = $pdo->prepare('INSERT INTO coach_meal_plans (coach_id, name) VALUES (?, ?)');
        $ins->execute([$coachId, $name]);
        $newPlanId = (int)$pdo->lastInsertId();

        flash('success', 'Jídelníček byl vytvořen.');
        redirect(BASE_URL . '/meal_plans.php?plan_id=' . $newPlanId);
    }

    if ($action === 'rename_plan') {
        $planId = intParam($_POST, 'plan_id');
        $name = trim((string)($_POST['plan_name'] ?? ''));

        if (!$isOwnedPlan($pdo, $planId, $coachId)) {
            flash('danger', 'Jídelníček nebyl nalezen.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        if ($name === '') {
            flash('danger', 'Název jídelníčku je povinný.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
        }

        $upd = $pdo->prepare('UPDATE coach_meal_plans SET name = ? WHERE id = ? AND coach_id = ?');
        $upd->execute([$name, $planId, $coachId]);

        flash('success', 'Název jídelníčku byl upraven.');
        redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
    }

    if ($action === 'clone_plan') {
        $sourcePlanId = intParam($_POST, 'plan_id');
        $cloneName = trim((string)($_POST['clone_name'] ?? ''));

        if (!$isOwnedPlan($pdo, $sourcePlanId, $coachId)) {
            flash('danger', 'Jídelníček pro kopii nebyl nalezen.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        $sourcePlanName = $getOwnedPlanName($pdo, $sourcePlanId, $coachId) ?: 'Jídelníček';
        if ($cloneName === '') {
            $cloneName = $sourcePlanName . ' (kopie)';
        }

        $pdo->beginTransaction();
        try {
            $insPlan = $pdo->prepare('INSERT INTO coach_meal_plans (coach_id, name) VALUES (?, ?)');
            $insPlan->execute([$coachId, $cloneName]);
            $newPlanId = (int)$pdo->lastInsertId();

            $copyItems = $pdo->prepare(
                'INSERT INTO coach_meal_plan_items (meal_plan_id, day_of_week, meal_type, meal_id, meal_name_snapshot, grams, note, position)
                 SELECT ?, day_of_week, meal_type, meal_id, meal_name_snapshot, grams, note, position
                 FROM coach_meal_plan_items
                 WHERE meal_plan_id = ?'
            );
            $copyItems->execute([$newPlanId, $sourcePlanId]);

            $pdo->commit();
            flash('success', 'Jídelníček byl zkopírován.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $newPlanId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'Kopírování jídelníčku selhalo.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $sourcePlanId);
        }
    }

    if ($action === 'delete_plan') {
        $planId = intParam($_POST, 'plan_id');

        if (!$isOwnedPlan($pdo, $planId, $coachId)) {
            flash('danger', 'Jídelníček nebyl nalezen.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        $del = $pdo->prepare('DELETE FROM coach_meal_plans WHERE id = ? AND coach_id = ?');
        $del->execute([$planId, $coachId]);

        flash('success', 'Jídelníček byl smazán.');
        redirect(BASE_URL . '/meal_plans.php');
    }

    if ($action === 'add_item') {
        $planId = intParam($_POST, 'plan_id');
        $dayOfWeek = (string)($_POST['day_of_week'] ?? '');
        $mealType = (string)($_POST['meal_type'] ?? '');
        $mealId = intParam($_POST, 'meal_id');
        $gramsRaw = trim((string)($_POST['item_grams'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $itemGrams = null;

        if (!$isOwnedPlan($pdo, $planId, $coachId)) {
            flash('danger', 'Jídelníček nebyl nalezen.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        if (!isset($mealDays[$dayOfWeek])) {
            flash('danger', 'Vyberte platný den.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
        }

        if (!isset($mealTypes[$mealType])) {
            flash('danger', 'Vyberte platný typ jídla.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
        }

        if ($gramsRaw === '' || !ctype_digit($gramsRaw)) {
            flash('danger', 'Množství jídla musí být celé číslo v gramech.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
        }
        $itemGrams = (int)$gramsRaw;
        if ($itemGrams <= 0 || $itemGrams > 5000) {
            flash('danger', 'Množství jídla musí být v rozsahu 1 až 5000 g.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
        }

        $mealStmt = $pdo->prepare(
            'SELECT id, name, meal_type
             FROM coach_meals
             WHERE id = ? AND coach_id = ?'
        );
        $mealStmt->execute([$mealId, $coachId]);
        $meal = $mealStmt->fetch();

        if (!$meal) {
            flash('danger', 'Vybrané jídlo v databázi neexistuje.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
        }

        // Pokud byl den označen jako Cheat Day, při přidání běžné položky značku dne odstraníme.
        $clearCheatDayStmt = $pdo->prepare(
            'DELETE FROM coach_meal_plan_items
             WHERE meal_plan_id = ?
               AND day_of_week = ?
               AND meal_type = "cheat_day"
               AND meal_id IS NULL'
        );
        $clearCheatDayStmt->execute([$planId, $dayOfWeek]);

        $maxPosStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(position), 0)
             FROM coach_meal_plan_items
             WHERE meal_plan_id = ? AND day_of_week = ?'
        );
        $maxPosStmt->execute([$planId, $dayOfWeek]);
        $position = (int)$maxPosStmt->fetchColumn() + 1;

        $ins = $pdo->prepare(
            'INSERT INTO coach_meal_plan_items (meal_plan_id, day_of_week, meal_type, meal_id, meal_name_snapshot, grams, note, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $planId,
            $dayOfWeek,
            $mealType,
            $mealId,
            (string)$meal['name'],
            $itemGrams,
            $note !== '' ? $note : null,
            $position,
        ]);

        $planNameForNotification = $getOwnedPlanName($pdo, $planId, $coachId) ?: 'Jídelníček';
        $notifyAssignedAthletesAboutPlanUpdate(
            $pdo,
            $planId,
            $coachId,
            $planNameForNotification,
            'Do složení jídelníčku byla přidána nová položka.'
        );

        flash('success', 'Položka byla přidána do jídelníčku.');
        redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
    }

    if ($action === 'set_cheat_day') {
        $planId = intParam($_POST, 'plan_id');
        $dayOfWeek = (string)($_POST['day_of_week'] ?? '');

        if (!$isOwnedPlan($pdo, $planId, $coachId)) {
            flash('danger', 'Jídelníček nebyl nalezen.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        if (!isset($mealDays[$dayOfWeek])) {
            flash('danger', 'Vyberte platný den pro Cheat Day.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'DELETE FROM coach_meal_plan_items
                 WHERE meal_plan_id = ? AND day_of_week = ?'
            )->execute([$planId, $dayOfWeek]);

            $pdo->prepare(
                'INSERT INTO coach_meal_plan_items (meal_plan_id, day_of_week, meal_type, meal_id, meal_name_snapshot, grams, note, position)
                 VALUES (?, ?, "cheat_day", NULL, ?, NULL, NULL, 1)'
            )->execute([$planId, $dayOfWeek, $cheatDayMessage]);

            $pdo->commit();

            $planNameForNotification = $getOwnedPlanName($pdo, $planId, $coachId) ?: 'Jídelníček';
            $notifyAssignedAthletesAboutPlanUpdate(
                $pdo,
                $planId,
                $coachId,
                $planNameForNotification,
                'Den ' . mealDayLabel($dayOfWeek) . ' byl označen jako Cheat Day.'
            );

            flash('success', 'Den ' . mealDayLabel($dayOfWeek) . ' byl nastaven jako Cheat Day.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'Nastavení Cheat Day se nepodařilo uložit.');
        }

        redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId . '#day-' . $dayOfWeek);
    }

    if ($action === 'delete_item') {
        $planId = intParam($_POST, 'plan_id');
        $itemId = intParam($_POST, 'item_id');

        if (!$isOwnedPlan($pdo, $planId, $coachId)) {
            flash('danger', 'Jídelníček nebyl nalezen.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        $del = $pdo->prepare(
            'DELETE i
             FROM coach_meal_plan_items i
             JOIN coach_meal_plans p ON p.id = i.meal_plan_id
             WHERE i.id = ? AND i.meal_plan_id = ? AND p.coach_id = ?'
        );
        $del->execute([$itemId, $planId, $coachId]);

        $planNameForNotification = $getOwnedPlanName($pdo, $planId, $coachId) ?: 'Jídelníček';
        $notifyAssignedAthletesAboutPlanUpdate(
            $pdo,
            $planId,
            $coachId,
            $planNameForNotification,
            'Ze složení jídelníčku byla odebrána položka.'
        );

        flash('success', 'Položka byla z jídelníčku odstraněna.');
        redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
    }

    if ($action === 'move_item') {
        $planId = intParam($_POST, 'plan_id');
        $itemId = intParam($_POST, 'item_id');
        $direction = (string)($_POST['direction'] ?? '');
        $returnAnchorRaw = trim((string)($_POST['return_anchor'] ?? ''));
        $returnAnchor = preg_replace('/[^a-z0-9_-]/i', '', $returnAnchorRaw) ?: '';
        $redirectUrl = BASE_URL . '/meal_plans.php?plan_id=' . $planId . ($returnAnchor !== '' ? '#' . $returnAnchor : '');

        if (!$isOwnedPlan($pdo, $planId, $coachId)) {
            flash('danger', 'Jídelníček nebyl nalezen.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        if ($direction !== 'up' && $direction !== 'down') {
            flash('danger', 'Neplatný směr řazení položky.');
            redirect($redirectUrl);
        }

        $currentStmt = $pdo->prepare(
            'SELECT i.id, i.day_of_week, i.position
             FROM coach_meal_plan_items i
             JOIN coach_meal_plans p ON p.id = i.meal_plan_id
             WHERE i.id = ? AND i.meal_plan_id = ? AND p.coach_id = ?
             LIMIT 1'
        );
        $currentStmt->execute([$itemId, $planId, $coachId]);
        $current = $currentStmt->fetch();

        if (!$current) {
            flash('danger', 'Položka jídelníčku nebyla nalezena.');
            redirect($redirectUrl);
        }

        if ($direction === 'up') {
            $neighborStmt = $pdo->prepare(
                'SELECT id, position
                 FROM coach_meal_plan_items
                 WHERE meal_plan_id = ? AND day_of_week = ? AND position < ?
                 ORDER BY position DESC, id DESC
                 LIMIT 1'
            );
        } else {
            $neighborStmt = $pdo->prepare(
                'SELECT id, position
                 FROM coach_meal_plan_items
                 WHERE meal_plan_id = ? AND day_of_week = ? AND position > ?
                 ORDER BY position ASC, id ASC
                 LIMIT 1'
            );
        }

        $neighborStmt->execute([$planId, (string)$current['day_of_week'], (int)$current['position']]);
        $neighbor = $neighborStmt->fetch();

        if (!$neighbor) {
            flash('info', $direction === 'up' ? 'Položka už je první v daném dni.' : 'Položka už je poslední v daném dni.');
            redirect($redirectUrl);
        }

        $swapStmt = $pdo->prepare('UPDATE coach_meal_plan_items SET position = ? WHERE id = ? AND meal_plan_id = ?');
        $pdo->beginTransaction();
        try {
            $swapStmt->execute([(int)$neighbor['position'], (int)$current['id'], $planId]);
            $swapStmt->execute([(int)$current['position'], (int)$neighbor['id'], $planId]);
            $pdo->commit();

            $planNameForNotification = $getOwnedPlanName($pdo, $planId, $coachId) ?: 'Jídelníček';
            $notifyAssignedAthletesAboutPlanUpdate(
                $pdo,
                $planId,
                $coachId,
                $planNameForNotification,
                'Bylo upraveno pořadí položek jídelníčku.'
            );

            flash('success', 'Pořadí položky bylo upraveno.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'Pořadí položky se nepodařilo upravit.');
        }

        redirect($redirectUrl);
    }

    if ($action === 'assign_plan') {
        $planId = intParam($_POST, 'plan_id');
        $target = (string)($_POST['target'] ?? '');

        if (!$isOwnedPlan($pdo, $planId, $coachId)) {
            flash('danger', 'Jídelníček nebyl nalezen.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        $planNameStmt = $pdo->prepare('SELECT name FROM coach_meal_plans WHERE id = ? AND coach_id = ?');
        $planNameStmt->execute([$planId, $coachId]);
        $planName = (string)($planNameStmt->fetchColumn() ?: 'Jídelníček');

        $athleteIds = [];
        if ($target === 'all') {
            $athletesStmt = $pdo->prepare('SELECT id FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name');
            $athletesStmt->execute([$coachId]);
            $athleteIds = array_map(static fn(array $row): int => (int)$row['id'], $athletesStmt->fetchAll());
        } elseif ($target === 'single') {
            $athleteId = intParam($_POST, 'athlete_id');
            $athleteStmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ?');
            $athleteStmt->execute([$athleteId, $coachId]);
            if ($athleteStmt->fetch()) {
                $athleteIds = [$athleteId];
            }
        }

        if (empty($athleteIds)) {
            flash('danger', 'Nebyl vybrán žádný sportovec.');
            redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
        }

        $checkActiveStmt = $pdo->prepare(
            'SELECT id
             FROM athlete_meal_plans
             WHERE athlete_id = ? AND meal_plan_id = ? AND removed_at IS NULL
             LIMIT 1'
        );

        $insertAssignStmt = $pdo->prepare(
            'INSERT INTO athlete_meal_plans (coach_id, athlete_id, meal_plan_id, assigned_at)
             VALUES (?, ?, ?, NOW())'
        );

        $assignedCount = 0;
        foreach ($athleteIds as $athleteId) {
            $checkActiveStmt->execute([$athleteId, $planId]);
            if ($checkActiveStmt->fetch()) {
                continue;
            }

            $insertAssignStmt->execute([$coachId, $athleteId, $planId]);
            createAthleteNotification(
                $athleteId,
                'Nový jídelníček',
                'Trenér vám přiřadil jídelníček "' . $planName . '". Najdete ho v sekci Jídelníčky.'
            );
            $assignedCount++;
        }

        if ($assignedCount > 0) {
            flash('success', 'Jídelníček byl přiřazen (' . $assignedCount . 'x).');
        } else {
            flash('info', 'Vybraným sportovcům je tento jídelníček už přiřazen.');
        }

        redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
    }

    if ($action === 'remove_assignment') {
        $planId = intParam($_POST, 'plan_id');
        $assignmentId = intParam($_POST, 'assignment_id');

        if (!$isOwnedPlan($pdo, $planId, $coachId)) {
            flash('danger', 'Jídelníček nebyl nalezen.');
            redirect(BASE_URL . '/meal_plans.php');
        }

        $upd = $pdo->prepare(
            'UPDATE athlete_meal_plans a
             JOIN coach_meal_plans p ON p.id = a.meal_plan_id
             SET a.removed_at = NOW(),
                 a.removed_by_coach_id = ?
             WHERE a.id = ?
               AND a.meal_plan_id = ?
               AND p.coach_id = ?
               AND a.removed_at IS NULL'
        );
        $upd->execute([$coachId, $assignmentId, $planId, $coachId]);

        flash('success', 'Jídelníček byl sportovci odebrán.');
        redirect(BASE_URL . '/meal_plans.php?plan_id=' . $planId);
    }
}

$plansStmt = $pdo->prepare(
    'SELECT p.id,
            p.name,
            p.created_at,
            (SELECT COUNT(*) FROM coach_meal_plan_items i WHERE i.meal_plan_id = p.id) AS item_count,
            (SELECT COUNT(*) FROM athlete_meal_plans a WHERE a.meal_plan_id = p.id AND a.removed_at IS NULL) AS active_assignments
     FROM coach_meal_plans p
     WHERE p.coach_id = ?
     ORDER BY p.created_at DESC, p.id DESC'
);
$plansStmt->execute([$coachId]);
$plans = $plansStmt->fetchAll();

$selectedPlanId = intParam($_GET, 'plan_id');

$selectedPlan = null;
foreach ($plans as $planRow) {
    if ((int)$planRow['id'] === $selectedPlanId) {
        $selectedPlan = $planRow;
        break;
    }
}
if ($selectedPlan === null) {
    $selectedPlanId = 0;
}

$mealsStmt = $pdo->prepare(
    'SELECT id,
            name,
            meal_type,
            grams,
            fat_per_100g,
            sugars_per_100g,
            protein_per_100g,
            fiber_per_100g,
            salt_per_100g,
            photo
     FROM coach_meals
     WHERE coach_id = ?
     ORDER BY CASE WHEN meal_type IS NULL THEN 1 ELSE 0 END,
              FIELD(meal_type, "breakfast", "snack", "lunch", "dinner", "second_dinner", "post_workout", "cheat_day"),
              name ASC'
);
$mealsStmt->execute([$coachId]);
$meals = $mealsStmt->fetchAll();

$athletesStmt = $pdo->prepare(
    'SELECT id, first_name, last_name
     FROM athletes
     WHERE coach_id = ?
    ORDER BY first_name ASC, last_name ASC'
);
$athletesStmt->execute([$coachId]);
$athletes = $athletesStmt->fetchAll();

$planItems = [];
$itemsByDay = [];
$activeAssignments = [];

if ($selectedPlanId > 0) {
    $itemsStmt = $pdo->prepare(
        'SELECT i.id,
                i.day_of_week,
                i.meal_type,
                i.meal_id,
                i.grams,
                i.note,
                i.position,
                i.meal_name_snapshot,
                m.name AS meal_name,
                m.photo,
                m.fat_per_100g,
                m.sugars_per_100g,
                m.protein_per_100g,
                m.fiber_per_100g,
                m.salt_per_100g
         FROM coach_meal_plan_items i
         LEFT JOIN coach_meals m ON m.id = i.meal_id
         WHERE i.meal_plan_id = ?
         ORDER BY FIELD(i.day_of_week, "monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"),
                  i.position ASC,
                  i.id ASC'
    );
    $itemsStmt->execute([$selectedPlanId]);
    $planItems = $itemsStmt->fetchAll();

    foreach ($planItems as $item) {
        $dayKey = (string)$item['day_of_week'];
        if (!isset($itemsByDay[$dayKey])) {
            $itemsByDay[$dayKey] = [];
        }
        $itemsByDay[$dayKey][] = $item;
    }

    $assignmentsStmt = $pdo->prepare(
        'SELECT a.id,
                a.assigned_at,
                a.athlete_id,
                at.first_name,
                at.last_name
         FROM athlete_meal_plans a
         JOIN athletes at ON at.id = a.athlete_id
         WHERE a.meal_plan_id = ?
           AND a.coach_id = ?
           AND a.removed_at IS NULL
         ORDER BY at.first_name ASC, at.last_name ASC'
    );
    $assignmentsStmt->execute([$selectedPlanId, $coachId]);
    $activeAssignments = $assignmentsStmt->fetchAll();
}

renderHeader('Jídelníčky', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-layer-group me-2 text-warning"></i>Jídelníčky</h2>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($selectedPlanId > 0): ?>
        <a href="<?= BASE_URL ?>/meal_plans_print.php?plan_id=<?= (int)$selectedPlanId ?>" target="_blank" class="btn btn-outline-dark btn-sm fw-semibold">
            <i class="fas fa-print me-1"></i>Tisk vybraného
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/meal_plans_print.php" target="_blank" class="btn btn-outline-dark btn-sm fw-semibold">
            <i class="fas fa-print me-1"></i>Tisk všech
        </a>
        <a href="<?= BASE_URL ?>/meals.php" class="btn btn-outline-secondary btn-sm fw-semibold">
            <i class="fas fa-utensils me-1"></i>Databáze jídel
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-3">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white fw-semibold">Nový jídelníček</div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_plan">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Název</label>
                        <input type="text" name="plan_name" class="form-control" maxlength="200" required placeholder="Např. Týden 1">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-warning w-100 fw-bold">Vytvořit</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Moje šablony</span>
                <span class="badge bg-warning text-dark"><?= count($plans) ?></span>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($plans)): ?>
                <div class="list-group-item text-muted">Zatím bez jídelníčků.</div>
                <?php else: ?>
                    <?php foreach ($plans as $plan): ?>
                    <a href="<?= BASE_URL ?>/meal_plans.php?plan_id=<?= (int)$plan['id'] ?>"
                       class="list-group-item list-group-item-action <?= (int)$plan['id'] === $selectedPlanId ? 'active' : '' ?>">
                        <div class="fw-semibold"><?= h((string)$plan['name']) ?></div>
                        <div class="small <?= (int)$plan['id'] === $selectedPlanId ? 'text-white-50' : 'text-muted' ?>">
                            položek: <?= (int)$plan['item_count'] ?> · přiřazeno: <?= (int)$plan['active_assignments'] ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-9">
        <?php if ($selectedPlanId <= 0): ?>
        <div class="alert alert-info"><?= !empty($plans) ? 'Vyberte vlevo šablonu jídelníčku.' : 'Nejdřív vytvořte jídelníček vlevo.' ?></div>
        <?php else: ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold">Nastavení jídelníčku</span>
                <span class="badge bg-warning text-dark">ID <?= (int)$selectedPlanId ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-lg-7">
                        <form method="post" class="row g-2 align-items-end h-100">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="rename_plan">
                            <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">
                            <div class="col-12">
                                <label class="form-label fw-semibold">Název jídelníčku</label>
                                <input type="text" name="plan_name" class="form-control" maxlength="200" required value="<?= h((string)$selectedPlan['name']) ?>">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-outline-primary fw-semibold">
                                    <i class="fas fa-save me-1"></i>Uložit název
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="col-lg-5">
                        <div class="border rounded-3 bg-light p-3">
                            <form method="post" class="row g-2 align-items-end">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="clone_plan">
                                <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">
                                <div class="col-12">
                                    <label class="form-label fw-semibold mb-1">Kopie jako nový jídelníček</label>
                                    <input type="text" name="clone_name" class="form-control" maxlength="200" placeholder="<?= h((string)$selectedPlan['name']) ?> (kopie)">
                                </div>
                                <div class="col-12 d-grid">
                                    <button type="submit" class="btn btn-outline-secondary fw-semibold">
                                        <i class="fas fa-copy me-1"></i>Zkopírovat šablonu
                                    </button>
                                </div>
                            </form>
                        </div>

                        <form method="post" class="mt-3 d-grid d-lg-flex justify-content-lg-end" onsubmit="return confirm('Opravdu chcete jídelníček smazat?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_plan">
                            <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">
                            <button type="submit" class="btn btn-outline-danger fw-semibold">
                                <i class="fas fa-trash me-1"></i>Smazat jídelníček
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white fw-semibold">Přidat položku do jídelníčku</div>
            <div class="card-body">
                <?php if (empty($meals)): ?>
                <div class="alert alert-warning mb-0">
                    Nejprve přidejte jídla do databáze. <a href="<?= BASE_URL ?>/meals.php" class="alert-link">Otevřít databázi jídel</a>
                </div>
                <?php else: ?>
                <form method="post" class="row g-3 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Den</label>
                        <select name="day_of_week" class="form-select" required>
                            <?php foreach ($mealDays as $dayKey => $dayLabel): ?>
                            <option value="<?= h($dayKey) ?>"><?= h($dayLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Typ jídla</label>
                        <select name="meal_type" class="form-select" required>
                            <?php foreach ($mealTypes as $typeKey => $typeLabel): ?>
                            <option value="<?= h($typeKey) ?>"><?= h($typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Jídlo z databáze</label>
                        <select name="meal_id" id="mealSelect" class="form-select" required>
                            <?php foreach ($meals as $meal): ?>
                            <option
                                value="<?= (int)$meal['id'] ?>"
                                data-default-grams="<?= $meal['grams'] !== null ? (int)$meal['grams'] : '' ?>"
                            >
                                <?= h((string)$meal['name']) ?>
                                (<?= !empty($meal['meal_type']) ? h(mealTypeLabel((string)$meal['meal_type'])) : 'Bez typu' ?><?= $meal['grams'] !== null ? ', výchozí ' . (int)$meal['grams'] . ' g' : '' ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Množství (g)</label>
                        <input type="number" name="item_grams" id="itemGramsInput" class="form-control" min="1" max="5000" step="1" required placeholder="150">
                    </div>

                    <div class="col-md-12 col-lg-12">
                        <button type="submit" class="btn btn-warning fw-bold w-100">
                            <i class="fas fa-plus me-1"></i>Přidat
                        </button>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Poznámka k typu jídla</label>
                        <textarea name="note" class="form-control" rows="2" maxlength="2000" placeholder="Volitelná poznámka pro sportovce..."></textarea>
                    </div>
                </form>

                <hr class="my-4">

                <form method="post" class="row g-3 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_cheat_day">
                    <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Nastavit Cheat Day pro den</label>
                        <select name="day_of_week" class="form-select" required>
                            <?php foreach ($mealDays as $dayKey => $dayLabel): ?>
                            <option value="<?= h($dayKey) ?>"><?= h($dayLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-warning fw-bold w-100" onclick="return confirm('Nastavit vybraný den jako Cheat Day? Tím se smažou stávající položky toho dne.');">
                            <i class="fas fa-fire me-1"></i>Nastavit Cheat Day
                        </button>
                    </div>

                    <div class="col-md-5">
                        <div class="small text-muted">Cheat Day vytvoří jedinou položku dne s textem: "<?= h($cheatDayMessage) ?>".</div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Obsah jídelníčku</span>
                <span class="badge bg-warning text-dark"><?= count($planItems) ?> položek</span>
            </div>
            <div class="card-body">
                <?php if (empty($planItems)): ?>
                <div class="text-muted">Jídelníček zatím neobsahuje žádné položky.</div>
                <?php else: ?>
                    <?php foreach ($mealDays as $dayKey => $dayLabel): ?>
                        <?php $dayItems = $itemsByDay[$dayKey] ?? []; ?>
                        <?php if (empty($dayItems)) continue; ?>
                        <div class="mb-4" id="day-<?= h($dayKey) ?>">
                            <h5 class="mb-3"><i class="fas fa-calendar-day me-2 text-primary"></i><?= h($dayLabel) ?></h5>
                            <div class="table-responsive mealplan-table-wrap">
                                <table class="table table-sm align-middle mealplan-items-table coach-mealplan-items-table" style="table-layout:fixed; width:100%;">
                                    <thead class="table-light">
                                    <tr>
                                        <th style="width:55px">#</th>
                                        <th style="width:150px">Typ</th>
                                        <th>Jídlo</th>
                                        <th style="width:120px">Množství</th>
                                        <th style="width:240px">Nutriční hodnoty</th>
                                        <th style="width:210px">Poznámka</th>
                                        <th style="width:120px"></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($dayItems as $itemIndex => $item): ?>
                                    <?php $isCheatDayRow = ((string)$item['meal_type'] === 'cheat_day' && $item['meal_id'] === null); ?>
                                    <tr>
                                        <td data-label="#"><?= (int)$item['position'] ?></td>
                                        <td data-label="Typ"><span class="badge <?= $isCheatDayRow ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= h(mealTypeLabel((string)$item['meal_type'])) ?></span></td>
                                        <td data-label="Jídlo">
                                            <?php if ($isCheatDayRow): ?>
                                            <div class="fw-semibold text-warning-emphasis"><?= h((string)$item['meal_name_snapshot']) ?></div>
                                            <?php else: ?>
                                            <div class="fw-semibold"><?= h((string)($item['meal_name'] ?: $item['meal_name_snapshot'])) ?></div>
                                            <?php if (!empty($item['photo'])): ?>
                                            <img src="<?= h(photoUrl((string)$item['photo'], 'meals')) ?>" alt="<?= h((string)($item['meal_name'] ?: $item['meal_name_snapshot'])) ?>" class="mt-1" style="width:52px;height:52px;object-fit:cover;border-radius:8px;" onerror="this.style.display='none'">
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Množství"><?= (!$isCheatDayRow && $item['grams'] !== null) ? (int)$item['grams'] . ' g' : '–' ?></td>
                                        <td data-label="Nutriční hodnoty" class="small text-muted">
                                            <?php
                                            $gramsFactor = $item['grams'] !== null ? ((float)$item['grams'] / 100) : 0.0;
                                            $fatValue = $item['fat_per_100g'] !== null ? (float)$item['fat_per_100g'] * $gramsFactor : null;
                                            $sugarsValue = $item['sugars_per_100g'] !== null ? (float)$item['sugars_per_100g'] * $gramsFactor : null;
                                            $proteinValue = $item['protein_per_100g'] !== null ? (float)$item['protein_per_100g'] * $gramsFactor : null;
                                            $fiberValue = $item['fiber_per_100g'] !== null ? (float)$item['fiber_per_100g'] * $gramsFactor : null;
                                            $saltValue = $item['salt_per_100g'] !== null ? (float)$item['salt_per_100g'] * $gramsFactor : null;
                                            ?>
                                            <?php if ($isCheatDayRow): ?>
                                            <div>–</div>
                                            <?php else: ?>
                                            <div>Tuky: <?= $fatValue !== null ? number_format($fatValue, 2, ',', '') . ' g' : '–' ?></div>
                                            <div>Cukry: <?= $sugarsValue !== null ? number_format($sugarsValue, 2, ',', '') . ' g' : '–' ?></div>
                                            <div>Bílkoviny: <?= $proteinValue !== null ? number_format($proteinValue, 2, ',', '') . ' g' : '–' ?></div>
                                            <div>Vláknina: <?= $fiberValue !== null ? number_format($fiberValue, 2, ',', '') . ' g' : '–' ?></div>
                                            <div>Sůl: <?= $saltValue !== null ? number_format($saltValue, 2, ',', '') . ' g' : '–' ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Poznámka" class="small text-muted" style="white-space:pre-wrap"><?= (!$isCheatDayRow && !empty($item['note'])) ? h((string)$item['note']) : '–' ?></td>
                                        <td data-label="Akce" class="text-end">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <form method="post" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="move_item">
                                                    <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">
                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                    <input type="hidden" name="direction" value="up">
                                                    <input type="hidden" name="return_anchor" value="day-<?= h($dayKey) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Posunout nahoru" <?= ($itemIndex === 0 || $isCheatDayRow) ? 'disabled' : '' ?>><i class="fas fa-chevron-up"></i></button>
                                                </form>

                                                <form method="post" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="move_item">
                                                    <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">
                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                    <input type="hidden" name="direction" value="down">
                                                    <input type="hidden" name="return_anchor" value="day-<?= h($dayKey) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Posunout dolů" <?= ($itemIndex === count($dayItems) - 1 || $isCheatDayRow) ? 'disabled' : '' ?>><i class="fas fa-chevron-down"></i></button>
                                                </form>

                                                <form method="post" class="d-inline" onsubmit="return confirm('Odstranit tuto položku?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">
                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white fw-semibold">Přiřadit sportovcům</div>
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end mb-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="assign_plan">
                    <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Komu poslat</label>
                        <select name="target" id="assignTarget" class="form-select" required>
                            <option value="single">Konkrétnímu sportovci</option>
                            <option value="all">Všem sportovcům</option>
                        </select>
                    </div>

                    <div class="col-md-6" id="singleAthleteWrap">
                        <label class="form-label fw-semibold">Sportovec</label>
                        <select name="athlete_id" class="form-select">
                            <?php foreach ($athletes as $athlete): ?>
                            <option value="<?= (int)$athlete['id'] ?>"><?= h(trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <button type="submit" class="btn btn-warning fw-bold w-100" <?= empty($athletes) ? 'disabled' : '' ?>>
                            <i class="fas fa-paper-plane me-1"></i>Přiřadit
                        </button>
                    </div>
                </form>

                <?php if (empty($athletes)): ?>
                <div class="alert alert-info mb-0">Nejprve přidejte alespoň jednoho sportovce.</div>
                <?php endif; ?>

                <h6 class="fw-semibold">Aktivně přiřazeno</h6>
                <?php if (empty($activeAssignments)): ?>
                <div class="text-muted">Tento jídelníček zatím není přiřazen žádnému sportovci.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Sportovec</th>
                            <th>Přiřazeno</th>
                            <th style="width:110px"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeAssignments as $assignment): ?>
                        <tr>
                            <td><?= h(trim((string)$assignment['first_name'] . ' ' . (string)$assignment['last_name'])) ?></td>
                            <td><?= formatDateTime((string)$assignment['assigned_at']) ?></td>
                            <td class="text-end">
                                <form method="post" onsubmit="return confirm('Odebrat jídelníček tomuto sportovci?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="remove_assignment">
                                    <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">
                                    <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-unlink me-1"></i>Odebrat
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var targetSelect = document.getElementById('assignTarget');
    var singleWrap = document.getElementById('singleAthleteWrap');
    var mealSelect = document.getElementById('mealSelect');
    var gramsInput = document.getElementById('itemGramsInput');
    if (!targetSelect || !singleWrap) {
        // pokračuj dál, protože předvyplnění gramáže může být i bez tohoto bloku
    } else {
        var updateVisibility = function () {
            singleWrap.style.display = targetSelect.value === 'single' ? '' : 'none';
        };

        targetSelect.addEventListener('change', updateVisibility);
        updateVisibility();
    }

    var fillDefaultGrams = function () {
        if (!mealSelect || !gramsInput) {
            return;
        }
        if (gramsInput.value !== '') {
            return;
        }
        var selected = mealSelect.options[mealSelect.selectedIndex];
        if (!selected) {
            return;
        }
        var defaultGrams = selected.getAttribute('data-default-grams');
        if (defaultGrams) {
            gramsInput.value = defaultGrams;
        }
    };

    if (mealSelect) {
        mealSelect.addEventListener('change', fillDefaultGrams);
        fillDefaultGrams();
    }
});
</script>

<?php renderFooter();
