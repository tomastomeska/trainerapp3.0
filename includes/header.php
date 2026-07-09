<?php
// includes/header.php
// Volá se: renderHeader('Název stránky');
require_once __DIR__ . '/support_widget.php';

function renderHeader(string $title = '', bool $withCharts = false, bool $compactNav = false): void {
    $coach   = getCurrentCoach();
    $flash   = getFlash();
    $appName = APP_NAME;
    $fullTitle = $title ? "$title – $appName" : $appName;
    ?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($fullTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .coach-navbar .nav-link {
            white-space: nowrap;
        }

        .coach-navbar .top-badge {
            font-size: .6rem !important;
            line-height: 1;
            padding: .3em .48em;
        }

        .coach-navbar .profile-link {
            display: inline-block;
            max-width: 165px;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: bottom;
        }

        @media (max-width: 1399.98px) {
            .coach-navbar .nav-link {
                padding-left: .45rem;
                padding-right: .45rem;
                font-size: .92rem;
            }

            .coach-navbar .profile-link {
                max-width: 125px;
            }
        }

        .coach-compact-topbar {
            display: none !important;
        }

        .impersonation-banner {
            background: #7c3aed;
            color: #fff;
            padding: 8px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            position: sticky;
            top: 56px;
            z-index: 1029;
            font-size: .9rem;
        }

        @media (max-width: 1427.98px) {
            .coach-navbar.coach-navbar-compact-mobile {
                display: none;
            }

            .coach-compact-topbar {
                display: flex !important;
            }

            .impersonation-banner.impersonation-banner-compact {
                top: 0;
            }
        }
    </style>
    <?php if ($withCharts): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <?php endif; ?>
</head>
<body>

<?php
$unreadMsgCount = 0;
$pendingCalendarCount = 0;
$unreadInfoCount = 0;
if ($coach) {
    try {
        $pdo = getDB();
        $unreadStmt = $pdo->prepare("
            SELECT COUNT(*) FROM admin_message_recipients
            WHERE coach_id = ? AND read_at IS NULL
        ");
        $unreadStmt->execute([$coach['id']]);
        $unreadMsgCount = (int)$unreadStmt->fetchColumn();

        $pendingCalendarStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'pending'
              AND requested_by_athlete_id IS NOT NULL
        ");
        $pendingCalendarStmt->execute([$coach['id']]);
        $pendingCalendarCount = (int)$pendingCalendarStmt->fetchColumn();

        $infoUnreadStmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM info_articles ia\n            JOIN info_categories ic ON ic.id = ia.category_id\n            LEFT JOIN info_article_reads_coach ir ON ir.article_id = ia.id AND ir.coach_id = ?\n            WHERE ia.is_active = 1\n              AND ia.published_at <= NOW()\n              AND ia.target_audience IN ('all', 'coach')\n              AND ic.is_active = 1\n              AND ic.audience IN ('all', 'coach')\n              AND ir.article_id IS NULL\n        ");
        $infoUnreadStmt->execute([(int)$coach['id']]);
        $unreadInfoCount = (int)$infoUnreadStmt->fetchColumn();
    } catch (Throwable $e) {
        $unreadMsgCount = 0;
        $pendingCalendarCount = 0;
        $unreadInfoCount = 0;
    }
}
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm sticky-top coach-navbar<?= $compactNav ? ' coach-navbar-compact-mobile' : '' ?>">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold text-warning" href="<?= BASE_URL ?>/dashboard.php">
            <i class="fas fa-dumbbell me-2"></i><?= h($appName) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/dashboard.php">
                        <i class="fas fa-users me-1"></i>Sportovci
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/exercises.php">
                        <i class="fas fa-list me-1"></i>Cviky
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/sady.php">
                        <i class="fas fa-layer-group me-1"></i>Sady
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link d-inline-flex align-items-center" href="<?= BASE_URL ?>/calendar.php">
                        <i class="fas fa-calendar-alt me-1"></i>Kalendář
                        <?php if ($pendingCalendarCount > 0): ?>
                        <span class="badge rounded-pill bg-warning text-dark ms-1" style="font-size:.65rem">
                            <?= $pendingCalendarCount ?>
                        </span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/payments.php">
                        <i class="fas fa-wallet me-1"></i>Platby
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/meal_plans.php">
                        <i class="fas fa-utensils me-1"></i>Jídelníčky
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/gallery.php">
                        <i class="fas fa-images me-1"></i>Galerie
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/coach_manual.php">
                        <i class="fas fa-circle-question me-1"></i>Návod
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/coach_terms.php">
                        <i class="fas fa-file-contract me-1"></i>Podmínky
                    </a>
                </li>
            </ul>
            <?php if ($coach): ?>
            <div class="navbar-nav">
                <a class="nav-link d-inline-flex align-items-center" href="<?= BASE_URL ?>/zpravy.php" title="Zprávy">
                    <i class="fas fa-envelope me-1"></i><span class="d-none d-xl-inline">Zprávy</span>
                    <?php if ($unreadMsgCount > 0): ?>
                    <span class="badge rounded-pill bg-danger ms-1 top-badge">
                        <?= $unreadMsgCount ?>
                    </span>
                    <?php endif; ?>
                </a>
                <a class="nav-link d-inline-flex align-items-center" href="<?= BASE_URL ?>/infokanal.php" title="Infokanál" aria-label="Infokanál">
                    <i class="fas fa-lightbulb" style="font-size:1.1rem"></i>
                    <?php if ($unreadInfoCount > 0): ?>
                    <span class="badge rounded-pill bg-danger ms-1 top-badge">
                        <?= $unreadInfoCount ?>
                    </span>
                    <?php endif; ?>
                </a>
                <a class="nav-link text-secondary" href="<?= BASE_URL ?>/profile.php">
                    <i class="fas fa-user-tie me-1"></i><span class="profile-link"><?= h($coach['name'] ?: $coach['username']) ?></span>
                </a>
                <a class="nav-link text-danger" href="<?= BASE_URL ?>/logout.php">
                    <i class="fas fa-sign-out-alt me-1"></i>Odhlásit
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<?php if (!empty($_SESSION['impersonating_admin_id'])): ?>
<div class="impersonation-banner<?= $compactNav ? ' impersonation-banner-compact' : '' ?>">
    <span>
        <i class="fas fa-user-secret me-2"></i>
        Prohlížíte profil trenéra <strong><?= h($_SESSION['coach_name'] ?? '') ?></strong> jako administrátor.
    </span>
    <a href="<?= BASE_URL ?>/admin/end_impersonate.php"
       class="btn btn-sm ms-auto fw-bold"
       style="background:#fff;color:#7c3aed;border:none;white-space:nowrap">
        <i class="fas fa-arrow-left me-1"></i>Zpět do admin panelu
    </a>
</div>
<?php endif; ?>

<div class="container-fluid px-3 px-md-4 py-3 py-md-4">
<?php if ($flash): ?>
<div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= !empty($flash['html']) ? $flash['message'] : h($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($compactNav && $coach): ?>
<div class="d-flex coach-compact-topbar justify-content-between align-items-center gap-2 flex-wrap mb-3">
    <div class="fw-bold fs-5"><?= h($title ?: 'Přehled') ?></div>
    <div class="d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm fw-bold">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <a href="<?= BASE_URL ?>/logout.php" class="btn btn-danger btn-sm fw-bold">
            <i class="fas fa-sign-out-alt me-1"></i>Odhlásit
        </a>
    </div>
</div>
<?php endif; ?>

<?php
// Hláška po přihlášení: zobrazit jen pokud existuje zpráva, trenér ji trvale neskryl
// a v této session ještě nebyla zobrazena (= jednou za přihlášení)
$_loginMsg = null;
if ($coach && empty($_SESSION['login_msg_shown'])) {
    $_pdo2 = getDB();
    $_lm   = $_pdo2->query('SELECT * FROM login_message ORDER BY id DESC LIMIT 1')->fetch();
    if ($_lm) {
        $_seen = $_pdo2->prepare(
            'SELECT 1 FROM coach_message_seen WHERE coach_id = ? AND message_version = ?'
        );
        $_seen->execute([$coach['id'], $_lm['version']]);
        if (!$_seen->fetch()) {
            $_loginMsg = $_lm;
            $_SESSION['login_msg_shown'] = true; // nezobrazovat víckrát ve stejné session
        }
    }
}
if ($_loginMsg):
    // Rozdělit zprávu: první řádek = nadpis (tučně), zbytek = tělo
    $_lmLines = explode("\n", $_loginMsg['message'], 2);
    $_lmTitle = trim($_lmLines[0]);
    $_lmBody  = isset($_lmLines[1]) ? trim($_lmLines[1]) : '';
?>
<!-- Modal: hláška po přihlášení -->
<div class="modal fade" id="loginMessageModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header" style="background:#1e1e2e;border-bottom:2px solid #f59e0b">
                <span style="font-size:1.1rem;font-weight:700;color:#f59e0b">
                    <i class="fas fa-triangle-exclamation me-2"></i>Důležité upozornění
                </span>
            </div>
            <div class="modal-body">
                <?php if ($_lmTitle !== ''): ?>
                <p class="fw-bold mb-2" style="font-size:1rem"><?= h($_lmTitle) ?></p>
                <?php endif; ?>
                <?php if ($_lmBody !== ''): ?>
                <p class="mb-0" style="white-space:pre-wrap;color:#374151"><?= h($_lmBody) ?></p>
                <?php endif; ?>
            </div>
            <div class="modal-footer flex-column align-items-start gap-2">
                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" id="loginMsgDismiss">
                    <label class="form-check-label text-muted" for="loginMsgDismiss">
                        Příště nezobrazovat
                    </label>
                </div>
                <button type="button" class="btn btn-warning w-100 fw-bold" id="loginMsgOk">
                    <i class="fas fa-check me-1"></i>Rozumím
                </button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal   = new bootstrap.Modal(document.getElementById('loginMessageModal'));
    var version = <?= (int)$_loginMsg['version'] ?>;
    var csrfToken = <?= json_encode(csrfToken()) ?>;

    modal.show();

    document.getElementById('loginMsgOk').addEventListener('click', function () {
        var dismiss = document.getElementById('loginMsgDismiss').checked;
        if (dismiss) {
            // Trvale uložit do DB, pak zavřít
            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('message_version', version);
            fetch('<?= BASE_URL ?>/api/dismiss_login_message.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).finally(function () { modal.hide(); });
        } else {
            modal.hide();
        }
    });
});
</script>
<?php endif; ?>
    <?php
}

function renderFooter(): void {
    $appName = APP_NAME;
    ?>
</div><!-- /container-fluid -->

<footer class="footer mt-auto py-3 bg-dark text-center text-secondary">
    <small><?= h($appName) ?> &copy; <?= date('Y') ?></small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<?php renderSupportWidget('coach'); ?>
</body>
</html>
<?php
}
