<?php
/**
 * POST /api/auth/switch_zone.php
 * Body: { "zone_id": 1 }
 * Only for authenticated admins
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$zoneId = (int)($input['zone_id'] ?? 0);

if ($zoneId <= 0) {
    jsonError('Valid zone_id is required');
}

$result = switchZone($zoneId);

if ($result['success']) {
    jsonSuccess($result['zone'], $result['message']);
} else {
    jsonError($result['message'], 403);
}
