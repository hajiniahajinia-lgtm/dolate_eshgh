-- نظرها و پیشنهادهای اعضا درباره هر ایده، جدا از متن اصلی ایده
CREATE TABLE IF NOT EXISTS community_idea_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idea_id INT NOT NULL,
    topic_id INT NOT NULL,
    community_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_idea_feedback_idea (idea_id, id),
    KEY idx_idea_feedback_topic (topic_id, id),
    KEY idx_idea_feedback_community_user (community_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
