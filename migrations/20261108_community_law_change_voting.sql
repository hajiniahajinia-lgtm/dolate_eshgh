-- قواعد و زمان‌بندی رأی جامعهٔ مادر دربارهٔ درخواست تغییر قانون
ALTER TABLE community_law_change_cases
    ADD COLUMN vote_opened_at DATETIME NULL AFTER review_completed_at,
    ADD COLUMN vote_closes_at DATETIME NULL AFTER vote_opened_at,
    ADD COLUMN electorate_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER vote_closes_at,
    ADD COLUMN vote_result ENUM('approved','rejected','insufficient_turnout') NULL AFTER electorate_count,
    ADD COLUMN turnout_numerator SMALLINT UNSIGNED NOT NULL DEFAULT 2 AFTER vote_result,
    ADD COLUMN turnout_denominator SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER turnout_numerator,
    ADD COLUMN approval_numerator SMALLINT UNSIGNED NOT NULL DEFAULT 2 AFTER turnout_denominator,
    ADD COLUMN approval_denominator SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER approval_numerator;
