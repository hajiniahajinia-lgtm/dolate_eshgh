-- Add domain-specific vote weights while preserving raw historical counts.
-- Make this migration safe to resume when some or all columns already exist.
-- Existing columns and their data/types are left unchanged.

SET @migration_sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'idea_votes'
      AND COLUMN_NAME = 'vote_weight'
  ),
  'SELECT 1',
  'ALTER TABLE idea_votes ADD COLUMN vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'topic_voting_ballots'
      AND COLUMN_NAME = 'vote_weight'
  ),
  'SELECT 1',
  'ALTER TABLE topic_voting_ballots ADD COLUMN vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ideas'
      AND COLUMN_NAME = 'idea_rationality_weighted'
  ),
  'SELECT 1',
  'ALTER TABLE ideas ADD COLUMN idea_rationality_weighted DECIMAL(12,2) NOT NULL DEFAULT 0.00'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Recalculate weighted rationality using the existing vote weights.
-- Existing historical weights are not modified.
UPDATE ideas i
SET i.idea_rationality_weighted = COALESCE((
  SELECT SUM(iv.vote_weight) FROM idea_votes iv WHERE iv.idea_id=i.id
),0.00);

-- Existing yes/no ballots retain their current weights.
