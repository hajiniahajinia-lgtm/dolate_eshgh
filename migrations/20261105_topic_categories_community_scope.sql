-- دسته‌های اختصاصی جامعه؛ دسته‌های قدیمی با community_id=NULL مشترک باقی می‌مانند.
SET @topic_categories_community_column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'topic_categories'
      AND column_name = 'community_id'
);
SET @topic_categories_community_column_sql = IF(
    @topic_categories_community_column_exists = 0,
    'ALTER TABLE topic_categories ADD COLUMN community_id INT UNSIGNED NULL DEFAULT NULL AFTER id',
    'SELECT 1'
);
PREPARE topic_categories_community_column_stmt FROM @topic_categories_community_column_sql;
EXECUTE topic_categories_community_column_stmt;
DEALLOCATE PREPARE topic_categories_community_column_stmt;

SET @topic_categories_community_index_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'topic_categories'
      AND index_name = 'idx_topic_categories_community_active'
);
SET @topic_categories_community_index_sql = IF(
    @topic_categories_community_index_exists = 0,
    'ALTER TABLE topic_categories ADD KEY idx_topic_categories_community_active (community_id, is_active, sort_order)',
    'SELECT 1'
);
PREPARE topic_categories_community_index_stmt FROM @topic_categories_community_index_sql;
EXECUTE topic_categories_community_index_stmt;
DEALLOCATE PREPARE topic_categories_community_index_stmt;
