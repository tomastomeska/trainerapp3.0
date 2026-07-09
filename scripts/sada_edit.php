<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();
$setId   = intParam($_GET, 'id');

// Načtení sady (musí patřit trenérovi)
$stmt = $pdo->prepare('SELECT * FROM workout_sets WHERE id = ? AND coach_id = ?');
$stmt->execute([$setId, $coachId]);
$workoutSet = $stmt->fetch();
if (!$workoutSet) {
    flash('error', 'Sada nenalezena.');
    redirect(BASE_URL . '/sady.php');
}

$error   = null;
$success = null;

// POST: uložit změny
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $name      = trim($_POST['name'] ?? '');
        $exercises = array_filter(array_map('intval', $_POST['exercises'] ?? []));
        if ($name === '') {
            $error = 'Název sady nesmí být prázdný.';
        } elseif (empty($exercises)) {
            $error = 'Vyberte alespoň jeden cvik.';
        } else {
            // Validace vlastnictví cviků
            $inClause = implode(',', array_fill(0, count($exercises), '?'));
            $params   = array_merge($exercises, [$coachId]);
            $check    = $pdo->prepare("SELECT COUNT(*) FROM exercises WHERE id IN ($inClause) AND (coach_id = ? OR is_global = 1)");
            $check->execute($params);
            if ((int)$check->fetchColumn() !== count($exercises)) {
                $error = 'Jeden nebo více cviků vám nepatří.';
            } else {
                $pdo->prepare('UPDATE workout_sets SET name = ? WHERE id = ? AND coach_id = ?')
                    ->execute([$name, $setId, $coachId]);
                $pdo->prepare('DELETE FROM workout_set_exercises WHERE workout_set_id = ?')
                    ->execute([$setId]);
                $ins = $pdo->prepare('INSERT INTO workout_set_exercises (workout_set_id, exercise_id, exercise_order) VALUES (?, ?, ?)');
                foreach (array_values($exercises) as $order => $exId) {
                    $ins->execute([$setId, $exId, $order + 1]);
                }
                flash('success', 'Sada byla upravena.');
                redirect(BASE_URL . '/sady.php');
            }
        }
    }
}

// Načtení aktuálních cviků v sadě
$stmtItems = $pdo->prepare(
    'SELECT wse.exercise_id, wse.exercise_order
     FROM workout_set_exercises wse
     WHERE wse.workout_set_id = ?
     ORDER BY wse.exercise_order'
);
$stmtItems->execute([$setId]);
$currentExercises = $stmtItems->fetchAll();

// Načtení dostupných cviků (vlastní + globální)
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

renderHeader('Upravit sadu');
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= BASE_URL ?>/sady.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Zpět
    </a>
    <h2 class="mb-0"><i class="fas fa-edit me-2 text-warning"></i>Upravit sadu</h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" novalidate>
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Název sady <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" value="<?= h($workoutSet['name']) ?>" required>
            </div>
            <div class="mb-2">
                <label class="form-label fw-semibold">Cviky v sadě</label>
                <p class="text-muted small mb-2">Přidejte cviky v pořadí, v jakém se budou cvičit.</p>
            </div>
            <div id="exercise-slots">
                <?php if (!empty($currentExercises)): ?>
                    <?php foreach ($currentExercises as $i => $ce): ?>
                        <div class="exercise-slot mb-3 d-flex gap-2 align-items-start">
                            <span class="badge bg-warning text-dark mt-2"><?= $i + 1 ?>.</span>
                            <div class="flex-grow-1">
                                <select name="exercises[]" class="exercise-select">
                                    <option value="">– vyberte cvik –</option>
                                    <?php foreach ($exercises as $ex): ?>
                                        <option value="<?= $ex['id'] ?>"
                                            data-photo="<?= h(photoUrl($ex['photo'] ?? null, 'exercises')) ?>"
                                            data-source="<?= $ex['is_global'] ? 'global' : 'own' ?>"
                                            <?= $ex['id'] == $ce['exercise_id'] ? 'selected' : '' ?>>
                                            <?= h($ex['name'] . ($ex['is_global'] ? ' [Globální]' : ' [Vlastní]')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button" class="btn btn-outline-danger btn-sm remove-slot mt-1">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="exercise-slot mb-3 d-flex gap-2 align-items-start">
                        <span class="badge bg-warning text-dark mt-2">1.</span>
                        <div class="flex-grow-1">
                            <select name="exercises[]" class="exercise-select">
                                <option value="">– vyberte cvik –</option>
                                <?= $exerciseOptionsHtml ?>
                            </select>
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-sm remove-slot mt-1" style="display:none">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="small text-muted mb-2">
                <span class="badge text-bg-primary me-1">Globální</span> cvik superadmina &nbsp;
                <span class="badge text-bg-secondary me-1">Vlastní</span> cvik trenéra
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-1" id="add-slot-btn">
                <i class="fas fa-plus me-1"></i>Přidat cvik
            </button>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-warning fw-bold">
                    <i class="fas fa-save me-1"></i>Uložit změny
                </button>
                <a href="<?= BASE_URL ?>/sady.php" class="btn btn-secondary">Zrušit</a>
            </div>
        </form>
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
        var opts = Array.from(nativeSel.options);
        nativeSel.style.display = 'none';

        var wrapper = document.createElement('div');
        wrapper.className = 'cs-wrapper position-relative';

        var trigger = document.createElement('div');
        trigger.className = 'cs-trigger form-control d-flex align-items-center gap-2';
        trigger.style.cssText = 'cursor:pointer;user-select:none;min-height:44px;';

        var panel = document.createElement('div');
        panel.style.cssText = 'position:absolute;top:calc(100% + 2px);left:0;right:0;z-index:9999;' +
            'background:#fff;border:1px solid #dee2e6;border-radius:.375rem;' +
            'box-shadow:0 4px 12px rgba(0,0,0,.12);display:none;flex-direction:column;max-height:300px;';

        var searchWrap = document.createElement('div');
        searchWrap.style.cssText = 'padding:8px;border-bottom:1px solid #e9ecef;flex-shrink:0;';
        var searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'form-control form-control-sm';
        searchInput.placeholder = 'Hledat cvik...';
        searchWrap.appendChild(searchInput);

        var optList = document.createElement('div');
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
    updateRemoveButtons();
</script>

<?php renderFooter(); ?>