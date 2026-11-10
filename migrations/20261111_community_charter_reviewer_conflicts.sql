-- محرمانه‌سازی اعلام تعارض و تشکیل هیئت ارزیابی واجد شرایط
-- افزایشی است؛ هیچ داده یا جدول قبلی حذف یا بازنویسی نمی‌شود.

CREATE TABLE IF NOT EXISTS community_charter_amendment_reviewer_candidates (
    amendment_id BIGINT UNSIGNED NOT NULL,
    candidate_user_id INT NOT NULL,
    candidate_order SMALLINT UNSIGNED NOT NULL,
    status ENUM('invited','eligible','assigned','recused','review_pending','conflict','insufficient') NOT NULL DEFAULT 'invited',
    declaration ENUM('no_conflict','recuse','review') NULL,
    conflict_categories JSON NULL,
    private_details MEDIUMTEXT NULL,
    declared_at DATETIME NULL,
    resolved_at DATETIME NULL,
    PRIMARY KEY (amendment_id,candidate_user_id),
    UNIQUE KEY uq_charter_reviewer_candidate_order (amendment_id,candidate_order),
    KEY idx_charter_reviewer_candidate_status (amendment_id,status,candidate_order),
    KEY idx_charter_reviewer_candidate_user (candidate_user_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_amendment_conflict_adjudicators (
    amendment_id BIGINT UNSIGNED NOT NULL,
    adjudicator_user_id INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (amendment_id,adjudicator_user_id),
    KEY idx_charter_conflict_adjudicator_user (adjudicator_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_amendment_conflict_decisions (
    amendment_id BIGINT UNSIGNED NOT NULL,
    candidate_user_id INT NOT NULL,
    adjudicator_user_id INT NOT NULL,
    verdict ENUM('clear','conflict','insufficient') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (amendment_id,candidate_user_id,adjudicator_user_id),
    KEY idx_charter_conflict_decision_candidate (amendment_id,candidate_user_id,verdict)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
