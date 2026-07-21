<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function instaOutputAvatarFallbackSvg(string $label): void
{
    $initial = instaGetProfileInitial($label);
    $safeInitial = htmlspecialchars($initial, ENT_QUOTES | ENT_XML1, 'UTF-8');

    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');

    echo '<svg xmlns="http://www.w3.org/2000/svg" width="156" height="156" viewBox="0 0 156 156" role="img" aria-label="Avatar">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#0ea5e9"/><stop offset="100%" stop-color="#22c55e"/></linearGradient></defs>'
        . '<circle cx="78" cy="78" r="75" fill="url(#g)" />'
        . '<text x="50%" y="53%" text-anchor="middle" dominant-baseline="middle" fill="#f8fafc" font-size="64" font-family="Arial, sans-serif" font-weight="700">'
        . $safeInitial
        . '</text>'
        . '</svg>';
}

function instaFetchBinaryImage(string $url, int $timeoutSeconds = 5): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: Mozilla/5.0 TrainerAppInsta/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];

    if (!is_string($body) || $body === '') {
        return [
            'ok' => false,
            'body' => null,
            'content_type' => null,
        ];
    }

    $contentType = null;
    foreach ($headers as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') === 0) {
            $contentType = trim((string)substr($headerLine, strlen('Content-Type:')));
        }
    }

    $normalizedType = strtolower((string)$contentType);
    if ($normalizedType === '' || strpos($normalizedType, 'image/') !== 0) {
        return [
            'ok' => false,
            'body' => null,
            'content_type' => null,
        ];
    }

    return [
        'ok' => true,
        'body' => $body,
        'content_type' => $contentType,
    ];
}

$profileId = (string)($_GET['profile'] ?? '');
$profiles = instaGetProfiles();

if (!isset($profiles[$profileId])) {
    instaOutputAvatarFallbackSvg('');
    exit;
}

$profile = $profiles[$profileId];
$label = (string)($profile['label'] ?? '');
$imageUrl = instaResolveProfileImageUrl($profile);

if ($imageUrl === null || $imageUrl === '') {
    instaOutputAvatarFallbackSvg($label);
    exit;
}

$fetched = instaFetchBinaryImage($imageUrl, 5);
if (empty($fetched['ok'])) {
    instaOutputAvatarFallbackSvg($label);
    exit;
}

header('Content-Type: ' . (string)$fetched['content_type']);
header('Cache-Control: public, max-age=1800');
echo (string)$fetched['body'];
