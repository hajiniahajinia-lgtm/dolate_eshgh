-- پیشنهاد و تصویب چندنفره قواعد رأی‌گیری برای هر نسخه قانون
-- هر دور پیشنهاد مستقل و تغییرناپذیر است؛ حداقل سه ارزیاب مستقل و مهلت هفت‌روزه دارد.
-- تصویب نیازمند موافقت دست‌کم دوسوم رأی‌دهندگانِ شرکت‌کننده است.

CREATE TABLE IF NOT EXISTS community_law_voting_rule_proposals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    proposal_number INT UNSIGNED NOT NULL,
    jurisdiction_level ENUM('local','parent_shared','global_charter','intercommunity') NOT NULL,
    individual_quorum_percent DECIMAL(5,2) NOT NULL,
    individual_approval_percent DECIMAL(5,2) NOT NULL,
    community_quorum_percent DECIMAL(5,2) NULL,
    community_approval_percent DECIMAL(5,2) NULL,
    community_aggregate_quorum_percent DECIMAL(5,2) NULL,
    community_aggregate_approval_percent DECIMAL(5,2) NULL,
    require_community_approval TINYINT(1) NOT NULL,
    individual_vote_basis ENUM('count','weighted') NOT NULL,
    community_vote_basis ENUM('one_community_one_vote','population_weighted') NULL,
    rationale MEDIUMTEXT NOT NULL,
    status ENUM('pending_review','approved','rejected') NOT NULL DEFAULT 'pending_review',
    reviewer_quorum TINYINT UNSIGNED NOT NULL DEFAULT 3,
    approval_threshold_percent DECIMAL(5,2) NOT NULL DEFAULT 66.67,
    configured_by INT NOT NULL,
    configured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    review_deadline DATETIME NOT NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_rule_proposal_number (law_version_id, proposal_number),
    KEY idx_law_rule_proposal_status (status, review_deadline),
    KEY idx_law_rule_proposal_version (law_version_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_voting_rule_electorate (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proposal_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_rule_elector (proposal_id, user_id),
    KEY idx_law_rule_elector_user (user_id, proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_voting_rule_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proposal_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    decision ENUM('approve','reject') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_rule_vote_reviewer (proposal_id, reviewer_user_id),
    KEY idx_law_rule_votes_decision (proposal_id, decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
