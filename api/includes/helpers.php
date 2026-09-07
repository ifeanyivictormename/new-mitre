<?php
/**
 * Common helper functions
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Send JSON response and exit
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Success response shortcut
 */
function jsonSuccess($data = null, string $message = 'Success'): void {
    jsonResponse([
        'success' => true,
        'message' => $message,
        'data'    => $data
    ]);
}

/**
 * Error response shortcut
 */
function jsonError(string $message, int $statusCode = 400, $errors = null): void {
    jsonResponse([
        'success' => false,
        'message' => $message,
        'errors'  => $errors
    ], $statusCode);
}

/**
 * Sanitize string input
 */
function clean(string $value): string {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate Nigerian phone number (basic)
 * Accepts formats: 08012345678, +2348012345678, 2348012345678
 */
function normalizePhone(string $phone): ?string {
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    if (preg_match('/^\+234([7-9][0-1]\d{8})$/', $phone, $m)) {
        return '0' . $m[1];
    }
    if (preg_match('/^234([7-9][0-1]\d{8})$/', $phone, $m)) {
        return '0' . $m[1];
    }
    if (preg_match('/^0([7-9][0-1]\d{8})$/', $phone)) {
        return $phone;
    }
    return null;
}

/**
 * Generate a simple random token
 */
function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Calculate attendance score (out of 45)
 * 3 days x 2 sessions = 6 possible marks
 * Each present session = 7.5 marks (45 / 6)
 */
function calculateAttendanceScore(array $sessions): float {
    $presentCount = 0;
    foreach ($sessions as $s) {
        if (!empty($s['is_present'])) {
            $presentCount++;
        }
    }
    $maxSessions = ATTENDANCE_DAYS * count(ATTENDANCE_SESSIONS); // 6
    if ($maxSessions === 0) return 0.0;
    return round(($presentCount / $maxSessions) * WEIGHT_ATTENDANCE, 2);
}

/**
 * Calculate total score for a conclave result row
 */
function calculateTotalScore(array $scores): float {
    return round(
        ($scores['attendance_score'] ?? 0) +
        ($scores['summary_score'] ?? 0) +
        ($scores['short_paper_score'] ?? 0) +
        ($scores['long_paper_score'] ?? 0) +
        ($scores['term_paper_score'] ?? 0) +
        ($scores['oversight_score'] ?? 0),
        2
    );
}

/**
 * Get active sets system-wide (junior + senior).
 * Sets are global - all zones run the same set concurrently.
 * Returns array of set rows ordered by set_number ASC (lower = older = senior).
 */
function getActiveSets(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT * FROM sets
        WHERE status IN ('active_junior', 'active_senior')
        ORDER BY set_number ASC
    ");
    return $stmt->fetchAll();
}

/**
 * Determine whether a new set can be opened (global rule).
 * At most two concurrent active sets. When two exist, the lower-numbered
 * one is senior; it must graduate before the next set can open.
 */
function canOpenNewSet(PDO $pdo): array {
    $active = getActiveSets($pdo);
    $count  = count($active);

    if ($count >= 2) {
        $senior = $active[0]; // lower set_number = older = senior
        return [
            'allowed' => false,
            'reason'  => "Cannot open a new set until Set {$senior['set_number']} (senior) graduates. Currently active: Set {$active[0]['set_number']} and Set {$active[1]['set_number']}.",
            'active'  => $active
        ];
    }

    $stmt = $pdo->query("SELECT COALESCE(MAX(set_number), 0) AS max_set FROM sets");
    $maxSet = (int)$stmt->fetchColumn();

    // Also consider students that might have set_number without a sets row
    $stmt = $pdo->query("SELECT COALESCE(MAX(set_number), 0) AS max_set FROM students WHERE set_number IS NOT NULL");
    $maxStudentSet = (int)$stmt->fetchColumn();
    $next = max($maxSet, $maxStudentSet) + 1;
    if ($next < 1) $next = 1;

    return [
        'allowed'  => true,
        'next_set' => $next,
        'active'   => $active,
        'reason'   => null
    ];
}

/**
 * Ensure a set row exists and return it. Used when admitting into a set.
 */
function ensureSet(PDO $pdo, int $setNumber, string $status = 'active_junior'): array {
    $stmt = $pdo->prepare("SELECT * FROM sets WHERE set_number = ?");
    $stmt->execute([$setNumber]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $stmt = $pdo->prepare("
        INSERT INTO sets (set_number, status, started_year, current_sequence)
        VALUES (?, ?, YEAR(NOW()), 0)
    ");
    $stmt->execute([$setNumber, $status]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM sets WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Promote roles when a new junior set opens: existing junior -> senior.
 */
function promoteJuniorToSenior(PDO $pdo): void {
    $pdo->exec("
        UPDATE sets SET status = 'active_senior'
        WHERE status = 'active_junior'
    ");
}

/**
 * Generate next registration number for a set in a zone.
 * Format: MITRE/{ZONE_CODE}/{SET}/{SEQ:03d}
 *
 * Call inside an active transaction. This function locks the target set row
 * so concurrent admissions cannot allocate the same sequence.
 */
function generateRegNo(PDO $pdo, int $zoneId, int $setNumber): string {
    // Serialize allocation for this set.
    $lock = $pdo->prepare("SELECT id FROM sets WHERE set_number = ? FOR UPDATE");
    $lock->execute([$setNumber]);

    $stmt = $pdo->prepare("SELECT code FROM zones WHERE id = ?");
    $stmt->execute([$zoneId]);
    $code = $stmt->fetchColumn() ?: 'Z';
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

    // Use MAX(sequence) instead of COUNT so deleted rows do not cause reuse.
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reg_no, '/', -1) AS UNSIGNED)), 0)
        FROM students
        WHERE zone_id = ?
          AND set_number = ?
          AND reg_no REGEXP ?
    ");
    $pattern = '^MITRE/' . $code . '/' . (int)$setNumber . '/[0-9]+$';
    $stmt->execute([$zoneId, $setNumber, $pattern]);
    $seq = (int)$stmt->fetchColumn() + 1;

    // Defensive fallback for legacy data anomalies.
    while (true) {
        $candidate = sprintf('MITRE/%s/%d/%03d', $code, $setNumber, $seq);
        $chk = $pdo->prepare("SELECT 1 FROM students WHERE zone_id = ? AND reg_no = ? LIMIT 1");
        $chk->execute([$zoneId, $candidate]);
        if (!$chk->fetchColumn()) {
            return $candidate;
        }
        $seq++;
    }
}

/**
 * Mark a set as graduated (global).
 */
function graduateSet(PDO $pdo, int $setNumber): void {
    $pdo->prepare("
        UPDATE sets SET status = 'graduated', graduated_at = CURDATE()
        WHERE set_number = ?
    ")->execute([$setNumber]);
}
