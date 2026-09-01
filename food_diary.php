<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/food_diary.php';

requireLogin();

$coachId = (int)getCurrentCoachId();
$athleteId = (int)($_GET['athlete_id'] ?? 0);
$pdo = getDB();

$athlete = foodDiaryRequireCoachAthlete($pdo, $coachId, $athleteId);
if (!$athlete) {
    flash('danger', 'Sportovec nebyl nalezen nebo k němu nemáte přístup.');
    redirect(BASE_URL . '/dashboard.php');
}

$todayDate = foodDiaryEffectiveToday($pdo);
$schemaHealth = foodDiarySchemaHealth($pdo);
$selectedDate = foodDiaryResolveSelectedDate((string)($_GET['date'] ?? $todayDate), $todayDate);
$monthStart = foodDiaryResolveMonthStart($selectedDate);
$monthParam = $monthStart->format('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$schemaHealth['ok']) {
        $missingText = implode(', ', $schemaHealth['missing']);
        flash('danger', 'Strava není připravená v databázi. Chybí: ' . $missingText);
        redirect(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($selectedDate) . '&month=' . urlencode($monthParam));
    }

    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($selectedDate) . '&month=' . urlencode($monthParam));
    }

    $action = (string)($_POST['action'] ?? '');
    $postedDate = foodDiaryResolveSelectedDate((string)($_POST['selected_date'] ?? $selectedDate), $todayDate);
    $postedMonth = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['month'] ?? '')) === 1
        ? (string)$_POST['month']
        : $monthParam;

    if (!foodDiaryValidateDate($postedDate) || foodDiaryIsFutureDate($postedDate, $todayDate)) {
        flash('danger', 'Do budoucího data nelze ukládat poznámky ke stravě.');
        redirect(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($selectedDate) . '&month=' . urlencode($monthParam));
    }

    if ($action === 'save_meal_note') {
        $mealId = (int)($_POST['meal_id'] ?? 0);
        $noteText = trim((string)($_POST['note'] ?? ''));
        $noteText = mb_substr($noteText, 0, 4000, 'UTF-8');

        if ($mealId <= 0) {
            flash('danger', 'Jídlo nebylo nalezeno.');
            redirect(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        $mealStmt = $pdo->prepare(
            'SELECT m.id
             FROM food_diary_meals m
             JOIN food_diary_days d ON d.id = m.day_id
             WHERE m.id = ?
               AND d.athlete_id = ?
               AND d.date = ?
             LIMIT 1'
        );
        $mealStmt->execute([$mealId, $athleteId, $postedDate]);
        if (!$mealStmt->fetch()) {
            flash('danger', 'Jídlo nebylo nalezeno.');
            redirect(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        try {
            $existingStmt = $pdo->prepare(
                'SELECT id
                 FROM food_diary_coach_notes
                 WHERE coach_id = ?
                   AND meal_id = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $existingStmt->execute([$coachId, $mealId]);
            $existingId = (int)$existingStmt->fetchColumn();

            if ($noteText === '') {
                if ($existingId > 0) {
                    $pdo->prepare('DELETE FROM food_diary_coach_notes WHERE id = ?')->execute([$existingId]);
                }
                flash('success', 'Poznámka trenéra byla smazána.');
            } elseif ($existingId > 0) {
                $pdo->prepare('UPDATE food_diary_coach_notes SET note = ?, updated_at = NOW() WHERE id = ?')->execute([$noteText, $existingId]);
                createAthleteNotification($athleteId, 'Poznámka ke stravě', 'Trenér upravil poznámku u jídla (' . formatDate($postedDate) . ').');
                flash('success', 'Poznámka trenéra byla upravena.');
            } else {
                $pdo->prepare(
                    'INSERT INTO food_diary_coach_notes (meal_id, day_id, coach_id, note, created_at, updated_at)
                     VALUES (?, NULL, ?, ?, NOW(), NOW())'
                )->execute([$mealId, $coachId, $noteText]);
                createAthleteNotification($athleteId, 'Poznámka ke stravě', 'Trenér přidal poznámku ke stravě.');
                flash('success', 'Poznámka trenéra byla přidána.');
            }
        } catch (Throwable $e) {
            flash('danger', 'Poznámku se nepodařilo uložit.');
        }

        redirect(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }

    if ($action === 'delete_meal_note') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        if ($noteId > 0) {
            $pdo->prepare('DELETE FROM food_diary_coach_notes WHERE id = ? AND coach_id = ?')->execute([$noteId, $coachId]);
            flash('success', 'Poznámka trenéra byla smazána.');
        }
        redirect(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }

    if ($action === 'save_day_note') {
        $noteText = trim((string)($_POST['note'] ?? ''));
        $noteText = mb_substr($noteText, 0, 4000, 'UTF-8');

        $dayId = foodDiaryGetOrCreateDayId($pdo, $athleteId, $postedDate);
        try {
            $existingStmt = $pdo->prepare(
                'SELECT id
                 FROM food_diary_coach_notes
                 WHERE coach_id = ?
                   AND day_id = ?
                   AND meal_id IS NULL
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $existingStmt->execute([$coachId, $dayId]);
            $existingId = (int)$existingStmt->fetchColumn();

            if ($noteText === '') {
                if ($existingId > 0) {
                    $pdo->prepare('DELETE FROM food_diary_coach_notes WHERE id = ?')->execute([$existingId]);
                }
                flash('success', 'Poznámka k celému dni byla smazána.');
            } elseif ($existingId > 0) {
                $pdo->prepare('UPDATE food_diary_coach_notes SET note = ?, updated_at = NOW() WHERE id = ?')->execute([$noteText, $existingId]);
                createAthleteNotification($athleteId, 'Poznámka ke stravě', 'Trenér upravil poznámku k celému dni (' . formatDate($postedDate) . ').');
                flash('success', 'Poznámka k celému dni byla upravena.');
            } else {
                $pdo->prepare(
                    'INSERT INTO food_diary_coach_notes (meal_id, day_id, coach_id, note, created_at, updated_at)
                     VALUES (NULL, ?, ?, ?, NOW(), NOW())'
                )->execute([$dayId, $coachId, $noteText]);
                createAthleteNotification($athleteId, 'Poznámka ke stravě', 'Trenér přidal poznámku ke stravě.');
                flash('success', 'Poznámka k celému dni byla přidána.');
            }
        } catch (Throwable $e) {
            flash('danger', 'Poznámku k celému dni se nepodařilo uložit.');
        }

        redirect(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }

    if ($action === 'delete_day_note') {
        $noteId = (int)($_POST['day_note_id'] ?? 0);
        if ($noteId > 0) {
            $pdo->prepare('DELETE FROM food_diary_coach_notes WHERE id = ? AND coach_id = ? AND meal_id IS NULL')->execute([$noteId, $coachId]);
            flash('success', 'Poznámka k celému dni byla smazána.');
        }

        redirect(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }
}

$day = foodDiaryFindDay($pdo, $athleteId, $selectedDate);
$dayId = (int)($day['id'] ?? 0);
$mealBlocks = [];
$dayCoachNote = null;
$customActivities = [];
$hydrationEntries = [];
$hydrationMl = 0;
if ($dayId > 0) {
    $mealBlocks = foodDiaryLoadMeals($pdo, $dayId, $coachId);
    $dayCoachNote = foodDiaryLoadDayNote($pdo, $dayId, $coachId);
    $customActivities = foodDiaryLoadCustomActivities($pdo, $dayId);
    $hydrationEntries = foodDiaryLoadHydrationEntries($pdo, $dayId);
    foreach ($hydrationEntries as $entry) {
        $hydrationMl += (int)($entry['amount_ml'] ?? 0);
    }
} else {
    foreach (array_keys(foodDiaryMealTypes()) as $mealType) {
        $mealBlocks[$mealType] = ['meal' => null, 'items' => [], 'coach_note' => null];
    }
}

$autoActivities = foodDiaryLoadAutoActivities($pdo, $athleteId, $selectedDate);
$monthStatus = foodDiaryMonthStatus($pdo, $athleteId, $monthStart);
$weekSummary = foodDiaryWeekSummary($pdo, $athleteId, $selectedDate);

$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$todayMonth = (new DateTimeImmutable('today'))->format('Y-m');
$nextMonthObj = $monthStart->modify('+1 month');
$nextMonth = $nextMonthObj->format('Y-m');
$canGoNextMonth = $nextMonthObj <= (new DateTimeImmutable('today'))->modify('first day of this month');

$monthTitleMonths = [
    1 => 'Leden',
    2 => 'Únor',
    3 => 'Březen',
    4 => 'Duben',
    5 => 'Květen',
    6 => 'Červen',
    7 => 'Červenec',
    8 => 'Srpen',
    9 => 'Září',
    10 => 'Říjen',
    11 => 'Listopad',
    12 => 'Prosinec',
];

$monthName = $monthTitleMonths[(int)$monthStart->format('n')] ?? $monthStart->format('m');
$monthTitle = $monthName . ' ' . $monthStart->format('Y');

renderHeader('Strava - ' . trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']), false, true);
?>

<?php if (!$schemaHealth['ok']): ?>
<div class="alert alert-danger">
    <div class="fw-semibold mb-1">Strava není připravená v databázi</div>
    <div class="small">Chybějící části schématu: <?= h(implode(', ', $schemaHealth['missing'])) ?></div>
    <div class="small mt-1">Spusťte migraci modulu Strava a stránku obnovte.</div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h2 class="mb-0"><i class="fas fa-bowl-food text-warning me-2"></i>Strava sportovce</h2>
        <small class="text-muted"><?= h(trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])) ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$athleteId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Detail sportovce
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="mb-0"><i class="fas fa-calendar-days me-2 text-primary"></i><?= h($monthTitle) ?></h5>
                    <div class="d-flex gap-2">
                        <a href="<?= BASE_URL ?>/food_diary.php?athlete_id=<?= (int)$athleteId ?>&month=<?= urlencode($previousMonth) ?>&date=<?= urlencode($selectedDate) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <a href="<?= BASE_URL ?>/food_diary.php?athlete_id=<?= (int)$athleteId ?>&month=<?= urlencode($todayMonth) ?>&date=<?= urlencode($todayDate) ?>" class="btn btn-outline-primary btn-sm">Dnes</a>
                        <?php if ($canGoNextMonth): ?>
                        <a href="<?= BASE_URL ?>/food_diary.php?athlete_id=<?= (int)$athleteId ?>&month=<?= urlencode($nextMonth) ?>&date=<?= urlencode($selectedDate) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Po</th>
                                <th>Út</th>
                                <th>St</th>
                                <th>Čt</th>
                                <th>Pá</th>
                                <th>So</th>
                                <th>Ne</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $daysInMonth = (int)$monthStart->format('t');
                            $firstWeekDay = (int)$monthStart->format('N');
                            $dayCounter = 1;
                            $printed = 0;

                            while ($printed < 42) {
                                echo '<tr>';
                                for ($col = 1; $col <= 7; $col++) {
                                    if (($printed < $firstWeekDay - 1) || $dayCounter > $daysInMonth) {
                                        echo '<td class="bg-light"></td>';
                                    } else {
                                        $cellDate = $monthStart->format('Y-m-') . str_pad((string)$dayCounter, 2, '0', STR_PAD_LEFT);
                                        $isToday = ($cellDate === $todayDate);
                                        $isSelected = ($cellDate === $selectedDate);
                                        $isFuture = ($cellDate > $todayDate);
                                        $status = $monthStatus[$cellDate] ?? 'none';
                                        $dot = $status === 'full' ? '🟢' : ($status === 'partial' ? '🟡' : '⚪');

                                        $classes = [];
                                        if ($isToday) {
                                            $classes[] = 'table-warning';
                                        }
                                        if ($isSelected) {
                                            $classes[] = 'table-primary';
                                        }
                                        if ($isFuture) {
                                            $classes[] = 'text-muted bg-light';
                                        }

                                        echo '<td class="' . h(implode(' ', $classes)) . '">';
                                        if ($isFuture) {
                                            echo '<div class="small fw-semibold">' . (int)$dayCounter . '</div>';
                                            echo '<div class="small">' . $dot . '</div>';
                                        } else {
                                            echo '<a class="text-decoration-none d-block" href="' . h(BASE_URL . '/food_diary.php?athlete_id=' . $athleteId . '&date=' . urlencode($cellDate) . '&month=' . urlencode($monthParam)) . '">';
                                            echo '<div class="small fw-semibold text-dark">' . (int)$dayCounter . '</div>';
                                            echo '<div class="small">' . $dot . '</div>';
                                            echo '</a>';
                                        }
                                        echo '</td>';

                                        $dayCounter++;
                                    }
                                    $printed++;
                                }
                                echo '</tr>';
                                if ($dayCounter > $daysInMonth && $printed >= 35) {
                                    break;
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-3"><i class="fas fa-chart-pie me-2 text-success"></i>Týdenní přehled</h5>
                <div class="small text-muted mb-2">
                    <?= h(formatDate($weekSummary['week_start'])) ?> - <?= h(formatDate($weekSummary['week_end'])) ?>
                </div>
                <?php foreach ($weekSummary['days'] as $dayStat): ?>
                    <?php $icon = $dayStat['status'] === 'full' ? '🟢' : ($dayStat['status'] === 'partial' ? '🟡' : '🔴'); ?>
                    <div class="d-flex justify-content-between border-bottom py-1 small">
                        <span><?= h((string)$dayStat['day_short']) ?> <?= $icon ?></span>
                        <span><?= (int)$dayStat['logged'] ?>/6</span>
                    </div>
                <?php endforeach; ?>
                <div class="mt-3">
                    <div><strong><?= (int)$weekSummary['total_logged'] ?> / <?= (int)$weekSummary['total_slots'] ?> jídel zaznamenáno</strong></div>
                    <div class="text-muted"><?= (int)$weekSummary['percent'] ?> % vyplněnost</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h4 class="mb-2"><?= h(foodDiaryFormatCzDateTitle($selectedDate)) ?></h4>
        <div class="card border mb-3">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h6 class="mb-0"><i class="fas fa-glass-water me-2 text-primary"></i>Pitný režim</h6>
                    <div class="small">
                        Vypito celkem:
                        <strong><?= $hydrationMl > 0 ? h(number_format($hydrationMl / 1000, 2, ',', '')) . ' l' : 'nezadáno' ?></strong>
                    </div>
                </div>
                <?php if (!empty($hydrationEntries)): ?>
                <div class="d-grid gap-2">
                    <?php foreach ($hydrationEntries as $entry): ?>
                    <div class="d-flex align-items-center justify-content-between border rounded px-2 py-1 bg-light">
                        <div class="small fw-semibold">
                            <?= h((string)$entry['drink_label']) ?> - <?= (int)$entry['amount_ml'] ?> ml
                        </div>
                        <div class="small text-muted"><?= h(date('H:i', strtotime((string)$entry['created_at']))) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="small text-muted">Sportovec zatím nezadal žádný záznam pitného režimu.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($autoActivities) || !empty($customActivities)): ?>
        <h6 class="mt-3 mb-2"><i class="fas fa-person-running me-2 text-primary"></i>Aktivita</h6>
        <?php foreach ($autoActivities as $activity): ?>
            <div class="alert alert-primary py-2 mb-2">
                <div class="fw-semibold">🏋️ <?= h((string)$activity['title']) ?></div>
                <div class="small"><?= h(date('H:i', strtotime((string)$activity['starts_at']))) ?> - <?= h(date('H:i', strtotime((string)$activity['ends_at']))) ?></div>
                <div class="small text-muted">Automaticky převzato z kalendáře TrainerApp.</div>
            </div>
        <?php endforeach; ?>
        <?php foreach ($customActivities as $customActivity): ?>
            <div class="alert alert-light py-2 mb-2 border">
                <div class="fw-semibold">🏃 <?= h(foodDiaryActivityTypeLabel((string)$customActivity['activity_type'])) ?><?= !empty($customActivity['activity_name']) ? ' - ' . h((string)$customActivity['activity_name']) : '' ?></div>
                <div class="small text-muted">
                    <?php if (!empty($customActivity['activity_time'])): ?><?= h(substr((string)$customActivity['activity_time'], 0, 5)) ?><?php endif; ?>
                    <?php if (!empty($customActivity['duration_minutes'])): ?> · <?= (int)$customActivity['duration_minutes'] ?> min<?php endif; ?>
                    <?php if ($customActivity['distance_km'] !== null): ?> · <?= h(number_format((float)$customActivity['distance_km'], 2, ',', '')) ?> km<?php endif; ?>
                </div>
                <?php if (!empty($customActivity['note'])): ?>
                <div class="small mt-1"><?= nl2br(h((string)$customActivity['note'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <div class="mt-3">
            <label class="form-label fw-semibold">Poznámka k celému dni</label>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_day_note">
                <input type="hidden" name="selected_date" value="<?= h($selectedDate) ?>">
                <input type="hidden" name="month" value="<?= h($monthParam) ?>">
                <textarea name="note" class="form-control" rows="3" placeholder="Např. Celkově dobrý příjem, pozor na pitný režim."><?= h((string)($dayCoachNote['note'] ?? '')) ?></textarea>
                <div class="d-flex gap-2 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Uložit poznámku</button>
                    <?php if (!empty($dayCoachNote['id'])): ?>
                    <button type="submit" name="action" value="delete_day_note" class="btn btn-outline-danger btn-sm" onclick="return confirm('Smazat poznámku k celému dni?')">Smazat poznámku</button>
                    <input type="hidden" name="day_note_id" value="<?= (int)$dayCoachNote['id'] ?>">
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row g-3">
    <?php foreach (foodDiaryMealTypes() as $mealType => $meta): ?>
        <?php
        $mealBlock = $mealBlocks[$mealType] ?? ['meal' => null, 'items' => [], 'coach_note' => null];
        $meal = $mealBlock['meal'];
        $items = $mealBlock['items'];
        $coachNote = $mealBlock['coach_note'];
        $isSkipped = ((int)($meal['skipped'] ?? 0) === 1);
        ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
                        <h5 class="mb-0"><i class="fas <?= h((string)$meta['icon']) ?> text-warning me-2"></i><?= h((string)$meta['label']) ?></h5>
                        <?php if (!$meal): ?>
                        <span class="badge bg-light text-dark border">Zatím bez záznamu</span>
                        <?php elseif ($isSkipped): ?>
                        <span class="badge bg-secondary">Jídlo vynecháno</span>
                        <?php else: ?>
                        <span class="badge bg-success">Záznam vyplněn</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($meal && !empty($meal['meal_time'])): ?>
                    <div class="small text-muted mb-2"><strong>Čas:</strong> <?= h(substr((string)$meal['meal_time'], 0, 5)) ?></div>
                    <?php endif; ?>

                    <?php if ($meal && !$isSkipped && !empty($items)): ?>
                    <ul class="mb-2">
                        <?php foreach ($items as $item): ?>
                        <li>
                            <strong><?= h((string)$item['food_name']) ?></strong>
                            <?php if ($item['quantity'] !== null): ?>
                                - <?= h(number_format((float)$item['quantity'], 2, ',', '')) ?>
                                <?= h((string)($item['unit'] ?? '')) ?>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if ($meal && !empty($meal['photo'])): ?>
                    <div class="mb-2">
                        <img src="<?= h(photoUrl((string)$meal['photo'], 'food_diary')) ?>" alt="Foto jídla" class="img-fluid rounded border" style="max-height:160px;object-fit:cover;">
                    </div>
                    <?php endif; ?>

                    <?php if ($meal && !empty($meal['athlete_note'])): ?>
                    <div class="small mb-2"><strong>Poznámka sportovce:</strong> <?= nl2br(h((string)$meal['athlete_note'])) ?></div>
                    <?php endif; ?>

                    <?php if ($meal): ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_meal_note">
                        <input type="hidden" name="selected_date" value="<?= h($selectedDate) ?>">
                        <input type="hidden" name="month" value="<?= h($monthParam) ?>">
                        <input type="hidden" name="meal_id" value="<?= (int)$meal['id'] ?>">
                        <label class="form-label small mb-1 fw-semibold">💬 Poznámka trenéra</label>
                        <textarea name="note" rows="2" class="form-control form-control-sm" placeholder="Napište poznámku ke konkrétnímu jídlu..."><?= h((string)($coachNote['note'] ?? '')) ?></textarea>
                        <div class="d-flex gap-2 mt-2">
                            <button type="submit" class="btn btn-primary btn-sm">Uložit poznámku</button>
                            <?php if (!empty($coachNote['id'])): ?>
                            <input type="hidden" name="note_id" value="<?= (int)$coachNote['id'] ?>">
                            <button type="submit" name="action" value="delete_meal_note" class="btn btn-outline-danger btn-sm" onclick="return confirm('Smazat poznámku trenéra?')">
                                Smazat poznámku
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php renderFooter();
