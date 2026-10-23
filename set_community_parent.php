<?php
session_start();
require __DIR__ . "/db.php";

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

$column_check = $conn->query("SHOW COLUMNS FROM communities LIKE 'parent_community_id'");
$table_check = $conn->query("SHOW TABLES LIKE 'community_parent_votes'");
if (!$column_check || $column_check->num_rows === 0 || !$table_check || $table_check->num_rows === 0) {
    if ($column_check) $column_check->close();
    if ($table_check) $table_check->close();
    return_to_community($community_id, 'schema');
}
$column_check->close();
$table_check->close();

$parents = [];
$all_result = $conn->query("SELECT id, parent_community_id FROM communities");
if (!$all_result) return_to_community($community_id, 'invalid');
while ($row = $all_result->fetch_assoc()) {
    $id = (int)$row['id'];
    $parents[$id] = $row['parent_community_id'] === null ? 0 : (int)$row['parent_community_id'];
}
$all_result->close();

if (!array_key_exists($community_id, $parents)) return_to_community($community_id, 'invalid');
if ($parent_community_id === $community_id) return_to_community($community_id, 'cycle');
if ($parent_community_id > 0 && !array_key_exists($parent_community_id, $parents)) return_to_community($community_id, 'invalid');

if ($parent_community_id > 0) {
    $cursor = $parent_community_id;
    $visited = [];
    while ($cursor > 0) {
        if ($cursor === $community_id || isset($visited[$cursor])) return_to_community($community_id, 'cycle');
        $visited[$cursor] = true;
        $cursor = $parents[$cursor] ?? 0;
    }
}

try {
    $conn->begin_transaction();

    if ($parent_community_id > 0) {
        $vote = $conn->prepare("INSERT INTO community_parent_votes (community_id, user_id, parent_community_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE parent_community_id = VALUES(parent_community_id), voted_at = CURRENT_TIMESTAMP");
        if (!$vote) throw new Exception('prepare vote failed');
        $vote->bind_param("iii", $community_id, $user_id, $parent_community_id);
    } else {
        $vote = $conn->prepare("INSERT INTO community_parent_votes (community_id, user_id, parent_community_id) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE parent_community_id = NULL, voted_at = CURRENT_TIMESTAMP");
        if (!$vote) throw new Exception('prepare vote failed');
        $vote->bind_param("ii", $community_id, $user_id);
    }
    if (!$vote->execute()) throw new Exception('save vote failed');
    $vote->close();

    // فقط انتخابی که بیشترین رأی یکتا دارد اعمال می‌شود؛ در تساوی، جایگاه فعلی حفظ می‌شود.
    $counts = $conn->prepare("SELECT parent_community_id, COUNT(*) AS vote_count FROM community_parent_votes WHERE community_id = ? GROUP BY parent_community_id ORDER BY vote_count DESC");
    if (!$counts) throw new Exception('prepare count failed');
    $counts->bind_param("i", $community_id);
    $counts->execute();
    $res = $counts->get_result();
    $top = [];
    $max_votes = -1;
    while ($r = $res->fetch_assoc()) {
        $count = (int)$r['vote_count'];
        if ($max_votes === -1) $max_votes = $count;
        if ($count !== $max_votes) break;
        $top[] = $r['parent_community_id'] === null ? 0 : (int)$r['parent_community_id'];
    }
    $counts->close();

    if (count($top) === 1) {
        $winner = $top[0];
        // دوباره بررسی می‌کنیم تا رأی اکثریت نتواند چرخه بسازد.
        $valid_winner = true;
        if ($winner > 0) {
            $cursor = $winner;
            $visited = [];
            while ($cursor > 0) {
                if ($cursor === $community_id || isset($visited[$cursor])) { $valid_winner = false; break; }
                $visited[$cursor] = true;
                $cursor = $parents[$cursor] ?? 0;
            }
        }
        if ($valid_winner) {
            if ($winner > 0) {
                $apply = $conn->prepare("UPDATE communities SET parent_community_id = ? WHERE id = ?");
                $apply->bind_param("ii", $winner, $community_id);
            } else {
                $apply = $conn->prepare("UPDATE communities SET parent_community_id = NULL WHERE id = ?");
                $apply->bind_param("i", $community_id);
            }
            if (!$apply->execute()) throw new Exception('apply winner failed');
            $apply->close();
        }
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    return_to_community($community_id, 'invalid');
}

return_to_community($community_id, 'voted');
