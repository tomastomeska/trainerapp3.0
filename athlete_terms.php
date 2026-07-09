<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$pdo = getDB();
$athlete = getCurrentAthlete();
$athleteId = (int)($athlete['id'] ?? 0);
$coachId = (int)($athlete['coach_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_terms.php?tab=agreement');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'respond_agreement') {
        $agreementId = (int)($_POST['agreement_id'] ?? 0);
        $response = (string)($_POST['response'] ?? '');
        if ($agreementId <= 0 || !in_array($response, ['approved', 'rejected'], true)) {
            flash('danger', 'Neplatná volba dohody.');
            redirect(BASE_URL . '/athlete_terms.php?tab=agreement');
        }

        $agreementStmt = $pdo->prepare(
            'SELECT id
             FROM coach_athlete_agreements
             WHERE id = ? AND coach_id = ? AND is_active = 1
             LIMIT 1'
        );
        $agreementStmt->execute([$agreementId, $coachId]);
        $agreementExists = $agreementStmt->fetch();

        if (!$agreementExists) {
            flash('danger', 'Dohoda už není aktivní.');
            redirect(BASE_URL . '/athlete_terms.php?tab=agreement');
        }

        $alreadyStmt = $pdo->prepare(
            'SELECT id
             FROM coach_athlete_agreement_responses
             WHERE agreement_id = ? AND athlete_id = ?
             LIMIT 1'
        );
        $alreadyStmt->execute([$agreementId, $athleteId]);
        if ($alreadyStmt->fetch()) {
            flash('info', 'Na tuto dohodu jste již reagovali. Volbu nelze změnit.');
            redirect(BASE_URL . '/athlete_terms.php?tab=agreement');
        }

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        if (str_contains((string)$ip, ',')) {
            $ip = trim(explode(',', (string)$ip)[0]);
        }
        $ip = mb_substr((string)$ip, 0, 45);
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

        $saveStmt = $pdo->prepare(
            'INSERT INTO coach_athlete_agreement_responses (agreement_id, athlete_id, response, responded_at, ip_address, user_agent)
             VALUES (?, ?, ?, NOW(), ?, ?)'
        );
        $saveStmt->execute([$agreementId, $athleteId, $response, $ip, $ua]);

        flash('success', 'Vaše volba byla uložena. Děkujeme.');
        redirect(BASE_URL . '/athlete_terms.php?tab=agreement');
    }
}

$tab = (($_GET['tab'] ?? '') === 'agreement') ? 'agreement' : 'terms';

$activeAgreementStmt = $pdo->prepare(
    'SELECT id, version, title, body, approve_label, reject_label, attachment_path, attachment_name, created_at
     FROM coach_athlete_agreements
     WHERE coach_id = ? AND is_active = 1
     ORDER BY version DESC, id DESC
     LIMIT 1'
);
$activeAgreementStmt->execute([$coachId]);
$activeAgreement = $activeAgreementStmt->fetch() ?: null;

$athleteAgreementResponse = null;
$athletePreviousAgreementResponse = null;
if ($activeAgreement) {
    $responseStmt = $pdo->prepare(
        'SELECT response, responded_at
         FROM coach_athlete_agreement_responses
         WHERE agreement_id = ? AND athlete_id = ?
         LIMIT 1'
    );
    $responseStmt->execute([(int)$activeAgreement['id'], $athleteId]);
    $athleteAgreementResponse = $responseStmt->fetch() ?: null;

    if ($athleteAgreementResponse === null) {
        $previousStmt = $pdo->prepare(
            'SELECT car.response, car.responded_at, ca.version
             FROM coach_athlete_agreement_responses car
             JOIN coach_athlete_agreements ca ON ca.id = car.agreement_id
             WHERE car.athlete_id = ?
               AND ca.coach_id = ?
             ORDER BY ca.version DESC, ca.id DESC, car.responded_at DESC
             LIMIT 1'
        );
        $previousStmt->execute([$athleteId, $coachId]);
        $athletePreviousAgreementResponse = $previousStmt->fetch() ?: null;
    }
}

$agreementNeedsAction = ($activeAgreement !== null && $athleteAgreementResponse === null);

renderAthleteHeader('Všeobecné podmínky pro sportovce', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-file-contract me-2 text-warning"></i>Všeobecné podmínky pro sportovce</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Vytisknout
        </button>
    </div>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'terms' ? 'active' : '' ?>" href="<?= BASE_URL ?>/athlete_terms.php?tab=terms">
            <i class="fas fa-file-contract me-1"></i>Všeobecné podmínky
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'agreement' ? 'active' : '' ?>" href="<?= BASE_URL ?>/athlete_terms.php?tab=agreement">
            <i class="fas fa-handshake me-1"></i>Dohoda s trenérem
            <?php if ($agreementNeedsAction): ?>
            <span class="badge rounded-pill bg-danger ms-1" style="font-size:.65rem">Akce</span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($agreementNeedsAction && $tab !== 'agreement'): ?>
<div class="alert alert-warning border-0 shadow-sm mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <span><i class="fas fa-exclamation-triangle me-2"></i>Vyžadována akce v záložce <strong>Dohoda s trenérem</strong>.</span>
    <a href="<?= BASE_URL ?>/athlete_terms.php?tab=agreement" class="btn btn-sm btn-outline-dark">
        <i class="fas fa-hand-pointer me-1"></i>Přejít na dohodu
    </a>
</div>
<?php endif; ?>

<?php if ($tab === 'terms'): ?>

<div class="alert alert-warning border-0 shadow-sm">
    Tento dokument obsahuje všeobecné podmínky používání aplikace pro sportovce, včetně omezení odpovědnosti a účelu projektu.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">1) Účel aplikace</div>
    <div class="card-body">
        <p>
            Aplikace slouží jako organizační a evidenční nástroj pro spolupráci mezi sportovcem a trenérem
            v rámci omezené, interní a úzké skupiny uživatelů.
            Nejde o veřejnou komerční službu určenou pro masové užívání.
        </p>
        <p class="mb-0">
            Aplikace má podpůrný charakter. Nenahrazuje zdravotní péči, lékařské doporučení,
            osobní konzultaci ani odborné posouzení zdravotního stavu.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">2) Ne-komerční charakter projektu</div>
    <div class="card-body">
        <p>
            Vývojář není podnikatel a tento projekt neslouží k výdělku, prodeji ani k poskytování placených služeb vývojářem.
            Projekt je provozován jako neveřejný nástroj pro omezený okruh uživatelů.
        </p>
        <p class="mb-0">
            Používání aplikace nezakládá mezi sportovcem a vývojářem obchodní vztah,
            závazek poskytování zákaznické podpory v režimu komerční služby ani garanci nepřetržité dostupnosti systému.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">3) Odpovědnost za data a jejich ztrátu</div>
    <div class="card-body">
        <p>
            Uživatel bere na vědomí, že vývojář nenese odpovědnost za ztrátu dat, poškození dat,
            neúplnost záznamů nebo nedostupnost údajů z důvodu technické poruchy,
            výpadku hostingu, chyby infrastruktury, zásahu třetích stran nebo nesprávného použití aplikace.
        </p>
        <p>
            Sportovec je odpovědný za to, aby důležité informace průběžně konzultoval s trenérem
            a nespoléhal výhradně na jediný elektronický záznam bez vlastní kontroly.
        </p>
        <p class="mb-0">
            Vkládání dat do aplikace probíhá na vlastní odpovědnost uživatele. Uživatel bere na vědomí,
            že data mohou být ovlivněna technickými limity prostředí, ve kterém je aplikace provozována.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">4) Omezení odpovědnosti vývojáře</div>
    <div class="card-body">
        <p>
            Vývojář nenese odpovědnost za přímou ani nepřímou újmu vzniklou v souvislosti s používáním aplikace,
            zejména za ušlý prospěch, provozní komplikace, ztrátu dat, zpoždění informací,
            chybné vyhodnocení výkonu nebo následky rozhodnutí učiněných na základě údajů v aplikaci.
        </p>
        <p class="mb-0">
            Aplikace je poskytována v režimu "jak stojí a leží" bez výslovných či implicitních záruk,
            včetně záruky vhodnosti pro konkrétní účel, nepřetržité funkčnosti nebo bezchybnosti.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">5) Zdravotní a bezpečnostní upozornění</div>
    <div class="card-body">
        <p>
            Tréninkové a výživové informace v aplikaci mají informativní charakter a musí být posuzovány
            s ohledem na individuální zdravotní stav sportovce.
        </p>
        <p class="mb-0">
            Vývojář neodpovídá za zranění, zdravotní komplikace ani jiné újmy vzniklé při sportovní aktivitě,
            při realizaci tréninkových plánů nebo při dodržování výživových doporučení.
            Za odborné vedení odpovídá trenér a za vlastní zdravotní rozhodnutí odpovídá sportovec.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">6) Povinnosti sportovce při používání aplikace</div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Chránit své přihlašovací údaje a nesdílet účet s dalšími osobami.</li>
            <li>Pravidelně kontrolovat kalendář, zprávy, jídelníčky a tréninkové záznamy.</li>
            <li>Neprodleně řešit nejasnosti nebo chyby se svým trenérem.</li>
            <li>Nevkládat nepravdivé, zavádějící nebo protiprávní informace.</li>
            <li>Používat aplikaci pouze v souladu s jejím určením.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">7) Soukromí a zabezpečení účtu</div>
    <div class="card-body">
        <p>
            Uživatel je odpovědný za zabezpečení svého zařízení a účtu. V případě podezření na zneužití účtu
            je povinen bezodkladně změnit heslo a kontaktovat trenéra.
        </p>
        <p class="mb-0">
            Vývojář nenese odpovědnost za škody vzniklé v důsledku slabého hesla, sdíleného zařízení,
            nezabezpečené sítě nebo jiných okolností mimo přímou kontrolu vývojáře.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">8) Přijetí podmínek</div>
    <div class="card-body">
        <p>
            Používáním aplikace sportovec potvrzuje, že se s těmito podmínkami seznámil,
            rozumí jim a souhlasí s nimi.
        </p>
        <p class="mb-0">
            Pokud sportovec s podmínkami nesouhlasí, neměl by aplikaci používat.
            Vývojář si vyhrazuje právo text podmínek přiměřeně aktualizovat.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">9) Kontakt</div>
    <div class="card-body">
        <p class="mb-2">
            Pro dotazy k těmto podmínkám nebo k provozu aplikace použijte následující kontakt:
        </p>
        <p class="mb-0"><strong>E-mail:</strong> tomas.tomeska@seznam.cz</p>
    </div>
</div>

<div class="alert alert-secondary border-0 shadow-sm mb-4">
    Datum poslední úpravy podmínek: <?= date('d.m.Y') ?>
</div>

<?php else: ?>

<?php if (!$activeAgreement): ?>
<div class="alert alert-info border-0 shadow-sm mb-4">
    Trenér zatím nezveřejnil žádnou aktivní dohodu.
</div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-handshake me-2"></i><?= h((string)$activeAgreement['title']) ?></span>
        <small class="text-white-50">Verze v<?= (int)$activeAgreement['version'] ?> • zveřejněno: <?= formatDateTime((string)$activeAgreement['created_at']) ?></small>
    </div>
    <div class="card-body">
        <div class="agreement-body"><?= (string)$activeAgreement['body'] ?></div>
        <?php if (!empty($activeAgreement['attachment_path']) && !empty($activeAgreement['attachment_name'])): ?>
        <hr>
        <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/agreement_attachment.php?agreement_id=<?= (int)$activeAgreement['id'] ?>">
            <i class="fas fa-download me-1"></i>Stáhnout přílohu: <?= h((string)$activeAgreement['attachment_name']) ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($athleteAgreementResponse): ?>
<?php
$response = (string)($athleteAgreementResponse['response'] ?? '');
$badgeClass = $response === 'approved' ? 'bg-success' : 'bg-danger';
$label = $response === 'approved' ? (string)$activeAgreement['approve_label'] : (string)$activeAgreement['reject_label'];
?>
<div class="alert alert-secondary border-0 shadow-sm mb-4">
    Vaše volba: <span class="badge <?= $badgeClass ?>"><?= h($label) ?></span>
    <br>
    <small class="text-muted">Potvrzeno dne <?= formatDateTime((string)$athleteAgreementResponse['responded_at']) ?>. Volbu již nelze změnit.</small>
</div>
<?php else: ?>
<?php if ($athletePreviousAgreementResponse): ?>
<?php
$prevResponse = (string)($athletePreviousAgreementResponse['response'] ?? '');
$prevLabel = $prevResponse === 'rejected' ? 'Zamítnuto' : 'Schváleno';
?>
<div class="alert alert-info border-0 shadow-sm mb-4">
    Poslední potvrzená dohoda: verze v<?= (int)$athletePreviousAgreementResponse['version'] ?>
    (<?= h($prevLabel) ?> dne <?= formatDateTime((string)$athletePreviousAgreementResponse['responded_at']) ?>).
    Aktuální verze v<?= (int)$activeAgreement['version'] ?> vyžaduje novou volbu.
</div>
<?php endif; ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <p class="mb-3">Prosím vyberte jednu možnost. Po potvrzení už volbu nelze změnit.</p>
        <form method="post" class="d-flex flex-wrap gap-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="respond_agreement">
            <input type="hidden" name="agreement_id" value="<?= (int)$activeAgreement['id'] ?>">
            <button type="submit" name="response" value="approved" class="btn btn-success fw-bold"
                    onclick="return confirm('Opravdu chcete potvrdit tuto volbu? Tuto akci nelze změnit.');">
                <i class="fas fa-check me-1"></i><?= h((string)$activeAgreement['approve_label']) ?>
            </button>
            <button type="submit" name="response" value="rejected" class="btn btn-danger fw-bold"
                    onclick="return confirm('Opravdu chcete potvrdit tuto volbu? Tuto akci nelze změnit.');">
                <i class="fas fa-times me-1"></i><?= h((string)$activeAgreement['reject_label']) ?>
            </button>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php renderAthleteFooter(); ?>

<style>
.agreement-body p:last-child,
.agreement-body ul:last-child,
.agreement-body ol:last-child {
    margin-bottom: 0;
}
</style>
