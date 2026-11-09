CREATE TABLE IF NOT EXISTS community_law_version_notices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NOT NULL,
    previous_version_id BIGINT UNSIGNED NULL,
    community_id INT NOT NULL,
    actor_user_id INT NULL,
    event_type VARCHAR(40) NOT NULL,
    notice_text VARCHAR(500) NOT NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_version_notice_transition (law_version_id, community_id, event_type),
    KEY idx_law_version_notice_community_unread (community_id, read_at, created_at),
    KEY idx_law_version_notice_law (law_id, law_version_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
