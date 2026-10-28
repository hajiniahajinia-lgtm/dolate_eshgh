CREATE TABLE IF NOT EXISTS community_topic_discussions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    topic_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_topic_discussions_topic_created (topic_id, created_at, id),
    KEY idx_topic_discussions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;