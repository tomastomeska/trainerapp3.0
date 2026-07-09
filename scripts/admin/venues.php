<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        die('Neplatný CSRF token.');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $name = normalizeTrainingVenueName($_POST['name'] ?? '');
        $address = trim((string)($_POST['address'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($name === '') {
            flash('danger', 'Název sportoviště je povinný.');
            redirect(BASE_URL . '/admin/venues.php');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO training_venues (name, address, note, is_active)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                address = VALUES(address),
                note = VALUES(note),
                is_active = 1'
        );
        $stmt->execute([
            $name,
            $address !== '' ? $address : null,
            $note !== '' ? $note : null,
        ]);

        flash('success', 'Sportoviště bylo uloženo.');
        redirect(BASE_URL . '/admin/venues.php');
    }

    if ($action === 'update') {
        $venueId = (int)($_POST['venue_id'] ?? 0);
        $name = normalizeTrainingVenueName($_POST['name'] ?? '');
        $address = trim((string)($_POST['address'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($venueId <= 0 || $name === '') {
            flash('danger', 'Sportoviště se nepodařilo uložit.');
            redirect(BASE_URL . '/admin/venues.php');
        }

        $currentStmt = $pdo->prepare('SELECT name FROM training_venues WHERE id = ?');
        $currentStmt->execute([$venueId]);
        $currentVenue = $currentStmt->fetch();
        if (!$currentVenue) {
            flash('danger', 'Sportoviště nebylo nalezeno.');
            redirect(BASE_URL . '/admin/venues.php');
        }

        $oldName = (string)$currentVenue['name'];

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'UPDATE training_venues
             SET name = ?, address = ?, note = ?, is_active = ?, updated_at = NOW()
             WHERE id = ?'
        );

        try {
            $stmt->execute([
                $name,
                $address !== '' ? $address : null,
                $note !== '' ? $note : null,
                $isActive,
                $venueId,
            ]);

            if ($oldName !== $name) {
                $pdo->prepare('UPDATE training_sessions SET location = ? WHERE location = ?')
                    ->execute([$name, $oldName]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'Sportoviště se nepodařilo uložit. Zkontrolujte, zda už stejný název neexistuje.');
            redirect(BASE_URL . '/admin/venues.php');
        }

        flash('success', 'Sportoviště bylo upraveno.');
        redirect(BASE_URL . '/admin/venues.php');
    }

    if ($action === 'delete') {
        $venueId = (int)($_POST['venue_id'] ?? 0);
        if ($venueId <= 0) {
            flash('danger', 'Sportoviště se nepodařilo smazat.');
            redirect(BASE_URL . '/admin/venues.php');
        }

        $stmtVenue = $pdo->prepare('SELECT id, name FROM training_venues WHERE id = ?');
        $stmtVenue->execute([$venueId]);
        $venue = $stmtVenue->fetch();
        if (!$venue) {
            flash('danger', 'Sportoviště nebylo nalezeno.');
            redirect(BASE_URL . '/admin/venues.php');
        }

        $oldName = (string)$venue['name'];
        $usageStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM training_sessions ts
             WHERE ts.location COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci'
        );
        $usageStmt->execute([$oldName]);
        $usageCount = (int)$usageStmt->fetchColumn();

        if ($usageCount > 0) {
            $replacementVenueId = (int)($_POST['replacement_venue_id'] ?? 0);
            if ($replacementVenueId <= 0 || $replacementVenueId === $venueId) {
                flash('danger', 'Toto sportoviště je použité v trénincích. Před smazáním vyberte náhradní sportoviště.');
                redirect(BASE_URL . '/admin/venues.php');
            }

            $stmtReplacement = $pdo->prepare('SELECT id, name FROM training_venues WHERE id = ?');
            $stmtReplacement->execute([$replacementVenueId]);
            $replacementVenue = $stmtReplacement->fetch();
            if (!$replacementVenue) {
                flash('danger', 'Náhradní sportoviště nebylo nalezeno.');
                redirect(BASE_URL . '/admin/venues.php');
            }

            $replacementName = (string)$replacementVenue['name'];

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE training_sessions SET location = ? WHERE location COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci')
                    ->execute([$replacementName, $oldName]);
                $pdo->prepare('DELETE FROM training_venues WHERE id = ?')
                    ->execute([$venueId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('danger', 'Smazání sportoviště selhalo.');
                redirect(BASE_URL . '/admin/venues.php');
            }

            flash('success', 'Sportoviště bylo smazáno a ' . $usageCount . ' tréninků bylo převedeno na náhradní místo.');
            redirect(BASE_URL . '/admin/venues.php');
        }

        $pdo->prepare('DELETE FROM training_venues WHERE id = ?')->execute([$venueId]);
        flash('success', 'Sportoviště bylo smazáno.');
        redirect(BASE_URL . '/admin/venues.php');
    }
}

$venues = $pdo->query(
    'SELECT tv.*, c.name AS coach_name, c.username AS coach_username,
            (SELECT COUNT(*) FROM training_sessions ts
             WHERE ts.location COLLATE utf8mb4_unicode_ci = tv.name COLLATE utf8mb4_unicode_ci) AS usage_count
     FROM training_venues tv
     LEFT JOIN coaches c ON c.id = tv.created_by_coach_id
     ORDER BY tv.is_active DESC, tv.name ASC'
)->fetchAll();

renderAdminHeader('Sportoviště');
?>

<style>
.venues-admin-shell {
    max-width: 1120px;
    margin: 0 auto;
}

.venue-edit-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(0, 1.25fr) minmax(0, 1.35fr) 120px minmax(0, 1.35fr);
    gap: 1rem;
    align-items: end;
}

.venue-actions {
    min-width: 220px;
}

@media (max-width: 1399.98px) {
    .venue-edit-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .venue-actions {
        min-width: 0;
    }
}

@media (max-width: 767.98px) {
    .venue-edit-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="venues-admin-shell">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold"><i class="fas fa-map-location-dot me-2" style="color:#a78bfa"></i>Sportoviště a místa</h2>
            <div class="text-muted">Katalog míst pro všechny tréninkové formuláře kromě golfu.</div>
        </div>
        <div class="badge text-bg-dark px-3 py-2"><?= count($venues) ?> míst</div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white fw-semibold">Přidat sportoviště</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Název</label>
                    <input type="text" name="name" class="form-control" maxlength="255" required placeholder="např. Posilovna Royal Brno">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Adresa</label>
                    <input type="text" name="address" class="form-control" maxlength="255" placeholder="např. U Stadionu 12, Brno">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Poznámka</label>
                    <input type="text" name="note" class="form-control" maxlength="500" placeholder="Parkování vzadu, vstup z boku...">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="fas fa-plus me-1"></i>Přidat
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <span>Seznam sportovišť</span>
            <div class="d-flex gap-2 align-items-center">
                <input type="text" id="venues-search" class="form-control form-control-sm" placeholder="Hledat název, adresu, poznámku..." style="min-width:280px;">
                <select id="venues-filter-active" class="form-select form-select-sm" style="width:auto;">
                    <option value="all">Vše</option>
                    <option value="active" selected>Aktivní</option>
                    <option value="inactive">Neaktivní</option>
                </select>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($venues)): ?>
            <div class="text-center py-5 text-muted">Zatím tu není žádné sportoviště.</div>
            <?php else: ?>
            <div id="venues-list" class="d-flex flex-column gap-3">
                <?php foreach ($venues as $venue): ?>
                <?php
                $venueName = (string)$venue['name'];
                $venueAddress = (string)($venue['address'] ?? '');
                $venueNote = (string)($venue['note'] ?? $venue['admin_note'] ?? '');
                $venueCreator = !empty($venue['coach_name']) || !empty($venue['coach_username'])
                    ? (string)($venue['coach_name'] ?: $venue['coach_username'])
                    : 'Admin nebo import';
                ?>
                <form method="post"
                      class="venue-item border rounded-3 p-3 bg-light shadow-sm"
                      data-name="<?= h(mb_strtolower($venueName, 'UTF-8')) ?>"
                      data-address="<?= h(mb_strtolower($venueAddress, 'UTF-8')) ?>"
                      data-note="<?= h(mb_strtolower($venueNote, 'UTF-8')) ?>"
                      data-active="<?= (int)$venue['is_active'] === 1 ? '1' : '0' ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="venue_id" value="<?= (int)$venue['id'] ?>">

                    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge text-bg-dark"><?= (int)$venue['usage_count'] ?>x použito</span>
                            <span class="badge <?= (int)$venue['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= (int)$venue['is_active'] === 1 ? 'Aktivní' : 'Neaktivní' ?>
                            </span>
                            <span class="small text-muted">Přidal: <?= h($venueCreator) ?></span>
                        </div>
                    </div>

                    <div class="venue-edit-grid">
                        <div>
                            <label class="form-label small text-muted mb-1">Název</label>
                            <input type="text" name="name" class="form-control" maxlength="255" required value="<?= h($venueName) ?>">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Adresa</label>
                            <input type="text" name="address" class="form-control" maxlength="255" value="<?= h($venueAddress) ?>" placeholder="Adresa...">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Poznámka</label>
                            <input type="text" name="note" class="form-control" maxlength="500" value="<?= h($venueNote) ?>" placeholder="Poznámka...">
                        </div>
                        <div class="text-start text-lg-center">
                            <label class="form-label small text-muted mb-1 d-block">Aktivní</label>
                            <div class="form-check d-inline-flex align-items-center justify-content-center m-0">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int)$venue['is_active'] === 1 ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Náhrada při smazání</label>
                            <select name="replacement_venue_id" class="form-select form-select-sm" title="Náhrada při smazání použitého sportoviště">
                                <option value="">Vybrat náhradu</option>
                                <?php foreach ($venues as $replacementVenue): ?>
                                <?php if ((int)$replacementVenue['id'] === (int)$venue['id']) continue; ?>
                                <?php $replacementName = (string)$replacementVenue['name']; ?>
                                <option value="<?= (int)$replacementVenue['id'] ?>">
                                    <?= h($replacementName) ?><?= !empty($replacementVenue['address']) ? ' - ' . h((string)$replacementVenue['address']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="venue-actions d-flex flex-wrap gap-2 justify-content-end mt-3 ms-auto">
                        <button type="submit" name="action" value="update" class="btn btn-outline-primary fw-semibold">
                            <i class="fas fa-save me-1"></i>Uložit
                        </button>
                        <button type="submit" name="action" value="delete" class="btn btn-outline-danger fw-semibold"
                                formnovalidate
                                onclick="return confirm('Opravdu chcete toto sportoviště smazat? Pokud je použité v trénincích, vyberte předtím náhradní sportoviště.')">
                            <i class="fas fa-trash me-1"></i>Smazat
                        </button>
                    </div>
                </form>
                <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <small id="venues-visible-count" class="text-muted"></small>
                <button type="button" id="venues-load-more" class="btn btn-outline-secondary btn-sm">Načíst další</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('venues-search');
    const activeFilter = document.getElementById('venues-filter-active');
    const items = Array.from(document.querySelectorAll('.venue-item'));
    const loadMoreBtn = document.getElementById('venues-load-more');
    const visibleCountEl = document.getElementById('venues-visible-count');
    const pageSize = 12;
    let shown = pageSize;

    if (!items.length) {
        if (loadMoreBtn) {
            loadMoreBtn.style.display = 'none';
        }
        return;
    }

    const getFilteredItems = function() {
        const q = (searchInput?.value || '').trim().toLowerCase();
        const mode = activeFilter?.value || 'all';

        return items.filter(function(item) {
            const haystack = [
                item.getAttribute('data-name') || '',
                item.getAttribute('data-address') || '',
                item.getAttribute('data-note') || ''
            ].join(' ');

            const isActive = item.getAttribute('data-active') === '1';
            const activeOk = mode === 'all' || (mode === 'active' ? isActive : !isActive);
            const textOk = q === '' || haystack.includes(q);

            return activeOk && textOk;
        });
    };

    const render = function(resetShown) {
        if (resetShown) {
            shown = pageSize;
        }

        const filtered = getFilteredItems();
        items.forEach(function(item) { item.style.display = 'none'; });

        filtered.slice(0, shown).forEach(function(item) {
            item.style.display = '';
        });

        const visible = Math.min(shown, filtered.length);
        if (visibleCountEl) {
            visibleCountEl.textContent = 'Zobrazeno ' + visible + ' z ' + filtered.length + ' položek';
        }

        if (loadMoreBtn) {
            loadMoreBtn.style.display = visible < filtered.length ? '' : 'none';
        }
    };

    if (searchInput) {
        searchInput.addEventListener('input', function() { render(true); });
    }
    if (activeFilter) {
        activeFilter.addEventListener('change', function() { render(true); });
    }
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function() {
            shown += pageSize;
            render(false);
        });
    }

    render(true);
});
</script>

<?php renderAdminFooter(); ?>