-- پاسخ مجری و شواهد تکمیلی برای گزارش‌های مورد اختلاف؛ رکوردها تغییرناپذیرند.
CREATE TABLE IF NOT EXISTS community_law_outcome_followups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    submitted_by INT NOT NULL,
    entry_type ENUM('executor_response','additional_evidence') NOT NULL,
    body MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_outcome_followups_report (report_id, created_at, id),
    KEY idx_law_outcome_followups_author (submitted_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
