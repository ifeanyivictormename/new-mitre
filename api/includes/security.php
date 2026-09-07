<?php
/**
 * Basic security helpers
 */

require_once __DIR__ . '/auth.php';

/**
 * Simple rate-limit style check using session (very basic)
 * For production, use Redis or a proper rate limiter.
 */
function checkRateLimit(string $key, int $maxAttempts = 10, int $windowSeconds = 300): bool {
    startSession();
    $now = time();
    $bucket = $_SESSION['_rate'][$key] ?? ['count' => 0, 'start' => $now];

    if ($now - $bucket['start'] > $windowSeconds) {
        $bucket = ['count' => 0, 'start' => $now];
    }

    $bucket['count']++;
    $_SESSION['_rate'][$key] = $bucket;

    return $bucket['count'] <= $maxAttempts;
}

/**
 * Sanitize filename for uploads
 */
function safeFilename(string $name): string {
    $name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $name);
    return substr($name, 0, 120);
}

/**
 * Basic CSRF token (for future form protection)
 */
function csrfToken(): string {
    startSession();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function verifyCsrf(?string $token): bool {
    startSession();
    return $token && hash_equals($_SESSION['_csrf'] ?? '', $token);
}
