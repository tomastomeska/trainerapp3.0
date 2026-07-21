<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$profileId = (string)($_GET['profile'] ?? '');
$profiles = instaGetProfiles();

if (!isset($profiles[$profileId])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Neznamy profil.';
    exit;
}

instaRecordClick($profileId);
header('Location: ' . (string)$profiles[$profileId]['url'], true, 302);
exit;
