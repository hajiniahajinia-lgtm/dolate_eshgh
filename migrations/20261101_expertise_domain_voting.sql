-- پیشنهاد و رأی‌گیری جمعی برای ایجاد حوزه‌های تخصصی
-- پیش از اجرا بررسی کنید ستون decided_at از قبل وجود نداشته باشد:
-- SHOW COLUMNS FROM expertise_domains LIKE 'decided_at';
-- این migration داده‌های قبلی را حذف یا بازنویسی نمی‌کند.
ALTER TABLE expertise_domains ADD COLUMN decided_at DATETIME NULL AFTER approved_at;

CREATE TABLE IF NOT EXISTS expertise_domain_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain_id INT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    user_id INT NOT NULL,
    vote ENUM('yes','no') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_domain_vote (domain_id, user_id),
    KEY idx_expertise_domain_vote_result (domain_id, vote),
    KEY idx_expertise_domain_vote_community (community_id, domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
