<?php
/** @var array|null $activeOnlineSubscription */
$subscriptionDays = $activeOnlineSubscription ? (int)$activeOnlineSubscription['days_remaining'] : null;
$subscriptionAlertClass = $subscriptionDays === null ? 'secondary' : ($subscriptionDays <= 15 ? 'warning' : 'success');
?>
<div class="alert alert-<?= $subscriptionAlertClass ?> mb-3">
    <strong>Online tréninky:</strong>
    <?php if ($activeOnlineSubscription): ?>
        Předplatné je platné do <?= h(formatDate($activeOnlineSubscription['ends_on'])) ?>.
        <?php if ($subscriptionDays <= 15): ?><strong><?= h(onlineTrainingSubscriptionDaysLabel($subscriptionDays)) ?>.</strong><?php endif; ?>
    <?php else: ?>
        Nemáte platné předplatné.
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/athlete_payments.php" class="ms-2">Platby</a>
</div>
