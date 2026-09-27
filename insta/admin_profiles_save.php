<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	http_response_code(405);
	echo json_encode(['success' => false, 'error' => 'Nepovolená metoda.'], JSON_UNESCAPED_UNICODE);
	exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($data)) {
	$data = [];
}

$pin = trim((string)($data['pin'] ?? ''));
if ($pin === '' || !hash_equals(instaGetAdminPin(), $pin)) {
	http_response_code(403);
	echo json_encode(['success' => false, 'error' => 'Neplatný PIN.'], JSON_UNESCAPED_UNICODE);
	exit;
}

$inputProfiles = $data['profiles'] ?? null;
if (!is_array($inputProfiles) || count($inputProfiles) < 1 || count($inputProfiles) > 40) {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Přidejte alespoň jeden účet (maximálně 40).'], JSON_UNESCAPED_UNICODE);
	exit;
}

$existing = instaGetProfiles();
$profiles = [];
foreach ($inputProfiles as $profile) {
	if (!is_array($profile)) {
		continue;
	}
	$id = trim((string)($profile['id'] ?? ''));
	$label = trim((string)($profile['label'] ?? ''));
	$description = trim((string)($profile['description'] ?? ''));
	$url = trim((string)($profile['url'] ?? ''));
	if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $id) || isset($profiles[$id])) {
		http_response_code(400);
		echo json_encode(['success' => false, 'error' => 'Identifikátor účtu není platný.'], JSON_UNESCAPED_UNICODE);
		exit;
	}
	if ($label === '' || mb_strlen($label, 'UTF-8') > 80) {
		http_response_code(400);
		echo json_encode(['success' => false, 'error' => 'Název účtu je povinný a může mít nejvýše 80 znaků.'], JSON_UNESCAPED_UNICODE);
		exit;
	}
	if (mb_strlen($description, 'UTF-8') > 240) {
		http_response_code(400);
		echo json_encode(['success' => false, 'error' => 'Popis může mít nejvýše 240 znaků.'], JSON_UNESCAPED_UNICODE);
		exit;
	}
	if (!instaIsValidInstagramProfileUrl($url)) {
		http_response_code(400);
		echo json_encode(['success' => false, 'error' => 'Zadejte platný odkaz na profil na instagram.com.'], JSON_UNESCAPED_UNICODE);
		exit;
	}
	$profiles[$id] = [
		'label' => $label,
		'description' => $description,
		'url' => $url,
		'profile_image' => '',
		'qr_file' => (string)($existing[$id]['qr_file'] ?? ''),
	];
}

if (count($profiles) !== count($inputProfiles) || !instaSaveProfiles($profiles)) {
	http_response_code(500);
	echo json_encode(['success' => false, 'error' => 'Účty se nepodařilo uložit. Zkontrolujte oprávnění složky uploads/insta.'], JSON_UNESCAPED_UNICODE);
	exit;
}

echo json_encode(['success' => true, 'message' => 'Instagram účty byly uloženy.'], JSON_UNESCAPED_UNICODE);