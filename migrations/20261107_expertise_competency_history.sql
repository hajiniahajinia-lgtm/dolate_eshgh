-- تاریخچهٔ تغییرناپذیر نسخه‌های تأییدشدهٔ شایستگی
-- رکورد جاری در expertise_competencies باقی می‌ماند؛ هر نسخهٔ جایگزین‌شده به این جدول افزوده می‌شود.
CREATE TABLE IF NOT EXISTS expertise_competency_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    domain_id INT UNSIGNED NOT NULL,
    level TINYINT UNSIGNED NOT NULL,
    weight DECIMAL(3,2) NOT NULL,
    approved_in_community_id INT NOT NULL,
    source_application_id BIGINT UNSIGNED NOT NULL,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    superseded_at DATETIME NOT NULL,
    superseded_by_application_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expertise_competency_history_user_domain (user_id, domain_id, id),
    KEY idx_expertise_competency_history_source (source_application_id),
    KEY idx_expertise_competency_history_superseded_by (superseded_by_application_id),
    KEY idx_expertise_competency_history_validity (valid_from, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
