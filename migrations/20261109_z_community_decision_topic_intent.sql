-- نگهداری مسیر اصلی موضوع در دفتر مستقل تصمیم‌ها؛ افزایشی و قابل اجرای مجدد.
SET @community_decision_intent_column_exists = (
    SELECT COUNT(*) > 0
    FROM information_schema.columns
    WHERE table_schema=DATABASE()
      AND table_name='community_decision_records'
      AND column_name='topic_intent_snapshot'
);
SET @community_decision_intent_column_sql = IF(
    @community_decision_intent_column_exists,
    'SELECT 1',
    'ALTER TABLE community_decision_records
     ADD COLUMN topic_intent_snapshot ENUM(''knowledge'',''decision'',''law'')
     NOT NULL DEFAULT ''decision'' AFTER topic_purpose_snapshot'
);
PREPARE community_decision_intent_column_stmt FROM @community_decision_intent_column_sql;
EXECUTE community_decision_intent_column_stmt;
DEALLOCATE PREPARE community_decision_intent_column_stmt;

-- برای snapshotهای تاریخی، نوع موضوع از رکورد منشأ بازیابی می‌شود.
-- اگر طبقه‌بندی جدید هنوز نصب نشده باشد، مقدار پیش‌فرض تصمیم حفظ می‌شود.
SET @community_decision_topics_have_intent = (
    SELECT COUNT(*) > 0
    FROM information_schema.columns
    WHERE table_schema=DATABASE()
      AND table_name='topics'
      AND column_name='topic_intent'
);
SET @community_decision_intent_backfill_sql = IF(
    @community_decision_topics_have_intent,
    'UPDATE community_decision_records d
     INNER JOIN topics t ON t.id=d.source_topic_id
     SET d.topic_intent_snapshot = CASE
         WHEN t.topic_intent IN (''knowledge'',''decision'',''law'') THEN t.topic_intent
         ELSE ''decision''
     END
     WHERE d.topic_intent_snapshot = ''decision''
       AND t.topic_intent IN (''knowledge'',''law'')',
    'SELECT 1'
);
PREPARE community_decision_intent_backfill_stmt FROM @community_decision_intent_backfill_sql;
EXECUTE community_decision_intent_backfill_stmt;
DEALLOCATE PREPARE community_decision_intent_backfill_stmt;
