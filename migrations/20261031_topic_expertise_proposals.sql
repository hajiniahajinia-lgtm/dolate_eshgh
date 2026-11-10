-- Pending expertise-domain proposals attached to topics.
-- Existing topics and votes are left untouched.
CREATE TABLE IF NOT EXISTS topic_expertise_proposals (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 topic_id BIGINT UNSIGNED NOT NULL,
 community_id INT NOT NULL,
 domain_id INT UNSIGNED NOT NULL,
 proposed_by INT NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 proposed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 decided_at DATETIME NULL,
 decided_by INT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_topic_expertise_proposal_topic (topic_id),
 KEY idx_topic_expertise_community_status (community_id,status,proposed_at),
 KEY idx_topic_expertise_domain_status (domain_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
