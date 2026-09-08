<?php
/**
 * Authentication & Session Management
 * Supports: Admin (email+password) and Student (phone)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

// Start secure session
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Do not inherit a server-wide `session.cookie_secure=On` setting when
        // running the local HTTP XAMPP site. A Secure cookie is discarded by
        // browsers on HTTP, which makes a successful login appear to "loop"
        // back to the login page on the next authenticated request.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);

        session_name(SESSION_NAME);
        // When frontend and API are on different hosts, browsers require
        // SameSite=None; Secure for credentialed cross-origin requests.
        // On same-host / local HTTP we keep Lax so the cookie still works.
        $sameSite = $isHttps ? 'None' : 'Lax';
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'httponly' => true,
            'samesite' => $sameSite,
            'secure'   => $isHttps,
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}

/**
 * Admin login
 */
function adminLogin(string $email, string $password): array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([strtolower(trim($email))]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    // Update last login
    $pdo->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);

    startSession();
    session_regenerate_id(true);

    $_SESSION['user_type'] = 'admin';
    $_SESSION['user_id']   = (int)$admin['id'];
    $_SESSION['role']      = $admin['role'];
    $_SESSION['zone_id']   = $admin['zone_id'] ? (int)$admin['zone_id'] : null;
    $_SESSION['full_name'] = $admin['full_name'];
    $_SESSION['email']     = $admin['email'];

    // Current working zone (super_admin can switch later)
    $_SESSION['current_zone_id'] = $admin['zone_id'] ? (int)$admin['zone_id'] : null;

    return [
        'success' => true,
        'message' => 'Login successful',
        'user'    => [
            'id'        => (int)$admin['id'],
            'full_name' => $admin['full_name'],
            'email'     => $admin['email'],
            'role'      => $admin['role'],
            'zone_id'   => $admin['zone_id'] ? (int)$admin['zone_id'] : null,
            'current_zone_id' => $_SESSION['current_zone_id']
        ]
    ];
}

/**
 * Student login (phone number only)
 */
function studentLogin(string $phone): array {
    $normalized = normalizePhone($phone);
    if (!$normalized) {
        return ['success' => false, 'message' => 'Invalid phone number format'];
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT s.*, z.name AS zone_name, z.code AS zone_code
        FROM students s
        JOIN zones z ON z.id = s.zone_id
        WHERE s.phone = ? AND s.status IN ('admitted','active','probation','graduated')
        LIMIT 1
    ");
    $stmt->execute([$normalized]);
    $student = $stmt->fetch();

    if (!$student) {
        return ['success' => false, 'message' => 'Student not found or not yet admitted'];
    }

    startSession();
    session_regenerate_id(true);

    $_SESSION['user_type'] = 'student';
    $_SESSION['user_id']   = (int)$student['id'];
    $_SESSION['zone_id']   = (int)$student['zone_id'];
    $_SESSION['current_zone_id'] = (int)$student['zone_id'];
    $_SESSION['full_name'] = trim($student['first_name'] . ' ' . $student['last_name']);
    $_SESSION['phone']     = $student['phone'];
    $_SESSION['status']    = $student['status'];
    $_SESSION['set_number'] = $student['set_number'] !== null ? (int)$student['set_number'] : null;
    $_SESSION['reg_no']     = $student['reg_no'] ?? null;
    $_SESSION['current_conclave'] = (int)$student['current_conclave'];
    $_SESSION['set_current_sequence'] = 0;

    try {
        if ($_SESSION['set_number'] !== null) {
            $setStmt = $pdo->prepare("SELECT current_sequence FROM sets WHERE set_number = ? LIMIT 1");
            $setStmt->execute([$_SESSION['set_number']]);
            $setRow = $setStmt->fetch();
            if ($setRow) {
                $_SESSION['set_current_sequence'] = (int)($setRow['current_sequence'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // Keep login working even if sets table/schema is unavailable.
    }

    return [
        'success' => true,
        'message' => 'Login successful',
        'user'    => [
            'id'              => (int)$student['id'],
            'full_name'       => $_SESSION['full_name'],
            'phone'           => $student['phone'],
            'zone_id'         => (int)$student['zone_id'],
            'zone_name'       => $student['zone_name'],
            'status'          => $student['status'],
            'set_number'      => $_SESSION['set_number'],
            'reg_no'          => $_SESSION['reg_no'],
            'current_conclave'=> (int)$student['current_conclave'],
            'set_current_sequence' => $_SESSION['set_current_sequence']
        ]
    ];
}

/**
 * Logout (works for both)
 */
function logout(): void {
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Require authenticated admin
 */
function requireAdmin(array $allowedRoles = ['super_admin', 'admin']): array {
    startSession();
    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        jsonError('Unauthorized. Admin login required.', 401);
    }
    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        jsonError('Forbidden. Insufficient privileges.', 403);
    }
    return [
        'id'              => (int)$_SESSION['user_id'],
        'role'            => $_SESSION['role'],
        'zone_id'         => $_SESSION['zone_id'] ?? null,
        'current_zone_id' => $_SESSION['current_zone_id'] ?? null,
        'full_name'       => $_SESSION['full_name'] ?? '',
        'email'           => $_SESSION['email'] ?? ''
    ];
}

/**
 * Require authenticated student
 */
function requireStudent(): array {
    startSession();
    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
        jsonError('Unauthorized. Student login required.', 401);
    }
    return [
        'id'              => (int)$_SESSION['user_id'],
        'zone_id'         => (int)$_SESSION['zone_id'],
        'current_zone_id' => (int)$_SESSION['current_zone_id'],
        'full_name'       => $_SESSION['full_name'] ?? '',
        'phone'           => $_SESSION['phone'] ?? '',
        'status'          => $_SESSION['status'] ?? '',
        'set_number'      => isset($_SESSION['set_number']) ? $_SESSION['set_number'] : null,
        'reg_no'          => $_SESSION['reg_no'] ?? null,
        'current_conclave'=> isset($_SESSION['current_conclave']) ? (int)$_SESSION['current_conclave'] : 0,
        'set_current_sequence' => isset($_SESSION['set_current_sequence']) ? (int)$_SESSION['set_current_sequence'] : 0
    ];
}

/**
 * Get current logged-in user (admin or student) or null
 */
function currentUser(): ?array {
    startSession();
    if (empty($_SESSION['user_type'])) {
        return null;
    }
    if ($_SESSION['user_type'] === 'admin') {
        return [
            'type'            => 'admin',
            'id'              => (int)$_SESSION['user_id'],
            'role'            => $_SESSION['role'],
            'zone_id'         => $_SESSION['zone_id'] ?? null,
            'current_zone_id' => $_SESSION['current_zone_id'] ?? null,
            'full_name'       => $_SESSION['full_name'] ?? '',
            'email'           => $_SESSION['email'] ?? ''
        ];
    }
    if ($_SESSION['user_type'] === 'student') {
        $studentId = (int)($_SESSION['user_id'] ?? 0);
        $zoneId = (int)($_SESSION['zone_id'] ?? 0);
        $status = $_SESSION['status'] ?? '';
        $setNumber = isset($_SESSION['set_number']) ? $_SESSION['set_number'] : null;
        $regNo = $_SESSION['reg_no'] ?? null;
        $currentConclave = isset($_SESSION['current_conclave']) ? (int)$_SESSION['current_conclave'] : 0;
        $setCurrentSequence = isset($_SESSION['set_current_sequence']) ? (int)$_SESSION['set_current_sequence'] : 0;

        // Keep student dashboard KPIs current even when the session predates admin updates.
        if ($studentId > 0) {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("\n                    SELECT s.zone_id, s.status, s.set_number, s.reg_no, s.current_conclave,\n                           st.current_sequence AS set_current_sequence\n                    FROM students s\n                    LEFT JOIN sets st ON st.set_number = s.set_number\n                    WHERE s.id = ?\n                    LIMIT 1\n                ");
                $stmt->execute([$studentId]);
                $row = $stmt->fetch();
                if ($row) {
                    $zoneId = (int)$row['zone_id'];
                    $status = $row['status'] ?? $status;
                    $setNumber = $row['set_number'] !== null ? (int)$row['set_number'] : null;
                    $regNo = $row['reg_no'] ?? null;
                    $currentConclave = (int)($row['current_conclave'] ?? 0);
                    $setCurrentSequence = (int)($row['set_current_sequence'] ?? 0);

                    $_SESSION['zone_id'] = $zoneId;
                    $_SESSION['current_zone_id'] = $zoneId;
                    $_SESSION['status'] = $status;
                    $_SESSION['set_number'] = $setNumber;
                    $_SESSION['reg_no'] = $regNo;
                    $_SESSION['current_conclave'] = $currentConclave;
                    $_SESSION['set_current_sequence'] = $setCurrentSequence;
                }
            } catch (Throwable $e) {
                // Fallback to session snapshot if database refresh fails.
            }
        }

        return [
            'type'            => 'student',
            'id'              => $studentId,
            'zone_id'         => $zoneId,
            'current_zone_id' => (int)($_SESSION['current_zone_id'] ?? $zoneId),
            'full_name'       => $_SESSION['full_name'] ?? '',
            'phone'           => $_SESSION['phone'] ?? '',
            'status'          => $status,
            'set_number'      => $setNumber,
            'reg_no'          => $regNo,
            'current_conclave'=> $currentConclave,
            'set_current_sequence' => $setCurrentSequence
        ];
    }
    return null;
}

/**
 * Switch current working zone (super_admin only, or admin if they have access)
 */
function switchZone(int $zoneId): array {
    $admin = requireAdmin(['super_admin', 'admin']);

    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, name, code FROM zones WHERE id = ? AND is_active = 1");
    $stmt->execute([$zoneId]);
    $zone = $stmt->fetch();

    if (!$zone) {
        return ['success' => false, 'message' => 'Zone not found or inactive'];
    }

    // Regular admin can only switch to their assigned zone (or if they have none)
    if ($admin['role'] === 'admin' && $admin['zone_id'] !== null && $admin['zone_id'] !== $zoneId) {
        return ['success' => false, 'message' => 'You do not have access to this zone'];
    }

    $_SESSION['current_zone_id'] = $zoneId;

    return [
        'success' => true,
        'message' => 'Zone switched successfully',
        'zone'    => [
            'id'   => (int)$zone['id'],
            'name' => $zone['name'],
            'code' => $zone['code']
        ]
    ];
}

/**
 * Get current zone details (or null if none selected)
 */
function getCurrentZone(): ?array {
    startSession();
    $zoneId = $_SESSION['current_zone_id'] ?? null;
    if (!$zoneId) return null;

    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, name, code, is_active FROM zones WHERE id = ?");
    $stmt->execute([$zoneId]);
    $zone = $stmt->fetch();
    return $zone ?: null;
}

/**
 * Require that a current zone is selected (for most admin operations)
 */
function requireCurrentZone(): array {
    $zone = getCurrentZone();
    if (!$zone) {
        jsonError('No zone selected. Please select a zone first.', 400);
    }
    return $zone;
}
