-- گزارش‌های پیشرفت و پیامد قوانین؛ هر گزارش یک سند مستقل و تاریخ‌دار است.
-- ثبت گزارش به‌تنهایی به معنی تأیید یا اثبات موفقیت قانون نیست.
CREATE TABLE IF NOT EXISTS community_law_outcome_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NOT NULL,
    implementation_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    submitted_by INT NOT NULL,
    report_type ENUM('progress','outcome') NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    summary MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    metrics_json JSON NULL,
    review_status ENUM('submitted','under_review','verified','disputed') NOT NULL DEFAULT 'submitted',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_outcome_reports_law (law_id, law_version_id, created_at),
    KEY idx_law_outcome_reports_community (community_id, review_status, created_at),
    KEY idx_law_outcome_reports_submitter (submitted_by, created_at),
    KEY idx_law_outcome_reports_implementation (implementation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
