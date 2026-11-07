-- چارچوب نگهداری قواعد تصویب هر نسخه قانون
-- هیچ حدنصاب یا درصدی به‌صورت پیش‌فرض تعیین نمی‌شود.
-- تا زمان ثبت و تأیید قواعد معتبر، آغاز رأی‌گیری باید متوقف بماند.

CREATE TABLE IF NOT EXISTS community_law_voting_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    jurisdiction_level ENUM(
        'local',
        'parent_shared',
        'global_charter',
        'intercommunity'
    ) NULL,
    individual_quorum_percent DECIMAL(5,2) NULL,
    individual_approval_percent DECIMAL(5,2) NULL,
    community_quorum_percent DECIMAL(5,2) NULL,
    community_approval_percent DECIMAL(5,2) NULL,
    require_community_approval TINYINT(1) NULL,
    individual_vote_basis ENUM('count','weighted') NULL,
    community_vote_basis ENUM('one_community_one_vote','population_weighted') NULL,
    status ENUM('pending','configured','approved','rejected') NOT NULL DEFAULT 'pending',
    configured_by INT NULL,
    configured_at DATETIME NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_voting_rules_version (law_version_id),
    KEY idx_law_voting_rules_status (status, updated_at),
    CONSTRAINT chk_law_individual_quorum
        CHECK (individual_quorum_percent IS NULL OR individual_quorum_percent BETWEEN 0 AND 100),
    CONSTRAINT chk_law_individual_approval
        CHECK (individual_approval_percent IS NULL OR individual_approval_percent BETWEEN 0 AND 100),
    CONSTRAINT chk_law_community_quorum
        CHECK (community_quorum_percent IS NULL OR community_quorum_percent BETWEEN 0 AND 100),
    CONSTRAINT chk_law_community_approval
        CHECK (community_approval_percent IS NULL OR community_approval_percent BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
