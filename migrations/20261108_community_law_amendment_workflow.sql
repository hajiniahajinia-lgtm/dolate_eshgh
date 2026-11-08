-- گردش‌کار پیشنهاد اصلاح محدود و نسخه‌دار برای قوانین محلی
-- نسخه مصوب قبلی هرگز بازنویسی نمی‌شود؛ نسخه پیشنهادی تا تصویب نهایی draft می‌ماند.
CREATE TABLE IF NOT EXISTS community_law_amendment_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_id BIGINT UNSIGNED NOT NULL,
    base_version_id BIGINT UNSIGNED NOT NULL,
    proposed_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    proposed_by INT NOT NULL,
    status ENUM('draft','submitted','local_voting','parent_review','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
    rationale MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    submitted_at DATETIME NULL,
    decided_at DATETIME NULL,
    decision_note MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_amendment_proposed_version (proposed_version_id),
    KEY idx_law_amendment_law_status (law_id,status,created_at),
    KEY idx_law_amendment_community_status (community_id,status,created_at),
    KEY idx_law_amendment_base_version (base_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_amendment_articles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    article_reference VARCHAR(120) NOT NULL,
    previous_text MEDIUMTEXT NOT NULL,
    proposed_text MEDIUMTEXT NOT NULL,
    change_reason MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_amendment_article (case_id,article_reference),
    KEY idx_law_amendment_article_case (case_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_amendment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    actor_user_id INT NULL,
    event_type VARCHAR(60) NOT NULL,
    details MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_law_amendment_event_case (case_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
