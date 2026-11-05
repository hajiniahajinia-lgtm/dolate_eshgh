<?php
// منطق مشترک نهایی‌سازی رأی و ساخت پروندهٔ دانش.
// این فایل از صفحات جامعه و رأی‌گیری فراخوانی می‌شود.

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

        $existing_stmt = $conn->prepare("SELECT entry_id FROM collective_knowledge_decisions WHERE topic_id=? LIMIT 1");
        if (!$existing_stmt) throw new Exception("decision check prepare: " . $conn->error);
        $existing_stmt->bind_param("i", $topic_id);
        if (!$existing_stmt->execute()) throw new Exception("decision check execute: " . $existing_stmt->error);
        $existing = $existing_stmt->get_result()->fetch_assoc();
        $existing_stmt->close();
        if ($existing) {
            $conn->commit();
            return true;
        }

        $topic_stmt = $conn->prepare("
            SELECT t.title, t.description, t.user_id, t.community_id,
                   COALESCE(t.topic_purpose, 'mixed') AS topic_purpose,
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
        $entry_type = $purpose === 'knowledge' ? 'research' : ($purpose === 'execution' ? 'solution' : 'lesson');
        $purpose_label = $purpose === 'knowledge' ? 'شناختی' : ($purpose === 'execution' ? 'اجرایی' : 'ترکیبی');
        $topic_title = trim((string)$topic['title']);
        $entry_title = mb_substr('پروندهٔ دانش: ' . $topic_title, 0, 180, 'UTF-8');
        $summary = mb_substr('پروندهٔ دانش جمعی برای موضوع «' . $topic_title . '». ماهیت موضوع: ' . $purpose_label . '. ایدهٔ منتخب و نتیجهٔ پذیرش جمعی و لازم‌الاجرا بودن در بخش‌های جداگانه ثبت شده‌اند.', 0, 1000, 'UTF-8');
        $idea_content = $topic['idea_content'] !== null ? (string)$topic['idea_content'] : null;
        $content = "موضوع: " . $topic_title . "\n\n";
        $content .= "ماهیت موضوع: " . $purpose_label . "\n\n";
        $content .= "شرح موضوع:\n" . trim((string)$topic['description']) . "\n\n";
        $content .= "ایدهٔ منتخب بر اساس ارزیابی ثبت‌شده در سامانه:\n" . ($idea_content !== null && trim($idea_content) !== '' ? $idea_content : 'برای این موضوع ایده‌ای به‌عنوان منتخب ثبت نشده است.') . "\n\n";
        $content .= "نتیجهٔ رأی پذیرش جمعی: " . $acceptance_status . "؛ موافق: " . $yes_count . "، مخالف: " . $no_count . "، درصد موافق خام: " . $yes_percent . "٪، درصد موافق وزنی: " . $weighted_yes_percent . "٪.\n";
        $content .= "نتیجهٔ رأی لازم‌الاجرا بودن: " . $enforceability_status . "؛ موافق اجرا: " . $enforce_yes . "، مخالف اجرا: " . $enforce_no . ".\n\n";
        $content .= "یادآوری: انتخاب جمعی یا برتری یک ایده به‌تنهایی به معنای اثبات علمی یا تجربی آن نیست. شواهد و نتایج اجرا باید جداگانه بررسی و تکمیل شوند.";

        $creator_id = (int)$topic['user_id'] > 0 ? (int)$topic['user_id'] : (int)$fallback_user_id;
        $community_value = (int)$topic['community_id'];
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
