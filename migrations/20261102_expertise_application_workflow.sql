-- چرخه درخواست و رأی‌گیری شایستگی تخصصی
ALTER TABLE expertise_applications
  ADD COLUMN expires_at DATETIME NULL AFTER decision_at;

UPDATE expertise_applications
SET expires_at = DATE_ADD(created_at, INTERVAL 7 DAY)
WHERE expires_at IS NULL;

