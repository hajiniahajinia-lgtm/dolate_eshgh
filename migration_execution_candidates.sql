-- دولت عشق: ساختار مستقل داوطلبی اجرای طرح و رأی مستقل انتخاب مجری
-- این فایل را فقط یک‌بار در پایگاه داده همان سایت اجرا کنید.
-- هیچ جدول یا رأی موجودی حذف یا تغییر داده نمی‌شود.

CREATE TABLE IF NOT EXISTS topic_execution_candidates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  topic_id INT NOT NULL,
  idea_id INT NOT NULL,
  user_id INT NOT NULL,
  volunteered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_execution_candidate (topic_id, idea_id, user_id),
  KEY idx_execution_candidates_idea (topic_id, idea_id),
  KEY idx_execution_candidates_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS topic_execution_candidate_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  topic_id INT NOT NULL,
  idea_id INT NOT NULL,
  candidate_user_id INT NOT NULL,
  voter_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_execution_vote_per_member (topic_id, idea_id, voter_user_id),
  KEY idx_execution_candidate_vote_count (topic_id, idea_id, candidate_user_id),
  KEY idx_execution_candidate_voter (voter_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
