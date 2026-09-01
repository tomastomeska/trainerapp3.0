<?php

if (!function_exists('foodDiaryMealTypes')) {
    function foodDiaryMealTypes(): array {
        return [
            'breakfast' => ['label' => 'Snídaně', 'icon' => 'fa-sun'],
            'morning_snack' => ['label' => 'Dopolední svačina', 'icon' => 'fa-apple-whole'],
            'lunch' => ['label' => 'Oběd', 'icon' => 'fa-bowl-food'],
            'afternoon_snack' => ['label' => 'Odpolední svačina', 'icon' => 'fa-cookie-bite'],
            'dinner' => ['label' => 'Večeře', 'icon' => 'fa-moon'],
            'second_dinner' => ['label' => 'Druhá večeře', 'icon' => 'fa-mug-hot'],
        ];
    }
}

if (!function_exists('foodDiarySchemaHealth')) {
    function foodDiarySchemaHealth(PDO $pdo): array {
        $required = [
            'food_diary_days' => ['id', 'athlete_id', 'date', 'hydration_ml'],
            'food_diary_meals' => ['id', 'day_id', 'meal_type', 'meal_time', 'skipped', 'athlete_note', 'photo'],
            'food_diary_items' => ['id', 'meal_id', 'food_name', 'quantity', 'unit'],
            'food_diary_coach_notes' => ['id', 'meal_id', 'day_id', 'coach_id', 'note'],
            'food_diary_custom_activities' => ['id', 'day_id', 'activity_type', 'activity_name', 'activity_time', 'duration_minutes', 'distance_km', 'note'],
            'food_diary_hydration_entries' => ['id', 'day_id', 'drink_type', 'custom_name', 'amount_ml', 'created_at'],
        ];

        $missing = [];
        foreach ($required as $table => $columns) {
            $tableStmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            $tableExists = $tableStmt !== false && (bool)$tableStmt->fetchColumn();
            if (!$tableExists) {
                $missing[] = $table;
                continue;
            }

            foreach ($columns as $column) {
                $colStmt = $pdo->query("SHOW COLUMNS FROM `" . $table . "` LIKE " . $pdo->quote($column));
                $colExists = $colStmt !== false && (bool)$colStmt->fetch();
                if (!$colExists) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }

        return [
            'ok' => empty($missing),
            'missing' => $missing,
        ];
    }
}

if (!function_exists('foodDiaryMealTypeLabel')) {
    function foodDiaryMealTypeLabel(string $mealType): string {
        $types = foodDiaryMealTypes();
        return (string)($types[$mealType]['label'] ?? $mealType);
    }
}

if (!function_exists('foodDiaryUnits')) {
    function foodDiaryUnits(): array {
        return ['g', 'kg', 'ml', 'l', 'ks', 'porce', 'plátek', 'kus', 'hrnek', 'lžíce', 'čajová lžička', 'jiná'];
    }
}

if (!function_exists('foodDiaryActivityTypes')) {
    function foodDiaryActivityTypes(): array {
        return [
            'run' => 'Běh',
            'walk' => 'Chůze',
            'bike' => 'Cyklistika',
            'strength' => 'Posilování',
            'swim' => 'Plavání',
            'other' => 'Jiná aktivita',
        ];
    }
}

if (!function_exists('foodDiaryActivityTypeLabel')) {
    function foodDiaryActivityTypeLabel(string $activityType): string {
        $types = foodDiaryActivityTypes();
        return (string)($types[$activityType] ?? 'Aktivita');
    }
}

if (!function_exists('foodDiaryHydrationTypes')) {
    function foodDiaryHydrationTypes(): array {
        return [
            'water' => 'Voda',
            'sweet_drink' => 'Sladký nápoj',
            'coffee' => 'Káva',
            'tea' => 'Čaj',
            'protein' => 'Protein',
            'beer' => 'Pivo',
            'spirits' => 'Tvrdý alkohol',
            'custom' => 'Vlastní',
        ];
    }
}

if (!function_exists('foodDiaryValidateDate')) {
    function foodDiaryValidateDate(string $date): bool {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    }
}

if (!function_exists('foodDiaryIsFutureDate')) {
    function foodDiaryIsFutureDate(string $date, ?string $todayDate = null): bool {
        if (!foodDiaryValidateDate($date)) {
            return false;
        }

        $selected = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$selected) {
            return false;
        }

        $todayBase = $todayDate;
        if ($todayBase === null || !foodDiaryValidateDate($todayBase)) {
            $todayBase = (new DateTimeImmutable('today'))->format('Y-m-d');
        }

        $today = DateTimeImmutable::createFromFormat('Y-m-d', $todayBase);
        if (!$today) {
            $today = new DateTimeImmutable('today');
        }
        return $selected > $today;
    }
}

if (!function_exists('foodDiaryEffectiveToday')) {
    function foodDiaryEffectiveToday(PDO $pdo): string {
        $phpToday = (new DateTimeImmutable('today'))->format('Y-m-d');
        $dbToday = $phpToday;

        try {
            $stmt = $pdo->query('SELECT CURDATE()');
            $value = $stmt !== false ? (string)$stmt->fetchColumn() : '';
            if (foodDiaryValidateDate($value)) {
                $dbToday = $value;
            }
        } catch (Throwable $e) {
            $dbToday = $phpToday;
        }

        return strcmp($dbToday, $phpToday) > 0 ? $dbToday : $phpToday;
    }
}

if (!function_exists('foodDiaryResolveSelectedDate')) {
    function foodDiaryResolveSelectedDate(?string $rawDate, ?string $todayDate = null): string {
        $today = ($todayDate !== null && foodDiaryValidateDate($todayDate))
            ? $todayDate
            : (new DateTimeImmutable('today'))->format('Y-m-d');
        if ($rawDate === null || !foodDiaryValidateDate($rawDate)) {
            return $today;
        }

        if (foodDiaryIsFutureDate($rawDate, $today)) {
            return $today;
        }

        return $rawDate;
    }
}

if (!function_exists('foodDiaryResolveMonthStart')) {
    function foodDiaryResolveMonthStart(string $selectedDate): DateTimeImmutable {
        $source = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDate);
        if (!$source) {
            $source = new DateTimeImmutable('today');
        }

        $monthRaw = (string)($_GET['month'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $monthRaw) === 1) {
            $monthDate = DateTimeImmutable::createFromFormat('Y-m-d', $monthRaw . '-01');
            if ($monthDate) {
                $todayMonthStart = (new DateTimeImmutable('today'))->modify('first day of this month');
                if ($monthDate <= $todayMonthStart) {
                    return $monthDate;
                }
            }
        }

        return $source->modify('first day of this month');
    }
}

if (!function_exists('foodDiaryRequireCoachAthlete')) {
    function foodDiaryRequireCoachAthlete(PDO $pdo, int $coachId, int $athleteId): ?array {
        $stmt = $pdo->prepare(
            'SELECT id, coach_id, first_name, last_name
             FROM athletes
             WHERE id = ? AND coach_id = ?
             LIMIT 1'
        );
        $stmt->execute([$athleteId, $coachId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

if (!function_exists('foodDiaryGetOrCreateDayId')) {
    function foodDiaryGetOrCreateDayId(PDO $pdo, int $athleteId, string $date): int {
        $select = $pdo->prepare('SELECT id FROM food_diary_days WHERE athlete_id = ? AND date = ? LIMIT 1');
        $select->execute([$athleteId, $date]);
        $existing = (int)$select->fetchColumn();
        if ($existing > 0) {
            return $existing;
        }

        $insert = $pdo->prepare('INSERT INTO food_diary_days (athlete_id, date, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
        $insert->execute([$athleteId, $date]);

        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('foodDiaryFindDay')) {
    function foodDiaryFindDay(PDO $pdo, int $athleteId, string $date): ?array {
        $stmt = $pdo->prepare('SELECT * FROM food_diary_days WHERE athlete_id = ? AND date = ? LIMIT 1');
        $stmt->execute([$athleteId, $date]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('foodDiaryNormalizeTimeOrNull')) {
    function foodDiaryNormalizeTimeOrNull(string $raw): ?string {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $match) !== 1) {
            return null;
        }

        $hour = (int)$match[1];
        $minute = (int)$match[2];
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $hour, $minute);
    }
}

if (!function_exists('foodDiaryNormalizeQuantityOrNull')) {
    function foodDiaryNormalizeQuantityOrNull(string $raw): ?float {
        $value = trim(str_replace(',', '.', $raw));
        if ($value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $number = (float)$value;
        if ($number < 0) {
            return null;
        }

        return $number;
    }
}

if (!function_exists('foodDiaryFormatCzDateTitle')) {
    function foodDiaryFormatCzDateTitle(string $ymd): string {
        if (!foodDiaryValidateDate($ymd)) {
            return $ymd;
        }

        $days = [
            1 => 'Pondělí',
            2 => 'Úterý',
            3 => 'Středa',
            4 => 'Čtvrtek',
            5 => 'Pátek',
            6 => 'Sobota',
            7 => 'Neděle',
        ];

        $months = [
            1 => 'ledna',
            2 => 'února',
            3 => 'března',
            4 => 'dubna',
            5 => 'května',
            6 => 'června',
            7 => 'července',
            8 => 'srpna',
            9 => 'září',
            10 => 'října',
            11 => 'listopadu',
            12 => 'prosince',
        ];

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
        if (!$date) {
            return $ymd;
        }

        $dow = (int)$date->format('N');
        $day = (int)$date->format('j');
        $month = (int)$date->format('n');
        $year = (int)$date->format('Y');

        return sprintf('%s %d. %s %d', $days[$dow] ?? '', $day, $months[$month] ?? '', $year);
    }
}

if (!function_exists('foodDiaryMonthStatus')) {
    function foodDiaryMonthStatus(PDO $pdo, int $athleteId, DateTimeImmutable $monthStart): array {
        $monthEnd = $monthStart->modify('last day of this month');

        $stmt = $pdo->prepare(
            'SELECT d.date,
                    SUM(CASE WHEN m.skipped = 1 OR i.item_count > 0 THEN 1 ELSE 0 END) AS logged_meals
             FROM food_diary_days d
             LEFT JOIN food_diary_meals m ON m.day_id = d.id
             LEFT JOIN (
                SELECT meal_id, COUNT(*) AS item_count
                FROM food_diary_items
                GROUP BY meal_id
             ) i ON i.meal_id = m.id
             WHERE d.athlete_id = ?
               AND d.date BETWEEN ? AND ?
             GROUP BY d.date'
        );
        $stmt->execute([$athleteId, $monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')]);

        $status = [];
        foreach ($stmt->fetchAll() as $row) {
            $date = (string)($row['date'] ?? '');
            $logged = (int)($row['logged_meals'] ?? 0);
            if ($logged <= 0) {
                $status[$date] = 'none';
            } elseif ($logged >= 6) {
                $status[$date] = 'full';
            } else {
                $status[$date] = 'partial';
            }
        }

        return $status;
    }
}

if (!function_exists('foodDiaryLoadAutoActivities')) {
    function foodDiaryLoadAutoActivities(PDO $pdo, int $athleteId, string $date): array {
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        $stmt = $pdo->prepare(
            'SELECT e.id,
                    e.custom_title,
                    e.location,
                    e.starts_at,
                    e.ends_at,
                    e.athlete_id,
                    e.second_athlete_id
             FROM coach_calendar_events e
             WHERE e.approval_status = "approved"
               AND e.starts_at BETWEEN ? AND ?
               AND (e.athlete_id = ? OR e.second_athlete_id = ?)
             ORDER BY e.starts_at ASC, e.id ASC'
        );
        $stmt->execute([$start, $end, $athleteId, $athleteId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $title = buildCalendarEventDisplayTitle($row);
            $items[] = [
                'id' => (int)$row['id'],
                'title' => $title,
                'starts_at' => (string)$row['starts_at'],
                'ends_at' => (string)$row['ends_at'],
                'location' => (string)($row['location'] ?? ''),
            ];
        }

        return $items;
    }
}

if (!function_exists('foodDiaryLoadCustomActivities')) {
    function foodDiaryLoadCustomActivities(PDO $pdo, int $dayId): array {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM food_diary_custom_activities
             WHERE day_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$dayId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('foodDiaryLoadHydrationEntries')) {
    function foodDiaryLoadHydrationEntries(PDO $pdo, int $dayId): array {
        $stmt = $pdo->prepare(
            'SELECT id, day_id, drink_type, custom_name, amount_ml, created_at
             FROM food_diary_hydration_entries
             WHERE day_id = ?
             ORDER BY id DESC'
        );
        $stmt->execute([$dayId]);

        $labels = foodDiaryHydrationTypes();
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $drinkType = (string)($row['drink_type'] ?? 'water');
            $customName = trim((string)($row['custom_name'] ?? ''));
            if ($drinkType === 'custom' && $customName !== '') {
                $row['drink_label'] = $customName;
            } else {
                $row['drink_label'] = (string)($labels[$drinkType] ?? 'Pití');
            }
            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('foodDiaryLoadMeals')) {
    function foodDiaryLoadMeals(PDO $pdo, int $dayId, int $coachId): array {
        $types = array_keys(foodDiaryMealTypes());
        $result = [];
        foreach ($types as $mealType) {
            $result[$mealType] = [
                'meal' => null,
                'items' => [],
                'coach_note' => null,
            ];
        }

        $mealStmt = $pdo->prepare(
            'SELECT *
             FROM food_diary_meals
             WHERE day_id = ?
             ORDER BY FIELD(meal_type, "breakfast", "morning_snack", "lunch", "afternoon_snack", "dinner", "second_dinner")'
        );
        $mealStmt->execute([$dayId]);
        $meals = $mealStmt->fetchAll();

        if (empty($meals)) {
            return $result;
        }

        $mealIds = [];
        foreach ($meals as $meal) {
            $mealId = (int)$meal['id'];
            $mealType = (string)$meal['meal_type'];
            $result[$mealType]['meal'] = $meal;
            $mealIds[] = $mealId;
        }

        $in = implode(',', array_fill(0, count($mealIds), '?'));

        $itemsStmt = $pdo->prepare(
            'SELECT *
             FROM food_diary_items
             WHERE meal_id IN (' . $in . ')
             ORDER BY meal_id ASC, id ASC'
        );
        $itemsStmt->execute($mealIds);
        foreach ($itemsStmt->fetchAll() as $item) {
            $mealId = (int)$item['meal_id'];
            foreach ($result as $type => $block) {
                if ((int)($block['meal']['id'] ?? 0) === $mealId) {
                    $result[$type]['items'][] = $item;
                    break;
                }
            }
        }

        $notesStmt = $pdo->prepare(
            'SELECT *
             FROM food_diary_coach_notes
             WHERE coach_id = ?
               AND meal_id IN (' . $in . ')
             ORDER BY updated_at DESC, id DESC'
        );
        $notesStmt->execute(array_merge([$coachId], $mealIds));
        $notesByMeal = [];
        foreach ($notesStmt->fetchAll() as $noteRow) {
            $mealId = (int)($noteRow['meal_id'] ?? 0);
            if ($mealId > 0 && !isset($notesByMeal[$mealId])) {
                $notesByMeal[$mealId] = $noteRow;
            }
        }

        foreach ($result as $type => $block) {
            $mealId = (int)($block['meal']['id'] ?? 0);
            if ($mealId > 0 && isset($notesByMeal[$mealId])) {
                $result[$type]['coach_note'] = $notesByMeal[$mealId];
            }
        }

        return $result;
    }
}

if (!function_exists('foodDiaryLoadDayNote')) {
    function foodDiaryLoadDayNote(PDO $pdo, int $dayId, int $coachId): ?array {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM food_diary_coach_notes
             WHERE day_id = ?
               AND coach_id = ?
               AND meal_id IS NULL
             ORDER BY updated_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$dayId, $coachId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

if (!function_exists('foodDiaryWeekSummary')) {
    function foodDiaryWeekSummary(PDO $pdo, int $athleteId, string $selectedDate): array {
        $anchor = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDate) ?: new DateTimeImmutable('today');
        $weekStart = $anchor->modify('monday this week');
        $weekEnd = $weekStart->modify('+6 day');

        $stmt = $pdo->prepare(
            'SELECT d.date,
                    SUM(CASE WHEN m.skipped = 1 OR i.item_count > 0 THEN 1 ELSE 0 END) AS logged_meals
             FROM food_diary_days d
             LEFT JOIN food_diary_meals m ON m.day_id = d.id
             LEFT JOIN (
                SELECT meal_id, COUNT(*) AS item_count
                FROM food_diary_items
                GROUP BY meal_id
             ) i ON i.meal_id = m.id
             WHERE d.athlete_id = ?
               AND d.date BETWEEN ? AND ?
             GROUP BY d.date'
        );
        $stmt->execute([$athleteId, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string)$row['date']] = (int)$row['logged_meals'];
        }

        $days = [];
        $totalLogged = 0;
        for ($i = 0; $i < 7; $i++) {
            $current = $weekStart->modify('+' . $i . ' day');
            $dateKey = $current->format('Y-m-d');
            $logged = max(0, min(6, (int)($map[$dateKey] ?? 0)));
            $totalLogged += $logged;
            $days[] = [
                'date' => $dateKey,
                'day_short' => ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'][$i],
                'logged' => $logged,
                'status' => $logged === 0 ? 'empty' : ($logged >= 6 ? 'full' : 'partial'),
            ];
        }

        return [
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'days' => $days,
            'total_logged' => $totalLogged,
            'total_slots' => 42,
            'percent' => (int)round(($totalLogged / 42) * 100),
        ];
    }
}

if (!function_exists('foodDiaryNormalizePeriod')) {
    function foodDiaryNormalizePeriod(array $source, string $selectedDate): array {
        $rangeType = (string)($source['range'] ?? 'day');
        if (!in_array($rangeType, ['day', 'week', 'month', 'custom'], true)) {
            $rangeType = 'day';
        }

        $anchor = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDate) ?: new DateTimeImmutable('today');
        $from = $anchor;
        $to = $anchor;

        if ($rangeType === 'week') {
            $from = $anchor->modify('monday this week');
            $to = $from->modify('+6 day');
        } elseif ($rangeType === 'month') {
            $from = $anchor->modify('first day of this month');
            $to = $anchor->modify('last day of this month');
        } elseif ($rangeType === 'custom') {
            $fromRaw = (string)($source['from'] ?? '');
            $toRaw = (string)($source['to'] ?? '');
            if (foodDiaryValidateDate($fromRaw) && !foodDiaryIsFutureDate($fromRaw)) {
                $fromCandidate = DateTimeImmutable::createFromFormat('Y-m-d', $fromRaw);
                if ($fromCandidate) {
                    $from = $fromCandidate;
                }
            }
            if (foodDiaryValidateDate($toRaw) && !foodDiaryIsFutureDate($toRaw)) {
                $toCandidate = DateTimeImmutable::createFromFormat('Y-m-d', $toRaw);
                if ($toCandidate) {
                    $to = $toCandidate;
                }
            }
            if ($from > $to) {
                $tmp = $from;
                $from = $to;
                $to = $tmp;
            }
        }

        $today = new DateTimeImmutable('today');
        if ($to > $today) {
            $to = $today;
        }
        if ($from > $to) {
            $from = $to;
        }

        return [
            'range' => $rangeType,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];
    }
}

if (!function_exists('foodDiaryExportRows')) {
    function foodDiaryExportRows(PDO $pdo, int $athleteId, int $coachId, string $fromDate, string $toDate): array {
        $daysStmt = $pdo->prepare(
            'SELECT *
             FROM food_diary_days
             WHERE athlete_id = ?
               AND date BETWEEN ? AND ?
             ORDER BY date ASC'
        );
        $daysStmt->execute([$athleteId, $fromDate, $toDate]);
        $days = $daysStmt->fetchAll();

        if (empty($days)) {
            return [];
        }

        $dayIds = array_map(static fn(array $d): int => (int)$d['id'], $days);
        $inDays = implode(',', array_fill(0, count($dayIds), '?'));

        $mealStmt = $pdo->prepare(
            'SELECT *
             FROM food_diary_meals
             WHERE day_id IN (' . $inDays . ')'
        );
        $mealStmt->execute($dayIds);
        $meals = $mealStmt->fetchAll();

        $mealsByDay = [];
        $mealIds = [];
        foreach ($meals as $meal) {
            $dayId = (int)$meal['day_id'];
            $mealId = (int)$meal['id'];
            $mealsByDay[$dayId][] = $meal;
            $mealIds[] = $mealId;
        }

        $itemsByMeal = [];
        if (!empty($mealIds)) {
            $inMeals = implode(',', array_fill(0, count($mealIds), '?'));
            $itemStmt = $pdo->prepare('SELECT * FROM food_diary_items WHERE meal_id IN (' . $inMeals . ') ORDER BY id ASC');
            $itemStmt->execute($mealIds);
            foreach ($itemStmt->fetchAll() as $item) {
                $itemsByMeal[(int)$item['meal_id']][] = $item;
            }

            $noteStmt = $pdo->prepare(
                'SELECT *
                 FROM food_diary_coach_notes
                 WHERE coach_id = ?
                   AND meal_id IN (' . $inMeals . ')'
            );
            $noteStmt->execute(array_merge([$coachId], $mealIds));
            $coachNotesByMeal = [];
            foreach ($noteStmt->fetchAll() as $note) {
                $mealId = (int)($note['meal_id'] ?? 0);
                if ($mealId > 0 && !isset($coachNotesByMeal[$mealId])) {
                    $coachNotesByMeal[$mealId] = (string)($note['note'] ?? '');
                }
            }
        } else {
            $coachNotesByMeal = [];
        }

        $dayNotesStmt = $pdo->prepare(
            'SELECT day_id, note
             FROM food_diary_coach_notes
             WHERE coach_id = ?
               AND day_id IN (' . $inDays . ')
               AND meal_id IS NULL
             ORDER BY updated_at DESC, id DESC'
        );
        $dayNotesStmt->execute(array_merge([$coachId], $dayIds));
        $dayCoachNotes = [];
        foreach ($dayNotesStmt->fetchAll() as $dayNote) {
            $dayId = (int)($dayNote['day_id'] ?? 0);
            if ($dayId > 0 && !isset($dayCoachNotes[$dayId])) {
                $dayCoachNotes[$dayId] = (string)($dayNote['note'] ?? '');
            }
        }

        $rows = [];
        foreach ($days as $day) {
            $date = (string)$day['date'];
            $dayId = (int)$day['id'];
            $autoActivities = foodDiaryLoadAutoActivities($pdo, $athleteId, $date);
            $customActivities = foodDiaryLoadCustomActivities($pdo, $dayId);
            $activityTexts = [];
            foreach ($autoActivities as $autoActivity) {
                $activityTexts[] = 'TR: ' . (string)$autoActivity['title'];
            }
            foreach ($customActivities as $customActivity) {
                $activityTexts[] = 'VL: ' . foodDiaryActivityTypeLabel((string)($customActivity['activity_type'] ?? 'other'))
                    . ((string)($customActivity['activity_name'] ?? '') !== '' ? ' - ' . (string)$customActivity['activity_name'] : '');
            }
            $activityLine = implode(' | ', $activityTexts);

            $dayMeals = $mealsByDay[$dayId] ?? [];
            foreach ($dayMeals as $meal) {
                $mealId = (int)$meal['id'];
                $items = $itemsByMeal[$mealId] ?? [];
                $coachNote = (string)($coachNotesByMeal[$mealId] ?? '');

                if ((int)($meal['skipped'] ?? 0) === 1) {
                    $rows[] = [
                        'date' => $date,
                        'meal_type' => (string)$meal['meal_type'],
                        'meal_time' => (string)($meal['meal_time'] ?? ''),
                        'food_name' => '[Jídlo vynecháno]',
                        'quantity' => '',
                        'unit' => '',
                        'athlete_note' => (string)($meal['athlete_note'] ?? ''),
                        'coach_note' => $coachNote,
                        'day_coach_note' => (string)($dayCoachNotes[$dayId] ?? ''),
                        'activity' => $activityLine,
                        'photo' => (string)($meal['photo'] ?? ''),
                    ];
                    continue;
                }

                if (empty($items)) {
                    $rows[] = [
                        'date' => $date,
                        'meal_type' => (string)$meal['meal_type'],
                        'meal_time' => (string)($meal['meal_time'] ?? ''),
                        'food_name' => '[Bez položek]',
                        'quantity' => '',
                        'unit' => '',
                        'athlete_note' => (string)($meal['athlete_note'] ?? ''),
                        'coach_note' => $coachNote,
                        'day_coach_note' => (string)($dayCoachNotes[$dayId] ?? ''),
                        'activity' => $activityLine,
                        'photo' => (string)($meal['photo'] ?? ''),
                    ];
                    continue;
                }

                foreach ($items as $item) {
                    $rows[] = [
                        'date' => $date,
                        'meal_type' => (string)$meal['meal_type'],
                        'meal_time' => (string)($meal['meal_time'] ?? ''),
                        'food_name' => (string)($item['food_name'] ?? ''),
                        'quantity' => $item['quantity'] !== null ? (string)$item['quantity'] : '',
                        'unit' => (string)($item['unit'] ?? ''),
                        'athlete_note' => (string)($meal['athlete_note'] ?? ''),
                        'coach_note' => $coachNote,
                        'day_coach_note' => (string)($dayCoachNotes[$dayId] ?? ''),
                        'activity' => $activityLine,
                        'photo' => (string)($meal['photo'] ?? ''),
                    ];
                }
            }
        }

        return $rows;
    }
}
