<?php
/**
 * SMS Logs (Admin)
 * GET /api/sms/logs.php
 *     ?student_id=X
 *     ?purpose=acknowledgment|admission|status_probation|...
 *     ?limit=50
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

$admin = requireAdmin(['super_admin', 'admin']);
$pdo   = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$studentId = (int)($_GET['student_id'] ?? 0);
$purpose   = trim($_GET['purpose'] ?? '');
$limit     = min(200, max(10, (int)($_GET['limit'] ?? 50)));

$sql = "
    SELECT l.*, s.first_name, s.last_name
    FROM sms_logs l
    LEFT JOIN students s ON s.id = l.related_student_id
    WHERE 1=1
";
$params = [];

if ($studentId > 0) {
    $sql .= " AND l.related_student_id = ?";
    $params[] = $studentId;
}
if ($purpose !== '') {
    $sql .= " AND l.purpose = ?";
    $params[] = $purpose;
}

$sql .= " ORDER BY l.created_at DESC LIMIT {$limit}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
jsonSuccess($stmt->fetchAll());
