-- ثبت منشأ قواعد رأی‌گیریِ اصلاحیه و پذیرش صریح استفاده از قواعد نسخهٔ مبنا
-- هیچ قانون یا نسخهٔ مصوب جاری در این migration تغییر نمی‌کند.
ALTER TABLE community_law_amendment_cases
    ADD COLUMN voting_rules_source_version_id BIGINT UNSIGNED NULL AFTER conformity_case_id,
    ADD COLUMN rules_adopted_by INT NULL AFTER proposed_by,
    ADD COLUMN rules_adopted_at DATETIME NULL AFTER rules_adopted_by,
    ADD KEY idx_law_amendment_rules_source (voting_rules_source_version_id);
