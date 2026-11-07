-- پروفایل نسخه‌دار چندحوزه‌ای برای تعیین سهم تخصص‌ها در هر موضوع
-- هر نسخه پس از رأی جامعه تغییرناپذیر است؛ اصلاحات در نسخه تازه ثبت می‌شوند.
CREATE TABLE IF NOT EXISTS topic_expertise_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    topic_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected','superseded') NOT NULL DEFAULT 'pending',
    proposed_by INT NOT NULL,
    expires_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_topic_expertise_profile_version (topic_id, version_number),
    KEY idx_topic_expertise_profile_topic_status (topic_id, status, version_number),
    KEY idx_topic_expertise_profile_community_status (community_id, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS topic_expertise_profile_domains (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id BIGINT UNSIGNED NOT NULL,
    domain_id INT UNSIGNED NOT NULL,
    share_percent DECIMAL(5,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_topic_expertise_profile_domain (profile_id, domain_id),
    KEY idx_topic_expertise_profile_domain_domain (domain_id, profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS topic_expertise_profile_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    vote ENUM('yes','no') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_topic_expertise_profile_voter (profile_id, user_id),
    KEY idx_topic_expertise_profile_vote (profile_id, vote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- snapshot پروفایل تخصصی و وزن هر رأی‌دهنده در لحظه آغاز رأی‌گیری قانون
CREATE TABLE IF NOT EXISTS community_law_expertise_profile_snapshots (
    law_version_id BIGINT UNSIGNED NOT NULL,
    profile_id BIGINT UNSIGNED NOT NULL,
    topic_id BIGINT UNSIGNED NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (law_version_id),
    KEY idx_law_expertise_profile_snapshot (profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_law_expertise_profile_domains (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    domain_id INT UNSIGNED NOT NULL,
    share_percent DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_expertise_domain (law_version_id, domain_id),
    KEY idx_law_expertise_domain (domain_id, law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_law_expertise_voter_weights (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    profile_id BIGINT UNSIGNED NOT NULL,
    final_vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_expertise_voter_weight (law_version_id, user_id),
    KEY idx_law_expertise_voter_weight_user (user_id, law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_law_expertise_weight_details (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    domain_id INT UNSIGNED NOT NULL,
    share_percent DECIMAL(5,2) NOT NULL,
    competency_id BIGINT UNSIGNED NULL,
    source_application_id BIGINT UNSIGNED NULL,
    competency_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    weighted_contribution DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_expertise_weight_detail (law_version_id, user_id, domain_id),
    KEY idx_law_expertise_weight_detail_competency (competency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- snapshot اعضای واجد رأی هنگام ثبت هر پیشنهاد؛ اعضای جدید بعداً وارد پنل نمی‌شوند.
CREATE TABLE IF NOT EXISTS topic_expertise_profile_electorate (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    profile_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_topic_expertise_profile_elector (profile_id, user_id),
    KEY idx_topic_expertise_profile_elector_user (user_id, profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
