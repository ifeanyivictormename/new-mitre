<?php
/**
 * Zones API
 * GET    /api/zones/index.php          → list all zones (admin)
 * POST   /api/zones/index.php          → create new zone (super_admin)
 * PUT    /api/zones/index.php?id=X     → update zone (name, code, description, is_active) (super_admin)
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

// ---------- GET: List zones ----------
if ($method === 'GET') {
    // Both admin roles can list zones
    requireAdmin(['super_admin', 'admin']);

    $onlyActive = isset($_GET['active']) && $_GET['active'] === '1';

    $sql = "SELECT id, name, code, description, is_active, created_at, updated_at FROM zones";
    if ($onlyActive) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY name ASC";

    $zones = $pdo->query($sql)->fetchAll();
    jsonSuccess($zones);
}

// ---------- POST: Create zone (super_admin only) ----------
if ($method === 'POST') {
    requireAdmin(['super_admin']);

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name        = trim($input['name'] ?? '');
    $code        = strtoupper(trim($input['code'] ?? ''));
    $description = trim($input['description'] ?? '');

    if ($name === '' || $code === '') {
        jsonError('Name and code are required');
    }

    if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
        jsonError('Code must be 2-10 uppercase letters/numbers');
    }

    // Check uniqueness
    $stmt = $pdo->prepare("SELECT id FROM zones WHERE code = ? OR name = ?");
    $stmt->execute([$code, $name]);
    if ($stmt->fetch()) {
        jsonError('A zone with this name or code already exists');
    }

    $stmt = $pdo->prepare("
        INSERT INTO zones (name, code, description, is_active)
        VALUES (?, ?, ?, 1)
    ");
    $stmt->execute([$name, $code, $description ?: null]);

    $id = (int)$pdo->lastInsertId();
    $zone = $pdo->query("SELECT id, name, code, description, is_active, created_at FROM zones WHERE id = $id")->fetch();

    http_response_code(201);
    jsonSuccess($zone, 'Zone created successfully');
}

// ---------- PUT: Update zone (super_admin only) ----------
if ($method === 'PUT') {
    requireAdmin(['super_admin']);

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonError('Valid zone id is required');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // Fetch existing
    $stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        jsonError('Zone not found', 404);
    }

    $name        = isset($input['name']) ? trim($input['name']) : $existing['name'];
    $code        = isset($input['code']) ? strtoupper(trim($input['code'])) : $existing['code'];
    $description = array_key_exists('description', $input) ? trim($input['description']) : $existing['description'];
    $isActive    = array_key_exists('is_active', $input) ? (int)(bool)$input['is_active'] : (int)$existing['is_active'];

    if ($name === '' || $code === '') {
        jsonError('Name and code cannot be empty');
    }

    if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
        jsonError('Code must be 2-10 uppercase letters/numbers');
    }

    // Uniqueness check (exclude self)
    $stmt = $pdo->prepare("SELECT id FROM zones WHERE (code = ? OR name = ?) AND id != ?");
    $stmt->execute([$code, $name, $id]);
    if ($stmt->fetch()) {
        jsonError('Another zone already uses this name or code');
    }

    $stmt = $pdo->prepare("
        UPDATE zones 
        SET name = ?, code = ?, description = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([$name, $code, $description ?: null, $isActive, $id]);

    $zone = $pdo->query("SELECT id, name, code, description, is_active, updated_at FROM zones WHERE id = $id")->fetch();
    jsonSuccess($zone, 'Zone updated successfully');
}

jsonError('Method not allowed', 405);
