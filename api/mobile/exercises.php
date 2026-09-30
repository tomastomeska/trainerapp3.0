<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();
$input = mobileInput();
$action = (string)($_REQUEST['action'] ?? $input['action'] ?? '');

function mobileExerciseCategories(): array
{
    return [
        'chest' => 'Hrudník (Chest)',
        'back' => 'Záda (Back)',
        'shoulders' => 'Ramena (Shoulders)',
        'biceps' => 'Biceps',
        'triceps' => 'Triceps',
        'forearms' => 'Předloktí',
        'quadriceps' => 'Quadricepsy',
        'hamstrings' => 'Hamstringy',
        'glutes' => 'Hýždě',
        'calves' => 'Lýtka',
        'core' => 'Core (Břicho + hluboký stabilizační systém)',
        'uncategorized' => 'Bez zařazení',
    ];
}

function mobileSanitizeExerciseCategories(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $allowed = array_keys(mobileExerciseCategories());
    $normalized = [];
    foreach ($raw as $item) {
        $item = trim((string)$item);
        if ($item !== '' && in_array($item, $allowed, true)) {
            $normalized[$item] = true;
        }
    }
    return array_keys($normalized);
}

function mobileExerciseModeFlag(string $mode): int
{
    return match ($mode) {
        'timed' => 1,
        'distance' => 2,
        'distance_time' => 3,
        default => 0,
    };
}

function mobileExerciseModeName(int $flag): string
{
    return match ($flag) {
        1 => 'timed',
        2 => 'distance',
        3 => 'distance_time',
        default => 'standard',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $name = trim((string)($input['name'] ?? ''));
    $mode = trim((string)($input['exercise_mode'] ?? 'standard'));
    $categories = mobileSanitizeExerciseCategories($input['muscle_categories'] ?? []);
    if ($name === '') {
        mobileJson(['success' => false, 'error' => 'Zadejte název cviku.'], 400);
    }
    if ($categories === []) {
        mobileJson(['success' => false, 'error' => 'Vyberte alespoň jednu svalovou kategorii.'], 400);
    }
    if (!in_array($mode, ['standard', 'timed', 'distance', 'distance_time'], true)) {
        $mode = 'standard';
    }
    try {
        $pdo->prepare('INSERT INTO exercises (coach_id, name, photo, sport_type, is_timed, muscle_categories) VALUES (?, ?, NULL, ?, ?, ?)')
            ->execute([(int)$coach['id'], $name, 'standard', mobileExerciseModeFlag($mode), json_encode($categories, JSON_UNESCAPED_UNICODE)]);
        mobileJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Cvik se nepodařilo uložit.'], 500);
    }
}

try {
    // Check if 'is_global' exists in exercises
    $hasGlobal = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'is_global'");
        if ($chk && $chk->fetch()) $hasGlobal = true;
    } catch (Throwable $e) {}

    $timedSelect = '0 AS is_timed';
    $categorySelect = 'NULL AS muscle_categories';
    try {
        $timedColumn = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'is_timed'");
        if ($timedColumn && $timedColumn->fetch()) {
            $timedSelect = 'COALESCE(is_timed, 0) AS is_timed';
        }
    } catch (Throwable $e) {
    }
    try {
        $categoryColumn = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'muscle_categories'");
        if ($categoryColumn && $categoryColumn->fetch()) {
            $categorySelect = 'muscle_categories';
        }
    } catch (Throwable $e) {
    }

    if ($hasGlobal) {
        $stmt = $pdo->prepare("SELECT id, name, sport_type, {$timedSelect}, {$categorySelect}, CASE WHEN is_global = 1 THEN 1 ELSE 0 END AS is_global FROM exercises WHERE coach_id = ? OR is_global = 1 ORDER BY is_global DESC, name ASC");
        $stmt->execute([$coach['id']]);
    } else {
        $stmt = $pdo->prepare("SELECT id, name, sport_type, {$timedSelect}, {$categorySelect}, 0 AS is_global FROM exercises WHERE coach_id = ? ORDER BY name ASC");
        $stmt->execute([$coach['id']]);
    }

    $exercises = [];
    foreach ($stmt->fetchAll() as $row) {
        $decodedCategories = json_decode((string)($row['muscle_categories'] ?? ''), true);
        $categories = mobileSanitizeExerciseCategories(is_array($decodedCategories) ? $decodedCategories : []);
        $exercises[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'sport_type' => (string)($row['sport_type'] ?? 'standard'),
            'is_global' => (int)($row['is_global'] ?? 0) === 1,
            'exercise_mode' => mobileExerciseModeName((int)($row['is_timed'] ?? 0)),
            'muscle_categories' => $categories === [] ? ['uncategorized'] : $categories,
        ];
    }
    $categoryOptions = [];
    foreach (mobileExerciseCategories() as $key => $label) {
        $categoryOptions[] = ['key' => $key, 'label' => $label];
    }
    mobileJson(['success' => true, 'exercises' => $exercises, 'category_options' => $categoryOptions]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 500);
}
