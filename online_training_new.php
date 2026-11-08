<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_training.php';
requireLogin();
$pdo = getDB(); $coachId = (int)getCurrentCoachId();
$error = null;
$athleteId = (int)($_POST['athlete_id'] ?? 0);
$setId = (int)($_POST['training_set_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$note = trim((string)($_POST['coach_note'] ?? ''));
$linkRows = $_POST['links'] ?? [['url' => '', 'description' => '']];
if (!is_array($linkRows)) $linkRows = [['url' => '', 'description' => '']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) $error = 'Požadavek je příliš velký. Zmenšete soubory nebo je nahrajte postupně v detailu tréninku.';
    elseif (!verifyCsrf($_POST['csrf_token'] ?? '')) $error = 'Neplatný bezpečnostní token.';
    else try {
        if ($athleteId <= 0 || $setId <= 0) throw new RuntimeException('Vyberte sportovce i tréninkovou sadu.');
        $files = [];
        if (isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
            foreach (array_keys($_FILES['attachments']['name']) as $index) {
                $files[] = [
                    'name' => $_FILES['attachments']['name'][$index],
                    'tmp_name' => $_FILES['attachments']['tmp_name'][$index],
                    'error' => $_FILES['attachments']['error'][$index],
                    'size' => $_FILES['attachments']['size'][$index],
                ];
            }
        }
        $urls = [];
        foreach ($linkRows as $link) {
            if (!is_array($link) || !isset($link['url'], $link['description']) || !is_string($link['url']) || !is_string($link['description'])) throw new RuntimeException('Neplatný odkaz nebo jeho popis.');
            $url = trim($link['url']);
            $description = trim($link['description']);
            if ($url === '' && $description === '') continue;
            if ($url === '') throw new RuntimeException('K popisu odkazu zadejte také internetovou adresu.');
            $urls[] = ['url' => $url, 'description' => $description];
        }
        $trainingId = onlineTrainingCreateFromSet($pdo, $coachId, $athleteId, $setId, $title, $note, $files, $urls);
        redirect(BASE_URL . '/online_training.php?id=' . $trainingId);
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$athletes = $pdo->prepare('SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name'); $athletes->execute([$coachId]); $athletes = $athletes->fetchAll();
$subscriptionByAthlete = [];
$futureSubscriptionByAthlete = [];
foreach ($athletes as $athlete) {
    $id = (int)$athlete['id'];
    $subscriptionByAthlete[$id] = onlineTrainingActiveSubscription($pdo, $coachId, $id);
    if (!$subscriptionByAthlete[$id]) {
        $futureSubscriptionByAthlete[$id] = onlineTrainingFuturePaidSubscription($pdo, $coachId, $id);
    }
}
$globalSetsEnabled = workoutSetsHasColumn('is_global') && workoutSetsHasColumn('description');
$setsSql = 'SELECT ws.id, ws.name, ' . ($globalSetsEnabled ? 'ws.is_global, ws.description, ' : '0 AS is_global, NULL AS description, ') . 'COUNT(wse.id) exercise_count FROM workout_sets ws LEFT JOIN workout_set_exercises wse ON wse.workout_set_id = ws.id WHERE ' . ($globalSetsEnabled ? '(ws.coach_id = ? OR ws.is_global = 1)' : 'ws.coach_id = ?') . (workoutSetArchivingEnabled() ? ' AND ws.is_active = 1' : '') . ' GROUP BY ws.id ORDER BY ' . ($globalSetsEnabled ? 'ws.is_global ASC, ' : '') . 'ws.name';
$sets = $pdo->prepare($setsSql); $sets->execute([$coachId]); $sets = $sets->fetchAll();
require_once __DIR__ . '/includes/header.php'; renderHeader('Nový online trénink', false, true);
?><div class="d-flex align-items-center gap-3 mb-4"><a href="<?= BASE_URL ?>/online_training.php" class="btn btn-outline-secondary btn-sm">Zpět</a><h2 class="mb-0"><i class="fas fa-laptop me-2 text-warning"></i>Nový online trénink</h2></div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<p class="small"><i class="fas fa-circle-question me-1"></i>Potřebujete poradit? <a href="<?= BASE_URL ?>/online_training_manual.php">Otevřete podrobný návod pro online tréninky</a>.</p>
<form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm">
    <div class="card-body">
        <?= csrfField() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-bold" for="online-athlete-id">Sportovec</label>
                <select id="online-athlete-id" name="athlete_id" class="form-select" required>
                    <option value="">Vyberte sportovce</option>
                    <?php foreach ($athletes as $athlete): $subscription = $subscriptionByAthlete[(int)$athlete['id']] ?? null; $futureSubscription = $futureSubscriptionByAthlete[(int)$athlete['id']] ?? null; ?>
                    <option value="<?= (int)$athlete['id'] ?>" data-until="<?= h($subscription ? formatDate($subscription['ends_on']) : '') ?>" data-starts="<?= h($futureSubscription ? formatDate($futureSubscription['starts_on']) : '') ?>" data-future-payment="<?= $futureSubscription && (float)$futureSubscription['price'] === 0.0 ? 'free' : 'paid' ?>" data-detail-url="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$athlete['id'] ?>" <?= $athleteId === (int)$athlete['id'] ? 'selected' : '' ?>><?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?><?= $subscription ? '' : ($futureSubscription ? ' — platnost od ' . h(formatDate($futureSubscription['starts_on'])) : ' — bez platného předplatného') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold" for="online-training-set">Tréninková sada</label>
                <select id="online-training-set" name="training_set_id" class="form-select" required><option value="">Vyberte sadu</option><?php foreach ($sets as $set): ?><option value="<?= (int)$set['id'] ?>" data-description="<?= h((string)($set['description'] ?? '')) ?>" <?= $setId === (int)$set['id'] ? 'selected' : '' ?>><?= $set['is_global'] ? 'Globální: ' : '' ?><?= h($set['name']) ?> (<?= (int)$set['exercise_count'] ?> cviků)</option><?php endforeach; ?></select>
            </div>
            <div class="col-12 d-none" id="online-set-description"><div class="alert alert-info mb-0"><strong>Pro koho je sada vhodná:</strong> <span></span></div></div>
            <div class="col-12"><div id="online-billing-preview" class="alert alert-secondary mb-0">Pro vytvoření a odeslání je nutné platné a uhrazené předplatné.</div></div>
            <div class="col-12"><label class="form-label" for="online-title">Název online tréninku</label><input id="online-title" name="title" class="form-control" value="<?= h($title) ?>" placeholder="Výchozí: název sady"></div>
            <div class="col-12"><label class="form-label fw-bold" for="online-instructions">Text a instrukce pro sportovce</label><textarea id="online-instructions" name="coach_note" class="form-control" rows="5"><?= h($note) ?></textarea></div>
            <div class="col-12"><label class="form-label fw-bold" for="online-files">Fotografie a videa</label><input id="online-files" type="file" name="attachments[]" class="form-control" accept="image/*,video/*" multiple><div class="form-text">Můžete vybrat více souborů, maximálně 50 MB na soubor. Celkovou velikost a počet souborů omezuje také nastavení serveru. Další přílohy lze přidat před odesláním.</div></div>
            <div class="col-12">
                <h5 class="fw-bold">Internetové odkazy</h5>
                <div id="online-links">
                    <?php foreach (array_values($linkRows) as $index => $link): if (!is_array($link)) continue; ?>
                    <div class="online-link-row border rounded p-3 mb-2">
                        <label class="form-label" for="online-link-url-<?= $index ?>">Internetová adresa</label>
                        <input id="online-link-url-<?= $index ?>" name="links[<?= $index ?>][url]" type="url" maxlength="1000" class="form-control mb-2" placeholder="https://..." value="<?= h(is_string($link['url'] ?? null) ? $link['url'] : '') ?>">
                        <label class="form-label" for="online-link-description-<?= $index ?>">Popis a vysvětlení (volitelné)</label>
                        <textarea id="online-link-description-<?= $index ?>" name="links[<?= $index ?>][description]" class="form-control" rows="2"><?= h(is_string($link['description'] ?? null) ? $link['description'] : '') ?></textarea>
                        <button type="button" class="btn btn-sm btn-outline-danger mt-2 online-remove-link">Odebrat odkaz</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="online-add-link" class="btn btn-outline-dark">Přidat další odkaz</button>
                <div class="form-text">Ke každému odkazu můžete napsat vlastní vysvětlení.</div>
            </div>
        </div>
        <button id="online-create" class="btn btn-warning fw-bold mt-4"><i class="fas fa-arrow-right me-1"></i>Pokračovat k přípravě a odeslání</button>
        <div id="online-create-warning" class="form-text text-danger" aria-live="polite">Vyberte sportovce s platným a uhrazeným předplatným.</div>
    </div>
</form>
<script>
(function () {
    const links = document.getElementById('online-links');
    let nextLinkIndex = <?= count($linkRows) ?>;
    document.getElementById('online-add-link').addEventListener('click', function () {
        const index = nextLinkIndex++;
        const row = document.createElement('div');
        row.className = 'online-link-row border rounded p-3 mb-2';
        row.innerHTML = '<label class="form-label" for="online-link-url-' + index + '">Internetová adresa</label><input id="online-link-url-' + index + '" name="links[' + index + '][url]" type="url" maxlength="1000" class="form-control mb-2" placeholder="https://..."><label class="form-label" for="online-link-description-' + index + '">Popis a vysvětlení (volitelné)</label><textarea id="online-link-description-' + index + '" name="links[' + index + '][description]" class="form-control" rows="2"></textarea><button type="button" class="btn btn-sm btn-outline-danger mt-2 online-remove-link">Odebrat odkaz</button>';
        links.appendChild(row);
    });
    links.addEventListener('click', function (event) {
        if (event.target.classList.contains('online-remove-link')) event.target.closest('.online-link-row').remove();
    });
    const athleteSelect = document.getElementById('online-athlete-id');
    const setSelect = document.getElementById('online-training-set');
    const setDescription = document.getElementById('online-set-description');
    const preview = document.getElementById('online-billing-preview');
    const createButton = document.getElementById('online-create');
    const createWarning = document.getElementById('online-create-warning');
    function updateBillingPreview() {
        const option = athleteSelect.options[athleteSelect.selectedIndex];
        createButton.disabled = !option || !option.value || !option.dataset.until;
        createWarning.textContent = createButton.disabled ? 'Sportovec musí mít platné a uhrazené předplatné. Může chybět, čekat na úhradu nebo ještě nezačalo platit.' : '';
        if (!option || !option.value) { preview.className = 'alert alert-secondary mb-0'; preview.textContent = 'Vyberte sportovce. Pro vytvoření a odeslání je nutné platné uhrazené předplatné.'; return; }
        if (option.dataset.until) { preview.className = 'alert alert-success mb-0'; preview.textContent = 'Předplatné platí do ' + option.dataset.until + '. Počet tréninků není omezen.'; return; }
        if (option.dataset.starts) {
            const message = (option.dataset.futurePayment === 'free' ? 'Bezplatné předplatné' : 'Uhrazené předplatné') + ' začne platit ' + option.dataset.starts + '. Trénink lze vytvořit a odeslat až od tohoto data.';
            preview.className = 'alert alert-warning mb-0'; preview.textContent = message; createWarning.textContent = message; return;
        }
        preview.className = 'alert alert-warning mb-0'; preview.innerHTML = 'Sportovec nemá platné a uhrazené předplatné. Zkontrolujte jeho stav v <a href="' + option.dataset.detailUrl + '" class="alert-link">detailu sportovce</a>.';
    }
    athleteSelect.addEventListener('change', updateBillingPreview);
    setSelect.addEventListener('change', function () { const description = setSelect.options[setSelect.selectedIndex]?.dataset.description || ''; setDescription.classList.toggle('d-none', description === ''); setDescription.querySelector('span').textContent = description; });
    updateBillingPreview();
})();
</script><?php renderFooter();