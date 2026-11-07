-- نتیجه مرکب رأی‌گیری؛ این نتیجه به‌تنهایی قانون را لازم‌الاجرا نمی‌کند.
-- لازم‌الاجرا شدن همچنان به بررسی صلاحیت، منشور بنیادین و ابلاغ معتبر وابسته است.

CREATE TABLE IF NOT EXISTS community_law_final_results (
    law_version_id BIGINT UNSIGNED NOT NULL,
    individual_vote_basis ENUM('count','weighted') NOT NULL,
    individual_eligible DECIMAL(18,4) NOT NULL DEFAULT 0,
    individual_participating DECIMAL(18,4) NOT NULL DEFAULT 0,
    individual_yes DECIMAL(18,4) NOT NULL DEFAULT 0,
    individual_no DECIMAL(18,4) NOT NULL DEFAULT 0,
    individual_abstain DECIMAL(18,4) NOT NULL DEFAULT 0,
    individual_turnout_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    individual_approval_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    require_community_approval TINYINT(1) NOT NULL DEFAULT 0,
    community_vote_basis ENUM('one_community_one_vote','population_weighted') NULL,
    community_aggregate_quorum_percent DECIMAL(5,2) NULL,
    community_aggregate_approval_percent DECIMAL(5,2) NULL,
    community_eligible DECIMAL(18,4) NOT NULL DEFAULT 0,
    community_participating DECIMAL(18,4) NOT NULL DEFAULT 0,
    community_yes DECIMAL(18,4) NOT NULL DEFAULT 0,
    community_no DECIMAL(18,4) NOT NULL DEFAULT 0,
    community_turnout_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    community_approval_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
    final_status ENUM('vote_passed','vote_rejected','insufficient') NOT NULL,
    calculated_by INT NULL,
    calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (law_version_id),
    KEY idx_law_final_results_status (final_status, calculated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
