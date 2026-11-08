-- پرونده‌های بررسی سازگاری قوانین محلی پس از تغییر سلسله‌مراتب
-- ایجاد پرونده به معنی اثبات تعارض نیست؛ مواد متعارض باید مستند و انسانی بررسی شوند.

CREATE TABLE IF NOT EXISTS community_law_conformity_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    community_id INT NOT NULL,
    parent_community_id INT NOT NULL,
    local_law_id BIGINT UNSIGNED NOT NULL,
    local_law_version_id BIGINT UNSIGNED NOT NULL,
    parent_law_id BIGINT UNSIGNED NOT NULL,
    parent_law_version_id BIGINT UNSIGNED NOT NULL,
    status ENUM('review_required','amendment_required','amendment_in_progress','resolved','closed') NOT NULL DEFAULT 'review_required',
    trigger_type ENUM('parent_changed','manual_review') NOT NULL DEFAULT 'parent_changed',
    opened_by INT NULL,
    review_summary MEDIUMTEXT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_conformity_pair (community_id,local_law_version_id,parent_law_version_id),
    KEY idx_law_conformity_community_status (community_id,status,created_at),
    KEY idx_law_conformity_parent (parent_community_id,parent_law_version_id),
    KEY idx_law_conformity_local_law (local_law_id,local_law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_conformity_conflicts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    local_article_reference VARCHAR(120) NOT NULL,
    parent_article_reference VARCHAR(120) NOT NULL,
    conflict_description MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    finding ENUM('unreviewed','conflict_confirmed','no_conflict','needs_more_evidence') NOT NULL DEFAULT 'unreviewed',
    finding_reason MEDIUMTEXT NULL,
    identified_by INT NOT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_conformity_conflict_case (case_id,id),
    KEY idx_law_conformity_conflict_finding (case_id,finding)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_conformity_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    actor_user_id INT NULL,
    event_type VARCHAR(60) NOT NULL,
    details MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_conformity_event_case (case_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
