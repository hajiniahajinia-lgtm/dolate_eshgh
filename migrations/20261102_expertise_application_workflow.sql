-- چرخه درخواست و رأی‌گیری شایستگی تخصصی
-- Resume safely when expires_at already exists on the host.

SET @migration_sql = IF(
  EXISTS(
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'expertise_applications'
      AND COLUMN_NAME = 'expires_at'
  ),
  'SELECT 1',
  'ALTER TABLE expertise_applications ADD COLUMN expires_at DATETIME NULL AFTER decision_at'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

UPDATE expertise_applications
SET expires_at = DATE_ADD(created_at, INTERVAL 7 DAY)
WHERE expires_at IS NULL;
