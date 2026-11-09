-- تاریخچهٔ مستقل و تغییرناپذیر جابه‌جایی جوامع در ساختار مادر/زیرشاخه
-- این سابقه حتی در نبود قوانین فعال نیز باید ثبت شود.
CREATE TABLE IF NOT EXISTS community_hierarchy_change_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    community_id INT NOT NULL,
    vote_round_id BIGINT UNSIGNED NOT NULL,
    old_parent_community_id INT NULL,
    new_parent_community_id INT NULL,
    actor_user_id INT NULL,
    event_type ENUM('parent_changed') NOT NULL DEFAULT 'parent_changed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hierarchy_history_vote_round (vote_round_id),
    KEY idx_hierarchy_history_community (community_id, created_at),
    KEY idx_hierarchy_history_actor (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
