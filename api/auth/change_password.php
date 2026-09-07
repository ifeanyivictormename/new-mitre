<?php
/**
 * POST /api/auth/change_password.php
 * Body: { "current_password": "...", "new_password": "...", "confirm_password": "..." }
 * Authenticated admin only — changes own password.
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

if (!checkRateLimit('change_password', 6, 300)) {
    jsonError('Too many attempts. Please wait a few minutes.', 429);
}

$admin = requireAdmin(['super_admin', 'admin']);
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$current = (string)($input['current_password'] ?? '');
$new     = (string)($input['new_password'] ?? '');
$confirm = (string)($input['confirm_password'] ?? '');

if ($current === '' || $new === '' || $confirm === '') {
    jsonError('current_password, new_password and confirm_password are required');
}

if ($new !== $confirm) {
    jsonError('New password and confirmation do not match');
}

if (strlen($new) < 8) {
    jsonError('New password must be at least 8 characters');
}

if ($new === $current) {
    jsonError('New password must be different from the current password');
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id, password_hash FROM admins WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$admin['id']]);
$row = $stmt->fetch();

if (!$row || !password_verify($current, $row['password_hash'])) {
    jsonError('Current password is incorrect', 401);
}

$hash = password_hash($new, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE id = ?")
    ->execute([$hash, $admin['id']]);

// Audit
try {
    $pdo->prepare("
        INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, new_values)
        VALUES (?, 'change_password', 'admin', ?, ?)
    ")->execute([$admin['id'], $admin['id'], json_encode(['changed_at' => date('c')])]);
} catch (Exception $e) {
    // non-fatal
}

jsonSuccess(null, 'Password changed successfully');
