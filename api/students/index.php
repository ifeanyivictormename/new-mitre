<?php
/**
 * Admin – List / View / Edit / Delete Students
 *
 * GET    /api/students/index.php
 *        ?status=active|probation|admitted|...
 *        ?zone_id=X
 *        ?search=phone_or_name
 *        ?id=123                 → single student
 *
 * PUT    /api/students/index.php
 * Body:  { "id": 12, "first_name": "...", ... }  (editable fields only)
 *
 * DELETE /api/students/index.php
 * Body:  { "id": 12 }
 *        Hard-deletes the student. Cascades attendance, assessments, results,
 *        applications. Use with care. Prefer status → withdrawn/inactive when possible.
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$admin  = requireAdmin(['super_admin', 'admin']);
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

// ---------- GET ----------
if ($method === 'GET') {
    // Single student by id
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("
            SELECT s.*, z.name AS zone_name, z.code AS zone_code
            FROM students s
            JOIN zones z ON z.id = s.zone_id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $student = $stmt->fetch();

        if (!$student) {
            jsonError('Student not found', 404);
        }

        if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $admin['zone_id'] != $student['zone_id']) {
            jsonError('Access denied to this student', 403);
        }

        jsonSuccess($student);
    }

    // List students
    $status  = $_GET['status'] ?? null;
    $statusesCsv = trim($_GET['statuses'] ?? '');
    $statusList = [];
    $zoneId  = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : null;
    $search  = trim($_GET['search'] ?? '');

    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null) {
        $zoneId = $admin['zone_id'];
    } elseif ($zoneId === null && !empty($admin['current_zone_id'])) {
        $zoneId = $admin['current_zone_id'];
    }

    $isCountOnly = !empty($_GET['count']);

    $sql = "
        SELECT 
            s.id, s.phone, s.email, s.first_name, s.last_name, s.other_names,
            s.gender, s.date_of_birth, s.address, s.state_of_origin, s.lga,
            s.status, s.current_conclave, s.admission_year,
            s.set_number, s.reg_no,
            s.application_date, s.admission_date,
            z.id AS zone_id, z.name AS zone_name, z.code AS zone_code
        FROM students s
        JOIN zones z ON z.id = s.zone_id
        WHERE 1=1
    ";
    $params = [];

    if ($statusesCsv !== '') {
        $parts = array_filter(array_map('trim', explode(',', $statusesCsv)));
        $allowedStatuses = ['applicant', 'admitted', 'active', 'probation', 'inactive', 'withdrawn', 'graduated'];
        foreach ($parts as $p) {
            $p = strtolower($p);
            if (in_array($p, $allowedStatuses, true)) {
                $statusList[] = $p;
            }
        }
        $statusList = array_values(array_unique($statusList));
    }

    if (!empty($statusList)) {
        $placeholders = implode(',', array_fill(0, count($statusList), '?'));
        $sql .= " AND s.status IN ($placeholders)";
        $params = array_merge($params, $statusList);
    } elseif ($status) {
        $sql .= " AND s.status = ?";
        $params[] = $status;
    }
    if ($zoneId) {
        $sql .= " AND s.zone_id = ?";
        $params[] = $zoneId;
    }
    if ($search !== '') {
        $sql .= " AND (
            s.phone LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?
            OR CONCAT(s.first_name,' ',s.last_name) LIKE ?
            OR IFNULL(s.reg_no,'') LIKE ?
        )";
        $term = "%{$search}%";
        $params = array_merge($params, [$term, $term, $term, $term, $term]);
    }

    if ($isCountOnly) {
        $countSql = preg_replace('/^\s*SELECT\s+.*?\s+FROM\s+students\s+s/si', 'SELECT COUNT(*) AS total FROM students s', $sql, 1);
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        jsonSuccess(['count' => $total]);
    }

    $sql .= " ORDER BY s.created_at DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    jsonSuccess($students);
}

// ---------- PUT: Update student ----------
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        jsonError('Student id is required');
    }

    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    if (!$student) {
        jsonError('Student not found', 404);
    }

    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $admin['zone_id'] != $student['zone_id']) {
        jsonError('Access denied to this student', 403);
    }

    // Editable fields
    $fields = [
        'first_name'        => 'string',
        'last_name'         => 'string',
        'other_names'       => 'string_null',
        'phone'             => 'string',
        'email'             => 'string_null',
        'gender'            => 'gender',
        'date_of_birth'     => 'date_null',
        'address'           => 'string_null',
        'state_of_origin'   => 'string_null',
        'lga'               => 'string_null',
        'reg_no'            => 'string_null',
        'set_number'        => 'int_null',
        'current_conclave'  => 'int_conclave',
        'status'            => 'status',
        'zone_id'           => 'zone',
    ];

    $sets = [];
    $params = [];
    $oldSnapshot = [];
    $newSnapshot = [];

    foreach ($fields as $key => $type) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $val = $input[$key];
        $oldSnapshot[$key] = $student[$key] ?? null;

        if ($type === 'string') {
            $val = trim((string)$val);
            if ($val === '') {
                jsonError(ucfirst(str_replace('_', ' ', $key)) . ' is required');
            }
        } elseif ($type === 'string_null') {
            $val = trim((string)($val ?? ''));
            $val = $val === '' ? null : $val;
        } elseif ($type === 'gender') {
            $val = $val === '' || $val === null ? null : strtolower(trim((string)$val));
            if ($val !== null && !in_array($val, ['male', 'female', 'other'], true)) {
                jsonError('Invalid gender');
            }
        } elseif ($type === 'date_null') {
            $val = trim((string)($val ?? ''));
            $val = $val === '' ? null : $val;
            if ($val !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                jsonError('Invalid date of birth (use YYYY-MM-DD)');
            }
        } elseif ($type === 'int_null') {
            if ($val === '' || $val === null) {
                $val = null;
            } else {
                $val = (int)$val;
                if ($val < 0) jsonError('Invalid set number');
            }
        } elseif ($type === 'int_conclave') {
            $val = (int)$val;
            if ($val < 0 || $val > 6) {
                jsonError('Current conclave must be 0–6');
            }
        } elseif ($type === 'status') {
            $val = strtolower(trim((string)$val));
            $allowed = ['applicant', 'admitted', 'active', 'probation', 'inactive', 'withdrawn', 'graduated'];
            if (!in_array($val, $allowed, true)) {
                jsonError('Invalid status');
            }
        } elseif ($type === 'zone') {
            $val = (int)$val;
            if ($val <= 0) {
                jsonError('Invalid zone');
            }
            // Regular admin cannot move students to another zone
            if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $val != $admin['zone_id']) {
                jsonError('You cannot move a student to another zone', 403);
            }
            $z = $pdo->prepare("SELECT id FROM zones WHERE id = ?");
            $z->execute([$val]);
            if (!$z->fetch()) {
                jsonError('Zone not found', 404);
            }
        }

        $sets[] = "`$key` = ?";
        $params[] = $val;
        $newSnapshot[$key] = $val;
    }

    if (empty($sets)) {
        jsonError('No fields to update');
    }

    // Phone uniqueness within zone
    if (isset($newSnapshot['phone']) || isset($newSnapshot['zone_id'])) {
        $phone = $newSnapshot['phone'] ?? $student['phone'];
        $zoneId = $newSnapshot['zone_id'] ?? $student['zone_id'];
        $chk = $pdo->prepare("SELECT id FROM students WHERE phone = ? AND zone_id = ? AND id <> ?");
        $chk->execute([$phone, $zoneId, $id]);
        if ($chk->fetch()) {
            jsonError('Another student in this zone already uses that phone number');
        }
    }

    // Reg no uniqueness within zone
    if (array_key_exists('reg_no', $newSnapshot) && $newSnapshot['reg_no'] !== null) {
        $zoneId = $newSnapshot['zone_id'] ?? $student['zone_id'];
        $chk = $pdo->prepare("SELECT id FROM students WHERE reg_no = ? AND zone_id = ? AND id <> ?");
        $chk->execute([$newSnapshot['reg_no'], $zoneId, $id]);
        if ($chk->fetch()) {
            jsonError('Registration number already in use in this zone');
        }
    }

    try {
        $pdo->beginTransaction();

        $params[] = $id;
        $sql = "UPDATE students SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ?";
        $pdo->prepare($sql)->execute($params);

        // Audit
        $pdo->prepare("
            INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_values, new_values)
            VALUES (?, 'student_update', 'student', ?, ?, ?)
        ")->execute([
            $admin['id'],
            $id,
            json_encode($oldSnapshot),
            json_encode($newSnapshot)
        ]);

        $pdo->commit();

        $stmt = $pdo->prepare("
            SELECT s.*, z.name AS zone_name, z.code AS zone_code
            FROM students s JOIN zones z ON z.id = s.zone_id WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        jsonSuccess($stmt->fetch(), 'Student updated');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Student update error: ' . $e->getMessage());
        jsonError('Failed to update student', 500);
    }
}

// ---------- DELETE: Hard-delete student ----------
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonError('Student id is required');
    }

    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    if (!$student) {
        jsonError('Student not found', 404);
    }

    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $admin['zone_id'] != $student['zone_id']) {
        jsonError('Access denied to this student', 403);
    }

    // Guard: only allow hard delete for applicant / withdrawn / inactive unless super_admin
    $safeStatuses = ['applicant', 'withdrawn', 'inactive'];
    if (!in_array($student['status'], $safeStatuses, true) && $admin['role'] !== 'super_admin') {
        jsonError('Only applicants, withdrawn or inactive students can be deleted by zone admins. Change status first, or ask a super admin.');
    }

    try {
        $pdo->beginTransaction();

        // Snapshot for audit before cascade
        $pdo->prepare("
            INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, old_values, new_values)
            VALUES (?, 'student_delete', 'student', ?, ?, NULL)
        ")->execute([
            $admin['id'],
            $id,
            json_encode([
                'first_name' => $student['first_name'],
                'last_name'  => $student['last_name'],
                'phone'      => $student['phone'],
                'reg_no'     => $student['reg_no'],
                'status'     => $student['status'],
                'zone_id'    => $student['zone_id'],
            ])
        ]);

        // Cascades via FKs: applications, attendance, assessments, results
        $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);

        $pdo->commit();
        jsonSuccess(null, 'Student deleted');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Student delete error: ' . $e->getMessage());
        jsonError('Failed to delete student. Related records may block deletion.', 500);
    }
}

jsonError('Method not allowed', 405);
