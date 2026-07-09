<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();
$mealDays = mealDayOptions();

$assignmentsStmt = $pdo->prepare(
    'SELECT a.id AS assignment_id,
            a.assigned_at,
            p.id AS plan_id,
            p.name AS plan_name
     FROM athlete_meal_plans a
     JOIN coach_meal_plans p ON p.id = a.meal_plan_id
     WHERE a.athlete_id = ?
       AND a.removed_at IS NULL
     ORDER BY a.assigned_at DESC, a.id DESC'
);
$assignmentsStmt->execute([$athleteId]);
$assignments = $assignmentsStmt->fetchAll();

$itemsByPlan = [];
if (!empty($assignments)) {
    $planIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['plan_id'], $assignments)));
    $in = implode(',', array_fill(0, count($planIds), '?'));

    $itemsStmt = $pdo->prepare(
        'SELECT i.meal_plan_id,
                i.day_of_week,
                i.meal_type,
                i.meal_id,
                i.grams,
                i.note,
                i.position,
                i.meal_name_snapshot,
                m.name AS meal_name,
                m.description,
                m.photo,
                m.fat_per_100g,
                m.sugars_per_100g,
                m.protein_per_100g,
                m.fiber_per_100g,
                m.salt_per_100g
         FROM coach_meal_plan_items i
         LEFT JOIN coach_meals m ON m.id = i.meal_id
         WHERE i.meal_plan_id IN (' . $in . ')
         ORDER BY i.meal_plan_id,
                  FIELD(i.day_of_week, "monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"),
                  i.position ASC,
                  i.id ASC'
    );
    $itemsStmt->execute($planIds);

    foreach ($itemsStmt->fetchAll() as $item) {
        $planId = (int)$item['meal_plan_id'];
        $day = (string)$item['day_of_week'];

        if (!isset($itemsByPlan[$planId])) {
            $itemsByPlan[$planId] = [];
        }
        if (!isset($itemsByPlan[$planId][$day])) {
            $itemsByPlan[$planId][$day] = [];
        }
        $itemsByPlan[$planId][$day][] = $item;
    }
}

renderAthleteHeader('Jídelníčky', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-utensils me-2 text-warning"></i>Jídelníčky</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <a href="<?= BASE_URL ?>/athlete_mealplans_print.php" target="_blank" class="btn btn-outline-dark btn-sm">
            <i class="fas fa-print me-1"></i>Tisk jídelníčků
        </a>
    </div>
</div>

<?php if (empty($assignments)): ?>
<div class="alert alert-info">
    Trenér vám zatím nepřiřadil žádný jídelníček.
</div>
<?php else: ?>
<div class="accordion" id="athleteMealPlansAccordion">
    <?php foreach ($assignments as $assignment): ?>
        <?php
        $planId = (int)$assignment['plan_id'];
        $daysInPlan = $itemsByPlan[$planId] ?? [];
        $accordionId = 'plan-' . (int)$assignment['assignment_id'];
        $totalItems = 0;
        foreach ($daysInPlan as $dayItemsCount) {
            $totalItems += count($dayItemsCount);
        }
        ?>
        <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
            <h2 class="accordion-header" id="heading-<?= h($accordionId) ?>">
                <button class="accordion-button collapsed bg-dark text-white" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?= h($accordionId) ?>" aria-expanded="false" aria-controls="collapse-<?= h($accordionId) ?>">
                    <div class="d-flex justify-content-between align-items-center w-100 me-3 flex-wrap gap-2">
                        <span class="fw-semibold"><i class="fas fa-layer-group me-2 text-warning"></i><?= h((string)$assignment['plan_name']) ?></span>
                        <span class="d-flex gap-2 flex-wrap">
                            <span class="badge bg-secondary">Položek <?= (int)$totalItems ?></span>
                            <span class="badge bg-warning text-dark">Přiřazeno <?= formatDateTime((string)$assignment['assigned_at']) ?></span>
                        </span>
                    </div>
                </button>
            </h2>
            <div id="collapse-<?= h($accordionId) ?>" class="accordion-collapse collapse" aria-labelledby="heading-<?= h($accordionId) ?>" data-bs-parent="#athleteMealPlansAccordion">
                <div class="accordion-body bg-white">
                    <?php if (empty($daysInPlan)): ?>
                    <div class="text-muted">Tento jídelníček zatím neobsahuje žádné položky.</div>
                    <?php else: ?>
                        <?php foreach ($mealDays as $dayKey => $dayLabel): ?>
                            <?php $dayItems = $daysInPlan[$dayKey] ?? []; ?>
                            <?php if (empty($dayItems)) continue; ?>
                            <div class="mb-4">
                                <h5 class="mb-3"><i class="fas fa-calendar-day me-2 text-primary"></i><?= h($dayLabel) ?></h5>
                                <div class="table-responsive mealplan-table-wrap">
                                    <table class="table table-sm align-middle mb-0 mealplan-items-table" style="table-layout:fixed; width:100%;">
                                        <thead class="table-light">
                                        <tr>
                                            <th style="width:55px">#</th>
                                            <th style="width:150px">Typ</th>
                                            <th>Jídlo</th>
                                            <th style="width:120px">Množství</th>
                                            <th style="width:240px">Nutriční hodnoty</th>
                                            <th style="width:210px">Poznámka</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($dayItems as $item): ?>
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
                                                <?php if (!empty($item['description'])): ?>
                                                <div class="small text-muted mt-1" style="white-space:pre-wrap"><?= h((string)$item['description']) ?></div>
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
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php renderAthleteFooter();
