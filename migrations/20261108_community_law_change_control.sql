-- گردش‌کار مستقل درخواست تعلیق، لغو یا بازبینی اعتبار قانون
-- ثبت درخواست هیچ اثر خودکاری بر وضعیت قانون یا اجرای زیرجامعه‌ها ندارد.

CREATE TABLE IF NOT EXISTS community_law_change_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NOT NULL,
    request_type ENUM('suspend','repeal','review_expiry') NOT NULL,
    status ENUM('submitted','under_review','review_complete','awaiting_vote','approved','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    requested_by INT NOT NULL,
    requester_community_id INT NOT NULL,
    reason MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    emergency_requested TINYINT(1) NOT NULL DEFAULT 0,
    requested_effective_at DATETIME NULL,
    decided_by INT NULL,
    decision_note MEDIUMTEXT NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_change_case_law_status (law_id, status, created_at),
    KEY idx_law_change_case_version (law_version_id, id),
    KEY idx_law_change_case_requester (requested_by, created_at),
    KEY idx_law_change_case_community (requester_community_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_change_reviewers (
    case_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    assigned_by INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (case_id, reviewer_user_id),
    KEY idx_law_change_reviewer_user (reviewer_user_id, case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_change_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    verdict ENUM('uphold','dismiss','unclear') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_change_assessment_reviewer (case_id, reviewer_user_id),
    KEY idx_law_change_assessment_case (case_id, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_change_electorate (
    case_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (case_id, user_id),
    KEY idx_law_change_electorate_user (user_id, case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_change_votes (
    case_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    vote ENUM('yes','no','abstain') NOT NULL,
    comment TEXT NULL,
    voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (case_id, user_id),
    KEY idx_law_change_vote_result (case_id, vote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_community_notices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    law_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    event_type ENUM('suspension_requested','suspended','repealed','expiry_review_requested','reinstated') NOT NULL,
    notice_text VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_notice_case_community_event (case_id, community_id, event_type),
    KEY idx_law_notice_community_unread (community_id, read_at, created_at),
    KEY idx_law_notice_law (law_id, law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
