<?php
/**
 * @var PDO $pdo
 * @var array $athlete
 * @var bool $athleteSpecialTrainingEnabled
 * @var bool $mycoachAppUserCanAccess
 * @var bool $healthQuestionnaireAccessEnabled
 * @var array $athleteMedals
 * @var bool $showHealthQuestionnairePrompt
 * @var array|null $editingWeightLog
 * @var string $weightFormAction
 * @var string $weightFormDate
 * @var string|float $weightFormValue
 * @var array $weightStats
 * @var array $weightHistory
 * @var array $weightVisibleRows
 * @var array $weightMobileRows
 * @var bool $weightShouldExpandAll
 * @var array $trainingMobileRows
 * @var array $trainingVisibleRows
 * @var array $onlineHistoryVisibleRows
 * @var array $onlineHistoryCollapsedRows
 * @var string|null $supportBankAccountForQr
 * @var string $supportBankAccount
 * @var string $supportContributorName
 * @var string $supportQrNote
 * @var array{settings: array, latest: array, height: array}|null $calculatorProfile
 */
require_once __DIR__ . '/dashboard_quick_menu.php';
// Dashboard view; data and POST actions stay in athlete_dashboard.php.
$latestWeightLog = $weightHistory[0] ?? null;
$weightTodayLog = null;
foreach ($weightHistory as $weightLog) {
    if (($weightLog['measured_at'] ?? '') === date('Y-m-d')) {
        $weightTodayLog = $weightLog;
        break;
    }
}
renderAthleteHeader('Profil sportovce', false, true, true);
?>

<style>
.athlete-dashboard-details {
    container-type: inline-size;
}

.athlete-mobile-dashboard {
    display: flex;
    flex-direction: column;
}

.athlete-mobile-next-training {
    order: 0;
}

.athlete-mobile-profile-photo {
    order: 1;
}

.athlete-profile-grid,
.athlete-activity-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1.5rem;
    align-items: start;
}

.athlete-profile-grid > *,
.athlete-activity-grid > * {
    min-width: 0;
}

@container (min-width: 840px) {
    .athlete-activity-grid,
    .athlete-profile-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .athlete-profile-grid > #weight-history {
        grid-column: 1 / -1;
    }
}

@container (min-width: 1180px) {
    .athlete-profile-grid {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.3fr);
    }

    .athlete-profile-grid > #weight-history {
        grid-column: auto;
    }

    .athlete-profile-photo {
        flex-wrap: wrap;
    }

    .athlete-profile-photo form {
        flex-basis: 180px;
        min-width: 0;
    }

    .athlete-profile-information td {
        overflow-wrap: anywhere;
    }

    .athlete-weight-form > .col-md-4 {
        width: 100%;
    }
}

@media (max-width: 767.98px) {
    .athlete-desktop-only {
        display: none !important;
    }

    .athlete-mobile-summary-card {
        border: 1px solid #dde5ee;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }

    .athlete-mobile-summary-card__head {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        align-items: flex-start;
        padding: 0.8rem 0.85rem;
        border-bottom: 1px solid #eef2f7;
        background: #f8fafc;
    }

    .athlete-mobile-summary-card__title {
        font-weight: 800;
        font-size: 0.98rem;
        line-height: 1.2;
    }

    .athlete-mobile-summary-card__body {
        padding: 0.8rem 0.85rem;
    }

    .athlete-mobile-summary-meta {
        display: grid;
        gap: 0.5rem;
    }

    .athlete-mobile-summary-line {
        display: grid;
        gap: 0.08rem;
    }

    .athlete-mobile-summary-label {
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #64748b;
    }

    .athlete-mobile-payment-card {
        border: 1px solid #e6edf5;
        border-radius: 12px;
        padding: 0.65rem 0.7rem;
        background: #fff;
    }

    .athlete-mobile-payment-card + .athlete-mobile-payment-card {
        margin-top: 0.55rem;
    }

    .athlete-mobile-payment-top {
        display: flex;
        justify-content: space-between;
        gap: 0.6rem;
        align-items: flex-start;
        margin-bottom: 0.35rem;
    }

    .athlete-mobile-payment-month {
        font-weight: 800;
    }

    .athlete-mobile-payment-grid {
        display: grid;
        gap: 0.25rem;
        font-size: 0.84rem;
    }

    .athlete-mobile-history-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
        padding: 0.7rem;
    }

    .athlete-mobile-history-card + .athlete-mobile-history-card {
        margin-top: 0.55rem;
    }

    .athlete-mobile-history-grid {
        display: grid;
        gap: 0.25rem;
        font-size: 0.84rem;
    }

    .athlete-mobile-history-actions {
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
        margin-top: 0.55rem;
    }

    .athlete-mobile-accordion .accordion-button {
        padding: 0.8rem 0.9rem;
        font-weight: 700;
    }

    .athlete-mobile-accordion .accordion-body {
        padding: 0.8rem 0.75rem;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-user me-2 text-warning"></i>Můj profil</h2>
</div>

<?php require __DIR__ . '/athlete_subscription_alert.php'; ?>
<div class="mb-3"><a href="<?= BASE_URL ?>/intake_questionnaire.php" class="btn btn-outline-dark btn-sm">Můj vstupní dotazník</a></div>

<div class="card border-0 shadow-sm mb-4 athlete-desktop-only">
    <div class="card-header bg-dark text-white"><i class="fas fa-calendar-check me-2"></i>Plán tréninků</div>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="text-muted small">Zaplánované tréninky</div>
                <div class="display-6 fw-bold mb-0"><?= (int)$upcomingPlannedCount ?></div>
            </div>
            <div class="text-start text-md-end">
                <?php if ($nearestPlannedTraining): ?>
                    <div class="fw-semibold">Nejbližší termín</div>
                    <div><?= formatDateTime((string)$nearestPlannedTraining['starts_at']) ?></div>
                    <div class="text-muted small">
                        Místo: <?= !empty($nearestPlannedTraining['location']) ? h((string)$nearestPlannedTraining['location']) : 'neuvedeno' ?>
                    </div>
                    <?php if (($nearestPlannedTraining['approval_status'] ?? 'approved') === 'pending'): ?>
                    <span class="badge bg-warning text-dark mt-1">Ke schválení</span>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted">Nejbližší termín zatím není naplánovaný.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<section class="card border-0 shadow-sm mb-4" aria-labelledby="athlete-medals-title">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <span id="athlete-medals-title"><i class="fas fa-medal me-2 text-warning"></i>Moje ocenění</span>
        <a href="<?= BASE_URL ?>/athlete_medals.php" class="btn btn-warning btn-sm fw-semibold">Všechny medaile</a>
    </div>
    <div class="card-body">
        <div class="medal-grid medal-grid--preview">
            <?php foreach (array_slice($athleteMedals, 0, 5) as $medal): ?>
                <?php renderAthleteMedalCard($medal); ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require __DIR__ . '/athlete_quick_menu.php'; ?>

<div class="d-md-none mb-4 athlete-mobile-dashboard">
    <div class="athlete-mobile-summary-card mb-3 athlete-mobile-next-training">
        <div class="athlete-mobile-summary-card__head">
            <div>
                <div class="athlete-mobile-summary-card__title"><i class="fas fa-calendar-check me-2 text-primary"></i>Nejbližší trénink</div>
            </div>
            <a href="<?= BASE_URL ?>/athlete_calendar.php" class="btn btn-outline-primary btn-sm">
                Kalendář
            </a>
        </div>
        <div class="athlete-mobile-summary-card__body">
            <?php if ($nearestPlannedTraining): ?>
            <div class="athlete-mobile-summary-meta">
                <div class="athlete-mobile-summary-line">
                    <span class="athlete-mobile-summary-label">Datum a čas</span>
                    <strong><?= formatDateTime((string)$nearestPlannedTraining['starts_at']) ?></strong>
                </div>
                <div class="athlete-mobile-summary-line">
                    <span class="athlete-mobile-summary-label">Místo</span>
                    <strong><?= !empty($nearestPlannedTraining['location']) ? h((string)$nearestPlannedTraining['location']) : 'neuvedeno' ?></strong>
                </div>
                <?php if (($nearestPlannedTraining['approval_status'] ?? 'approved') === 'pending'): ?>
                <span class="badge bg-warning text-dark align-self-start">Ke schválení</span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="text-muted">Nejbližší termín zatím není naplánovaný.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="athlete-mobile-summary-card mb-3 athlete-mobile-profile-photo">
        <div class="athlete-mobile-summary-card__head">
            <div class="athlete-mobile-summary-card__title"><i class="fas fa-camera me-2 text-warning"></i>Profilová fotografie</div>
        </div>
        <div class="athlete-mobile-summary-card__body">
            <?php $athleteMobileProfilePhoto = photoUrl($athlete['photo'] ?? null, 'athletes'); ?>
            <div class="d-flex align-items-center gap-3">
                <?php if ($athleteMobileProfilePhoto): ?>
                <img src="<?= h($athleteMobileProfilePhoto) ?>" alt="Profilová fotografie" class="rounded-circle" style="width:64px;height:64px;object-fit:cover;flex:0 0 auto;">
                <?php else: ?>
                <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted" style="width:64px;height:64px;flex:0 0 auto;">
                    <i class="fas fa-user fa-lg"></i>
                </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="flex-grow-1">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_profile_photo">
                    <input type="file" name="photo" class="form-control form-control-sm" accept="image/*" required>
                    <button type="submit" class="btn btn-warning btn-sm mt-2"><i class="fas fa-upload me-1"></i>Nahrát fotografii</button>
                </form>
            </div>
        </div>
    </div>

    <div class="athlete-mobile-summary-card mb-3">
        <div class="athlete-mobile-summary-card__head">
            <div>
                <div class="athlete-mobile-summary-card__title"><i class="fas fa-wallet me-2 text-warning"></i>Platby</div>
                <div class="small text-muted">Poslední přehled</div>
            </div>
            <a href="<?= BASE_URL ?>/athlete_payments.php" class="btn btn-warning btn-sm fw-semibold">
                Všechny
            </a>
        </div>
        <div class="athlete-mobile-summary-card__body">
            <?php if (!empty($paymentRowsForView)): ?>
                <?php foreach (array_slice($paymentRowsForView, 0, 3) as $row): ?>
                <div class="athlete-mobile-payment-card">
                    <div class="athlete-mobile-payment-top">
                        <div class="athlete-mobile-payment-month"><?= h($row['month_label']) ?></div>
                        <?php if ($row['is_paid']): ?>
                            <span class="badge bg-success">Uhrazeno</span>
                        <?php elseif ($row['is_pending']): ?>
                            <span class="badge bg-warning text-dark">Čeká</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Nevystaveno</span>
                        <?php endif; ?>
                    </div>
                    <div class="athlete-mobile-payment-grid">
                        <div><span class="text-muted">Tréninky:</span> <strong><?= (int)$row['billable_sessions'] ?></strong></div>
                        <div><span class="text-muted">Částka:</span> <strong><?= $row['display_amount'] !== null ? number_format((float)$row['display_amount'], 0, ',', ' ') . ' Kč' : 'nelze spočítat' ?></strong></div>
                        <?php if (!empty($row['payment']['paid_at'])): ?>
                        <div><span class="text-muted">Uhrazeno:</span> <?= formatDateTime((string)$row['payment']['paid_at']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-muted">Aktuálně tu nemáte žádnou evidovanou platbu.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="accordion athlete-mobile-accordion" id="athleteMobileAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="headingWeightInputMobile">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseWeightInputMobile" aria-expanded="false" aria-controls="collapseWeightInputMobile">
                    Zapsat váhu
                </button>
            </h2>
            <div id="collapseWeightInputMobile" class="accordion-collapse collapse" aria-labelledby="headingWeightInputMobile" data-bs-parent="#athleteMobileAccordion">
                <div class="accordion-body">
                    <?php if ($latestWeightLog): ?>
                    <div class="alert alert-light border">Poslední váha: <strong><?= number_format((float)$latestWeightLog['weight_kg'], 1, ',', '') ?> kg</strong>, <?= h(date('j. n. Y', strtotime((string)$latestWeightLog['measured_at']))) ?></div>
                    <?php endif; ?>
                    <?php if (!$editingWeightLog && $weightTodayLog): ?>
                    <div class="alert alert-warning">Na dnešek už máte zadanou váhu <?= number_format((float)$weightTodayLog['weight_kg'], 1, ',', '') ?> kg. Znovu ji zadat nejde.</div>
                    <?php endif; ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="<?= h($weightFormAction) ?>">
                        <?php if ($editingWeightLog): ?>
                        <input type="hidden" name="weight_log_id" value="<?= (int)$editingWeightLog['id'] ?>">
                        <?php endif; ?>
                        <div class="mb-2">
                            <label class="form-label fw-semibold" for="measured_at_mobile">Datum vážení</label>
                            <input type="date" id="measured_at_mobile" name="measured_at" class="form-control" value="<?= h((string)$weightFormDate) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="weight_kg_mobile">Hmotnost (kg)</label>
                            <input type="number" id="weight_kg_mobile" name="weight_kg" class="form-control" min="20" max="400" step="0.1" value="<?= h((string)$weightFormValue) ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">
                            <i class="fas fa-save me-1"></i><?= $editingWeightLog ? 'Uložit změny' : 'Uložit váhu' ?>
                        </button>
                    </form>
                    <small class="text-muted d-block mt-2">Na jeden den lze váhu zapsat jen jednou.</small>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header" id="headingWeightHistoryMobile">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseWeightHistoryMobile" aria-expanded="false" aria-controls="collapseWeightHistoryMobile">
                    Historie hmotnosti
                </button>
            </h2>
            <div id="collapseWeightHistoryMobile" class="accordion-collapse collapse" aria-labelledby="headingWeightHistoryMobile" data-bs-parent="#athleteMobileAccordion">
                <div class="accordion-body">
                    <?php if (empty($weightHistory)): ?>
                        <div class="text-muted text-center py-2">Zatím tu není žádný záznam hmotnosti.</div>
                    <?php else: ?>
                        <?php foreach ($weightMobileRows as $weightRow): ?>
                            <?php
                                $sourceLabel = 'Ruční záznam';
                                if (($weightRow['source'] ?? '') === 'coach') {
                                    $sourceLabel = 'Trenér';
                                } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                                    $sourceLabel = 'Sportovec';
                                }
                            ?>
                            <div class="athlete-mobile-history-card <?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'border border-warning' : '' ?>">
                                <div class="athlete-mobile-history-grid">
                                    <div><span class="text-muted">Datum:</span> <strong><?= formatDate((string)$weightRow['measured_at']) ?></strong></div>
                                    <div><span class="text-muted">Hmotnost:</span> <strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></div>
                                    <div><span class="text-muted">Zdroj:</span> <span class="badge bg-secondary"><?= h($sourceLabel) ?></span></div>
                                </div>
                                <div class="athlete-mobile-history-actions">
                                    <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-pen me-1"></i>Upravit
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_weight">
                                        <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash me-1"></i>Smazat
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!empty($weightMobileCollapsedRows)): ?>
                        <div class="collapse mt-2" id="athleteWeightMobileMore">
                            <?php foreach ($weightMobileCollapsedRows as $weightRow): ?>
                                <?php
                                    $sourceLabel = 'Ruční záznam';
                                    if (($weightRow['source'] ?? '') === 'coach') {
                                        $sourceLabel = 'Trenér';
                                    } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                                        $sourceLabel = 'Sportovec';
                                    }
                                ?>
                                <div class="athlete-mobile-history-card">
                                    <div class="athlete-mobile-history-grid">
                                        <div><span class="text-muted">Datum:</span> <strong><?= formatDate((string)$weightRow['measured_at']) ?></strong></div>
                                        <div><span class="text-muted">Hmotnost:</span> <strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></div>
                                        <div><span class="text-muted">Zdroj:</span> <span class="badge bg-secondary"><?= h($sourceLabel) ?></span></div>
                                    </div>
                                    <div class="athlete-mobile-history-actions">
                                        <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-pen me-1"></i>Upravit
                                        </a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_weight">
                                            <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash me-1"></i>Smazat
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-2">
                            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteWeightMobileMore" aria-expanded="false" aria-controls="athleteWeightMobileMore">
                                <i class="fas fa-chevron-down me-1"></i>Zobrazit všechny záznamy (<?= count($weightMobileCollapsedRows) ?>)
                            </button>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header" id="headingTrainingHistoryMobile">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTrainingHistoryMobile" aria-expanded="false" aria-controls="collapseTrainingHistoryMobile">
                    Historie tréninků
                </button>
            </h2>
            <div id="collapseTrainingHistoryMobile" class="accordion-collapse collapse" aria-labelledby="headingTrainingHistoryMobile" data-bs-parent="#athleteMobileAccordion">
                <div class="accordion-body">
                    <?php if (empty($sessions)): ?>
                        <div class="text-muted text-center py-2">Zatím nemáte žádné tréninky.</div>
                    <?php else: ?>
                        <?php foreach ($trainingMobileRows as $s): ?>
                        <div class="athlete-mobile-history-card">
                            <div class="athlete-mobile-history-grid">
                                <div><span class="text-muted">Datum:</span> <strong><?= formatDateTime((string)$s['started_at']) ?></strong></div>
                                <div><span class="text-muted">Sada:</span> <strong><?= h((string)$s['set_name']) ?></strong></div>
                                <div><span class="text-muted">Místo:</span> <?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></div>
                                <div><span class="text-muted">Stav:</span> <?php if (!empty($s['completed_at'])): ?><span class="badge bg-success">Dokončeno</span><?php else: ?><span class="badge bg-warning text-dark">Naplánováno / probíhá</span><?php endif; ?></div>
                            </div>
                            <div class="athlete-mobile-history-actions">
                                <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-eye me-1"></i>Detail
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!empty($trainingMobileCollapsedRows)): ?>
                        <div class="collapse mt-2" id="athleteTrainingMobileMore">
                            <?php foreach ($trainingMobileCollapsedRows as $s): ?>
                            <div class="athlete-mobile-history-card">
                                <div class="athlete-mobile-history-grid">
                                    <div><span class="text-muted">Datum:</span> <strong><?= formatDateTime((string)$s['started_at']) ?></strong></div>
                                    <div><span class="text-muted">Sada:</span> <strong><?= h((string)$s['set_name']) ?></strong></div>
                                    <div><span class="text-muted">Místo:</span> <?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></div>
                                    <div><span class="text-muted">Stav:</span> <?php if (!empty($s['completed_at'])): ?><span class="badge bg-success">Dokončeno</span><?php else: ?><span class="badge bg-warning text-dark">Naplánováno / probíhá</span><?php endif; ?></div>
                                </div>
                                <div class="athlete-mobile-history-actions">
                                    <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-eye me-1"></i>Detail
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-2">
                            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteTrainingMobileMore" aria-expanded="false" aria-controls="athleteTrainingMobileMore">
                                <i class="fas fa-chevron-down me-1"></i>Zobrazit všechny tréninky (<?= count($trainingMobileCollapsedRows) ?>)
                            </button>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mb-4">
    <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteAccountDeletionForm" aria-expanded="false" aria-controls="athleteAccountDeletionForm">
        <i class="fas fa-user-slash me-1"></i>Požádat o smazání účtu
    </button>
    <div class="collapse mt-2" id="athleteAccountDeletionForm">
        <div class="border border-danger rounded p-3">
            <p class="small text-muted mb-3">
                Systém odstraní profil, přístupová data a související záznamy v rozsahu aplikace. Zákonné účetní a evidenční záznamy mohou být uchovány podle právních povinností.
            </p>
            <form method="post" onsubmit="return confirm('Opravdu chcete požádat o smazání svého účtu a přidružených dat? Tato akce je nevratná.');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="request_account_deletion">
                <div class="d-flex gap-2 flex-wrap align-items-start">
                    <textarea name="delete_reason" class="form-control" rows="2" maxlength="1000" placeholder="Volitelný důvod žádosti"></textarea>
                    <button type="submit" class="btn btn-outline-danger">
                        <i class="fas fa-user-slash me-1"></i>Odeslat žádost
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="athlete-dashboard-details" id="athlete-dashboard-details">
<div class="athlete-profile-grid mb-4 athlete-desktop-only">
    <div class="athlete-profile-information">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white"><i class="fas fa-id-card me-2"></i>Informace</div>
            <div class="card-body">
                <?php $athleteProfilePhoto = photoUrl($athlete['photo'] ?? null, 'athletes'); ?>
                <div class="athlete-profile-photo d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
                    <?php if ($athleteProfilePhoto): ?>
                    <img src="<?= h($athleteProfilePhoto) ?>" alt="Profilová fotografie" class="rounded-circle" style="width:72px;height:72px;object-fit:cover;">
                    <?php else: ?>
                    <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-muted" style="width:72px;height:72px;flex:0 0 auto;">
                        <i class="fas fa-user fa-2x"></i>
                    </div>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data" class="flex-grow-1">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_profile_photo">
                        <label class="form-label fw-semibold mb-1">Profilová fotografie</label>
                        <input type="file" name="photo" class="form-control form-control-sm" accept="image/*" required>
                        <div class="form-text">Vyberte novou fotografii a odešlete ji.</div>
                        <button type="submit" class="btn btn-warning btn-sm mt-2">
                            <i class="fas fa-camera me-1"></i>Nahrát fotografii
                        </button>
                    </form>
                </div>
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted fw-semibold" style="width:45%">Jméno</td><td><?= h(trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])) ?> <span class="text-muted">(ID: <?= (int)$athleteId ?>)</span></td></tr>
                    <tr><td class="text-muted fw-semibold">E-mail</td><td><?= h((string)$athlete['email']) ?></td></tr>
                    <tr><td class="text-muted fw-semibold">Trenér</td><td><?= h((string)($athlete['coach_name'] ?: $athlete['coach_username'])) ?></td></tr>
                    <tr><td class="text-muted fw-semibold">Datum narození</td><td><?= !empty($athlete['birth_date']) ? formatDate((string)$athlete['birth_date']) : '–' ?></td></tr>
                    <tr><td class="text-muted fw-semibold">Aktuální váha</td><td><?= $weightStats['current_weight'] !== null ? number_format((float)$weightStats['current_weight'], 1, ',', '') . ' kg' : '–' ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <div>
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-primary text-white"><i class="fas fa-weight-scale me-2"></i>Zaznamenat aktuální hmotnost</div>
            <div class="card-body">
                <?php if ($latestWeightLog): ?>
                <div class="alert alert-light border">Poslední váha: <strong><?= number_format((float)$latestWeightLog['weight_kg'], 1, ',', '') ?> kg</strong>, <?= h(date('j. n. Y', strtotime((string)$latestWeightLog['measured_at']))) ?></div>
                <?php endif; ?>
                <?php if (!$editingWeightLog && $weightTodayLog): ?>
                <div class="alert alert-warning">Na dnešek už máte zadanou váhu <?= number_format((float)$weightTodayLog['weight_kg'], 1, ',', '') ?> kg. Znovu ji zadat nejde.</div>
                <?php endif; ?>
                <form method="post" class="athlete-weight-form row g-3 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= h($weightFormAction) ?>">
                    <?php if ($editingWeightLog): ?>
                    <input type="hidden" name="weight_log_id" value="<?= (int)$editingWeightLog['id'] ?>">
                    <?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Datum vážení</label>
                        <input type="date" name="measured_at" class="form-control" value="<?= h((string)$weightFormDate) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Hmotnost (kg)</label>
                        <input type="number" name="weight_kg" class="form-control" min="20" max="400" step="0.1" value="<?= h((string)$weightFormValue) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100 fw-semibold"><?= $editingWeightLog ? 'Uložit změny' : 'Uložit' ?></button>
                            <?php if ($editingWeightLog): ?>
                            <a href="<?= BASE_URL ?>/athlete_dashboard.php#weight-history" class="btn btn-outline-secondary">Zrušit</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
                <small class="text-muted d-block mt-2">Na jeden den lze váhu zapsat jen jednou.</small>
            </div>
        </div>
    </div>

<div class="card border-0 shadow-sm" id="weight-history">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-clock-rotate-left me-2"></i>Historie hmotnosti</span>
        <span class="badge bg-light text-dark"><?= count($weightHistory) ?> záznamů</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($weightHistory)): ?>
        <div class="text-center text-muted py-4">Zatím tu není žádný záznam hmotnosti.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                <tr>
                    <th>Datum</th>
                    <th>Hmotnost</th>
                    <th>Zdroj</th>
                    <th class="text-end">Akce</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($weightVisibleRows as $weightRow): ?>
                    <?php
                        $sourceLabel = 'Ruční záznam';
                        if (($weightRow['source'] ?? '') === 'coach') {
                            $sourceLabel = 'Trenér';
                        } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                            $sourceLabel = 'Sportovec';
                        }
                    ?>
                <tr class="<?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'table-warning' : '' ?>">
                    <td><?= formatDate((string)$weightRow['measured_at']) ?></td>
                    <td><strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></td>
                    <td><span class="badge bg-secondary"><?= h($sourceLabel) ?></span></td>
                    <td class="text-end">
                        <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-pen me-1"></i>Upravit
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_weight">
                            <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (!empty($weightCollapsedRows)): ?>
                <tbody id="athleteWeightHistoryCollapse" class="collapse <?= $weightShouldExpandAll ? 'show' : '' ?>">
                <?php foreach ($weightCollapsedRows as $weightRow): ?>
                    <?php
                        $sourceLabel = 'Ruční záznam';
                        if (($weightRow['source'] ?? '') === 'coach') {
                            $sourceLabel = 'Trenér';
                        } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                            $sourceLabel = 'Sportovec';
                        }
                    ?>
                <tr class="<?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'table-warning' : '' ?>">
                    <td><?= formatDate((string)$weightRow['measured_at']) ?></td>
                    <td><strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></td>
                    <td><span class="badge bg-secondary"><?= h($sourceLabel) ?></span></td>
                    <td class="text-end">
                        <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-pen me-1"></i>Upravit
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_weight">
                            <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($weightCollapsedRows)): ?>
        <div class="border-top p-3 text-center bg-light">
            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteWeightHistoryCollapse" aria-expanded="<?= $weightShouldExpandAll ? 'true' : 'false' ?>" aria-controls="athleteWeightHistoryCollapse">
                <i class="fas fa-chevron-down me-1"></i>
                <?= $weightShouldExpandAll ? 'Skrýt starší záznamy' : 'Zobrazit starší záznamy (' . count($weightCollapsedRows) . ')' ?>
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<?php if (!empty($calculatorProfile)): ?>
<?php
$calcLatestBmi = $calculatorProfile['latest']['bmi'] ?? null;
$calcBmiToneClass = [
    'info' => 'bg-info text-dark', 'success' => 'bg-success', 'warning' => 'bg-warning text-dark',
    'orange' => 'bg-warning text-dark', 'danger' => 'bg-danger', 'dark' => 'bg-dark',
][(string)($calcLatestBmi['inputs']['category_tone'] ?? '')] ?? 'bg-secondary';
?>
<div class="card border-0 shadow-sm mb-4" id="calculator-profile-card">
    <div class="card-header text-white d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:linear-gradient(150deg,#23272f 0%,#16181d 60%,#0e1013 100%);border-bottom:2px solid #d4a73c;">
        <span><i class="fas fa-calculator me-2" style="color:#d4a73c"></i>Kalkulačky</span>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (calculatorIsEnabled('performance')): ?><a href="<?= BASE_URL ?>/performance.php" class="btn btn-outline-light btn-sm">Výkonnost a regenerace</a><?php endif; ?>
            <a href="<?= BASE_URL ?>/calculators_print.php?type=report&amp;print=1" target="_blank" rel="noopener" class="btn btn-outline-light btn-sm"><i class="fas fa-print me-1"></i>Tisk zprávy</a>
            <a href="<?= BASE_URL ?>/calculators.php" class="btn btn-warning btn-sm fw-semibold"><i class="fas fa-arrow-right me-1"></i>Otevřít Kalkulačky</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-4">
                <div class="text-muted small text-uppercase fw-semibold">Poslední BMI</div>
                <?php if ($calcLatestBmi): ?>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="fs-3 fw-bold"><?= number_format((float)$calcLatestBmi['result_value'], 2, ',', '') ?></span>
                        <span class="badge <?= h($calcBmiToneClass) ?>"><?= h((string)$calcLatestBmi['result_label']) ?></span>
                    </div>
                    <div class="small text-muted"><?= h(calculatorResultSummary($calcLatestBmi)) ?> · <?= h(date('j. n. Y', strtotime((string)$calcLatestBmi['created_at']))) ?></div>
                <?php else: ?>
                    <div class="fs-5 fw-semibold">–</div>
                    <a href="<?= BASE_URL ?>/calculators.php?calc=bmi" class="small">Spočítat a uložit BMI</a>
                <?php endif; ?>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small text-uppercase fw-semibold">Výška</div>
                <div class="fs-5 fw-semibold"><?= $calculatorProfile['height']['height_cm'] !== null ? number_format((float)$calculatorProfile['height']['height_cm'], 1, ',', '') . ' cm' : '–' ?></div>
                <?php if ($calculatorProfile['height']['source'] !== null): ?><div class="small text-muted"><?= h((string)$calculatorProfile['height']['source']) ?></div><?php endif; ?>
            </div>
            <div class="col-12 col-md-5">
                <form method="post" action="<?= BASE_URL ?>/calculators.php" class="m-0">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_share_setting">
                    <input type="hidden" name="return_to" value="profile">
                    <input type="hidden" name="share_with_coach" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="calcShareProfile" name="share_with_coach" value="1" <?= $calculatorProfile['settings']['share_with_coach'] ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="form-check-label fw-semibold" for="calcShareProfile">Sdílet výsledky kalkulaček s trenérem</label>
                    </div>
                    <div class="small text-muted"><?= $calculatorProfile['settings']['share_with_coach'] ? 'Trenér vidí vaše uložené výsledky a historii.' : 'Výsledky vidíte pouze vy.' ?></div>
                    <noscript><button type="submit" class="btn btn-outline-dark btn-sm mt-2">Uložit</button></noscript>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="athlete-activity-grid mb-4 athlete-desktop-only">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-wallet me-2"></i>Platby</span>
        <a href="<?= BASE_URL ?>/athlete_payments.php" class="btn btn-warning btn-sm fw-semibold">
            <i class="fas fa-eye me-1"></i>Zobrazit platby
        </a>
    </div>
    <div class="card-body">
        <?php if ($pendingMonthPreview): ?>
            <div class="alert alert-info mb-3">
                <div class="fw-semibold mb-1"><i class="fas fa-hourglass-half me-1"></i>Rozpracované požadavky pro <?= h((string)$pendingMonthPreview['month_label']) ?></div>
                <div>
                    Máte <?= (int)$pendingMonthPreview['total'] ?> neschválený(é) požadavek(y).
                    <?php if ((int)$pendingMonthPreview['makeup'] > 0): ?>
                        Náhradní: <?= (int)$pendingMonthPreview['makeup'] ?>x (0 Kč).
                    <?php endif; ?>
                    <?php if ((int)$pendingMonthPreview['single'] > 0): ?>
                        Individuální: <?= (int)$pendingMonthPreview['single'] ?>x.
                    <?php endif; ?>
                    <?php if ((int)$pendingMonthPreview['paired'] > 0): ?>
                        Párové: <?= (int)$pendingMonthPreview['paired'] ?>x.
                    <?php endif; ?>
                </div>
                <?php if ($pendingMonthPreview['estimated_amount'] !== null): ?>
                    <div class="small mt-1">Orientační částka po schválení: <strong><?= number_format((float)$pendingMonthPreview['estimated_amount'], 0, ',', ' ') ?> Kč</strong>.</div>
                <?php else: ?>
                    <div class="small mt-1">Orientační částku teď nelze spočítat (chybí sazba).</div>
                <?php endif; ?>
                <div class="small mt-1 text-muted">Jde o předběžný přehled. Finální stav se promítne po schválení trenérem.</div>
            </div>
        <?php endif; ?>

        <?php if ($activeReplacementNotice): ?>
            <div id="dashboardReplacementNotice" class="alert <?= $activeReplacementNotice['is_overdue'] ? 'alert-danger' : 'alert-warning' ?> mb-3">
                <div class="fw-semibold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>Náhradní termín po zrušení</div>
                <div>
                    <?= $activeReplacementNotice['count'] > 1
                        ? ('Máte ' . (int)$activeReplacementNotice['count'] . ' zrušené termíny, které je potřeba nahradit.')
                        : 'Máte zrušený termín, který je potřeba nahradit.' ?>
                    <?php if (!empty($activeReplacementNotice['deadline_at'])): ?>
                        Nejzazší termín rezervace je <strong><?= h(date('d.m.Y H:i', strtotime((string)$activeReplacementNotice['deadline_at']))) ?></strong>.
                    <?php endif; ?>
                </div>
                <div class="small mt-2">
                    Náhradu vyberte v <a href="<?= BASE_URL ?>/athlete_calendar.php">kalendáři</a> nejpozději do uvedeného termínu; potom nárok zaniká.
                </div>
            </div>
        <?php else: ?>
            <div id="dashboardReplacementNotice" class="alert alert-warning mb-3 d-none">
                <div class="fw-semibold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>Náhradní termín po zrušení</div>
                <div id="dashboardReplacementNoticeText"></div>
                <div class="small mt-2">
                    Náhradu vyberte v <a href="<?= BASE_URL ?>/athlete_calendar.php">kalendáři</a> nejpozději do 14 kalendářních dní od zrušeného termínu.
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($paymentRowsForView)): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Měsíc</th>
                        <th>Tréninky</th>
                        <th>Částka</th>
                        <th>Stav</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($paymentRowsForView as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= h($row['month_label']) ?></div>
                                <?php if ((int)$row['stats']['makeup_sessions'] > 0): ?>
                                    <div class="small text-muted">Náhradní termíny: <?= (int)$row['stats']['makeup_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ((int)$row['stats']['transferred_sessions'] > 0): ?>
                                    <div class="small text-muted">Z jiného kalendářního měsíce: <?= (int)$row['stats']['transferred_sessions'] ?>x</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-semibold"><?= (int)$row['billable_sessions'] ?></span>
                                <div class="small text-muted">započítaných tréninků</div>
                                <?php if ((int)($row['paired_sessions'] ?? 0) > 0): ?>
                                    <div class="small text-muted">párové: <?= (int)$row['paired_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ((int)($row['single_sessions'] ?? 0) > 0): ?>
                                    <div class="small text-muted">individuální: <?= (int)$row['single_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ((int)$row['carryover_applied'] > 0): ?>
                                    <div class="small text-warning">Započteno jako náhrada: <?= (int)$row['carryover_applied'] ?>x</div>
                                <?php endif; ?>
                                <?php if (!empty($row['is_snapshot_locked'])): ?>
                                    <div class="small text-muted">Počet ve výzvě je uzamčený.</div>
                                <?php endif; ?>
                                <?php if (!empty($row['calendar_changed'])): ?>
                                    <div class="small text-warning">Kalendář se po vystavení výzvy změnil; obsah účtenky zůstává zachovaný.</div>
                                <?php endif; ?>
                                <?php if ((int)($row['forfeited_compensations'] ?? 0) > 0): ?>
                                    <div class="small text-danger">Propadlá kompenzace: <?= (int)$row['forfeited_compensations'] ?> tréninků</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['display_amount'] !== null): ?>
                                    <span class="fw-semibold"><?= number_format((float)$row['display_amount'], 0, ',', ' ') ?> Kč</span>
                                    <?php if ((float)$row['display_amount'] <= 0.0001): ?>
                                        <div class="small text-success">Fakturovaná částka: 0 Kč</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Nelze spočítat</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['is_paid']): ?>
                                    <span class="badge bg-success">Uhrazeno</span>
                                    <?php if (!empty($row['payment']['paid_at'])): ?>
                                        <div class="small text-muted mt-1"><?= formatDateTime((string)$row['payment']['paid_at']) ?></div>
                                    <?php endif; ?>
                                <?php elseif ($row['is_pending']): ?>
                                    <span class="badge bg-warning text-dark">Čeká na úhradu</span>
                                    <div class="small text-muted mt-1">Poznámka: <?= h($row['note']) ?></div>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Čeká na vystavení výzvy</span>
                                    <div class="small text-muted mt-1">Trenér ještě neuzavřel měsíc.</div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-muted">Aktuálně tu nemáte žádnou evidovanou platbu. Jakmile trenér připraví výzvu, uvidíte ji zde i v sekci Platby.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white"><i class="fas fa-history me-2"></i>Historie tréninků</div>
    <div class="card-body p-0">
        <?php if (empty($sessions)): ?>
        <div class="text-center text-muted py-4">Zatím nemáte žádné tréninky.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                <tr>
                    <th>Datum</th>
                    <th>Sada</th>
                    <th>Místo</th>
                    <th>Stav</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($trainingVisibleRows as $s): ?>
                <tr>
                    <td><?= formatDateTime((string)$s['started_at']) ?></td>
                    <td><?= h((string)$s['set_name']) ?></td>
                    <td><?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></td>
                    <td>
                        <?php if (!empty($s['completed_at'])): ?>
                        <span class="badge bg-success">Dokončeno</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Naplánováno / probíhá</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye me-1"></i>Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (!empty($trainingCollapsedRows)): ?>
                <tbody id="athleteTrainingHistoryCollapse" class="collapse">
                <?php foreach ($trainingCollapsedRows as $s): ?>
                <tr>
                    <td><?= formatDateTime((string)$s['started_at']) ?></td>
                    <td><?= h((string)$s['set_name']) ?></td>
                    <td><?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></td>
                    <td>
                        <?php if (!empty($s['completed_at'])): ?>
                        <span class="badge bg-success">Dokončeno</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Naplánováno / probíhá</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye me-1"></i>Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($trainingCollapsedRows)): ?>
        <div class="border-top p-3 text-center bg-light">
            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteTrainingHistoryCollapse" aria-expanded="false" aria-controls="athleteTrainingHistoryCollapse">
                <i class="fas fa-chevron-down me-1"></i>
                Zobrazit starší tréninky (<?= count($trainingCollapsedRows) ?>)
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</div>

<?php if (!empty($onlineHistory)): ?>
<div class="card border-warning shadow-sm athlete-desktop-only mb-4">
    <div class="card-header bg-warning text-dark fw-bold"><i class="fas fa-laptop me-2"></i>Historie online tréninků</div>
    <div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead><tr><th>Číslo</th><th>Název</th><th>Odesláno</th><th>Stav</th><th></th></tr></thead><tbody>
    <?php foreach ($onlineHistoryVisibleRows as $onlineRow): ?><tr><td><strong>ONLINE #<?= str_pad((string)$onlineRow['sequence_number'], 3, '0', STR_PAD_LEFT) ?></strong></td><td><?= h($onlineRow['title']) ?></td><td><?= h(formatDateTime($onlineRow['sent_at'])) ?></td><td><span class="badge bg-<?= h(onlineTrainingAthleteStatusClass($onlineRow['status'])) ?>"><?= h(onlineTrainingAthleteStatusLabel($onlineRow['status'])) ?></span></td><td><a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/online_training.php?id=<?= (int)$onlineRow['id'] ?>">Detail</a></td></tr><?php endforeach; ?>
    </tbody><?php if ($onlineHistoryCollapsedRows): ?><tbody id="athleteOnlineTrainingHistoryCollapse" class="collapse"><?php foreach ($onlineHistoryCollapsedRows as $onlineRow): ?><tr><td><strong>ONLINE #<?= str_pad((string)$onlineRow['sequence_number'], 3, '0', STR_PAD_LEFT) ?></strong></td><td><?= h($onlineRow['title']) ?></td><td><?= h(formatDateTime($onlineRow['sent_at'])) ?></td><td><span class="badge bg-<?= h(onlineTrainingAthleteStatusClass($onlineRow['status'])) ?>"><?= h(onlineTrainingAthleteStatusLabel($onlineRow['status'])) ?></span></td><td><a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/online_training.php?id=<?= (int)$onlineRow['id'] ?>">Detail</a></td></tr><?php endforeach; ?></tbody><?php endif; ?></table></div>
    <?php if ($onlineHistoryCollapsedRows): ?><div class="border-top p-3 text-center bg-light"><button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteOnlineTrainingHistoryCollapse" aria-expanded="false"><i class="fas fa-chevron-down me-1"></i>Zobrazit starší online tréninky (<?= count($onlineHistoryCollapsedRows) ?>)</button></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="modal fade" id="supportContributionModal" tabindex="-1" aria-labelledby="supportContributionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="supportContributionModalLabel"><i class="fas fa-heart me-2 text-warning"></i>Dobrovolná podpora provozu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Jde jen o volitelnou podporu provozu aplikace. Aplikace zůstává zdarma a nic není potřeba platit.</p>
                <?php if ($supportBankAccountForQr === null): ?>
                <div class="alert alert-warning mb-3">Pro tento účet zatím není v administraci nastavené číslo účtu.</div>
                <?php else: ?>
                <div class="mb-3">
                    <label for="supportContributionAmount" class="form-label fw-semibold">Částka</label>
                    <input type="number" min="1" step="1" class="form-control form-control-lg" id="supportContributionAmount" placeholder="Např. 100">
                </div>
                <div class="border rounded-3 p-3 bg-light mb-3">
                    <img id="supportContributionQrImage" src="" alt="QR kód pro příspěvek" class="img-fluid border rounded p-2 bg-white d-none" style="max-width:220px;">
                    <div id="supportContributionQrEmpty" class="text-muted small">Zadejte částku a QR kód se zobrazí automaticky.</div>
                </div>
                <div class="small"><strong>Účet:</strong> <span id="supportContributionAccount"><?= h($supportBankAccount) ?></span></div>
                <div class="small"><strong>Odesílatel:</strong> <span id="supportContributionSender"><?= h($supportContributorName) ?></span></div>
                <div class="small"><strong>Poznámka:</strong> <span id="supportContributionNotePreview"><?= h($supportQrNote) ?></span></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer justify-content-between flex-wrap gap-2">
                <div class="small text-muted">Aplikace zůstává bezplatná. Příspěvek je pouze dobrovolná pomoc s provozem.</div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zavřít</button>
            </div>
        </div>
    </div>
</div>

<?php if ($showHealthQuestionnairePrompt): ?>
<div class="modal fade" id="healthQuestionnairePromptModal" tabindex="-1" aria-labelledby="healthQuestionnairePromptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title" id="healthQuestionnairePromptModalLabel">
                    <i class="fas fa-heart-pulse me-2 text-danger"></i>Zdravotní dotazník
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <?php if (!empty($healthQuestionnaireStatus['needs_refresh'])): ?>
                <p class="mb-2 fw-semibold">Byly přidány nové otázky. Prosím aktualizujte zdravotní dotazník.</p>
                <p class="text-muted mb-0 small">Vyplnění pomůže trenérovi bezpečně plánovat trénink podle aktuálního stavu.</p>
                <?php else: ?>
                <p class="mb-2 fw-semibold">Prosím vyplňte zdravotní dotazník.</p>
                <p class="text-muted mb-0 small">Je to důležité pro bezpečné vedení tréninku a přizpůsobení zátěže.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zavřít</button>
                <a href="<?= BASE_URL ?>/athlete_health_questionnaire.php" class="btn btn-warning fw-semibold">
                    <i class="fas fa-arrow-right me-1"></i>Přejít do dotazníku
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const supportBankAccount = <?= json_encode($supportBankAccountForQr, JSON_UNESCAPED_UNICODE) ?>;
    const supportContributorName = <?= json_encode($supportContributorName, JSON_UNESCAPED_UNICODE) ?>;
    const supportQrNote = <?= json_encode($supportQrNote, JSON_UNESCAPED_UNICODE) ?>;
    const amountInput = document.getElementById('supportContributionAmount');
    const qrImage = document.getElementById('supportContributionQrImage');
    const qrEmpty = document.getElementById('supportContributionQrEmpty');

    if (!amountInput || !qrImage || !qrEmpty || supportBankAccount === null) {
        return;
    }

    const buildQrUrl = (amount) => {
        const spd = [
            'SPD*1.0',
            'ACC:' + supportBankAccount,
            'CC:CZK',
            'AM:' + amount.toFixed(2),
            'MSG:' + supportQrNote,
        ].join('*');

        return 'https://quickchart.io/qr?size=220&text=' + encodeURIComponent(spd);
    };

    const updateQr = () => {
        const amount = parseFloat(String(amountInput.value || '').replace(',', '.'));
        if (!Number.isFinite(amount) || amount <= 0) {
            qrImage.classList.add('d-none');
            qrEmpty.classList.remove('d-none');
            qrImage.removeAttribute('src');
            return;
        }

        qrImage.src = buildQrUrl(amount);
        qrImage.classList.remove('d-none');
        qrEmpty.classList.add('d-none');
    };

    amountInput.addEventListener('input', updateQr);
    amountInput.addEventListener('change', updateQr);
})();
</script>

<script>
(function () {
    const noticeEl = document.getElementById('dashboardReplacementNotice');
    if (!noticeEl) {
        return;
    }

    // If server-side notice is already rendered, keep it and do not override.
    if (!noticeEl.classList.contains('d-none')) {
        return;
    }

    const noticeTextEl = document.getElementById('dashboardReplacementNoticeText');
    if (!noticeTextEl) {
        return;
    }

    const now = new Date();
    const localDate = now.toISOString().slice(0, 10);
    const localHour = String(now.getHours()).padStart(2, '0');
    const localMinute = String(now.getMinutes()).padStart(2, '0');
    const startsAt = `${localDate}T${localHour}:${localMinute}`;

    fetch('<?= BASE_URL ?>/api/athlete_calendar_makeup_hint.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE) ?>,
            starts_at: startsAt,
        }),
    })
    .then((response) => response.json())
    .then((payload) => {
        if (!payload || payload.success !== true) {
            return;
        }

        const count = Number(payload.required_replacement_count || 0);
        const hasRequired = Boolean(payload.has_required_replacement) && count > 0;
        if (!hasRequired) {
            return;
        }

        let text = count > 1
            ? `Máte ${count} zrušené termíny, které je potřeba nahradit.`
            : 'Máte zrušený termín, který je potřeba nahradit.';

        if (hasRequired && payload.required_replacement_deadline_at) {
            const deadline = new Date(String(payload.required_replacement_deadline_at).replace(' ', 'T'));
            if (!Number.isNaN(deadline.getTime())) {
                text += ` Nejzazší termín rezervace je ${deadline.toLocaleString('cs-CZ')}.`;
            }
        }

        noticeTextEl.textContent = text;
        noticeEl.classList.remove('d-none');
    })
    .catch(() => {
        // Silent fallback; dashboard remains unchanged if API call fails.
    });
})();
</script>

<?php if ($showHealthQuestionnairePrompt): ?>
<script>
(function () {
    const modalEl = document.getElementById('healthQuestionnairePromptModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return;
    }

    const storageKeyBase = 'healthQuestionnairePrompt:' + <?= (int)$athleteId ?>;
    const hiddenUntilKey = storageKeyBase + ':hiddenUntil';
    const signatureKey = storageKeyBase + ':signature';
    const currentSignature = <?= json_encode(
        (($healthQuestionnaireStatus['state'] ?? 'missing') . '|' .
        (!empty($healthQuestionnaireStatus['needs_refresh']) ? '1' : '0') . '|' .
        (string)($healthQuestionnaireStatus['questions_updated_at'] ?? '') . '|' .
        (string)($healthQuestionnaireStatus['filled_at'] ?? '')),
        JSON_UNESCAPED_UNICODE
    ) ?>;
    const oneDayMs = 24 * 60 * 60 * 1000;

    const canUseStorage = (() => {
        try {
            return typeof window.localStorage !== 'undefined';
        } catch (e) {
            return false;
        }
    })();

    if (canUseStorage) {
        try {
            const savedSignature = String(localStorage.getItem(signatureKey) || '');
            if (savedSignature !== currentSignature) {
                localStorage.removeItem(hiddenUntilKey);
                localStorage.setItem(signatureKey, currentSignature);
            }

            const hiddenUntil = Number(localStorage.getItem(hiddenUntilKey) || '0');
            if (Number.isFinite(hiddenUntil) && hiddenUntil > Date.now()) {
                return;
            }
        } catch (e) {
            // Storage may be blocked; fallback is immediate modal display.
        }
    }

    const modal = new bootstrap.Modal(modalEl, {
        backdrop: true,
        keyboard: true,
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        if (!canUseStorage) {
            return;
        }

        try {
            localStorage.setItem(hiddenUntilKey, String(Date.now() + oneDayMs));
            localStorage.setItem(signatureKey, currentSignature);
        } catch (e) {
            // Ignore storage write errors.
        }
    });

    modal.show();
})();
</script>
<?php endif; ?>

<?php renderAthleteFooter();
