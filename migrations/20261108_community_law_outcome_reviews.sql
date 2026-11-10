-- ارزیابی مستقل گزارش‌های پیامد؛ هر ارزیابی یک رکورد تغییرناپذیر است.
CREATE TABLE IF NOT EXISTS community_law_outcome_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    verdict ENUM('confirm','challenge') NOT NULL,
    rationale MEDIUMTEXT NOT NULL,
    human_impact MEDIUMTEXT NULL,
    social_impact MEDIUMTEXT NULL,
    environmental_impact MEDIUMTEXT NULL,
    economic_impact MEDIUMTEXT NULL,
    evidence MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_outcome_review_reviewer (report_id, reviewer_user_id),
    KEY idx_law_outcome_reviews_report (report_id, created_at),
    KEY idx_law_outcome_reviews_reviewer (reviewer_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
