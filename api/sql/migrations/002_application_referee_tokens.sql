-- Secure, single-use continuation tokens for remote referees.
CREATE TABLE IF NOT EXISTS application_referee_tokens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id   INT UNSIGNED NOT NULL,
    token_hash   CHAR(64) NOT NULL UNIQUE,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_referee_student (student_id),
    INDEX idx_referee_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
