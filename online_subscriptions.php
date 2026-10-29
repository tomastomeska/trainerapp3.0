<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_training.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$subscriptions = onlineTrainingCoachActiveSubscriptions(getDB(), (int)getCurrentCoachId());
$upcomingSubscriptions = [];
$unpaidSubscriptions = [];
foreach (onlineTrainingCoachUpcomingAndUnpaidSubscriptions(getDB(), (int)getCurrentCoachId()) as $subscription) {
    if (in_array($subscription['payment_state'], ['paid', 'free'], true)) {
        $upcomingSubscriptions[] = $subscription;
    } else {
        $unpaidSubscriptions[] = $subscription;
    }
}
$expiringCount = count(array_filter($subscriptions, static fn(array $subscription): bool => (int)$subscription['days_remaining'] <= 15));
renderHeader('Online předplatná', false, true);
?>
<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-4">
    <h2 class="mb-0"><i class="fas fa-calendar-check me-2 text-warning"></i>Online předplatná</h2>
    <a class="btn btn-outline-dark btn-sm" href="<?= BASE_URL ?>/online_training.php">Online tréninky</a>
</div>
<p>Přehled platných, budoucích a neuhrazených předplatných. Předplatné vytvoříte nebo ukončíte v detailu sportovce. Tréninky lze posílat pouze během platného a uhrazeného předplatného nebo během platného bezplatného předplatného.</p>
<h3 class="h5 mt-4">Aktuálně platná předplatná</h3>
<?php if ($expiringCount > 0): ?>
<div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>Počet předplatných končících do 15 dní: <?= $expiringCount ?>. Jednotlivé termíny jsou označené níže.</div>
<?php endif; ?>
<?php if (!$subscriptions): ?>
<div class="alert alert-secondary">Momentálně nemáte žádné platné online předplatné.</div>
<?php else: ?>
<div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Sportovec</th><th>Platnost od</th><th>Platnost do</th><th>Do konce</th><th>Cena</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($subscriptions as $subscription): $days = (int)$subscription['days_remaining']; ?>
    <tr>
        <td><strong><?= h($subscription['first_name'] . ' ' . $subscription['last_name']) ?></strong></td>
        <td><?= h(formatDate($subscription['starts_on'])) ?></td>
        <td><?= h(formatDate($subscription['ends_on'])) ?></td>
        <td><span class="badge <?= $days <= 15 ? 'bg-warning text-dark' : 'bg-success' ?>"><?= h(onlineTrainingSubscriptionDaysLabel($days)) ?></span></td>
        <td><?= number_format((float)$subscription['price'], 2, ',', ' ') ?> Kč</td>
        <td><a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$subscription['athlete_id'] ?>">Spravovat u sportovce</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<h3 class="h5 mt-4">Budoucí uhrazená a bezplatná předplatná</h3>
<p class="small text-muted">Tréninky bude možné posílat až od začátku platnosti.</p>
<?php if (!$upcomingSubscriptions): ?>
<div class="alert alert-secondary">Žádná uhrazená ani bezplatná předplatná nezačínají později.</div>
<?php else: ?>
<div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Sportovec</th><th>Platnost od</th><th>Platnost do</th><th>Úhrada</th><th>Cena</th><th></th></tr></thead>
    <tbody><?php foreach ($upcomingSubscriptions as $subscription): ?>
    <tr>
        <td><strong><?= h($subscription['first_name'] . ' ' . $subscription['last_name']) ?></strong></td>
        <td><?= h(formatDate($subscription['starts_on'])) ?></td><td><?= h(formatDate($subscription['ends_on'])) ?></td>
        <td><span class="badge bg-info text-dark"><?= $subscription['payment_state'] === 'free' ? 'Zdarma' : 'Uhrazeno' ?> · čeká na začátek</span></td>
        <td><?= number_format((float)$subscription['price'], 2, ',', ' ') ?> Kč</td>
        <td><a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$subscription['athlete_id'] ?>">Spravovat u sportovce</a></td>
    </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<h3 class="h5 mt-4">Neuhrazená předplatná</h3>
<p class="small text-muted">Úhrada se označuje u celé platební výzvy včetně případných dalších položek.</p>
<?php if (!$unpaidSubscriptions): ?>
<div class="alert alert-secondary">Žádná předplatná nečekají na úhradu.</div>
<?php else: ?>
<div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Sportovec</th><th>Platnost od</th><th>Platnost do</th><th>Stav</th><th>Cena předplatného</th><th></th></tr></thead>
    <tbody><?php foreach ($unpaidSubscriptions as $subscription): ?>
    <tr>
        <td><strong><?= h($subscription['first_name'] . ' ' . $subscription['last_name']) ?></strong></td>
        <td><?= h(formatDate($subscription['starts_on'])) ?></td><td><?= h(formatDate($subscription['ends_on'])) ?></td>
        <td><span class="badge bg-warning text-dark"><?= $subscription['payment_state'] === 'missing' ? 'Výzva nenalezena' : 'Čeká na úhradu' ?></span>
            <?php if ($subscription['period_expired']): ?><div class="small text-danger">Období platnosti již skončilo.</div>
            <?php elseif ($subscription['starts_in_future']): ?><div class="small text-muted">Platnost ještě nezačala.</div><?php endif; ?>
        </td>
        <td><?= number_format((float)$subscription['price'], 2, ',', ' ') ?> Kč</td>
        <td><a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$subscription['athlete_id'] ?>">Sportovec</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/payments.php<?= $subscription['billing_month'] ? '?month=' . urlencode(substr((string)$subscription['billing_month'], 0, 7)) : '' ?>">Platby</a></td>
    </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php renderFooter(); ?>
