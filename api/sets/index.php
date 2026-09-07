<?php
/**
 * Sets (cohorts) – GLOBAL Senior / Junior model
 * All zones share the same set sequence.
 *
 * GET  /api/sets/index.php              → list all sets (any admin)
 * GET  /api/sets/index.php?overview=1   → junior/senior overview (any admin)
 * POST /api/sets/index.php              → open a new set (super_admin only)
 * PUT  /api/sets/index.php?id=X         → update / graduate (super_admin only)
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/auth.php';

$admin  = requireAdmin(['super_admin', 'admin']);
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

function setsSchemaError(Throwable $e): void {
    $msg = $e->getMessage();
    if (stripos($msg, 'sets') !== false || stripos($msg, "doesn't exist") !== false || stripos($msg, 'Unknown column') !== false) {
        jsonError(
            'Sets feature requires a database update. Run sql/migration_sets.sql on your database, then refresh. Details: ' . $msg,
            500
        );
    }
    error_log('Sets API error: ' . $msg);
    jsonError('Sets operation failed: ' . $msg, 500);
}

function requireSuperAdmin(array $admin): void {
    if (($admin['role'] ?? '') !== 'super_admin') {
        jsonError('Only a super admin can open, update, or graduate sets (sets are global across all zones).', 403);
    }
}

// ---------- GET ----------
if ($method === 'GET') {
    try {
        if (!empty($_GET['overview'])) {
            $active = getActiveSets($pdo);
            $check  = canOpenNewSet($pdo);

            $junior = null;
            $senior = null;
            foreach ($active as $s) {
                if ($s['status'] === 'active_junior') $junior = $s;
                if ($s['status'] === 'active_senior') $senior = $s;
            }
            if (!$junior && !$senior && count($active) === 2) {
                $senior = $active[0];
                $junior = $active[1];
            } elseif (!$junior && count($active) === 1) {
                $junior = $active[0];
            }

            foreach ([&$junior, &$senior] as &$ref) {
                if (!$ref) continue;
                try {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS cnt,
                               MAX(current_conclave) AS max_conclave,
                               AVG(current_conclave) AS avg_conclave
                        FROM students
                        WHERE set_number = ?
                          AND status IN ('admitted','active','probation')
                    ");
                    $stmt->execute([$ref['set_number']]);
                    $stats = $stmt->fetch();
                    $maxConclave = $stats['max_conclave'] !== null ? (int)$stats['max_conclave'] : null;
                    $currentSeq = isset($ref['current_sequence']) ? (int)$ref['current_sequence'] : 0;
                    $ref['student_count'] = (int)$stats['cnt'];
                    $ref['max_conclave']  = $maxConclave;
                    $ref['effective_conclave'] = max($currentSeq, (int)($maxConclave ?? 0));
                    $ref['avg_conclave']  = round((float)$stats['avg_conclave'], 1);
                } catch (Throwable $e) {
                    $ref['student_count'] = 0;
                    $ref['max_conclave']  = (int)($ref['current_sequence'] ?? 0);
                    $ref['effective_conclave'] = (int)($ref['current_sequence'] ?? 0);
                    $ref['avg_conclave']  = 0;
                }
            }
            unset($ref);

            jsonSuccess([
                'junior'       => $junior,
                'senior'       => $senior,
                'can_open_new' => $check['allowed'],
                'next_set'     => $check['next_set'] ?? null,
                'reason'       => $check['reason'] ?? null,
                'is_super'     => ($admin['role'] === 'super_admin')
            ]);
        }

        $stmt = $pdo->query("
            SELECT s.*,
                   (SELECT COUNT(*) FROM students st
                    WHERE st.set_number = s.set_number
                      AND st.status IN ('admitted','active','probation','graduated')) AS student_count
            FROM sets s
            ORDER BY s.set_number DESC
        ");
        jsonSuccess($stmt->fetchAll());
    } catch (Throwable $e) {
        setsSchemaError($e);
    }
}

// ---------- POST: Open new set (super_admin only) ----------
if ($method === 'POST') {
    try {
        requireSuperAdmin($admin);

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        // Optional: force a specific starting set number (e.g. seed Set 18)
        $forceNumber = isset($input['set_number']) ? (int)$input['set_number'] : 0;

        if ($forceNumber > 0) {
            $stmt = $pdo->prepare("SELECT id FROM sets WHERE set_number = ?");
            $stmt->execute([$forceNumber]);
            if ($stmt->fetch()) {
                jsonError("Set {$forceNumber} already exists");
            }
            // If forcing while two actives exist, still block unless they are graduating
            $check = canOpenNewSet($pdo);
            if (!$check['allowed'] && empty($input['force'])) {
                jsonError($check['reason']);
            }
            promoteJuniorToSenior($pdo);
            $row = ensureSet($pdo, $forceNumber, 'active_junior');
            if (!empty($input['current_sequence'])) {
                $pdo->prepare("UPDATE sets SET current_sequence = ? WHERE id = ?")
                    ->execute([(int)$input['current_sequence'], $row['id']]);
                $row['current_sequence'] = (int)$input['current_sequence'];
            }
            jsonSuccess($row, "Set {$forceNumber} opened as junior set");
        }

        $check = canOpenNewSet($pdo);
        if (!$check['allowed']) {
            jsonError($check['reason']);
        }

        $next = (int)$check['next_set'];
        promoteJuniorToSenior($pdo);
        $row = ensureSet($pdo, $next, 'active_junior');

        jsonSuccess($row, "Set {$next} opened as junior set");
    } catch (Throwable $e) {
        setsSchemaError($e);
    }
}

// ---------- PUT: Update / graduate (super_admin only) ----------
if ($method === 'PUT') {
    try {
        requireSuperAdmin($admin);

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) jsonError('id is required');

        $stmt = $pdo->prepare("SELECT * FROM sets WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) jsonError('Set not found', 404);

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? null;

        if ($action === 'graduate') {
            graduateSet($pdo, (int)$existing['set_number']);

            if (!empty($input['graduate_students'])) {
                try {
                    $pdo->prepare("
                        UPDATE students
                        SET status = 'graduated', graduation_date = CURDATE()
                        WHERE set_number = ?
                          AND status IN ('admitted','active','probation')
                    ")->execute([$existing['set_number']]);
                } catch (Throwable $e) {
                    // ignore if column missing
                }
            }

            $stmt = $pdo->prepare("SELECT * FROM sets WHERE id = ?");
            $stmt->execute([$id]);
            jsonSuccess($stmt->fetch(), 'Set graduated successfully');
        }

        $status = $input['status'] ?? $existing['status'];
        $seq    = array_key_exists('current_sequence', $input) ? (int)$input['current_sequence'] : (int)$existing['current_sequence'];
        $notes  = array_key_exists('notes', $input) ? $input['notes'] : $existing['notes'];
        $setNum = array_key_exists('set_number', $input) ? (int)$input['set_number'] : (int)$existing['set_number'];
        $startedYear = array_key_exists('started_year', $input)
            ? ($input['started_year'] !== '' && $input['started_year'] !== null ? (int)$input['started_year'] : null)
            : $existing['started_year'];
        $graduatedAt = array_key_exists('graduated_at', $input)
            ? ($input['graduated_at'] !== '' && $input['graduated_at'] !== null ? $input['graduated_at'] : null)
            : $existing['graduated_at'];

        $allowed = ['planned','active_junior','active_senior','graduated'];
        if (!in_array($status, $allowed, true)) {
            jsonError('Invalid status');
        }
        if ($setNum <= 0) {
            jsonError('Invalid set_number');
        }
        if ($seq < 0 || $seq > 6) {
            jsonError('current_sequence must be between 0 and 6');
        }

        if ($setNum !== (int)$existing['set_number']) {
            $stmt = $pdo->prepare("SELECT id FROM sets WHERE set_number = ? AND id <> ?");
            $stmt->execute([$setNum, $id]);
            if ($stmt->fetch()) {
                jsonError("Set {$setNum} already exists");
            }
        }

        if ($status === 'graduated' && empty($graduatedAt)) {
            $graduatedAt = date('Y-m-d');
        }
        if ($status !== 'graduated' && ($existing['status'] ?? '') === 'graduated' && !array_key_exists('graduated_at', $input)) {
            $graduatedAt = null;
        }

        $stmt = $pdo->prepare("
            UPDATE sets
            SET set_number = ?, status = ?, current_sequence = ?, notes = ?,
                started_year = ?, graduated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$setNum, $status, $seq, $notes, $startedYear, $graduatedAt, $id]);

        $stmt = $pdo->prepare("SELECT * FROM sets WHERE id = ?");
        $stmt->execute([$id]);
        jsonSuccess($stmt->fetch(), 'Set updated');
    } catch (Throwable $e) {
        setsSchemaError($e);
    }
}

jsonError('Method not allowed', 405);
