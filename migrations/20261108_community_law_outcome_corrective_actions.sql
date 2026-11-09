-- اقدام‌های اصلاحی ناشی از رسیدگی مستقل؛ تعریف اقدام و گزارش‌های پیشرفت هر دو append-only هستند.
CREATE TABLE IF NOT EXISTS community_law_outcome_corrective_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reassessment_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    law_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    created_by INT NOT NULL,
    responsible_user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    action_details MEDIUMTEXT NOT NULL,
    success_criteria MEDIUMTEXT NOT NULL,
    target_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_outcome_corrective_report (report_id,created_at),
    KEY idx_law_outcome_corrective_assignee (responsible_user_id,community_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_outcome_corrective_updates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    submitted_by INT NOT NULL,
    status ENUM('in_progress','blocked','completed') NOT NULL,
    update_text MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_outcome_corrective_updates_action (action_id,id),
    KEY idx_law_outcome_corrective_updates_report (report_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
