<?php
/**
 * View Conclave Results
 *
 * GET /api/assessments/results.php?conclave_id=X
 * GET /api/assessments/results.php?student_id=Y
 * GET /api/assessments/results.php?conclave_id=X&student_id=Y
 *
 * Accessible by Admin and by the student themselves (for their own results)
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

startSession();
$user = currentUser();
if (!$user) {
    jsonError('Authentication required', 401);
}

$pdo = db();
$conclaveId = (int)($_GET['conclave_id'] ?? 0);
$studentId  = (int)($_GET['student_id'] ?? 0);
$zoneId     = (int)($_GET['zone_id'] ?? 0);

// Student can only see their own results
if ($user['type'] === 'student') {
    $studentId = $user['id'];
}

$sql = "
    SELECT 
        r.*,
        s.first_name, s.last_name, s.phone, s.current_conclave,
        c.sequence, c.year, c.title AS conclave_title,
        z.name AS zone_name
    FROM conclave_results r
    JOIN students s ON s.id = r.student_id
    JOIN conclaves c ON c.id = r.conclave_id
    JOIN zones z ON z.id = s.zone_id
    WHERE 1=1
";
$params = [];

if ($conclaveId > 0) {
    $sql .= " AND r.conclave_id = ?";
    $params[] = $conclaveId;
}
if ($studentId > 0) {
    $sql .= " AND r.student_id = ?";
    $params[] = $studentId;
}

// Optional zone filter for super_admin.
if ($user['type'] === 'admin' && $user['role'] === 'super_admin' && $zoneId > 0) {
    $sql .= " AND s.zone_id = ?";
    $params[] = $zoneId;
}

// Zone restriction for regular admin
if ($user['type'] === 'admin' && $user['role'] === 'admin' && !empty($user['zone_id'])) {
    $sql .= " AND s.zone_id = ?";
    $params[] = $user['zone_id'];
}

$sql .= " ORDER BY s.first_name ASC, s.last_name ASC, c.year DESC, c.sequence ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

jsonSuccess($results);
