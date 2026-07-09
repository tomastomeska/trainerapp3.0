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

$privateCoachVenues = $pdo->query(
    'SELECT ctv.*, c.name AS coach_name, c.username AS coach_username,
          (
             SELECT COUNT(*)
             FROM training_sessions ts
             JOIN athletes a ON a.id = ts.athlete_id
             WHERE a.coach_id = ctv.coach_id
               AND ts.location COLLATE utf8mb4_unicode_ci = ctv.name COLLATE utf8mb4_unicode_ci
          ) AS usage_count
    FROM coach_training_venues ctv
    JOIN coaches c ON c.id = ctv.coach_id
    ORDER BY ctv.is_active DESC, ctv.name ASC'
)->fetchAll();

renderAdminHeader('Sportoviště');
?>

<style>
.venues-admin-shell {
    max-width: 1120px;
    margin: 0 auto;
}

.venue-row {
    border: 1px solid #dfe7ef;
    border-radius: 12px;
    background: #f8fbfe;
}

.venue-row .venue-main {
    display: grid;
    grid-template-columns: minmax(220px, 1.35fr) minmax(170px, 1fr) minmax(180px, auto) minmax(170px, .9fr) auto;
    gap: .6rem;
    align-items: center;
    padding: .7rem .9rem;
}

.venue-row .venue-main > div {
    min-width: 0;
}

.venue-row .venue-main .small,
.venue-row .venue-main .fw-semibold {
    overflow-wrap: anywhere;
    word-break: break-word;
}

.venues-head {
    display: grid;
    grid-template-columns: minmax(220px, 1.35fr) minmax(170px, 1fr) minmax(180px, auto) minmax(170px, .9fr) auto;
    gap: .6rem;
    align-items: center;
    padding: 0 .9rem .35rem;
    color: #6b7e92;
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
}

@media (max-width: 767.98px) {
    .venues-head {
        display: none;
    }

    .venue-row .venue-main {
        grid-template-columns: 1fr;
        padding: .65rem .75rem;
    }
}

@media (max-width: 991.98px) {
    .venue-row .venue-main {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .venue-row .venue-main > div:last-child {
        justify-self: end;
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
            <div class="venues-head">
                <div>Název a adresa</div>
                <div>Poznámka</div>
                <div>Stav / použití</div>
                <div>Přidal</div>
                <div class="text-end">Akce</div>
            </div>
            <div id="venues-list" class="d-flex flex-column gap-3">
                <?php foreach ($venues as $venue): ?>
                <?php
                $venueName = (string)$venue['name'];
                $venueAddress = (string)($venue['address'] ?? '');
                $venueNote = (string)($venue['note'] ?? $venue['admin_note'] ?? '');
                $venueCreator = !empty($venue['coach_name']) || !empty($venue['coach_username'])
                    ? (string)($venue['coach_name'] ?: $venue['coach_username'])
                    : 'Admin nebo import';
                $detailId = 'venueDetail' . (int)$venue['id'];
                ?>
                <div class="venue-item venue-row"
                     data-name="<?= h(mb_strtolower($venueName, 'UTF-8')) ?>"
                     data-address="<?= h(mb_strtolower($venueAddress, 'UTF-8')) ?>"
                     data-note="<?= h(mb_strtolower($venueNote, 'UTF-8')) ?>"
                     data-active="<?= (int)$venue['is_active'] === 1 ? '1' : '0' ?>">
                    <div class="venue-main">
                        <div>
                            <div class="fw-semibold"><?= h($venueName) ?></div>
                            <div class="small text-muted"><?= $venueAddress !== '' ? h($venueAddress) : 'Bez adresy' ?></div>
                        </div>
                        <div class="small text-muted">
                            <?= $venueNote !== '' ? h($venueNote) : 'Bez poznámky' ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge text-bg-dark"><?= (int)$venue['usage_count'] ?>x použito</span>
                            <span class="badge <?= (int)$venue['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= (int)$venue['is_active'] === 1 ? 'Aktivní' : 'Neaktivní' ?>
                            </span>
                        </div>
                        <div class="small text-muted">Přidal: <?= h($venueCreator) ?></div>
                        <div class="text-end">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $detailId ?>" aria-expanded="false" aria-controls="<?= $detailId ?>">
                                <i class="fas fa-pen-to-square me-1"></i>Rozkliknout
                            </button>
                        </div>
                    </div>

                    <div id="<?= $detailId ?>" class="collapse border-top">
                        <form method="post" class="p-3">
                            <?= csrfField() ?>
                            <input type="hidden" name="venue_id" value="<?= (int)$venue['id'] ?>">

                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label small text-muted mb-1">Název</label>
                                    <input type="text" name="name" class="form-control" maxlength="255" required value="<?= h($venueName) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted mb-1">Adresa</label>
                                    <input type="text" name="address" class="form-control" maxlength="255" value="<?= h($venueAddress) ?>" placeholder="Adresa...">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted mb-1">Poznámka</label>
                                    <input type="text" name="note" class="form-control" maxlength="500" value="<?= h($venueNote) ?>" placeholder="Poznámka...">
                                </div>
                                <div class="col-md-4">
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
                                <div class="col-md-2 d-flex align-items-end">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="venueActive<?= (int)$venue['id'] ?>" <?= (int)$venue['is_active'] === 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="venueActive<?= (int)$venue['id'] ?>">Aktivní</label>
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex justify-content-end gap-2 align-items-end">
                                    <button type="submit" name="action" value="update" class="btn btn-outline-primary fw-semibold">
                                        <i class="fas fa-save me-1"></i>Uložit
                                    </button>
                                    <button type="submit" name="action" value="delete" class="btn btn-outline-danger fw-semibold"
                                            formnovalidate
                                            onclick="return confirm('Opravdu chcete toto sportoviště smazat? Pokud je použité v trénincích, vyberte předtím náhradní sportoviště.')">
                                        <i class="fas fa-trash me-1"></i>Smazat
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <small id="venues-visible-count" class="text-muted"></small>
                <button type="button" id="venues-load-more" class="btn btn-outline-secondary btn-sm">Načíst další</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>Soukromá místa trenérů</span>
            <span class="badge text-bg-secondary"><?= count($privateCoachVenues) ?> míst</span>
        </div>
        <div class="card-body">
            <div class="small text-muted mb-3">
                Tato místa se zobrazují pouze konkrétnímu trenérovi, který je zadal.
            </div>
            <?php if (empty($privateCoachVenues)): ?>
            <div class="text-center py-4 text-muted">Zatím nejsou evidovaná žádná soukromá místa trenérů.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Místo</th>
                            <th>Trenér</th>
                            <th>Použito</th>
                            <th>Stav</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($privateCoachVenues as $privateVenue): ?>
                        <?php
                        $privateCoachName = (string)($privateVenue['coach_name'] ?: $privateVenue['coach_username']);
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= h((string)$privateVenue['name']) ?></td>
                            <td>
                                <?= h($privateCoachName) ?>
                                <?php if (!empty($privateVenue['coach_username'])): ?>
                                <span class="text-muted">(<?= h((string)$privateVenue['coach_username']) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge text-bg-dark"><?= (int)$privateVenue['usage_count'] ?>x</span></td>
                            <td>
                                <span class="badge <?= (int)$privateVenue['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= (int)$privateVenue['is_active'] === 1 ? 'Aktivní' : 'Neaktivní' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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