<?php
/**
 * Attendance Management
 *
 * GET  /api/attendance/index.php?conclave_id=X&student_id=Y   → get attendance
 * POST /api/attendance/index.php
 *      Body: {
 *        "conclave_id": 5,
 *        "student_id": 12,
 *        "records": [
 *          {"day_number":1, "session":"morning", "is_present": true},
 *          {"day_number":1, "session":"evening", "is_present": false},
 *          ...
 *        ]
 *      }
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$admin  = requireAdmin(['super_admin', 'admin']);
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

// ---------- GET ----------
if ($method === 'GET') {
    $conclaveId = (int)($_GET['conclave_id'] ?? 0);
    $studentId  = (int)($_GET['student_id'] ?? 0);

    if ($conclaveId <= 0) jsonError('conclave_id is required');

    $sql = "
        SELECT a.*, s.first_name, s.last_name, s.phone
        FROM attendance a
        JOIN students s ON s.id = a.student_id
        WHERE a.conclave_id = ?
    ";
    $params = [$conclaveId];

    if ($studentId > 0) {
        $sql .= " AND a.student_id = ?";
        $params[] = $studentId;
    }

    $sql .= " ORDER BY s.last_name, a.day_number, a.session";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonSuccess($stmt->fetchAll());
}

// ---------- POST: Mark / Update attendance ----------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $conclaveId = (int)($input['conclave_id'] ?? 0);
    $studentId  = (int)($input['student_id'] ?? 0);
    $records    = $input['records'] ?? [];

    if ($conclaveId <= 0 || $studentId <= 0 || empty($records)) {
        jsonError('conclave_id, student_id and records array are required');
    }

    // Validate conclave exists (global) and is OPEN (status only — not dates)
    $stmt = $pdo->prepare("SELECT id, status FROM conclaves WHERE id = ?");
    $stmt->execute([$conclaveId]);
    $conclave = $stmt->fetch();
    if (!$conclave) jsonError('Conclave not found', 404);

    $cStatus = strtolower((string)$conclave['status']);
    $isOpen = in_array($cStatus, ['open', 'ongoing', 'planned'], true);
    if (!$isOpen) {
        jsonError('This conclave is closed. Re-open it to record or edit attendance.', 403);
    }

    $stmt = $pdo->prepare("SELECT id, zone_id, status FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) jsonError('Student not found', 404);

    try {
        $pdo->beginTransaction();

        $upsert = $pdo->prepare("
            INSERT INTO attendance (student_id, conclave_id, day_number, session, is_present, marked_by, marked_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                is_present = VALUES(is_present),
                marked_by = VALUES(marked_by),
                marked_at = NOW()
        ");

        foreach ($records as $r) {
            $day     = (int)($r['day_number'] ?? 0);
            $session = $r['session'] ?? '';
            $present = !empty($r['is_present']) ? 1 : 0;

            if ($day < 1 || $day > 3 || !in_array($session, ['morning', 'evening'])) {
                continue; // skip invalid
            }

            $upsert->execute([$studentId, $conclaveId, $day, $session, $present, $admin['id']]);
        }

        $pdo->commit();

        // Keep conclave_results in sync so attendance shows up in Results immediately.
        computeResult($pdo, $studentId, $conclaveId);

        // Return current attendance for this student/conclave
        $stmt = $pdo->prepare("
            SELECT day_number, session, is_present 
            FROM attendance 
            WHERE student_id = ? AND conclave_id = ?
            ORDER BY day_number, session
        ");
        $stmt->execute([$studentId, $conclaveId]);
        $current = $stmt->fetchAll();

        jsonSuccess($current, 'Attendance saved successfully');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Attendance error: ' . $e->getMessage());
        jsonError('Failed to save attendance', 500);
    }
}

jsonError('Method not allowed', 405);
