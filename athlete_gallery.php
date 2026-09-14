<?php
// athlete_gallery.php – galerie pro prihlaseneho sportovce (prohlizeni)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();
$athleteId = getCurrentAthleteId();
$pdo       = getDB();

$athlete = $pdo->prepare("SELECT a.*, c.id AS coach_id FROM athletes a JOIN coaches c ON c.id = a.coach_id WHERE a.id = ?");
$athlete->execute([$athleteId]);
$athlete = $athlete->fetch();
if (!$athlete) {
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$coachId = (int)$athlete['coach_id'];

$files = $pdo->prepare("
    SELECT gf.*
    FROM gallery_files gf
    WHERE gf.coach_id = ?
      AND (
          gf.visibility = 'all_athletes'
          OR (
              gf.visibility = 'specific_athletes'
              AND EXISTS (
                  SELECT 1
                  FROM gallery_file_athletes gfa
                  WHERE gfa.file_id = gf.id
                    AND gfa.athlete_id = ?
              )
          )
      )
    ORDER BY gf.created_at DESC
");
$files->execute([$coachId, $athleteId]);
$sharedFiles = $files->fetchAll();

// Zobrazime jen soubory, ktere fyzicky existuji na disku.
$sharedFiles = array_values(array_filter($sharedFiles, static function (array $f) use ($coachId): bool {
    $full = __DIR__ . '/uploads/gallery/coach_' . $coachId . '/' . ($f['file_path'] ?? '');
    return is_string($f['file_path'] ?? null) && $f['file_path'] !== '' && file_exists($full);
}));

foreach ($sharedFiles as &$sharedFile) {
    $sharedFile['_source'] = 'coach';
}
unset($sharedFile);

$adminAthleteRecipientsAvailable = (bool)$pdo->query("SHOW TABLES LIKE 'admin_gallery_file_athletes'")->fetch();
$specificAdminAthleteCondition = $adminAthleteRecipientsAvailable
    ? "OR (
           agf.visibility = 'specific_athletes'
           AND EXISTS (
               SELECT 1
               FROM admin_gallery_file_athletes agfa
               WHERE agfa.file_id = agf.id
                 AND agfa.athlete_id = ?
           )
       )"
    : '';
$adminFilesStmt = $pdo->prepare("
    SELECT agf.*
    FROM admin_gallery_files agf
    WHERE agf.visibility IN ('all_athletes', 'all_users')
       $specificAdminAthleteCondition
    ORDER BY agf.created_at DESC
");
$adminFilesStmt->execute($adminAthleteRecipientsAvailable ? [$athleteId] : []);
$adminFiles = $adminFilesStmt->fetchAll();
$adminFiles = array_values(array_filter($adminFiles, static function (array $file): bool {
    $filePath = (string)($file['file_path'] ?? '');
    return $filePath !== '' && file_exists(__DIR__ . '/uploads/gallery/admin/' . $filePath);
}));
foreach ($adminFiles as &$adminFile) {
    $adminFile['_source'] = 'admin';
}
unset($adminFile);

$pdo->prepare(
        "UPDATE athlete_notifications
         SET read_at = COALESCE(read_at, NOW())
         WHERE athlete_id = ?
             AND read_at IS NULL
             AND subject = 'Nový soubor v galerii od administrátora'"
)->execute([$athleteId]);

function buildSharedSignature(array $files): string
{
    $parts = [];
    foreach ($files as $f) {
        $parts[] = implode('|', [
            (string)($f['id'] ?? ''),
            (string)($f['file_path'] ?? ''),
            (string)($f['created_at'] ?? ''),
            (string)($f['visibility'] ?? ''),
            (string)($f['_source'] ?? ''),
            (string)($f['title'] ?? ''),
            (string)($f['description'] ?? ''),
        ]);
    }
    return sha1(implode(';', $parts));
}

function renderSharedFilesSection(array $sharedFiles, string $uploadBaseUrl, string $heading, string $headingIcon): string
{
    ob_start();
    if (empty($sharedFiles)): ?>
<div class="alert alert-light border text-center py-5">
    <i class="fas fa-images fa-3x text-muted mb-3 d-block"></i>
    <p class="text-muted mb-0">Zatim zde nejsou zadne sdilene soubory.</p>
</div>
<?php else: ?>
<div class="mb-5">
    <h5 class="fw-bold mb-3">
        <i class="fas <?= h($headingIcon) ?> me-2 text-primary"></i><?= h($heading) ?>
        <span class="badge bg-secondary ms-2" style="font-size:.75rem"><?= count($sharedFiles) ?></span>
    </h5>
    <div class="row g-3">
        <?php foreach ($sharedFiles as $f): ?>
        <div class="col-6 col-md-4 col-lg-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <?php
                $fileSrc = $uploadBaseUrl . '/' . rawurlencode($f['file_path']);
                $ico = match($f['file_type']) { 'image' => 'fa-image', 'video' => 'fa-video', default => 'fa-file-alt' };
                $icoColor = match($f['file_type']) { 'image' => 'text-success', 'video' => 'text-danger', default => 'text-info' };
                $previewType = $f['file_type'] === 'document' ? 'document' : $f['file_type'];
                $postTitle = trim((string)($f['title'] ?? '')) ?: (string)$f['original_name'];
                $previewCall = 'openGalleryPreview(' . implode(', ', [
                    json_encode($previewType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                    json_encode($fileSrc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                    json_encode($postTitle, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                    json_encode((string)($f['description'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                    json_encode(date('d.m.Y H:i', strtotime($f['created_at'])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                ]) . ')';
                ?>
                <?php if ($f['file_type'] === 'image'): ?>
                <button type="button" class="btn p-0 border-0 d-block w-100 text-start"
                    onclick="<?= h($previewCall) ?>">
                    <img src="<?= $fileSrc ?>" alt="<?= h($postTitle) ?>"
                         style="width:100%;height:120px;object-fit:cover;border-radius:.375rem .375rem 0 0">
                </button>
                <?php elseif ($f['file_type'] === 'video'): ?>
                <div class="d-flex align-items-center justify-content-center" style="height:100px;background:#f8f9fa;border-radius:.375rem .375rem 0 0">
                    <button type="button" class="btn p-0 border-0 text-decoration-none"
                            onclick="<?= h($previewCall) ?>">
                        <i class="fas <?= $ico ?> <?= $icoColor ?>" style="font-size:2.5rem"></i>
                    </button>
                </div>
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center" style="height:80px;background:#f8f9fa;border-radius:.375rem .375rem 0 0">
                    <button type="button" class="btn p-0 border-0 text-decoration-none"
                            onclick="<?= h($previewCall) ?>">
                        <i class="fas <?= $ico ?> <?= $icoColor ?>" style="font-size:2rem"></i>
                    </button>
                </div>
                <?php endif; ?>
                <div class="card-body p-2">
                    <div class="small fw-semibold text-truncate"><?= h($postTitle) ?></div>
                    <?php if ($f['description']): ?>
                    <div class="text-muted" style="font-size:.75rem"><?= h(mb_strimwidth($f['description'], 0, 60, '...')) ?></div>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:.7rem"><?= date('d.m.Y', strtotime($f['created_at'])) ?></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" style="font-size:.75rem"
                            onclick="<?= h($previewCall) ?>">
                        <i class="fas fa-eye me-1"></i>Otevrit
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
    endif;
    return (string)ob_get_clean();
}

$sharedSignature = buildSharedSignature(array_merge($adminFiles, $sharedFiles));
$sharedHtml = '';
if ($adminFiles !== []) {
    $sharedHtml .= renderSharedFilesSection(
        $adminFiles,
        BASE_URL . '/uploads/gallery/admin',
        'TrainerApp galerie',
        'fa-images'
    );
}
if ($sharedFiles !== []) {
    $sharedHtml .= renderSharedFilesSection(
        $sharedFiles,
        BASE_URL . '/uploads/gallery/coach_' . $coachId,
        'Od trenéra',
        'fa-user-check'
    );
}
if ($sharedHtml === '') {
    $sharedHtml = renderSharedFilesSection([], '', '', '');
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'signature' => $sharedSignature,
        'html' => $sharedHtml,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

renderAthleteHeader('Galerie', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-images me-2 text-warning"></i>Galerie</h2>
</div>

<div id="gallerySharedSection"><?= $sharedHtml ?></div>

<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-md-down modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div class="min-w-0">
                    <h5 class="modal-title text-break" id="filePreviewTitle">Příspěvek</h5>
                    <div class="small text-muted" id="filePreviewDate"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body p-3">
                <div id="filePreviewDescription" class="mb-3 text-break" style="white-space:pre-wrap"></div>
                <div id="filePreviewBody" class="bg-light rounded p-2" style="min-height:200px"></div>
            </div>
        </div>
    </div>
</div>

<script>
let gallerySignature = <?= json_encode($sharedSignature) ?>;

function openGalleryPreview(type, src, name, description, createdAt) {
    const title = document.getElementById('filePreviewTitle');
    const date = document.getElementById('filePreviewDate');
    const descriptionElement = document.getElementById('filePreviewDescription');
    const body = document.getElementById('filePreviewBody');
    if (!title || !date || !descriptionElement || !body) return;

    title.textContent = name || 'Příspěvek';
    date.textContent = createdAt || '';
    descriptionElement.textContent = description || '';
    descriptionElement.classList.toggle('d-none', !description);
    body.replaceChildren();

    let media;
    if (type === 'image') {
        media = document.createElement('img');
        media.alt = name || '';
        media.style.cssText = 'max-width:100%;max-height:70vh;display:block;margin:0 auto;border-radius:.5rem';
        media.src = src;
    } else if (type === 'video') {
        media = document.createElement('video');
        media.controls = true;
        media.style.cssText = 'width:100%;max-height:70vh;border-radius:.5rem;background:#000';
        media.src = src;
    } else {
        media = document.createElement('iframe');
        media.title = name || 'Náhled dokumentu';
        media.style.cssText = 'width:100%;height:70vh;border:0;border-radius:.5rem;background:#fff';
        media.src = src;
    }
    body.appendChild(media);

    const modalEl = document.getElementById('filePreviewModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

async function pollAthleteGallery() {
    try {
        const response = await fetch('<?= BASE_URL ?>/athlete_gallery.php?ajax=1&t=' + Date.now(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) return;

        const data = await response.json();
        if (!data || !data.ok || !data.signature) return;

        if (data.signature !== gallerySignature) {
            const section = document.getElementById('gallerySharedSection');
            if (!section) return;
            section.innerHTML = data.html || '';
            gallerySignature = data.signature;
        }
    } catch (e) {
        // Silent fail; next poll retries.
    }
}

setInterval(pollAthleteGallery, 10000);
</script>

<?php renderAthleteFooter(); ?>
