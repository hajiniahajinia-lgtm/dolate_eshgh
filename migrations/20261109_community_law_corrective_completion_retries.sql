-- امکان اصلاح اقدام پس از رد تأیید تکمیل؛ هر دور بررسی مستقل و تغییرناپذیر است.
ALTER TABLE community_law_outcome_corrective_actions
    ADD COLUMN completion_review_round SMALLINT UNSIGNED NOT NULL DEFAULT 1
    AFTER completion_status;
ALTER TABLE community_law_outcome_corrective_completion_reviews
    ADD COLUMN review_round SMALLINT UNSIGNED NOT NULL DEFAULT 1
    AFTER action_id,
    DROP INDEX uq_law_outcome_completion_reviewer,
    ADD UNIQUE KEY uq_law_outcome_completion_reviewer_round (action_id,review_round,reviewer_user_id),
    ADD KEY idx_law_outcome_completion_round (action_id,review_round,verdict);
