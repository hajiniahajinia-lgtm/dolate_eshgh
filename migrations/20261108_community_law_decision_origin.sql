-- پیوند اختیاری نسخهٔ قانون به تصمیم جمعی مستقلِ منشأ آن.
-- تصمیم جمعی به‌تنهایی قانون نیست؛ تصویب و لازم‌الاجرا شدن همچنان از مسیر حقوقی مستقل می‌گذرد.
SET @law_decision_source_column_exists = (
    SELECT COUNT(*) > 0
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'community_law_versions'
      AND column_name = 'source_decision_id'
);
SET @law_decision_source_column_sql = IF(
    @law_decision_source_column_exists,
    'SELECT 1',
    'ALTER TABLE community_law_versions
       ADD COLUMN source_decision_id BIGINT UNSIGNED NULL AFTER source_idea_id'
);
PREPARE law_decision_source_column_stmt FROM @law_decision_source_column_sql;
EXECUTE law_decision_source_column_stmt;
DEALLOCATE PREPARE law_decision_source_column_stmt;

SET @law_decision_source_index_exists = (
    SELECT COUNT(*) > 0
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'community_law_versions'
      AND index_name = 'idx_law_versions_source_decision'
);
SET @law_decision_source_index_sql = IF(
    @law_decision_source_index_exists,
    'SELECT 1',
    'CREATE INDEX idx_law_versions_source_decision
       ON community_law_versions (source_decision_id)'
);
PREPARE law_decision_source_index_stmt FROM @law_decision_source_index_sql;
EXECUTE law_decision_source_index_stmt;
DEALLOCATE PREPARE law_decision_source_index_stmt;
