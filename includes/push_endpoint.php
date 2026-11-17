<?php

function pushEndpointIsAllowed(mixed $endpoint): bool
{
    if (!is_string($endpoint)
        || $endpoint === ''
        || strlen($endpoint) > 2048
        || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint) === 1
        || filter_var($endpoint, FILTER_VALIDATE_URL) === false
    ) {
        return false;
    }

    $parts = parse_url($endpoint);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || (isset($parts['port']) && $parts['port'] !== 443)
    ) {
        return false;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    if (in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com'], true)) {
        return true;
    }

    return preg_match(
        '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:push\.apple\.com|notify\.windows\.com)$/D',
        $host
    ) === 1;
}
