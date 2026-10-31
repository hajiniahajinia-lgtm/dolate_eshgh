-- چرخه درخواست و رأی‌گیری شایستگی تخصصی
ALTER TABLE expertise_applications
  ADD COLUMN expires_at DATETIME NULL AFTER decision_at;

UPDATE expertise_applications
SET expires_at = DATE_ADD(created_at, INTERVAL 7 DAY)
WHERE expires_at IS NULL;

ALTER TABLE expertise_application_votes
  ADD KEY idx_expertise_application_vote_lookup (application_id, voter_user_id);

