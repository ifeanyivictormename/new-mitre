<?php
/**
 * POST /api/auth/admin_login.php
 * Body: { "email": "...", "password": "..." }
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

if (!checkRateLimit('admin_login', 8, 300)) {
    jsonError('Too many login attempts. Please wait a few minutes.', 429);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if ($email === '' || $password === '') {
    jsonError('Email and password are required');
}

$result = adminLogin($email, $password);

if ($result['success']) {
    jsonSuccess($result['user'], $result['message']);
} else {
    jsonError($result['message'], 401);
}
