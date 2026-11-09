-- انتقال افزایشی snapshotهای تاریخی به دفتر مستقل تصمیم‌ها.
-- اگر جدول قدیمی در نصب فعلی وجود نداشته باشد، مهاجرت بدون خطا رد می‌شود.
SET @community_decision_legacy_tables_ready = (
    SELECT COUNT(*) = 2
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('topics', 'collective_knowledge_decisions')
);

SET @community_decision_backfill_sql = IF(
    @community_decision_legacy_tables_ready,
    'INSERT IGNORE INTO community_decision_records
        (community_id, source_topic_id, source_idea_id, topic_purpose_snapshot,
         selected_idea_content, acceptance_status, enforceability_status,
         yes_count, no_count, yes_weight, no_weight, yes_percent,
         weighted_yes_percent, enforce_yes_count, enforce_no_count,
         record_status, decided_at)
     SELECT
        t.community_id,
        d.topic_id,
        NULLIF(d.selected_idea_id, 0),
        COALESCE(d.topic_purpose_snapshot, ''mixed''),
        d.selected_idea_content,
        COALESCE(d.acceptance_status, ''no_votes''),
        COALESCE(d.enforceability_status, ''undecided''),
        COALESCE(d.yes_count, 0),
        COALESCE(d.no_count, 0),
        COALESCE(d.yes_weight, 0),
        COALESCE(d.no_weight, 0),
        COALESCE(d.yes_percent, 0),
        COALESCE(d.weighted_yes_percent, 0),
        COALESCE(d.enforce_yes_count, 0),
        COALESCE(d.enforce_no_count, 0),
        ''recorded'',
        d.finalized_at
     FROM collective_knowledge_decisions d
     INNER JOIN topics t ON t.id = d.topic_id
     WHERE d.finalized_at IS NOT NULL',
    'SELECT 1'
);

PREPARE community_decision_backfill_stmt FROM @community_decision_backfill_sql;
EXECUTE community_decision_backfill_stmt;
DEALLOCATE PREPARE community_decision_backfill_stmt;
