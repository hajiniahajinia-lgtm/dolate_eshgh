-- فهرست مستقل ارزیابان هر اعتراض به اصلاح منشور
-- این migration فقط در شاخه توسعه تعریف می‌شود و اجرا نشده است.

CREATE TABLE IF NOT EXISTS community_charter_amendment_appeal_reviewer_assignments (
    appeal_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (appeal_id, reviewer_user_id),
    KEY idx_charter_amendment_appeal_assignment_user (reviewer_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
