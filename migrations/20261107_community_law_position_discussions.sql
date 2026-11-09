-- گفت‌وگوی مستدل اعضای هر جامعه درباره نسخه پیشنهادی قانون
-- رأی‌گیری رسمی جامعه همچنان از جدول‌های electorate و votes موجود استفاده می‌کند.

CREATE TABLE IF NOT EXISTS community_law_position_discussions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    author_user_id INT NOT NULL,
    stance ENUM('support','oppose','neutral') NOT NULL DEFAULT 'neutral',
    argument MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_position_discussion_version_community (law_version_id, community_id, created_at),
    KEY idx_law_position_discussion_author (author_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
