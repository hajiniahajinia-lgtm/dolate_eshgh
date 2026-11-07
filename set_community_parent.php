<?php
session_start();
require __DIR__ . "/db.php";
require_once __DIR__ . "/community_law_helpers.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: profile.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;
$parent_community_id = isset($_POST['parent_community_id']) ? (int)$_POST['parent_community_id'] : 0;
$csrf_token = (string)($_POST['csrf_token'] ?? '');

function return_to_community(int $community_id, string $status): void {
    if (($_POST['return_to'] ?? '') === 'profile') {
        header("Location: profile.php?hierarchy_status=" . rawurlencode($status) . "#community-parent-voting");
    } elseif ($community_id > 0) {
        header("Location: community.php?id=" . $community_id . "&hierarchy_status=" . rawurlencode($status) . "#community-hierarchy");
    } else {
        header("Location: profile.php?hierarchy_status=" . rawurlencode($status) . "#community-parent-voting");
    }
    exit;
}

if ($community_id <= 0) return_to_community(0, 'invalid');
if (empty($_SESSION['community_hierarchy_csrf']) || $csrf_token === '' ||
    !hash_equals($_SESSION['community_hierarchy_csrf'], $csrf_token)) {
    return_to_community($community_id, 'csrf');
}

$required_tables = ['community_parent_vote_rounds', 'community_parent_vote_electorate', 'community_parent_round_votes', 'community_members'];
foreach ($required_tables as $table_name) {
    $safe_table = $conn->real_escape_string($table_name);
    $check = $conn->query("SHOW TABLES LIKE '{$safe_table}'");
    if (!$check || $check->num_rows === 0) {
        if ($check) $check->close();
        return_to_community($community_id, 'schema');
    }
    $check->close();
}

try {
    if (!$conn->begin_transaction()) throw new Exception('begin transaction failed');

    // قفل سراسری ساختار برای جلوگیری از چرخه و تغییر هم‌زمان والدها.
    $all_result = $conn->query("SELECT id, parent_community_id FROM communities ORDER BY id FOR UPDATE");
    if (!$all_result) throw new Exception('lock hierarchy failed');
    $parents = [];
    while ($row = $all_result->fetch_assoc()) {
        $id = (int)$row['id'];
        $parents[$id] = $row['parent_community_id'] === null ? 0 : (int)$row['parent_community_id'];
    }
    $all_result->close();

    if (!array_key_exists($community_id, $parents)) throw new RuntimeException('invalid community');
    foreach ($parents as $node_id => $_parent_id) {
        $cursor = (int)$node_id;
        $visited = [];
        while ($cursor > 0) {
            if (isset($visited[$cursor]) || !array_key_exists($cursor, $parents)) {
                throw new RuntimeException('invalid existing hierarchy');
            }
            $visited[$cursor] = true;
            $cursor = (int)$parents[$cursor];
        }
    }

    if ($parent_community_id === $community_id) throw new RuntimeException('cycle');
    if ($parent_community_id > 0 && !array_key_exists($parent_community_id, $parents)) {
        throw new RuntimeException('invalid parent');
    }
    if ($parent_community_id > 0) {
        $cursor = $parent_community_id;
        $visited = [];
        while ($cursor > 0) {
            if ($cursor === $community_id || isset($visited[$cursor])) throw new RuntimeException('cycle');
            $visited[$cursor] = true;
            $cursor = $parents[$cursor] ?? 0;
        }
    }

    // فقط اعضای موجود در لحظه آغاز دور، در فهرست ثابت رأی‌دهندگان قرار می‌گیرند.
    $round_stmt = $conn->prepare(
        "SELECT id, deadline FROM community_parent_vote_rounds
         WHERE community_id=? AND status='open' ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    if (!$round_stmt) throw new Exception('prepare round lookup failed');
    $round_stmt->bind_param("i", $community_id);
    if (!$round_stmt->execute()) throw new Exception('lookup round failed');
    $round = $round_stmt->get_result()->fetch_assoc();
    $round_stmt->close();

    if ($round && strtotime($round['deadline']) <= time()) {
        $round_id = (int)$round['id'];
        $vote_counts = $conn->prepare(
            "SELECT parent_community_id, COUNT(*) AS vote_count
             FROM community_parent_round_votes WHERE round_id=?
             GROUP BY parent_community_id ORDER BY vote_count DESC"
        );
        if (!$vote_counts) throw new Exception('prepare final count failed');
        $vote_counts->bind_param("i", $round_id);
        if (!$vote_counts->execute()) throw new Exception('count final votes failed');
        $count_result = $vote_counts->get_result();
        $options = [];
        $total_votes = 0;
        while ($item = $count_result->fetch_assoc()) {
            $count = (int)$item['vote_count'];
            $total_votes += $count;
            $options[] = [
                'parent' => $item['parent_community_id'] === null ? 0 : (int)$item['parent_community_id'],
                'count' => $count
            ];
        }
        $vote_counts->close();

        $round_info_stmt = $conn->prepare(
            "SELECT electorate_count, quorum_required FROM community_parent_vote_rounds WHERE id=? FOR UPDATE"
        );
        if (!$round_info_stmt) throw new Exception('prepare round info failed');
        $round_info_stmt->bind_param("i", $round_id);
        if (!$round_info_stmt->execute()) throw new Exception('read round info failed');
        $round_info = $round_info_stmt->get_result()->fetch_assoc();
        $round_info_stmt->close();

        $status = 'insufficient';
        $winner = null;
        $winner_count = 0;
        if ($round_info && $total_votes >= (int)$round_info['quorum_required'] && !empty($options)) {
            if (count($options) === 1 || $options[0]['count'] > $options[1]['count']) {
                $winner_count = $options[0]['count'];
                if (($winner_count * 3) >= ($total_votes * 2)) {
                    $winner = $options[0]['parent'];
                    $status = 'rejected';
                    $valid_winner = true;
                    if ($winner > 0) {
                        $cursor = $winner;
                        $seen = [];
                        while ($cursor > 0) {
                            if ($cursor === $community_id || isset($seen[$cursor]) || !array_key_exists($cursor, $parents)) {
                                $valid_winner = false;
                                break;
                            }
                            $seen[$cursor] = true;
                            $cursor = (int)$parents[$cursor];
                        }
                    }
                    if ($valid_winner) {
                        if ($winner !== $parents[$community_id]) {
                            $new_parents = $parents;
                            $new_parents[$community_id] = $winner;
                            if (!community_law_record_hierarchy_scope_changes(
                                $conn, $parents, $new_parents, $community_id, $user_id
                            )) {
                                throw new Exception('record law scope history failed');
                            }
                            if ($winner > 0) {
                                $apply = $conn->prepare("UPDATE communities SET parent_community_id=? WHERE id=?");
                                if (!$apply) throw new Exception('prepare hierarchy update failed');
                                $apply->bind_param("ii", $winner, $community_id);
                            } else {
                                $apply = $conn->prepare("UPDATE communities SET parent_community_id=NULL WHERE id=?");
                                if (!$apply) throw new Exception('prepare hierarchy update failed');
                                $apply->bind_param("i", $community_id);
                            }
                            if (!$apply->execute() || $apply->affected_rows > 1) throw new Exception('apply hierarchy winner failed');
                            $apply->close();
                        }
                        $status = 'approved';
                    }
                } else {
                    $status = 'rejected';
                }
            } else {
                $status = 'rejected';
            }
        }

        $finalize = $conn->prepare(
            "UPDATE community_parent_vote_rounds
             SET status=?, winning_parent_id=?, yes_count=?, decided_at=NOW() WHERE id=? AND status='open'"
        );
        if (!$finalize) throw new Exception('prepare round finalization failed');
        $winner_db = ($status === 'approved' && $winner !== null && $winner > 0) ? $winner : null;
        $finalize->bind_param("ssii", $status, $winner_db, $winner_count, $round_id);
        if (!$finalize->execute()) throw new Exception('finalize round failed');
        $finalize->close();
        if (!$conn->commit()) throw new Exception('commit finalized round failed');
        return_to_community($community_id, $status === 'approved' ? 'approved' : $status);
    }

    if (!$round) {
        $next_stmt = $conn->prepare("SELECT COALESCE(MAX(round_number),0)+1 AS next_round FROM community_parent_vote_rounds WHERE community_id=?");
        if (!$next_stmt) throw new Exception('prepare round number failed');
        $next_stmt->bind_param("i", $community_id);
        if (!$next_stmt->execute()) throw new Exception('read round number failed');
        $next_round = (int)($next_stmt->get_result()->fetch_assoc()['next_round'] ?? 1);
        $next_stmt->close();

        $current_parent = $parents[$community_id] > 0 ? $parents[$community_id] : null;
        $create_round = $conn->prepare(
            "INSERT INTO community_parent_vote_rounds
             (community_id, round_number, current_parent_id, deadline)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))"
        );
        if (!$create_round) throw new Exception('prepare create round failed');
        $create_round->bind_param("iis", $community_id, $next_round, $current_parent);
        if (!$create_round->execute()) throw new Exception('create round failed');
        $round_id = (int)$create_round->insert_id;
        $create_round->close();

        $snapshot = $conn->prepare(
            "INSERT IGNORE INTO community_parent_vote_electorate (round_id, user_id)
             SELECT ?, user_id FROM community_members WHERE community_id=? ORDER BY user_id"
        );
        if (!$snapshot) throw new Exception('prepare electorate snapshot failed');
        $snapshot->bind_param("ii", $round_id, $community_id);
        if (!$snapshot->execute()) throw new Exception('snapshot electorate failed');
        $snapshot->close();

        $elector_count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM community_parent_vote_electorate WHERE round_id=?");
        if (!$elector_count_stmt) throw new Exception('prepare electorate count failed');
        $elector_count_stmt->bind_param("i", $round_id);
        if (!$elector_count_stmt->execute()) throw new Exception('count electorate failed');
        $electorate_count = (int)$elector_count_stmt->get_result()->fetch_assoc()['total'];
        $elector_count_stmt->close();
        $quorum_required = max(2, (int)ceil($electorate_count * 0.5));
        $update_round = $conn->prepare("UPDATE community_parent_vote_rounds SET electorate_count=?, quorum_required=? WHERE id=?");
        if (!$update_round) throw new Exception('prepare electorate update failed');
        $update_round->bind_param("iii", $electorate_count, $quorum_required, $round_id);
        if (!$update_round->execute()) throw new Exception('save electorate count failed');
        $update_round->close();
        $round = ['id' => $round_id, 'deadline' => date('Y-m-d H:i:s', time() + 7 * 86400)];
    }

    $round_id = (int)$round['id'];
    if (strtotime($round['deadline']) <= time()) throw new RuntimeException('round expired');
    $eligible = $conn->prepare("SELECT 1 FROM community_parent_vote_electorate WHERE round_id=? AND user_id=? LIMIT 1");
    if (!$eligible) throw new Exception('prepare eligibility check failed');
    $eligible->bind_param("ii", $round_id, $user_id);
    if (!$eligible->execute()) throw new Exception('check voter eligibility failed');
    $is_eligible = (bool)$eligible->get_result()->fetch_row();
    $eligible->close();
    if (!$is_eligible) throw new RuntimeException('not in electorate');

    if ($parent_community_id > 0) {
        $save_vote = $conn->prepare(
            "INSERT INTO community_parent_round_votes (round_id,user_id,parent_community_id)
             VALUES (?,?,?) ON DUPLICATE KEY UPDATE parent_community_id=VALUES(parent_community_id), voted_at=NOW()"
        );
        if (!$save_vote) throw new Exception('prepare vote save failed');
        $save_vote->bind_param("iii", $round_id, $user_id, $parent_community_id);
    } else {
        $save_vote = $conn->prepare(
            "INSERT INTO community_parent_round_votes (round_id,user_id,parent_community_id)
             VALUES (?,?,NULL) ON DUPLICATE KEY UPDATE parent_community_id=NULL, voted_at=NOW()"
        );
        if (!$save_vote) throw new Exception('prepare vote save failed');
        $save_vote->bind_param("ii", $round_id, $user_id);
    }
    if (!$save_vote->execute()) throw new Exception('save hierarchy vote failed');
    $save_vote->close();

    if (!$conn->commit()) throw new Exception('commit hierarchy vote failed');
} catch (Throwable $e) {
    $conn->rollback();
    return_to_community($community_id, 'invalid');
}

return_to_community($community_id, 'voted');
