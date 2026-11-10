-- اعمال اثر حقوقی رأی معتبر و نگهداری snapshot اجرای زیرجامعه‌ها
ALTER TABLE community_law_change_cases
    MODIFY request_type ENUM('suspend','repeal','review_expiry','reinstate') NOT NULL,
    ADD COLUMN effect_applied_at DATETIME NULL AFTER decided_at,
    ADD COLUMN effect_applied_by INT NULL AFTER effect_applied_at,
    ADD COLUMN effect_summary MEDIUMTEXT NULL AFTER effect_applied_by;

ALTER TABLE community_laws
    ADD COLUMN suspended_at DATETIME NULL AFTER repealed_at,
    ADD COLUMN suspension_case_id BIGINT UNSIGNED NULL AFTER suspended_at,
    ADD COLUMN last_status_change_case_id BIGINT UNSIGNED NULL AFTER suspension_case_id,
    ADD KEY idx_community_laws_suspension_case (suspension_case_id);

ALTER TABLE community_law_implementations
    MODIFY status ENUM('pending','in_progress','blocked','completed','not_applicable','suspended') NOT NULL DEFAULT 'pending';

ALTER TABLE community_law_community_notices
    MODIFY event_type ENUM(
        'change_requested','change_vote_result','suspension_requested','suspended',
        'repealed','expiry_review_requested','expiry_updated','reinstated'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS community_law_change_implementation_snapshots (
    case_id BIGINT UNSIGNED NOT NULL,
    law_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    previous_responsible_user_id INT NULL,
    previous_local_plan MEDIUMTEXT NULL,
    previous_status ENUM('pending','in_progress','blocked','completed','not_applicable','suspended') NOT NULL,
    previous_started_at DATETIME NULL,
    previous_target_date DATE NULL,
    previous_completed_at DATETIME NULL,
    previous_progress_report MEDIUMTEXT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (case_id, community_id),
    KEY idx_law_change_snapshot_law (law_id, law_version_id, community_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
