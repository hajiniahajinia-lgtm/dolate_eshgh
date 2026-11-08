-- مرحلهٔ دوم رسیدگی مستقل به گزارش مورد اختلاف؛ پرونده و رأی‌ها جدا از گزارش اولیه نگهداری می‌شوند.
CREATE TABLE IF NOT EXISTS community_law_outcome_reassessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    law_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    status ENUM('open','finalized') NOT NULL DEFAULT 'open',
    final_outcome ENUM('confirm_report','revise_execution_plan','needs_more_review','recommend_law_review') NULL,
    finalized_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_outcome_reassessment_report (report_id),
    KEY idx_law_outcome_reassessment_community (community_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_outcome_reassessment_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reassessment_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    outcome ENUM('confirm_report','revise_execution_plan','needs_more_review','recommend_law_review') NOT NULL,
    rationale MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_outcome_reassessment_reviewer (reassessment_id,reviewer_user_id),
    KEY idx_law_outcome_reassessment_reviews_report (report_id,created_at),
    KEY idx_law_outcome_reassessment_reviews_reviewer (reviewer_user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
