-- تکمیل اقدام اصلاحی فقط پس از ارزیابی مستقل ثبت می‌شود.
ALTER TABLE community_law_outcome_corrective_actions
    ADD COLUMN completion_status ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none'
    AFTER target_date;
ALTER TABLE community_law_outcome_corrective_updates
    MODIFY status ENUM('in_progress','blocked','completed','completion_claimed') NOT NULL;
CREATE TABLE IF NOT EXISTS community_law_outcome_corrective_completion_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    verdict ENUM('confirm','reject') NOT NULL,
    rationale MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_outcome_completion_reviewer (action_id,reviewer_user_id),
    KEY idx_law_outcome_completion_reviews_verdict (action_id,verdict)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
