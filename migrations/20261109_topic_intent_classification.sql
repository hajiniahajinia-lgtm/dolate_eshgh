-- طبقه‌بندی مستقل هدف موضوع؛ با حفظ topic_purpose قدیمی برای سازگاری با کتابخانه دانش.
SET @topic_intent_column_exists = (
    SELECT COUNT(*) > 0 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='topics' AND column_name='topic_intent'
);
SET @topic_intent_column_sql = IF(
    @topic_intent_column_exists,
    'SELECT 1',
    'ALTER TABLE topics ADD COLUMN topic_intent ENUM(''knowledge'',''decision'',''law'') NOT NULL DEFAULT ''decision'' AFTER topic_purpose'
);
PREPARE topic_intent_column_stmt FROM @topic_intent_column_sql;
EXECUTE topic_intent_column_stmt;
DEALLOCATE PREPARE topic_intent_column_stmt;

-- داده‌های قبلی به نزدیک‌ترین مسیر معادل منتقل می‌شوند؛ هیچ موضوع قدیمی قانون تلقی نمی‌شود.
UPDATE topics
SET topic_intent = CASE
    WHEN topic_purpose = 'knowledge' THEN 'knowledge'
    ELSE 'decision'
END
WHERE topic_intent = 'decision';

SET @topic_intent_index_exists = (
    SELECT COUNT(*) > 0 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='topics' AND index_name='idx_topics_community_intent'
);
SET @topic_intent_index_sql = IF(
    @topic_intent_index_exists,
    'SELECT 1',
    'CREATE INDEX idx_topics_community_intent ON topics (community_id, topic_intent, closed)'
);
PREPARE topic_intent_index_stmt FROM @topic_intent_index_sql;
EXECUTE topic_intent_index_stmt;
DEALLOCATE PREPARE topic_intent_index_stmt;
