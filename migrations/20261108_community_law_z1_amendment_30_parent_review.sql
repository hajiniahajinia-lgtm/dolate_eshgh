-- بررسی انطباق نسخه اصلاح‌شده با قانون مادر؛ جامعه مادر فقط ارزیابی می‌کند و حق بازنویسی متن محلی را ندارد.
ALTER TABLE community_law_amendment_cases
    MODIFY status ENUM(
        'draft','submitted','local_voting','vote_insufficient','parent_review',
        'parent_review_approved','revision_required','approved','rejected','cancelled'
    ) NOT NULL DEFAULT 'draft';

CREATE TABLE IF NOT EXISTS community_law_amendment_parent_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    parent_community_id INT NOT NULL,
    finding ENUM('compatible','conflict_remains') NOT NULL,
    conflicting_article_references MEDIUMTEXT NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_amendment_parent_reviewer (case_id,reviewer_user_id),
    KEY idx_amendment_parent_review_community (case_id,parent_community_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
