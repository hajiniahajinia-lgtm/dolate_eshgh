-- Creates the dedicated urgency-vote table for the final voting period.
-- This is deliberately separate from topic_urgency_votes, used during topic discussion.
CREATE TABLE IF NOT EXISTS topic_voting_urgency_votes (
  id INT NOT NULL AUTO_INCREMENT,
  topic_id INT NOT NULL,
  user_id INT NOT NULL,
  urgency_days INT NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_voting_topic_user (topic_id, user_id),
  KEY idx_voting_topic_id (topic_id),
  KEY idx_voting_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
