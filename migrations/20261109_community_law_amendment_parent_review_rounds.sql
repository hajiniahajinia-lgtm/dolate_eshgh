-- Keep parent compatibility review disagreements open for a fresh independent round.
ALTER TABLE community_law_amendment_parent_reviews
    ADD COLUMN review_round SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER case_id,
    DROP INDEX uq_amendment_parent_reviewer,
    ADD UNIQUE KEY uq_amendment_parent_reviewer_round (case_id,review_round,reviewer_user_id);
