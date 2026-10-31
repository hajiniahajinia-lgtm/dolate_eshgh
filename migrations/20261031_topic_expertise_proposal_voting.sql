-- Seven-day voting window for topic expertise proposals.
ALTER TABLE topic_expertise_proposals
  ADD COLUMN expires_at DATETIME NULL AFTER proposed_at;

UPDATE topic_expertise_proposals
SET expires_at = DATE_ADD(proposed_at, INTERVAL 7 DAY)
WHERE expires_at IS NULL;

CREATE TABLE IF NOT EXISTS topic_expertise_proposal_votes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  vote ENUM('yes','no') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_topic_expertise_proposal_voter (proposal_id,user_id),
  KEY idx_topic_expertise_proposal_vote (proposal_id,vote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
