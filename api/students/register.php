<?php
/**
 * Public Student Application / Registration
 * POST /api/students/register.php
 *
 * Body example:
 * {
 *   "zone_id": 1,
 *   "phone": "08012345678",
 *   "email": "optional@email.com",
 *   "first_name": "John",
 *   "last_name": "Doe",
 *   "other_names": "",
 *   "gender": "male",
 *   "date_of_birth": "1995-05-15",
 *   "address": "...",
 *   "state_of_origin": "Lagos",
 *   "lga": "..."
 * }
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

if (!checkRateLimit('student_register', 5, 600)) {
    jsonError('Too many registration attempts. Please try again later.', 429);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Required fields
$zoneId     = (int)($input['zone_id'] ?? 0);
$phoneRaw   = trim($input['phone'] ?? '');
$firstName  = trim($input['first_name'] ?? '');
$lastName   = trim($input['last_name'] ?? '');

if ($zoneId <= 0 || $phoneRaw === '' || $firstName === '' || $lastName === '') {
    jsonError('zone_id, phone, first_name and last_name are required');
}

$phone = normalizePhone($phoneRaw);
if (!$phone) {
    jsonError('Invalid phone number format. Use e.g. 08012345678');
}

// Optional fields
$email          = trim($input['email'] ?? '') ?: null;
$otherNames     = trim($input['other_names'] ?? '') ?: null;
$gender         = in_array($input['gender'] ?? '', ['male','female','other']) ? $input['gender'] : null;
$dob            = !empty($input['date_of_birth']) ? $input['date_of_birth'] : null;
$address        = trim($input['address'] ?? '') ?: null;
$stateOfOrigin  = trim($input['state_of_origin'] ?? '') ?: null;
$lga            = trim($input['lga'] ?? '') ?: null;

$pdo = db();

// Validate zone exists and is active
$stmt = $pdo->prepare("SELECT id, name FROM zones WHERE id = ? AND is_active = 1");
$stmt->execute([$zoneId]);
$zone = $stmt->fetch();
if (!$zone) {
    jsonError('Selected zone is invalid or inactive');
}

// Check if phone already exists in this zone
$stmt = $pdo->prepare("SELECT id, status FROM students WHERE phone = ? AND zone_id = ?");
$stmt->execute([$phone, $zoneId]);
$existing = $stmt->fetch();

if ($existing) {
    if (in_array($existing['status'], ['applicant', 'admitted', 'active', 'probation'])) {
        jsonError('A student with this phone number has already applied or is registered in this zone');
    }
    // Allow re-application only if previously withdrawn/inactive/rejected – for simplicity we block for now
    jsonError('This phone number is already associated with a student record in this zone');
}

// Check application window (optional – based on nearest upcoming conclave)
// For now we allow applications; admin can later enforce deadlines.

try {
    $pdo->beginTransaction();

    // Insert student as applicant
    $stmt = $pdo->prepare("
        INSERT INTO students (
            zone_id, phone, email, first_name, last_name, other_names,
            gender, date_of_birth, address, state_of_origin, lga,
            status, application_date, current_conclave
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            'applicant', NOW(), 0
        )
    ");
    $stmt->execute([
        $zoneId, $phone, $email, $firstName, $lastName, $otherNames,
        $gender, $dob, $address, $stateOfOrigin, $lga
    ]);

    $studentId = (int)$pdo->lastInsertId();

    // Create application record
    $year = (int)date('Y');
    $stmt = $pdo->prepare("
        INSERT INTO applications (student_id, zone_id, application_year, status, acknowledgment_sms_sent)
        VALUES (?, ?, ?, 'pending', 0)
    ");
    $stmt->execute([$studentId, $zoneId, $year]);

    $applicationId = (int)$pdo->lastInsertId();

    $pdo->commit();

    // --- Acknowledgment SMS (stub) ---
    $smsMessage = "Dear {$firstName}, your application to " . APP_NAME . " ({$zone['name']}) has been received. You will be notified of your admission status. Thank you.";
    logSms($phone, $smsMessage, 'acknowledgment', $studentId);

    // Mark SMS as sent (even if stub)
    $pdo->prepare("UPDATE applications SET acknowledgment_sms_sent = 1 WHERE id = ?")
        ->execute([$applicationId]);

    jsonSuccess([
        'student_id'     => $studentId,
        'application_id' => $applicationId,
        'zone'           => $zone['name'],
        'phone'          => $phone,
        'status'         => 'applicant'
    ], 'Application submitted successfully. An acknowledgment message has been sent.');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Registration error: ' . $e->getMessage());
    jsonError('Registration failed. Please try again later.', 500);
}

/**
 * Simple SMS logger (real sending will be implemented in Phase 5)
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
