-- مرحله تصویب نهایی، مهلت اعتراض و رسیدگی مستقل به اعتراض‌ها
-- این migration فقط روی شاخه توسعه ثبت می‌شود؛ اجرای آن روی هیچ پایگاه داده‌ای انجام نشده است.
-- قانون تا تکمیل بررسی نهایی، پایان مهلت اعتراض و رسیدگی به اعتراض‌های باز، فعال نمی‌شود.

ALTER TABLE community_laws
    MODIFY status ENUM(
        'draft','proposed','voting','pending_final_review',
        'appeal_period','enactment_pending','approved',
        'rejected','suspended','repealed'
    ) NOT NULL DEFAULT 'draft';

ALTER TABLE community_law_versions
    MODIFY status ENUM(
        'draft','proposed','voting','pending_final_review',
        'appeal_period','enactment_pending','approved',
        'rejected','superseded'
    ) NOT NULL DEFAULT 'draft';

CREATE TABLE IF NOT EXISTS community_law_final_review_cases (
    law_version_id BIGINT UNSIGNED NOT NULL,
    status ENUM(
        'pending','review_clear','conflict_found','needs_revision',
        'appeal_period','appeal_hold','ready_for_enactment',
        'enacted','rejected'
    ) NOT NULL DEFAULT 'pending',
    reviewer_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    appeal_period_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    appeal_deadline DATETIME NULL,
    opened_by INT NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    review_completed_at DATETIME NULL,
    enacted_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (law_version_id),
    KEY idx_law_final_review_status (status, appeal_deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_final_review_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    verdict ENUM('confirm','dispute','unclear') NOT NULL,
    charter_and_rights ENUM('pass','conflict','unclear') NOT NULL,
    non_discrimination ENUM('pass','conflict','unclear') NOT NULL,
    power_concentration ENUM('pass','conflict','unclear') NOT NULL,
    human_and_social_impact ENUM('pass','conflict','unclear') NOT NULL,
    environmental_impact ENUM('pass','conflict','unclear') NOT NULL,
    economic_and_resource_impact ENUM('pass','conflict','unclear') NOT NULL,
    future_generations_and_other_life ENUM('pass','conflict','unclear') NOT NULL,
    implementation_and_accountability ENUM('pass','conflict','unclear') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_final_review_assessor (law_version_id, reviewer_user_id),
    KEY idx_law_final_review_assessment_version (law_version_id, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_final_review_electorate (
    law_version_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (law_version_id, user_id),
    KEY idx_law_final_review_electorate_user (user_id, law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_final_review_references (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    reference_law_id BIGINT UNSIGNED NOT NULL,
    reference_law_version_id BIGINT UNSIGNED NOT NULL,
    reference_origin_community_id INT NOT NULL,
    relation_type ENUM('higher','same_level','lower_level','peer') NOT NULL,
    scope_fingerprint CHAR(64) NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_final_review_reference (law_version_id, reference_law_version_id),
    KEY idx_law_final_review_reference_version (law_version_id, relation_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_appeals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    submitted_by INT NOT NULL,
    appeal_type ENUM(
        'charter_conflict','jurisdiction_conflict','rights_violation',
        'discrimination','power_concentration','environmental_harm',
        'future_generations','implementation_risk','new_evidence','other'
    ) NOT NULL DEFAULT 'other',
    description MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    status ENUM('submitted','under_review','upheld','dismissed','withdrawn') NOT NULL DEFAULT 'submitted',
    reviewer_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    decision_note MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_law_appeals_version_status (law_version_id, status, created_at),
    KEY idx_law_appeals_community (community_id, status),
    KEY idx_law_appeals_submitter (submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_appeal_electorate (
    appeal_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (appeal_id, user_id),
    KEY idx_law_appeal_electorate_user (user_id, appeal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_appeal_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appeal_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    verdict ENUM('uphold','dismiss','unclear') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_appeal_reviewer (appeal_id, reviewer_user_id),
    KEY idx_law_appeal_assessment_appeal (appeal_id, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
