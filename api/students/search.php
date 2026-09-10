<?php
/**
 * Student name search (for autocomplete)
 * GET /api/students/search.php?q=john
 * Returns matching students (name, phone, id, zone) – limited results
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

$admin = requireAdmin(['super_admin', 'admin']);
$pdo   = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    jsonSuccess([]);
}

$zoneId = null;
if ($admin['role'] === 'admin' && $admin['zone_id'] !== null) {
    $zoneId = $admin['zone_id'];
} elseif (!empty($admin['current_zone_id'])) {
    $zoneId = $admin['current_zone_id'];
}

$term = '%' . $q . '%';

// Prefer query with set/reg columns; fall back if migration not applied yet
$sqlWithSets = "
    SELECT s.id, s.first_name, s.last_name, s.other_names, s.phone, s.status,
           s.set_number, s.reg_no, s.current_conclave,
           z.name AS zone_name, z.code AS zone_code
    FROM students s
    JOIN zones z ON z.id = s.zone_id
    WHERE (
        s.first_name LIKE ? OR s.last_name LIKE ?
        OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
        OR CONCAT(s.first_name, ' ', COALESCE(s.other_names,''), ' ', s.last_name) LIKE ?
        OR s.phone LIKE ?
        OR IFNULL(s.reg_no,'') LIKE ?
    )
    AND s.status IN ('admitted','active','probation','graduated')
";
$sqlBasic = "
    SELECT s.id, s.first_name, s.last_name, s.other_names, s.phone, s.status,
           z.name AS zone_name, z.code AS zone_code
    FROM students s
    JOIN zones z ON z.id = s.zone_id
    WHERE (
        s.first_name LIKE ? OR s.last_name LIKE ?
        OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
        OR CONCAT(s.first_name, ' ', COALESCE(s.other_names,''), ' ', s.last_name) LIKE ?
        OR s.phone LIKE ?
    )
    AND s.status IN ('admitted','active','probation','graduated')
";

try {
    $params = [$term, $term, $term, $term, $term, $term];
    $sql = $sqlWithSets;
    if ($zoneId) {
        $sql .= " AND s.zone_id = ?";
        $params[] = $zoneId;
    }
    $sql .= " ORDER BY s.first_name ASC, s.last_name ASC LIMIT 15";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    // Columns may not exist yet – fall back
    $params = [$term, $term, $term, $term, $term];
    $sql = $sqlBasic;
    if ($zoneId) {
        $sql .= " AND s.zone_id = ?";
        $params[] = $zoneId;
    }
    $sql .= " ORDER BY s.first_name ASC, s.last_name ASC LIMIT 15";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

foreach ($rows as &$r) {
    $r['display_name'] = trim($r['first_name'] . ' ' . ($r['other_names'] ? $r['other_names'] . ' ' : '') . $r['last_name']);
}
unset($r);

jsonSuccess($rows);
