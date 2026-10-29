-- نگهداری نسخه‌های قبلی ایده‌ها هنگام اصلاح پس از مباحثه
CREATE TABLE IF NOT EXISTS community_idea_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idea_id INT NOT NULL,
    editor_user_id INT NOT NULL,
    previous_content MEDIUMTEXT NOT NULL,
    revision_note VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_idea_revisions_idea_id (idea_id, id),
    KEY idx_idea_revisions_editor (editor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
