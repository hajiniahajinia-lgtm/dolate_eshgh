-- پیوند اختیاری دفتر تصمیم به پروندهٔ دانش؛ بدون ادغام دو موجودیت.
SET @decision_knowledge_column_exists = (
    SELECT COUNT(*) > 0 FROM information_schema.columns
    WHERE table_schema=DATABASE()
      AND table_name='community_decision_records'
      AND column_name='knowledge_entry_id'
);
SET @decision_knowledge_column_sql = IF(
    @decision_knowledge_column_exists,
    'SELECT 1',
    'ALTER TABLE community_decision_records
       ADD COLUMN knowledge_entry_id BIGINT UNSIGNED NULL AFTER source_idea_id'
);
PREPARE decision_knowledge_column_stmt FROM @decision_knowledge_column_sql;
EXECUTE decision_knowledge_column_stmt;
DEALLOCATE PREPARE decision_knowledge_column_stmt;

SET @decision_knowledge_index_exists = (
    SELECT COUNT(*) > 0 FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='community_decision_records'
      AND index_name='idx_decision_knowledge_entry'
);
SET @decision_knowledge_index_sql = IF(
    @decision_knowledge_index_exists,
    'SELECT 1',
    'CREATE INDEX idx_decision_knowledge_entry
       ON community_decision_records (knowledge_entry_id)'
);
PREPARE decision_knowledge_index_stmt FROM @decision_knowledge_index_sql;
EXECUTE decision_knowledge_index_stmt;
DEALLOCATE PREPARE decision_knowledge_index_stmt;
