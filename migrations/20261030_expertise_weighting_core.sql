-- هستهٔ اولیهٔ نظام حوزه‌های تخصصی و شایستگی
-- فقط جدول‌های جدید ایجاد می‌شوند؛ هیچ جدول یا دادهٔ قبلی تغییر یا حذف نمی‌شود.

CREATE TABLE IF NOT EXISTS expertise_domains (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    proposed_by INT NOT NULL,
    proposed_in_community_id INT NOT NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expertise_domain_parent (parent_id, status),
    KEY idx_expertise_domain_community (proposed_in_community_id, status),
    KEY idx_expertise_domain_proposer (proposed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expertise_criteria_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain_id INT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    criteria_text MEDIUMTEXT NOT NULL,
    status ENUM('pending','approved','rejected','superseded') NOT NULL DEFAULT 'pending',
    proposed_by INT NOT NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_criteria_version (domain_id, version_number),
    KEY idx_expertise_criteria_domain_status (domain_id, status),
    KEY idx_expertise_criteria_community (community_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expertise_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_code CHAR(32) NOT NULL,
    community_id INT NOT NULL,
    domain_id INT UNSIGNED NOT NULL,
    target_user_id INT NOT NULL,
    created_by INT NOT NULL,
    request_type ENUM('initial','upgrade','renewal','reassessment','reduction') NOT NULL,
    requested_level TINYINT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected','needs_renewal','under_review') NOT NULL DEFAULT 'pending',
    criteria_version_id BIGINT UNSIGNED NULL,
    decision_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_application_code (application_code),
    KEY idx_expertise_application_target (target_user_id, domain_id, status),
    KEY idx_expertise_application_community (community_id, status, created_at),
    KEY idx_expertise_application_domain (domain_id, status),
    KEY idx_expertise_application_creator (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expertise_application_requesters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    requester_user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_requester (application_id, requester_user_id),
    KEY idx_expertise_requester_user (requester_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expertise_application_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    submitted_by INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    reference_url VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expertise_evidence_application (application_id, id),
    KEY idx_expertise_evidence_submitter (submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expertise_application_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    voter_user_id INT NOT NULL,
    vote ENUM('yes','no') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_application_vote (application_id, voter_user_id),
    KEY idx_expertise_vote_result (application_id, vote),
    KEY idx_expertise_vote_user (voter_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expertise_application_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expertise_comment_application (application_id, id),
    KEY idx_expertise_comment_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expertise_competencies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    domain_id INT UNSIGNED NOT NULL,
    level TINYINT UNSIGNED NOT NULL,
    weight DECIMAL(3,2) NOT NULL,
    approved_in_community_id INT NOT NULL,
    source_application_id BIGINT UNSIGNED NOT NULL,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_user_domain (user_id, domain_id),
    KEY idx_expertise_domain_level (domain_id, level),
    KEY idx_expertise_validity (valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expertise_application_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    actor_user_id INT NULL,
    event_type VARCHAR(50) NOT NULL,
    details MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expertise_history_application (application_id, id),
    KEY idx_expertise_history_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expertise_ai_analyses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    analysis_text MEDIUMTEXT NOT NULL,
    confidence DECIMAL(5,2) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_ai_version (application_id, version_number),
    KEY idx_expertise_ai_application (application_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
