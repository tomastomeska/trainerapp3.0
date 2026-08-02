<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (!isLoggedIn() && !athleteIsLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    flash('danger', 'Formulář se nepodařilo odeslat (neplatný token).');
    $fallback = BASE_URL . (isLoggedIn() ? '/special_training.php' : '/athlete_special_training.php');
    header('Location: ' . $fallback);
    exit;
}

$eventSlug = trim((string)($_POST['_event_slug'] ?? ''));
$eventName = trim((string)($_POST['_event_name'] ?? ''));
$formSubject = trim((string)($_POST['_event_form_subject'] ?? ''));
$returnUrl = trim((string)($_POST['_return_url'] ?? ''));

$toEmail = trim(getAppSetting('events_forms_email_to', ''));
if ($toEmail === '') {
    $toEmail = getAdminNotificationEmail();
}

if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    flash('danger', 'Cílový e-mail pro formuláře Events není správně nastaven.');
    $fallback = BASE_URL . (isLoggedIn() ? '/special_training.php' : '/athlete_special_training.php');
    header('Location: ' . $fallback);
    exit;
}

$reservedKeys = [
    'csrf_token' => true,
    '_event_slug' => true,
    '_event_name' => true,
    '_event_form_subject' => true,
    '_return_url' => true,
];

$fields = [];
foreach ($_POST as $key => $value) {
    $fieldKey = trim((string)$key);
    if ($fieldKey === '' || isset($reservedKeys[$fieldKey])) {
        continue;
    }

    if (is_array($value)) {
        $vals = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $vals[] = mb_substr($text, 0, 4000, 'UTF-8');
            }
        }
        if (!empty($vals)) {
            $fields[$fieldKey] = $vals;
        }
        continue;
    }

    $textValue = trim((string)$value);
    if ($textValue === '') {
        continue;
    }
    $fields[$fieldKey] = mb_substr($textValue, 0, 4000, 'UTF-8');
}

if (empty($fields)) {
    flash('warning', 'Formulář neobsahoval vyplněná pole.');
} else {
    $senderName = '';
    $senderEmail = '';
    $senderRole = '';

    if (isLoggedIn()) {
        $senderRole = 'coach';
        $coach = getCurrentCoach();
        if (is_array($coach)) {
            $senderName = trim((string)($coach['name'] ?? $coach['username'] ?? ''));
            $senderEmail = trim((string)($coach['email'] ?? ''));
        }
    } elseif (athleteIsLoggedIn()) {
        $senderRole = 'athlete';
        $athlete = getCurrentAthlete();
        if (is_array($athlete)) {
            $senderName = trim((string)(($athlete['first_name'] ?? '') . ' ' . ($athlete['last_name'] ?? '')));
            $senderEmail = trim((string)($athlete['email'] ?? ''));
        }
    }

    if ($formSubject === '') {
        $formSubject = 'Events formular';
    }
    if ($eventName !== '') {
        $formSubject .= ' - ' . $eventName;
    }

    $sent = sendSpecialEventFormEmail($toEmail, $formSubject, $fields, [
        'event_name' => $eventName,
        'event_slug' => $eventSlug,
        'sender_name' => $senderName,
        'sender_email' => $senderEmail,
        'sender_role' => $senderRole,
    ]);

    if ($sent) {
        flash('success', 'Formulář byl úspěšně odeslán.');
    } else {
        flash('danger', 'Odeslání formuláře se nepodařilo. Zkuste to prosím znovu.');
    }
}

$fallback = BASE_URL . (isLoggedIn() ? '/special_training.php' : '/athlete_special_training.php');
$redirect = $fallback;
if ($returnUrl !== '') {
    $parsed = parse_url($returnUrl);
    $path = (string)($parsed['path'] ?? '');
    $query = isset($parsed['query']) ? ('?' . $parsed['query']) : '';

    if ($path !== '' && str_starts_with($path, BASE_URL . '/')) {
        $redirect = $path . $query;
    } elseif ($path !== '' && str_starts_with($path, '/')) {
        $redirect = $path . $query;
    }
}

header('Location: ' . $redirect);
exit;
