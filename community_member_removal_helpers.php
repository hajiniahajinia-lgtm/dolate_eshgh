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
    $sql = "SELECT id, community_id
            FROM community_member_removal_requests
            WHERE status='open' AND deadline_at <= NOW()";
    if ($community_id > 0) $sql .= " AND community_id=" . (int)$community_id;
    $sql .= " ORDER BY community_id, id";
    $result = $conn->query($sql);
    if (!$result) return;

    while ($request = $result->fetch_assoc()) {
        $request_id = (int)$request['id'];
        $community_id_value = (int)$request['community_id'];

        if (!$conn->begin_transaction()) {
            error_log("Community member removal finalization: transaction start failed");
            continue;
        }

        try {
            // ترتیب قفل در تمام مسیرهای عضویت یکسان است: ابتدا جامعه، سپس درخواست.
            $community_lock = $conn->prepare("SELECT id FROM communities WHERE id=? FOR UPDATE");
            if (!$community_lock) throw new RuntimeException("prepare removal community lock failed");
            $community_lock->bind_param("i", $community_id_value);
            if (!$community_lock->execute()) throw new RuntimeException("lock removal community failed");
            $community_exists = (bool)$community_lock->get_result()->fetch_row();
            $community_lock->close();
            if (!$community_exists) throw new RuntimeException("removal community no longer exists");

            $request_lock = $conn->prepare(
                "SELECT status, deadline_at, target_user_id, member_count_snapshot
                 FROM community_member_removal_requests WHERE id=? FOR UPDATE"
            );
            if (!$request_lock) throw new RuntimeException("prepare removal request lock failed");
            $request_lock->bind_param("i", $request_id);
            if (!$request_lock->execute()) throw new RuntimeException("lock removal request failed");
            $current = $request_lock->get_result()->fetch_assoc();
            $request_lock->close();

            if (!$current || $current['status'] !== 'open' || strtotime($current['deadline_at']) > time()) {
                $conn->commit();
                continue;
            }

            // شمارش رأی‌ها پس از گرفتن قفل‌ها انجام می‌شود تا رأی هم‌زمانِ پیش از مهلت جا نماند.
            $vote_rows = $conn->prepare(
                "SELECT vote FROM community_member_removal_votes WHERE request_id=? FOR UPDATE"
            );
            if (!$vote_rows) throw new RuntimeException("prepare removal vote count failed");
            $vote_rows->bind_param("i", $request_id);
            if (!$vote_rows->execute()) throw new RuntimeException("read removal votes failed");
            $vote_result = $vote_rows->get_result();
            $yes = 0;
            $no = 0;
            $total = 0;
            while ($vote_row = $vote_result->fetch_assoc()) {
                $total++;
                if ($vote_row['vote'] === 'yes') $yes++;
                elseif ($vote_row['vote'] === 'no') $no++;
            }
            $vote_rows->close();

            $quorum = (int)ceil(((int)$current['member_count_snapshot']) / 3);
            $approved = $quorum > 0 && $total >= $quorum && $yes > $no;
            $status = $approved ? 'approved' : 'rejected';

            if ($approved) {
                $delete_member = $conn->prepare(
                    "DELETE FROM community_members WHERE community_id=? AND user_id=?"
                );
                if (!$delete_member) throw new RuntimeException("prepare member removal failed");
                $target_user_id = (int)$current['target_user_id'];
                $delete_member->bind_param("ii", $community_id_value, $target_user_id);
                if (!$delete_member->execute()) throw new RuntimeException("remove member failed");
                $removed = $delete_member->affected_rows;
                $delete_member->close();

                if ($removed > 0) {
                    $update_count = $conn->prepare(
                        "UPDATE communities SET members_count=GREATEST(members_count-?,0) WHERE id=?"
                    );
                    if (!$update_count) throw new RuntimeException("prepare member count update failed");
                    $update_count->bind_param("ii", $removed, $community_id_value);
                    if (!$update_count->execute()) throw new RuntimeException("update member count failed");
                    $update_count->close();
                } else {
                    $status = 'rejected';
                }
            }

            $finish = $conn->prepare(
                "UPDATE community_member_removal_requests
                 SET status=?, decided_at=NOW() WHERE id=? AND status='open'"
            );
            if (!$finish) throw new RuntimeException("prepare removal finalization failed");
            $finish->bind_param("si", $status, $request_id);
            if (!$finish->execute()) throw new RuntimeException("finalize removal request failed");
            $finish->close();

            if (!$conn->commit()) throw new RuntimeException("commit member removal failed");
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Community member removal finalization failed: " . $e->getMessage());
        }
    }
    $result->close();
}
?>