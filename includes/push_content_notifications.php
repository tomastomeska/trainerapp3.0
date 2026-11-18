<?php

function pushContentLoad(PDO $pdo, string $kind, int $id): ?array
{
    if ($id < 1) {
        throw new InvalidArgumentException('Invalid push content ID.');
    }
    $queries = [
        'survey' => "SELECT id, title, survey_type, audience FROM surveys
                     WHERE id = ? AND status = 'published'
                       AND (expires_at IS NULL OR expires_at >= NOW())",
        'info_article' => "SELECT ia.id, ia.title, ia.target_audience, ic.audience AS category_audience
                           FROM info_articles ia JOIN info_categories ic ON ic.id = ia.category_id
                           WHERE ia.id = ? AND ia.is_active = 1 AND ia.published_at <= NOW()
                             AND ic.is_active = 1",
        'admin_gallery' => 'SELECT id, title, visibility FROM admin_gallery_files WHERE id = ?',
        'coach_gallery' => 'SELECT id, original_name AS title, visibility, coach_id FROM gallery_files WHERE id = ?',
    ];
    if (!isset($queries[$kind])) {
        throw new InvalidArgumentException('Invalid push content kind.');
    }
    $stmt = $pdo->prepare($queries[$kind]);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function pushContentRoles(string $kind, ?array $row): array
{
    if ($row === null) return [];
    if ($kind === 'survey') {
        return match ($row['audience']) {
            'all' => ['coach', 'athlete'],
            'coaches' => ['coach'],
            'athletes' => ['athlete'],
            default => throw new UnexpectedValueException('Invalid survey audience.'),
        };
    }
    if ($kind === 'info_article') {
        if (!in_array($row['target_audience'], ['all', 'coach', 'athlete'], true)
            || !in_array($row['category_audience'], ['all', 'coach', 'athlete'], true)) {
            throw new UnexpectedValueException('Invalid info article audience.');
        }
        $roles = [];
        foreach (['coach', 'athlete'] as $role) {
            if (in_array($row['target_audience'], ['all', $role], true)
                && in_array($row['category_audience'], ['all', $role], true)) {
                $roles[] = $role;
            }
        }
        return $roles;
    }
    if ($kind === 'admin_gallery') {
        return match ($row['visibility']) {
            'all_users' => ['coach', 'athlete'],
            'all_coaches', 'specific_coaches' => ['coach'],
            'all_athletes', 'specific_athletes' => ['athlete'],
            default => throw new UnexpectedValueException('Invalid admin gallery visibility.'),
        };
    }
    if ($kind === 'coach_gallery') {
        return match ($row['visibility']) {
            'all_athletes', 'specific_athletes' => ['athlete'],
            'private' => [],
            default => throw new UnexpectedValueException('Invalid coach gallery visibility.'),
        };
    }
    throw new InvalidArgumentException('Invalid push content kind.');
}

function pushContentRecipients(PDO $pdo, string $kind, array $row, string $role, ?int $subscriptionId = null): array
{
    if (!in_array($role, pushContentRoles($kind, $row), true)) return [];
    $params = [];
    if ($role === 'coach') {
        $sql = "SELECT DISTINCT s.coach_id FROM push_subscriptions s
                JOIN coaches c ON c.id = s.coach_id
                WHERE s.status = 'active' AND s.athlete_id IS NULL AND c.is_active = 1";
    } else {
        $sql = "SELECT DISTINCT s.athlete_id FROM push_subscriptions s
                JOIN athletes a ON a.id = s.athlete_id
                JOIN coaches c ON c.id = a.coach_id
                WHERE s.status = 'active' AND s.coach_id IS NULL AND a.login_enabled = 1 AND c.is_active = 1";
    }
    if ($kind === 'admin_gallery' && $row['visibility'] === 'specific_coaches') {
        $sql .= ' AND EXISTS (SELECT 1 FROM admin_gallery_file_coaches x WHERE x.file_id = ? AND x.coach_id = s.coach_id)';
        $params[] = (int)$row['id'];
    } elseif ($kind === 'admin_gallery' && $row['visibility'] === 'specific_athletes') {
        $sql .= ' AND EXISTS (SELECT 1 FROM admin_gallery_file_athletes x WHERE x.file_id = ? AND x.athlete_id = s.athlete_id)';
        $params[] = (int)$row['id'];
    } elseif ($kind === 'coach_gallery') {
        $sql .= ' AND a.coach_id = ?';
        $params[] = (int)$row['coach_id'];
        if ($row['visibility'] === 'specific_athletes') {
            $sql .= ' AND EXISTS (SELECT 1 FROM gallery_file_athletes x WHERE x.file_id = ? AND x.athlete_id = s.athlete_id)';
            $params[] = (int)$row['id'];
        }
    }
    if ($subscriptionId !== null) {
        $sql .= ' AND s.id = ?';
        $params[] = $subscriptionId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function pushContentTarget(string $kind, string $role, int $id): string
{
    return match ($kind) {
        'survey' => ($role === 'coach' ? '/surveys.php?id=' : '/athlete_surveys.php?id=') . $id,
        'info_article' => ($role === 'coach' ? '/infokanal.php?article=' : '/athlete_infokanal.php?article=') . $id,
        'admin_gallery' => ($role === 'coach' ? '/gallery.php?admin_file=' : '/athlete_gallery.php?admin_file=') . $id,
        'coach_gallery' => '/athlete_gallery.php?coach_file=' . $id,
        default => throw new InvalidArgumentException('Invalid push content kind.'),
    };
}

function pushContentBody(string $kind, array $row): string
{
    $name = trim((string)$row['title']);
    if ($name === '' || preg_match('//u', $name) !== 1) {
        throw new UnexpectedValueException('Push content title is invalid.');
    }
    $name = trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name));
    if ($name === '') throw new UnexpectedValueException('Push content title is empty.');
    $prefix = match ($kind) {
        'survey' => match ($row['survey_type']) {
            'poll' => 'Nová anketa: ',
            'questionnaire' => 'Nový dotazník: ',
            default => throw new UnexpectedValueException('Invalid survey type.'),
        },
        'info_article' => 'Nový článek v Infokanálu: ',
        'admin_gallery' => 'Nový příspěvek v galerii: ',
        'coach_gallery' => 'Trenér sdílel soubor: ',
        default => throw new InvalidArgumentException('Invalid push content kind.'),
    };
    $limit = 240 - mb_strlen($prefix, 'UTF-8');
    if (mb_strlen($name, 'UTF-8') > $limit) {
        $name = mb_substr($name, 0, $limit - 1, 'UTF-8') . '…';
    }
    return $prefix . $name;
}

function pushContentNotify(PDO $pdo, string $kind, int $id, array $excludeRoles = []): void
{
    try {
        if ($pdo->inTransaction()) throw new LogicException('Content push requires a committed event.');
        $row = pushContentLoad($pdo, $kind, $id);
        if ($row === null) return;
        $category = match ($kind) {
            'survey' => 'surveys',
            'info_article' => 'info_channel',
            'admin_gallery', 'coach_gallery' => 'gallery',
            default => throw new InvalidArgumentException('Invalid push content kind.'),
        };
        $body = pushContentBody($kind, $row);
        $queued = 0;
        foreach (array_diff(pushContentRoles($kind, $row), $excludeRoles) as $role) {
            foreach (pushContentRecipients($pdo, $kind, $row, $role) as $ownerId) {
                try {
                    $queued += pushEnqueueNotification(
                        $pdo, $role, $ownerId, 'TrainerApp', $body,
                        pushContentTarget($kind, $role, $id), 'content_' . $kind,
                        $category, 'content:' . $kind . ':' . $id
                    );
                } catch (Throwable $e) {
                    error_log('Content push recipient enqueue failed: ' . $kind . ' ' . get_class($e));
                }
            }
        }
        if ($queued > 0 && !pushDispatchAfterResponse($pdo)) {
            error_log('Content push remains queued; after-response dispatch is unavailable.');
        }
    } catch (Throwable $e) {
        error_log('Content push enqueue failed: ' . $kind . ' ' . get_class($e));
    }
}

function pushContentDeliveryAllowed(PDO $pdo, array $delivery): bool
{
    $types = [
        'content_survey' => ['survey', 'id'],
        'content_info_article' => ['info_article', 'article'],
        'content_admin_gallery' => ['admin_gallery', 'admin_file'],
        'content_coach_gallery' => ['coach_gallery', 'coach_file'],
    ];
    $type = (string)$delivery['notification_type'];
    if (!isset($types[$type])) return true;
    [$kind, $key] = $types[$type];
    $role = $delivery['coach_id'] !== null ? 'coach' : 'athlete';
    $url = (string)$delivery['target_url'];
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query)) return false;
    parse_str($query, $parameters);
    $rawId = $parameters[$key] ?? null;
    if (!is_string($rawId) || !preg_match('/^[1-9][0-9]*$/D', $rawId)) return false;
    $id = (int)$rawId;
    if ($id < 1 || pushContentTarget($kind, $role, $id) !== $url) return false;
    $row = pushContentLoad($pdo, $kind, $id);
    if ($row === null) return false;
    $subscriptionId = (int)($delivery['subscription_id'] ?? 0);
    if ($subscriptionId < 1) return false;
    return pushContentRecipients($pdo, $kind, $row, $role, $subscriptionId) !== [];
}
