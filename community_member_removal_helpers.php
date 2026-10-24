<?php
// توابع مشترک رأی‌گیری اخراج عضو؛ این فایل هیچ تغییری در جداول موجود ایجاد نمی‌کند.
function community_removal_tables_ready($conn) {
    $a = $conn->query("SHOW TABLES LIKE 'community_member_removal_requests'");
    $b = $conn->query("SHOW TABLES LIKE 'community_member_removal_votes'");
    $ready = $a && $b && $a->num_rows > 0 && $b->num_rows > 0;
    if ($a) $a->close();
    if ($b) $b->close();
    return $ready;
}

function community_removal_finalize_expired($conn, $community_id = 0) {
    $sql = "SELECT id, community_id, target_user_id, member_count_snapshot
            FROM community_member_removal_requests
            WHERE status='open' AND deadline_at <= NOW()";
    if ($community_id > 0) $sql .= " AND community_id=" . (int)$community_id;
    $result = $conn->query($sql);
    if (!$result) return;
    while ($request = $result->fetch_assoc()) {
        $request_id = (int)$request['id'];
        $yes_stmt = $conn->prepare("SELECT
            SUM(CASE WHEN vote='yes' THEN 1 ELSE 0 END) AS yes_count,
            SUM(CASE WHEN vote='no' THEN 1 ELSE 0 END) AS no_count,
            COUNT(*) AS total_count
            FROM community_member_removal_votes WHERE request_id=?");
        if (!$yes_stmt) continue;
        $yes_stmt->bind_param("i", $request_id);
        $yes_stmt->execute();
        $counts = $yes_stmt->get_result()->fetch_assoc() ?: [];
        $yes_stmt->close();

        $yes = (int)($counts['yes_count'] ?? 0);
        $no = (int)($counts['no_count'] ?? 0);
        $total = (int)($counts['total_count'] ?? 0);
        $quorum = (int)ceil(((int)$request['member_count_snapshot']) / 3);
        $approved = $quorum > 0 && $total >= $quorum && $yes > $no;
        $status = $approved ? 'approved' : 'rejected';

        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT status FROM community_member_removal_requests WHERE id=? FOR UPDATE");
            $lock->bind_param("i", $request_id);
            $lock->execute();
            $current = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$current || $current['status'] !== 'open') {
                $conn->commit();
                continue;
            }

            if ($approved) {
                $del = $conn->prepare("DELETE FROM community_members WHERE community_id=? AND user_id=?");
                $community_id_value = (int)$request['community_id'];
                $target_id = (int)$request['target_user_id'];
                $del->bind_param("ii", $community_id_value, $target_id);
                $del->execute();
                $removed = $del->affected_rows;
                $del->close();
                if ($removed > 0) {
                    $upd_members = $conn->prepare("UPDATE communities SET members_count=GREATEST(members_count-1,0) WHERE id=?");
                    $upd_members->bind_param("i", $community_id_value);
                    $upd_members->execute();
                    $upd_members->close();
                } else {
                    // اگر فرد پیش‌تر خارج شده باشد، رأی‌گیری دیگر اخراج تازه‌ای انجام نمی‌دهد.
                    $status = 'rejected';
                }
            }
            $finish = $conn->prepare("UPDATE community_member_removal_requests SET status=?, decided_at=NOW() WHERE id=? AND status='open'");
            $finish->bind_param("si", $status, $request_id);
            $finish->execute();
            $finish->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Community member removal finalization failed: " . $e->getMessage());
        }
    }
    $result->close();
}
?>