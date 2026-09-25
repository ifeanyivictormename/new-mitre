ALTER TABLE applications
    ADD COLUMN submitted_at DATETIME NULL AFTER reviewed_at,
    ADD COLUMN referee_completed_at DATETIME NULL AFTER submitted_at;
