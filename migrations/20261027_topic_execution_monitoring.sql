CREATE TABLE IF NOT EXISTS topic_execution_candidate_plans (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  topic_id INT NOT NULL,
  idea_id INT NOT NULL,
  user_id INT NOT NULL,
  initial_plan TEXT NOT NULL,
  estimated_days INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_execution_candidate_plan (topic_id, idea_id, user_id),
  KEY idx_execution_candidate_plan (topic_id, idea_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS topic_execution_plans (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  topic_id INT NOT NULL,
  idea_id INT NOT NULL,
  community_id INT NOT NULL,
  executor_user_id INT NOT NULL,
  initial_plan TEXT NOT NULL,
  detailed_plan TEXT NULL,
  timeline TEXT NULL,
  status ENUM('preparing','in_progress','blocked','completed') NOT NULL DEFAULT 'preparing',
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_execution_plan (topic_id, idea_id),
  KEY idx_execution_plan_community (community_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS topic_execution_updates (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  execution_plan_id INT NOT NULL,
  user_id INT NOT NULL,
  update_type ENUM('progress','discussion','problem','delay','misconduct','response','decision') NOT NULL DEFAULT 'discussion',
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_execution_updates_plan (execution_plan_id, created_at),
  KEY idx_execution_updates_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;