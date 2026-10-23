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

$community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;
$parent_community_id = isset($_POST['parent_community_id']) ? (int)$_POST['parent_community_id'] : 0;
$csrf_token = (string)($_POST['csrf_token'] ?? '');

function return_to_community(int $community_id, string $status): void {
    if ($community_id > 0) {
        header("Location: community.php?id=" . $community_id . "&hierarchy_status=" . rawurlencode($status) . "#community-hierarchy");
    } else {
        header("Location: profile.php");
    }
    exit;
}

if ($community_id <= 0) {
    return_to_community(0, 'invalid');
}

if (
    empty($_SESSION['community_hierarchy_csrf']) ||
    $csrf_token === '' ||
    !hash_equals($_SESSION['community_hierarchy_csrf'], $csrf_token)
) {
    return_to_community($community_id, 'csrf');
}

// بررسی اینکه تغییر ساختار پایگاه داده به‌صورت کنترل‌شده انجام شده باشد.
$column_check = $conn->query("SHOW COLUMNS FROM communities LIKE 'parent_community_id'");
if (!$column_check || $column_check->num_rows === 0) {
    if ($column_check) $column_check->close();
    return_to_community($community_id, 'schema');
}
$column_check->close();

// فهرست ارتباط‌ها را می‌خوانیم تا جامعه نامعتبر یا چرخه‌ای ایجاد نشود.
$parents = [];
$all_result = $conn->query("SELECT id, parent_community_id FROM communities");
if (!$all_result) {
    return_to_community($community_id, 'invalid');
}
while ($row = $all_result->fetch_assoc()) {
    $id = (int)$row['id'];
    $parents[$id] = $row['parent_community_id'] === null ? 0 : (int)$row['parent_community_id'];
}
$all_result->close();

if (!array_key_exists($community_id, $parents)) {
    return_to_community($community_id, 'invalid');
}

if ($parent_community_id === $community_id) {
    return_to_community($community_id, 'cycle');
}

if ($parent_community_id > 0 && !array_key_exists($parent_community_id, $parents)) {
    return_to_community($community_id, 'invalid');
}

if ($parent_community_id > 0) {
    $cursor = $parent_community_id;
    $visited = [];
    while ($cursor > 0) {
        if ($cursor === $community_id || isset($visited[$cursor])) {
            return_to_community($community_id, 'cycle');
        }
        $visited[$cursor] = true;
        $cursor = $parents[$cursor] ?? 0;
    }
}

$new_parent = $parent_community_id > 0 ? $parent_community_id : null;
$update = $conn->prepare("UPDATE communities SET parent_community_id = ? WHERE id = ?");
if (!$update) {
    return_to_community($community_id, 'invalid');
}
$update->bind_param("ii", $new_parent, $community_id);
if (!$update->execute()) {
    $update->close();
    return_to_community($community_id, 'invalid');
}
$update->close();

header("Location: community.php?id=" . $community_id . "&hierarchy_status=saved#community-hierarchy");
exit;
