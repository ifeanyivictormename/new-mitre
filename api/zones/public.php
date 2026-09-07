<?php
/**
 * Public – List active zones (for registration form dropdown)
 * GET /api/zones/public.php
 * No authentication required
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$pdo = db();
$zones = $pdo->query("
    SELECT id, name, code 
    FROM zones 
    WHERE is_active = 1 
    ORDER BY name ASC
")->fetchAll();

jsonSuccess($zones);
