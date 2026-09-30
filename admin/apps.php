<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$platform = strtolower(trim((string)($_GET['platform'] ?? 'android')));
$platform = $platform === 'ios' ? 'ios' : 'android';
$androidMarketUrlKey = 'mobile_app_android_market_url';
$androidApkPathKey = 'mobile_app_android_apk_path';
$iosMarketUrlKey = 'mobile_app_ios_market_url';
$androidMarketUrl = trim(getAppSetting($androidMarketUrlKey, ''));
$androidApkPath = trim(getAppSetting($androidApkPathKey, ''));
$iosMarketUrl = trim(getAppSetting($iosMarketUrlKey, ''));
$schemaErrors = [];

foreach ([
    ['coaches', 'mobile_app_enabled', 'TINYINT(1) NOT NULL DEFAULT 1'],
    ['athletes', 'mobile_app_enabled', 'TINYINT(1) NOT NULL DEFAULT 1'],
    ['mobile_api_tokens', 'platform', "VARCHAR(20) NOT NULL DEFAULT 'android'"],
    ['mobile_api_tokens', 'app_version', 'VARCHAR(100) DEFAULT NULL'],
] as [$table, $column, $definition]) {
    try {
        $exists = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column))->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {
        $schemaErrors[] = $table . '.' . $column;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
    } else {
        $action = (string)($_POST['action'] ?? '');
        $saveSetting = static function (PDO $pdo, string $key, string $value): void {
            $pdo->prepare(
                'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            )->execute([$key, $value]);
        };
        $validateMarketUrl = static function (string $url): string|false {
            if ($url === '') {
                return '';
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return false;
            }
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            return $scheme === 'https' ? $url : false;
        };

        if ($action === 'remove_android_apk') {
            try {
                if (str_starts_with($androidApkPath, 'uploads/apps/android/') && !str_contains($androidApkPath, '..')) {
                    $apkFile = __DIR__ . '/../' . $androidApkPath;
                    if (is_file($apkFile)) {
                        @unlink($apkFile);
                    }
                }
                $saveSetting($pdo, $androidApkPathKey, '');
                flash('success', 'Instalační soubor Android byl odebrán.');
            } catch (Throwable $e) {
                flash('danger', 'Instalační soubor Android se nepodařilo odebrat.');
            }
        } elseif ($action === 'save_android_app') {
            $marketUrl = $validateMarketUrl(trim((string)($_POST['android_market_url'] ?? '')));
            if ($marketUrl === false) {
                flash('danger', 'Zadejte platný odkaz na Google Play začínající https://.');
            } else {
                $newApkPath = $androidApkPath;
                $upload = $_FILES['android_apk'] ?? null;
                $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                $newFileAbsolutePath = '';
                if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                    if ($uploadError !== UPLOAD_ERR_OK) {
                        flash('danger', 'Soubor APK se nepodařilo nahrát. Zkontrolujte také limit velikosti uploadu serveru.');
                        redirect(BASE_URL . '/admin/apps.php?platform=android');
                    }
                    $originalName = (string)($upload['name'] ?? '');
                    $temporaryPath = (string)($upload['tmp_name'] ?? '');
                    $fileSize = (int)($upload['size'] ?? 0);
                    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'apk' || $fileSize <= 0 || $fileSize > 500 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
                        flash('danger', 'Vyberte platný instalační soubor APK do velikosti 500 MB.');
                        redirect(BASE_URL . '/admin/apps.php?platform=android');
                    }
                    $uploadDirectory = __DIR__ . '/../uploads/apps/android';
                    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
                        flash('danger', 'Nepodařilo se připravit složku pro instalační soubor.');
                        redirect(BASE_URL . '/admin/apps.php?platform=android');
                    }
                    $fileName = 'trainerapp_android_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.apk';
                    $newFileAbsolutePath = $uploadDirectory . '/' . $fileName;
                    if (!move_uploaded_file($temporaryPath, $newFileAbsolutePath)) {
                        flash('danger', 'Instalační soubor se nepodařilo uložit.');
                        redirect(BASE_URL . '/admin/apps.php?platform=android');
                    }
                    $newApkPath = 'uploads/apps/android/' . $fileName;
                }

                try {
                    $saveSetting($pdo, $androidMarketUrlKey, $marketUrl);
                    if ($newApkPath !== $androidApkPath) {
                        $saveSetting($pdo, $androidApkPathKey, $newApkPath);
                    }
                    if ($newFileAbsolutePath !== '' && str_starts_with($androidApkPath, 'uploads/apps/android/') && !str_contains($androidApkPath, '..')) {
                        $oldFile = __DIR__ . '/../' . $androidApkPath;
                        if (is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }
                    flash('success', 'Nastavení Android aplikace bylo uloženo.');
                } catch (Throwable $e) {
                    if ($newFileAbsolutePath !== '' && is_file($newFileAbsolutePath)) {
                        @unlink($newFileAbsolutePath);
                    }
                    flash('danger', 'Nastavení Android aplikace se nepodařilo uložit.');
                }
            }
        } elseif ($action === 'save_ios_app') {
            $marketUrl = $validateMarketUrl(trim((string)($_POST['ios_market_url'] ?? '')));
            if ($marketUrl === false) {
                flash('danger', 'Zadejte platný odkaz na App Store začínající https://.');
            } else {
                try {
                    $saveSetting($pdo, $iosMarketUrlKey, $marketUrl);
                    flash('success', 'Odkaz na App Store byl uložen.');
                } catch (Throwable $e) {
                    flash('danger', 'Odkaz na App Store se nepodařilo uložit.');
                }
            }
        } else {
            $userType = (string)($_POST['user_type'] ?? '');
            $userId = (int)($_POST['user_id'] ?? 0);
            $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
            $table = $userType === 'coach' ? 'coaches' : ($userType === 'athlete' ? 'athletes' : '');

            if ($table !== '' && $userId > 0) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE `$table` SET mobile_app_enabled = ? WHERE id = ?")
                    ->execute([$enabled, $userId]);
                if ($enabled === 0) {
                    $pdo->prepare('DELETE FROM mobile_api_tokens WHERE account_type = ? AND user_id = ?')
                        ->execute([$userType, $userId]);
                }
                $pdo->commit();
                flash('success', $enabled ? 'Přístup do aplikace byl povolen.' : 'Přístup do aplikace byl vypnut a mobilní relace odhlášeny.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('danger', 'Přístup do aplikace se nepodařilo uložit.');
            }
        } else {
            flash('danger', 'Uživatel nebyl nalezen.');
        }
        }
    }

    redirect(BASE_URL . '/admin/apps.php?platform=' . $platform);
}

$usageByUser = [];
if (empty($schemaErrors)) {
    $usageStmt = $pdo->prepare(
        'SELECT t.account_type, t.user_id, MAX(t.created_at) AS last_login,
                MAX(t.last_used_at) AS last_activity,
                (SELECT latest.app_version
                 FROM mobile_api_tokens latest
                 WHERE latest.account_type = t.account_type
                   AND latest.user_id = t.user_id
                   AND latest.platform = t.platform
                 ORDER BY latest.created_at DESC, latest.id DESC
                 LIMIT 1) AS app_version
         FROM mobile_api_tokens t
         WHERE t.platform = ?
         GROUP BY t.account_type, t.user_id, t.platform'
    );
    $usageStmt->execute([$platform]);
    foreach ($usageStmt->fetchAll() as $usage) {
        $usageByUser[(string)$usage['account_type']][(int)$usage['user_id']] = $usage;
    }
}

$users = [];
if (empty($schemaErrors) && ($platform === 'android' || $usageByUser !== [])) {
    foreach ($pdo->query('SELECT id, name AS display_name, email, username, mobile_app_enabled FROM coaches ORDER BY name, username')->fetchAll() as $coach) {
        $id = (int)$coach['id'];
        if ($platform === 'ios' && !isset($usageByUser['coach'][$id])) {
            continue;
        }
        $users[] = [
            'type' => 'coach', 'id' => $id, 'name' => trim((string)$coach['display_name']) ?: (string)$coach['username'],
            'email' => (string)$coach['email'], 'enabled' => (int)$coach['mobile_app_enabled'],
            'usage' => $usageByUser['coach'][$id] ?? [],
        ];
    }
    foreach ($pdo->query("SELECT id, CONCAT_WS(' ', first_name, last_name) AS display_name, email, mobile_app_enabled FROM athletes ORDER BY first_name, last_name")->fetchAll() as $athlete) {
        $id = (int)$athlete['id'];
        if ($platform === 'ios' && !isset($usageByUser['athlete'][$id])) {
            continue;
        }
        $users[] = [
            'type' => 'athlete', 'id' => $id, 'name' => trim((string)$athlete['display_name']) ?: 'Sportovec',
            'email' => (string)$athlete['email'], 'enabled' => (int)$athlete['mobile_app_enabled'],
            'usage' => $usageByUser['athlete'][$id] ?? [],
        ];
    }
    usort($users, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
}

renderAdminHeader($platform === 'ios' ? 'Aplikace – Apple' : 'Aplikace – Android');
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h4 class="fw-bold mb-0"><i class="<?= $platform === 'ios' ? 'fab fa-apple' : 'fab fa-android' ?> me-2"></i><?= $platform === 'ios' ? 'Apple' : 'Android' ?></h4>
    <div class="btn-group" role="group" aria-label="Platforma aplikace">
        <a class="btn btn-sm <?= $platform === 'android' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= BASE_URL ?>/admin/apps.php?platform=android"><i class="fab fa-android me-1"></i>Android</a>
        <a class="btn btn-sm <?= $platform === 'ios' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= BASE_URL ?>/admin/apps.php?platform=ios"><i class="fab fa-apple me-1"></i>Apple</a>
    </div>
</div>

<?php if ($platform === 'android'): ?>
<section class="bg-white border rounded p-3 mb-4">
    <h5 class="fw-bold mb-3"><i class="fab fa-google-play me-2 text-success"></i>Stažení aplikace Android</h5>
    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_android_app">
        <div class="col-12 col-lg-5">
            <label class="form-label" for="android_apk">Instalační soubor APK</label>
            <input class="form-control" type="file" id="android_apk" name="android_apk" accept=".apk,application/vnd.android.package-archive">
            <div class="form-text"><?= $androidApkPath !== '' ? 'Soubor je nahraný.' : 'Zatím není nahraný žádný soubor.' ?></div>
        </div>
        <div class="col-12 col-lg-5">
            <label class="form-label" for="android_market_url">Odkaz na Google Play</label>
            <input class="form-control" type="url" id="android_market_url" name="android_market_url" value="<?= h($androidMarketUrl) ?>" placeholder="https://play.google.com/store/apps/details?id=…">
            <div class="form-text">Je-li odkaz vyplněný, tlačítko na přihlášení otevře Google Play místo APK.</div>
        </div>
        <div class="col-12 col-lg-2 d-grid gap-2">
            <button class="btn btn-primary w-100" type="submit"><i class="fas fa-save me-1"></i>Uložit</button>
            <?php if ($androidApkPath !== ''): ?>
            <button class="btn btn-outline-danger w-100" type="submit" name="action" value="remove_android_apk" formnovalidate onclick="return confirm('Opravdu odebrat instalační soubor Android?');"><i class="fas fa-trash me-1"></i>Odebrat APK</button>
            <?php endif; ?>
        </div>
    </form>
</section>
<?php else: ?>
<section class="bg-white border rounded p-3 mb-4">
    <h5 class="fw-bold mb-3"><i class="fab fa-apple me-2"></i>Odkaz na aplikaci Apple</h5>
    <form method="post" class="row g-3 align-items-end">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_ios_app">
        <div class="col-12 col-lg-10">
            <label class="form-label" for="ios_market_url">Odkaz na App Store</label>
            <input class="form-control" type="url" id="ios_market_url" name="ios_market_url" value="<?= h($iosMarketUrl) ?>" placeholder="https://apps.apple.com/…">
        </div>
        <div class="col-12 col-lg-2"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-save me-1"></i>Uložit</button></div>
    </form>
</section>
<?php endif; ?>

<?php if ($schemaErrors): ?>
<div class="alert alert-danger">Nepodařilo se připravit databázi aplikace (<?= h(implode(', ', $schemaErrors)) ?>). Zkontrolujte oprávnění databáze pro úpravu tabulek.</div>
<?php elseif ($platform === 'ios' && $usageByUser === []): ?>
<div class="alert alert-light border text-center py-5 mb-0">Aplikace pro iPhone zatím není vydaná.</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-hover table-sm align-middle bg-white">
        <thead class="table-light">
            <tr><th>Jméno</th><th>Role</th><th>Přístup</th><th>Poslední přihlášení</th><th>Poslední aktivita</th><th>Verze aplikace</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php $usage = $user['usage']; ?>
            <tr>
                <td><div class="fw-semibold"><?= h($user['name']) ?></div><div class="small text-muted"><?= h($user['email']) ?></div></td>
                <td><?= $user['type'] === 'coach' ? 'Trenér' : 'Sportovec' ?></td>
                <td>
                    <form method="post" class="m-0">
                        <?= csrfField() ?>
                        <input type="hidden" name="user_type" value="<?= h($user['type']) ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                        <input type="hidden" name="enabled" value="<?= $user['enabled'] ? '0' : '1' ?>">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" <?= $user['enabled'] ? 'checked' : '' ?> onchange="this.form.elements.enabled.value=this.checked?'1':'0';this.form.submit()" aria-label="Přístup do aplikace pro <?= h($user['name']) ?>">
                            <span class="small <?= $user['enabled'] ? 'text-success' : 'text-muted' ?>"><?= $user['enabled'] ? 'Zapnuto' : 'Vypnuto' ?></span>
                        </div>
                    </form>
                </td>
                <td class="text-nowrap"><?= !empty($usage['last_login']) ? h(formatDateTime((string)$usage['last_login'])) : '—' ?></td>
                <td class="text-nowrap"><?= !empty($usage['last_activity']) ? h(formatDateTime((string)$usage['last_activity'])) : '—' ?></td>
                <td><?= !empty($usage['app_version']) ? h((string)$usage['app_version']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($users === []): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Zatím zde nejsou žádní uživatelé.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php renderAdminFooter(); ?>