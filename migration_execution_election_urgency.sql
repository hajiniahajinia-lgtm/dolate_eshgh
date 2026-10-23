-- دولت عشق: فوریت و نهایی‌سازی انتخابات مجری در آرشیو
-- این فایل را فقط یک‌بار و به‌صورت کنترل‌شده در پایگاه داده همان سایت اجرا کنید.
-- این مهاجرت هیچ جدول یا رأی موجودی را حذف یا بازسازی نمی‌کند.

CREATE TABLE IF NOT EXISTS topic_execution_elections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  topic_id INT NOT NULL,
  idea_id INT NOT NULL,
  started_at DATETIME NOT NULL,
  finalized_at DATETIME NULL,
  final_candidate_user_id INT NULL,
  UNIQUE KEY uq_execution_election (topic_id, idea_id),
  KEY idx_execution_election_final (finalized_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS topic_execution_election_urgency_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  topic_id INT NOT NULL,
  idea_id INT NOT NULL,
  user_id INT NOT NULL,
  urgency_days INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_execution_urgency_vote (topic_id, idea_id, user_id),
  KEY idx_execution_urgency_topic (topic_id, idea_id),
  KEY idx_execution_urgency_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
