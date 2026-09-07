<?php
/**
 * Admin Users API – super_admin only
 *
 * GET    /api/admins/index.php           → list admins
 * GET    /api/admins/index.php?id=X      → one admin
 * POST   /api/admins/index.php           → create admin
 * PUT    /api/admins/index.php?id=X      → update admin
 * DELETE /api/admins/index.php?id=X      → soft-deactivate
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$admin  = requireAdmin(['super_admin']);
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

function adminPublicRow(array $row): array {
    return [
        'id'         => (int)$row['id'],
        'full_name'  => $row['full_name'],
        'email'      => $row['email'],
        'phone'      => $row['phone'],
        'role'       => $row['role'],
        'zone_id'    => $row['zone_id'] !== null ? (int)$row['zone_id'] : null,
        'zone_name'  => $row['zone_name'] ?? null,
        'is_active'  => (int)$row['is_active'],
        'last_login' => $row['last_login'],
        'created_at' => $row['created_at'],
    ];
}

if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT a.*, z.name AS zone_name
            FROM admins a
            LEFT JOIN zones z ON z.id = a.zone_id
            WHERE a.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonError('Admin not found', 404);
        jsonSuccess(adminPublicRow($row));
    }
    $rows = $pdo->query("
        SELECT a.*, z.name AS zone_name
        FROM admins a
        LEFT JOIN zones z ON z.id = a.zone_id
        ORDER BY a.role DESC, a.full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    jsonSuccess(array_map('adminPublicRow', $rows));
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $fullName = trim($input['full_name'] ?? '');
    $email    = strtolower(trim($input['email'] ?? ''));
    $phone    = trim($input['phone'] ?? '') ?: null;
    $password = (string)($input['password'] ?? '');
    $role     = trim($input['role'] ?? 'admin');
    $zoneId   = array_key_exists('zone_id', $input) && $input['zone_id'] !== '' && $input['zone_id'] !== null
        ? (int)$input['zone_id'] : null;
    $isActive = isset($input['is_active']) ? (int)(bool)$input['is_active'] : 1;

    if ($fullName === '' || $email === '' || $password === '') {
        jsonError('full_name, email and password are required');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Invalid email address');
    if (!in_array($role, ['super_admin', 'admin'], true)) jsonError('role must be super_admin or admin');
    if (strlen($password) < 8) jsonError('Password must be at least 8 characters');
    if ($role === 'super_admin') $zoneId = null;
    if ($zoneId !== null) {
        $z = $pdo->prepare('SELECT id FROM zones WHERE id = ?');
        $z->execute([$zoneId]);
        if (!$z->fetch()) jsonError('Invalid zone_id');
    }
    $exists = $pdo->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) jsonError('An admin with this email already exists');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO admins (full_name, email, phone, password_hash, role, zone_id, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$fullName, $email, $phone, $hash, $role, $zoneId, $isActive]);
    $newId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("
        SELECT a.*, z.name AS zone_name FROM admins a
        LEFT JOIN zones z ON z.id = a.zone_id WHERE a.id = ?
    ");
    $stmt->execute([$newId]);
    jsonSuccess(adminPublicRow($stmt->fetch(PDO::FETCH_ASSOC)), 'Admin created');
}

if ($method === 'PUT') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) jsonError('id is required');
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) jsonError('Admin not found', 404);

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $fullName = array_key_exists('full_name', $input) ? trim($input['full_name']) : $existing['full_name'];
    $email    = array_key_exists('email', $input) ? strtolower(trim($input['email'])) : $existing['email'];
    $phone    = array_key_exists('phone', $input) ? (trim($input['phone'] ?? '') ?: null) : $existing['phone'];
    $role     = array_key_exists('role', $input) ? trim($input['role']) : $existing['role'];
    $isActive = array_key_exists('is_active', $input) ? (int)(bool)$input['is_active'] : (int)$existing['is_active'];
    if (array_key_exists('zone_id', $input)) {
        $zoneId = ($input['zone_id'] === '' || $input['zone_id'] === null) ? null : (int)$input['zone_id'];
    } else {
        $zoneId = $existing['zone_id'] !== null ? (int)$existing['zone_id'] : null;
    }

    if ($fullName === '' || $email === '') jsonError('full_name and email are required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Invalid email address');
    if (!in_array($role, ['super_admin', 'admin'], true)) jsonError('role must be super_admin or admin');

    if ($existing['role'] === 'super_admin' && ($role !== 'super_admin' || $isActive === 0)) {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin' AND is_active = 1")->fetchColumn();
        if ($cnt <= 1) jsonError('Cannot demote or deactivate the last active super_admin');
    }
    if ((int)$admin['id'] === $id && $isActive === 0) jsonError('You cannot deactivate your own account');
    if ($role === 'super_admin') $zoneId = null;
    if ($zoneId !== null) {
        $z = $pdo->prepare('SELECT id FROM zones WHERE id = ?');
        $z->execute([$zoneId]);
        if (!$z->fetch()) jsonError('Invalid zone_id');
    }
    $dup = $pdo->prepare('SELECT id FROM admins WHERE email = ? AND id != ? LIMIT 1');
    $dup->execute([$email, $id]);
    if ($dup->fetch()) jsonError('Another admin already uses this email');

    $sql = "UPDATE admins SET full_name = ?, email = ?, phone = ?, role = ?, zone_id = ?, is_active = ?, updated_at = NOW()";
    $params = [$fullName, $email, $phone, $role, $zoneId, $isActive];
    if (!empty($input['password'])) {
        if (strlen((string)$input['password']) < 8) jsonError('Password must be at least 8 characters');
        $sql .= ", password_hash = ?";
        $params[] = password_hash((string)$input['password'], PASSWORD_DEFAULT);
    }
    $sql .= " WHERE id = ?";
    $params[] = $id;
    $pdo->prepare($sql)->execute($params);

    $stmt = $pdo->prepare("
        SELECT a.*, z.name AS zone_name FROM admins a
        LEFT JOIN zones z ON z.id = a.zone_id WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    jsonSuccess(adminPublicRow($stmt->fetch(PDO::FETCH_ASSOC)), 'Admin updated');
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) jsonError('id is required');
    if ((int)$admin['id'] === $id) jsonError('You cannot deactivate your own account');
    $stmt = $pdo->prepare('SELECT role FROM admins WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Admin not found', 404);
    if ($row['role'] === 'super_admin') {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin' AND is_active = 1")->fetchColumn();
        if ($cnt <= 1) jsonError('Cannot deactivate the last active super_admin');
    }
    $pdo->prepare('UPDATE admins SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
    jsonSuccess(null, 'Admin deactivated');
}

jsonError('Method not allowed', 405);
