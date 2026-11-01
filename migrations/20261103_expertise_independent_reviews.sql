-- ارزیابی مستقل چهارمعیاره برای درخواست‌های شایستگی تخصصی
-- داده‌های درخواست‌ها و رأی‌های قبلی دست‌نخورده می‌مانند.
CREATE TABLE IF NOT EXISTS expertise_application_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    theoretical_score TINYINT UNSIGNED NULL,
    practical_score TINYINT UNSIGNED NULL,
    achievements_score TINYINT UNSIGNED NULL,
    professional_score TINYINT UNSIGNED NULL,
    conflict_of_interest ENUM('none','declared') NOT NULL DEFAULT 'none',
    review_notes MEDIUMTEXT NULL,
    status ENUM('pending','submitted','recused') NOT NULL DEFAULT 'pending',
    submitted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expertise_application_reviewer (application_id, reviewer_user_id),
    KEY idx_expertise_review_application_status (application_id, status),
    KEY idx_expertise_review_reviewer (reviewer_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
