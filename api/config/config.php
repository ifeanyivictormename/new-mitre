<?php
/**
 * Application Configuration
 * Minister Improvement and Training Retreat
 *
 * When frontend and backend are on different hosts:
 *   - APP_URL      = the API / backend origin (used for CORS + absolute upload URLs)
 *   - FRONTEND_URL = the public frontend origin (add to allowed CORS origins)
 */

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('Africa/Lagos');

// Database credentials – change these for your environment
define('DB_HOST', 'localhost');
define('DB_NAME', 'leadstar_new-mitre');
define('DB_USER', 'leadstar_new-mitre');
define('DB_PASS', 'Avalanche@25');
define('DB_CHARSET', 'utf8mb4');

// Application
define('APP_NAME', 'Minister Improvement and Training Retreat');
// Backend / API base URL (no trailing slash). Used for CORS and building absolute upload URLs.
define('APP_URL', 'https://api.leadstar.com.ng/mitre');    // DEV – change to https://api.yourdomain.com on live
// Frontend origin(s) allowed to call this API with credentials (comma-separated if multiple)
define('FRONTEND_URL', 'https://mitre.com.ng');          // DEV – origin only (no path). Live: https://yourdomain.com
define('APP_VERSION', '1.0.0');

// Session
define('SESSION_NAME', 'MITRE_SESSION');
define('SESSION_LIFETIME', 7200);               // 2 hours

// SMS Gateway (placeholder – replace with real credentials later)
define('SMS_ENABLED', false);
define('SMS_API_KEY', '');
define('SMS_SENDER_ID', 'MITRE');

// File uploads – live under the API host
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('UPLOAD_URL', rtrim(APP_URL, '/') . '/uploads/');  // stored in DB as full URL
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);     // 5 MB

// Scoring weights (do not change without updating business logic)
define('WEIGHT_ATTENDANCE', 45);
define('WEIGHT_SUMMARY', 5);
define('WEIGHT_SHORT_PAPER', 5);
define('WEIGHT_LONG_PAPER', 10);
define('WEIGHT_TERM_PAPER', 30);
define('WEIGHT_OVERSIGHT', 5);                  // Extra 5 marks

// Conclave rules
define('CONCLAVES_PER_YEAR', 2);
define('TOTAL_CONCLAVES', 6);
define('ATTENDANCE_DAYS', 3);
define('ATTENDANCE_SESSIONS', ['morning', 'evening']);

// Application window
define('APPLICATION_CLOSE_DAYS_BEFORE', 30);    // 1 month before resumption
define('ADMISSION_SMS_DAYS_BEFORE', 14);        // 2 weeks before resumption
