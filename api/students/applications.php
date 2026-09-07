<?php
/**
 * Admin – Manage Applications
 *
 * GET    /api/students/applications.php
 *        ?status=pending|admitted|rejected  (optional)
 *        ?zone_id=X                        (optional, super_admin)
 *        ?search=name_or_phone             (optional)
 *        ?id=12                            → single application
 *
 * POST   /api/students/applications.php
 * Body:  { "application_id": 12, "action": "admit"|"reject", "notes": "optional" }
 *
 * PUT    /api/students/applications.php
 * Body:  { "application_id": 12, "first_name": "...", "phone": "...", ... }
 *        Updates linked student profile (+ optional review_notes on the application).
 *
 * DELETE /api/students/applications.php
 * Body:  { "application_id": 12, "delete_student": true|false }
 *        Deletes the application. If delete_student is true and student is still
 *        an applicant with no other applications, the student record is removed too.
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

$admin = requireAdmin(['super_admin', 'admin']);
$pdo   = db();
$method = $_SERVER['REQUEST_METHOD'];

// ---------- GET: List / single application ----------
if ($method === 'GET') {
    $status = $_GET['status'] ?? null;
    $zoneId = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : null;
    $search = trim($_GET['search'] ?? '');
    $appId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null) {
        $zoneId = $admin['zone_id'];
    } elseif ($zoneId === null && !empty($admin['current_zone_id'])) {
        $zoneId = $admin['current_zone_id'];
    }

    $sql = "
        SELECT 
            a.id AS application_id,
            a.status AS application_status,
            a.application_year,
            a.acknowledgment_sms_sent,
            a.admission_sms_sent,
            a.review_notes,
            a.reviewed_at,
            a.created_at AS applied_at,
            s.id AS student_id,
            s.phone,
            s.email,
            s.first_name,
            s.last_name,
            s.other_names,
            s.gender,
            s.date_of_birth,
            s.address,
            s.state_of_origin,
            s.lga,
            s.next_of_kin_name,
            s.next_of_kin_phone,
            s.status AS student_status,
            z.id AS zone_id,
            z.name AS zone_name,
            z.code AS zone_code
        FROM applications a
        JOIN students s ON s.id = a.student_id
        JOIN zones z ON z.id = a.zone_id
        WHERE 1=1
    ";
    $params = [];

    if ($appId > 0) {
        $sql .= " AND a.id = ?";
        $params[] = $appId;
    }
    if ($status && in_array($status, ['pending','admitted','rejected','withdrawn'], true)) {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }
    if ($zoneId) {
        $sql .= " AND a.zone_id = ?";
        $params[] = $zoneId;
    }
    if ($search !== '') {
        $sql .= " AND (
            s.phone LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?
            OR CONCAT(s.first_name,' ',s.last_name) LIKE ?
        )";
        $term = "%{$search}%";
        $params = array_merge($params, [$term, $term, $term, $term]);
    }

    $sql .= " ORDER BY a.created_at DESC LIMIT 300";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($appId > 0) {
        if (!$rows) jsonError('Application not found', 404);
        $row = $rows[0];
        if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $admin['zone_id'] != $row['zone_id']) {
            jsonError('Access denied', 403);
        }
        jsonSuccess($row);
    }

    jsonSuccess($rows);
}

// ---------- POST: Admit or Reject ----------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $appId  = (int)($input['application_id'] ?? 0);
    $action = strtolower(trim($input['action'] ?? ''));
    $notes  = trim($input['notes'] ?? '') ?: null;

    if ($appId <= 0 || !in_array($action, ['admit', 'reject'])) {
        jsonError('application_id and action (admit|reject) are required');
    }

    // Fetch application + student
    $stmt = $pdo->prepare("
        SELECT a.*, s.first_name, s.last_name, s.phone, s.zone_id AS student_zone_id
        FROM applications a
        JOIN students s ON s.id = a.student_id
        WHERE a.id = ?
    ");
    $stmt->execute([$appId]);
    $app = $stmt->fetch();

    if (!$app) {
        jsonError('Application not found', 404);
    }

    if ($app['status'] !== 'pending') {
        jsonError('This application has already been reviewed');
    }

    // Zone access check for regular admin
    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $admin['zone_id'] != $app['zone_id']) {
        jsonError('You do not have permission to manage applications in this zone', 403);
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'admit') {
            $zoneId = (int)$app['zone_id'];

            // Sets are global across all zones. Admissions only attach to the
            // current junior set — opening/graduating sets is super_admin only.
            $active = getActiveSets($pdo);
            $junior = null;
            foreach ($active as $s) {
                if ($s['status'] === 'active_junior') {
                    $junior = $s;
                    break;
                }
            }
            // Single active set with no junior label still accepts admissions
            if (!$junior && count($active) === 1) {
                $junior = $active[0];
            }
            if (!$junior) {
                $pdo->rollBack();
                jsonError('No active junior set. A super admin must open a set from Admin → Sets before admissions can proceed.');
            }
            $setNumber = (int)$junior['set_number'];
            $regNo = generateRegNo($pdo, $zoneId, $setNumber);

            // Update application
            $stmt = $pdo->prepare("
                UPDATE applications 
                SET status = 'admitted', reviewed_by = ?, review_notes = ?, reviewed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$admin['id'], $notes, $appId]);

            // Update student with set + reg no
            $stmt = $pdo->prepare("
                UPDATE students 
                SET status = 'admitted',
                    admission_date = NOW(),
                    admission_year = YEAR(NOW()),
                    set_number = ?,
                    reg_no = ?,
                    current_conclave = 0
                WHERE id = ?
            ");
            $stmt->execute([$setNumber, $regNo, $app['student_id']]);

            // Admission SMS (stub)
            $msg = "Dear {$app['first_name']}, congratulations! You have been admitted to " . APP_NAME .
                   " (Set {$setNumber}, Reg. No. {$regNo}). Further details will follow. Welcome!";
            logSms($app['phone'], $msg, 'admission', (int)$app['student_id']);

            $pdo->prepare("UPDATE applications SET admission_sms_sent = 1 WHERE id = ?")
                ->execute([$appId]);

            $pdo->commit();
            jsonSuccess([
                'set_number' => $setNumber,
                'reg_no'     => $regNo
            ], 'Student admitted successfully. Admission SMS queued.');

        } else { // reject
            $stmt = $pdo->prepare("
                UPDATE applications 
                SET status = 'rejected', reviewed_by = ?, review_notes = ?, reviewed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$admin['id'], $notes, $appId]);

            // Keep student status as applicant or move to a rejected state if desired.
            // For now we leave student status as 'applicant' but application is rejected.
            // Optionally: UPDATE students SET status = 'inactive' ...

            $pdo->commit();
            jsonSuccess(null, 'Application rejected.');
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Application review error: ' . $e->getMessage());
        jsonError('Failed to process application', 500);
    }
}

// ---------- PUT: Edit application / linked student profile ----------
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $appId = (int)($input['application_id'] ?? 0);
    if ($appId <= 0) {
        jsonError('application_id is required');
    }

    $stmt = $pdo->prepare("
        SELECT a.*, s.id AS student_id, s.phone AS student_phone, s.zone_id AS student_zone_id,
               s.first_name, s.last_name, s.other_names, s.email, s.gender,
               s.date_of_birth, s.address, s.state_of_origin, s.lga,
               s.next_of_kin_name, s.next_of_kin_phone, s.status AS student_status
        FROM applications a
        JOIN students s ON s.id = a.student_id
        WHERE a.id = ?
    ");
    $stmt->execute([$appId]);
    $app = $stmt->fetch();
    if (!$app) {
        jsonError('Application not found', 404);
    }

    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $admin['zone_id'] != $app['zone_id']) {
        jsonError('You do not have permission for this application', 403);
    }

    $studentFields = [
        'first_name', 'last_name', 'other_names', 'phone', 'email', 'gender',
        'date_of_birth', 'address', 'state_of_origin', 'lga',
        'next_of_kin_name', 'next_of_kin_phone'
    ];

    $sets = [];
    $params = [];
    $oldSnap = [];
    $newSnap = [];

    foreach ($studentFields as $key) {
        if (!array_key_exists($key, $input)) continue;
        $val = $input[$key];
        $oldSnap[$key] = $app[$key] ?? ($key === 'phone' ? $app['student_phone'] : null);

        if (in_array($key, ['first_name', 'last_name', 'phone'], true)) {
            $val = trim((string)$val);
            if ($val === '') {
                jsonError(ucfirst(str_replace('_', ' ', $key)) . ' is required');
            }
        } else {
            $val = trim((string)($val ?? ''));
            $val = $val === '' ? null : $val;
        }

        if ($key === 'gender' && $val !== null && !in_array($val, ['male', 'female', 'other'], true)) {
            jsonError('Invalid gender');
        }
        if ($key === 'date_of_birth' && $val !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            jsonError('Invalid date of birth (use YYYY-MM-DD)');
        }

        $col = $key === 'phone' ? 'phone' : $key;
        $sets[] = "`$col` = ?";
        $params[] = $val;
        $newSnap[$key] = $val;
    }

    $reviewNotes = array_key_exists('review_notes', $input)
        ? (trim((string)$input['review_notes']) ?: null)
        : null;
    $updateNotes = array_key_exists('review_notes', $input);

    if (empty($sets) && !$updateNotes) {
        jsonError('No fields to update');
    }

    // Phone uniqueness in zone
    if (isset($newSnap['phone'])) {
        $chk = $pdo->prepare("SELECT id FROM students WHERE phone = ? AND zone_id = ? AND id <> ?");
        $chk->execute([$newSnap['phone'], $app['zone_id'], $app['student_id']]);
        if ($chk->fetch()) {
            jsonError('Another student in this zone already uses that phone number');
        }
    }

    try {
        $pdo->beginTransaction();

        if (!empty($sets)) {
            $params[] = $app['student_id'];
            $pdo->prepare(
                "UPDATE students SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ?"
            )->execute($params);
        }

        if ($updateNotes) {
            $pdo->prepare("UPDATE applications SET review_notes = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$reviewNotes, $appId]);
            $oldSnap['review_notes'] = $app['review_notes'];
            $newSnap['review_notes'] = $reviewNotes;
        }

        $pdo->prepare("
            INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_values, new_values)
            VALUES (?, 'application_update', 'application', ?, ?, ?)
        ")->execute([
            $admin['id'],
            $appId,
            json_encode($oldSnap),
            json_encode($newSnap)
        ]);

        $pdo->commit();
        jsonSuccess(null, 'Application / applicant updated');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Application update error: ' . $e->getMessage());
        jsonError('Failed to update application', 500);
    }
}

// ---------- DELETE: Remove application (optionally applicant) ----------
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $appId = (int)($input['application_id'] ?? $_GET['id'] ?? 0);
    $deleteStudent = !empty($input['delete_student']);

    if ($appId <= 0) {
        jsonError('application_id is required');
    }

    $stmt = $pdo->prepare("
        SELECT a.*, s.status AS student_status, s.first_name, s.last_name, s.phone
        FROM applications a
        JOIN students s ON s.id = a.student_id
        WHERE a.id = ?
    ");
    $stmt->execute([$appId]);
    $app = $stmt->fetch();
    if (!$app) {
        jsonError('Application not found', 404);
    }

    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $admin['zone_id'] != $app['zone_id']) {
        jsonError('You do not have permission for this application', 403);
    }

    // Non-pending applications: only super_admin may delete
    if ($app['status'] !== 'pending' && $admin['role'] !== 'super_admin') {
        jsonError('Only pending applications can be deleted by zone admins. Ask a super admin for admitted/rejected records.');
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_values, new_values)
            VALUES (?, 'application_delete', 'application', ?, ?, NULL)
        ")->execute([
            $admin['id'],
            $appId,
            json_encode([
                'status' => $app['status'],
                'student_id' => $app['student_id'],
                'name' => $app['first_name'] . ' ' . $app['last_name'],
                'phone' => $app['phone'],
                'delete_student' => $deleteStudent,
            ])
        ]);

        $studentId = (int)$app['student_id'];
        $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$appId]);

        $studentDeleted = false;
        if ($deleteStudent) {
            // Only remove student if still applicant and no other applications remain
            $left = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE student_id = ?");
            $left->execute([$studentId]);
            $remaining = (int)$left->fetchColumn();

            $st = $pdo->prepare("SELECT status FROM students WHERE id = ?");
            $st->execute([$studentId]);
            $stRow = $st->fetch();

            if ($remaining === 0 && $stRow && $stRow['status'] === 'applicant') {
                $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$studentId]);
                $studentDeleted = true;
            }
        }

        $pdo->commit();
        jsonSuccess([
            'student_deleted' => $studentDeleted
        ], $studentDeleted ? 'Application and applicant deleted' : 'Application deleted');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Application delete error: ' . $e->getMessage());
        jsonError('Failed to delete application', 500);
    }
}

jsonError('Method not allowed', 405);

/**
 * SMS logger (shared with register)
 */
function logSms(string $phone, string $message, string $purpose, ?int $studentId = null): void {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO sms_logs (recipient_phone, message, purpose, related_student_id, status, sent_at)
            VALUES (?, ?, ?, ?, 'sent', NOW())
        ");
        $stmt->execute([$phone, $message, $purpose, $studentId]);
    } catch (Exception $e) {
        error_log('SMS log failed: ' . $e->getMessage());
    }
}
