-- سازگار با ساختار واقعی جدول expertise_domains در پایگاه دادهٔ اصلی
-- پیش‌تر تأیید شد که decided_at وجود ندارد.
-- ستون proposed_in_community_id برای نسبت‌دادن حوزه به جامعهٔ پیشنهاددهنده افزوده می‌شود.
ALTER TABLE expertise_domains
    ADD COLUMN proposed_in_community_id INT NOT NULL DEFAULT 0,
    ADD COLUMN decided_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS expertise_domain_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    vote ENUM('yes','no') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_domain_vote (domain_id, user_id),
    KEY idx_expertise_domain_vote_result (domain_id, vote),
    KEY idx_expertise_domain_vote_community (community_id, domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
