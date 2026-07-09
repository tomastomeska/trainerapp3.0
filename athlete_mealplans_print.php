<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$athlete = getCurrentAthlete();
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
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tisk jídelníčků</title>
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        body { font-family: "Segoe UI", Arial, sans-serif; color: #111827; margin: 0; }
        .print-actions { margin-bottom: 10px; }
        .print-actions button { padding: 7px 12px; font-weight: 700; }
        .title { font-size: 20px; font-weight: 800; margin: 0 0 2px; }
        .subtitle { color: #4b5563; margin: 0 0 10px; font-size: 12px; }
        .plan-block { border: 1px solid #d1d5db; border-radius: 8px; margin-bottom: 10px; overflow: hidden; break-inside: avoid; }
        .plan-head { background: linear-gradient(135deg, #111827, #1f2937); color: #fff; padding: 7px 9px; font-weight: 700; display: flex; justify-content: space-between; gap: 6px; font-size: 12px; }
        .plan-body { padding: 8px; }
        .day-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; }
        .day-card { border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
        .day-head { background: #f3f4f6; padding: 5px 7px; font-size: 12px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border-top: 1px solid #e5e7eb; padding: 4px 5px; vertical-align: top; font-size: 10px; }
        th { background: #fafafa; text-align: left; font-size: 9.5px; color: #374151; }
        .muted { color: #6b7280; }
        .badge { display: inline-block; border-radius: 999px; background: #e5e7eb; padding: 1px 6px; font-size: 9px; font-weight: 700; }
        .cheat-row { background: #fffbeb; color: #92400e; font-weight: 700; }
        @media print {
            .print-actions { display: none; }
        }
    </style>
</head>
<body>
<div class="print-actions">
    <button onclick="window.print()">Tisk</button>
</div>

<div class="title">Jídelníčky sportovce</div>
<div class="subtitle">
    <?= h(trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''))) ?>
    · vygenerováno <?= date('d.m.Y H:i') ?>
</div>

<?php if (empty($assignments)): ?>
<p class="muted">Sportovec nemá přiřazen žádný jídelníček.</p>
<?php else: ?>
    <?php foreach ($assignments as $assignment): ?>
        <?php
        $planId = (int)$assignment['plan_id'];
        $daysInPlan = $itemsByPlan[$planId] ?? [];
        ?>
        <section class="plan-block">
            <div class="plan-head">
                <span><?= h((string)$assignment['plan_name']) ?></span>
                <span>Přiřazeno <?= formatDateTime((string)$assignment['assigned_at']) ?></span>
            </div>
            <div class="plan-body">
                <?php if (empty($daysInPlan)): ?>
                <p class="muted">Tento jídelníček zatím neobsahuje žádné položky.</p>
                <?php else: ?>
                    <div class="day-grid">
                    <?php foreach ($mealDays as $dayKey => $dayLabel): ?>
                        <?php $dayItems = $daysInPlan[$dayKey] ?? []; ?>
                        <?php if (empty($dayItems)) continue; ?>
                        <div class="day-card">
                            <div class="day-head"><?= h($dayLabel) ?></div>
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:30px">#</th>
                                        <th style="width:66px">Typ</th>
                                        <th>Jídlo</th>
                                        <th style="width:56px">g</th>
                                        <th style="width:100px">Nutrice</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($dayItems as $item): ?>
                                    <?php
                                    $isCheatDayRow = ((string)$item['meal_type'] === 'cheat_day' && $item['meal_id'] === null);
                                    $gramsFactor = $item['grams'] !== null ? ((float)$item['grams'] / 100) : 0.0;
                                    $fatValue = $item['fat_per_100g'] !== null ? (float)$item['fat_per_100g'] * $gramsFactor : null;
                                    $sugarsValue = $item['sugars_per_100g'] !== null ? (float)$item['sugars_per_100g'] * $gramsFactor : null;
                                    $proteinValue = $item['protein_per_100g'] !== null ? (float)$item['protein_per_100g'] * $gramsFactor : null;
                                    ?>
                                    <tr class="<?= $isCheatDayRow ? 'cheat-row' : '' ?>">
                                        <td><?= (int)$item['position'] ?></td>
                                        <td><span class="badge"><?= h(mealTypeLabel((string)$item['meal_type'])) ?></span></td>
                                        <td>
                                            <?= h((string)($item['meal_name'] ?: $item['meal_name_snapshot'])) ?>
                                            <?php if (!$isCheatDayRow && !empty($item['note'])): ?>
                                            <div class="muted" style="font-size:9px"><?= h((string)$item['note']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (!$isCheatDayRow && $item['grams'] !== null) ? (int)$item['grams'] . ' g' : '–' ?></td>
                                        <td>
                                            <?php if ($isCheatDayRow): ?>–<?php else: ?>
                                            T <?= $fatValue !== null ? number_format($fatValue, 1, ',', '') : '–' ?> ·
                                            C <?= $sugarsValue !== null ? number_format($sugarsValue, 1, ',', '') : '–' ?> ·
                                            B <?= $proteinValue !== null ? number_format($proteinValue, 1, ',', '') : '–' ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
