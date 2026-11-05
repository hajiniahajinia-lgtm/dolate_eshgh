<?php
/**
 * Scheduled maintenance for Dolate Eshgh.
 *
 * IMPORTANT:
 * - CLI only. This file must never be exposed as a web endpoint.
 * - Run from a trusted scheduler after verifying the server's db.php configuration.
 * - Do not invoke manually against production without an explicit maintenance window.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../community_member_removal_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function maintenance_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SHOW TABLES LIKE ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

$summary = [
    'topics_closed' => 0,
    'final_votes_created' => 0,
    'votings_archived' => 0,
    'expertise_proposals_decided' => 0,
    'execution_elections_finalized' => 0,
    'member_removal_tables_ready' => false,
];

try {
    // Expired member-removal requests are finalized only by this scheduled task.
    $summary['member_removal_tables_ready'] = community_removal_tables_ready($conn);
    if ($summary['member_removal_tables_ready']) {
        community_removal_finalize_expired($conn, 0);
    }

    // Close topics whose urgency period has elapsed.
    $topics = $conn->query("SELECT id, urgency, created_at FROM topics WHERE closed=0");
    while ($topic = $topics->fetch_assoc()) {
        $created = strtotime((string)$topic['created_at']);
        $urgency = (int)$topic['urgency'];
        $days = $urgency > 0 ? $urgency : 3;
        if ($created === false || time() < $created + ($days * 86400)) {
            continue;
        }

        $topicId = (int)$topic['id'];
        $conn->begin_transaction();
        try {
            $close = $conn->prepare('UPDATE topics SET closed=1 WHERE id=? AND closed=0');
            $close->bind_param('i', $topicId);
            $close->execute();
            $changed = $close->affected_rows;
            $close->close();

            if ($changed > 0) {
                $best = $conn->prepare('SELECT id FROM ideas WHERE topic_id=? ORDER BY idea_rationality_weighted DESC, idea_rationality DESC, id ASC LIMIT 1');
                $best->bind_param('i', $topicId);
                $best->execute();
                $bestIdea = $best->get_result()->fetch_assoc();
                $best->close();

                if ($bestIdea) {
                    $ideaId = (int)$bestIdea['id'];
                    $insert = $conn->prepare('INSERT IGNORE INTO topic_final_votes (topic_id, idea_id) VALUES (?, ?)');
                    $insert->bind_param('ii', $topicId, $ideaId);
                    $insert->execute();
                    if ($insert->affected_rows > 0) {
                        $summary['final_votes_created']++;
                    }
                    $insert->close();
                }
                $summary['topics_closed']++;
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
    $topics->close();

    // Backfill voting metadata for previously closed topics, as the old page code did.
    if (maintenance_table_exists($conn, 'topic_voting_meta')) {
        $conn->query("
            INSERT INTO topic_voting_meta (topic_id, urgency_days, voting_started, finalized)
            SELECT t.id, 5, t.created_at, 0
            FROM topics t
            LEFT JOIN topic_voting_meta m ON m.topic_id=t.id
            WHERE t.closed=1 AND m.topic_id IS NULL
        ");
    }

    // Finalize expired voting rounds and archive their topics.
    $requiredVotingTables = ['topic_voting_meta', 'topic_enforce_votes', 'topic_enforce_stats', 'topic_winners'];
    $votingReady = true;
    foreach ($requiredVotingTables as $table) {
        if (!maintenance_table_exists($conn, $table)) {
            $votingReady = false;
            break;
        }
    }

    if ($votingReady) {
        $due = $conn->query("
            SELECT t.id, m.voting_started, m.urgency_days
            FROM topics t
            JOIN topic_voting_meta m ON m.topic_id=t.id
            WHERE t.closed=1 AND m.finalized=0
        ");

        while ($row = $due->fetch_assoc()) {
            $topicId = (int)$row['id'];
            $started = strtotime((string)$row['voting_started']);
            $days = max(1, (int)$row['urgency_days']);
            if ($started === false || time() < $started + ($days * 86400)) {
                continue;
            }

            $conn->begin_transaction();
            try {
                $lock = $conn->prepare('SELECT finalized FROM topic_voting_meta WHERE topic_id=? FOR UPDATE');
                $lock->bind_param('i', $topicId);
                $lock->execute();
                $meta = $lock->get_result()->fetch_assoc();
                $lock->close();

                if (!$meta || (int)$meta['finalized'] === 1) {
                    $conn->commit();
                    continue;
                }

                $ideas = $conn->prepare('SELECT id FROM ideas WHERE topic_id=? ORDER BY id ASC');
                $ideas->bind_param('i', $topicId);
                $ideas->execute();
                $ideaRows = $ideas->get_result();

                while ($idea = $ideaRows->fetch_assoc()) {
                    $ideaId = (int)$idea['id'];
                    $counts = $conn->prepare("
                        SELECT SUM(enforceable=1) AS yes_count, SUM(enforceable=0) AS no_count
                        FROM topic_enforce_votes WHERE topic_id=? AND idea_id=?
                    ");
                    $counts->bind_param('ii', $topicId, $ideaId);
                    $counts->execute();
                    $enforce = $counts->get_result()->fetch_assoc() ?: [];
                    $counts->close();

                    $yes = (int)($enforce['yes_count'] ?? 0);
                    $no = (int)($enforce['no_count'] ?? 0);
                    $saveStats = $conn->prepare("
                        INSERT INTO topic_enforce_stats (topic_id, idea_id, enforce_count, non_enforce_count)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE enforce_count=VALUES(enforce_count),
                            non_enforce_count=VALUES(non_enforce_count), updated_at=CURRENT_TIMESTAMP
                    ");
                    $saveStats->bind_param('iiii', $topicId, $ideaId, $yes, $no);
                    $saveStats->execute();
                    $saveStats->close();
                }
                $ideas->close();

                $best = $conn->prepare('SELECT id FROM ideas WHERE topic_id=? ORDER BY idea_rationality DESC, idea_rationality_weighted DESC, id ASC LIMIT 1');
                $best->bind_param('i', $topicId);
                $best->execute();
                $winner = $best->get_result()->fetch_assoc();
                $best->close();

                if ($winner) {
                    $ideaId = (int)$winner['id'];
                    $existing = $conn->prepare('SELECT id FROM topic_winners WHERE topic_id=? LIMIT 1');
                    $existing->bind_param('i', $topicId);
                    $existing->execute();
                    $winnerRow = $existing->get_result()->fetch_assoc();
                    $existing->close();

                    if ($winnerRow) {
                        $winnerRowId = (int)$winnerRow['id'];
                        $updateWinner = $conn->prepare('UPDATE topic_winners SET idea_id=?, selected_at=NOW() WHERE id=?');
                        $updateWinner->bind_param('ii', $ideaId, $winnerRowId);
                        $updateWinner->execute();
                        $updateWinner->close();
                    } else {
                        $insertWinner = $conn->prepare('INSERT INTO topic_winners (topic_id, idea_id, selected_at) VALUES (?, ?, NOW())');
                        $insertWinner->bind_param('ii', $topicId, $ideaId);
                        $insertWinner->execute();
                        $insertWinner->close();
                    }
                }

                $finishMeta = $conn->prepare('UPDATE topic_voting_meta SET finalized=1, finalized_at=NOW() WHERE topic_id=? AND finalized=0');
                $finishMeta->bind_param('i', $topicId);
                $finishMeta->execute();
                $finishMeta->close();

                $archive = $conn->prepare('UPDATE topics SET closed=2 WHERE id=? AND closed=1');
                $archive->bind_param('i', $topicId);
                $archive->execute();
                $archive->close();

                $summary['votings_archived']++;
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }
        $due->close();
    }

    // Decide expired community expertise-domain proposals.
    if (maintenance_table_exists($conn, 'topic_expertise_proposals')
        && maintenance_table_exists($conn, 'topic_expertise_proposal_votes')) {
        $conn->query("
            UPDATE topic_expertise_proposals p
            SET status = CASE
                WHEN (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='yes')
                   > (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='no') THEN 'approved'
                WHEN (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='no')
                   > (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='yes') THEN 'rejected'
                ELSE 'pending'
            END,
            decided_at = CASE
                WHEN (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='yes')
                   <> (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='no') THEN NOW()
                ELSE NULL
            END
            WHERE p.status='pending' AND p.expires_at IS NOT NULL AND p.expires_at<=NOW()
        ");
        $summary['expertise_proposals_decided'] = $conn->affected_rows;
    }

    // Initialize and finalize execution elections for winning plans.
    $electionTables = [
        'topic_execution_elections',
        'topic_execution_election_urgency_votes',
        'topic_execution_candidates',
        'topic_execution_candidate_votes',
        'topic_winners',
        'topic_voting_meta'
    ];
    $electionsReady = true;
    foreach ($electionTables as $table) {
        if (!maintenance_table_exists($conn, $table)) {
            $electionsReady = false;
            break;
        }
    }

    if ($electionsReady) {
        $winners = $conn->query("
            SELECT t.id AS topic_id, t.community_id, m.finalized_at, w.idea_id
            FROM topics t
            JOIN topic_voting_meta m ON m.topic_id=t.id AND m.finalized=1
            JOIN topic_winners w ON w.topic_id=t.id
            WHERE m.finalized_at IS NOT NULL
        ");
        while ($winner = $winners->fetch_assoc()) {
            $topicId = (int)$winner['topic_id'];
            $communityId = (int)$winner['community_id'];
            $ideaId = (int)$winner['idea_id'];
            $startedAt = (string)$winner['finalized_at'];

            $createElection = $conn->prepare('INSERT IGNORE INTO topic_execution_elections (topic_id, idea_id, started_at) VALUES (?, ?, ?)');
            $createElection->bind_param('iis', $topicId, $ideaId, $startedAt);
            $createElection->execute();
            $createElection->close();

            $stateQuery = $conn->prepare('SELECT started_at, finalized_at, final_candidate_user_id FROM topic_execution_elections WHERE topic_id=? AND idea_id=? LIMIT 1');
            $stateQuery->bind_param('ii', $topicId, $ideaId);
            $stateQuery->execute();
            $state = $stateQuery->get_result()->fetch_assoc();
            $stateQuery->close();
            if (!$state || !empty($state['finalized_at']) || !empty($state['final_candidate_user_id'])) {
                continue;
            }

            $urgency = $conn->prepare('SELECT AVG(urgency_days) AS avg_days, COUNT(*) AS total FROM topic_execution_election_urgency_votes WHERE topic_id=? AND idea_id=?');
            $urgency->bind_param('ii', $topicId, $ideaId);
            $urgency->execute();
            $urgencyRow = $urgency->get_result()->fetch_assoc() ?: [];
            $urgency->close();

            $duration = ((int)($urgencyRow['total'] ?? 0) > 0)
                ? (int)ceil((float)$urgencyRow['avg_days'])
                : 5;
            $deadline = strtotime((string)$state['started_at']) + ($duration * 86400);
            if (time() < $deadline) {
                continue;
            }

            $pick = $conn->prepare("
                SELECT c.user_id, COUNT(v.voter_user_id) AS vote_count, c.volunteered_at
                FROM topic_execution_candidates c
                JOIN community_members cm ON cm.user_id=c.user_id AND cm.community_id=?
                LEFT JOIN topic_execution_candidate_votes v
                    ON v.topic_id=c.topic_id AND v.idea_id=c.idea_id AND v.candidate_user_id=c.user_id
                WHERE c.topic_id=? AND c.idea_id=?
                GROUP BY c.user_id, c.volunteered_at
                ORDER BY vote_count DESC, c.volunteered_at ASC, c.user_id ASC
                LIMIT 1
            ");
            $pick->bind_param('iii', $communityId, $topicId, $ideaId);
            $pick->execute();
            $candidate = $pick->get_result()->fetch_assoc();
            $pick->close();
            if (!$candidate) {
                continue;
            }

            $candidateId = (int)$candidate['user_id'];
            $finalize = $conn->prepare("
                UPDATE topic_execution_elections
                SET final_candidate_user_id=?, finalized_at=NOW()
                WHERE topic_id=? AND idea_id=?
                  AND finalized_at IS NULL AND final_candidate_user_id IS NULL
            ");
            $finalize->bind_param('iii', $candidateId, $topicId, $ideaId);
            $finalize->execute();
            if ($finalize->affected_rows > 0) {
                $summary['execution_elections_finalized']++;
            }
            $finalize->close();
        }
        $winners->close();
    }

    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Maintenance failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
