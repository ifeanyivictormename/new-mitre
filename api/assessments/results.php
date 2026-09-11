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
require_once __DIR__ . '/../includes/helpers.php';

startSession();
$user = currentUser();
if (!$user) {
    jsonError('Authentication required', 401);
}

$pdo = db();
$conclaveId = (int)($_GET['conclave_id'] ?? 0);
$studentId  = (int)($_GET['student_id'] ?? 0);
$zoneId     = (int)($_GET['zone_id'] ?? 0);

function assertResultWriteAllowed(PDO $pdo, array $user, int $studentId): void {
    if (($user['type'] ?? '') !== 'admin') {
        jsonError('Admin access required', 403);
    }

    $s = $pdo->prepare('SELECT zone_id FROM students WHERE id = ? LIMIT 1');
    $s->execute([$studentId]);
    $studentZoneId = $s->fetchColumn();

    if ($studentZoneId === false) {
        jsonError('Student not found', 404);
    }

    if (($user['role'] ?? '') === 'admin' && !empty($user['zone_id']) && (int)$user['zone_id'] !== (int)$studentZoneId) {
        jsonError('You can only modify records in your zone', 403);
    }
}

function parseScoreField(string $label, $raw, float $min, float $max): float {
    if (!is_numeric($raw)) {
        jsonError("{$label} must be a valid number");
    }
    $value = (float)$raw;
    if ($value < $min || $value > $max) {
        jsonError("{$label} must be between {$min} and {$max}");
    }
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $studentIdPost = (int)($input['student_id'] ?? 0);
    $conclaveIdPost = (int)($input['conclave_id'] ?? 0);

    if ($studentIdPost <= 0 || $conclaveIdPost <= 0) {
        jsonError('student_id and conclave_id are required');
    }

    assertResultWriteAllowed($pdo, $user, $studentIdPost);

    if ($action === 'update') {
        $attendance = parseScoreField('Attendance score', $input['attendance_score'] ?? null, 0, (float)WEIGHT_ATTENDANCE);
        $summary = parseScoreField('Summary score', $input['summary_score'] ?? null, 0, (float)WEIGHT_SUMMARY);
        $short = parseScoreField('Short paper score', $input['short_paper_score'] ?? null, 0, (float)WEIGHT_SHORT_PAPER);
        $long = parseScoreField('Long paper score', $input['long_paper_score'] ?? null, 0, (float)WEIGHT_LONG_PAPER);
        $term = parseScoreField('Term paper score', $input['term_paper_score'] ?? null, 0, (float)WEIGHT_TERM_PAPER);
        $oversight = parseScoreField('Oversight score', $input['oversight_score'] ?? null, 0, (float)WEIGHT_OVERSIGHT);

        $stmt = $pdo->prepare('SELECT id FROM conclaves WHERE id = ? LIMIT 1');
        $stmt->execute([$conclaveIdPost]);
        if (!$stmt->fetchColumn()) {
            jsonError('Conclave not found', 404);
        }

        try {
            $pdo->beginTransaction();

            $upsert = $pdo->prepare("\n                INSERT INTO assessments (student_id, conclave_id, type, score, max_score, source_conclave_id, recorded_by, submitted_at)\n                VALUES (?, ?, ?, ?, ?, NULL, ?, NOW())\n                ON DUPLICATE KEY UPDATE\n                    score = VALUES(score),\n                    max_score = VALUES(max_score),\n                    recorded_by = VALUES(recorded_by),\n                    submitted_at = NOW()\n            ");
            $upsert->execute([$studentIdPost, $conclaveIdPost, 'summary', $summary, WEIGHT_SUMMARY, $user['id']]);
            $upsert->execute([$studentIdPost, $conclaveIdPost, 'short_paper', $short, WEIGHT_SHORT_PAPER, $user['id']]);
            $upsert->execute([$studentIdPost, $conclaveIdPost, 'long_paper', $long, WEIGHT_LONG_PAPER, $user['id']]);
            $upsert->execute([$studentIdPost, $conclaveIdPost, 'term_paper', $term, WEIGHT_TERM_PAPER, $user['id']]);

            $total = calculateTotalScore([
                'attendance_score' => $attendance,
                'summary_score' => $summary,
                'short_paper_score' => $short,
                'long_paper_score' => $long,
                'term_paper_score' => $term,
                'oversight_score' => $oversight,
            ]);

            $resStmt = $pdo->prepare("\n                INSERT INTO conclave_results (\n                    student_id, conclave_id,\n                    attendance_score, summary_score, short_paper_score, long_paper_score,\n                    term_paper_score, oversight_score, total_score,\n                    has_attendance, has_term_paper, computed_at\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n                ON DUPLICATE KEY UPDATE\n                    attendance_score = VALUES(attendance_score),\n                    summary_score = VALUES(summary_score),\n                    short_paper_score = VALUES(short_paper_score),\n                    long_paper_score = VALUES(long_paper_score),\n                    term_paper_score = VALUES(term_paper_score),\n                    oversight_score = VALUES(oversight_score),\n                    total_score = VALUES(total_score),\n                    has_attendance = VALUES(has_attendance),\n                    has_term_paper = VALUES(has_term_paper),\n                    computed_at = NOW()\n            ");
            $resStmt->execute([
                $studentIdPost,
                $conclaveIdPost,
                $attendance,
                $summary,
                $short,
                $long,
                $term,
                $oversight,
                $total,
                $attendance > 0 ? 1 : 0,
                $term > 0 ? 1 : 0,
            ]);

            $pdo->commit();
            jsonSuccess(['total_score' => $total], 'Result updated');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Result update failed: ' . $e->getMessage());
            jsonError('Failed to update result', 500);
        }
    }

    if ($action === 'delete') {
        try {
            $pdo->beginTransaction();
            $delA = $pdo->prepare('DELETE FROM assessments WHERE student_id = ? AND conclave_id = ?');
            $delA->execute([$studentIdPost, $conclaveIdPost]);

            $delR = $pdo->prepare('DELETE FROM conclave_results WHERE student_id = ? AND conclave_id = ?');
            $delR->execute([$studentIdPost, $conclaveIdPost]);

            $pdo->commit();
            jsonSuccess([
                'deleted_assessments' => $delA->rowCount(),
                'deleted_results' => $delR->rowCount(),
            ], 'Result deleted');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Result delete failed: ' . $e->getMessage());
            jsonError('Failed to delete result', 500);
        }
    }

    jsonError('Invalid action', 400);
}

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
