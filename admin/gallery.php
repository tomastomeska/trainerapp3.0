<?php
// admin/gallery.php – galerie administrátora
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();
$pdo     = getDB();
$adminId = $_SESSION['admin_id'] ?? null;

// Všichni aktivní trenéři
$coaches = $pdo->query("SELECT id, name, username, email FROM coaches WHERE is_active = 1 ORDER BY name")->fetchAll();
$coachesById = [];
foreach ($coaches as $coach) {
    $coachesById[(int)$coach['id']] = $coach;
}
$athletes = $pdo->query(
    "SELECT a.id, a.first_name, a.last_name, a.email, c.name AS coach_name
     FROM athletes a
     LEFT JOIN coaches c ON c.id = a.coach_id
     ORDER BY a.last_name, a.first_name"
)->fetchAll();
$athleteIds = array_map('intval', array_column($athletes, 'id'));
$athletesById = [];
foreach ($athletes as $athlete) {
    $athletesById[(int)$athlete['id']] = $athlete;
}
$validCoachIds = array_map('intval', array_column($coaches, 'id'));
$allowedVisibilities = ['all_coaches', 'specific_coaches', 'all_athletes', 'specific_athletes', 'all_users'];
$descriptionMaxLength = 10000;

$errors = [];
$visibilityColumn = $pdo->query("SHOW COLUMNS FROM admin_gallery_files LIKE 'visibility'")->fetch();
$visibilityType = (string)($visibilityColumn['Type'] ?? '');
$titleColumn = $pdo->query("SHOW COLUMNS FROM admin_gallery_files LIKE 'title'")->fetch();
$galleryPostTitleEnabled = (bool)$titleColumn;
$athleteRecipientsTable = $pdo->query("SHOW TABLES LIKE 'admin_gallery_file_athletes'")->fetch();
$athleteAudienceEnabled = str_contains($visibilityType, "'specific_athletes'")
    && str_contains($visibilityType, "'all_users'")
    && (bool)$athleteRecipientsTable;
$galleryUpgradeReady = $athleteAudienceEnabled && $galleryPostTitleEnabled;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/gallery.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'enable_athlete_audience') {
        try {
            $pdo->exec(
                "ALTER TABLE admin_gallery_files
                 MODIFY visibility ENUM('all_coaches','specific_coaches','all_athletes','specific_athletes','all_users') NOT NULL DEFAULT 'all_coaches'"
            );
            if (!$galleryPostTitleEnabled) {
                $pdo->exec('ALTER TABLE admin_gallery_files ADD COLUMN title VARCHAR(180) NULL AFTER original_name');
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS admin_gallery_file_athletes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    file_id INT NOT NULL,
                    athlete_id INT NOT NULL,
                    UNIQUE KEY uq_admin_gallery_file_athlete (file_id, athlete_id),
                    CONSTRAINT fk_admin_gallery_file_athletes_file
                        FOREIGN KEY (file_id) REFERENCES admin_gallery_files(id) ON DELETE CASCADE,
                    CONSTRAINT fk_admin_gallery_file_athletes_athlete
                        FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            flash('success', 'Galerie byla aktualizována. Lze používat vlastní názvy i všechna publika.');
        } catch (Throwable $e) {
            error_log('Admin gallery athlete audience migration failed: ' . $e->getMessage());
            flash('danger', 'Databázi se nepodařilo upravit. Kontaktujte správce serveru.');
        }
        redirect(BASE_URL . '/admin/gallery.php');
    }

    if ($action === 'update_visibility') {
        $fileId = intParam($_POST, 'file_id');
        $postTitle = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180, 'UTF-8');
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, $descriptionMaxLength, 'UTF-8');
        $visibility = in_array($_POST['visibility'] ?? '', $allowedVisibilities, true)
            ? (string)$_POST['visibility']
            : 'all_coaches';
        $specificCoachIds = array_values(array_intersect(
            array_map('intval', array_filter($_POST['specific_coaches'] ?? [])),
            $validCoachIds
        ));
        $specificAthleteIds = array_values(array_intersect(
            array_map('intval', array_filter($_POST['specific_athletes'] ?? [])),
            $athleteIds
        ));

        if ($visibility === 'specific_coaches' && $specificCoachIds === []) {
            flash('danger', 'Vyberte alespoň jednoho aktivního trenéra.');
            redirect(BASE_URL . '/admin/gallery.php');
        }
        if ($postTitle === '') {
            flash('danger', 'Vyplňte vlastní název příspěvku.');
            redirect(BASE_URL . '/admin/gallery.php');
        }
        if (!$galleryPostTitleEnabled) {
            flash('danger', 'Nejprve aktualizujte galerii.');
            redirect(BASE_URL . '/admin/gallery.php');
        }
        if ($visibility === 'specific_athletes' && $specificAthleteIds === []) {
            flash('danger', 'Vyberte alespoň jednoho sportovce.');
            redirect(BASE_URL . '/admin/gallery.php');
        }
        if (!$athleteAudienceEnabled && in_array($visibility, ['all_athletes', 'specific_athletes', 'all_users'], true)) {
            flash('danger', 'Nejprve povolte všechna publika galerie.');
            redirect(BASE_URL . '/admin/gallery.php');
        }

        $fileStmt = $pdo->prepare('SELECT id FROM admin_gallery_files WHERE id = ?');
        $fileStmt->execute([$fileId]);
        if (!$fileStmt->fetch()) {
            flash('danger', 'Soubor nebyl nalezen.');
            redirect(BASE_URL . '/admin/gallery.php');
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE admin_gallery_files SET title = ?, description = ?, visibility = ? WHERE id = ?')
                ->execute([$postTitle, $description !== '' ? $description : null, $visibility, $fileId]);
            $pdo->prepare('DELETE FROM admin_gallery_file_coaches WHERE file_id = ?')->execute([$fileId]);
            if ($athleteAudienceEnabled) {
                $pdo->prepare('DELETE FROM admin_gallery_file_athletes WHERE file_id = ?')->execute([$fileId]);
            }

            if ($visibility === 'specific_coaches') {
                $insertCoach = $pdo->prepare('INSERT INTO admin_gallery_file_coaches (file_id, coach_id) VALUES (?, ?)');
                foreach ($specificCoachIds as $coachId) {
                    $insertCoach->execute([$fileId, $coachId]);
                }
            }
            if ($visibility === 'specific_athletes') {
                $insertAthlete = $pdo->prepare('INSERT INTO admin_gallery_file_athletes (file_id, athlete_id) VALUES (?, ?)');
                foreach ($specificAthleteIds as $athleteId) {
                    $insertAthlete->execute([$fileId, $athleteId]);
                }
            }

            $pdo->commit();
            flash('success', 'Příspěvek byl upraven.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin gallery visibility update failed: ' . $e->getMessage());
            flash('danger', 'Viditelnost souboru se nepodařilo změnit.');
        }
        redirect(BASE_URL . '/admin/gallery.php');
    }

    // Nahrání souboru
    if ($action === 'upload') {
        $postTitle = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180, 'UTF-8');
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, $descriptionMaxLength, 'UTF-8');
        $visibility  = in_array($_POST['visibility'] ?? '', $allowedVisibilities, true)
                       ? $_POST['visibility'] : 'all_coaches';
        $specificCoachIds = array_map('intval', array_filter($_POST['specific_coaches'] ?? []));
        $specificAthleteIds = array_map('intval', array_filter($_POST['specific_athletes'] ?? []));
        $specificCoachIds = array_values(array_intersect($specificCoachIds, $validCoachIds));
        $specificAthleteIds = array_values(array_intersect($specificAthleteIds, $athleteIds));

        if (!$galleryPostTitleEnabled) {
            $errors[] = 'Nejprve aktualizujte galerii.';
        } elseif ($postTitle === '') {
            $errors[] = 'Vyplňte vlastní název příspěvku.';
        }

        if ($visibility === 'specific_coaches' && $specificCoachIds === []) {
            $errors[] = 'Vyberte alespoň jednoho aktivního trenéra.';
        }
        if ($visibility === 'specific_athletes' && $specificAthleteIds === []) {
            $errors[] = 'Vyberte alespoň jednoho sportovce.';
        }
        if (!$athleteAudienceEnabled && in_array($visibility, ['all_athletes', 'specific_athletes', 'all_users'], true)) {
            $errors[] = 'Nejprve povolte všechna publika galerie.';
        }

        $allowed = ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','webm',
                    'pdf','doc','docx','xls','xlsx','csv','txt','zip','rar','7z','ppt','pptx'];

        $uploadDir = dirname(__DIR__) . '/uploads/gallery/admin/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $uploadedCount = 0;
        $uploadedIds   = [];
        $files         = $_FILES['files'] ?? [];

        if (!is_array($files['name'])) {
            $files = ['name' => [$files['name']], 'type' => [$files['type']],
                      'tmp_name' => [$files['tmp_name']], 'error' => [$files['error']], 'size' => [$files['size']]];
        }

        foreach (empty($errors) ? $files['name'] : [] as $i => $origName) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK || !$origName) continue;
            $size = $files['size'][$i];
            if ($size > 200 * 1024 * 1024) { $errors[] = h($origName) . ': max 200 MB.'; continue; }
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) { $errors[] = h($origName) . ': typ .' . $ext . ' není povolen.'; continue; }

            $newName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($files['tmp_name'][$i], $uploadDir . $newName)) {
                $errors[] = h($origName) . ': nepodařilo se uložit.'; continue;
            }

            $mime  = mime_content_type($uploadDir . $newName) ?: '';
            $ftype = str_starts_with($mime, 'image/') ? 'image'
                   : (str_starts_with($mime, 'video/') ? 'video' : 'document');

            $ins = $pdo->prepare("INSERT INTO admin_gallery_files (file_path, original_name, title, file_size, file_type, mime_type, description, visibility, uploaded_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$newName, $origName, $postTitle, $size, $ftype, $mime, $description ?: null, $visibility, $adminId]);
            $newFileId = (int)$pdo->lastInsertId();
            $uploadedIds[] = $newFileId;

            if ($visibility === 'specific_coaches') {
                $insVis = $pdo->prepare("INSERT IGNORE INTO admin_gallery_file_coaches (file_id, coach_id) VALUES (?, ?)");
                foreach ($specificCoachIds as $cid) {
                    $insVis->execute([$newFileId, $cid]);
                }
            }
            if ($visibility === 'specific_athletes') {
                $insVis = $pdo->prepare("INSERT IGNORE INTO admin_gallery_file_athletes (file_id, athlete_id) VALUES (?, ?)");
                foreach ($specificAthleteIds as $athleteId) {
                    $insVis->execute([$newFileId, $athleteId]);
                }
            }
            $uploadedCount++;
        }

        if ($uploadedCount > 0) {
            $emailExcerpt = preg_replace('/\s+/u', ' ', $description) ?? $description;
            $emailExcerpt = mb_strimwidth(trim($emailExcerpt), 0, 300, '…', 'UTF-8');
            $notifyCoachIds = match ($visibility) {
                'all_coaches', 'all_users' => $validCoachIds,
                'specific_coaches' => $specificCoachIds,
                default => [],
            };
            $notifyAthleteIds = match ($visibility) {
                'all_athletes', 'all_users' => $athleteIds,
                'specific_athletes' => $specificAthleteIds,
                default => [],
            };

            foreach ($notifyAthleteIds as $athleteId) {
                $subject = 'Nový soubor v galerii od administrátora';
                $message = "Administrátor přidal nové soubory do galerie TrainerApp.\n\nPřejděte do sekce Galerie a prohlédněte si je.";
                createAthleteNotification($athleteId, $subject, $message);

                $athleteRecipient = $athletesById[$athleteId] ?? null;
                if ($athleteRecipient && !empty($athleteRecipient['email'])) {
                    $athleteName = trim((string)$athleteRecipient['first_name'] . ' ' . (string)$athleteRecipient['last_name']);
                    call_user_func(
                        'sendGalleryNotificationEmail',
                        (string)$athleteRecipient['email'],
                        $athleteName !== '' ? $athleteName : 'sportovče',
                        'athlete',
                        $postTitle,
                        $emailExcerpt
                    );
                }
            }

            foreach ($notifyCoachIds as $coachId) {
                createCoachSystemMessage(
                    $coachId,
                    'Nový soubor v galerii od administrátora',
                    "Administrátor přidal nové soubory do galerie TrainerApp.\n\nPřejděte do sekce Galerie a prohlédněte si je.",
                    false
                );
                $coachRecipient = $coachesById[$coachId] ?? null;
                if ($coachRecipient && !empty($coachRecipient['email'])) {
                    $coachName = trim((string)($coachRecipient['name'] ?: $coachRecipient['username']));
                    call_user_func(
                        'sendGalleryNotificationEmail',
                        (string)$coachRecipient['email'],
                        $coachName !== '' ? $coachName : 'trenére',
                        'coach',
                        $postTitle,
                        $emailExcerpt
                    );
                }
            }

            if (($notifyCoachIds !== [] || $notifyAthleteIds !== []) && isEmailQueueEnabled()) {
                try {
                    processEmailNotificationQueue(200, 'gallery_notification');
                } catch (Throwable $e) {
                    error_log('Immediate gallery email processing failed: ' . $e->getMessage());
                }
            }

            $recipientLabel = $visibility === 'all_users'
                ? 'Trenéři i sportovci byli upozorněni.'
                : ($notifyAthleteIds !== [] ? 'Sportovci byli upozorněni.' : 'Trenéři byli upozorněni.');
            $flashType = empty($errors) ? 'success' : 'warning';
            $failureLabel = empty($errors) ? '' : ' Některé soubory se nepodařilo nahrát.';
            flash($flashType, "Nahráno $uploadedCount soubor(ů). $recipientLabel$failureLabel");
            redirect(BASE_URL . '/admin/gallery.php');
        }

        if (!empty($errors) && $uploadedCount === 0) {
            // Zobrazíme chyby dole
        }
    }

    // Smazání souboru
    if ($action === 'delete') {
        $fileId = intParam($_POST, 'file_id');
        $f = $pdo->prepare("SELECT * FROM admin_gallery_files WHERE id = ?");
        $f->execute([$fileId]);
        $f = $f->fetch();
        if ($f) {
            $full = dirname(__DIR__) . '/uploads/gallery/admin/' . $f['file_path'];
            if (file_exists($full)) @unlink($full);
            $pdo->prepare("DELETE FROM admin_gallery_files WHERE id = ?")->execute([$fileId]);
            flash('success', 'Soubor byl smazán.');
        }
        redirect(BASE_URL . '/admin/gallery.php');
    }
}

// Načíst soubory admina se statistikami
$adminFiles = $pdo->query("
    SELECT agf.*,
           (SELECT COUNT(*) FROM admin_gallery_file_coaches agfc WHERE agfc.file_id = agf.id) AS coach_count,
           (SELECT GROUP_CONCAT(agfc.coach_id) FROM admin_gallery_file_coaches agfc WHERE agfc.file_id = agf.id) AS coach_ids,
           " . ($athleteAudienceEnabled
               ? "(SELECT COUNT(*) FROM admin_gallery_file_athletes agfa WHERE agfa.file_id = agf.id)"
               : "0") . " AS athlete_count,
           " . ($athleteAudienceEnabled
               ? "(SELECT GROUP_CONCAT(agfa.athlete_id) FROM admin_gallery_file_athletes agfa WHERE agfa.file_id = agf.id)"
               : "NULL") . " AS athlete_ids
    FROM admin_gallery_files agf
    ORDER BY agf.created_at DESC
")->fetchAll();

renderAdminHeader('Galerie');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="fw-bold mb-0"><i class="fas fa-images me-2 text-primary"></i>Galerie administrátora</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUpload">
        <i class="fas fa-cloud-upload-alt me-1"></i>Nahrát soubory
    </button>
</div>

<?php if (!$galleryUpgradeReady): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
        <i class="fas fa-database me-2"></i>
        Pro vlastní názvy příspěvků a všechna publika je potřeba jednorázově aktualizovat galerii.
    </div>
    <form method="post" class="m-0">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="enable_athlete_audience">
        <button type="submit" class="btn btn-warning fw-semibold">
            <i class="fas fa-play me-1"></i>Aktualizovat galerii
        </button>
    </form>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if (empty($adminFiles)): ?>
<div class="alert alert-light border text-center py-5">
    <i class="fas fa-images fa-3x text-muted mb-3 d-block"></i>
    <p class="text-muted">Zatím nejsou žádné soubory v galerii administrátora.</p>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUpload">
        <i class="fas fa-cloud-upload-alt me-1"></i>Nahrát první soubor
    </button>
</div>
<?php else: ?>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-dark">
                <tr>
                    <th style="width:50px">Typ</th>
                    <th>Název</th>
                    <th>Popis</th>
                    <th>Viditelnost</th>
                    <th>Velikost</th>
                    <th>Datum</th>
                    <th style="width:145px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($adminFiles as $f): ?>
            <?php
            $ico = match($f['file_type']) { 'image' => 'fa-image text-success', 'video' => 'fa-video text-danger', default => 'fa-file-alt text-info' };
            $src = BASE_URL . '/uploads/gallery/admin/' . rawurlencode($f['file_path']);
            $postTitle = trim((string)($f['title'] ?? '')) ?: (string)$f['original_name'];
            $selectedCoachIds = array_values(array_filter(array_map('intval', explode(',', (string)($f['coach_ids'] ?? '')))));
            $selectedAthleteIds = array_values(array_filter(array_map('intval', explode(',', (string)($f['athlete_ids'] ?? '')))));
            ?>
            <tr>
                <td class="text-center"><i class="fas <?= $ico ?> fa-lg"></i></td>
                <td>
                    <a href="<?= $src ?>" target="_blank" class="fw-semibold text-decoration-none">
                        <?= h($postTitle) ?>
                    </a>
                    <div class="small text-muted text-truncate"><?= h($f['original_name']) ?></div>
                </td>
                <td class="text-muted small"><?= h(mb_strimwidth($f['description'] ?? '', 0, 60, '…')) ?: '—' ?></td>
                <td>
                    <?php
                    $visibilityBadge = match($f['visibility']) {
                        'all_coaches' => ['bg-success', 'Všichni trenéři'],
                        'specific_coaches' => ['bg-warning text-dark', (int)$f['coach_count'] . ' vybraných trenérů'],
                        'all_athletes' => ['bg-primary', 'Všichni sportovci'],
                        'specific_athletes' => ['bg-info text-dark', (int)$f['athlete_count'] . ' vybraných sportovců'],
                        'all_users' => ['bg-dark', 'Trenéři i sportovci'],
                        default => ['bg-secondary', 'Neznámé publikum'],
                    };
                    ?>
                    <span class="badge <?= $visibilityBadge[0] ?>"><?= h($visibilityBadge[1]) ?></span>
                </td>
                <td class="text-muted small"><?= round($f['file_size'] / 1024, 1) ?> KB</td>
                <td class="text-muted small text-nowrap"><?= date('d.m.Y H:i', strtotime($f['created_at'])) ?></td>
                <td class="text-end">
                    <a href="<?= $src ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Otevřít">
                        <i class="fas fa-eye"></i>
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary me-1 js-edit-visibility"
                            title="<?= $galleryUpgradeReady ? 'Upravit příspěvek' : 'Nejprve aktualizujte galerii' ?>"
                            <?= $galleryUpgradeReady ? '' : 'disabled' ?>
                            data-bs-toggle="modal" data-bs-target="#modalVisibility"
                            data-file-id="<?= (int)$f['id'] ?>"
                            data-file-name="<?= h($f['original_name']) ?>"
                            data-title="<?= h($postTitle) ?>"
                            data-description="<?= h((string)($f['description'] ?? '')) ?>"
                            data-visibility="<?= h($f['visibility']) ?>"
                            data-coach-ids="<?= h(json_encode($selectedCoachIds)) ?>"
                            data-athlete-ids="<?= h(json_encode($selectedAthleteIds)) ?>">
                        <i class="fas fa-users-gear"></i>
                    </button>
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Opravdu smazat soubor <?= h(addslashes($f['original_name'])) ?>?')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Smazat">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Nahrát soubory -->
<div class="modal fade" id="modalUpload" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="upload">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cloud-upload-alt me-2"></i>Nahrát soubory do galerie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Soubory <small class="text-muted">(max 200 MB každý)</small></label>
                    <input type="file" name="files[]" class="form-control" multiple required
                           accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.rar,.7z,.ppt,.pptx">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Název příspěvku</label>
                    <input type="text" name="title" class="form-control" maxlength="180" required
                           placeholder="Např. Regenerace svalů po tréninku">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Popis <small class="text-muted">(volitelný)</small></label>
                    <textarea name="description" class="form-control" rows="5" maxlength="<?= $descriptionMaxLength ?>"></textarea>
                    <div class="form-text">Maximálně <?= number_format($descriptionMaxLength, 0, ',', ' ') ?> znaků.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Viditelnost</label>
                    <select name="visibility" class="form-select" id="visSelect">
                        <option value="all_coaches">👥 Všichni trenéři</option>
                        <option value="specific_coaches">👤 Vybraní trenéři</option>
                        <option value="all_athletes" <?= $athleteAudienceEnabled ? '' : 'disabled' ?>>🏃 Všichni sportovci</option>
                        <option value="specific_athletes" <?= $athleteAudienceEnabled ? '' : 'disabled' ?>>🏃 Vybraní sportovci</option>
                        <option value="all_users" <?= $athleteAudienceEnabled ? '' : 'disabled' ?>>👥 Trenéři i sportovci</option>
                    </select>
                </div>
                <div id="specificCoaches" class="mb-3 d-none">
                    <label class="form-label fw-semibold">Vyberte trenéry</label>
                    <div class="row g-2" style="max-height:200px;overflow-y:auto">
                        <?php foreach ($coaches as $c): ?>
                        <div class="col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="specific_coaches[]" value="<?= $c['id'] ?>"
                                       id="coach<?= $c['id'] ?>">
                                <label class="form-check-label" for="coach<?= $c['id'] ?>">
                                    <?= h($c['name'] ?: $c['username']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="specificAthletes" class="mb-3 d-none">
                    <label class="form-label fw-semibold">Vyberte sportovce</label>
                    <div class="row g-2" style="max-height:240px;overflow-y:auto">
                        <?php foreach ($athletes as $athlete): ?>
                        <div class="col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="specific_athletes[]" value="<?= (int)$athlete['id'] ?>"
                                       id="athlete<?= (int)$athlete['id'] ?>">
                                <label class="form-check-label" for="athlete<?= (int)$athlete['id'] ?>">
                                    <?= h(trim($athlete['first_name'] . ' ' . $athlete['last_name'])) ?>
                                    <?php if (!empty($athlete['coach_name'])): ?>
                                    <span class="d-block small text-muted"><?= h($athlete['coach_name']) ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="alert alert-info small mb-0">
                    <i class="fas fa-bell me-1"></i>
                    Po nahrání budou všichni dotčení uživatelé automaticky upozorněni systémovou zprávou.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-primary fw-bold">
                    <i class="fas fa-upload me-1"></i>Nahrát a notifikovat
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Upravit existující příspěvek -->
<div class="modal fade" id="modalVisibility" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_visibility">
            <input type="hidden" name="file_id" id="editVisibilityFileId">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="fas fa-pen-to-square me-2"></i>Upravit příspěvek</h5>
                    <div class="small text-muted text-truncate" id="editVisibilityFileName"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="editPostTitle" class="form-label fw-semibold">Název příspěvku</label>
                    <input type="text" name="title" id="editPostTitle" class="form-control" maxlength="180" required>
                </div>
                <div class="mb-3">
                    <label for="editPostDescription" class="form-label fw-semibold">Popis <small class="text-muted">(volitelný)</small></label>
                    <textarea name="description" id="editPostDescription" class="form-control" rows="8" maxlength="<?= $descriptionMaxLength ?>"></textarea>
                    <div class="form-text">Maximálně <?= number_format($descriptionMaxLength, 0, ',', ' ') ?> znaků.</div>
                </div>
                <div class="mb-3">
                    <label for="editVisibilitySelect" class="form-label fw-semibold">Viditelnost</label>
                    <select name="visibility" class="form-select" id="editVisibilitySelect">
                        <option value="all_coaches">👥 Všichni trenéři</option>
                        <option value="specific_coaches">👤 Vybraní trenéři</option>
                        <option value="all_athletes" <?= $athleteAudienceEnabled ? '' : 'disabled' ?>>🏃 Všichni sportovci</option>
                        <option value="specific_athletes" <?= $athleteAudienceEnabled ? '' : 'disabled' ?>>🏃 Vybraní sportovci</option>
                        <option value="all_users" <?= $athleteAudienceEnabled ? '' : 'disabled' ?>>👥 Trenéři i sportovci</option>
                    </select>
                </div>
                <div id="editSpecificCoaches" class="mb-3 d-none">
                    <label class="form-label fw-semibold">Vyberte trenéry</label>
                    <div class="row g-2" style="max-height:240px;overflow-y:auto">
                        <?php foreach ($coaches as $coach): ?>
                        <div class="col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input edit-coach-checkbox" type="checkbox"
                                       name="specific_coaches[]" value="<?= (int)$coach['id'] ?>"
                                       id="editCoach<?= (int)$coach['id'] ?>">
                                <label class="form-check-label" for="editCoach<?= (int)$coach['id'] ?>">
                                    <?= h($coach['name'] ?: $coach['username']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="editSpecificAthletes" class="mb-3 d-none">
                    <label class="form-label fw-semibold">Vyberte sportovce</label>
                    <div class="row g-2" style="max-height:240px;overflow-y:auto">
                        <?php foreach ($athletes as $athlete): ?>
                        <div class="col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input edit-athlete-checkbox" type="checkbox"
                                       name="specific_athletes[]" value="<?= (int)$athlete['id'] ?>"
                                       id="editAthlete<?= (int)$athlete['id'] ?>">
                                <label class="form-check-label" for="editAthlete<?= (int)$athlete['id'] ?>">
                                    <?= h(trim($athlete['first_name'] . ' ' . $athlete['last_name'])) ?>
                                    <?php if (!empty($athlete['coach_name'])): ?>
                                    <span class="d-block small text-muted"><?= h($athlete['coach_name']) ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-primary fw-semibold">
                    <i class="fas fa-save me-1"></i>Uložit změny
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('visSelect')?.addEventListener('change', function () {
    document.getElementById('specificCoaches')?.classList.toggle('d-none', this.value !== 'specific_coaches');
    document.getElementById('specificAthletes')?.classList.toggle('d-none', this.value !== 'specific_athletes');
});

const editVisibilitySelect = document.getElementById('editVisibilitySelect');

function updateEditRecipientLists() {
    document.getElementById('editSpecificCoaches')?.classList.toggle('d-none', editVisibilitySelect?.value !== 'specific_coaches');
    document.getElementById('editSpecificAthletes')?.classList.toggle('d-none', editVisibilitySelect?.value !== 'specific_athletes');
}

editVisibilitySelect?.addEventListener('change', updateEditRecipientLists);

document.querySelectorAll('.js-edit-visibility').forEach((button) => {
    button.addEventListener('click', () => {
        const coachIds = new Set(JSON.parse(button.dataset.coachIds || '[]').map(String));
        const athleteIds = new Set(JSON.parse(button.dataset.athleteIds || '[]').map(String));

        document.getElementById('editVisibilityFileId').value = button.dataset.fileId || '';
        document.getElementById('editVisibilityFileName').textContent = button.dataset.fileName || '';
        document.getElementById('editPostTitle').value = button.dataset.title || '';
        document.getElementById('editPostDescription').value = button.dataset.description || '';
        editVisibilitySelect.value = button.dataset.visibility || 'all_coaches';
        document.querySelectorAll('.edit-coach-checkbox').forEach((checkbox) => {
            checkbox.checked = coachIds.has(checkbox.value);
        });
        document.querySelectorAll('.edit-athlete-checkbox').forEach((checkbox) => {
            checkbox.checked = athleteIds.has(checkbox.value);
        });
        updateEditRecipientLists();
    });
});
</script>

<?php renderAdminFooter(); ?>
