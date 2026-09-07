<?php
/**
 * CORS headers – supports separate frontend + backend hosts
 */
require_once __DIR__ . '/../config/config.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Build allowed list from FRONTEND_URL (comma-separated) + APP_URL itself
$allowed = array_filter(array_map('trim', explode(',', FRONTEND_URL)));
$allowed[] = rtrim(APP_URL, '/');
$allowed = array_unique($allowed);

if ($origin !== '' && in_array($origin, $allowed, true)) {
    // Cookie-authenticated requests cannot use a wildcard origin.
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Zone-Id');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
