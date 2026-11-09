-- رأی مستقیم هر فرد در هر نسخه قانون فقط یک‌بار؛ مستقل از تعداد جوامع عضویت
-- این مسیر از رأی اعضا برای شکل‌گیری موضع رسمی هر جامعه جدا نگه داشته می‌شود.

CREATE TABLE IF NOT EXISTS community_law_direct_electorate (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_direct_electorate_member (law_version_id, user_id),
    KEY idx_law_direct_electorate_user (user_id, law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_direct_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    voter_user_id INT NOT NULL,
    vote ENUM('yes','no','abstain') NOT NULL,
    vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    comment TEXT NULL,
    voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_direct_vote_member (law_version_id, voter_user_id),
    KEY idx_law_direct_votes_result (law_version_id, vote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_direct_results (
    law_version_id BIGINT UNSIGNED NOT NULL,
    electorate_count INT UNSIGNED NOT NULL DEFAULT 0,
    yes_count INT UNSIGNED NOT NULL DEFAULT 0,
    no_count INT UNSIGNED NOT NULL DEFAULT 0,
    abstain_count INT UNSIGNED NOT NULL DEFAULT 0,
    yes_weight DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    no_weight DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    counted_at DATETIME NULL,
    PRIMARY KEY (law_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
