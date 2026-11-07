-- تشخیص دامنه اختیار و بررسی تعارض با قوانین معتبرِ بالادستی و هم‌سطح
-- سطح global_charter عمداً خودکار تخصیص داده نمی‌شود؛ نیازمند سازوکار منشور مستقل است.

CREATE TABLE IF NOT EXISTS community_law_authority_cases (
    law_version_id BIGINT UNSIGNED NOT NULL,
    derived_jurisdiction_level ENUM('local','parent_shared','intercommunity') NOT NULL,
    status ENUM('pending','confirmed','conflict_found','needs_revision') NOT NULL DEFAULT 'pending',
    reviewer_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    opened_by INT NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (law_version_id),
    KEY idx_law_authority_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_authority_references (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    reference_law_id BIGINT UNSIGNED NOT NULL,
    reference_law_version_id BIGINT UNSIGNED NOT NULL,
    reference_origin_community_id INT NOT NULL,
    relation_type ENUM('higher','same_level','lower_level','peer') NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_authority_reference (law_version_id, reference_law_version_id),
    KEY idx_law_authority_reference_version (law_version_id, relation_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_authority_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    authority_verdict ENUM('confirm','dispute','unclear') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_authority_reviewer (law_version_id, reviewer_user_id),
    KEY idx_law_authority_assessment_version (law_version_id, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_precedent_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_id BIGINT UNSIGNED NOT NULL,
    law_version_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    compatibility_verdict ENUM('compatible','conflict','unclear') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_precedent_reviewer (reference_id, reviewer_user_id),
    KEY idx_law_precedent_assessment_version (law_version_id, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
