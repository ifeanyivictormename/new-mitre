-- ============================================================
-- Minister Improvement and Training Retreat - Full Database Schema
-- Multi-zone, 3-year / 6-conclave cycle
-- Single file – ready for fresh install (no migrations needed)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. ZONES (Branches)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    code            VARCHAR(20)  NOT NULL UNIQUE,          -- e.g. LAG, ABU, PHC
    description     TEXT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. ADMINS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    phone           VARCHAR(20)  NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
    zone_id         INT UNSIGNED NULL,                    -- NULL = can access all zones (super_admin)
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login      DATETIME NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. STUDENTS (includes full multi-step application fields)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_token   VARCHAR(32)  NULL UNIQUE,           -- resume token for multi-step form
    application_step    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=complete/legacy, 1-3=current step',

    zone_id             INT UNSIGNED NOT NULL,
    phone               VARCHAR(20)  NOT NULL,              -- primary login identifier
    alt_phone           VARCHAR(20)  NULL,
    email               VARCHAR(150) NULL,
    first_name          VARCHAR(80)  NOT NULL,
    last_name           VARCHAR(80)  NOT NULL,
    other_names         VARCHAR(100) NULL,
    gender              ENUM('male','female','other') NULL,
    age                 TINYINT UNSIGNED NULL,
    marital_status      ENUM('married','single','separated','widowed','other') NULL,
    date_of_birth       DATE NULL,
    address             TEXT NULL,
    state_of_origin     VARCHAR(50) NULL,
    lga                 VARCHAR(80) NULL,

    -- Spiritual / church
    church              VARCHAR(150) NULL,
    church_post         VARCHAR(100) NULL,
    born_again          VARCHAR(50)  NULL COMMENT 'year or description',
    baptism             ENUM('yes','no','uncertain') NULL,
    calling             TEXT NULL,
    in_calling          ENUM('yes','no','not_sure') NULL,
    entered_calling     VARCHAR(100) NULL,
    attended_mitre      ENUM('yes','no') NULL,
    why_mitre           TEXT NULL,

    -- Education / work
    occupation          VARCHAR(255) NULL,
    lang_speak          VARCHAR(255) NULL,
    lang_write          VARCHAR(255) NULL,
    literacy            ENUM('yes','no') NULL,
    certificate         VARCHAR(50) NULL,
    cert_year           SMALLINT UNSIGNED NULL,
    institution         VARCHAR(255) NULL,
    oversight           VARCHAR(255) NULL,

    passport_photo      VARCHAR(255) NULL,                   -- full URL: {API_BASE}/uploads/filename

    -- Referee
    ref_name            VARCHAR(150) NULL,
    ref_phone           VARCHAR(20) NULL,
    ref_address         TEXT NULL,
    ref_email           VARCHAR(100) NULL,
    ref_duration        VARCHAR(100) NULL,
    ref_info            TEXT NULL,

    -- Academic tracking
    admission_year      YEAR NULL,
    set_number          INT UNSIGNED NULL,                  -- e.g. 18, 19
    reg_no              VARCHAR(40) NULL,                   -- e.g. MITRE/LAG/18/001
    current_conclave    TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0 = not yet started, 1-6
    status              ENUM('applicant','admitted','active','probation','inactive','withdrawn','graduated')
                        NOT NULL DEFAULT 'applicant',

    application_date    DATETIME NULL,
    admission_date      DATETIME NULL,
    graduation_date     DATE NULL,

    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_phone_zone (phone, zone_id),
    UNIQUE KEY uk_reg_no_zone (reg_no, zone_id),
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE RESTRICT,
    INDEX idx_status (status),
    INDEX idx_zone_status (zone_id, status),
    INDEX idx_phone (phone),
    INDEX idx_set_number (set_number),
    INDEX idx_zone_set (zone_id, set_number),
    INDEX idx_application_token (application_token),
    INDEX idx_application_step (application_step)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. APPLICATIONS (workflow / review)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS applications (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id          INT UNSIGNED NOT NULL,
    zone_id             INT UNSIGNED NOT NULL,
    application_year    YEAR NOT NULL,
    status              ENUM('pending','admitted','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    reviewed_by         INT UNSIGNED NULL,
    review_notes        TEXT NULL,
    reviewed_at         DATETIME NULL,
    acknowledgment_sms_sent TINYINT(1) DEFAULT 0,
    admission_sms_sent  TINYINT(1) DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE RESTRICT,
    FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_status_year (status, application_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. CONCLAVES (global – no zone_id)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conclaves (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sequence            TINYINT UNSIGNED NOT NULL,          -- 1 to 6
    year                YEAR NOT NULL,
    title               VARCHAR(150) NULL,
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    status              ENUM('open','closed') NOT NULL DEFAULT 'closed',
    notes               TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_seq_year (sequence, year),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. ATTENDANCE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id          INT UNSIGNED NOT NULL,
    conclave_id         INT UNSIGNED NOT NULL,
    day_number          TINYINT UNSIGNED NOT NULL,          -- 1, 2 or 3
    session             ENUM('morning','evening') NOT NULL,
    is_present          TINYINT(1) NOT NULL DEFAULT 0,
    marked_by           INT UNSIGNED NULL,
    marked_at           DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_student_conclave_day_session (student_id, conclave_id, day_number, session),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (conclave_id) REFERENCES conclaves(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_by) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_conclave (conclave_id),
    INDEX idx_student_conclave (student_id, conclave_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. ASSESSMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assessments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id          INT UNSIGNED NOT NULL,
    conclave_id         INT UNSIGNED NOT NULL,
    type                ENUM('summary','short_paper','long_paper','term_paper') NOT NULL,
    score               DECIMAL(5,2) NULL,
    max_score           DECIMAL(5,2) NOT NULL,
    source_conclave_id  INT UNSIGNED NULL,                  -- term paper belongs to next conclave
    remarks             TEXT NULL,
    submitted_at        DATETIME NULL,
    recorded_by         INT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_student_conclave_type (student_id, conclave_id, type),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (conclave_id) REFERENCES conclaves(id) ON DELETE CASCADE,
    FOREIGN KEY (source_conclave_id) REFERENCES conclaves(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_conclave (conclave_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. CONCLAVE RESULTS (computed totals)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conclave_results (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id          INT UNSIGNED NOT NULL,
    conclave_id         INT UNSIGNED NOT NULL,
    attendance_score    DECIMAL(5,2) NOT NULL DEFAULT 0,
    summary_score       DECIMAL(5,2) NOT NULL DEFAULT 0,
    short_paper_score   DECIMAL(5,2) NOT NULL DEFAULT 0,
    long_paper_score    DECIMAL(5,2) NOT NULL DEFAULT 0,
    term_paper_score    DECIMAL(5,2) NOT NULL DEFAULT 0,
    oversight_score     DECIMAL(5,2) NOT NULL DEFAULT 0,
    total_score         DECIMAL(5,2) NOT NULL DEFAULT 0,
    grade               VARCHAR(5) NULL,
    has_attendance      TINYINT(1) NOT NULL DEFAULT 0,
    has_term_paper      TINYINT(1) NOT NULL DEFAULT 0,
    computed_at         DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_student_conclave (student_id, conclave_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (conclave_id) REFERENCES conclaves(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. INSTRUCTORS / STAFF
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS instructors (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_id             INT UNSIGNED NULL,
    full_name           VARCHAR(150) NOT NULL,
    phone               VARCHAR(20) NULL,
    email               VARCHAR(150) NULL,
    gender              ENUM('male','female','other') NULL,
    qualification       VARCHAR(150) NULL,
    specialization      VARCHAR(150) NULL,
    bio                 TEXT NULL,
    passport_photo      VARCHAR(255) NULL,
    status              ENUM('pending','active','inactive') NOT NULL DEFAULT 'pending',
    joined_date         DATE NULL,
    left_date           DATE NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. ALUMNI
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alumni (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id          INT UNSIGNED NULL,
    zone_id             INT UNSIGNED NULL,
    full_name           VARCHAR(150) NOT NULL,
    phone               VARCHAR(20) NULL,
    email               VARCHAR(150) NULL,
    gender              ENUM('male','female','other') NULL,
    graduation_year     YEAR NULL,
    current_occupation  VARCHAR(150) NULL,
    current_location    VARCHAR(150) NULL,
    bio                 TEXT NULL,
    passport_photo      VARCHAR(255) NULL,
    last_gathering_attended YEAR NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    INDEX idx_graduation (graduation_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. ALUMNI GATHERING ATTENDANCE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alumni_gathering_attendance (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alumni_id           INT UNSIGNED NOT NULL,
    gathering_year      YEAR NOT NULL,
    is_present          TINYINT(1) NOT NULL DEFAULT 0,
    notes               TEXT NULL,
    recorded_by         INT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_alumni_year (alumni_id, gathering_year),
    FOREIGN KEY (alumni_id) REFERENCES alumni(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. ANNOUNCEMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_id             INT UNSIGNED NULL,                  -- NULL = global
    title               VARCHAR(200) NOT NULL,
    body                TEXT NOT NULL,
    is_published        TINYINT(1) NOT NULL DEFAULT 0,
    published_at        DATETIME NULL,
    expires_at          DATETIME NULL,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_published (is_published, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. RESOURCES (downloadable files)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS resources (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_id             INT UNSIGNED NULL,
    title               VARCHAR(200) NOT NULL,
    description         TEXT NULL,
    file_path           VARCHAR(255) NOT NULL,              -- full URL or relative path under uploads
    file_type           VARCHAR(50) NULL,
    file_size           INT UNSIGNED NULL,
    is_published        TINYINT(1) NOT NULL DEFAULT 0,
    download_count      INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by         INT UNSIGNED NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14. SMS LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sms_logs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_phone     VARCHAR(20) NOT NULL,
    message             TEXT NOT NULL,
    purpose             VARCHAR(80) NULL,
    related_student_id  INT UNSIGNED NULL,
    status              ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    provider_response   TEXT NULL,
    sent_at             DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (related_student_id) REFERENCES students(id) ON DELETE SET NULL,
    INDEX idx_phone (recipient_phone),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15. AUDIT / ACTIVITY LOG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id            INT UNSIGNED NULL,
    action              VARCHAR(100) NOT NULL,
    entity_type         VARCHAR(50) NULL,
    entity_id           INT UNSIGNED NULL,
    old_values          JSON NULL,
    new_values          JSON NULL,
    ip_address          VARCHAR(45) NULL,
    user_agent          VARCHAR(255) NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 16. SETS (global Senior / Junior cohorts)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    set_number          INT UNSIGNED NOT NULL,
    status              ENUM('planned','active_junior','active_senior','graduated')
                        NOT NULL DEFAULT 'planned',
    started_year        YEAR NULL,
    current_sequence    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    graduated_at        DATE NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_set_number (set_number),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 17. SETTINGS (global)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key         VARCHAR(100) NOT NULL,
    setting_value       TEXT NULL,
    description         VARCHAR(255) NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA (minimal – no zones)
-- ============================================================

-- Default super admin (password: admin123 – CHANGE IMMEDIATELY after first login)
-- Hash generated with password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO admins (full_name, email, phone, password_hash, role, zone_id) VALUES
('System Administrator', 'admin@example.com', '08000000000',
 '$2y$10$A.yUNYiyih00Y5jLqygS2.U/b0UbeCx/zIXj10X5BoKoGeJBLinny',
 'super_admin', NULL);

-- Global site settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('app_name', 'Minister Improvement and Training Retreat', 'Application display name'),
('sms_enabled', '0', 'Enable/disable SMS sending'),
('application_close_days', '30', 'Days before resumption when application closes'),
('admission_sms_days', '14', 'Days before resumption to send admission SMS');

-- ------------------------------------------------------------
-- ADMIN NOTIFICATIONS (admin-to-admin messaging)
-- Also available as migrations/001_admin_notifications.sql
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_id       INT UNSIGNED NOT NULL,
    recipient_id    INT UNSIGNED NOT NULL,
    message         TEXT         NOT NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    read_at         DATETIME     NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notif_sender    FOREIGN KEY (sender_id)    REFERENCES admins(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_recipient FOREIGN KEY (recipient_id) REFERENCES admins(id) ON DELETE CASCADE,

    INDEX idx_recipient_read_created (recipient_id, is_read, created_at DESC),
    INDEX idx_recipient_id (recipient_id, id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
