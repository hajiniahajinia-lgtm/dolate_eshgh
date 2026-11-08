-- تکمیل مرحلهٔ ارزیابی مستقل درخواست تغییر قانون
ALTER TABLE community_law_change_cases
    ADD COLUMN review_result ENUM('supported','dismissed','inconclusive') NULL AFTER status,
    ADD COLUMN review_summary MEDIUMTEXT NULL AFTER decision_note,
    ADD COLUMN review_completed_at DATETIME NULL AFTER review_summary;

ALTER TABLE community_law_community_notices
    MODIFY event_type ENUM(
        'change_requested','suspension_requested','suspended','repealed',
        'expiry_review_requested','reinstated'
    ) NOT NULL;
