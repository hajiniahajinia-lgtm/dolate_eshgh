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

-- Existing yes/no ballots retain the default weight 1.00.
-- Historical votes are never retroactively reweighted.
