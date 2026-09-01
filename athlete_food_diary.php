<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';
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

$coachId = (int)$athlete['coach_id'];
$todayDate = foodDiaryEffectiveToday($pdo);
$schemaHealth = foodDiarySchemaHealth($pdo);

$selectedDate = foodDiaryResolveSelectedDate((string)($_GET['date'] ?? $todayDate), $todayDate);
$monthStart = foodDiaryResolveMonthStart($selectedDate);
$monthParam = $monthStart->format('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$schemaHealth['ok']) {
        $missingText = implode(', ', $schemaHealth['missing']);
        flash('danger', 'Strava není připravená v databázi. Chybí: ' . $missingText);
        redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($selectedDate) . '&month=' . urlencode($monthParam));
    }

    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($selectedDate) . '&month=' . urlencode($monthParam));
    }

    $action = (string)($_POST['action'] ?? '');
    $postedDate = foodDiaryResolveSelectedDate((string)($_POST['selected_date'] ?? $selectedDate), $todayDate);
    $postedMonth = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['month'] ?? '')) === 1
        ? (string)$_POST['month']
        : $monthParam;

    if (!foodDiaryValidateDate($postedDate) || foodDiaryIsFutureDate($postedDate, $todayDate)) {
        flash('danger', 'Do budoucího data nelze přidávat záznamy stravy.');
        redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($selectedDate) . '&month=' . urlencode($monthParam));
    }

    if ($action === 'save_meal') {
        $mealType = (string)($_POST['meal_type'] ?? '');
        $mealTypes = foodDiaryMealTypes();
        if (!isset($mealTypes[$mealType])) {
            flash('danger', 'Neplatný typ jídla.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        $mealTimeRaw = (string)($_POST['meal_time'] ?? '');
        $mealTime = foodDiaryNormalizeTimeOrNull($mealTimeRaw);
        if (trim($mealTimeRaw) !== '' && $mealTime === null) {
            flash('danger', 'Zadejte platný čas jídla ve formátu HH:MM nebo nechte pole prázdné.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        $athleteNote = trim((string)($_POST['athlete_note'] ?? ''));
        $athleteNote = $athleteNote !== '' ? mb_substr($athleteNote, 0, 4000, 'UTF-8') : '';
        $skipped = !empty($_POST['skipped']) ? 1 : 0;

        $itemNames = $_POST['item_name'] ?? [];
        $itemQuantities = $_POST['item_quantity'] ?? [];
        $itemUnits = $_POST['item_unit'] ?? [];
        if (!is_array($itemNames) || !is_array($itemQuantities) || !is_array($itemUnits)) {
            $itemNames = [];
            $itemQuantities = [];
            $itemUnits = [];
        }

        $normalizedItems = [];
        $allowedUnits = foodDiaryUnits();
        foreach ($itemNames as $index => $itemNameRaw) {
            $name = trim((string)$itemNameRaw);
            if ($name === '') {
                continue;
            }

            $name = mb_substr($name, 0, 255, 'UTF-8');
            $qtyRaw = (string)($itemQuantities[$index] ?? '');
            $qtyNormalized = foodDiaryNormalizeQuantityOrNull($qtyRaw);
            if (trim($qtyRaw) !== '' && $qtyNormalized === null) {
                flash('danger', 'Množství položky musí být číslo (celé nebo desetinné).');
                redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
            }

            $unitRaw = trim((string)($itemUnits[$index] ?? ''));
            $unit = $unitRaw !== '' ? mb_substr($unitRaw, 0, 50, 'UTF-8') : null;
            if ($unit !== null && !in_array($unit, $allowedUnits, true)) {
                $unit = 'jiná';
            }

            $normalizedItems[] = [
                'name' => $name,
                'quantity' => $qtyNormalized,
                'unit' => $unit,
            ];
        }

        if ($skipped === 0 && empty($normalizedItems) && $athleteNote === '' && empty($_FILES['meal_photo']['name']) && trim($mealTimeRaw) === '') {
            flash('danger', 'Doplňte alespoň jednu položku jídla nebo označte jídlo jako vynechané.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        $pdo->beginTransaction();
        try {
            $dayId = foodDiaryGetOrCreateDayId($pdo, $athleteId, $postedDate);

            $mealStmt = $pdo->prepare(
                'SELECT *
                 FROM food_diary_meals
                 WHERE day_id = ? AND meal_type = ?
                 LIMIT 1'
            );
            $mealStmt->execute([$dayId, $mealType]);
            $meal = $mealStmt->fetch();
            $mealId = (int)($meal['id'] ?? 0);

            if ($mealId <= 0) {
                $insertMeal = $pdo->prepare(
                    'INSERT INTO food_diary_meals (day_id, meal_type, meal_time, skipped, athlete_note, photo, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NULL, NOW(), NOW())'
                );
                $insertMeal->execute([$dayId, $mealType, $mealTime, $skipped, $athleteNote !== '' ? $athleteNote : null]);
                $mealId = (int)$pdo->lastInsertId();
                $meal = ['photo' => null];
            }

            $photo = (string)($meal['photo'] ?? '');
            if (!empty($_POST['remove_photo']) && $photo !== '') {
                deleteUploadedPhoto($photo, 'food_diary');
                $photo = '';
            }

            $newPhoto = resizeAndSavePhoto('meal_photo', 'food_diary');
            if ($newPhoto !== null) {
                if ($photo !== '') {
                    deleteUploadedPhoto($photo, 'food_diary');
                }
                $photo = $newPhoto;
            }

            $updateMeal = $pdo->prepare(
                'UPDATE food_diary_meals
                 SET meal_time = ?,
                     skipped = ?,
                     athlete_note = ?,
                     photo = ?,
                     updated_at = NOW()
                 WHERE id = ?'
            );
            $updateMeal->execute([
                $mealTime,
                $skipped,
                $athleteNote !== '' ? $athleteNote : null,
                $photo !== '' ? $photo : null,
                $mealId,
            ]);

            $pdo->prepare('DELETE FROM food_diary_items WHERE meal_id = ?')->execute([$mealId]);

            if ($skipped === 0) {
                $insertItem = $pdo->prepare(
                    'INSERT INTO food_diary_items (meal_id, food_name, quantity, unit, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NOW(), NOW())'
                );
                foreach ($normalizedItems as $item) {
                    $insertItem->execute([
                        $mealId,
                        $item['name'],
                        $item['quantity'] !== null ? number_format($item['quantity'], 2, '.', '') : null,
                        $item['unit'],
                    ]);
                }
            }

            $pdo->prepare('UPDATE food_diary_days SET updated_at = NOW() WHERE id = ?')->execute([$dayId]);
            $pdo->commit();

            flash('success', 'Jídlo bylo uloženo.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Food diary save_meal error: ' . $e->getMessage());
            $msg = 'Záznam jídla se nepodařilo uložit.';
            $errorText = mb_strtolower((string)$e->getMessage(), 'UTF-8');
            if (strpos($errorText, 'food_diary_') !== false
                || strpos($errorText, 'base table or view not found') !== false
                || strpos($errorText, 'unknown column') !== false
                || strpos($errorText, 'data truncated for column') !== false
            ) {
                $msg = 'Uložení selhalo kvůli nekompletnímu DB schématu modulu Strava. Spusťte prosím migraci Strava znovu.';
            }
            flash('danger', $msg);
        }

        redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }

    if ($action === 'save_hydration_entry') {
        $hydrationAmountRaw = trim((string)($_POST['hydration_amount'] ?? ''));
        $hydrationUnit = (string)($_POST['hydration_unit'] ?? 'ml');
        $customDrinkNameRaw = trim((string)($_POST['custom_drink_name'] ?? ''));
        $customDrinkName = '';
        $drinkTypesRaw = $_POST['drink_types'] ?? [];
        $drinkTypesRaw = is_array($drinkTypesRaw) ? $drinkTypesRaw : [];
        $allowedDrinkTypes = foodDiaryHydrationTypes();
        $drinkTypes = [];
        foreach ($drinkTypesRaw as $drinkType) {
            $type = (string)$drinkType;
            if (isset($allowedDrinkTypes[$type]) && !in_array($type, $drinkTypes, true)) {
                $drinkTypes[] = $type;
            }
        }

        if (empty($drinkTypes)) {
            flash('danger', 'Vyberte alespoň jeden druh pití.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        if (!in_array($hydrationUnit, ['ml', 'l'], true)) {
            $hydrationUnit = 'ml';
        }

        $hydrationAmount = foodDiaryNormalizeQuantityOrNull($hydrationAmountRaw);
        if ($hydrationAmount === null || $hydrationAmount <= 0) {
            flash('danger', 'Množství tekutin musí být kladné číslo (např. 250 nebo 0,3).');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        $hydrationMl = $hydrationUnit === 'l'
            ? (int)round($hydrationAmount * 1000)
            : (int)round($hydrationAmount);
        if ($hydrationMl < 10 || $hydrationMl > 5000) {
            flash('danger', 'Jedna dávka pití musí být v rozmezí 10 až 5000 ml.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        if (in_array('custom', $drinkTypes, true)) {
            $customDrinkName = mb_substr($customDrinkNameRaw, 0, 120, 'UTF-8');
            if ($customDrinkName === '') {
                flash('danger', 'Pro volbu Vlastní zadejte název nápoje.');
                redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
            }
        }

        try {
            $dayId = foodDiaryGetOrCreateDayId($pdo, $athleteId, $postedDate);
            $insert = $pdo->prepare(
                'INSERT INTO food_diary_hydration_entries (day_id, drink_type, custom_name, amount_ml, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            foreach ($drinkTypes as $drinkType) {
                $insert->execute([$dayId, $drinkType, $drinkType === 'custom' ? $customDrinkName : null, $hydrationMl]);
            }

            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_ml), 0) FROM food_diary_hydration_entries WHERE day_id = ?');
            $sumStmt->execute([$dayId]);
            $totalMl = (int)$sumStmt->fetchColumn();
            $pdo->prepare('UPDATE food_diary_days SET hydration_ml = ?, updated_at = NOW() WHERE id = ?')->execute([$totalMl, $dayId]);

            flash('success', 'Dávka pití byla přidána.');
        } catch (Throwable $e) {
            error_log('Food diary save_hydration_entry error: ' . $e->getMessage());
            $msg = 'Dávku pití se nepodařilo uložit.';
            $errorText = mb_strtolower((string)$e->getMessage(), 'UTF-8');
            if (strpos($errorText, 'food_diary_') !== false
                || strpos($errorText, 'base table or view not found') !== false
                || strpos($errorText, 'unknown column') !== false
            ) {
                $msg = 'Uložení pitného režimu selhalo kvůli nekompletnímu DB schématu modulu Strava. Spusťte prosím migraci Strava znovu.';
            }
            flash('danger', $msg);
        }

        redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }

    if ($action === 'delete_hydration_entry') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        if ($entryId <= 0) {
            flash('danger', 'Záznam pití nebyl nalezen.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        try {
            $dayId = foodDiaryGetOrCreateDayId($pdo, $athleteId, $postedDate);
            $delete = $pdo->prepare(
                'DELETE e
                 FROM food_diary_hydration_entries e
                 JOIN food_diary_days d ON d.id = e.day_id
                 WHERE e.id = ?
                   AND e.day_id = ?
                   AND d.athlete_id = ?'
            );
            $delete->execute([$entryId, $dayId, $athleteId]);

            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_ml), 0) FROM food_diary_hydration_entries WHERE day_id = ?');
            $sumStmt->execute([$dayId]);
            $totalMl = (int)$sumStmt->fetchColumn();
            $pdo->prepare('UPDATE food_diary_days SET hydration_ml = ?, updated_at = NOW() WHERE id = ?')->execute([$totalMl, $dayId]);

            flash('success', 'Dávka pití byla smazána.');
        } catch (Throwable $e) {
            error_log('Food diary delete_hydration_entry error: ' . $e->getMessage());
            flash('danger', 'Dávku pití se nepodařilo smazat.');
        }

        redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }

    if ($action === 'delete_meal') {
        $mealId = (int)($_POST['meal_id'] ?? 0);

        if ($mealId <= 0) {
            flash('danger', 'Záznam jídla nebyl nalezen.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        $mealStmt = $pdo->prepare(
            'SELECT m.id, m.photo
             FROM food_diary_meals m
             JOIN food_diary_days d ON d.id = m.day_id
             WHERE m.id = ?
               AND d.athlete_id = ?
               AND d.date = ?
             LIMIT 1'
        );
        $mealStmt->execute([$mealId, $athleteId, $postedDate]);
        $meal = $mealStmt->fetch();

        if (!$meal) {
            flash('danger', 'Záznam jídla nebyl nalezen.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        try {
            $photo = (string)($meal['photo'] ?? '');
            if ($photo !== '') {
                deleteUploadedPhoto($photo, 'food_diary');
            }

            $pdo->prepare('DELETE FROM food_diary_meals WHERE id = ?')->execute([$mealId]);
            flash('success', 'Záznam jídla byl smazán.');
        } catch (Throwable $e) {
            flash('danger', 'Záznam jídla se nepodařilo smazat.');
        }

        redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }

    if ($action === 'save_custom_activity') {
        $activityId = (int)($_POST['activity_id'] ?? 0);
        $activityType = (string)($_POST['activity_type'] ?? 'other');
        $activityName = trim((string)($_POST['activity_name'] ?? ''));
        $activityTimeRaw = (string)($_POST['activity_time'] ?? '');
        $durationRaw = trim((string)($_POST['duration_minutes'] ?? ''));
        $distanceRaw = trim((string)($_POST['distance_km'] ?? ''));
        $activityNote = trim((string)($_POST['activity_note'] ?? ''));

        if (!isset(foodDiaryActivityTypes()[$activityType])) {
            $activityType = 'other';
        }

        $activityTime = foodDiaryNormalizeTimeOrNull($activityTimeRaw);
        if (trim($activityTimeRaw) !== '' && $activityTime === null) {
            flash('danger', 'Čas aktivity musí být ve formátu HH:MM nebo prázdný.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        $duration = null;
        if ($durationRaw !== '') {
            if (!ctype_digit($durationRaw)) {
                flash('danger', 'Délka aktivity musí být celé číslo v minutách.');
                redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
            }
            $duration = (int)$durationRaw;
        }

        $distance = null;
        if ($distanceRaw !== '') {
            $distanceNormalized = foodDiaryNormalizeQuantityOrNull($distanceRaw);
            if ($distanceNormalized === null) {
                flash('danger', 'Vzdálenost musí být číslo (např. 5,2).');
                redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
            }
            $distance = $distanceNormalized;
        }

        $activityName = $activityName !== '' ? mb_substr($activityName, 0, 255, 'UTF-8') : '';
        $activityNote = $activityNote !== '' ? mb_substr($activityNote, 0, 2000, 'UTF-8') : '';

        $dayId = foodDiaryGetOrCreateDayId($pdo, $athleteId, $postedDate);

        try {
            if ($activityId > 0) {
                $update = $pdo->prepare(
                    'UPDATE food_diary_custom_activities a
                     JOIN food_diary_days d ON d.id = a.day_id
                     SET a.activity_type = ?,
                         a.activity_name = ?,
                         a.activity_time = ?,
                         a.duration_minutes = ?,
                         a.distance_km = ?,
                         a.note = ?,
                         a.updated_at = NOW()
                     WHERE a.id = ?
                       AND d.id = ?
                       AND d.athlete_id = ?'
                );
                $update->execute([
                    $activityType,
                    $activityName !== '' ? $activityName : null,
                    $activityTime,
                    $duration,
                    $distance !== null ? number_format($distance, 2, '.', '') : null,
                    $activityNote !== '' ? $activityNote : null,
                    $activityId,
                    $dayId,
                    $athleteId,
                ]);
                flash('success', 'Aktivita byla upravena.');
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO food_diary_custom_activities
                     (day_id, activity_type, activity_name, activity_time, duration_minutes, distance_km, note, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                );
                $insert->execute([
                    $dayId,
                    $activityType,
                    $activityName !== '' ? $activityName : null,
                    $activityTime,
                    $duration,
                    $distance !== null ? number_format($distance, 2, '.', '') : null,
                    $activityNote !== '' ? $activityNote : null,
                ]);
                flash('success', 'Vlastní aktivita byla přidána.');
            }
        } catch (Throwable $e) {
            error_log('Food diary save_custom_activity error: ' . $e->getMessage());
            $msg = 'Aktivitu se nepodařilo uložit.';
            $errorText = mb_strtolower((string)$e->getMessage(), 'UTF-8');
            if (strpos($errorText, 'food_diary_') !== false
                || strpos($errorText, 'base table or view not found') !== false
                || strpos($errorText, 'unknown column') !== false
            ) {
                $msg = 'Uložení aktivity selhalo kvůli nekompletnímu DB schématu modulu Strava. Spusťte prosím migraci Strava znovu.';
            }
            flash('danger', $msg);
        }

        redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }

    if ($action === 'delete_custom_activity') {
        $activityId = (int)($_POST['activity_id'] ?? 0);
        if ($activityId <= 0) {
            flash('danger', 'Aktivita nebyla nalezena.');
            redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
        }

        $delete = $pdo->prepare(
            'DELETE a
             FROM food_diary_custom_activities a
             JOIN food_diary_days d ON d.id = a.day_id
             WHERE a.id = ?
               AND d.athlete_id = ?
               AND d.date = ?'
        );
        $delete->execute([$activityId, $athleteId, $postedDate]);
        flash('success', 'Aktivita byla smazána.');

        redirect(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($postedDate) . '&month=' . urlencode($postedMonth));
    }
}

$day = foodDiaryFindDay($pdo, $athleteId, $selectedDate);
$dayId = (int)($day['id'] ?? 0);
$hydrationEntries = [];
$hydrationMl = isset($day['hydration_ml']) && $day['hydration_ml'] !== null ? (int)$day['hydration_ml'] : 0;
$mealBlocks = [];
$dayCoachNote = null;
$customActivities = [];
if ($dayId > 0) {
    $mealBlocks = foodDiaryLoadMeals($pdo, $dayId, $coachId);
    $dayCoachNote = foodDiaryLoadDayNote($pdo, $dayId, $coachId);
    $customActivities = foodDiaryLoadCustomActivities($pdo, $dayId);
    $hydrationEntries = foodDiaryLoadHydrationEntries($pdo, $dayId);
    $hydrationMl = 0;
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

$dailyMealSummary = [];
$loggedMealCount = 0;
foreach (foodDiaryMealTypes() as $mealType => $meta) {
    $mealBlock = $mealBlocks[$mealType] ?? ['meal' => null, 'items' => [], 'coach_note' => null];
    $meal = $mealBlock['meal'];
    $items = $mealBlock['items'];
    $isSkipped = ((int)($meal['skipped'] ?? 0) === 1);
    $hasItems = !empty($items);
    $hasAnyContent = $meal && ($isSkipped || $hasItems || !empty($meal['athlete_note']) || !empty($meal['photo']));
    if ($hasAnyContent) {
        $loggedMealCount++;
    }

    $preview = [];
    foreach (array_slice($items, 0, 3) as $item) {
        $line = (string)($item['food_name'] ?? '');
        if ($item['quantity'] !== null) {
            $line .= ' - ' . rtrim(rtrim(number_format((float)$item['quantity'], 2, ',', ''), '0'), ',');
            if (!empty($item['unit'])) {
                $line .= ' ' . (string)$item['unit'];
            }
        }
        $preview[] = $line;
    }

    $dailyMealSummary[$mealType] = [
        'label' => (string)$meta['label'],
        'icon' => (string)$meta['icon'],
        'status' => !$meal ? 'empty' : ($isSkipped ? 'skipped' : ($hasItems ? 'filled' : 'partial')),
        'preview' => $preview,
        'items_count' => count($items),
    ];
}

$dayFillPercent = (int)round(($loggedMealCount / 6) * 100);

renderAthleteHeader('Strava', false, true);
?>

<style>
    .food-diary-layout {
        align-items: flex-start;
    }

    .food-diary-side {
        position: sticky;
        top: 88px;
    }

    .food-diary-progress {
        height: 10px;
        background: #eef2f7;
        border-radius: 999px;
        overflow: hidden;
    }

    .food-diary-progress > span {
        display: block;
        height: 100%;
        background: linear-gradient(90deg, #f59e0b 0%, #22c55e 100%);
    }

    .food-diary-side-list {
        display: grid;
        gap: .6rem;
    }

    .food-diary-side-item {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: .65rem .7rem;
        text-decoration: none;
        color: inherit;
        background: #fff;
        display: block;
    }

    .food-diary-side-item:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
    }

    .food-diary-side-item--filled {
        border-left: 4px solid #16a34a;
    }

    .food-diary-side-item--skipped {
        border-left: 4px solid #64748b;
    }

    .food-diary-side-item--empty {
        border-left: 4px solid #e5e7eb;
    }

    .food-diary-side-item__title {
        font-size: .92rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
    }

    .food-diary-side-item__meta {
        font-size: .78rem;
        color: #64748b;
        margin-top: .2rem;
    }

    .food-diary-meal-card--saved {
        border-left: 4px solid #16a34a;
    }

    .food-diary-meal-card--skipped {
        border-left: 4px solid #64748b;
    }

    .food-diary-field--saved {
        font-weight: 700;
        color: #111827;
    }

    .food-diary-hydration-history {
        max-height: 220px;
        overflow: auto;
    }

    @media (max-width: 991.98px) {
        .food-diary-side {
            position: static;
            top: auto;
        }
    }

    @media (max-width: 575.98px) {
        .food-diary-calendar-wrap {
            overflow-x: auto;
        }

        .food-diary-calendar-table {
            table-layout: fixed;
            width: 100%;
            min-width: 100%;
            margin-bottom: 0;
        }

        .food-diary-calendar-table thead th {
            font-size: .66rem;
            padding: .32rem .1rem;
            white-space: nowrap;
        }

        .food-diary-calendar-table tbody td {
            padding: .3rem .12rem;
            min-height: 46px;
            vertical-align: top;
        }

        .food-diary-calendar-table tbody td .small {
            font-size: .68rem;
            line-height: 1.15;
        }

        .food-diary-calendar-table tbody td a {
            padding: .1rem 0;
        }

        .food-diary-hydration-history {
            max-height: 180px;
        }
    }
</style>

<?php if (!$schemaHealth['ok']): ?>
<div class="alert alert-danger">
    <div class="fw-semibold mb-1">Strava není připravená v databázi</div>
    <div class="small">Chybějící části schématu: <?= h(implode(', ', $schemaHealth['missing'])) ?></div>
    <div class="small mt-1">Spusťte migraci modulu Strava a stránku obnovte.</div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h2 class="mb-0"><i class="fas fa-bowl-food text-warning me-2"></i>Strava</h2>
        <small class="text-muted">Skutečně snědená strava - historie i dnešek</small>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-end">
        <form method="get" action="<?= BASE_URL ?>/athlete_food_diary_export_pdf.php" target="_blank" class="d-flex gap-2 flex-wrap align-items-end" id="foodDiaryExportFormPdf">
            <input type="hidden" name="date" value="<?= h($selectedDate) ?>">
            <div>
                <label class="form-label small mb-1">Období exportu</label>
                <select name="range" class="form-select form-select-sm js-export-range">
                    <option value="day">Aktuální den</option>
                    <option value="week">Aktuální týden</option>
                    <option value="month">Aktuální měsíc</option>
                    <option value="custom">Vlastní období</option>
                </select>
            </div>
            <div class="js-export-custom d-none">
                <label class="form-label small mb-1">Od</label>
                <input type="date" name="from" class="form-control form-control-sm" max="<?= h($todayDate) ?>">
            </div>
            <div class="js-export-custom d-none">
                <label class="form-label small mb-1">Do</label>
                <input type="date" name="to" class="form-control form-control-sm" max="<?= h($todayDate) ?>">
            </div>
            <button type="submit" class="btn btn-outline-dark btn-sm">
                <i class="fas fa-file-pdf me-1"></i>Export PDF
            </button>
            <button type="submit" formaction="<?= BASE_URL ?>/athlete_food_diary_export_excel.php" formtarget="_self" class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i>Export Excel
            </button>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8 order-2 order-lg-1">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="mb-0"><i class="fas fa-calendar-days me-2 text-primary"></i><?= h($monthTitle) ?></h5>
                    <div class="d-flex gap-2">
                        <a href="<?= BASE_URL ?>/athlete_food_diary.php?month=<?= urlencode($previousMonth) ?>&date=<?= urlencode($selectedDate) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <a href="<?= BASE_URL ?>/athlete_food_diary.php?month=<?= urlencode($todayMonth) ?>&date=<?= urlencode($todayDate) ?>" class="btn btn-outline-primary btn-sm">Dnes</a>
                        <?php if ($canGoNextMonth): ?>
                        <a href="<?= BASE_URL ?>/athlete_food_diary.php?month=<?= urlencode($nextMonth) ?>&date=<?= urlencode($selectedDate) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive food-diary-calendar-wrap">
                    <table class="table table-bordered align-middle text-center mb-0 food-diary-calendar-table no-mobile-stack">
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
                                            echo '<a class="text-decoration-none d-block" href="' . h(BASE_URL . '/athlete_food_diary.php?date=' . urlencode($cellDate) . '&month=' . urlencode($monthParam)) . '">';
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

                <div class="small text-muted mt-2">⚪ bez záznamu • 🟡 částečně vyplněno • 🟢 6/6 jídel</div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 order-1 order-lg-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-3"><i class="fas fa-chart-pie me-2 text-success"></i>Týdenní přehled</h5>
                <div class="small text-muted mb-2">
                    <?= h(formatDate($weekSummary['week_start'])) ?> - <?= h(formatDate($weekSummary['week_end'])) ?>
                </div>
                <?php foreach ($weekSummary['days'] as $dayStat): ?>
                    <?php
                    $icon = $dayStat['status'] === 'full' ? '🟢' : ($dayStat['status'] === 'partial' ? '🟡' : '🔴');
                    ?>
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

<div class="d-lg-none mb-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="fas fa-list-check me-2 text-success"></i>Dnešní přehled</h6>
                <span class="badge bg-dark"><?= (int)$loggedMealCount ?>/6</span>
            </div>
            <div class="food-diary-progress mb-3" aria-label="Denní vyplněnost">
                <span style="width: <?= (int)$dayFillPercent ?>%"></span>
            </div>
            <div class="small text-muted mb-3"><?= (int)$dayFillPercent ?> % vyplněno</div>
            <div class="small mb-3">
                <i class="fas fa-glass-water me-1 text-primary"></i>
                Vypito: <strong><?= $hydrationMl > 0 ? h(number_format($hydrationMl / 1000, 2, ',', '')) . ' l' : 'nezadáno' ?></strong>
            </div>

            <div class="food-diary-side-list">
                <?php foreach ($dailyMealSummary as $mealType => $summary): ?>
                    <?php
                    $status = (string)$summary['status'];
                    $statusLabel = $status === 'filled'
                        ? 'Vyplněno'
                        : ($status === 'skipped' ? 'Vynecháno' : ($status === 'partial' ? 'Rozpracováno' : 'Bez záznamu'));
                    $statusIcon = $status === 'filled'
                        ? '🟢'
                        : ($status === 'skipped' ? '⚪' : ($status === 'partial' ? '🟡' : '⚪'));
                    ?>
                    <a href="#meal-card-<?= h($mealType) ?>" class="food-diary-side-item food-diary-side-item--<?= h($status) ?>">
                        <div class="food-diary-side-item__title">
                            <span><i class="fas <?= h((string)$summary['icon']) ?> me-1 text-warning"></i><?= h((string)$summary['label']) ?></span>
                            <span><?= $statusIcon ?></span>
                        </div>
                        <div class="food-diary-side-item__meta"><?= h($statusLabel) ?><?php if ((int)$summary['items_count'] > 0): ?> · <?= (int)$summary['items_count'] ?> položek<?php endif; ?></div>
                        <?php if (!empty($summary['preview'])): ?>
                            <div class="food-diary-side-item__meta mt-1"><?= h(implode(' | ', $summary['preview'])) ?></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h4 class="mb-2"><?= h(foodDiaryFormatCzDateTitle($selectedDate)) ?></h4>
        <div class="small text-muted mb-3">Datum deníku: <?= h(formatDate($selectedDate)) ?></div>

        <div class="card border mb-3">
            <div class="card-body py-2">
                <form method="post" class="row g-1 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_hydration_entry">
                    <input type="hidden" name="selected_date" value="<?= h($selectedDate) ?>">
                    <input type="hidden" name="month" value="<?= h($monthParam) ?>">
                    <div class="col-12">
                        <label class="form-label small mb-1"><i class="fas fa-glass-water me-1 text-primary"></i>Druh pití (můžete vybrat více)</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach (foodDiaryHydrationTypes() as $drinkType => $drinkLabel): ?>
                            <div class="form-check form-check-inline me-2 mb-1">
                                <input class="form-check-input" type="checkbox" name="drink_types[]" value="<?= h($drinkType) ?>" id="drink-type-<?= h($drinkType) ?>">
                                <label class="form-check-label small" for="drink-type-<?= h($drinkType) ?>"><?= h($drinkLabel) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12 d-none" id="customDrinkNameWrap">
                        <label class="form-label small mb-1">Vlastní název nápoje</label>
                        <input type="text" name="custom_drink_name" id="customDrinkNameInput" class="form-control form-control-sm" placeholder="např. Ionťák citron">
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label small mb-1">Množství dávky</label>
                        <input type="text" name="hydration_amount" class="form-control form-control-sm" value="250" placeholder="např. 250 nebo 0,3">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small mb-1">Jednotka dávky</label>
                        <select name="hydration_unit" class="form-select form-select-sm">
                            <option value="ml" selected>ml</option>
                            <option value="l">l</option>
                        </select>
                    </div>
                    <div class="col-sm-4 d-grid">
                        <button type="submit" class="btn btn-primary btn-sm">Přidat dávku</button>
                    </div>
                </form>
                <?php if (!empty($hydrationEntries)): ?>
                <div class="small text-muted mt-2">
                    Uloženo průběžně: <strong><?= h(number_format($hydrationMl / 1000, 2, ',', '')) ?> l</strong> (<?= (int)$hydrationMl ?> ml)
                    · Dávek: <strong><?= count($hydrationEntries) ?></strong>
                </div>
                <details class="mt-2">
                    <summary class="small fw-semibold" style="cursor:pointer;">Historie dávek (<?= count($hydrationEntries) ?>)</summary>
                    <div class="mt-2 d-grid gap-2 food-diary-hydration-history pe-1">
                        <?php foreach ($hydrationEntries as $entry): ?>
                        <form method="post" class="d-flex align-items-center justify-content-between border rounded px-2 py-1 bg-light">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_hydration_entry">
                            <input type="hidden" name="selected_date" value="<?= h($selectedDate) ?>">
                            <input type="hidden" name="month" value="<?= h($monthParam) ?>">
                            <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                            <div class="small fw-bold">
                                <?= h((string)$entry['drink_label']) ?> - <?= (int)$entry['amount_ml'] ?> ml
                                <span class="text-muted fw-normal">(<?= h(date('H:i', strtotime((string)$entry['created_at']))) ?>)</span>
                            </div>
                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Smazat tuto dávku pití?')" title="Smazat dávku">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php else: ?>
                <div class="small text-muted mt-2">Zatím bez záznamu pitného režimu.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($autoActivities) || !empty($customActivities)): ?>
        <div class="mb-3">
            <h6 class="mb-2"><i class="fas fa-person-running me-2 text-primary"></i>Aktivita</h6>
            <?php foreach ($autoActivities as $activity): ?>
                <div class="alert alert-primary py-2 mb-2">
                    <div class="fw-semibold">🏋️ <?= h((string)$activity['title']) ?></div>
                    <div class="small"><?= h(date('H:i', strtotime((string)$activity['starts_at']))) ?> - <?= h(date('H:i', strtotime((string)$activity['ends_at']))) ?></div>
                    <?php if ((string)$activity['location'] !== ''): ?>
                    <div class="small text-muted"><i class="fas fa-location-dot me-1"></i><?= h((string)$activity['location']) ?></div>
                    <?php endif; ?>
                    <div class="small text-muted">Automaticky převzato z kalendáře TrainerApp.</div>
                </div>
            <?php endforeach; ?>

            <?php foreach ($customActivities as $customActivity): ?>
                <details class="border rounded p-2 mb-2 bg-light">
                    <summary class="fw-semibold"><?= h(foodDiaryActivityTypeLabel((string)$customActivity['activity_type'])) ?><?= !empty($customActivity['activity_name']) ? ' - ' . h((string)$customActivity['activity_name']) : '' ?></summary>
                    <div class="small mt-2">
                        <?php if (!empty($customActivity['activity_time'])): ?>
                        <div><strong>Čas:</strong> <?= h(substr((string)$customActivity['activity_time'], 0, 5)) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($customActivity['duration_minutes'])): ?>
                        <div><strong>Délka:</strong> <?= (int)$customActivity['duration_minutes'] ?> min</div>
                        <?php endif; ?>
                        <?php if ($customActivity['distance_km'] !== null): ?>
                        <div><strong>Vzdálenost:</strong> <?= h(number_format((float)$customActivity['distance_km'], 2, ',', '')) ?> km</div>
                        <?php endif; ?>
                        <?php if (!empty($customActivity['note'])): ?>
                        <div><strong>Poznámka:</strong> <?= nl2br(h((string)$customActivity['note'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="row g-2 mt-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_custom_activity">
                        <input type="hidden" name="selected_date" value="<?= h($selectedDate) ?>">
                        <input type="hidden" name="month" value="<?= h($monthParam) ?>">
                        <input type="hidden" name="activity_id" value="<?= (int)$customActivity['id'] ?>">
                        <div class="col-md-4">
                            <select name="activity_type" class="form-select form-select-sm">
                                <?php foreach (foodDiaryActivityTypes() as $typeKey => $typeLabel): ?>
                                <option value="<?= h($typeKey) ?>" <?= $typeKey === (string)$customActivity['activity_type'] ? 'selected' : '' ?>><?= h($typeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><input type="text" name="activity_name" class="form-control form-control-sm" placeholder="Název" value="<?= h((string)$customActivity['activity_name']) ?>"></div>
                        <div class="col-md-4"><input type="time" name="activity_time" class="form-control form-control-sm" value="<?= !empty($customActivity['activity_time']) ? h(substr((string)$customActivity['activity_time'], 0, 5)) : '' ?>"></div>
                        <div class="col-md-4"><input type="number" name="duration_minutes" class="form-control form-control-sm" min="0" placeholder="Délka (min)" value="<?= $customActivity['duration_minutes'] !== null ? (int)$customActivity['duration_minutes'] : '' ?>"></div>
                        <div class="col-md-4"><input type="text" name="distance_km" class="form-control form-control-sm" placeholder="Vzdálenost (km)" value="<?= $customActivity['distance_km'] !== null ? h(str_replace('.', ',', (string)$customActivity['distance_km'])) : '' ?>"></div>
                        <div class="col-md-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100">Uložit</button>
                            <button type="submit" formaction="<?= BASE_URL ?>/athlete_food_diary.php" name="action" value="delete_custom_activity" class="btn btn-outline-danger btn-sm w-100" onclick="return confirm('Opravdu smazat aktivitu?')">Smazat</button>
                        </div>
                        <div class="col-12"><textarea name="activity_note" rows="2" class="form-control form-control-sm" placeholder="Poznámka"><?= h((string)$customActivity['note']) ?></textarea></div>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <details class="mb-4">
            <summary class="btn btn-outline-primary btn-sm">+ Přidat aktivitu</summary>
            <form method="post" class="row g-2 mt-2">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_custom_activity">
                <input type="hidden" name="selected_date" value="<?= h($selectedDate) ?>">
                <input type="hidden" name="month" value="<?= h($monthParam) ?>">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Typ</label>
                    <select name="activity_type" class="form-select form-select-sm">
                        <?php foreach (foodDiaryActivityTypes() as $typeKey => $typeLabel): ?>
                        <option value="<?= h($typeKey) ?>"><?= h($typeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Název</label>
                    <input type="text" name="activity_name" class="form-control form-control-sm" placeholder="např. Běh v parku">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Čas</label>
                    <input type="time" name="activity_time" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Délka (min)</label>
                    <input type="number" min="0" name="duration_minutes" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Vzdálenost (km)</label>
                    <input type="text" name="distance_km" class="form-control form-control-sm" placeholder="např. 5,2">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Uložit aktivitu</button>
                </div>
                <div class="col-12">
                    <label class="form-label small mb-1">Poznámka</label>
                    <textarea name="activity_note" rows="2" class="form-control form-control-sm"></textarea>
                </div>
            </form>
        </details>

        <?php if ($dayCoachNote): ?>
        <div class="alert alert-info">
            <div class="fw-semibold"><i class="fas fa-comment-medical me-1"></i>Poznámka trenéra k celému dni</div>
            <div><?= nl2br(h((string)$dayCoachNote['note'])) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 food-diary-layout">
    <div class="col-lg-8">
        <div class="row g-3">
    <?php foreach (foodDiaryMealTypes() as $mealType => $meta): ?>
        <?php
        $mealBlock = $mealBlocks[$mealType] ?? ['meal' => null, 'items' => [], 'coach_note' => null];
        $meal = $mealBlock['meal'];
        $items = $mealBlock['items'];
        $coachNote = $mealBlock['coach_note'];
        $mealTimeValue = !empty($meal['meal_time']) ? substr((string)$meal['meal_time'], 0, 5) : '';
        $isSkipped = ((int)($meal['skipped'] ?? 0) === 1);
        $mealPhoto = (string)($meal['photo'] ?? '');
        $isSavedMeal = $meal && ($isSkipped || !empty($items) || !empty($meal['athlete_note']) || $mealPhoto !== '');
        ?>
        <div class="col-12">
            <div id="meal-card-<?= h($mealType) ?>" class="card border-0 shadow-sm <?= $isSavedMeal ? ($isSkipped ? 'food-diary-meal-card--skipped' : 'food-diary-meal-card--saved') : '' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
                        <h5 class="mb-0"><i class="fas <?= h((string)$meta['icon']) ?> text-warning me-2"></i><?= h((string)$meta['label']) ?></h5>
                        <?php if (!$meal): ?>
                        <span class="badge bg-light text-dark border">Zatím bez záznamu</span>
                        <?php elseif ($isSkipped): ?>
                        <span class="badge bg-secondary">Jídlo vynecháno</span>
                        <?php else: ?>
                        <span class="badge bg-success">Záznam uložen</span>
                        <?php endif; ?>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="food-diary-meal-form">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_meal">
                        <input type="hidden" name="selected_date" value="<?= h($selectedDate) ?>">
                        <input type="hidden" name="month" value="<?= h($monthParam) ?>">
                        <input type="hidden" name="meal_type" value="<?= h($mealType) ?>">

                        <div class="row g-2 mb-2">
                            <div class="col-sm-4">
                                <label class="form-label small mb-1">Čas (volitelné)</label>
                                <input type="time" name="meal_time" class="form-control form-control-sm" value="<?= h($mealTimeValue) ?>">
                            </div>
                            <div class="col-sm-8 d-flex align-items-end">
                                <div class="form-check mb-1">
                                    <input class="form-check-input js-skipped-toggle" type="checkbox" name="skipped" value="1" id="skip-<?= h($mealType) ?>" <?= $isSkipped ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="skip-<?= h($mealType) ?>">Jídlo jsem vynechal</label>
                                </div>
                            </div>
                        </div>

                        <div class="js-items-container" data-meal="<?= h($mealType) ?>">
                            <?php if (!empty($items)): ?>
                                <?php foreach ($items as $item): ?>
                                <div class="row g-2 align-items-end mb-2 js-item-row">
                                    <div class="col-md-5">
                                        <label class="form-label small mb-1">Název</label>
                                        <input type="text" name="item_name[]" class="form-control form-control-sm" value="<?= h((string)$item['food_name']) ?>" placeholder="např. Banán">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Množství</label>
                                        <input type="text" name="item_quantity[]" class="form-control form-control-sm" value="<?= $item['quantity'] !== null ? h(str_replace('.', ',', (string)$item['quantity'])) : '' ?>" placeholder="např. 120">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Jednotka</label>
                                        <select name="item_unit[]" class="form-select form-select-sm">
                                            <option value="">-</option>
                                            <?php foreach (foodDiaryUnits() as $unit): ?>
                                            <option value="<?= h($unit) ?>" <?= (string)($item['unit'] ?? '') === $unit ? 'selected' : '' ?>><?= h($unit) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-outline-danger btn-sm w-100 js-remove-item" title="Odstranit řádek"><i class="fas fa-xmark"></i></button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="row g-2 align-items-end mb-2 js-item-row">
                                    <div class="col-md-5">
                                        <label class="form-label small mb-1">Název</label>
                                        <input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="např. Banán">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Množství</label>
                                        <input type="text" name="item_quantity[]" class="form-control form-control-sm" placeholder="např. 120">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Jednotka</label>
                                        <select name="item_unit[]" class="form-select form-select-sm">
                                            <option value="">-</option>
                                            <?php foreach (foodDiaryUnits() as $unit): ?>
                                            <option value="<?= h($unit) ?>"><?= h($unit) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-outline-danger btn-sm w-100 js-remove-item" title="Odstranit řádek"><i class="fas fa-xmark"></i></button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <button type="button" class="btn btn-outline-primary btn-sm mb-3 js-add-item" data-meal="<?= h($mealType) ?>">
                            <i class="fas fa-plus me-1"></i>Přidat položku
                        </button>

                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">📷 Vyfotit / Přidat fotografii</label>
                                <input type="file" name="meal_photo" accept="image/*" capture="environment" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6">
                                <?php if ($mealPhoto !== ''): ?>
                                <img src="<?= h(photoUrl($mealPhoto, 'food_diary')) ?>" alt="Foto jídla" class="img-fluid rounded border" style="max-height:120px;object-fit:cover;">
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="remove-photo-<?= h($mealType) ?>" name="remove_photo" value="1">
                                    <label class="form-check-label small" for="remove-photo-<?= h($mealType) ?>">Odstranit fotografii</label>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small mb-1">Moje poznámka</label>
                            <textarea name="athlete_note" rows="2" class="form-control form-control-sm" placeholder="Např. Po tréninku jsem měl velký hlad."><?= h((string)($meal['athlete_note'] ?? '')) ?></textarea>
                        </div>

                        <?php if ($coachNote): ?>
                        <div class="alert alert-info py-2">
                            <div class="fw-semibold">💬 Poznámka trenéra</div>
                            <div><?= nl2br(h((string)$coachNote['note'])) ?></div>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-warning btn-sm fw-semibold">
                                <i class="fas fa-save me-1"></i>Uložit
                            </button>
                            <?php if ($meal): ?>
                            <input type="hidden" name="meal_id" value="<?= (int)$meal['id'] ?>">
                            <button type="submit" name="action" value="delete_meal" class="btn btn-outline-danger btn-sm" onclick="return confirm('Opravdu chcete tento záznam smazat?')">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
        </div>
    </div>

    <div class="col-lg-4 d-none d-lg-block">
        <div class="card border-0 shadow-sm food-diary-side">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><i class="fas fa-list-check me-2 text-success"></i>Dnešní přehled</h6>
                    <span class="badge bg-dark"><?= (int)$loggedMealCount ?>/6</span>
                </div>
                <div class="food-diary-progress mb-3" aria-label="Denní vyplněnost">
                    <span style="width: <?= (int)$dayFillPercent ?>%"></span>
                </div>
                <div class="small text-muted mb-3"><?= (int)$dayFillPercent ?> % vyplněno</div>
                <div class="small mb-3">
                    <i class="fas fa-glass-water me-1 text-primary"></i>
                    Vypito: <strong><?= $hydrationMl > 0 ? h(number_format($hydrationMl / 1000, 2, ',', '')) . ' l' : 'nezadáno' ?></strong>
                </div>

                <div class="food-diary-side-list">
                    <?php foreach ($dailyMealSummary as $mealType => $summary): ?>
                        <?php
                        $status = (string)$summary['status'];
                        $statusLabel = $status === 'filled'
                            ? 'Vyplněno'
                            : ($status === 'skipped' ? 'Vynecháno' : ($status === 'partial' ? 'Rozpracováno' : 'Bez záznamu'));
                        $statusIcon = $status === 'filled'
                            ? '🟢'
                            : ($status === 'skipped' ? '⚪' : ($status === 'partial' ? '🟡' : '⚪'));
                        ?>
                        <a href="#meal-card-<?= h($mealType) ?>" class="food-diary-side-item food-diary-side-item--<?= h($status) ?>">
                            <div class="food-diary-side-item__title">
                                <span><i class="fas <?= h((string)$summary['icon']) ?> me-1 text-warning"></i><?= h((string)$summary['label']) ?></span>
                                <span><?= $statusIcon ?></span>
                            </div>
                            <div class="food-diary-side-item__meta"><?= h($statusLabel) ?><?php if ((int)$summary['items_count'] > 0): ?> · <?= (int)$summary['items_count'] ?> položek<?php endif; ?></div>
                            <?php if (!empty($summary['preview'])): ?>
                                <div class="food-diary-side-item__meta mt-1"><?= h(implode(' | ', $summary['preview'])) ?></div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="foodDiaryItemRowTemplate">
    <div class="row g-2 align-items-end mb-2 js-item-row">
        <div class="col-md-5">
            <label class="form-label small mb-1">Název</label>
            <input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="např. Jogurt">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Množství</label>
            <input type="text" name="item_quantity[]" class="form-control form-control-sm" placeholder="např. 1,5">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Jednotka</label>
            <select name="item_unit[]" class="form-select form-select-sm">
                <option value="">-</option>
                <?php foreach (foodDiaryUnits() as $unit): ?>
                <option value="<?= h($unit) ?>"><?= h($unit) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm w-100 js-remove-item" title="Odstranit řádek"><i class="fas fa-xmark"></i></button>
        </div>
    </div>
</template>

<script>
(function () {
    const rowTemplate = document.getElementById('foodDiaryItemRowTemplate');

    const exportForm = document.getElementById('foodDiaryExportFormPdf');
    if (exportForm) {
        const rangeSelect = exportForm.querySelector('.js-export-range');
        const customFields = exportForm.querySelectorAll('.js-export-custom');
        const syncExportRange = () => {
            const showCustom = rangeSelect && rangeSelect.value === 'custom';
            customFields.forEach((field) => {
                field.classList.toggle('d-none', !showCustom);
                field.querySelectorAll('input').forEach((input) => {
                    input.required = showCustom;
                });
            });
        };
        if (rangeSelect) {
            rangeSelect.addEventListener('change', syncExportRange);
        }
        syncExportRange();
    }

    document.querySelectorAll('.js-add-item').forEach((button) => {
        button.addEventListener('click', () => {
            const mealKey = button.getAttribute('data-meal');
            const container = document.querySelector('.js-items-container[data-meal="' + mealKey + '"]');
            if (!container || !rowTemplate) {
                return;
            }
            const clone = rowTemplate.content.cloneNode(true);
            container.appendChild(clone);
        });
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        const button = target.closest('.js-remove-item');
        if (!button) {
            return;
        }

        const row = button.closest('.js-item-row');
        const container = button.closest('.js-items-container');
        if (!row || !container) {
            return;
        }

        if (container.querySelectorAll('.js-item-row').length <= 1) {
            row.querySelectorAll('input, select').forEach((input) => {
                input.value = '';
            });
            return;
        }

        row.remove();
    });

    document.querySelectorAll('.food-diary-meal-form').forEach((form) => {
        const skippedToggle = form.querySelector('.js-skipped-toggle');
        const itemsContainer = form.querySelector('.js-items-container');
        const addItemButton = form.querySelector('.js-add-item');

        if (!skippedToggle || !itemsContainer || !addItemButton) {
            return;
        }

        const syncState = () => {
            const disabled = skippedToggle.checked;
            itemsContainer.querySelectorAll('input, select, button').forEach((field) => {
                field.disabled = disabled;
            });
            addItemButton.disabled = disabled;
        };

        skippedToggle.addEventListener('change', syncState);
        syncState();
    });

    const customDrinkCheckbox = document.getElementById('drink-type-custom');
    const customDrinkWrap = document.getElementById('customDrinkNameWrap');
    const customDrinkInput = document.getElementById('customDrinkNameInput');
    const syncCustomDrinkField = () => {
        if (!customDrinkCheckbox || !customDrinkWrap || !customDrinkInput) {
            return;
        }

        const enabled = customDrinkCheckbox.checked;
        customDrinkWrap.classList.toggle('d-none', !enabled);
        customDrinkInput.required = enabled;
        if (!enabled) {
            customDrinkInput.value = '';
        }
    };
    if (customDrinkCheckbox) {
        customDrinkCheckbox.addEventListener('change', syncCustomDrinkField);
        syncCustomDrinkField();
    }

    // Existing persisted values are emphasized to improve quick scanning.
    const markSavedFields = () => {
        document.querySelectorAll('form input, form select, form textarea').forEach((field) => {
            if (field.type === 'hidden' || field.type === 'file' || field.type === 'password') {
                return;
            }

            let hasValue = false;
            if (field.type === 'checkbox' || field.type === 'radio') {
                hasValue = field.checked;
            } else {
                hasValue = String(field.value || '').trim() !== '';
            }

            field.classList.toggle('food-diary-field--saved', hasValue);
        });
    };

    document.addEventListener('input', markSavedFields);
    document.addEventListener('change', markSavedFields);
    markSavedFields();
})();
</script>

<?php renderAthleteFooter();
