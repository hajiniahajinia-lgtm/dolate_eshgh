-- فرایند سخت‌گیرانه و قابل بازبینی اصلاح منشور بنیادین
-- فقط در شاخه توسعه؛ این فایل در این مرحله اجرا نمی‌شود.

CREATE TABLE IF NOT EXISTS community_charter_amendments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    base_charter_version_id BIGINT UNSIGNED NOT NULL,
    proposed_charter_version_id BIGINT UNSIGNED NOT NULL,
    proposer_user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    justification MEDIUMTEXT NOT NULL,
    impact_assessment MEDIUMTEXT NOT NULL,
    status ENUM(
        'draft','independent_review','public_voting','appeal_period',
        'approved_pending_activation','activated','rejected','withdrawn'
    ) NOT NULL DEFAULT 'draft',
    reviewer_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    reviewer_approval_numerator SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    reviewer_approval_denominator SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    voter_turnout_numerator SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    voter_turnout_denominator SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    voter_approval_numerator SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    voter_approval_denominator SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    review_opens_at DATETIME NULL,
    review_closes_at DATETIME NULL,
    voting_opens_at DATETIME NULL,
    voting_closes_at DATETIME NULL,
    appeal_closes_at DATETIME NULL,
    approved_at DATETIME NULL,
    activated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_charter_amendment_proposed_version (proposed_charter_version_id),
    KEY idx_charter_amendment_status (status, created_at),
    KEY idx_charter_amendment_base (base_charter_version_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_amendment_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    amendment_id BIGINT UNSIGNED NOT NULL,
    action ENUM('add','replace','remove') NOT NULL,
    target_article_id BIGINT UNSIGNED NULL,
    article_code VARCHAR(30) NOT NULL,
    proposed_title VARCHAR(200) NULL,
    proposed_text MEDIUMTEXT NULL,
    principle_group ENUM(
        'human_dignity','equality','freedom','justice','peace',
        'living_beings','environment','shared_resources',
        'self_governance','accountability','future_generations',
        'technology'
    ) NULL,
    rationale MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_charter_amendment_items (amendment_id, id),
    KEY idx_charter_amendment_target (target_article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_amendment_reviewers (
    amendment_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    selected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (amendment_id, reviewer_user_id),
    KEY idx_charter_amendment_reviewer (reviewer_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_amendment_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    amendment_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    verdict ENUM('supports','conflict','unclear') NOT NULL,
    rights_protection ENUM('maintained','improved','weakened','unclear') NOT NULL,
    equality_and_non_discrimination ENUM('maintained','improved','weakened','unclear') NOT NULL,
    living_beings_and_environment ENUM('maintained','improved','weakened','unclear') NOT NULL,
    power_and_accountability ENUM('maintained','improved','weakened','unclear') NOT NULL,
    future_generations ENUM('maintained','improved','weakened','unclear') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_charter_amendment_assessment (amendment_id, reviewer_user_id),
    KEY idx_charter_amendment_assessment_result (amendment_id, verdict)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_amendment_electorate (
    amendment_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (amendment_id, user_id),
    KEY idx_charter_amendment_electorate_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_amendment_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    amendment_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    vote ENUM('yes','no','abstain') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_charter_amendment_vote (amendment_id, user_id),
    KEY idx_charter_amendment_votes_result (amendment_id, vote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_amendment_appeals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    amendment_id BIGINT UNSIGNED NOT NULL,
    appellant_user_id INT NOT NULL,
    grounds MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    status ENUM('submitted','under_review','upheld','dismissed') NOT NULL DEFAULT 'submitted',
    resolution MEDIUMTEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_charter_amendment_appeal (amendment_id, status),
    KEY idx_charter_amendment_appellant (appellant_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_amendment_appeal_reviewers (
    appeal_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    verdict ENUM('uphold','dismiss','unclear') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (appeal_id, reviewer_user_id),
    KEY idx_charter_amendment_appeal_reviewer (reviewer_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
