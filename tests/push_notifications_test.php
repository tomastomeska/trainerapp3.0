<?php

require_once __DIR__ . '/../includes/push_notifications.php';
require_once __DIR__ . '/../dependencies/webpush/vendor/autoload.php';

function expectPush(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$valid = ['athlete', 42, 'TrainerApp', 'Nova zprava.', '/zpravy.php', 'new_message', 'messages', 'message:10'];
expectPush(pushNotificationInputIsValid(...$valid), 'Valid notification was rejected.');
foreach ([
    [0, 'admin'],
    [1, 0],
    [2, str_repeat('x', 101)],
    [3, "a\nb"],
    [4, '//evil.example/path'],
    [4, '/%2f%2fevil.example'],
    [4, "/path\\@evil.example"],
    [4, '/path%0dheader'],
    [5, 'New Message'],
    [6, 'unrecognized'],
    [7, ''],
] as [$index, $value]) {
    $invalid = $valid;
    $invalid[$index] = $value;
    expectPush(!pushNotificationInputIsValid(...$invalid), 'Invalid notification was accepted.');
}
$oversized = $valid;
$oversized[2] = str_repeat("\u{2028}", 100);
$oversized[3] = str_repeat("\u{2028}", 240);
$oversized[4] = '/' . str_repeat("\u{2028}", 340);
expectPush(!pushNotificationInputIsValid(...$oversized), 'Oversized encoded payload was accepted.');

$vapid = \Minishlink\WebPush\VAPID::createVapidKeys();
$device = \Minishlink\WebPush\VAPID::createVapidKeys();
$history = [];
$mock = new \GuzzleHttp\Handler\MockHandler([
    new \GuzzleHttp\Psr7\Response(201),
    new \GuzzleHttp\Psr7\Response(410),
    new \GuzzleHttp\Psr7\Response(201),
]);
$stack = \GuzzleHttp\HandlerStack::create($mock);
$stack->push(\GuzzleHttp\Middleware::history($history));
$sender = new \Minishlink\WebPush\WebPush(
    ['VAPID' => [
        'subject' => 'https://example.com/',
        'publicKey' => $vapid['publicKey'],
        'privateKey' => $vapid['privateKey'],
    ]],
    [],
    5,
    ['handler' => $stack, 'allow_redirects' => false]
);
$delivery = [
    'coach_id' => null,
    'athlete_id' => 42,
    'title' => 'TrainerApp',
    'body' => 'Nova zprava.',
    'target_url' => '/zpravy.php',
    'notification_type' => 'new_message',
    'category' => 'messages',
    'log_id' => 123,
    'endpoint' => 'https://fcm.googleapis.com/fcm/send/example',
    'p256dh_key' => $device['publicKey'],
    'auth_key' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
];
$withCount = $delivery;
$withCount['unread_count'] = 7;
expectPush(json_decode(pushDeliveryPayload($withCount), true, 16, JSON_THROW_ON_ERROR)['unread_count'] === 7, 'Unread badge count missing from payload.');
$withCount['unread_count'] = -1;
expectPush(!isset(json_decode(pushDeliveryPayload($withCount), true, 16, JSON_THROW_ON_ERROR)['unread_count']), 'Invalid badge count was accepted.');
$report = pushSendDelivery($sender, $delivery);
expectPush($report->isSuccess() && $report->getResponse()->getStatusCode() === 201, 'Mock delivery failed.');
expectPush(pushClassifyReport($report) === ['sent', 201, null], 'Acceptance classification failed.');
expectPush($history[0]['request']->getHeaderLine('Content-Encoding') === 'aes128gcm', 'Wrong content encoding.');
expectPush($history[0]['options']['allow_redirects'] === false, 'Redirects must be disabled.');
expectPush((string)$history[0]['request']->getBody() !== pushDeliveryPayload($delivery), 'Payload was not encrypted.');
$report = pushSendDelivery($sender, $delivery);
expectPush($report->isSubscriptionExpired(), 'Expired subscription was not recognized.');
expectPush(pushClassifyReport($report) === ['expired', 410, 'endpoint_expired'], 'Expiry classification failed.');

$delivery['endpoint'] = 'https://wns2-ln2p.notify.windows.com/example';
$report = pushSendDelivery($sender, $delivery);
expectPush(pushClassifyReport($report) === ['sent', 201, null], 'Mock WNS delivery failed.');

$request = new \GuzzleHttp\Psr7\Request('POST', 'https://fcm.googleapis.com/example');
foreach ([[301, 'failed'], [400, 'failed'], [429, 'retry'], [503, 'retry']] as [$status, $expected]) {
    $report = new \Minishlink\WebPush\MessageSentReport($request, new \GuzzleHttp\Psr7\Response($status));
    expectPush(pushClassifyReport($report)[0] === $expected, 'HTTP response classification failed.');
}
$report = new \Minishlink\WebPush\MessageSentReport($request, null, false);
expectPush(pushClassifyReport($report)[0] === 'retry', 'Network failure classification failed.');

$delivery['endpoint'] = 'https://127.0.0.1/example';
try {
    pushSendDelivery($sender, $delivery);
    expectPush(false, 'Disallowed endpoint was accepted.');
} catch (InvalidArgumentException $e) {
    expectPush(count($history) === 3, 'Disallowed endpoint reached the HTTP handler.');
}

echo "Push notification validation and mocked delivery passed.\n";
