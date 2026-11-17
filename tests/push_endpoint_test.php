<?php

require_once __DIR__ . '/../includes/push_endpoint.php';

$cases = [
    ['https://fcm.googleapis.com/fcm/send/example', true],
    ['https://updates.push.services.mozilla.com/wpush/v2/example', true],
    ['https://web.push.apple.com/example', true],
    ['https://a.b.push.apple.com:443/example?token=x', true],
    ['https://wns2-ln2p.notify.windows.com/example', true],
    ['https://a.b.notify.windows.com:443/example', true],
    ['HTTPS://FCM.GOOGLEAPIS.COM/example', true],
    ['http://fcm.googleapis.com/example', false],
    ['https://fcm.googleapis.com:444/example', false],
    ['https://fcm.googleapis.com.evil.example/example', false],
    ['https://fcm.googleapis.com@evil.example/example', false],
    ['https://user@fcm.googleapis.com/example', false],
    ['https://web.push.apple.com.evil.example/example', false],
    ['https://push.apple.com/example', false],
    ['https://-bad.push.apple.com/example', false],
    ['https://notify.windows.com/example', false],
    ['https://wns2.notify.windows.com.evil.example/example', false],
    ['https://-bad.notify.windows.com/example', false],
    ['https://wns2.notify.windows.com:8443/example', false],
    ['https://127.0.0.1/example', false],
    ['https://fcm.googleapis.com/example#fragment', false],
    ["https://fcm.googleapis.com/example\r\n", false],
    ['https://fcm.googleapis.com\\@evil.example/example', false],
    [null, false],
];

foreach ($cases as $index => [$endpoint, $expected]) {
    if (pushEndpointIsAllowed($endpoint) !== $expected) {
        fwrite(STDERR, 'Push endpoint validation case ' . ($index + 1) . " failed.\n");
        exit(1);
    }
}

echo "Push endpoint validation passed.\n";
