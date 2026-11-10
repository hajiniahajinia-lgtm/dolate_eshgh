-- Allow a documented second review round when the local and parent communities disagree.
ALTER TABLE community_law_conformity_conflict_reviews
    ADD COLUMN review_round SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER conflict_id,
    DROP INDEX uq_conformity_conflict_side,
    ADD UNIQUE KEY uq_conformity_conflict_side_round (conflict_id,review_round,reviewer_community_id);
