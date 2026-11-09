<?php
// منطق مشترک نهایی‌سازی رأی و ساخت پروندهٔ دانش.
// این فایل از صفحات جامعه و رأی‌گیری فراخوانی می‌شود.
require_once __DIR__ . '/community_decision_ledger.php';

// ایجاد پروندهٔ دانش و snapshot تصمیم؛ این عملیات برای هر موضوع فقط یک‌بار انجام می‌شود.
function create_collective_knowledge_decision($conn, $topic_id, $fallback_user_id) {
    if (!$conn->begin_transaction()) {
        error_log("Knowledge decision transaction could not start for topic " . $topic_id);
        return false;
    }

    try {
        // قفل meta، اجرای هم‌زمان این بخش را برای یک موضوع سریالی می‌کند.
        $meta_stmt = $conn->prepare("SELECT finalized FROM topic_voting_meta WHERE topic_id=? LIMIT 1 FOR UPDATE");
        if (!$meta_stmt) throw new Exception("meta prepare: " . $conn->error);
        $meta_stmt->bind_param("i", $topic_id);
        if (!$meta_stmt->execute()) throw new Exception("meta execute: " . $meta_stmt->error);
        $meta_row = $meta_stmt->get_result()->fetch_assoc();
        $meta_stmt->close();
        if (!$meta_row) throw new Exception("topic voting metadata not found");

        // جدول snapshot قدیمی اختياری است؛ دفتر مستقل تصمیم مرجع جدید خواهد بود.
        $legacy_table_check = $conn->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name='collective_knowledge_decisions'
             LIMIT 1"
        );
        if (!$legacy_table_check) throw new Exception("legacy decision table check failed: " . $conn->error);
        $legacy_decisions_available = (bool)$legacy_table_check->fetch_row();
        $legacy_table_check->free();

        if ($legacy_decisions_available) {
            $existing_stmt = $conn->prepare(
                "SELECT entry_id FROM collective_knowledge_decisions WHERE topic_id=? LIMIT 1"
            );
            if (!$existing_stmt) throw new Exception("decision check prepare: " . $conn->error);
            $existing_stmt->bind_param("i", $topic_id);
            if (!$existing_stmt->execute()) throw new Exception("decision check execute: " . $existing_stmt->error);
            $existing = $existing_stmt->get_result()->fetch_assoc();
            $existing_stmt->close();
            if ($existing) {
                // مهاجرت تدریجی: snapshotهای قدیمی هنگام مراجعه به دفتر مستقل منتقل می‌شوند.
                $legacy_snapshot_stmt = $conn->prepare(
                    "SELECT t.community_id, COALESCE(t.topic_intent, 'decision') AS topic_intent,
                            d.selected_idea_id, d.selected_idea_content,
                            d.topic_purpose_snapshot, d.acceptance_status, d.enforceability_status,
                            d.yes_count, d.no_count, d.yes_weight, d.no_weight,
                            d.yes_percent, d.weighted_yes_percent,
                            d.enforce_yes_count, d.enforce_no_count, d.finalized_at
                     FROM collective_knowledge_decisions d
                     JOIN topics t ON t.id=d.topic_id
                     WHERE d.topic_id=? LIMIT 1"
                );
                if (!$legacy_snapshot_stmt) throw new Exception("legacy snapshot lookup prepare: " . $conn->error);
                $legacy_snapshot_stmt->bind_param("i", $topic_id);
                if (!$legacy_snapshot_stmt->execute()) throw new Exception("legacy snapshot lookup execute: " . $legacy_snapshot_stmt->error);
                $legacy_row = $legacy_snapshot_stmt->get_result()->fetch_assoc();
                $legacy_snapshot_stmt->close();
                if (!$legacy_row) throw new Exception("legacy decision exists but its source topic is missing");

                $legacy_idea_id = (int)$legacy_row['selected_idea_id'] > 0
                    ? (int)$legacy_row['selected_idea_id']
                    : null;
                $legacy_snapshot = [
                    'community_id' => (int)$legacy_row['community_id'],
                    'source_topic_id' => (int)$topic_id,
                    'source_idea_id' => $legacy_idea_id,
                    'topic_purpose_snapshot' => (string)$legacy_row['topic_purpose_snapshot'],
                    'topic_intent' => in_array($legacy_row['topic_intent'], ['knowledge', 'decision', 'law'], true) ? (string)$legacy_row['topic_intent'] : 'decision',
                    'selected_idea_content' => $legacy_row['selected_idea_content'],
                    'acceptance_status' => (string)$legacy_row['acceptance_status'],
                    'enforceability_status' => (string)$legacy_row['enforceability_status'],
                    'yes_count' => (int)$legacy_row['yes_count'],
                    'no_count' => (int)$legacy_row['no_count'],
                    'yes_weight' => (float)$legacy_row['yes_weight'],
                    'no_weight' => (float)$legacy_row['no_weight'],
                    'yes_percent' => (float)$legacy_row['yes_percent'],
                    'weighted_yes_percent' => (float)$legacy_row['weighted_yes_percent'],
                    'enforce_yes_count' => (int)$legacy_row['enforce_yes_count'],
                    'enforce_no_count' => (int)$legacy_row['enforce_no_count'],
                    'decided_at' => (string)$legacy_row['finalized_at'],
                ];
                if (!community_decision_record_snapshot($conn, $legacy_snapshot)) {
                    throw new Exception("legacy decision could not be copied to the independent ledger");
                }
                if (!$conn->commit()) throw new Exception("legacy decision migration commit failed");
                return true;
            }
        }

        $topic_stmt = $conn->prepare("
            SELECT t.title, t.description, t.user_id, t.community_id,
                   COALESCE(t.topic_purpose, 'mixed') AS topic_purpose,
                   COALESCE(t.topic_intent, 'decision') AS topic_intent,
                   w.idea_id, i.content AS idea_content
            FROM topics t
            LEFT JOIN topic_winners w ON w.topic_id=t.id
            LEFT JOIN ideas i ON i.id=w.idea_id AND i.topic_id=t.id
            WHERE t.id=? LIMIT 1
        ");
        if (!$topic_stmt) throw new Exception("topic prepare: " . $conn->error);
        $topic_stmt->bind_param("i", $topic_id);
        if (!$topic_stmt->execute()) throw new Exception("topic execute: " . $topic_stmt->error);
        $topic = $topic_stmt->get_result()->fetch_assoc();
        $topic_stmt->close();
        if (!$topic) throw new Exception("topic not found");

        // نتیجهٔ پذیرش باید فقط بر پایهٔ رأی‌های ایدهٔ منتخب محاسبه شود.
        $source_idea_id = $topic['idea_id'] !== null ? (int)$topic['idea_id'] : null;
        $selected_idea_id = $source_idea_id ?? 0;

        $vote_stmt = $conn->prepare("
            SELECT
                SUM(vote_type='yes') AS yes_count,
                SUM(vote_type='no') AS no_count,
                SUM(CASE WHEN vote_type='yes' THEN vote_weight ELSE 0 END) AS yes_weight,
                SUM(CASE WHEN vote_type='no' THEN vote_weight ELSE 0 END) AS no_weight
            FROM topic_voting_ballots
            WHERE topic_id=? AND idea_id=?
        ");
        if (!$vote_stmt) throw new Exception("ballot prepare: " . $conn->error);
        $vote_stmt->bind_param("ii", $topic_id, $selected_idea_id);
        if (!$vote_stmt->execute()) throw new Exception("ballot execute: " . $vote_stmt->error);
        $vote_stats = $vote_stmt->get_result()->fetch_assoc() ?: [];
        $vote_stmt->close();

        $enforce_stmt = $conn->prepare("
            SELECT SUM(enforceable=1) AS enforce_yes, SUM(enforceable=0) AS enforce_no
            FROM topic_enforce_votes WHERE topic_id=? AND idea_id=?
        ");
        if (!$enforce_stmt) throw new Exception("enforce prepare: " . $conn->error);
        $enforce_stmt->bind_param("ii", $topic_id, $selected_idea_id);
        if (!$enforce_stmt->execute()) throw new Exception("enforce execute: " . $enforce_stmt->error);
        $enforce_stats = $enforce_stmt->get_result()->fetch_assoc() ?: [];
        $enforce_stmt->close();

        $yes_count = (int)($vote_stats['yes_count'] ?? 0);
        $no_count = (int)($vote_stats['no_count'] ?? 0);
        $yes_weight = (float)($vote_stats['yes_weight'] ?? 0);
        $no_weight = (float)($vote_stats['no_weight'] ?? 0);
        $raw_total = $yes_count + $no_count;
        $weight_total = $yes_weight + $no_weight;
        $yes_percent = $raw_total > 0 ? round(($yes_count / $raw_total) * 100, 2) : 0;
        $weighted_yes_percent = $weight_total > 0 ? round(($yes_weight / $weight_total) * 100, 2) : 0;
        $enforce_yes = (int)($enforce_stats['enforce_yes'] ?? 0);
        $enforce_no = (int)($enforce_stats['enforce_no'] ?? 0);
        // با توجه به وزن تخصصی رأی‌ها، پذیرش جمعی نیز بر اساس اکثریت وزنی تعیین می‌شود.
        $acceptance_status = $weight_total <= 0 ? 'no_votes' : ($weighted_yes_percent > 50 ? 'accepted' : 'not_accepted');
        $enforceability_status = $enforce_yes > $enforce_no ? 'enforceable' : ($enforce_no > $enforce_yes ? 'not_enforceable' : 'undecided');

        $purpose = in_array($topic['topic_purpose'], ['knowledge', 'execution', 'mixed'], true) ? $topic['topic_purpose'] : 'mixed';
        $topic_intent = in_array($topic['topic_intent'], ['knowledge', 'decision', 'law'], true) ? $topic['topic_intent'] : 'decision';
        $intent_label = $topic_intent === 'knowledge' ? 'دانش‌محور' : ($topic_intent === 'law' ? 'قانون‌گذاری' : 'تصمیم‌گیری');
        $entry_type = $purpose === 'knowledge' ? 'research' : ($purpose === 'execution' ? 'solution' : 'lesson');
        $purpose_label = $purpose === 'knowledge' ? 'شناختی' : ($purpose === 'execution' ? 'اجرایی' : 'ترکیبی');
        $topic_title = trim((string)$topic['title']);
        $entry_title = mb_substr('پروندهٔ دانش: ' . $topic_title, 0, 180, 'UTF-8');
        $summary = mb_substr('پروندهٔ دانش جمعی برای موضوع «' . $topic_title . '». مسیر موضوع: ' . $intent_label . '. ماهیت موضوع: ' . $purpose_label . '. ایدهٔ منتخب فقط موضوع بررسی است و شواهد و ارزیابی دانش باید جداگانه سنجیده شوند.', 0, 1000, 'UTF-8');
        $idea_content = $topic['idea_content'] !== null ? (string)$topic['idea_content'] : null;
        $content = "موضوع: " . $topic_title . "\n\n";
        $content .= "مسیر موضوع: " . $intent_label . "\n\n";
        $content .= "ماهیت موضوع: " . $purpose_label . "\n\n";
        $content .= "شرح موضوع:\n" . trim((string)$topic['description']) . "\n\n";
        $content .= "ایدهٔ منتخب در فرآیند جمعی (برای بررسی، نه اثبات صحت):\n" . ($idea_content !== null && trim($idea_content) !== '' ? $idea_content : 'برای این موضوع ایده‌ای به‌عنوان منتخب ثبت نشده است.') . "\n\n";
        $content .= "یادداشت تفکیک: شمار رأی‌ها، نتیجهٔ تصمیم و وضعیت اجرای آن در دفتر مستقل تصمیم‌های جامعه نگهداری می‌شود و بخشی از محتوای دانش نیست.\n\n";
        $quality_notes = [];
        if (mb_strlen(trim($topic_title), 'UTF-8') < 10) $quality_notes[] = 'عنوان موضوع کوتاه است';
        if (trim((string)$topic['description']) === '') $quality_notes[] = 'شرح موضوع ثبت نشده است';
        if ($idea_content === null || trim($idea_content) === '') $quality_notes[] = 'ایدهٔ منتخب ثبت نشده است';
        if (!empty($quality_notes)) {
            $content .= "وضعیت کفایت اطلاعات: نیازمند تکمیل — " . implode('؛ ', $quality_notes) . ".\n\n";
        } else {
            $content .= "وضعیت کفایت اطلاعات اولیه: دارای عنوان، شرح و ایدهٔ منتخب؛ این موارد هنوز نیازمند بررسی و ارزیابی هستند.\n\n";
        }
        $content .= "یادآوری: انتخاب جمعی یا برتری یک ایده به‌تنهایی به معنای اثبات علمی یا تجربی آن نیست. شواهد و نتایج اجرا باید جداگانه بررسی و تکمیل شوند.";

        // نخست نتیجهٔ جمعی در دفتر مستقل تصمیم ثبت و تثبیت می‌شود؛
        // ساخت یا ارزیابی پروندهٔ دانش در تراکنش جداگانه انجام خواهد شد.
        $decision_time_result = $conn->query("SELECT NOW() AS decided_at");
        if (!$decision_time_result) throw new Exception("decision timestamp lookup failed: " . $conn->error);
        $decision_time_row = $decision_time_result->fetch_assoc() ?: [];
        $decision_time_result->free();
        $decision_snapshot = [
            'community_id' => (int)$topic['community_id'],
            'source_topic_id' => (int)$topic_id,
            'source_idea_id' => $source_idea_id,
            'topic_purpose_snapshot' => $purpose,
            'topic_intent' => $topic_intent,
            'selected_idea_content' => $idea_content,
            'acceptance_status' => $acceptance_status,
            'enforceability_status' => $enforceability_status,
            'yes_count' => $yes_count,
            'no_count' => $no_count,
            'yes_weight' => $yes_weight,
            'no_weight' => $no_weight,
            'yes_percent' => $yes_percent,
            'weighted_yes_percent' => $weighted_yes_percent,
            'enforce_yes_count' => $enforce_yes,
            'enforce_no_count' => $enforce_no,
            'decided_at' => (string)($decision_time_row['decided_at'] ?? ''),
        ];
        if (!community_decision_record_snapshot($conn, $decision_snapshot)) {
            throw new Exception("independent community decision snapshot could not be recorded");
        }
        if (!$conn->commit()) throw new Exception("decision ledger commit failed");

        // از این نقطه به بعد، شکست کتابخانهٔ دانش نباید نتیجهٔ تصمیم را حذف کند.
        if (!$conn->begin_transaction()) throw new Exception("knowledge transaction could not start");
        $relock_meta = $conn->prepare(
            "SELECT finalized FROM topic_voting_meta WHERE topic_id=? LIMIT 1 FOR UPDATE"
        );
        if (!$relock_meta) throw new Exception("meta relock prepare: " . $conn->error);
        $relock_meta->bind_param("i", $topic_id);
        if (!$relock_meta->execute()) throw new Exception("meta relock execute: " . $relock_meta->error);
        $relocked_meta = $relock_meta->get_result()->fetch_assoc();
        $relock_meta->close();
        if (!$relocked_meta) throw new Exception("topic voting metadata disappeared before knowledge creation");

        // درخواست هم‌زمان دیگری ممکن است در فاصلهٔ دو تراکنش پروندهٔ سازگاری قدیمی را ساخته باشد.
        if ($legacy_decisions_available) {
            $legacy_existing_stmt = $conn->prepare(
                "SELECT entry_id FROM collective_knowledge_decisions WHERE topic_id=? LIMIT 1"
            );
            if (!$legacy_existing_stmt) throw new Exception("legacy decision recheck prepare: " . $conn->error);
            $legacy_existing_stmt->bind_param("i", $topic_id);
            if (!$legacy_existing_stmt->execute()) throw new Exception("legacy decision recheck execute: " . $legacy_existing_stmt->error);
            $legacy_existing = $legacy_existing_stmt->get_result()->fetch_assoc();
            $legacy_existing_stmt->close();
            if ($legacy_existing) {
                if (!$conn->commit()) throw new Exception("knowledge retry commit failed");
                return true;
            }
        }

        $creator_id = (int)$topic['user_id'] > 0 ? (int)$topic['user_id'] : (int)$fallback_user_id;
        $community_value = (int)$topic['community_id'];
        // اگر موضوع از قبل پروندهٔ موقت داشته باشد، همان پرونده تکمیل می‌شود؛
        // در غیر این صورت پروندهٔ نهایی تازه ساخته خواهد شد.
        $draft_stmt = $conn->prepare("
            SELECT id
            FROM collective_knowledge_entries
            WHERE source_topic_id=? AND title LIKE 'پروندهٔ در حال شکل‌گیری:%'
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");
        if (!$draft_stmt) throw new Exception("knowledge draft lookup prepare: " . $conn->error);
        $draft_stmt->bind_param("i", $topic_id);
        if (!$draft_stmt->execute()) throw new Exception("knowledge draft lookup execute: " . $draft_stmt->error);
        $draft_row = $draft_stmt->get_result()->fetch_assoc();
        $draft_stmt->close();

        if ($draft_row) {
            $entry_id = (int)$draft_row['id'];
            $update_entry = $conn->prepare("
                UPDATE collective_knowledge_entries
                SET title=?, entry_type=?, summary=?, content=?, source_idea_id=?, status='unreviewed'
                WHERE id=? AND source_topic_id=?
            ");
            if (!$update_entry) throw new Exception("knowledge draft update prepare: " . $conn->error);
            $update_entry->bind_param("ssssiii", $entry_title, $entry_type, $summary, $content, $source_idea_id, $entry_id, $topic_id);
            if (!$update_entry->execute()) throw new Exception("knowledge draft update execute: " . $update_entry->error);
            $update_entry->close();
        } else {
            $entry_stmt = $conn->prepare("
                INSERT INTO collective_knowledge_entries
                    (title, entry_type, summary, content, evidence, visibility, community_id, source_topic_id, source_idea_id, created_by)
                VALUES (?, ?, ?, ?, '', 'community', ?, ?, ?, ?)
            ");
            if (!$entry_stmt) throw new Exception("knowledge entry prepare: " . $conn->error);
            $entry_stmt->bind_param("ssssiiii", $entry_title, $entry_type, $summary, $content, $community_value, $topic_id, $source_idea_id, $creator_id);
            if (!$entry_stmt->execute()) throw new Exception("knowledge entry execute: " . $entry_stmt->error);
            $entry_id = (int)$conn->insert_id;
            $entry_stmt->close();
        }

        // پیوند ارجاعی دانش به تصمیم مستقل؛ تصمیم حتی بدون پروندهٔ دانش نیز معتبر می‌ماند.
        if (!community_decision_attach_knowledge_entry($conn, (int)$topic_id, (int)$entry_id)) {
            throw new Exception("knowledge entry could not be linked to its independent decision record");
        }

        // تا زمانی که مصرف‌کنندگان قدیمی به دفتر مستقل منتقل نشده‌اند،
        // snapshot سازگاری فقط در صورت وجود جدول قدیمی نیز نوشته می‌شود.
        if ($legacy_decisions_available) {
            $decision_stmt = $conn->prepare("
                INSERT INTO collective_knowledge_decisions
                    (topic_id, entry_id, selected_idea_id, selected_idea_content, topic_purpose_snapshot,
                     yes_count, no_count, yes_weight, no_weight, yes_percent, weighted_yes_percent,
                     enforce_yes_count, enforce_no_count, acceptance_status, enforceability_status, finalized_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            if (!$decision_stmt) throw new Exception("decision insert prepare: " . $conn->error);
            $decision_stmt->bind_param(
                "iiissiiddddiiss",
                $topic_id, $entry_id, $source_idea_id, $idea_content, $purpose,
                $yes_count, $no_count, $yes_weight, $no_weight, $yes_percent, $weighted_yes_percent,
                $enforce_yes, $enforce_no, $acceptance_status, $enforceability_status
            );
            if (!$decision_stmt->execute()) throw new Exception("decision insert execute: " . $decision_stmt->error);
            $decision_stmt->close();
        }

        if (!$conn->commit()) throw new Exception("transaction commit failed");
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Collective knowledge finalization failed for topic " . $topic_id . ": " . $e->getMessage());
        return false;
    }
}

// گرفتن لیست موضوعات در حال رأی‌گیری

function finalize_collective_knowledge_for_community($conn, $community_id, $user_id) {
    $community_id = (int)$community_id;
    $user_id = (int)$user_id;
    if ($community_id <= 0 || $user_id <= 0) return false;

    // فقط عضو همان جامعه اجازه دارد بازدیدش اجرای نهایی‌سازی را فعال کند.
    $member_stmt = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
    if (!$member_stmt) {
        error_log("Knowledge finalizer membership check prepare failed: " . $conn->error);
        return false;
    }
    $member_stmt->bind_param("ii", $community_id, $user_id);
    if (!$member_stmt->execute()) {
        error_log("Knowledge finalizer membership check execute failed: " . $member_stmt->error);
        $member_stmt->close();
        return false;
    }
    $is_member = (bool)$member_stmt->get_result()->fetch_row();
    $member_stmt->close();
    if (!$is_member) return false;

    // برای موضوعات بسته‌شده‌ای که هنوز meta ندارند، همان پیش‌فرض قبلی votes.php حفظ می‌شود.
    $meta_stmt = $conn->prepare("
        INSERT INTO topic_voting_meta (topic_id, urgency_days, voting_started, finalized)
        SELECT t.id, 5, t.created_at, 0
        FROM topics t
        LEFT JOIN topic_voting_meta m ON m.topic_id = t.id
        WHERE t.community_id = ? AND t.closed = 1 AND m.topic_id IS NULL
    ");
    if (!$meta_stmt) {
        error_log("Knowledge finalizer meta prepare failed: " . $conn->error);
        return false;
    }
    $meta_stmt->bind_param("i", $community_id);
    if (!$meta_stmt->execute()) {
        error_log("Knowledge finalizer meta execute failed: " . $meta_stmt->error);
        $meta_stmt->close();
        return false;
    }
    $meta_stmt->close();

    $finalize_q = $conn->prepare("
        SELECT t.id, m.voting_started, m.urgency_days, m.finalized
        FROM topics t
        JOIN topic_voting_meta m ON m.topic_id = t.id
        WHERE t.community_id=? 
          AND t.closed = 1
          AND m.finalized = 0
    ");
    $finalize_q->bind_param("i", $community_id);
    $finalize_q->execute();
    $finalize_res = $finalize_q->get_result();
    
    while($row = $finalize_res->fetch_assoc()){
    
        $tid = (int)$row['id'];
        $started = strtotime($row['voting_started']);
        $days    = (int)$row['urgency_days'];
        $deadline = $started + ($days * 24 * 3600);
    
        // اگر زمان رأی‌گیری تمام شده
        if(time() >= $deadline){
    
            // ==========================
            // 1️⃣ — گرفتن تعداد لازم‌الاجرا / غیر لازم‌الاجرا برای هر ایده
            // ==========================
            $ideas_q = $conn->query("
                SELECT id AS idea_id
                FROM ideas
                WHERE topic_id = $tid
            ");
    
            if($ideas_q && $ideas_q->num_rows > 0){
                while($idea_row = $ideas_q->fetch_assoc()){
                    $iid = (int)$idea_row['idea_id'];
    
                    $en = $conn->query("
                        SELECT 
                            SUM(enforceable = 1) AS enforce_count,
                            SUM(enforceable = 0) AS non_enforce_count
                        FROM topic_enforce_votes
                        WHERE topic_id = $tid AND idea_id = $iid
                    ")->fetch_assoc();
    
                    $enforce_count      = (int)$en['enforce_count'];
                    $non_enforce_count  = (int)$en['non_enforce_count'];
    
                    // ذخیره در topic_enforce_stats با idea_id
                    $conn->query("
                        INSERT INTO topic_enforce_stats (topic_id, idea_id, enforce_count, non_enforce_count)
                        VALUES ($tid, $iid, $enforce_count, $non_enforce_count)
                        ON DUPLICATE KEY UPDATE 
                            enforce_count = VALUES(enforce_count),
                            non_enforce_count = VALUES(non_enforce_count),
                            updated_at = CURRENT_TIMESTAMP
                    ");
                }
            }
    
            // ==========================
            // 2️⃣ — مشخص کردن ایده برنده
            // ==========================
            $best = $conn->query("
                SELECT id, content, user_id
                FROM ideas
                WHERE topic_id=$tid
                ORDER BY idea_rationality_weighted DESC, idea_rationality DESC, id ASC
                LIMIT 1
            ");
    
            if($best && $best->num_rows > 0){
                $best_data = $best->fetch_assoc();
                $idea_id = (int)$best_data['id'];
    
                // جدول topic_winners در پایگاه دادهٔ فعلی فقط ستون‌های id, topic_id, idea_id و selected_at را دارد.
                $winner_check = $conn->prepare("SELECT id FROM topic_winners WHERE topic_id=? LIMIT 1");
                $winner_check->bind_param("i", $tid);
                $winner_check->execute();
                $existing_winner = $winner_check->get_result()->fetch_assoc();
                $winner_check->close();
    
                if ($existing_winner) {
                    $winner_row_id = (int)$existing_winner['id'];
                    $winner_update = $conn->prepare("UPDATE topic_winners SET idea_id=?, selected_at=NOW() WHERE id=?");
                    $winner_update->bind_param("ii", $idea_id, $winner_row_id);
                    $winner_update->execute();
                    $winner_update->close();
                } else {
                    $winner_insert = $conn->prepare("INSERT INTO topic_winners (topic_id, idea_id, selected_at) VALUES (?, ?, NOW())");
                    $winner_insert->bind_param("ii", $tid, $idea_id);
                    $winner_insert->execute();
                    $winner_insert->close();
                }        }
    
            // پیش از بستن موضوع، پروندهٔ دانش و snapshot تصمیم باید با موفقیت ثبت شوند.
            // در صورت خطا، موضوع باز می‌ماند تا بارگذاری بعدی دوباره تلاش کند.
            if (!create_collective_knowledge_decision($conn, $tid, $user_id)) {
                continue;
            }
    
            // ==========================
            // 3️⃣ — نهایی‌سازی meta
            // ==========================
            $conn->query("
                UPDATE topic_voting_meta 
                SET finalized=1, finalized_at=NOW() 
                WHERE topic_id=$tid
            ");
    
            // ==========================
            // 4️⃣ — انتقال موضوع به آرشیو
            // ==========================
            $conn->query("UPDATE topics SET closed=2 WHERE id=$tid");
        }
    }
    
    $finalize_q->close();
    return true;
}



// ایجاد تدریجی پرونده برای موضوعات باز؛ نتیجهٔ رأی یا تصمیم نهایی در این مرحله ثبت نمی‌شود.
function ensure_collective_knowledge_drafts_for_community($conn, $community_id, $user_id, $limit = 10) {
    $community_id = (int)$community_id;
    $user_id = (int)$user_id;
    $limit = max(1, min(10, (int)$limit));
    if ($community_id <= 0 || $user_id <= 0) return 0;

    $member_stmt = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
    if (!$member_stmt) return 0;
    $member_stmt->bind_param("ii", $community_id, $user_id);
    if (!$member_stmt->execute()) {
        $member_stmt->close();
        return 0;
    }
    $is_member = (bool)$member_stmt->get_result()->fetch_row();
    $member_stmt->close();
    if (!$is_member) return 0;

    $sql = "
        SELECT t.id
        FROM topics t
        WHERE t.community_id=? AND t.closed=0
          AND NOT EXISTS (
              SELECT 1 FROM collective_knowledge_entries e
              WHERE e.source_topic_id=t.id
          )
          AND NOT EXISTS (
              SELECT 1 FROM collective_knowledge_decisions d
              WHERE d.topic_id=t.id
          )
        ORDER BY t.id DESC
        LIMIT " . $limit;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Knowledge draft selection prepare failed: " . $conn->error);
        return 0;
    }
    $stmt->bind_param("i", $community_id);
    if (!$stmt->execute()) {
        error_log("Knowledge draft selection execute failed: " . $stmt->error);
        $stmt->close();
        return 0;
    }
    $result = $stmt->get_result();
    $topic_ids = [];
    while ($row = $result->fetch_assoc()) $topic_ids[] = (int)$row['id'];
    $stmt->close();

    $created = 0;
    $debug_errors = [];
    foreach ($topic_ids as $topic_id) {
        if (!$conn->begin_transaction()) continue;
        try {
            $lock = $conn->prepare("SELECT id, title, description, topic_purpose, topic_intent, user_id, community_id FROM topics WHERE id=? AND community_id=? AND closed=0 LIMIT 1 FOR UPDATE");
            if (!$lock) throw new Exception("topic lock prepare: " . $conn->error);
            $lock->bind_param("ii", $topic_id, $community_id);
            if (!$lock->execute()) throw new Exception("topic lock execute: " . $lock->error);
            $topic = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$topic) {
                $conn->commit();
                continue;
            }

            $exists = $conn->prepare("SELECT id FROM collective_knowledge_entries WHERE source_topic_id=? LIMIT 1");
            if (!$exists) throw new Exception("draft duplicate check prepare: " . $conn->error);
            $exists->bind_param("i", $topic_id);
            if (!$exists->execute()) throw new Exception("draft duplicate check execute: " . $exists->error);
            $existing_entry = $exists->get_result()->fetch_assoc();
            $exists->close();
            if ($existing_entry) {
                $conn->commit();
                continue;
            }

            $purpose = in_array($topic['topic_purpose'], ['knowledge', 'execution', 'mixed'], true) ? $topic['topic_purpose'] : 'mixed';
            $topic_intent = in_array($topic['topic_intent'], ['knowledge', 'decision', 'law'], true) ? $topic['topic_intent'] : 'decision';
            $intent_label = $topic_intent === 'knowledge' ? 'دانش‌محور' : ($topic_intent === 'law' ? 'قانون‌گذاری' : 'تصمیم‌گیری');
            $entry_type = $purpose === 'knowledge' ? 'research' : ($purpose === 'execution' ? 'solution' : 'lesson');
            $purpose_label = $purpose === 'knowledge' ? 'شناختی' : ($purpose === 'execution' ? 'اجرایی' : 'ترکیبی');
            $topic_title = trim((string)$topic['title']);
            $entry_title = mb_substr('پروندهٔ در حال شکل‌گیری: ' . $topic_title, 0, 180, 'UTF-8');
            $summary = mb_substr('پروندهٔ دانشِ در حال شکل‌گیری برای موضوع «' . $topic_title . '». مسیر موضوع: ' . $intent_label . '. این پرونده تا پایان بررسی و رأی موضوع تکمیل می‌شود.', 0, 1000, 'UTF-8');
            $content = "موضوع: " . $topic_title . "\n\n";
            $content .= "مسیر موضوع: " . $intent_label . "\n\n";
            $content .= "ماهیت موضوع: " . $purpose_label . "\n\n";
            $content .= "شرح موضوع:\n" . trim((string)$topic['description']) . "\n\n";
            $content .= "وضعیت: در حال شکل‌گیری؛ هنوز هیچ ایده‌ای به‌عنوان منتخب نهایی و هیچ نتیجه‌ای به‌عنوان رأی جمعی ثبت نشده است.\n\n";
            $content .= "ایده‌ها، نقدها، بازخوردها و شواهد مرتبط در طول بررسی موضوع به این پرونده متصل می‌شوند.";

            $creator_id = (int)$topic['user_id'] > 0 ? (int)$topic['user_id'] : $user_id;
            $insert = $conn->prepare("
                INSERT INTO collective_knowledge_entries
                    (title, entry_type, summary, content, evidence, visibility, community_id, source_topic_id, source_idea_id, created_by)
                VALUES (?, ?, ?, ?, '', 'community', ?, ?, NULL, ?)
            ");
            if (!$insert) throw new Exception("draft insert prepare: " . $conn->error);
            $insert->bind_param("ssssiii", $entry_title, $entry_type, $summary, $content, $community_id, $topic_id, $creator_id);
            if (!$insert->execute()) throw new Exception("draft insert execute: " . $insert->error);
            $insert->close();
            if (!$conn->commit()) throw new Exception("draft commit failed");
            $created++;
        } catch (Throwable $e) {
            $conn->rollback();
            $debug_errors[] = "موضوع " . $topic_id . ": " . $e->getMessage();
            error_log("Knowledge draft creation failed for topic " . $topic_id . ": " . $e->getMessage());
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE && $user_id === 27) {
        $_SESSION['knowledge_draft_debug'] =
            "شناسه جامعه: " . $community_id .
            "؛ شناسه کاربر: " . $user_id .
            "؛ تعداد موضوعات واجد شرایط: " . count($topic_ids) .
            "؛ پرونده‌های ساخته‌شده: " . $created .
            (!empty($debug_errors) ? "؛ خطاها: " . implode(" | ", $debug_errors) : "؛ خطای ثبت‌شده‌ای وجود ندارد.");
    }
    return $created;
}

// تکمیل تدریجی پرونده‌های تاریخی؛ فقط برای موضوعات نهایی‌شده و بدون پرونده.
// این تابع برنده یا رأی‌ها را تغییر نمی‌دهد و در هر بار اجرا حداکثر ۱۰ پرونده می‌سازد.
function backfill_collective_knowledge_for_community($conn, $community_id, $user_id, $limit = 10) {
    $community_id = (int)$community_id;
    $user_id = (int)$user_id;
    $limit = max(1, min(10, (int)$limit));
    if ($community_id <= 0 || $user_id <= 0) return 0;

    $member_stmt = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
    if (!$member_stmt) return 0;
    $member_stmt->bind_param("ii", $community_id, $user_id);
    if (!$member_stmt->execute()) {
        $member_stmt->close();
        return 0;
    }
    $is_member = (bool)$member_stmt->get_result()->fetch_row();
    $member_stmt->close();
    if (!$is_member) return 0;

    $sql = "
        SELECT t.id
        FROM topics t
        JOIN topic_voting_meta m ON m.topic_id=t.id
        LEFT JOIN collective_knowledge_decisions d ON d.topic_id=t.id
        WHERE t.community_id=?
          AND t.closed=2
          AND m.finalized=1
          AND d.topic_id IS NULL
          AND NOT EXISTS (
              SELECT 1
              FROM collective_knowledge_entries e
              WHERE e.source_topic_id=t.id
          )
        ORDER BY t.id ASC
        LIMIT " . $limit;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Knowledge backfill prepare failed: " . $conn->error);
        return 0;
    }
    $stmt->bind_param("i", $community_id);
    if (!$stmt->execute()) {
        error_log("Knowledge backfill execute failed: " . $stmt->error);
        $stmt->close();
        return 0;
    }
    $result = $stmt->get_result();
    $topic_ids = [];
    while ($row = $result->fetch_assoc()) $topic_ids[] = (int)$row['id'];
    $stmt->close();

    $created = 0;
    foreach ($topic_ids as $topic_id) {
        if (create_collective_knowledge_decision($conn, $topic_id, $user_id)) {
            $created++;
        }
    }
    return $created;
}
