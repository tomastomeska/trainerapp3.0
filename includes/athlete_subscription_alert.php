<?php
/** @var array|null $activeOnlineSubscription */
/** @var array|null $unpaidOnlineSubscription Nezaplacené předplatné online tréninků (nastavuje athlete_dashboard.php). */
/** @var array|null $unpaidOnlineSubscriptionQr QR platba pro nezaplacené předplatné. */
/** @var bool $athletePaymentsEnabled */
$unpaidOnlineSubscription = $unpaidOnlineSubscription ?? null;
$unpaidOnlineSubscriptionQr = $unpaidOnlineSubscriptionQr ?? null;
$subscriptionAlertPaymentsEnabled = $athletePaymentsEnabled ?? true;
$subscriptionDays = $activeOnlineSubscription ? (int)$activeOnlineSubscription['days_remaining'] : null;
$subscriptionAlertClass = $subscriptionDays === null ? 'secondary' : ($subscriptionDays <= 15 ? 'warning' : 'success');
$subscriptionPaymentAmount = (float)($unpaidOnlineSubscription['payment_amount'] ?? $unpaidOnlineSubscription['price'] ?? 0);
?>
<?php if ($unpaidOnlineSubscription): ?>
<div class="alert alert-warning mb-3">
    <div class="fw-semibold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>Online tréninky – předplatné čeká na úhradu</div>
    <div>
        Předplatné na období <?= h(formatDate((string)$unpaidOnlineSubscription['starts_on'])) ?> – <?= h(formatDate((string)$unpaidOnlineSubscription['ends_on'])) ?>
        zatím není uhrazené. Částka k úhradě: <strong><?= number_format($subscriptionPaymentAmount, 2, ',', ' ') ?> Kč</strong>.
        <?php if (abs($subscriptionPaymentAmount - (float)$unpaidOnlineSubscription['price']) > 0.009): ?>
            (Cena předplatného: <?= number_format((float)$unpaidOnlineSubscription['price'], 2, ',', ' ') ?> Kč.)
        <?php endif; ?>
    </div>
    <?php if ($unpaidOnlineSubscriptionQr): ?>
    <div class="mt-2 d-flex flex-wrap align-items-center gap-3">
        <img src="<?= h($unpaidOnlineSubscriptionQr['qr_url']) ?>" alt="QR platba předplatného" class="border rounded bg-white p-1" style="max-width:160px;">
        <div class="small">
            <div><strong>Účet:</strong> <?= h($unpaidOnlineSubscriptionQr['account']) ?></div>
            <div><strong>Částka k úhradě:</strong> <?= number_format($subscriptionPaymentAmount, 2, ',', ' ') ?> Kč</div>
            <div><strong>Popis pro příjemce (předvyplněný v QR):</strong> <?= h($unpaidOnlineSubscriptionQr['note']) ?></div>
            <div><strong>Popis pro odesílatele:</strong> <?= h($unpaidOnlineSubscriptionQr['note']) ?></div>
        </div>
    </div>
    <?php if ($subscriptionAlertPaymentsEnabled): ?>
    <div class="mt-2 small">Popis pro odesílatele vyplňte v bankovní aplikaci ručně. <a href="<?= BASE_URL ?>/athlete_payments.php" class="alert-link">Všechny platby</a></div>
    <?php else: ?>
    <div class="mt-2 small">Popis pro odesílatele vyplňte v bankovní aplikaci ručně.</div>
    <?php endif; ?>
    <?php else: ?>
    <?php if ($subscriptionAlertPaymentsEnabled): ?>
    <div class="mt-1">Trenér nemá nastavený platný účet pro QR platby. <a href="<?= BASE_URL ?>/athlete_payments.php" class="alert-link">Otevřít platby</a>.</div>
    <?php else: ?>
    <div class="mt-1">Trenér nemá nastavený platný účet pro QR platby. Obraťte se na něj pro platební údaje.</div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="alert alert-<?= $subscriptionAlertClass ?> mb-3 athlete-subscription-status <?= $activeOnlineSubscription ? 'athlete-subscription-status--active' : 'athlete-subscription-status--inactive' ?>">
    <strong>Online tréninky:</strong>
    <?php if ($activeOnlineSubscription): ?>
        Předplatné je platné do <?= h(formatDate($activeOnlineSubscription['ends_on'])) ?>.
        <?php if ($subscriptionDays <= 15): ?><strong><?= h(onlineTrainingSubscriptionDaysLabel($subscriptionDays)) ?>.</strong><?php endif; ?>
    <?php else: ?>
        Nemáte platné předplatné.
    <?php endif; ?>
    <?php if ($subscriptionAlertPaymentsEnabled): ?><a href="<?= BASE_URL ?>/athlete_payments.php" class="ms-2">Platby</a><?php endif; ?>
</div>
