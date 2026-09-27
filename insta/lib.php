<?php
declare(strict_types=1);

function instaGetProfiles(): array
{
	$defaults = [
		'profile_1' => [
			'label' => 'Denisa',
			'description' => '',
			'url' => 'https://www.instagram.com/deni_ska1990',
			'profile_image' => 'https://unavatar.io/instagram/deni_ska1990',
			'qr_file' => 'WhatsApp Image 2026-07-21 at 14.37.20.jpeg',
		],
		'profile_2' => [
			'label' => 'Tomáš',
			'description' => '',
			'url' => 'https://www.instagram.com/tomastomeska/',
			'profile_image' => 'https://unavatar.io/instagram/tomastomeska',
			'qr_file' => 'WhatsApp Image 2026-07-21 at 14.37.20 (1).jpeg',
		],
	];

	$raw = @file_get_contents(instaGetProfilesPath());
	$saved = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
	if (!is_array($saved)) {
		return $defaults;
	}

	$profiles = [];
	foreach ($saved as $profileId => $profile) {
		if (!is_string($profileId) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $profileId) || !is_array($profile)) {
			continue;
		}
		$label = trim((string)($profile['label'] ?? ''));
		$url = trim((string)($profile['url'] ?? ''));
		if ($label === '' || !instaIsValidInstagramProfileUrl($url)) {
			continue;
		}
		$profiles[$profileId] = [
			'label' => $label,
			'description' => trim((string)($profile['description'] ?? '')),
			'url' => $url,
			'profile_image' => (string)($profile['profile_image'] ?? ($defaults[$profileId]['profile_image'] ?? '')),
			'qr_file' => (string)($profile['qr_file'] ?? ($defaults[$profileId]['qr_file'] ?? '')),
		];
	}

	return $profiles !== [] ? $profiles : $defaults;
}

function instaGetProfilesPath(): string
{
	return dirname(__DIR__) . '/uploads/insta/profiles.json';
}

function instaSaveProfiles(array $profiles): bool
{
	$path = instaGetProfilesPath();
	$directory = dirname($path);
	if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
		return false;
	}

	$handle = @fopen($path, 'c+');
	if (!$handle || !flock($handle, LOCK_EX)) {
		if (is_resource($handle)) {
			fclose($handle);
		}
		return false;
	}

	$encoded = json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$written = is_string($encoded) && ftruncate($handle, 0) && rewind($handle) && fwrite($handle, $encoded) !== false && fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);
	return $written;
}

function instaIsValidInstagramProfileUrl(string $url): bool
{
	$parts = parse_url($url);
	if (!is_array($parts)) {
		return false;
	}
	$host = strtolower((string)($parts['host'] ?? ''));
	$username = instaGetInstagramUsernameFromUrl($url);
	return strtolower((string)($parts['scheme'] ?? '')) === 'https'
		&& in_array($host, ['instagram.com', 'www.instagram.com'], true)
		&& !isset($parts['user'])
		&& !isset($parts['pass'])
		&& !isset($parts['port'])
		&& $username !== '';
}

function instaBuildUnavatarUrl(string $username): string
{
	return 'https://unavatar.io/instagram/' . rawurlencode($username);
}

function instaGetInstagramUsernameFromUrl(string $url): string
{
	$path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
	$path = trim($path, '/');
	if ($path === '') {
		return '';
	}

	$parts = explode('/', $path);
	$username = trim((string)($parts[0] ?? ''));
	if ($username === '' || !preg_match('/^[a-zA-Z0-9._]+$/', $username)) {
		return '';
	}

	return $username;
}

function instaFetchUrl(string $url, int $timeoutSeconds = 3): ?string
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

	$raw = @file_get_contents($url, false, $context);
	if (!is_string($raw) || $raw === '') {
		return null;
	}

	return $raw;
}

function instaExtractOgImageFromHtml(string $html): ?string
{
	if ($html === '') {
		return null;
	}

	if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
		return html_entity_decode(trim((string)$matches[1]), ENT_QUOTES, 'UTF-8');
	}

	if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $matches)) {
		return html_entity_decode(trim((string)$matches[1]), ENT_QUOTES, 'UTF-8');
	}

	return null;
}

function instaIsLikelyWebUrl(string $url): bool
{
	if (!preg_match('#^https?://#i', $url)) {
		return false;
	}

	$host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
	if ($host === '') {
		return false;
	}

	return true;
}

function instaResolveProfileImageUrl(array $profile): ?string
{
	$url = trim((string)($profile['url'] ?? ''));
	if ($url === '') {
		return null;
	}

	$username = instaGetInstagramUsernameFromUrl($url);
	if ($username === '') {
		$configuredImage = trim((string)($profile['profile_image'] ?? ''));
		if ($configuredImage !== '' && instaIsLikelyWebUrl($configuredImage)) {
			return $configuredImage;
		}

		return null;
	}

	$oembedUrl = 'https://www.instagram.com/oembed/?url=' . rawurlencode('https://www.instagram.com/' . $username . '/');
	$oembedRaw = instaFetchUrl($oembedUrl, 3);
	if (is_string($oembedRaw)) {
		$oembed = json_decode($oembedRaw, true);
		$thumb = trim((string)($oembed['thumbnail_url'] ?? ''));
		if ($thumb !== '' && instaIsLikelyWebUrl($thumb)) {
			return $thumb;
		}
	}

	$profileHtml = instaFetchUrl('https://www.instagram.com/' . rawurlencode($username) . '/', 3);
	if (is_string($profileHtml)) {
		$ogImage = trim((string)(instaExtractOgImageFromHtml($profileHtml) ?? ''));
		if ($ogImage !== '' && instaIsLikelyWebUrl($ogImage)) {
			return $ogImage;
		}
	}

	$configuredImage = trim((string)($profile['profile_image'] ?? ''));
	if ($configuredImage !== '' && instaIsLikelyWebUrl($configuredImage)) {
		return $configuredImage;
	}

	return instaBuildUnavatarUrl($username);
}

function instaGetProfileInitial(string $label): string
{
	$label = trim($label);
	if ($label === '') {
		return '?';
	}

	$first = mb_substr($label, 0, 1, 'UTF-8');
	return mb_strtoupper($first, 'UTF-8');
}

function instaGetAdminPin(): string
{
	$configuredPin = getenv('INSTAGRAM_ADMIN_PIN');
	return is_string($configuredPin) && trim($configuredPin) !== ''
		? trim($configuredPin)
		: '170683';
}

function instaGetStatsPath(): string
{
	return dirname(__DIR__) . '/uploads/insta/stats.json';
}

function instaGetDefaultStats(): array
{
	$clicks = [];
	foreach (instaGetProfiles() as $profileId => $_profileConfig) {
		$clicks[$profileId] = 0;
	}

	return [
		'visits_total' => 0,
		'clicks' => $clicks,
		'updated_at' => gmdate('c'),
	];
}

function instaReadStats(): array
{
	$path = instaGetStatsPath();
	if (!is_file($path)) {
		return instaGetDefaultStats();
	}

	$raw = @file_get_contents($path);
	if (!is_string($raw) || $raw === '') {
		return instaGetDefaultStats();
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return instaGetDefaultStats();
	}

	$defaults = instaGetDefaultStats();
	$stats = array_merge($defaults, $decoded);
	if (!isset($stats['clicks']) || !is_array($stats['clicks'])) {
		$stats['clicks'] = $defaults['clicks'];
	}

	foreach ($defaults['clicks'] as $profileId => $defaultValue) {
		if (!array_key_exists($profileId, $stats['clicks'])) {
			$stats['clicks'][$profileId] = $defaultValue;
		}
		$stats['clicks'][$profileId] = max(0, (int)$stats['clicks'][$profileId]);
	}

	$stats['visits_total'] = max(0, (int)($stats['visits_total'] ?? 0));
	$stats['updated_at'] = (string)($stats['updated_at'] ?? gmdate('c'));

	return $stats;
}

function instaMutateStats(callable $mutator): array
{
	$path = instaGetStatsPath();
	$dir = dirname($path);
	if (!is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}

	$handle = @fopen($path, 'c+');
	if (!$handle) {
		return instaReadStats();
	}

	$locked = @flock($handle, LOCK_EX);
	if (!$locked) {
		fclose($handle);
		return instaReadStats();
	}

	rewind($handle);
	$existingRaw = stream_get_contents($handle);
	$existing = is_string($existingRaw) && $existingRaw !== '' ? json_decode($existingRaw, true) : null;
	$stats = is_array($existing) ? array_merge(instaGetDefaultStats(), $existing) : instaGetDefaultStats();

	$mutated = $mutator($stats);
	if (is_array($mutated)) {
		$stats = $mutated;
	}

	$defaults = instaGetDefaultStats();
	$stats = array_merge($defaults, $stats);
	if (!isset($stats['clicks']) || !is_array($stats['clicks'])) {
		$stats['clicks'] = $defaults['clicks'];
	}

	foreach ($defaults['clicks'] as $profileId => $defaultValue) {
		$stats['clicks'][$profileId] = max(0, (int)($stats['clicks'][$profileId] ?? $defaultValue));
	}

	$stats['visits_total'] = max(0, (int)($stats['visits_total'] ?? 0));
	$stats['updated_at'] = gmdate('c');

	$encoded = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if (is_string($encoded)) {
		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, $encoded);
		fflush($handle);
	}

	flock($handle, LOCK_UN);
	fclose($handle);

	return $stats;
}

function instaRecordVisit(): void
{
	instaMutateStats(static function (array $stats): array {
		$stats['visits_total'] = (int)($stats['visits_total'] ?? 0) + 1;
		return $stats;
	});
}

function instaRecordClick(string $profileId): void
{
	instaMutateStats(static function (array $stats) use ($profileId): array {
		if (!isset($stats['clicks']) || !is_array($stats['clicks'])) {
			$stats['clicks'] = [];
		}
		$stats['clicks'][$profileId] = (int)($stats['clicks'][$profileId] ?? 0) + 1;
		return $stats;
	});
}

function instaResetStats(): array
{
	return instaMutateStats(static function (array $stats): array {
		$defaults = instaGetDefaultStats();
		$stats['visits_total'] = 0;
		$stats['clicks'] = $defaults['clicks'];
		return $stats;
	});
}

function instaGetQrImageWebPath(string $fileName): string
{
	return '../QR/' . rawurlencode($fileName);
}

function instaH(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
