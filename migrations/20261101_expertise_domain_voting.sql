-- ساختار ستون‌های proposed_in_community_id و decided_at در دیتابیس واقعی موجود است.
-- این مهاجرت فقط جدول رأی‌گیری حوزه‌های تخصصی را ایجاد می‌کند.
CREATE TABLE IF NOT EXISTS expertise_domain_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain_id BIGINT UNSIGNED NOT NULL,
    community_id BIGINT NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    vote ENUM('yes','no') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_domain_vote (domain_id, user_id),
    KEY idx_expertise_domain_vote_result (domain_id, vote),
    KEY idx_expertise_domain_vote_community (community_id, domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
