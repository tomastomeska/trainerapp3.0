<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/food_diary.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.coach_id, a.first_name, a.last_name
     FROM athletes a
     WHERE a.id = ?
     LIMIT 1'
);
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    session_destroy();
    redirect(BASE_URL . '/login.php');
}

$selectedDate = foodDiaryResolveSelectedDate((string)($_GET['date'] ?? date('Y-m-d')));
$period = foodDiaryNormalizePeriod($_GET, $selectedDate);
$rows = foodDiaryExportRows($pdo, $athleteId, (int)$athlete['coach_id'], $period['from'], $period['to']);

$rowsByDate = [];
foreach ($rows as $row) {
    $rowsByDate[(string)$row['date']][] = $row;
}

$periodLabel = formatDate($period['from']) . ' - ' . formatDate($period['to']);
$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Strava - Export PDF</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #111; }
        h1, h2, h3 { margin: 0 0 10px; }
        .meta { margin-bottom: 18px; font-size: 14px; color: #444; }
        .day { border: 1px solid #ddd; border-radius: 10px; padding: 14px; margin-bottom: 14px; page-break-inside: avoid; }
        .meal { border-top: 1px dashed #ddd; padding-top: 10px; margin-top: 10px; }
        .meal:first-child { border-top: 0; padding-top: 0; margin-top: 0; }
        .item { margin-left: 16px; }
        .note { background: #f8f9fa; border-left: 3px solid #999; padding: 8px; margin-top: 6px; }
        .coach-note { border-left-color: #0d6efd; }
        .day-note { border-left-color: #198754; }
        .photo { margin-top: 8px; max-width: 260px; max-height: 200px; border: 1px solid #ddd; border-radius: 6px; }
        @media print {
            .no-print { display: none; }
            body { margin: 8mm; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom:12px;">
        <button onclick="window.print()">Tisk / Uložit jako PDF</button>
        <a href="<?= BASE_URL ?>/athlete_food_diary.php?date=<?= urlencode($selectedDate) ?>" style="margin-left:10px;">Zpět do Stravy</a>
    </div>

    <h1>Strava - export</h1>
    <div class="meta">
        <div><strong>Sportovec:</strong> <?= h($athleteName) ?></div>
        <div><strong>Období:</strong> <?= h($periodLabel) ?></div>
    </div>

    <?php if (empty($rowsByDate)): ?>
        <p>V tomto období nejsou žádné záznamy stravy.</p>
    <?php else: ?>
        <?php foreach ($rowsByDate as $date => $dateRows): ?>
            <div class="day">
                <h3><?= h(foodDiaryFormatCzDateTitle((string)$date)) ?></h3>
                <?php
                $activity = trim((string)($dateRows[0]['activity'] ?? ''));
                if ($activity !== ''):
                ?>
                <div><strong>Aktivita:</strong> <?= h($activity) ?></div>
                <?php endif; ?>

                <?php
                $byMeal = [];
                foreach ($dateRows as $row) {
                    $byMeal[(string)$row['meal_type']][] = $row;
                }
                ?>

                <?php foreach (foodDiaryMealTypes() as $mealType => $mealMeta): ?>
                    <?php if (empty($byMeal[$mealType])) { continue; } ?>
                    <?php $mealRows = $byMeal[$mealType]; ?>
                    <div class="meal">
                        <strong><?= h((string)$mealMeta['label']) ?></strong>
                        <?php if (!empty($mealRows[0]['meal_time'])): ?>
                            (<?= h(substr((string)$mealRows[0]['meal_time'], 0, 5)) ?>)
                        <?php endif; ?>

                        <?php foreach ($mealRows as $mealRow): ?>
                            <div class="item">- <?= h((string)$mealRow['food_name']) ?>
                                <?php if ((string)$mealRow['quantity'] !== ''): ?>
                                    <?= h(str_replace('.', ',', (string)$mealRow['quantity'])) ?> <?= h((string)$mealRow['unit']) ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if (!empty($mealRows[0]['athlete_note'])): ?>
                            <div class="note"><strong>Poznámka sportovce:</strong><br><?= nl2br(h((string)$mealRows[0]['athlete_note'])) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($mealRows[0]['coach_note'])): ?>
                            <div class="note coach-note"><strong>Poznámka trenéra:</strong><br><?= nl2br(h((string)$mealRows[0]['coach_note'])) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($mealRows[0]['photo'])): ?>
                            <img class="photo" src="<?= h(photoUrl((string)$mealRows[0]['photo'], 'food_diary')) ?>" alt="Foto jídla">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($dateRows[0]['day_coach_note'])): ?>
                <div class="note day-note"><strong>Poznámka trenéra k celému dni:</strong><br><?= nl2br(h((string)$dateRows[0]['day_coach_note'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
