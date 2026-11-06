<?php
/**
 * انتقال موضوعات منقضی‌شده به رأی‌گیری نهایی.
 * این پردازش در بازدید صفحه جامعه یا رأی‌گیری اجرا می‌شود و به جامعه جاری محدود نیست.
 * موضوعات بایگانی‌شده (closed=2) عمداً دست‌نخورده می‌مانند.
 */
function process_expired_topics($conn) {
    $ids = [];
    $result = $conn->query("SELECT id FROM topics WHERE closed=0 ORDER BY id ASC");
    if (!$result) {
        error_log("Expired topics scan failed: " . $conn->error);
        return;
    }
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $result->close();

    foreach ($ids as $topic_id) {
        try {
            if (!$conn->begin_transaction()) {
                error_log("Expired topic transaction could not start: " . $topic_id);
                continue;
            }

            $topic_stmt = $conn->prepare(
                "SELECT urgency, urgency_minutes, created_at FROM topics WHERE id=? AND closed=0 LIMIT 1 FOR UPDATE"
            );
            if (!$topic_stmt) {
                throw new Exception("Topic lock prepare failed: " . $conn->error);
            }
            $topic_stmt->bind_param("i", $topic_id);
            if (!$topic_stmt->execute()) {
                throw new Exception("Topic lock execute failed: " . $topic_stmt->error);
            }
            $topic = $topic_stmt->get_result()->fetch_assoc();
            $topic_stmt->close();

            if (!$topic) {
                $conn->commit();
                continue;
            }

            $created = strtotime((string)$topic['created_at']);
            if ($created === false) {
                $conn->commit();
                continue;
            }
            $urgency_minutes = (int)($topic['urgency_minutes'] ?? 0);
            if ($urgency_minutes > 0) {
                $deadline = $created + ($urgency_minutes * 60);
            } else {
                // سازگاری با موضوعات قدیمی فاقد مقدار دقیقه‌ای: urgency هنوز روز محسوب می‌شود.
                $urgency = (int)$topic['urgency'];
                $legacy_days = $urgency > 0 ? $urgency : 3;
                $deadline = $created + ($legacy_days * 86400);
            }
            if (time() < $deadline) {
                $conn->commit();
                continue;
            }

            $best_stmt = $conn->prepare(
                "SELECT id FROM ideas WHERE topic_id=? ORDER BY idea_rationality_weighted DESC, idea_rationality DESC, id ASC LIMIT 1"
            );
            if (!$best_stmt) {
                throw new Exception("Best idea prepare failed: " . $conn->error);
            }
            $best_stmt->bind_param("i", $topic_id);
            if (!$best_stmt->execute()) {
                throw new Exception("Best idea execute failed: " . $best_stmt->error);
            }
            $best_idea = $best_stmt->get_result()->fetch_assoc();
            $best_stmt->close();

            $close_stmt = $conn->prepare("UPDATE topics SET closed=1 WHERE id=? AND closed=0");
            if (!$close_stmt) {
                throw new Exception("Topic close prepare failed: " . $conn->error);
            }
            $close_stmt->bind_param("i", $topic_id);
            if (!$close_stmt->execute()) {
                throw new Exception("Topic close execute failed: " . $close_stmt->error);
            }
            $close_stmt->close();

            if ($best_idea) {
                $idea_id = (int)$best_idea['id'];
                $final_stmt = $conn->prepare(
                    "INSERT IGNORE INTO topic_final_votes (topic_id, idea_id) VALUES (?, ?)"
                );
                if (!$final_stmt) {
                    throw new Exception("Final vote prepare failed: " . $conn->error);
                }
                $final_stmt->bind_param("ii", $topic_id, $idea_id);
                if (!$final_stmt->execute()) {
                    throw new Exception("Final vote insert failed: " . $final_stmt->error);
                }
                $final_stmt->close();
            }

            if (!$conn->commit()) {
                throw new Exception("Topic finalization commit failed: " . $conn->error);
            }
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Expired topic processing failed for topic " . $topic_id . ": " . $e->getMessage());
        }
    }
}
