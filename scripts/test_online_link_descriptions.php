<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../includes/online_training.php';

$base = ['status' => 'active', 'starts_on' => '2026-10-28', 'price' => '0.00', 'payment_status' => null];
foreach ([
    [$base, 'Zdarma'],
    [array_merge($base, ['price' => '100.00', 'payment_status' => 'paid']), 'Uhrazeno'],
    [array_merge($base, ['price' => '100.00', 'payment_status' => 'pending']), 'Čeká na úhradu'],
    [array_merge($base, ['status' => 'cancelled']), 'Ukončeno'],
    [array_merge($base, ['starts_on' => null]), 'Historie'],
] as [$subscription, $expected]) {
    if (onlineTrainingSubscriptionPaymentLabel($subscription) !== $expected) throw new RuntimeException('Nesprávný stav úhrady.');
}
onlineTrainingValidateLink('https://example.com/video', "První řádek\nDruhý řádek");
try {
    onlineTrainingValidateLink('javascript:alert(1)', 'Neplatný');
    throw new LogicException('Neplatný odkaz prošel kontrolou.');
} catch (RuntimeException $e) {
    // Expected.
}
echo "PASS: payment labels and link validation\n";
