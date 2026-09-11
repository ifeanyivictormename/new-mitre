-- ============================================================
-- Migration: Admin Notification System
-- Run after base schema.sql
-- ============================================================

SET NAMES utf8mb4;

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

    -- Fast unread count + recent list for a recipient
    INDEX idx_recipient_read_created (recipient_id, is_read, created_at DESC),
    -- Incremental polling: "give me everything newer than this id for me"
    INDEX idx_recipient_id (recipient_id, id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
