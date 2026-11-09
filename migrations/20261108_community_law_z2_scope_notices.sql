CREATE TABLE IF NOT EXISTS community_law_scope_notices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    actor_user_id INT NULL,
    transition ENUM('scope_entered','scope_left') NOT NULL,
    notice_text VARCHAR(500) NOT NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_law_scope_notice_community_unread (community_id, read_at, created_at),
    KEY idx_law_scope_notice_law_version (law_id, law_version_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
