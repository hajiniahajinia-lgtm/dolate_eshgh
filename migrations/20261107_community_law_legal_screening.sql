-- غربالگری حقوقی و انطباق اولیه پیش از رأی‌گیری
-- این مرحله جایگزین بررسی صلاحیت نهایی یا تفسیر معتبر منشور بنیادین نیست.

CREATE TABLE IF NOT EXISTS community_law_review_cases (
    law_version_id BIGINT UNSIGNED NOT NULL,
    originating_community_id INT NOT NULL,
    status ENUM('pending','screening_clear','conflict_found','needs_revision') NOT NULL DEFAULT 'pending',
    reviewer_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    opened_by INT NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (law_version_id),
    KEY idx_law_review_cases_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_review_electorate (
    law_version_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (law_version_id, user_id),
    KEY idx_law_review_electorate_user (user_id, law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_review_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    charter_compatibility ENUM('compliant','conflict','unclear') NOT NULL,
    hierarchy_compatibility ENUM('compliant','conflict','unclear') NOT NULL,
    rights_impact ENUM('compliant','conflict','unclear') NOT NULL,
    environmental_impact ENUM('compliant','conflict','unclear') NOT NULL,
    implementation_feasibility ENUM('compliant','conflict','unclear') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_review_assessor (law_version_id, reviewer_user_id),
    KEY idx_law_review_assessment_version (law_version_id, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
