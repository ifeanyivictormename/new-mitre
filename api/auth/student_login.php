<?php
/**
 * POST /api/auth/student_login.php
 * Body: { "phone": "08012345678" }
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

if (!checkRateLimit('student_login', 10, 300)) {
    jsonError('Too many login attempts. Please wait a few minutes.', 429);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$phone = trim($input['phone'] ?? '');

if ($phone === '') {
    jsonError('Phone number is required');
}

$result = studentLogin($phone);

if ($result['success']) {
    jsonSuccess($result['user'], $result['message']);
} else {
    jsonError($result['message'], 401);
}
