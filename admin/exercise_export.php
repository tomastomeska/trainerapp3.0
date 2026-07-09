<?php
// admin/exercise_export.php – export globálních cviků (CSV nebo SQL)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$format = $_GET['format'] ?? 'csv';
if (!in_array($format, ['csv', 'sql'], true)) {
    $format = 'csv';
}

$pdo = getDB();

function exportExerciseCategoryMap(): array {
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
        'core' => 'Core',
        'uncategorized' => 'Bez zařazení',
    ];
}

function normalizeExportCategories(?string $raw): string {
    $keys = array_keys(exportExerciseCategoryMap());
    if ($raw === null || trim($raw) === '') {
        return 'uncategorized';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return 'uncategorized';
    }

    $valid = [];
    foreach ($decoded as $item) {
        $item = trim((string)$item);
        if ($item !== '' && in_array($item, $keys, true)) {
            $valid[$item] = true;
        }
    }

    if (empty($valid)) {
        return 'uncategorized';
    }

    return implode(',', array_keys($valid));
}

function categoryHelpText(): string {
    $parts = [];
    foreach (exportExerciseCategoryMap() as $key => $label) {
        $parts[] = $key . '=' . $label;
    }
    return 'Pouzijte klice oddelene carkou: ' . implode(' | ', $parts);
}

$exercises = $pdo->query(
    'SELECT id, name, photo, muscle_categories FROM exercises WHERE is_global = 1 ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

$filename = 'globalni_cviky_' . date('Y-m-d');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    // BOM pro Excel
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'name', 'photo', 'muscle_categories', 'category_help'], ';');
    $helpText = categoryHelpText();
    foreach ($exercises as $ex) {
        fputcsv(
            $out,
            [
                $ex['id'],
                $ex['name'],
                $ex['photo'] ?? '',
                normalizeExportCategories($ex['muscle_categories'] ?? null),
                $helpText,
            ],
            ';'
        );
    }
    fclose($out);
    exit;
}

// SQL export
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.sql"');

echo "-- Globální cviky TrainerApp\n";
echo "-- Exportováno: " . date('Y-m-d H:i:s') . "\n\n";
echo "-- Odstraní stávající globální cviky a nahradí je exportovanými:\n";
echo "DELETE FROM exercises WHERE is_global = 1;\n\n";

if (!empty($exercises)) {
    echo "INSERT INTO exercises (coach_id, name, photo, is_global, muscle_categories) VALUES\n";
    $rows = [];
    foreach ($exercises as $ex) {
        $name  = str_replace("'", "''", $ex['name']);
        $photo = $ex['photo'] ? str_replace("'", "''", $ex['photo']) : 'NULL';
        $categories = normalizeExportCategories($ex['muscle_categories'] ?? null);
        $categoriesEscaped = str_replace("'", "''", json_encode(explode(',', $categories), JSON_UNESCAPED_UNICODE));
        $photoVal = $ex['photo'] ? "'$photo'" : 'NULL';
        $rows[] = "(NULL, '$name', $photoVal, 1, '$categoriesEscaped')";
    }
    echo implode(",\n", $rows) . ";\n";
}
exit;
