<?php
/**
 * Public multi-step student application draft API.
 *
 * POST  /api/students/application.php
 *       {"action":"start", "zone_id":1, "phone":"080...", "first_name":"...", "last_name":"..."}
 * GET   /api/students/application.php?token=<resume-token>
 * PATCH /api/students/application.php
 *       {"token":"...", "step":1|2, "data":{...}}
 * POST  /api/students/application.php (multipart/form-data)
 *       action=upload_passport, token=...
 * POST  /api/students/application.php
 *       {"action":"generate_referee_link", "token":"..."}
 * GET   /api/students/application.php?action=referee&token=<referee-token>
 * POST  /api/students/application.php
 *       {"action":"referee_submit", "referee_token":"...", ...}
 * POST  /api/students/application.php
 *       {"action":"submit_application", "token":"..."}
 *
 * This endpoint is intentionally separate from register.php. It owns draft
 * creation and incremental saves for the public application flow.
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'referee') {
        getRefereeApplication();
    }
    getApplication();
}

if (!in_array($method, ['POST', 'PATCH'], true)) {
    jsonError('Method not allowed', 405);
}

if (!checkRateLimit('student_application', 20, 600)) {
    jsonError('Too many application requests. Please try again later.', 429);
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'upload_passport') {
    uploadPassport($_POST, $_FILES['passport'] ?? null);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST') {
    $action = $input['action'] ?? '';
    if ($action === 'start') {
        startApplication($input);
    }
    if ($action === 'generate_referee_link') {
        generateRefereeLink($input);
    }
    if ($action === 'referee_submit') {
        submitReferee($input);
    }
    if ($action === 'submit_application') {
        submitApplication($input);
    }
    jsonError('Unsupported application action');
}

saveStep($input);

function startApplication(array $input): void {
    $zoneId = (int)($input['zone_id'] ?? 0);
    $phone = normalizePhone(trim((string)($input['phone'] ?? '')));
    $firstName = trim((string)($input['first_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));

    if ($zoneId <= 0 || !$phone || $firstName === '' || $lastName === '') {
        jsonError('zone_id, phone, first_name and last_name are required');
    }

    $pdo = db();
    assertActiveZone($pdo, $zoneId);

    $stmt = $pdo->prepare('SELECT id, status FROM students WHERE phone = ? AND zone_id = ?');
    $stmt->execute([$phone, $zoneId]);
    if ($stmt->fetch()) {
        jsonError('A student record with this phone number already exists in this zone', 409);
    }

    $token = generateToken(32);
    $data = normalizeStepData(1, $input);

    $studentId = 0;
    $applicationId = 0;

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('
            INSERT INTO students (
                application_token, application_step, zone_id, phone, alt_phone, email,
                first_name, last_name, other_names, gender, age, marital_status,
                address, occupation, lang_speak, lang_write, literacy,
                state_of_origin, lga, status, application_date, current_conclave
            ) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'applicant\', NOW(), 0)
        ');
        $stmt->execute([
            $token, $zoneId, $phone, $data['alt_phone'], $data['email'],
            $firstName, $lastName, $data['other_names'], $data['gender'], $data['age'],
            $data['marital_status'], $data['address'], $data['occupation'],
            $data['lang_speak'], $data['lang_write'], $data['literacy'],
            $data['state_of_origin'], $data['lga']
        ]);
        $studentId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('
            INSERT INTO applications (student_id, zone_id, application_year, status, acknowledgment_sms_sent)
            VALUES (?, ?, ?, \'pending\', 0)
        ');
        $stmt->execute([$studentId, $zoneId, (int)date('Y')]);
        $applicationId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Application draft creation error: ' . $e->getMessage());
        jsonError('Unable to start the application. Please try again later.', 500);
    }

    jsonSuccess([
        'application_id' => $applicationId,
        'student_id' => $studentId,
        'resume_token' => $token,
        'current_step' => 1,
        'next_step' => 1
    ], 'Application started. Save the resume token to continue later.');
}

function saveStep(array $input): void {
    $token = trim((string)($input['token'] ?? ''));
    $step = (int)($input['step'] ?? 0);
    $data = is_array($input['data'] ?? null) ? $input['data'] : [];

    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        jsonError('A valid application token is required');
    }
    if (!in_array($step, [1, 2], true)) {
        jsonError('Only steps 1 and 2 are currently available');
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM students WHERE application_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $student = $stmt->fetch();
    if (!$student) {
        jsonError('Application draft not found', 404);
    }

    $stmt = $pdo->prepare("SELECT submitted_at FROM applications WHERE student_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int)$student['id']]);
    $application = $stmt->fetch();
    if (!$application || !empty($application['submitted_at'])) {
        jsonError('This application can no longer be edited', 409);
    }

    $currentStep = (int)$student['application_step'];
    if ($step > $currentStep) {
        jsonError('Complete the previous step before continuing', 409);
    }

    $normalized = normalizeStepData($step, $data);
    validateStep($step, $normalized);
    $columns = array_keys($normalized);
    $assignments = implode(', ', array_map(static fn($column) => "{$column} = ?", $columns));
    $values = array_values($normalized);
    $nextStep = $step === 1 ? 2 : 3;
    $storedStep = max($currentStep, $nextStep);

    try {
        $pdo->beginTransaction();
        $values[] = $storedStep;
        $values[] = (int)$student['id'];
        $stmt = $pdo->prepare("UPDATE students SET {$assignments}, application_step = ? WHERE id = ? AND application_token = ?");
        $values[] = $token;
        $stmt->execute($values);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Application step save error: ' . $e->getMessage());
        jsonError('Unable to save this step. Please try again later.', 500);
    }

    jsonSuccess([
        'application_id' => getApplicationId($pdo, (int)$student['id']),
        'student_id' => (int)$student['id'],
        'current_step' => $storedStep,
        'next_step' => $nextStep
    ], "Step {$step} saved successfully.");
}

function uploadPassport(array $input, ?array $file): void {
    $token = trim((string)($input['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        jsonError('A valid application token is required');
    }
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonError('A passport photograph is required');
    }
    if ((int)$file['size'] > MAX_UPLOAD_SIZE) {
        jsonError('Passport photograph must not exceed 5 MB', 422);
    }

    $stmt = db()->prepare('SELECT id, application_step FROM students WHERE application_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $student = $stmt->fetch();
    if (!$student) {
        jsonError('Application draft not found', 404);
    }
    if ((int)$student['application_step'] < 3) {
        jsonError('Complete steps 1 and 2 before uploading a passport', 409);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    $imageInfo = @getimagesize($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!$imageInfo || !isset($extensions[$mime])) {
        jsonError('Passport photograph must be a valid JPG or PNG image', 422);
    }

    if (!is_dir(UPLOAD_PATH) && !mkdir(UPLOAD_PATH, 0750, true) && !is_dir(UPLOAD_PATH)) {
        jsonError('Unable to prepare upload storage', 500);
    }
    $filename = 'passport_' . (int)$student['id'] . '_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    $destination = rtrim(UPLOAD_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        jsonError('Unable to save passport photograph', 500);
    }

    $url = rtrim(UPLOAD_URL, '/') . '/' . $filename;
    $stmt = db()->prepare('UPDATE students SET passport_photo = ? WHERE id = ? AND application_token = ?');
    $stmt->execute([$url, (int)$student['id'], $token]);

    jsonSuccess([
        'student_id' => (int)$student['id'],
        'passport_photo' => $url,
        'current_step' => 3
    ], 'Passport photograph uploaded successfully.');
}

function generateRefereeLink(array $input): void {
    $token = trim((string)($input['token'] ?? ''));
    $student = findStudentByApplicationToken($token);
    if ((int)$student['application_step'] < 3 || empty($student['passport_photo'])) {
        jsonError('Upload the passport photograph before generating a referee link', 409);
    }

    $refereeToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $refereeToken);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE application_referee_tokens SET used_at = NOW() WHERE student_id = ? AND used_at IS NULL')
            ->execute([(int)$student['id']]);
        $pdo->prepare('INSERT INTO application_referee_tokens (student_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))')
            ->execute([(int)$student['id'], $tokenHash]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Referee link creation error: ' . $e->getMessage());
        jsonError('Unable to create referee link. Apply the database migration and try again.', 500);
    }

    $frontend = trim(explode(',', FRONTEND_URL)[0]);
    $link = rtrim($frontend, '/') . '/application/referee.html?token=' . rawurlencode($refereeToken);
    jsonSuccess(['referee_link' => $link, 'expires_in_days' => 7], 'Referee continuation link created.');
}

function getRefereeApplication(): void {
    $token = trim((string)($_GET['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        jsonError('A valid referee token is required');
    }

    $stmt = db()->prepare('
        SELECT s.first_name, s.last_name, t.expires_at
        FROM application_referee_tokens t
        JOIN students s ON s.id = t.student_id
        WHERE t.token_hash = SHA2(?, 256) AND t.used_at IS NULL AND t.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $application = $stmt->fetch();
    if (!$application) {
        jsonError('This referee link is invalid, expired, or already used', 410);
    }

    jsonSuccess([
        'candidate_name' => trim($application['first_name'] . ' ' . $application['last_name']),
        'expires_at' => $application['expires_at']
    ]);
}

function submitReferee(array $input): void {
    $refereeToken = trim((string)($input['referee_token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $refereeToken)) {
        jsonError('A valid referee token is required');
    }

    $refName = trim((string)($input['ref_name'] ?? ''));
    $refPhone = normalizePhone(trim((string)($input['ref_phone'] ?? '')));
    $refEmail = trim((string)($input['ref_email'] ?? '')) ?: null;
    $refAddress = trim((string)($input['ref_address'] ?? ''));
    $refDuration = trim((string)($input['ref_duration'] ?? ''));
    $refInfo = trim((string)($input['ref_info'] ?? '')) ?: null;
    if ($refName === '' || !$refPhone || $refAddress === '' || $refDuration === '') {
        jsonError('ref_name, ref_phone, ref_address and ref_duration are required', 422);
    }
    if ($refEmail !== null && !filter_var($refEmail, FILTER_VALIDATE_EMAIL)) {
        jsonError('Invalid referee email address', 422);
    }

    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT t.id, t.student_id
        FROM application_referee_tokens t
        WHERE t.token_hash = SHA2(?, 256) AND t.used_at IS NULL AND t.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$refereeToken]);
    $tokenRow = $stmt->fetch();
    if (!$tokenRow) {
        jsonError('This referee link is invalid, expired, or already used', 410);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('
            UPDATE students
            SET ref_name = ?, ref_phone = ?, ref_email = ?, ref_address = ?, ref_duration = ?, ref_info = ?
            WHERE id = ?
        ');
        $stmt->execute([$refName, $refPhone, $refEmail, $refAddress, $refDuration, $refInfo, (int)$tokenRow['student_id']]);
        $stmt = $pdo->prepare('UPDATE application_referee_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $stmt->execute([(int)$tokenRow['id']]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            jsonError('This referee link has already been used', 410);
        }
        $pdo->prepare('UPDATE applications SET referee_completed_at = NOW() WHERE student_id = ? AND status = \'pending\'')
            ->execute([(int)$tokenRow['student_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Referee submission error: ' . $e->getMessage());
        jsonError('Unable to save referee details. Please try again later.', 500);
    }

    jsonSuccess(['completed' => true], 'Referee details submitted successfully.');
}

function submitApplication(array $input): void {
    $student = findStudentByApplicationToken(trim((string)($input['token'] ?? '')));
    if (empty($student['passport_photo']) || empty($student['ref_name']) || empty($student['ref_phone']) || empty($student['ref_address']) || empty($student['ref_duration'])) {
        jsonError('Passport and referee details must be completed before final submission', 409);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, submitted_at FROM applications WHERE student_id = ? AND status = \'pending\' ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$student['id']]);
    $application = $stmt->fetch();
    if (!$application) {
        jsonError('Pending application not found', 404);
    }
    if (!empty($application['submitted_at'])) {
        jsonError('This application has already been submitted', 409);
    }

    $stmt = $pdo->prepare('UPDATE applications SET submitted_at = NOW(), updated_at = NOW() WHERE id = ? AND submitted_at IS NULL AND status = \'pending\'');
    $stmt->execute([(int)$application['id']]);
    if ($stmt->rowCount() !== 1) {
        jsonError('This application has already been submitted', 409);
    }

    $smsMessage = renderSmsTemplate(
        $pdo,
        'sms_application_completion_template',
        'Your MITRE application was received successfully. You will be contacted for further information. More of God\'s blessings.',
        []
    );
    if (sendEbulkSms((string)$student['phone'], $smsMessage, 'application_completion', (int)$student['id'])) {
        $pdo->prepare('UPDATE applications SET acknowledgment_sms_sent = 1 WHERE id = ?')
            ->execute([(int)$application['id']]);
    }

    jsonSuccess([
        'application_id' => (int)$application['id'],
        'submitted_at' => date('Y-m-d H:i:s'),
        'status' => 'pending'
    ], 'Application submitted successfully and is awaiting review.');
}

function findStudentByApplicationToken(string $token): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        jsonError('A valid application token is required');
    }
    $stmt = db()->prepare('SELECT * FROM students WHERE application_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $student = $stmt->fetch();
    if (!$student) {
        jsonError('Application draft not found', 404);
    }
    return $student;
}

function getApplication(): void {
    $token = trim((string)($_GET['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        jsonError('A valid application token is required');
    }

    $stmt = db()->prepare('SELECT * FROM students WHERE application_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $student = $stmt->fetch();
    if (!$student) {
        jsonError('Application draft not found', 404);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, submitted_at, referee_completed_at FROM applications WHERE student_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$student['id']]);
    $applicationMeta = $stmt->fetch() ?: [];
    $applicationId = (int)($applicationMeta['id'] ?? 0);
    unset($student['application_token']);
    unset($student['password']);

    jsonSuccess([
        'application_id' => $applicationId,
        'student_id' => (int)$student['id'],
        'current_step' => (int)$student['application_step'],
        'submitted_at' => $applicationMeta['submitted_at'] ?? null,
        'referee_completed_at' => $applicationMeta['referee_completed_at'] ?? null,
        'application' => $student
    ]);
}

function assertActiveZone(PDO $pdo, int $zoneId): void {
    $stmt = $pdo->prepare('SELECT id FROM zones WHERE id = ? AND is_active = 1');
    $stmt->execute([$zoneId]);
    if (!$stmt->fetch()) {
        jsonError('Selected zone is invalid or inactive');
    }
}

function getApplicationId(PDO $pdo, int $studentId): int {
    $stmt = $pdo->prepare('SELECT id FROM applications WHERE student_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$studentId]);
    return (int)$stmt->fetchColumn();
}

function normalizeStepData(int $step, array $input): array {
    $value = static fn(string $key): ?string => trim((string)($input[$key] ?? '')) ?: null;
    $data = [];

    if ($step === 1) {
        $data = [
            'alt_phone' => $value('alt_phone'),
            'email' => $value('email'),
            'other_names' => $value('other_names'),
            'gender' => strtolower((string)($input['gender'] ?? '')) ?: null,
            'age' => isset($input['age']) && $input['age'] !== '' ? (int)$input['age'] : null,
            'marital_status' => strtolower((string)($input['marital_status'] ?? '')) ?: null,
            'address' => $value('address'),
            'occupation' => $value('occupation'),
            'lang_speak' => $value('lang_speak'),
            'lang_write' => $value('lang_write'),
            'literacy' => strtolower((string)($input['literacy'] ?? '')) ?: null,
            'state_of_origin' => $value('state_of_origin'),
            'lga' => $value('lga')
        ];
    } else {
        $data = [
            'church' => $value('church'),
            'church_post' => $value('church_post'),
            'born_again' => $value('born_again'),
            'baptism' => strtolower((string)($input['baptism'] ?? '')) ?: null,
            'calling' => $value('calling'),
            'in_calling' => strtolower((string)($input['in_calling'] ?? '')) ?: null,
            'entered_calling' => $value('entered_calling'),
            'attended_mitre' => strtolower((string)($input['attended_mitre'] ?? '')) ?: null,
            'why_mitre' => $value('why_mitre'),
            'certificate' => $value('certificate'),
            'cert_year' => isset($input['cert_year']) && $input['cert_year'] !== '' ? (int)$input['cert_year'] : null,
            'institution' => $value('institution'),
            'oversight' => $value('oversight')
        ];
    }

    return $data;
}

function validateStep(int $step, array $data): void {
    $required = $step === 1
        ? ['gender', 'marital_status', 'address', 'occupation', 'lang_speak', 'lang_write', 'literacy']
        : ['church', 'church_post', 'born_again', 'baptism', 'calling', 'in_calling', 'attended_mitre', 'why_mitre', 'certificate', 'cert_year', 'institution'];

    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
            jsonError("{$field} is required", 422);
        }
    }

    $allowed = [
        'gender' => ['male', 'female', 'other'],
        'marital_status' => ['married', 'single', 'separated', 'widowed', 'other'],
        'literacy' => ['yes', 'no'],
        'baptism' => ['yes', 'no', 'uncertain'],
        'in_calling' => ['yes', 'no', 'not_sure'],
        'attended_mitre' => ['yes', 'no']
    ];
    foreach ($allowed as $field => $values) {
        if (array_key_exists($field, $data) && $data[$field] !== null && !in_array($data[$field], $values, true)) {
            jsonError("Invalid {$field} value", 422);
        }
    }
}
