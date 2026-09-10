<?php
/**
 * Assessments & Result Computation Engine
 *
 * GET  /api/assessments/index.php?conclave_id=X&student_id=Y
 * POST /api/assessments/index.php
 *      Body examples:
 *      // Record in-conclave papers
 *      {
 *        "conclave_id": 5,
 *        "student_id": 12,
 *        "summary": 4.5,
 *        "short_paper": 5,
 *        "long_paper": 8.5,
 *        "oversight": 5
 *      }
 *
 *      // Record term paper (take-home from previous conclave)
 *      {
 *        "conclave_id": 5,          // the conclave this score belongs to
 *        "student_id": 12,
 *        "term_paper": 27,
 *        "source_conclave_id": 4    // the conclave it was assigned from
 *      }
 *
 * POST /api/assessments/compute.php  (or action=compute)
 *      Body: { "conclave_id": 5, "student_id": 12 }  or just conclave_id to compute all
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
        SELECT a.*, s.first_name, s.last_name
        FROM assessments a
        JOIN students s ON s.id = a.student_id
        WHERE a.conclave_id = ?
    ";
    $params = [$conclaveId];
    if ($studentId > 0) {
        $sql .= " AND a.student_id = ?";
        $params[] = $studentId;
    }
    $sql .= " ORDER BY s.last_name, a.type";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonSuccess($stmt->fetchAll());
}

// ---------- POST ----------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $conclaveId = (int)($input['conclave_id'] ?? 0);
    $studentId  = (int)($input['student_id'] ?? 0);

    if ($conclaveId <= 0) {
        jsonError('conclave_id is required');
    }

    // Validate conclave is OPEN (status only — not dates)
    $stmt = $pdo->prepare("SELECT id, sequence, status FROM conclaves WHERE id = ?");
    $stmt->execute([$conclaveId]);
    $conclave = $stmt->fetch();
    if (!$conclave) jsonError('Conclave not found', 404);

    $cStatus = strtolower((string)$conclave['status']);
    $isOpen = in_array($cStatus, ['open', 'ongoing', 'planned'], true);
    if (!$isOpen) {
        jsonError('This conclave is closed. Re-open it to record or edit assessments.', 403);
    }

    // Special action: compute results
    if (($input['action'] ?? '') === 'compute') {
        handleCompute($pdo, $admin, $input);
        exit;
    }

    if ($studentId <= 0) {
        jsonError('student_id is required');
    }

    $stmt = $pdo->prepare("SELECT id, zone_id FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        jsonError('Student not found', 404);
    }

    $validateScore = function (string $label, $raw, float $max): ?float {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            jsonError("{$label} must be a valid number");
        }
        $score = (float)$raw;
        if ($score < 0 || $score > $max) {
            jsonError("{$label} must be between 0 and {$max}");
        }
        return $score;
    };

    $summaryScore = $validateScore('Summary score', $input['summary'] ?? null, (float)WEIGHT_SUMMARY);
    $shortScore = $validateScore('Short paper score', $input['short_paper'] ?? null, (float)WEIGHT_SHORT_PAPER);
    $longScore = $validateScore('Long paper score', $input['long_paper'] ?? null, (float)WEIGHT_LONG_PAPER);
    $termScore = $validateScore('Term paper score', $input['term_paper'] ?? null, (float)WEIGHT_TERM_PAPER);
    $oversightScore = $validateScore('Oversight score', $input['oversight'] ?? null, (float)WEIGHT_OVERSIGHT);

    try {
        $pdo->beginTransaction();

        // Helper to upsert one assessment type
        $upsert = function(string $type, float $score, float $max, ?int $sourceId = null) use ($pdo, $studentId, $conclaveId, $admin) {
            $stmt = $pdo->prepare("
                INSERT INTO assessments (student_id, conclave_id, type, score, max_score, source_conclave_id, recorded_by, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    score = VALUES(score),
                    max_score = VALUES(max_score),
                    source_conclave_id = VALUES(source_conclave_id),
                    recorded_by = VALUES(recorded_by),
                    submitted_at = NOW()
            ");
            $stmt->execute([$studentId, $conclaveId, $type, $score, $max, $sourceId, $admin['id']]);
        };

        // In-conclave assessments
        if ($summaryScore !== null) {
            $upsert('summary', $summaryScore, WEIGHT_SUMMARY);
        }
        if ($shortScore !== null) {
            $upsert('short_paper', $shortScore, WEIGHT_SHORT_PAPER);
        }
        if ($longScore !== null) {
            $upsert('long_paper', $longScore, WEIGHT_LONG_PAPER);
        }

        // Term paper (take-home)
        if ($termScore !== null) {
            $sourceId = isset($input['source_conclave_id']) ? (int)$input['source_conclave_id'] : null;
            $upsert('term_paper', $termScore, WEIGHT_TERM_PAPER, $sourceId);
        }

        // Oversight is stored in conclave_results, not assessments table
        // We handle it during compute

        $pdo->commit();

        // Auto-compute after saving
        computeResult($pdo, $studentId, $conclaveId, $oversightScore);

        jsonSuccess(null, 'Assessments saved and result computed');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Assessment error: ' . $e->getMessage());
        jsonError('Failed to save assessments', 500);
    }
}

jsonError('Method not allowed', 405);

/**
 * Batch compute for a whole conclave or single student
 * (computeResult() itself now lives in includes/helpers.php so attendance
 * saves can also trigger recomputation)
 */
function handleCompute(PDO $pdo, array $admin, array $input): void {
    $conclaveId = (int)($input['conclave_id'] ?? 0);
    $studentId  = (int)($input['student_id'] ?? 0);

    if ($conclaveId <= 0) jsonError('conclave_id is required');

    if ($studentId > 0) {
        computeResult($pdo, $studentId, $conclaveId, $input['oversight'] ?? null);
        jsonSuccess(null, 'Result computed for student');
    }

    // All students who have any attendance or assessment in this conclave
    $stmt = $pdo->prepare("
        SELECT DISTINCT student_id FROM (
            SELECT student_id FROM attendance WHERE conclave_id = ?
            UNION
            SELECT student_id FROM assessments WHERE conclave_id = ?
        ) t
    ");
    $stmt->execute([$conclaveId, $conclaveId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $sid) {
        computeResult($pdo, (int)$sid, $conclaveId);
    }

    jsonSuccess(['computed_count' => count($ids)], 'Results computed for conclave');
}
