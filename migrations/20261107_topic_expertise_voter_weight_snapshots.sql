-- وزن رأی تخصصی در سطح فرد و موضوع snapshot می‌شود.
-- همه رأی‌های فرد در یک موضوع از یک وزن استفاده می‌کنند، حتی اگر چند ایده داشته باشد.
CREATE TABLE IF NOT EXISTS topic_expertise_voter_weight_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    topic_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_topic_expertise_voter_snapshot (topic_id,user_id),
    KEY idx_topic_expertise_voter_snapshot_user (user_id,topic_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- برای رأی‌های قدیمی، وزن نخستین برگهٔ هر فرد در هر موضوع حفظ می‌شود.
INSERT IGNORE INTO topic_expertise_voter_weight_snapshots
    (topic_id,user_id,vote_weight,captured_at)
SELECT b.topic_id,b.user_id,b.vote_weight,b.created_at
FROM topic_voting_ballots b
WHERE NOT EXISTS (
    SELECT 1
    FROM topic_voting_ballots earlier
    WHERE earlier.topic_id=b.topic_id
      AND earlier.user_id=b.user_id
      AND (
          earlier.created_at < b.created_at
          OR (earlier.created_at=b.created_at AND earlier.id < b.id)
      )
);
