<?php
/** @var \PDO $pdo @var string $userType @var int $userId @var string $appUrl */

$itemId = (int)($_GET['id'] ?? 0);
if ($itemId <= 0) { flash('danger', 'Neplatná položka.'); redirect($appUrl); }

if (!mycoachAppIsLive() && !mycoachAppCanAccess($pdo, $userType, $userId)) {
    flash('warning', 'Přístup k MyCoach není aktivní.');
    redirect($appUrl);
}

try {
    $stmt = $pdo->prepare('SELECT * FROM mycoach_app_section_items WHERE id = ? AND is_active = 1 AND item_type = "link" LIMIT 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
} catch (Throwable $e) { $item = null; }

if (!$item) { flash('danger', 'Odkaz nebyl nalezen.'); redirect($appUrl); }

$section = null;
try {
    $sStmt = $pdo->prepare('SELECT * FROM mycoach_app_sections WHERE id = ? LIMIT 1');
    $sStmt->execute([(int)$item['section_id']]);
    $section = $sStmt->fetch() ?: null;
} catch (Throwable $e) {}

$sectionUrl = ($userType === 'coach')
    ? BASE_URL . '/mycoach_app_section.php?id=' . (int)($section['id'] ?? 0)
    : BASE_URL . '/athlete_mycoach_app_section.php?id=' . (int)($section['id'] ?? 0);

$targetUrl = (string)($item['url'] ?? '');
// Základní validace URL pro bezpečnost
$safeUrl = (filter_var($targetUrl, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $targetUrl))
    ? $targetUrl : '';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-app.css?v=20260813">
<style>
  .mca-webview-frame { width:100%; border:0; display:block; background:#fff; border-radius:10px; }
</style>

<!-- Header lišta -->
<div class="mca-hero mb-0" style="padding:.75rem 0;">
  <div class="container-fluid px-3 d-flex align-items-center justify-content-between gap-2 flex-wrap">
    <div>
      <div class="mca-breadcrumb" style="color:rgba(240,240,240,.6);">
        <a href="<?= h($appUrl) ?>" style="color:rgba(247,148,29,.8);"><i class="fas fa-brain me-1"></i>MyCoach</a>
        <?php if ($section): ?>
          <span class="mca-breadcrumb-sep">/</span>
          <a href="<?= h($sectionUrl) ?>" style="color:rgba(247,148,29,.8);"><?= h($section['title']) ?></a>
        <?php endif; ?>
      </div>
      <div class="mca-hero-title mt-1" style="font-size:1rem;">
        <i class="fas fa-external-link-alt me-1"></i><?= h($item['title']) ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($safeUrl !== ''): ?>
        <a href="<?= h($safeUrl) ?>" target="_blank" rel="noopener noreferrer"
           class="mca-btn-outline" style="font-size:.8rem;padding:.35rem .8rem;">
          <i class="fas fa-arrow-up-right-from-square me-1"></i>Otevřít v prohlížeči
        </a>
      <?php endif; ?>
      <a href="<?= h($sectionUrl) ?>" class="mca-btn-outline" style="font-size:.8rem;padding:.35rem .8rem;">
        <i class="fas fa-arrow-left me-1"></i>Zpět
      </a>
    </div>
  </div>
</div>

<?php if ($safeUrl !== ''): ?>
<div style="height:calc(100vh - 120px);padding:0;">
  <iframe src="<?= h($safeUrl) ?>"
          class="mca-webview-frame"
          style="height:100%;"
          sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"
          title="<?= h($item['title']) ?>">
    <p style="padding:1rem;">Váš prohlížeč nepodporuje zobrazení v rámci stránky.
       <a href="<?= h($safeUrl) ?>" target="_blank">Otevřít odkaz</a>
    </p>
  </iframe>
</div>
<?php else: ?>
<div class="container-fluid px-3 py-4">
  <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-1"></i>Neplatná URL odkazu.</div>
</div>
<?php endif; ?>
