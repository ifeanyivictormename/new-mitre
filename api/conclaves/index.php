<?php
/**
 * Conclave Management (GLOBAL – not per zone)
 *
 * Status model: only "open" | "closed"
 * - Attendance & assessments allowed only when status = open
 * - Both admin and super_admin can create / update
 * - Only super_admin can delete
 *
 * GET    /api/conclaves/index.php
 * GET    /api/conclaves/index.php?id=X
 * POST   /api/conclaves/index.php
 * PUT    /api/conclaves/index.php?id=X
 * DELETE /api/conclaves/index.php?id=X   (super_admin only)
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

$admin  = requireAdmin(['super_admin', 'admin']);
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$isSuper = ($admin['role'] === 'super_admin');

$allowedStatus = ['open', 'closed'];

// ---------- GET ----------
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM conclaves WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) jsonError('Conclave not found', 404);
        // Normalize legacy statuses for clients
        $row['status'] = normalizeStatus($row['status']);
        jsonSuccess($row);
    }

    $sql = "SELECT * FROM conclaves WHERE 1=1";
    $params = [];
    if (!empty($_GET['status'])) {
        $want = normalizeStatus($_GET['status']);
        if ($want === 'open') {
            $sql .= " AND status IN ('open','ongoing','planned')";
        } else {
            $sql .= " AND status IN ('closed','cancelled')";
        }
    }
    if (!empty($_GET['year'])) {
        $sql .= " AND year = ?";
        $params[] = (int)$_GET['year'];
    }
    $sql .= " ORDER BY year DESC, sequence ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['status'] = normalizeStatus($r['status']);
    }
    unset($r);
    jsonSuccess($rows);
}

// ---------- POST: Create (admin + super_admin) ----------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $sequence = (int)($input['sequence'] ?? 0);
    $year     = (int)($input['year'] ?? date('Y'));
    $title    = trim($input['title'] ?? '');
    $start    = $input['start_date'] ?? '';
    $end      = $input['end_date'] ?? '';
    $status   = normalizeStatus($input['status'] ?? 'closed');
    $notes    = array_key_exists('notes', $input) ? $input['notes'] : null;

    if ($sequence < 1 || $sequence > 6 || !$start || !$end) {
        jsonError('sequence (1-6), start_date and end_date are required');
    }
    if (!in_array($status, $allowedStatus, true)) {
        jsonError('Status must be open or closed');
    }

    $stmt = $pdo->prepare("SELECT id FROM conclaves WHERE sequence = ? AND year = ?");
    $stmt->execute([$sequence, $year]);
    if ($stmt->fetch()) {
        jsonError('A conclave with this sequence already exists for the selected year');
    }

    if ($title === '') {
        $title = "{$year} Conclave {$sequence}";
    }

    $stmt = $pdo->prepare("
        INSERT INTO conclaves (sequence, year, title, start_date, end_date, status, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$sequence, $year, $title, $start, $end, $status, $notes]);

    $id = (int)$pdo->lastInsertId();
    $row = $pdo->query("SELECT * FROM conclaves WHERE id = {$id}")->fetch();
    $row['status'] = normalizeStatus($row['status']);
    jsonSuccess($row, 'Conclave created successfully');
}

// ---------- PUT: Update (admin + super_admin) ----------
if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('id is required');

    $stmt = $pdo->prepare("SELECT * FROM conclaves WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) jsonError('Conclave not found', 404);

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $title    = isset($input['title']) ? trim($input['title']) : $existing['title'];
    $start    = $input['start_date'] ?? $existing['start_date'];
    $end      = $input['end_date'] ?? $existing['end_date'];
    $status   = isset($input['status']) ? normalizeStatus($input['status']) : normalizeStatus($existing['status']);
    $notes    = array_key_exists('notes', $input) ? $input['notes'] : $existing['notes'];
    $sequence = isset($input['sequence']) ? (int)$input['sequence'] : (int)$existing['sequence'];
    $year     = isset($input['year']) ? (int)$input['year'] : (int)$existing['year'];

    if (!in_array($status, $allowedStatus, true)) {
        jsonError('Status must be open or closed');
    }
    if ($sequence < 1 || $sequence > 6) {
        jsonError('sequence must be 1-6');
    }

    $stmt = $pdo->prepare("SELECT id FROM conclaves WHERE sequence = ? AND year = ? AND id != ?");
    $stmt->execute([$sequence, $year, $id]);
    if ($stmt->fetch()) {
        jsonError('Another conclave already uses this sequence for the selected year');
    }

    $stmt = $pdo->prepare("
        UPDATE conclaves
        SET sequence = ?, year = ?, title = ?, start_date = ?, end_date = ?, status = ?, notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$sequence, $year, $title, $start, $end, $status, $notes, $id]);

    $row = $pdo->query("SELECT * FROM conclaves WHERE id = {$id}")->fetch();
    $row['status'] = normalizeStatus($row['status']);
    jsonSuccess($row, 'Conclave updated successfully');
}

// ---------- DELETE: super_admin only ----------
if ($method === 'DELETE') {
    if (!$isSuper) {
        jsonError('Only super_admin can delete conclaves', 403);
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('id is required');

    $stmt = $pdo->prepare("SELECT id, status FROM conclaves WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) jsonError('Conclave not found', 404);

    // Block delete if attendance or assessments exist
    $cnt = (int)$pdo->query("
        SELECT (
            (SELECT COUNT(*) FROM attendance WHERE conclave_id = {$id}) +
            (SELECT COUNT(*) FROM assessments WHERE conclave_id = {$id})
        )
    ")->fetchColumn();

    if ($cnt > 0) {
        jsonError('Cannot delete: this conclave has attendance or assessment records. Close it instead.');
    }

    $pdo->prepare("DELETE FROM conclaves WHERE id = ?")->execute([$id]);
    jsonSuccess(null, 'Conclave deleted');
}

jsonError('Method not allowed', 405);

/**
 * Map legacy statuses → open | closed
 */
function normalizeStatus(?string $status): string {
    $s = strtolower(trim((string)$status));
    if (in_array($s, ['open', 'ongoing', 'planned'], true)) {
        return 'open';
    }
    return 'closed';
}
