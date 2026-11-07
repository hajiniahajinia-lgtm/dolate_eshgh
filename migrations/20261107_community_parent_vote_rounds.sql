-- دورهای مستقل و زمان‌دار برای رأی‌گیری درباره جایگاه جوامع
-- جدول قدیمی community_parent_votes حفظ می‌شود و هیچ رأی یا سابقه‌ای حذف نمی‌شود.
CREATE TABLE IF NOT EXISTS community_parent_vote_rounds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    community_id INT NOT NULL,
    round_number INT UNSIGNED NOT NULL,
    current_parent_id INT NULL,
    status ENUM('open','approved','rejected','insufficient','invalidated') NOT NULL DEFAULT 'open',
    electorate_count INT UNSIGNED NOT NULL DEFAULT 0,
    quorum_required INT UNSIGNED NOT NULL DEFAULT 2,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline DATETIME NOT NULL,
    decided_at DATETIME NULL,
    winning_parent_id INT NULL,
    yes_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parent_vote_round_number (community_id, round_number),
    KEY idx_parent_vote_round_status (community_id, status, deadline),
    KEY idx_parent_vote_round_deadline (status, deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_parent_vote_electorate (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    round_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parent_vote_elector (round_id, user_id),
    KEY idx_parent_vote_elector_user (user_id, round_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_parent_round_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    round_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    parent_community_id INT NULL,
    voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parent_round_vote_user (round_id, user_id),
    KEY idx_parent_round_vote_choice (round_id, parent_community_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
