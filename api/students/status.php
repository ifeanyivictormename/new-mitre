<?php
/**
 * Student Status Management & Probation
 *
 * GET  /api/students/status.php?action=check_probation&zone_id=X
 *      → Scan students and flag those who meet probation criteria
 *
 * POST /api/students/status.php
 * Body examples:
 * {
 *   "student_id": 12,
 *   "action": "set_probation" | "set_inactive" | "set_withdrawn" | "set_active" | "set_graduated",
 *   "notes": "optional reason",
 *   "send_sms": true
 * }
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$admin  = requireAdmin(['super_admin', 'admin']);
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

// ---------- GET: Check / Detect Probation ----------
if ($method === 'GET' && ($_GET['action'] ?? '') === 'check_probation') {
    $zoneId = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : null;

    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null) {
        $zoneId = $admin['zone_id'];
    } elseif ($zoneId === null && !empty($admin['current_zone_id'])) {
        $zoneId = $admin['current_zone_id'];
    }

    $flagged = detectProbationCandidates($pdo, $zoneId);
    jsonSuccess($flagged, 'Probation check completed');
}

// ---------- POST: Change Status ----------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $studentId = (int)($input['student_id'] ?? 0);
    $action    = strtolower(trim($input['action'] ?? ''));
    $notes     = trim($input['notes'] ?? '') ?: null;
    $sendSms   = !empty($input['send_sms']);

    $allowed = [
        'set_probation'  => 'probation',
        'set_inactive'   => 'inactive',
        'set_withdrawn'  => 'withdrawn',
        'set_active'     => 'active',
        'set_graduated'  => 'graduated'
    ];

    if ($studentId <= 0 || !isset($allowed[$action])) {
        jsonError('student_id and a valid action are required');
    }

    $newStatus = $allowed[$action];

    // Fetch student
    $stmt = $pdo->prepare("
        SELECT s.*, z.name AS zone_name 
        FROM students s 
        JOIN zones z ON z.id = s.zone_id 
        WHERE s.id = ?
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        jsonError('Student not found', 404);
    }

    // Zone access check
    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $admin['zone_id'] != $student['zone_id']) {
        jsonError('You do not have permission for this student', 403);
    }

    $oldStatus = $student['status'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE students SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $studentId]);

        // Audit log
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_values, new_values)
            VALUES (?, ?, 'student', ?, ?, ?)
        ");
        $stmt->execute([
            $admin['id'],
            "status_change_{$newStatus}",
            $studentId,
            json_encode(['status' => $oldStatus, 'notes' => $notes]),
            json_encode(['status' => $newStatus])
        ]);

        $pdo->commit();

        // SMS notification
        if ($sendSms) {
            $fullName = trim($student['first_name'] . ' ' . $student['last_name']);
            $msg = buildStatusSms($fullName, $newStatus, $student['zone_name']);
            logAndSendSms($student['phone'], $msg, "status_{$newStatus}", $studentId);
        }

        jsonSuccess([
            'student_id' => $studentId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ], "Student status updated to {$newStatus}");

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Status change error: ' . $e->getMessage());
        jsonError('Failed to update status', 500);
    }
}

jsonError('Method not allowed', 405);

/**
 * Detect students who missed attendance and/or term paper
 * for two consecutive conclaves → candidates for probation
 */
function detectProbationCandidates(PDO $pdo, ?int $zoneId = null): array {
    // Get active/admitted students
    $sql = "
        SELECT s.id, s.first_name, s.last_name, s.phone, s.status, s.current_conclave, s.zone_id,
               z.name AS zone_name
        FROM students s
        JOIN zones z ON z.id = s.zone_id
        WHERE s.status IN ('admitted', 'active', 'probation')
    ";
    $params = [];
    if ($zoneId) {
        $sql .= " AND s.zone_id = ?";
        $params[] = $zoneId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $candidates = [];

    foreach ($students as $student) {
        // Get the last two conclave results for this student (by sequence desc)
        $stmt = $pdo->prepare("
            SELECT r.conclave_id, r.has_attendance, r.has_term_paper,
                   c.sequence, c.year, c.title
            FROM conclave_results r
            JOIN conclaves c ON c.id = r.conclave_id
            WHERE r.student_id = ?
            ORDER BY c.year DESC, c.sequence DESC
            LIMIT 2
        ");
        $stmt->execute([$student['id']]);
        $results = $stmt->fetchAll();

        if (count($results) < 2) {
            continue; // need at least two conclaves
        }

        $missedCount = 0;
        $details = [];

        foreach ($results as $r) {
            $missedAttendance = empty($r['has_attendance']);
            $missedTerm       = empty($r['has_term_paper']);

            // Rule: skipped term paper AND/OR attendance
            if ($missedAttendance || $missedTerm) {
                $missedCount++;
                $details[] = [
                    'conclave'         => $r['title'] ?? "Seq {$r['sequence']}",
                    'missed_attendance'=> $missedAttendance,
                    'missed_term_paper'=> $missedTerm
                ];
            }
        }

        if ($missedCount >= 2) {
            $candidates[] = [
                'student_id'     => (int)$student['id'],
                'full_name'      => trim($student['first_name'] . ' ' . $student['last_name']),
                'phone'          => $student['phone'],
                'current_status' => $student['status'],
                'zone_name'      => $student['zone_name'],
                'missed_details' => $details,
                'recommendation' => $student['status'] === 'probation' 
                    ? 'Already on probation – consider inactive/withdrawn' 
                    : 'Recommend set to probation'
            ];
        }
    }

    return $candidates;
}

/**
 * Build human-readable SMS for status changes
 */
function buildStatusSms(string $name, string $status, string $zoneName): string {
    $app = APP_NAME;
    switch ($status) {
        case 'probation':
            return "Dear {$name}, you have been placed on PROBATION at {$app} ({$zoneName}) due to consecutive missed assessments/attendance. Please contact the administration.";
        case 'inactive':
            return "Dear {$name}, your student status at {$app} ({$zoneName}) has been set to INACTIVE. Contact admin for clarification.";
        case 'withdrawn':
            return "Dear {$name}, you have been marked as WITHDRAWN from {$app} ({$zoneName}). We wish you the best.";
        case 'active':
            return "Dear {$name}, your status at {$app} ({$zoneName}) has been restored to ACTIVE. Welcome back.";
        case 'graduated':
            return "Dear {$name}, congratulations! You have been marked as GRADUATED from {$app} ({$zoneName}).";
        default:
            return "Dear {$name}, your status at {$app} has been updated to {$status}.";
    }
}

/**
 * Log SMS (real gateway can be plugged in later)
 */
function logAndSendSms(string $phone, string $message, string $purpose, ?int $studentId = null): void {
    try {
        $pdo = db();
        $status = SMS_ENABLED ? 'pending' : 'sent'; // when real SMS is enabled, set pending then update after send

        $stmt = $pdo->prepare("
            INSERT INTO sms_logs (recipient_phone, message, purpose, related_student_id, status, sent_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$phone, $message, $purpose, $studentId, $status]);

        // Placeholder for real SMS gateway call
        // if (SMS_ENABLED) { sendViaGateway($phone, $message); }
    } catch (Exception $e) {
        error_log('SMS log failed: ' . $e->getMessage());
    }
}
