-- Add domain-specific vote weights while preserving raw historical counts.
-- Existing ballots receive the default weight 1.00.
ALTER TABLE idea_votes
  ADD COLUMN vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00;

ALTER TABLE topic_voting_ballots
  ADD COLUMN vote_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00;

ALTER TABLE ideas
  ADD COLUMN idea_rationality_weighted DECIMAL(12,2) NOT NULL DEFAULT 0.00;

UPDATE ideas i
SET i.idea_rationality_weighted = COALESCE((
  SELECT SUM(iv.vote_weight) FROM idea_votes iv WHERE iv.idea_id=i.id
),0.00);

-- Backfill weights on existing yes/no ballots with their domain competency,
-- only where the topic's proposed domain was approved before the ballot.
UPDATE topic_voting_ballots b
JOIN topic_expertise_proposals p ON p.topic_id=b.topic_id AND p.status='approved'
JOIN expertise_competencies ec ON ec.user_id=b.user_id AND ec.domain_id=p.domain_id
SET b.vote_weight = CASE ec.level
  WHEN 1 THEN 1.25
  WHEN 2 THEN 1.50
  WHEN 3 THEN 2.00
  WHEN 4 THEN 2.50
  ELSE 1.00
END
WHERE ec.valid_from <= NOW()
  AND ec.valid_until > NOW();

-- Historical idea votes are deliberately left at 1.00 so old rationality
-- results do not change retroactively.
