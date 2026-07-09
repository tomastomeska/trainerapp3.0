<?php
// admin/settings.php – obecná nastavení aplikace (verze apod.)
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo     = getDB();
$error   = null;
$success = null;

$logoSettingKey = 'login_logo_path';
$supportBankAccountKey = 'support_bank_account';
$logoUploadDir = __DIR__ . '/../uploads/logo';
$logoBasePath = 'uploads/logo';

function normalizeBankAccountInput(?string $raw): string|false|null
{
    $value = strtoupper(str_replace(' ', '', trim((string)$raw)));
    if ($value === '') {
        return null;
    }

    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $value) === 1) {
        return $value;
    }

    if (
        preg_match('/^[0-9]{1,6}-[0-9]{2,10}\/[0-9]{4}$/', $value) === 1 ||
        preg_match('/^[0-9]{2,10}\/[0-9]{4}$/', $value) === 1
    ) {
        return $value;
    }

    return false;
}

$currentVersion = getAppSetting('app_version', APP_VERSION);
$currentLogoPath = trim(getAppSetting($logoSettingKey, ''));
$currentSupportBankAccount = trim(getAppSetting($supportBankAccountKey, ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $action = trim((string)($_POST['action'] ?? 'save_version'));

        if ($action === 'upload_login_logo') {
            if (empty($_FILES['login_logo']['tmp_name']) || (int)($_FILES['login_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Vyberte prosím soubor loga.';
            } else {
                $allowedExt = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
                $originalName = (string)($_FILES['login_logo']['name'] ?? '');
                $tmpName = (string)($_FILES['login_logo']['tmp_name'] ?? '');
                $fileSize = (int)($_FILES['login_logo']['size'] ?? 0);
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExt, true)) {
                    $error = 'Nepodporovaný formát loga. Povolené: png, jpg, jpeg, webp, svg.';
                } elseif ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
                    $error = 'Soubor loga musí mít velikost 1 B až 5 MB.';
                } else {
                    if (!is_dir($logoUploadDir) && !mkdir($logoUploadDir, 0775, true) && !is_dir($logoUploadDir)) {
                        $error = 'Nepodařilo se vytvořit složku pro logo.';
                    } else {
                        $newName = 'login_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $targetPath = $logoUploadDir . '/' . $newName;

                        if (!move_uploaded_file($tmpName, $targetPath)) {
                            $error = 'Soubor loga se nepodařilo nahrát.';
                        } else {
                            if ($currentLogoPath !== '') {
                                $oldPath = __DIR__ . '/../' . ltrim($currentLogoPath, '/');
                                if (is_file($oldPath)) {
                                    @unlink($oldPath);
                                }
                            }

                            $newRelativePath = $logoBasePath . '/' . $newName;
                            $pdo->prepare(
                                'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
                            )->execute([$logoSettingKey, $newRelativePath]);

                            $currentLogoPath = $newRelativePath;
                            $success = 'Logo přihlášení bylo úspěšně nahráno.';
                        }
                    }
                }
            }
        } elseif ($action === 'remove_login_logo') {
            if ($currentLogoPath !== '') {
                $oldPath = __DIR__ . '/../' . ltrim($currentLogoPath, '/');
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $pdo->prepare(
                'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            )->execute([$logoSettingKey, '']);

            $currentLogoPath = '';
            $success = 'Logo přihlášení bylo odebráno.';
        } elseif ($action === 'save_support_bank_account') {
            $bankAccount = normalizeBankAccountInput($_POST['support_bank_account'] ?? '');

            if ($bankAccount === false) {
                $error = 'Zadejte platné číslo účtu (např. 123456789/0800 nebo IBAN).';
            } else {
                $pdo->prepare(
                    'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
                )->execute([$supportBankAccountKey, $bankAccount ?? '']);
                $currentSupportBankAccount = $bankAccount ?? '';
                $success = $bankAccount !== null
                    ? 'Číslo účtu pro dobrovolné příspěvky bylo uloženo.'
                    : 'Číslo účtu pro dobrovolné příspěvky bylo vymazáno.';
            }
        } else {
            $version = trim($_POST['app_version'] ?? '');
            if ($version === '') {
                $error = 'Číslo verze nesmí být prázdné.';
            } elseif (!preg_match('/^[\w.\-]+$/', $version)) {
                $error = 'Číslo verze obsahuje nepovolené znaky. Povoleno: písmena, číslice, tečka, pomlčka.';
            } else {
                $pdo->prepare(
                    'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
                )->execute(['app_version', $version]);
                $currentVersion = $version;
                $success = 'Verze aplikace byla nastavena na "' . $version . '".';
            }
        }
    }
}

$logoPreviewUrl = null;
if ($currentLogoPath !== '') {
    $logoAbsolutePath = __DIR__ . '/../' . ltrim($currentLogoPath, '/');
    if (is_file($logoAbsolutePath)) {
        $logoPreviewUrl = BASE_URL . '/' . ltrim($currentLogoPath, '/');
    }
}

renderAdminHeader('Nastavení aplikace');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-sliders me-2" style="color:#a78bfa"></i>Nastavení aplikace
    </h4>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-1"></i><?= h($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle me-1"></i><?= h($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:480px">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-tag me-2"></i>Číslo verze aplikace
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_version">
            <div class="mb-3">
                <label for="versionInput" class="form-label fw-semibold">Aktuální verze</label>
                <input type="text" name="app_version" id="versionInput"
                       class="form-control form-control-lg fw-bold"
                       value="<?= h($currentVersion) ?>"
                       placeholder="např. 1.2.0"
                       required>
                <div class="form-text">
                    Zobrazuje se pod přihlašovacím formulářem. Povolený formát: <code>1.0.0</code>, <code>2.1.3-beta</code> apod.
                </div>
            </div>
            <button type="submit" class="btn fw-bold"
                    style="background:#7c3aed;color:#fff;border:none">
                <i class="fas fa-save me-1"></i>Uložit verzi
            </button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4" style="max-width:760px">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-heart me-2"></i>Dobrovolný příspěvek na provoz aplikace
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">Toto číslo účtu se zobrazí sportovcům i trenérům v modulu podpory. Příspěvek je čistě dobrovolný, aplikace zůstává bezplatná.</p>
        <form method="post" class="row g-3 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_support_bank_account">
            <div class="col-md-9">
                <label for="supportBankAccountInput" class="form-label fw-semibold">Číslo účtu pro příspěvky</label>
                <input type="text" name="support_bank_account" id="supportBankAccountInput" class="form-control form-control-lg" value="<?= h($currentSupportBankAccount) ?>" placeholder="Např. 123456789/0800 nebo CZ...">
                <div class="form-text">Použije se v QR platbě jako cílový účet pro podporu vývoje, provozu webhostingu a placených nástrojů.</div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn fw-bold w-100" style="background:#7c3aed;color:#fff;border:none">
                    <i class="fas fa-save me-1"></i>Uložit účet
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4" style="max-width:760px">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-image me-2"></i>Logo přihlášení
    </div>
    <div class="card-body">
        <?php if ($logoPreviewUrl): ?>
        <div class="mb-3">
            <div class="small text-muted mb-2">Aktuální logo</div>
            <img src="<?= h($logoPreviewUrl) ?>" alt="Login logo" style="max-width:320px;width:100%;height:auto;border:1px solid #ddd;border-radius:10px;padding:8px;background:#fff;">
        </div>
        <?php else: ?>
        <div class="alert alert-light border mb-3">Momentálně není nastavené žádné vlastní logo. Použije se text názvu aplikace.</div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="mb-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_login_logo">
            <div class="row g-3 align-items-end">
                <div class="col-md-9">
                    <label for="loginLogoInput" class="form-label fw-semibold">Nahrát nové logo</label>
                    <input type="file" name="login_logo" id="loginLogoInput" class="form-control" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml" required>
                    <div class="form-text">Povolené formáty: png, jpg, jpeg, webp, svg. Maximálně 5 MB.</div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn fw-bold w-100" style="background:#7c3aed;color:#fff;border:none">
                        <i class="fas fa-upload me-1"></i>Nahrát logo
                    </button>
                </div>
            </div>
        </form>

        <?php if ($logoPreviewUrl): ?>
        <form method="post" onsubmit="return confirm('Odebrat aktuální logo přihlášení?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="remove_login_logo">
            <button type="submit" class="btn btn-outline-danger fw-semibold">
                <i class="fas fa-trash me-1"></i>Odebrat logo
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php
echo '</div></div></div>';
echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
echo '</body></html>';
?>
