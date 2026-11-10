<?php
declare(strict_types=1);

if (!function_exists('community_charter_prepare_conflict_review_pool')) {
    /**
     * 8 نامزد ارزیابی، 3 رسیدگی‌کننده و فهرست رأی‌دهندگان را جداگانه snapshot می‌کند.
     * همه شناسه‌ها باید از قبل به‌صورت تصادفی مرتب و از پیشنهاددهنده مستقل باشند.
     */
    function community_charter_prepare_conflict_review_pool(
        mysqli $conn,
        int $amendment_id,
        int $proposer_user_id,
        array $candidate_ids,
        array $adjudicator_ids,
        array $electorate_ids
    ): array {
        if ($amendment_id <= 0 || $proposer_user_id <= 0 ||
            count($candidate_ids) !== 8 || count($adjudicator_ids) !== 3 ||
            count($electorate_ids) < 3) {
            throw new RuntimeException('تعداد نامزدها، رسیدگی‌کنندگان یا رأی‌دهندگان کافی نیست.');
        }
        $all = [];
        foreach (array_merge($candidate_ids, $adjudicator_ids, $electorate_ids) as $id) {
            $id = (int)$id;
            if ($id <= 0 || $id === $proposer_user_id || isset($all[$id])) {
                throw new RuntimeException('نقش‌های ارزیابی، رسیدگی و رأی‌گیری باید مستقل و بدون تکرار باشند.');
            }
            $all[$id] = true;
        }

        $candidate = $conn->prepare(
            "INSERT INTO community_charter_amendment_reviewer_candidates
             (amendment_id,candidate_user_id,candidate_order) VALUES (?,?,?)"
        );
        if (!$candidate) throw new RuntimeException('ثبت نامزدهای ارزیابی ممکن نیست.');
        foreach (array_values($candidate_ids) as $index => $id) {
            $id = (int)$id; $order = $index + 1;
            $candidate->bind_param('iii', $amendment_id, $id, $order);
            if (!$candidate->execute()) {
                $candidate->close();
                throw new RuntimeException('ثبت نامزد ارزیابی انجام نشد.');
            }
        }
        $candidate->close();

        $adjudicator = $conn->prepare(
            "INSERT INTO community_charter_amendment_conflict_adjudicators
             (amendment_id,adjudicator_user_id) VALUES (?,?)"
        );
        if (!$adjudicator) throw new RuntimeException('ثبت رسیدگی‌کنندگان تعارض ممکن نیست.');
        foreach ($adjudicator_ids as $id) {
            $id = (int)$id;
            $adjudicator->bind_param('ii', $amendment_id, $id);
            if (!$adjudicator->execute()) {
                $adjudicator->close();
                throw new RuntimeException('ثبت رسیدگی‌کننده تعارض انجام نشد.');
            }
        }
        $adjudicator->close();

        $elector = $conn->prepare(
            "INSERT INTO community_charter_amendment_electorate (amendment_id,user_id) VALUES (?,?)"
        );
        if (!$elector) throw new RuntimeException('ثبت فهرست رأی‌دهندگان ممکن نیست.');
        foreach ($electorate_ids as $id) {
            $id = (int)$id;
            $elector->bind_param('ii', $amendment_id, $id);
            if (!$elector->execute()) {
                $elector->close();
                throw new RuntimeException('ثبت رأی‌دهنده انجام نشد.');
            }
        }
        $elector->close();

        return [
            'candidate_ids' => array_values(array_map('intval', $candidate_ids)),
            'adjudicator_ids' => array_values(array_map('intval', $adjudicator_ids)),
            'electorate_ids' => array_values(array_map('intval', $electorate_ids))
        ];
    }
}

if (!function_exists('community_charter_assign_eligible_candidate')) {
    function community_charter_assign_eligible_candidate(mysqli $conn, int $amendment_id, int $candidate_user_id): bool {
        $count = $conn->prepare(
            "SELECT COUNT(*) AS total FROM community_charter_amendment_reviewers WHERE amendment_id=?"
        );
        if (!$count) throw new RuntimeException('بررسی ظرفیت هیئت ارزیابی ممکن نیست.');
        $count->bind_param('i', $amendment_id);
        if (!$count->execute()) throw new RuntimeException('بررسی ظرفیت هیئت ارزیابی انجام نشد.');
        $total = (int)$count->get_result()->fetch_assoc()['total'];
        $count->close();
        if ($total >= 5) return false;

        $insert = $conn->prepare(
            "INSERT INTO community_charter_amendment_reviewers (amendment_id,reviewer_user_id)
             SELECT amendment_id,candidate_user_id
             FROM community_charter_amendment_reviewer_candidates
             WHERE amendment_id=? AND candidate_user_id=? AND status='eligible'"
        );
        if (!$insert) throw new RuntimeException('تخصیص ارزیاب مستقل ممکن نیست.');
        $insert->bind_param('ii', $amendment_id, $candidate_user_id);
        if (!$insert->execute()) {
            $insert->close();
            throw new RuntimeException('تخصیص ارزیاب مستقل انجام نشد.');
        }
        $assigned = $insert->affected_rows === 1;
        $insert->close();
        if (!$assigned) return false;

        $mark = $conn->prepare(
            "UPDATE community_charter_amendment_reviewer_candidates
             SET status='assigned' WHERE amendment_id=? AND candidate_user_id=? AND status='eligible'"
        );
        if (!$mark) throw new RuntimeException('ثبت وضعیت ارزیاب ممکن نیست.');
        $mark->bind_param('ii', $amendment_id, $candidate_user_id);
        if (!$mark->execute() || $mark->affected_rows !== 1) {
            $mark->close();
            throw new RuntimeException('ثبت وضعیت ارزیاب انجام نشد.');
        }
        $mark->close();

        $count = $conn->prepare(
            "SELECT COUNT(*) AS total FROM community_charter_amendment_reviewers WHERE amendment_id=?"
        );
        if (!$count) throw new RuntimeException('بررسی تکمیل هیئت ارزیابی ممکن نیست.');
        $count->bind_param('i', $amendment_id);
        if (!$count->execute()) throw new RuntimeException('بررسی تکمیل هیئت ارزیابی انجام نشد.');
        $total = (int)$count->get_result()->fetch_assoc()['total'];
        $count->close();
        if ($total === 5) community_charter_start_independent_review($conn, $amendment_id);
        return true;
    }
}

if (!function_exists('community_charter_record_conflict_declaration')) {
    function community_charter_record_conflict_declaration(
        mysqli $conn,
        int $amendment_id,
        int $candidate_user_id,
        string $declaration,
        array $categories,
        string $private_details
    ): void {
        $allowed = ['family_personal','financial','employment','professional','personal_dispute','direct_involvement','other'];
        if ($amendment_id <= 0 || $candidate_user_id <= 0 ||
            !in_array($declaration, ['no_conflict','recuse','review'], true) ||
            mb_strlen($private_details) > 10000 ||
            ($declaration === 'review' && (!$categories || mb_strlen($private_details) < 10))) {
            throw new RuntimeException('اعلام تعارض منافع کامل یا معتبر نیست.');
        }
        foreach ($categories as $category) {
            if (!is_string($category) || !in_array($category, $allowed, true)) {
                throw new RuntimeException('دسته تعارض منافع معتبر نیست.');
            }
        }
        if ($declaration === 'no_conflict' && ($categories || $private_details !== '')) {
            throw new RuntimeException('برای اعلام نبود تعارض، جزئیات تعارض نباید ارسال شود.');
        }
        if ($declaration === 'recuse') $categories = [];

        if (!$conn->begin_transaction()) throw new RuntimeException('شروع ثبت اعلام تعارض ممکن نیست.');
        try {
            $case = $conn->prepare("SELECT status FROM community_charter_amendments WHERE id=? FOR UPDATE");
            if (!$case) throw new RuntimeException('قفل پرونده ممکن نیست.');
            $case->bind_param('i', $amendment_id);
            if (!$case->execute()) throw new RuntimeException('خواندن پرونده انجام نشد.');
            $case_row = $case->get_result()->fetch_assoc(); $case->close();
            if (!$case_row || $case_row['status'] !== 'draft') throw new RuntimeException('اعلام تعارض فقط پیش از آغاز بررسی مستقل ممکن است.');

            $candidate = $conn->prepare(
                "SELECT status FROM community_charter_amendment_reviewer_candidates
                 WHERE amendment_id=? AND candidate_user_id=? FOR UPDATE"
            );
            if (!$candidate) throw new RuntimeException('بررسی نامزدی ارزیاب ممکن نیست.');
            $candidate->bind_param('ii', $amendment_id, $candidate_user_id);
            if (!$candidate->execute()) throw new RuntimeException('خواندن نامزدی انجام نشد.');
            $row = $candidate->get_result()->fetch_assoc(); $candidate->close();
            if (!$row || $row['status'] !== 'invited') throw new RuntimeException('این نامزد قبلاً اعلام وضعیت کرده یا دعوت معتبر ندارد.');

            $status = $declaration === 'no_conflict' ? 'eligible' :
                ($declaration === 'recuse' ? 'recused' : 'review_pending');
            $json = $categories ? json_encode(array_values($categories), JSON_UNESCAPED_UNICODE) : null;
            $details = $private_details !== '' ? $private_details : null;
            $save = $conn->prepare(
                "UPDATE community_charter_amendment_reviewer_candidates
                 SET status=?,declaration=?,conflict_categories=?,private_details=?,declared_at=NOW()
                 WHERE amendment_id=? AND candidate_user_id=? AND status='invited'"
            );
            if (!$save) throw new RuntimeException('ذخیره اعلام تعارض ممکن نیست.');
            $save->bind_param('ssssii', $status, $declaration, $json, $details, $amendment_id, $candidate_user_id);
            if (!$save->execute() || $save->affected_rows !== 1) {
                $save->close(); throw new RuntimeException('ذخیره اعلام تعارض انجام نشد.');
            }
            $save->close();
            if ($declaration === 'no_conflict') {
                community_charter_assign_eligible_candidate($conn, $amendment_id, $candidate_user_id);
            }
            if (!$conn->commit()) throw new RuntimeException('ثبت اعلام تعارض نهایی نشد.');
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    }
}

if (!function_exists('community_charter_record_conflict_decision')) {
    function community_charter_record_conflict_decision(
        mysqli $conn,
        int $amendment_id,
        int $candidate_user_id,
        int $adjudicator_user_id,
        string $verdict,
        string $reasoning
    ): void {
        if ($amendment_id <= 0 || $candidate_user_id <= 0 || $adjudicator_user_id <= 0 ||
            !in_array($verdict, ['clear','conflict','insufficient'], true) ||
            mb_strlen($reasoning) < 20 || mb_strlen($reasoning) > 10000) {
            throw new RuntimeException('تصمیم رسیدگی به تعارض معتبر نیست.');
        }
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع رسیدگی مستقل ممکن نیست.');
        try {
            $case = $conn->prepare("SELECT status FROM community_charter_amendments WHERE id=? FOR UPDATE");
            if (!$case) throw new RuntimeException('قفل پرونده ممکن نیست.');
            $case->bind_param('i', $amendment_id);
            if (!$case->execute()) throw new RuntimeException('خواندن پرونده انجام نشد.');
            $case_row = $case->get_result()->fetch_assoc(); $case->close();
            if (!$case_row || $case_row['status'] !== 'draft') throw new RuntimeException('رسیدگی تعارض فقط پیش از آغاز بررسی مستقل ممکن است.');

            $authorized = $conn->prepare(
                "SELECT 1 FROM community_charter_amendment_conflict_adjudicators
                 WHERE amendment_id=? AND adjudicator_user_id=?"
            );
            if (!$authorized) throw new RuntimeException('بررسی صلاحیت رسیدگی‌کننده ممکن نیست.');
            $authorized->bind_param('ii', $amendment_id, $adjudicator_user_id);
            if (!$authorized->execute() || !$authorized->get_result()->fetch_row()) {
                $authorized->close(); throw new RuntimeException('شما رسیدگی‌کننده مستقل این پرونده نیستید.');
            }
            $authorized->close();

            $candidate = $conn->prepare(
                "SELECT status FROM community_charter_amendment_reviewer_candidates
                 WHERE amendment_id=? AND candidate_user_id=? FOR UPDATE"
            );
            if (!$candidate) throw new RuntimeException('خواندن نامزد ممکن نیست.');
            $candidate->bind_param('ii', $amendment_id, $candidate_user_id);
            if (!$candidate->execute()) throw new RuntimeException('خواندن نامزد انجام نشد.');
            $candidate_row = $candidate->get_result()->fetch_assoc(); $candidate->close();
            if (!$candidate_row || $candidate_row['status'] !== 'review_pending') throw new RuntimeException('این اعلام در انتظار رسیدگی نیست.');

            $save = $conn->prepare(
                "INSERT INTO community_charter_amendment_conflict_decisions
                 (amendment_id,candidate_user_id,adjudicator_user_id,verdict,reasoning)
                 VALUES (?,?,?,?,?)"
            );
            if (!$save) throw new RuntimeException('ثبت تصمیم رسیدگی ممکن نیست.');
            $save->bind_param('iiiss', $amendment_id, $candidate_user_id, $adjudicator_user_id, $verdict, $reasoning);
            if (!$save->execute()) { $save->close(); throw new RuntimeException('هر رسیدگی‌کننده فقط یک تصمیم برای هر اعلام ثبت می‌کند.'); }
            $save->close();

            $counts = $conn->prepare(
                "SELECT verdict,COUNT(*) AS total FROM community_charter_amendment_conflict_decisions
                 WHERE amendment_id=? AND candidate_user_id=? GROUP BY verdict"
            );
            if (!$counts) throw new RuntimeException('جمع‌بندی رسیدگی ممکن نیست.');
            $counts->bind_param('ii', $amendment_id, $candidate_user_id);
            if (!$counts->execute()) throw new RuntimeException('خواندن تصمیم‌ها انجام نشد.');
            $totals = [];
            $result = $counts->get_result();
            while ($r = $result->fetch_assoc()) $totals[$r['verdict']] = (int)$r['total'];
            $counts->close();

            $resolved = null;
            foreach (['clear','conflict','insufficient'] as $choice) {
                if (($totals[$choice] ?? 0) >= 2) { $resolved = $choice; break; }
            }
            $submitted = array_sum($totals);
            if ($resolved !== null || $submitted >= 3) {
                $next = $resolved ?? 'insufficient';
                $new_status = $next === 'clear' ? 'eligible' : ($next === 'conflict' ? 'conflict' : 'insufficient');
                $mark = $conn->prepare(
                    "UPDATE community_charter_amendment_reviewer_candidates
                     SET status=?,resolved_at=NOW() WHERE amendment_id=? AND candidate_user_id=? AND status='review_pending'"
                );
                if (!$mark) throw new RuntimeException('ثبت نتیجه رسیدگی ممکن نیست.');
                $mark->bind_param('sii', $new_status, $amendment_id, $candidate_user_id);
                if (!$mark->execute() || $mark->affected_rows !== 1) { $mark->close(); throw new RuntimeException('ثبت نتیجه رسیدگی انجام نشد.'); }
                $mark->close();
                if ($next === 'clear') community_charter_assign_eligible_candidate($conn, $amendment_id, $candidate_user_id);
            }
            if (!$conn->commit()) throw new RuntimeException('تصمیم رسیدگی نهایی نشد.');
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    }
}
