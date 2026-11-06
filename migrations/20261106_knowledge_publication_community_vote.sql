-- پیشنهاد و رأی اعضای جامعه برای عمومی‌کردن پروندهٔ دانش.
-- منشأ اولیه در community_id پرونده حفظ می‌شود؛ این مهاجرت دادهٔ قبلی را حذف نمی‌کند.

CREATE TABLE IF NOT EXISTS collective_knowledge_publication_proposals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    proposed_by INT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_knowledge_publication_entry_status (entry_id, status, id),
    KEY idx_knowledge_publication_community_status (community_id, status, created_at),
    KEY idx_knowledge_publication_proposer (proposed_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collective_knowledge_publication_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proposal_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    vote ENUM('approve','reject') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_knowledge_publication_vote_user (proposal_id, user_id),
    KEY idx_knowledge_publication_vote_result (proposal_id, vote),
    KEY idx_knowledge_publication_vote_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
