<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();
$error   = null;

// Smazání sady
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $setId = intParam($_POST, 'set_id');
        $stmt  = $pdo->prepare('SELECT id FROM workout_sets WHERE id = ? AND coach_id = ?');
        $stmt->execute([$setId, $coachId]);
        if ($stmt->fetch()) {
            $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM training_sessions WHERE workout_set_id = ?');
            $stmt2->execute([$setId]);
            if ((int)$stmt2->fetchColumn() > 0) {
                $error = 'Tuto sadu nelze smazat – byla již použita v tréninku.';
            } else {
                $pdo->prepare('DELETE FROM workout_sets WHERE id = ? AND coach_id = ?')
                    ->execute([$setId, $coachId]);
                flash('success', 'Sada byla smazána.');
                redirect(BASE_URL . '/sady.php');
            }
        }
    }
}

// Načtení sad s cviky
$stmt = $pdo->prepare(
    'SELECT ws.*,
            COUNT(wse.id) AS exercise_count,
            (SELECT COUNT(*) FROM training_sessions ts WHERE ts.workout_set_id = ws.id) AS session_count
     FROM workout_sets ws
     LEFT JOIN workout_set_exercises wse ON ws.id = wse.workout_set_id
     WHERE ws.coach_id = ?
     GROUP BY ws.id
     ORDER BY ws.name'
);
$stmt->execute([$coachId]);
$sets = $stmt->fetchAll();

// Načtení cviků pro formulář
$stmtEx = $pdo->prepare('SELECT * FROM exercises WHERE (coach_id = ? OR is_global = 1) ORDER BY name');
$stmtEx->execute([$coachId]);
$exercises = $stmtEx->fetchAll();

$exerciseOptionsHtml = '';
foreach ($exercises as $ex) {
    $label = $ex['name'] . ($ex['is_global'] ? ' [Globální]' : ' [Vlastní]');
    $exerciseOptionsHtml .= sprintf(
        '<option value="%d" data-photo="%s" data-source="%s">%s</option>',
        (int)$ex['id'],
        h(photoUrl($ex['photo'] ?? null, 'exercises')),
        $ex['is_global'] ? 'global' : 'own',
        h($label)
    );
}

renderHeader('Sady');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fas fa-layer-group me-2 text-warning"></i>Sady</h2>
    <?php if (!empty($exercises)): ?>
        <button class="btn btn-warning btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addSetModal">
            <i class="fas fa-plus me-1"></i>Nová sada
        </button>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if (empty($exercises)): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Nejprve přidejte <a href="<?= BASE_URL ?>/exercises.php" class="alert-link">cviky</a>,
        pak teprve můžete vytvářet sady.
    </div>
<?php endif; ?>

<?php if (empty($sets)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <div class="display-3 text-muted mb-3">📋</div>
            <h4 class="text-muted">Zatím žádné sady</h4>
            <p class="text-muted">Sada je tréninkový plán – pojmenovaná skupinka cviků.</p>
            <?php if (!empty($exercises)): ?>
                <button class="btn btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#addSetModal">
                    <i class="fas fa-plus me-1"></i>Vytvořit první sadu
                </button>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($sets as $ws): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="fas fa-layer-group me-2 text-warning"></i><?= h($ws['name']) ?></span>
                        <span class="badge bg-secondary"><?= $ws['exercise_count'] ?> cvik<?= $ws['exercise_count'] != 1 ? ($ws['exercise_count'] < 5 ? 'y' : 'ů') : '' ?></span>
                    </div>
                    <div class="card-body">
                        <?php
                        $stmtItems = $pdo->prepare(
                            'SELECT wse.exercise_order, e.name
                     FROM workout_set_exercises wse
                     JOIN exercises e ON wse.exercise_id = e.id
                     WHERE wse.workout_set_id = ?
                     ORDER BY wse.exercise_order'
                        );
                        $stmtItems->execute([$ws['id']]);
                        $items = $stmtItems->fetchAll();
                        ?>
                        <?php if ($items): ?>
                            <ol class="mb-3 ps-3">
                                <?php foreach ($items as $item): ?>
                                    <li><?= h($item['name']) ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            <p class="text-muted small">Žádné cviky.</p>
                        <?php endif; ?>
                        <small class="text-muted">
                            <i class="fas fa-chart-bar me-1"></i><?= $ws['session_count'] ?> tréninků
                        </small>
                    </div>
                    <div class="card-footer bg-transparent d-flex gap-2">
                        <a href="<?= BASE_URL ?>/sada_edit.php?id=<?= $ws['id'] ?>"
                            class="btn btn-outline-secondary btn-sm flex-fill">
                            <i class="fas fa-edit me-1"></i>Upravit
                        </a>
                        <?php if ($ws['session_count'] == 0): ?>
                            <form method="post" class="d-inline flex-fill"
                                onsubmit="return confirm('Smazat sadu \'<?= h(addslashes($ws['name'])) ?>\'?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="set_id" value="<?= $ws['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                    <i class="fas fa-trash me-1"></i>Smazat
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-outline-secondary btn-sm flex-fill" disabled
                                title="Nelze smazat – sada byla použita v tréninku">
                                <i class="fas fa-lock me-1"></i>Smazat
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal: Přidat sadu -->
<?php if (!empty($exercises)): ?>
    <div class="modal fade" id="addSetModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?= BASE_URL ?>/sada_add.php" novalidate>
                    <?= csrfField() ?>
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title"><i class="fas fa-plus me-2 text-warning"></i>Nová sada</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Název sady <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                placeholder="např. Sada A, Push Day, Horní tělo..." required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Cviky v sadě</label>
                            <p class="text-muted small mb-2">Přidejte cviky v pořadí, v jakém se budou cvičit.</p>
                        </div>
                        <div id="exercise-slots">
                            <div class="exercise-slot mb-3 d-flex gap-2 align-items-start">
                                <span class="badge bg-warning text-dark mt-2">1.</span>
                                <div class="flex-grow-1">
                                    <select name="exercises[]" class="exercise-select" required>
                                        <option value="">– vyberte cvik –</option>
                                        <?= $exerciseOptionsHtml ?>
                                    </select>
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-sm remove-slot mt-1" style="display:none">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="small text-muted mb-2">
                            <span class="badge text-bg-primary me-1">Globální</span> cvik superadmina &nbsp;
                            <span class="badge text-bg-secondary me-1">Vlastní</span> cvik trenéra
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-1" id="add-slot-btn">
                            <i class="fas fa-plus me-1"></i>Přidat cvik
                        </button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                        <button type="submit" class="btn btn-warning fw-bold">
                            <i class="fas fa-save me-1"></i>Vytvořit sadu
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const exerciseOptions = <?= json_encode($exerciseOptionsHtml, JSON_UNESCAPED_UNICODE) ?>;

        function csThumb(photo, size) {
            if (photo) {
                return '<img src="' + photo + '" alt="" class="rounded border flex-shrink-0" style="width:' + size + 'px;height:' + size + 'px;object-fit:cover;">';
            }
            return '<div class="rounded border flex-shrink-0 d-flex align-items-center justify-content-center bg-light" style="width:' + size + 'px;height:' + size + 'px;"><i class="fas fa-dumbbell text-muted small"></i></div>';
        }

        function initCustomSelect(nativeSel) {
            const opts = Array.from(nativeSel.options);
            nativeSel.style.display = 'none';

            const wrapper = document.createElement('div');
            wrapper.className = 'cs-wrapper position-relative';

            const trigger = document.createElement('div');
            trigger.className = 'cs-trigger form-control d-flex align-items-center gap-2';
            trigger.style.cssText = 'cursor:pointer;user-select:none;min-height:44px;';

            const panel = document.createElement('div');
            panel.style.cssText = 'position:absolute;top:calc(100% + 2px);left:0;right:0;z-index:9999;' +
                'background:#fff;border:1px solid #dee2e6;border-radius:.375rem;' +
                'box-shadow:0 4px 12px rgba(0,0,0,.12);display:none;flex-direction:column;max-height:300px;';

            const searchWrap = document.createElement('div');
            searchWrap.style.cssText = 'padding:8px;border-bottom:1px solid #e9ecef;flex-shrink:0;';
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'form-control form-control-sm';
            searchInput.placeholder = 'Hledat cvik...';
            searchWrap.appendChild(searchInput);

            const optList = document.createElement('div');
            optList.style.cssText = 'overflow-y:auto;flex:1;';

            panel.appendChild(searchWrap);
            panel.appendChild(optList);

            function renderOpts(q) {
                optList.innerHTML = '';
                var found = 0;
                opts.forEach(function(opt) {
                    if (!opt.value) return;
                    var name = opt.text.replace(/\s\[(Globální|Vlastní)\]$/, '');
                    if (q && name.toLowerCase().indexOf(q.toLowerCase()) === -1) return;
                    found++;
                    var isGlobal = opt.dataset.source === 'global';
                    var photo = opt.dataset.photo || '';
                    var selected = String(opt.value) === String(nativeSel.value);
                    var item = document.createElement('div');
                    item.style.cssText = 'cursor:pointer;padding:8px 12px;display:flex;align-items:center;gap:10px;' +
                        (selected ? 'background:#e8f0fe;font-weight:600;' : '');
                    item.innerHTML = csThumb(photo, 36) +
                        '<span class="flex-grow-1 small">' + name + '</span>' +
                        '<span class="badge ' + (isGlobal ? 'text-bg-primary' : 'text-bg-secondary') + '">' + (isGlobal ? 'Globální' : 'Vlastní') + '</span>';
                    item.addEventListener('mouseenter', function() {
                        if (!selected) item.style.background = '#f8f9fa';
                    });
                    item.addEventListener('mouseleave', function() {
                        if (!selected) item.style.background = '';
                    });
                    item.addEventListener('click', function() {
                        nativeSel.value = opt.value;
                        updateTrigger();
                        closePanel();
                    });
                    optList.appendChild(item);
                });
                if (!found) optList.innerHTML = '<div style="padding:12px;text-align:center;color:#6c757d;font-size:.85em;">Žádné výsledky</div>';
            }

            function updateTrigger() {
                var sel = null;
                opts.forEach(function(o) {
                    if (String(o.value) === String(nativeSel.value) && o.value) sel = o;
                });
                if (sel) {
                    var name = sel.text.replace(/\s\[(Globální|Vlastní)\]$/, '');
                    var isGlobal = sel.dataset.source === 'global';
                    trigger.innerHTML = csThumb(sel.dataset.photo || '', 32) +
                        '<span class="fw-semibold small flex-grow-1">' + name + '</span>' +
                        '<span class="badge ' + (isGlobal ? 'text-bg-primary' : 'text-bg-secondary') + ' me-1">' + (isGlobal ? 'Globální' : 'Vlastní') + '</span>' +
                        '<i class="fas fa-chevron-down text-muted small"></i>';
                } else {
                    trigger.innerHTML = '<span class="text-muted flex-grow-1 small">– vyberte cvik –</span>' +
                        '<i class="fas fa-chevron-down text-muted small"></i>';
                }
            }

            function openPanel() {
                renderOpts('');
                searchInput.value = '';
                panel.style.display = 'flex';
                setTimeout(function() {
                    searchInput.focus();
                }, 0);
            }

            function closePanel() {
                panel.style.display = 'none';
            }

            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                panel.style.display === 'none' ? openPanel() : closePanel();
            });
            searchInput.addEventListener('input', function() {
                renderOpts(searchInput.value);
            });
            searchInput.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            document.addEventListener('click', closePanel);

            wrapper.appendChild(trigger);
            wrapper.appendChild(panel);
            nativeSel.parentNode.insertBefore(wrapper, nativeSel);
            updateTrigger();
        }

        document.getElementById('add-slot-btn').addEventListener('click', function() {
            var num = document.querySelectorAll('.exercise-slot').length + 1;
            var div = document.createElement('div');
            div.className = 'exercise-slot mb-3 d-flex gap-2 align-items-start';
            var sel = document.createElement('select');
            sel.name = 'exercises[]';
            sel.className = 'exercise-select';
            sel.innerHTML = '<option value="">– vyberte cvik –</option>' + exerciseOptions;
            var wrap = document.createElement('div');
            wrap.className = 'flex-grow-1';
            wrap.appendChild(sel);
            var badge = document.createElement('span');
            badge.className = 'badge bg-warning text-dark mt-2';
            badge.textContent = num + '.';
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-outline-danger btn-sm remove-slot mt-1';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            div.appendChild(badge);
            div.appendChild(wrap);
            div.appendChild(removeBtn);
            document.getElementById('exercise-slots').appendChild(div);
            initCustomSelect(sel);
            updateRemoveButtons();
        });

        document.getElementById('exercise-slots').addEventListener('click', function(e) {
            if (e.target.closest('.remove-slot')) {
                e.target.closest('.exercise-slot').remove();
                renumberSlots();
                updateRemoveButtons();
            }
        });

        function renumberSlots() {
            document.querySelectorAll('.exercise-slot').forEach(function(slot, i) {
                slot.querySelector('.badge').textContent = (i + 1) + '.';
            });
        }

        function updateRemoveButtons() {
            var btns = document.querySelectorAll('.remove-slot');
            btns.forEach(function(b) {
                b.style.display = btns.length > 1 ? '' : 'none';
            });
        }

        document.querySelectorAll('.exercise-slot').forEach(function(slot) {
            initCustomSelect(slot.querySelector('.exercise-select'));
        });
    </script>
<?php endif; ?>

<?php renderFooter(); ?>