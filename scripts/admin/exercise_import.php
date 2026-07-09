<?php
// admin/exercise_import.php – import globálních cviků z CSV
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

function importExerciseCategoryMap(): array {
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

function normalizeCategoryToken(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(['(', ')', '+'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function importCategoryAliases(): array {
    return [
        'hrudnik' => 'chest',
        'hrudník' => 'chest',
        'chest' => 'chest',
        'zada' => 'back',
        'záda' => 'back',
        'back' => 'back',
        'ramena' => 'shoulders',
        'shoulders' => 'shoulders',
        'biceps' => 'biceps',
        'triceps' => 'triceps',
        'predlokti' => 'forearms',
        'předloktí' => 'forearms',
        'forearms' => 'forearms',
        'quadricepsy' => 'quadriceps',
        'quadriceps' => 'quadriceps',
        'hamstringy' => 'hamstrings',
        'hamstrings' => 'hamstrings',
        'hyzde' => 'glutes',
        'hýždě' => 'glutes',
        'glutes' => 'glutes',
        'lytka' => 'calves',
        'lýtka' => 'calves',
        'calves' => 'calves',
        'core' => 'core',
        'bricho' => 'core',
        'břicho' => 'core',
        'hluboky stabilizacni system' => 'core',
        'hluboký stabilizační systém' => 'core',
        'bez zarazeni' => 'uncategorized',
        'bez zařazení' => 'uncategorized',
        'uncategorized' => 'uncategorized',
    ];
}

function parseImportCategories(string $rawCategories): array {
    $allowed = array_keys(importExerciseCategoryMap());
    $aliases = importCategoryAliases();
    $normalized = [];

    $parts = preg_split('/[,|\/]+/', (string)$rawCategories);
    if (!is_array($parts)) {
        $parts = [];
    }

    foreach ($parts as $part) {
        $token = normalizeCategoryToken((string)$part);
        if ($token === '') {
            continue;
        }
        if (in_array($token, $allowed, true)) {
            $normalized[$token] = true;
            continue;
        }
        if (isset($aliases[$token])) {
            $normalized[$aliases[$token]] = true;
        }
    }

    if (empty($normalized)) {
        return ['uncategorized'];
    }

    return array_keys($normalized);
}

$preview = [];
$errors  = [];
$inserted = 0;
$updated  = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/exercise_import.php');
    }

    $action = $_POST['action'] ?? '';

    // Krok 2: skutečný import
    if ($action === 'import' && !empty($_POST['rows']) && is_array($_POST['rows'])) {
        $stmtById = $pdo->prepare('SELECT id FROM exercises WHERE id = ? AND is_global = 1');
        $stmtByName = $pdo->prepare('SELECT id FROM exercises WHERE name = ? AND is_global = 1 LIMIT 1');
        $stmtInsert = $pdo->prepare('INSERT INTO exercises (coach_id, name, photo, is_global, muscle_categories) VALUES (NULL, ?, ?, 1, ?)');
        $stmtUpdate = $pdo->prepare('UPDATE exercises SET name = ?, photo = ?, muscle_categories = ?, coach_id = NULL, is_global = 1 WHERE id = ?');

        $pdo->beginTransaction();
        try {
            foreach ($_POST['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $photoRaw = trim((string)($row['photo'] ?? ''));
                $photo = $photoRaw === '' ? null : $photoRaw;

                $categories = parseImportCategories((string)($row['muscle_categories'] ?? ''));
                $categoriesJson = json_encode($categories, JSON_UNESCAPED_UNICODE);

                $targetId = (int)($row['target_id'] ?? 0);
                if ($targetId > 0) {
                    $stmtById->execute([$targetId]);
                    if ($stmtById->fetch()) {
                        $stmtUpdate->execute([$name, $photo, $categoriesJson, $targetId]);
                        $updated++;
                        continue;
                    }
                }

                $stmtByName->execute([$name]);
                $existingByName = $stmtByName->fetchColumn();
                if ($existingByName) {
                    $stmtUpdate->execute([$name, $photo, $categoriesJson, (int)$existingByName]);
                    $updated++;
                    continue;
                }

                $stmtInsert->execute([$name, $photo, $categoriesJson]);
                $inserted++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        flash('success', "Import dokončen: vloženo $inserted, aktualizováno $updated. Duplicitní cviky se nevytváří.");
        redirect(BASE_URL . '/admin/exercises.php');
    }

    // Krok 1: náhled CSV
    if ($action === 'preview') {
        if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Vyberte platný CSV soubor.';
        } else {
            $handle = fopen($_FILES['csv']['tmp_name'], 'r');
            if (!$handle) {
                $errors[] = 'Soubor nelze otevřít.';
            } else {
                // Přeskočit BOM
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
                $headerRow = fgetcsv($handle, 0, ';');
                // Zjisti indexy sloupců
                $nameIdx = false;
                $idIdx = false;
                $photoIdx = false;
                $categoriesIdx = false;
                if ($headerRow) {
                    foreach ($headerRow as $k => $v) {
                        $header = strtolower(trim((string)$v));
                        if ($header === 'name') {
                            $nameIdx = $k;
                        } elseif ($header === 'id') {
                            $idIdx = $k;
                        } elseif ($header === 'photo') {
                            $photoIdx = $k;
                        } elseif ($header === 'muscle_categories') {
                            $categoriesIdx = $k;
                        }
                    }
                }
                if ($nameIdx === false) {
                    $errors[] = 'CSV neobsahuje sloupec "name". Očekávaná hlavička: id;name;photo;muscle_categories;category_help.';
                } else {
                    $stmtCheckById = $pdo->prepare('SELECT id FROM exercises WHERE id = ? AND is_global = 1');
                    $stmtCheckByName = $pdo->prepare('SELECT id FROM exercises WHERE name = ? AND is_global = 1 LIMIT 1');
                    while (($row = fgetcsv($handle, 0, ';')) !== false) {
                        $name = trim($row[$nameIdx] ?? '');
                        if ($name === '') continue;

                        $csvId = ($idIdx !== false) ? (int)trim((string)($row[$idIdx] ?? '0')) : 0;
                        $photo = ($photoIdx !== false) ? trim((string)($row[$photoIdx] ?? '')) : '';
                        $rawCategories = ($categoriesIdx !== false) ? trim((string)($row[$categoriesIdx] ?? '')) : '';
                        $normalizedCategories = implode(',', parseImportCategories($rawCategories));

                        $targetId = 0;
                        $status = 'insert';

                        if ($csvId > 0) {
                            $stmtCheckById->execute([$csvId]);
                            $foundById = $stmtCheckById->fetchColumn();
                            if ($foundById) {
                                $targetId = (int)$foundById;
                                $status = 'update';
                            }
                        }

                        if ($targetId === 0) {
                            $stmtCheckByName->execute([$name]);
                            $foundByName = $stmtCheckByName->fetchColumn();
                            if ($foundByName) {
                                $targetId = (int)$foundByName;
                                $status = 'update';
                            }
                        }

                        $preview[] = [
                            'target_id' => $targetId,
                            'name' => $name,
                            'photo' => $photo,
                            'muscle_categories' => $normalizedCategories,
                            'status' => $status,
                        ];
                    }
                    if (empty($preview)) {
                        $errors[] = 'CSV soubor neobsahuje žádné záznamy.';
                    }
                }
                fclose($handle);
            }
        }
    }
}

renderAdminHeader('Import globálních cviků');
?>

<div class="mb-4">
    <h4 class="fw-bold"><i class="fas fa-file-import me-2" style="color:#7c3aed"></i>Import globálních cviků</h4>
    <p class="text-muted small mb-0">
        Nahraje CSV soubor (oddělený středníkem) s globálními cviky.
        Sloupec <code>name</code> je povinný, doporučená hlavička je <code>id;name;photo;muscle_categories;category_help</code>.
        Import nezdvojuje data: existující cviky aktualizuje (podle ID, případně názvu), nové vloží.
    </p>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<?php if (empty($preview)): ?>
<!-- Formulář pro nahrání CSV -->
<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="preview">
            <div class="mb-3">
                <label class="form-label fw-semibold">CSV soubor</label>
                <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
                <div class="form-text">
                    Formát: <code>id;name;photo;muscle_categories;category_help</code>.
                    Do <code>muscle_categories</code> pište klíče oddělené čárkou (např. <code>chest,shoulders</code> nebo <code>uncategorized</code>).
                    Sloupec <code>category_help</code> je jen nápověda a při importu se ignoruje.
                </div>
            </div>
            <button type="submit" class="btn" style="background:#7c3aed;color:#fff">
                <i class="fas fa-search me-1"></i>Zobrazit náhled
            </button>
            <a href="<?= BASE_URL ?>/admin/exercises.php" class="btn btn-outline-secondary ms-2">Zpět</a>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Náhled importu -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header fw-semibold" style="background:#312e81;color:#fff">
        Náhled – <?= count($preview) ?> záznamů
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Název cviku</th>
                    <th>Kategorie</th>
                    <th>Stav</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview as $p): ?>
                <tr class="<?= $p['status'] === 'update' ? 'table-warning' : '' ?>">
                    <td><?= h($p['name']) ?></td>
                    <td><code><?= h($p['muscle_categories']) ?></code></td>
                    <td>
                        <?php if ($p['status'] === 'update'): ?>
                        <span class="badge bg-warning text-dark"><i class="fas fa-pen me-1"></i>aktualizovat existující</span>
                        <?php else: ?>
                        <span class="badge bg-success"><i class="fas fa-plus me-1"></i>vložit nový</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php $newCount = count(array_filter($preview, static fn($p) => $p['status'] === 'insert')); ?>
<?php $updateCount = count(array_filter($preview, static fn($p) => $p['status'] === 'update')); ?>
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="import">
    <?php foreach ($preview as $idx => $p): ?>
    <input type="hidden" name="rows[<?= $idx ?>][target_id]" value="<?= (int)$p['target_id'] ?>">
    <input type="hidden" name="rows[<?= $idx ?>][name]" value="<?= h($p['name']) ?>">
    <input type="hidden" name="rows[<?= $idx ?>][photo]" value="<?= h($p['photo']) ?>">
    <input type="hidden" name="rows[<?= $idx ?>][muscle_categories]" value="<?= h($p['muscle_categories']) ?>">
    <?php endforeach; ?>
    <div class="d-flex gap-2 align-items-center">
        <?php if (($newCount + $updateCount) > 0): ?>
        <button type="submit" class="btn" style="background:#7c3aed;color:#fff">
            <i class="fas fa-file-import me-1"></i>Importovat <?= ($newCount + $updateCount) ?> cviků (nové: <?= $newCount ?>, aktualizace: <?= $updateCount ?>)
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/exercise_import.php" class="btn btn-outline-secondary">Nahrát jiný soubor</a>
        <a href="<?= BASE_URL ?>/admin/exercises.php" class="btn btn-link text-muted">Zrušit</a>
    </div>
</form>
<?php endif; ?>

<?php renderAdminFooter(); ?>
