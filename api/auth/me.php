<?php
/**
 * GET /api/auth/me.php
 * Returns current logged-in user + current zone
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$user = currentUser();
if (!$user) {
    jsonError('Not authenticated', 401);
}

$zone = getCurrentZone();

jsonSuccess([
    'user' => $user,
    'current_zone' => $zone
]);
