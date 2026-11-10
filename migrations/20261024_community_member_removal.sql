-- مهاجرت افزونهٔ رأی‌گیری برای اخراج عضو از جامعه
-- این فایل فقط ساختار جدید می‌سازد؛ هیچ جدول یا دادهٔ موجودی را حذف یا تغییر نمی‌دهد.
CREATE TABLE IF NOT EXISTS community_member_removal_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    community_id INT NOT NULL,
    target_user_id INT NOT NULL,
    requester_user_id INT NOT NULL,
    member_count_snapshot INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline_at DATETIME NOT NULL,
    status ENUM('open','approved','rejected') NOT NULL DEFAULT 'open',
    decided_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_cmr_community_status (community_id, status),
    KEY idx_cmr_deadline_status (deadline_at, status),
    KEY idx_cmr_target (community_id, target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_member_removal_votes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    vote ENUM('yes','no') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cmr_vote_per_user (request_id, user_id),
    KEY idx_cmr_votes_request_vote (request_id, vote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
