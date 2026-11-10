-- دولت عشق: تکمیل امن ساختار نامزدی و انتخابات مجری
-- فقط جدول‌های مفقود را با CREATE TABLE IF NOT EXISTS ایجاد می‌کند؛ داده‌ای حذف یا بازسازی نمی‌شود.

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
