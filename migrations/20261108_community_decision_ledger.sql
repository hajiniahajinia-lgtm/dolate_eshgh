-- دفتر مستقل تصمیم‌های جمعی؛ مستقل از پرونده‌های کتابخانه دانش.
-- این مهاجرت فقط ساختار افزایشی ایجاد می‌کند و هیچ داده موجودی را جابه‌جا یا حذف نمی‌کند.
CREATE TABLE IF NOT EXISTS community_decision_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    community_id INT NOT NULL,
    source_topic_id INT NOT NULL,
    source_idea_id INT NULL,
    knowledge_entry_id BIGINT UNSIGNED NULL,
    topic_purpose_snapshot ENUM('knowledge','execution','mixed') NOT NULL DEFAULT 'mixed',
    selected_idea_content MEDIUMTEXT NULL,
    acceptance_status ENUM('accepted','not_accepted','no_votes') NOT NULL DEFAULT 'no_votes',
    enforceability_status ENUM('enforceable','not_enforceable','undecided') NOT NULL DEFAULT 'undecided',
    yes_count INT UNSIGNED NOT NULL DEFAULT 0,
    no_count INT UNSIGNED NOT NULL DEFAULT 0,
    yes_weight DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    no_weight DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    yes_percent DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    weighted_yes_percent DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    enforce_yes_count INT UNSIGNED NOT NULL DEFAULT 0,
    enforce_no_count INT UNSIGNED NOT NULL DEFAULT 0,
    record_status ENUM('recorded','superseded','voided') NOT NULL DEFAULT 'recorded',
    decided_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_community_decision_source_topic (source_topic_id),
    KEY idx_decision_knowledge_entry (knowledge_entry_id),
    KEY idx_community_decision_community_date (community_id, decided_at, id),
    KEY idx_community_decision_status (record_status, decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
