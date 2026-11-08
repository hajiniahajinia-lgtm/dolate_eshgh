-- هستهٔ اولیهٔ نظام قوانین بالادستی دولت عشق
-- فقط جدول‌های جدید ایجاد می‌شوند؛ هیچ جدول یا دادهٔ موجودی تغییر یا حذف نمی‌شود.
-- تصویب، حدنصاب‌ها و ابلاغ در فایل‌های اجرایی بعدی پیاده‌سازی می‌شوند.

CREATE TABLE IF NOT EXISTS community_laws (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_code VARCHAR(40) NOT NULL,
    originating_community_id INT NOT NULL,
    created_by INT NOT NULL,
    status ENUM('draft','proposed','voting','approved','rejected','suspended','repealed') NOT NULL DEFAULT 'draft',
    current_version_id BIGINT UNSIGNED NULL,
    effective_at DATETIME NULL,
    expires_at DATETIME NULL,
    repealed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_community_law_code (law_code),
    KEY idx_community_laws_origin_status (originating_community_id, status, created_at),
    KEY idx_community_laws_status_effective (status, effective_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    law_text MEDIUMTEXT NOT NULL,
    implementation_plan MEDIUMTEXT NULL,
    change_summary TEXT NULL,
    source_topic_id INT NULL,
    source_idea_id INT NULL,
    proposed_by INT NOT NULL,
    status ENUM('draft','proposed','voting','approved','rejected','superseded') NOT NULL DEFAULT 'draft',
    vote_started_at DATETIME NULL,
    vote_deadline DATETIME NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_community_law_version (law_id, version_number),
    KEY idx_law_versions_status (law_id, status, created_at),
    KEY idx_law_versions_source_topic (source_topic_id),
    KEY idx_law_versions_source_idea (source_idea_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_scopes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    scope_community_id INT NOT NULL,
    include_descendants TINYINT(1) NOT NULL DEFAULT 0,
    scope_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_scope_root (law_version_id, scope_community_id),
    KEY idx_law_scope_community (scope_community_id, law_version_id),
    KEY idx_law_scope_descendants (law_version_id, include_descendants)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_electorate (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    voting_community_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_electorate_member (law_version_id, voting_community_id, user_id),
    KEY idx_law_electorate_community (voting_community_id, law_version_id),
    KEY idx_law_electorate_user (user_id, law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    voting_community_id INT NOT NULL,
    voter_user_id INT NOT NULL,
    vote ENUM('yes','no','abstain') NOT NULL,
    vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    comment TEXT NULL,
    voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_vote_member (law_version_id, voting_community_id, voter_user_id),
    KEY idx_law_votes_result (law_version_id, voting_community_id, vote),
    KEY idx_law_votes_user (voter_user_id, law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_positions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    electorate_count INT UNSIGNED NOT NULL DEFAULT 0,
    yes_count INT UNSIGNED NOT NULL DEFAULT 0,
    no_count INT UNSIGNED NOT NULL DEFAULT 0,
    abstain_count INT UNSIGNED NOT NULL DEFAULT 0,
    yes_weight DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    no_weight DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    position_result ENUM('pending','approve','reject','insufficient') NOT NULL DEFAULT 'pending',
    decided_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_position_community (law_version_id, community_id),
    KEY idx_law_positions_community (community_id, position_result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_objections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    submitted_by INT NOT NULL,
    objection_type ENUM('charter_conflict','jurisdiction_conflict','rights_impact','implementation_barrier','other') NOT NULL DEFAULT 'other',
    description MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    status ENUM('pending','under_review','upheld','dismissed','withdrawn') NOT NULL DEFAULT 'pending',
    reviewer_user_id INT NULL,
    decision_note MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_law_objections_version_status (law_version_id, status, created_at),
    KEY idx_law_objections_community (community_id, status),
    KEY idx_law_objections_submitter (submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_implementations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    responsible_user_id INT NULL,
    local_plan MEDIUMTEXT NULL,
    status ENUM('pending','in_progress','blocked','completed','not_applicable') NOT NULL DEFAULT 'pending',
    started_at DATETIME NULL,
    target_date DATE NULL,
    completed_at DATETIME NULL,
    progress_report MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_implementation_community (law_version_id, community_id),
    KEY idx_law_implementation_status (community_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NULL,
    community_id INT NULL,
    actor_user_id INT NULL,
    event_type VARCHAR(60) NOT NULL,
    details MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_history_law (law_id, id),
    KEY idx_law_history_community (community_id, id),
    KEY idx_law_history_actor (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
