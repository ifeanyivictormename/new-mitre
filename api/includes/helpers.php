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
 * Send one SMS through eBulkSMS and write the result to the existing SMS log.
 * Returns true only when eBulkSMS responds with SUCCESS.
 */
function sendEbulkSms(string $phone, string $message, string $purpose, ?int $studentId = null): bool {
    $normalizedPhone = normalizePhone($phone);
    $settingsEnabled = true;
    try {
        $settingsEnabled = getGlobalSetting(db(), 'sms_enabled', '1') === '1';
    } catch (Throwable $error) {
        error_log('SMS setting lookup failed: ' . $error->getMessage());
    }
    if (!$normalizedPhone || !SMS_ENABLED || !$settingsEnabled || SMS_USERNAME === '' || SMS_API_KEY === '') {
        return false;
    }

    $recipient = '234' . substr($normalizedPhone, 1);
    $payload = [
        'SMS' => [
            'auth' => [
                'username' => SMS_USERNAME,
                'apikey' => SMS_API_KEY
            ],
            'message' => [
                'sender' => substr(SMS_SENDER_ID, 0, 11),
                'messagetext' => substr($message, 0, 160),
                'flash' => '0'
            ],
            'recipients' => [
                'gsm' => [[
                    'msidn' => $recipient,
                    'msgid' => substr(uniqid('mitre_', true), 0, 30)
                ]]
            ],
            'dndsender' => '0'
        ]
    ];

    $providerResponse = '';
    $status = 'failed';
    try {
        $curl = curl_init(SMS_API_URL);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30
        ]);
        $providerResponse = (string)curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $response = json_decode($providerResponse, true);
        $providerStatus = strtoupper((string)($response['response']['status'] ?? ''));
        if ($httpCode >= 200 && $httpCode < 300 && $providerStatus === 'SUCCESS') {
            $status = 'sent';
        } elseif ($curlError !== '') {
            $providerResponse = 'cURL error: ' . $curlError;
        }
    } catch (Throwable $error) {
        $providerResponse = $error->getMessage();
    }

    try {
        $stmt = db()->prepare('INSERT INTO sms_logs (recipient_phone, message, purpose, related_student_id, status, provider_response, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$normalizedPhone, $message, $purpose, $studentId, $status, $providerResponse, $status === 'sent' ? date('Y-m-d H:i:s') : null]);
    } catch (Throwable $error) {
        error_log('SMS log failed: ' . $error->getMessage());
    }

    if ($status === 'failed') {
        error_log('eBulkSMS send failed: ' . $providerResponse);
    }
    return $status === 'sent';
}

/**
 * Read a global setting, returning the supplied fallback when it is absent.
 */
function getGlobalSetting(PDO $pdo, string $key, string $fallback = ''): string {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null || $value === '' ? $fallback : (string)$value;
}

/**
 * Render an SMS template using {{placeholder}} tokens.
 */
function renderSmsTemplate(PDO $pdo, string $key, string $fallback, array $values): string {
    $template = getGlobalSetting($pdo, $key, $fallback);
    foreach ($values as $name => $value) {
        $template = str_replace('{{' . $name . '}}', (string)$value, $template);
    }
    return $template;
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
 * Compute or re-compute a student's result for a conclave.
 * Shared by attendance and assessments endpoints so either action
 * (marking attendance OR recording scores) keeps conclave_results in sync.
 */
function computeResult(PDO $pdo, int $studentId, int $conclaveId, $oversightOverride = null): void {
    // Attendance score
    $stmt = $pdo->prepare("
        SELECT day_number, session, is_present 
        FROM attendance 
        WHERE student_id = ? AND conclave_id = ?
    ");
    $stmt->execute([$studentId, $conclaveId]);
    $sessions = $stmt->fetchAll();
    $attendanceScore = calculateAttendanceScore($sessions);
    $hasAttendance = count($sessions) > 0 && $attendanceScore > 0;

    // Assessment scores
    $stmt = $pdo->prepare("
        SELECT type, score FROM assessments 
        WHERE student_id = ? AND conclave_id = ?
    ");
    $stmt->execute([$studentId, $conclaveId]);
    $assessments = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $summary     = (float)($assessments['summary'] ?? 0);
    $short       = (float)($assessments['short_paper'] ?? 0);
    $long        = (float)($assessments['long_paper'] ?? 0);
    $term        = (float)($assessments['term_paper'] ?? 0);
    $hasTerm     = isset($assessments['term_paper']);

    // Oversight – preserve any previously saved value instead of resetting it
    if ($oversightOverride !== null) {
        $oversight = (float)$oversightOverride;
    } else {
        $existing = $pdo->prepare("SELECT oversight_score FROM conclave_results WHERE student_id = ? AND conclave_id = ?");
        $existing->execute([$studentId, $conclaveId]);
        $prevOversight = $existing->fetchColumn();
        $oversight = $prevOversight !== false ? (float)$prevOversight : (float)WEIGHT_OVERSIGHT;
    }

    $total = calculateTotalScore([
        'attendance_score'  => $attendanceScore,
        'summary_score'     => $summary,
        'short_paper_score' => $short,
        'long_paper_score'  => $long,
        'term_paper_score'  => $term,
        'oversight_score'   => $oversight
    ]);

    // Upsert result
    $stmt = $pdo->prepare("
        INSERT INTO conclave_results (
            student_id, conclave_id,
            attendance_score, summary_score, short_paper_score, long_paper_score,
            term_paper_score, oversight_score, total_score,
            has_attendance, has_term_paper, computed_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
        ON DUPLICATE KEY UPDATE
            attendance_score = VALUES(attendance_score),
            summary_score = VALUES(summary_score),
            short_paper_score = VALUES(short_paper_score),
            long_paper_score = VALUES(long_paper_score),
            term_paper_score = VALUES(term_paper_score),
            oversight_score = VALUES(oversight_score),
            total_score = VALUES(total_score),
            has_attendance = VALUES(has_attendance),
            has_term_paper = VALUES(has_term_paper),
            computed_at = NOW()
    ");
    $stmt->execute([
        $studentId, $conclaveId,
        $attendanceScore, $summary, $short, $long,
        $term, $oversight, $total,
        $hasAttendance ? 1 : 0,
        $hasTerm ? 1 : 0
    ]);
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
 * Get one-letter zone prefix used in reg numbers.
 */
function zoneRegPrefix(PDO $pdo, int $zoneId): string {
    $stmt = $pdo->prepare("SELECT code FROM zones WHERE id = ?");
    $stmt->execute([$zoneId]);
    $code = strtoupper((string)($stmt->fetchColumn() ?: 'Z'));
    $code = preg_replace('/[^A-Z0-9]/', '', $code);
    return $code !== '' ? substr($code, 0, 1) : 'Z';
}

/**
 * Build registration number format: {set}-{zoneLetter}{sequence:03d}
 * Example: 18-K001
 */
function formatRegNo(int $setNumber, string $zoneLetter, int $sequence): string {
    return sprintf('%d-%s%03d', $setNumber, strtoupper($zoneLetter), $sequence);
}

/**
 * Generate next registration number for a set in a zone.
 * Format: {SET}-{ZONE_LETTER}{SEQ:03d}
 *
 * Call inside an active transaction. This function locks the target set row
 * so concurrent admissions cannot allocate the same sequence.
 */
function generateRegNo(PDO $pdo, int $zoneId, int $setNumber): string {
    // Serialize allocation for this set.
    $lock = $pdo->prepare("SELECT id FROM sets WHERE set_number = ? FOR UPDATE");
    $lock->execute([$setNumber]);

    $zoneLetter = zoneRegPrefix($pdo, $zoneId);

    // Use MAX(sequence) instead of COUNT so deleted rows do not cause reuse.
        $stmt = $pdo->prepare("
                SELECT COALESCE(MAX(CAST(SUBSTRING(SUBSTRING_INDEX(reg_no, '-', -1), 2) AS UNSIGNED)), 0)
            FROM students
            WHERE zone_id = ?
              AND set_number = ?
              AND reg_no REGEXP ?
        ");
    $pattern = '^' . (int)$setNumber . '-' . $zoneLetter . '[0-9]+$';
        $stmt->execute([$zoneId, $setNumber, $pattern]);
    $seq = (int)$stmt->fetchColumn() + 1;

    // Defensive fallback for legacy data anomalies.
    while (true) {
        $candidate = formatRegNo($setNumber, $zoneLetter, $seq);
        $chk = $pdo->prepare("SELECT 1 FROM students WHERE zone_id = ? AND reg_no = ? LIMIT 1");
        $chk->execute([$zoneId, $candidate]);
        if (!$chk->fetchColumn()) {
            return $candidate;
        }
        $seq++;
    }
}

/**
 * Recalculate reg_no for all students in a zone/set alphabetically.
 * This keeps numbering contiguous after admissions or deletions.
 */
function resequenceRegNosForZoneSet(PDO $pdo, int $zoneId, int $setNumber): void {
    if ($zoneId <= 0 || $setNumber <= 0) {
        return;
    }

    // Lock students in this zone/set to avoid concurrent resequence races.
    $lock = $pdo->prepare("
        SELECT id FROM students
        WHERE zone_id = ? AND set_number = ?
        FOR UPDATE
    ");
    $lock->execute([$zoneId, $setNumber]);

    // Clear existing reg numbers first to avoid unique-key conflicts while reassigning.
    $clear = $pdo->prepare("
        UPDATE students
        SET reg_no = NULL
        WHERE zone_id = ? AND set_number = ?
    ");
    $clear->execute([$zoneId, $setNumber]);

    // Only assign to students who are officially in the programme.
    $stmt = $pdo->prepare("
        SELECT id
        FROM students
        WHERE zone_id = ?
          AND set_number = ?
          AND status IN ('admitted', 'active', 'probation', 'graduated')
        ORDER BY
            COALESCE(last_name, '') ASC,
            COALESCE(first_name, '') ASC,
            COALESCE(other_names, '') ASC,
            id ASC
    ");
    $stmt->execute([$zoneId, $setNumber]);
    $rows = $stmt->fetchAll();

    $zoneLetter = zoneRegPrefix($pdo, $zoneId);
    $upd = $pdo->prepare("UPDATE students SET reg_no = ? WHERE id = ?");
    $seq = 1;
    foreach ($rows as $row) {
        $upd->execute([formatRegNo($setNumber, $zoneLetter, $seq), (int)$row['id']]);
        $seq++;
    }
}

/**
 * Recalculate reg_no across many zone/set groups.
 * Optional filters allow targeting one zone and/or one set.
 */
function resequenceRegNos(PDO $pdo, ?int $zoneId = null, ?int $setNumber = null): array {
    $sql = "
        SELECT DISTINCT zone_id, set_number
        FROM students
        WHERE set_number IS NOT NULL
          AND set_number > 0
    ";
    $params = [];

    if ($zoneId !== null && $zoneId > 0) {
        $sql .= " AND zone_id = ?";
        $params[] = $zoneId;
    }
    if ($setNumber !== null && $setNumber > 0) {
        $sql .= " AND set_number = ?";
        $params[] = $setNumber;
    }

    $sql .= " ORDER BY zone_id ASC, set_number ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $groups = $stmt->fetchAll();

    $processed = 0;
    foreach ($groups as $g) {
        resequenceRegNosForZoneSet($pdo, (int)$g['zone_id'], (int)$g['set_number']);
        $processed++;
    }

    return [
        'processed_groups' => $processed,
        'groups' => array_map(static function ($g) {
            return [
                'zone_id' => (int)$g['zone_id'],
                'set_number' => (int)$g['set_number'],
            ];
        }, $groups),
    ];
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
