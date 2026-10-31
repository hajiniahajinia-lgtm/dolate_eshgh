-- Preserve all historical yes/no ballots at the base weight.
-- Expertise weights apply only to votes cast after the weighted-voting
-- columns are installed and the relevant topic domain has been approved.
UPDATE topic_voting_ballots
SET vote_weight = 1.00;

-- Historical idea votes also retain their original base weight; rebuild
-- the separate weighted total without changing the raw rationality counter.
UPDATE ideas i
SET i.idea_rationality_weighted = COALESCE((
  SELECT SUM(iv.vote_weight) FROM idea_votes iv WHERE iv.idea_id=i.id
),0.00);
