-- ایجاد جدول‌های وظایف و رویکردهای پیشنهادی جامعه
-- این اسکریپت فقط جدول‌های جدید می‌سازد؛ جدول‌ها یا داده‌های فعلی را حذف نمی‌کند.
CREATE TABLE IF NOT EXISTS community_duties (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    community_id INT NOT NULL,
    user_id INT NOT NULL,
    proposal_type ENUM('duty', 'approach') NOT NULL,
    content VARCHAR(600) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_community_duties_community (community_id),
    KEY idx_community_duties_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_duty_votes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    community_id INT NOT NULL,
    proposal_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_community_duty_vote (community_id, user_id),
    KEY idx_community_duty_votes_proposal (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
