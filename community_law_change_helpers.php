<?php
/**
 * گردش‌کار تغییر قانون: ثبت درخواست، ارزیابی مستقل و ارجاع به رأی جامعهٔ مادر.
 * ثبت درخواست اضطراری به‌تنهایی هیچ اثری بر اعتبار یا اجرای قانون ندارد.
 */
require_once __DIR__ . '/community_law_helpers.php';

if (!function_exists('community_law_change_shuffle')) {
    function community_law_change_shuffle(array &$items): void {
        for ($i=count($items)-1; $i>0; $i--) {
            $j=random_int(0,$i);
            [$items[$i],$items[$j]]=[$items[$j],$items[$i]];
        }
    }
}

if (!function_exists('community_law_open_change_case')) {
    /**
     * درخواست عضو جامعهٔ مبدأ یا جامعهٔ مشمول را ثبت می‌کند.
     * پنج ارزیاب مستقل از اعضای جامعهٔ مادر، به‌صورت تصادفی انتخاب می‌شوند.
     * در صورت نبود پنج ارزیاب بی‌طرف، کل ثبت درخواست rollback می‌شود.
     */
    function community_law_open_change_case(
        mysqli $conn, int $law_id, int $actor_user_id, int $requester_community_id,
        string $request_type, string $reason, ?string $evidence=null,
        bool $emergency_requested=false, ?string $requested_effective_at=null
    ): int|false {
        $reason=trim($reason);
        if ($law_id<=0 || $actor_user_id<=0 || $requester_community_id<=0 ||
            !in_array($request_type,['suspend','repeal','review_expiry','reinstate'],true) ||
            $reason==='' || mb_strlen($reason)>30000 ||
            ($evidence!==null && mb_strlen($evidence)>30000) ||
            ($emergency_requested && $request_type!=='suspend')) return false;

        if ($requested_effective_at!==null) {
            $date=DateTime::createFromFormat('Y-m-d H:i:s',$requested_effective_at);
            if (!$date || $date->format('Y-m-d H:i:s')!==$requested_effective_at) return false;
        }
        if ($request_type==='review_expiry' && $requested_effective_at===null) return false;
        if ($request_type==='review_expiry' && strtotime($requested_effective_at)<=time()+8*86400) return false;
        if ($request_type!=='review_expiry' && $requested_effective_at!==null) return false;

        $member=$conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
        if (!$member) return false;
        $member->bind_param('ii',$requester_community_id,$actor_user_id);
        if (!$member->execute()) { $member->close(); return false; }
        $is_member=(bool)$member->get_result()->fetch_row();
        $member->close();
        if (!$is_member) return false;

        $law=$conn->prepare(
            "SELECT l.originating_community_id,l.current_version_id,l.created_by,l.status AS law_status,
                    l.expires_at,l.effective_at,v.proposed_by
             FROM community_laws l
             JOIN community_law_versions v ON v.id=l.current_version_id AND v.law_id=l.id
             WHERE l.id=? AND l.status IN ('approved','suspended') AND v.status='approved'
               AND l.repealed_at IS NULL LIMIT 1"
        );
        if (!$law) return false;
        $law->bind_param('i',$law_id);
        if (!$law->execute()) { $law->close(); return false; }
        $law_row=$law->get_result()->fetch_assoc();
        $law->close();
        if (!$law_row) return false;

        $version_id=(int)$law_row['current_version_id'];
        $origin_id=(int)$law_row['originating_community_id'];
        $creator_id=(int)$law_row['created_by'];
        $proposer_id=(int)$law_row['proposed_by'];
        $law_status=(string)$law_row['law_status'];
        $expiry=$law_row['expires_at'];
        $effective=$law_row['effective_at'];
        if ($request_type==='suspend' &&
            ($law_status!=='approved' || ($effective!==null && strtotime($effective)>time()) ||
             ($expiry!==null && strtotime($expiry)<=time()+8*86400))) return false;
        if ($request_type==='repeal' && !in_array($law_status,['approved','suspended'],true)) return false;
        if ($request_type==='review_expiry' && $law_status!=='approved') return false;
        if ($request_type==='reinstate' &&
            ($law_status!=='suspended' || ($expiry!==null && strtotime($expiry)<=time()))) return false;
        if ($requester_community_id!==$origin_id &&
            !community_law_scope_matches($conn,$version_id,$requester_community_id)) return false;

        $pending_case=$conn->prepare(
            "SELECT 1 FROM community_law_change_cases
             WHERE law_id=? AND status IN ('submitted','under_review','review_complete','awaiting_vote')
             LIMIT 1"
        );
        if (!$pending_case) return false;
        $pending_case->bind_param('i',$law_id);
        if (!$pending_case->execute()) { $pending_case->close(); return false; }
        $has_pending=(bool)$pending_case->get_result()->fetch_row();
        $pending_case->close();
        if ($has_pending) return false;

        // ارزیابان باید عضو جامعهٔ مادر باشند و در پیشنهاد یا رأی‌گیری اولیه دخالت نداشته باشند.
        $candidates_stmt=$conn->prepare(
            "SELECT DISTINCT cm.user_id
             FROM community_members cm
             WHERE cm.community_id=?
               AND cm.user_id<>? AND cm.user_id<>? AND cm.user_id<>?
               AND NOT EXISTS (
                   SELECT 1 FROM community_law_votes lv
                   WHERE lv.law_version_id=? AND lv.voter_user_id=cm.user_id
               )
             ORDER BY cm.user_id"
        );
        if (!$candidates_stmt) return false;
        $candidates_stmt->bind_param('iiiii',$origin_id,$actor_user_id,$creator_id,$proposer_id,$version_id);
        if (!$candidates_stmt->execute()) { $candidates_stmt->close(); return false; }
        $candidates=[];
        $cr=$candidates_stmt->get_result();
        while ($row=$cr->fetch_assoc()) $candidates[]=(int)$row['user_id'];
        $candidates_stmt->close();
        if (count($candidates)<5) return false;
        community_law_change_shuffle($candidates);
        $reviewers=array_slice($candidates,0,5);

        $scope_ids=community_law_scope_community_ids($conn,$version_id);
        if ($scope_ids===null) return false;
        $scope_ids[]=$origin_id;
        $scope_ids=array_values(array_unique(array_map('intval',$scope_ids)));

        if (!$conn->begin_transaction()) return false;
        try {
            $insert=$conn->prepare(
                "INSERT INTO community_law_change_cases
                 (law_id,law_version_id,request_type,requested_by,requester_community_id,
                  reason,evidence,emergency_requested,requested_effective_at,status)
                 VALUES (?,?,?,?,?,?,?,?,?,'under_review')"
            );
            if (!$insert) throw new RuntimeException('case prepare failed');
            $emergency=$emergency_requested?1:0;
            $insert->bind_param('iisiissis',$law_id,$version_id,$request_type,$actor_user_id,
                $requester_community_id,$reason,$evidence,$emergency,$requested_effective_at);
            if (!$insert->execute()) throw new RuntimeException('case insert failed');
            $case_id=(int)$conn->insert_id;
            $insert->close();

            $assign=$conn->prepare(
                'INSERT INTO community_law_change_reviewers (case_id,reviewer_user_id,assigned_by) VALUES (?,?,NULL)'
            );
            if (!$assign) throw new RuntimeException('reviewer assignment prepare failed');
            foreach ($reviewers as $reviewer_id) {
                $assign->bind_param('ii',$case_id,$reviewer_id);
                if (!$assign->execute()) throw new RuntimeException('reviewer assignment failed');
            }
            $assign->close();

            $notice_text='درخواست بازبینی قانون ثبت شده است؛ تا پایان فرایند معتبر، قانون همچنان لازم‌الاجراست.';
            if ($request_type==='suspend') $notice_text='درخواست تعلیق قانون ثبت شده است؛ ثبت درخواست، حتی در حالت اضطراری، به‌معنای تعلیق قانون نیست.';
            elseif ($request_type==='repeal') $notice_text='درخواست لغو قانون ثبت شده است؛ قانون تا تصویب تصمیم معتبر همچنان لازم‌الاجراست.';
            elseif ($request_type==='reinstate') $notice_text='درخواست بازگرداندن قانون تعلیق‌شده ثبت شده است؛ تا تصویب درخواست، وضعیت تعلیق پابرجاست.';
            elseif ($request_type==='review_expiry') $notice_text='درخواست بازبینی تاریخ پایان اعتبار قانون ثبت شده است؛ تاریخ فعلی تا تصویب درخواست تغییر نمی‌کند.';
            $notice=$conn->prepare(
                "INSERT IGNORE INTO community_law_community_notices
                 (case_id,law_id,law_version_id,community_id,event_type,notice_text)
                 VALUES (?,?,?,?, 'change_requested',?)"
            );
            if (!$notice) throw new RuntimeException('notice prepare failed');
            foreach ($scope_ids as $community_id) {
                $notice->bind_param('iiiis',$case_id,$law_id,$version_id,$community_id,$notice_text);
                if (!$notice->execute()) throw new RuntimeException('notice insert failed');
            }
            $notice->close();

            $details=json_encode([
                'case_id'=>$case_id,'request_type'=>$request_type,
                'requester_community_id'=>$requester_community_id,
                'emergency_requested'=>$emergency_requested,
                'reviewer_count'=>count($reviewers),'status_effect_changed'=>false
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (!community_law_record_history($conn,$law_id,$version_id,$requester_community_id,
                $actor_user_id,'change_case_submitted',$details)) throw new RuntimeException('history failed');
            if (!$conn->commit()) throw new RuntimeException('commit failed');
            return $case_id;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}

if (!function_exists('community_law_submit_change_assessment')) {
    /**
     * هر ارزیاب فقط یک‌بار ارزیابی می‌کند. پس از تکمیل پنج ارزیابی:
     * ۴ رأی uphold پرونده را به رأی جامعهٔ مادر می‌فرستد؛
     * ۴ رأی dismiss درخواست را رد می‌کند؛ در غیر این صورت نتیجه inconclusive است.
     */
    function community_law_submit_change_assessment(
        mysqli $conn,int $case_id,int $reviewer_user_id,string $verdict,
        string $reasoning,?string $evidence=null
    ): string|false {
        $reasoning=trim($reasoning);
        if ($case_id<=0 || $reviewer_user_id<=0 ||
            !in_array($verdict,['uphold','dismiss','unclear'],true) ||
            mb_strlen($reasoning)<20 || mb_strlen($reasoning)>20000 ||
            ($evidence!==null && mb_strlen($evidence)>20000)) return false;
        if (!$conn->begin_transaction()) return false;
        try {
            $lock=$conn->prepare('SELECT c.law_id,c.law_version_id,c.status,l.originating_community_id FROM community_law_change_cases c JOIN community_laws l ON l.id=c.law_id WHERE c.id=? FOR UPDATE');
            if (!$lock) throw new RuntimeException('case lock failed');
            $lock->bind_param('i',$case_id);
            if (!$lock->execute()) throw new RuntimeException('case read failed');
            $case=$lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$case || $case['status']!=='under_review') throw new RuntimeException('case is not under review');

            $assigned=$conn->prepare('SELECT 1 FROM community_law_change_reviewers WHERE case_id=? AND reviewer_user_id=? LIMIT 1');
            if (!$assigned) throw new RuntimeException('reviewer check failed');
            $assigned->bind_param('ii',$case_id,$reviewer_user_id);
            if (!$assigned->execute() || !$assigned->get_result()->fetch_row()) throw new RuntimeException('not an assigned reviewer');
            $assigned->close();

            $save=$conn->prepare(
                'INSERT INTO community_law_change_assessments (case_id,reviewer_user_id,verdict,reasoning,evidence) VALUES (?,?,?,?,?)'
            );
            if (!$save) throw new RuntimeException('assessment prepare failed');
            $save->bind_param('iisss',$case_id,$reviewer_user_id,$verdict,$reasoning,$evidence);
            if (!$save->execute()) throw new RuntimeException('assessment already submitted or insert failed');
            $save->close();

            $counts=$conn->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(verdict='uphold') AS uphold_count,
                        SUM(verdict='dismiss') AS dismiss_count
                 FROM community_law_change_assessments WHERE case_id=?"
            );
            if (!$counts) throw new RuntimeException('assessment count failed');
            $counts->bind_param('i',$case_id);
            if (!$counts->execute()) throw new RuntimeException('assessment count query failed');
            $summary=$counts->get_result()->fetch_assoc();
            $counts->close();

            $new_status='under_review'; $review_result=null; $electorate_count=0;
            if ((int)$summary['total']>=5) {
                if ((int)$summary['uphold_count']>=4) {
                    $eligible_count_stmt=$conn->prepare('SELECT COUNT(*) AS total FROM community_members cm WHERE cm.community_id=? AND NOT EXISTS (SELECT 1 FROM community_law_change_reviewers r WHERE r.case_id=? AND r.reviewer_user_id=cm.user_id)');
                    if (!$eligible_count_stmt) throw new RuntimeException('electorate count prepare failed');
                    $origin_id=(int)$case['originating_community_id'];
                    $eligible_count_stmt->bind_param('ii',$origin_id,$case_id);
                    if (!$eligible_count_stmt->execute()) throw new RuntimeException('electorate count failed');
                    $eligible_count=(int)$eligible_count_stmt->get_result()->fetch_assoc()['total'];
                    $eligible_count_stmt->close();
                    if ($eligible_count>=3) {
                        $capture=$conn->prepare('INSERT IGNORE INTO community_law_change_electorate (case_id,user_id) SELECT ?,cm.user_id FROM community_members cm WHERE cm.community_id=? AND NOT EXISTS (SELECT 1 FROM community_law_change_reviewers r WHERE r.case_id=? AND r.reviewer_user_id=cm.user_id)');
                        if (!$capture) throw new RuntimeException('electorate snapshot prepare failed');
                        $capture->bind_param('iii',$case_id,$origin_id,$case_id);
                        if (!$capture->execute()) throw new RuntimeException('electorate snapshot failed');
                        $capture->close();
                        $electorate_count=(int)$conn->query('SELECT COUNT(*) AS total FROM community_law_change_electorate WHERE case_id='.(int)$case_id)->fetch_assoc()['total'];
                    }
                    if ($electorate_count>=3) {
                        $new_status='awaiting_vote'; $review_result='supported';
                    } else {
                        $new_status='review_complete'; $review_result='inconclusive';
                    }
                } elseif ((int)$summary['dismiss_count']>=4) {
                    $new_status='rejected'; $review_result='dismissed';
                } else {
                    $new_status='review_complete'; $review_result='inconclusive';
                }
                $summary_text=json_encode([
                    'reviewer_count'=>(int)$summary['total'],
                    'uphold_count'=>(int)$summary['uphold_count'],
                    'dismiss_count'=>(int)$summary['dismiss_count'],
                    'result'=>$review_result,
                    'vote_electorate_count'=>$electorate_count
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $update=$conn->prepare(
                    "UPDATE community_law_change_cases
                     SET status=?,review_result=?,review_summary=?,review_completed_at=NOW(),
                         vote_opened_at=IF(?='awaiting_vote',NOW(),NULL),
                         vote_closes_at=IF(?='awaiting_vote',DATE_ADD(NOW(),INTERVAL 7 DAY),NULL),
                         electorate_count=?
                     WHERE id=? AND status='under_review'"
                );
                if (!$update) throw new RuntimeException('case result update prepare failed');
                $update->bind_param('sssssii',$new_status,$review_result,$summary_text,$new_status,$new_status,$electorate_count,$case_id);
                if (!$update->execute() || $update->affected_rows!==1) throw new RuntimeException('case result update failed');
                $update->close();
                if (!community_law_record_history($conn,(int)$case['law_id'],(int)$case['law_version_id'],null,
                    $reviewer_user_id,'change_case_independent_review_completed',$summary_text)) {
                    throw new RuntimeException('review history failed');
                }
                if ($new_status==='awaiting_vote' && !community_law_record_history(
                    $conn,(int)$case['law_id'],(int)$case['law_version_id'],$origin_id,
                    $reviewer_user_id,'change_case_parent_vote_opened',
                    json_encode(['case_id'=>$case_id,'electorate_count'=>$electorate_count,'duration_days'=>7],JSON_UNESCAPED_UNICODE)
                )) throw new RuntimeException('vote opening history failed');
            }
            if (!$conn->commit()) throw new RuntimeException('assessment commit failed');
            return $new_status;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}

if (!function_exists('community_law_change_cast_vote')) {
    /** ارزیابان مستقل در رأی نهایی شرکت نمی‌کنند؛ رأی‌دهندگان فقط از snapshot مادر هستند. */
    function community_law_change_cast_vote(mysqli $conn,int $case_id,int $user_id,string $vote,?string $comment=null): bool {
        if ($case_id<=0 || $user_id<=0 || !in_array($vote,['yes','no','abstain'],true) ||
            ($comment!==null && mb_strlen($comment)>5000) || !$conn->begin_transaction()) return false;
        try {
            $case=$conn->prepare("SELECT status,(vote_closes_at IS NOT NULL AND vote_closes_at>NOW()) AS vote_open FROM community_law_change_cases WHERE id=? FOR UPDATE");
            if (!$case) throw new RuntimeException('vote case lock failed');
            $case->bind_param('i',$case_id);
            if (!$case->execute()) throw new RuntimeException('vote case read failed');
            $row=$case->get_result()->fetch_assoc(); $case->close();
            if (!$row || $row['status']!=='awaiting_vote' || (int)$row['vote_open']!==1) throw new RuntimeException('vote is closed');

            $eligible=$conn->prepare('SELECT 1 FROM community_law_change_electorate WHERE case_id=? AND user_id=? LIMIT 1');
            if (!$eligible) throw new RuntimeException('electorate check failed');
            $eligible->bind_param('ii',$case_id,$user_id);
            if (!$eligible->execute() || !$eligible->get_result()->fetch_row()) throw new RuntimeException('voter is not in the electorate');
            $eligible->close();

            $save=$conn->prepare(
                'INSERT INTO community_law_change_votes (case_id,user_id,vote,comment) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE vote=VALUES(vote),comment=VALUES(comment),voted_at=NOW()'
            );
            if (!$save) throw new RuntimeException('vote save prepare failed');
            $save->bind_param('iiss',$case_id,$user_id,$vote,$comment);
            if (!$save->execute()) throw new RuntimeException('vote save failed');
            $save->close();
            if (!$conn->commit()) throw new RuntimeException('vote commit failed');
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}

if (!function_exists('community_law_change_apply_approved_case')) {
    /**
     * اثر مصوب را داخل تراکنش رأی نهایی اعمال می‌کند.
     * snapshot اجرای قبلی هر زیرجامعه پیش از تغییر وضعیت ذخیره می‌شود.
     */
    function community_law_change_apply_approved_case(mysqli $conn,int $case_id,?int $actor_user_id=null): bool {
        if ($case_id<=0) return false;
        $stmt=$conn->prepare(
            "SELECT c.law_id,c.law_version_id,c.request_type,c.requested_effective_at,
                    c.status,c.vote_result,c.effect_applied_at,
                    l.originating_community_id,l.status AS law_status,
                    l.current_version_id,l.suspension_case_id,l.expires_at
             FROM community_law_change_cases c
             JOIN community_laws l ON l.id=c.law_id
             WHERE c.id=? FOR UPDATE"
        );
        if (!$stmt) return false;
        $stmt->bind_param('i',$case_id);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row || $row['status']!=='approved' || $row['vote_result']!=='approved' ||
            (int)$row['current_version_id']!==(int)$row['law_version_id']) return false;
        if ($row['effect_applied_at']!==null) return true;

        $law_id=(int)$row['law_id']; $version_id=(int)$row['law_version_id'];
        $request_type=(string)$row['request_type']; $origin_id=(int)$row['originating_community_id'];
        $snapshot_stmt=null;
        if (in_array($request_type,['suspend','repeal'],true)) {
            $snapshot_stmt=$conn->prepare(
                "INSERT IGNORE INTO community_law_change_implementation_snapshots
                 (case_id,law_id,law_version_id,community_id,previous_responsible_user_id,
                  previous_local_plan,previous_status,previous_started_at,previous_target_date,
                  previous_completed_at,previous_progress_report)
                 SELECT ?,?,law_version_id,community_id,responsible_user_id,local_plan,status,
                        started_at,target_date,completed_at,progress_report
                 FROM community_law_implementations WHERE law_version_id=?"
            );
            if (!$snapshot_stmt) return false;
            $snapshot_stmt->bind_param('iii',$case_id,$law_id,$version_id);
            if (!$snapshot_stmt->execute()) { $snapshot_stmt->close(); return false; }
            $snapshot_stmt->close();
        }

        $notice_event=''; $history_event=''; $notice_text=''; $effect=[];
        if ($request_type==='suspend') {
            if ($row['law_status']!=='approved') return false;
            $update_law=$conn->prepare(
                "UPDATE community_laws SET status='suspended',suspended_at=NOW(),
                    suspension_case_id=?,last_status_change_case_id=?
                 WHERE id=? AND status='approved' AND repealed_at IS NULL"
            );
            if (!$update_law) return false;
            $update_law->bind_param('iii',$case_id,$case_id,$law_id);
            $ok=$update_law->execute() && $update_law->affected_rows===1; $update_law->close();
            if (!$ok) return false;
            $update_impl=$conn->prepare(
                "UPDATE community_law_implementations SET status='suspended'
                 WHERE law_version_id=? AND status<>'not_applicable'"
            );
            if (!$update_impl) return false;
            $update_impl->bind_param('i',$version_id);
            $ok=$update_impl->execute(); $update_impl->close();
            if (!$ok) return false;
            $notice_event='suspended'; $history_event='law_suspended';
            $notice_text='قانون پس از رأی معتبر جامعهٔ مادر به حالت تعلیق درآمده است؛ سوابق اجرای قبلی حفظ شده‌اند.';
            $effect=['status'=>'suspended','implementation_snapshots'=>true];
        } elseif ($request_type==='repeal') {
            if (!in_array($row['law_status'],['approved','suspended'],true)) return false;
            $update_law=$conn->prepare(
                "UPDATE community_laws SET status='repealed',repealed_at=NOW(),
                    last_status_change_case_id=?
                 WHERE id=? AND status IN ('approved','suspended') AND repealed_at IS NULL"
            );
            if (!$update_law) return false;
            $update_law->bind_param('ii',$case_id,$law_id);
            $ok=$update_law->execute() && $update_law->affected_rows===1; $update_law->close();
            if (!$ok) return false;
            $update_impl=$conn->prepare(
                "UPDATE community_law_implementations SET status='not_applicable'
                 WHERE law_version_id=?"
            );
            if (!$update_impl) return false;
            $update_impl->bind_param('i',$version_id);
            $ok=$update_impl->execute(); $update_impl->close();
            if (!$ok) return false;
            $notice_event='repealed'; $history_event='law_repealed';
            $notice_text='قانون پس از رأی معتبر جامعهٔ مادر لغو شد؛ سوابق و گزارش‌های اجرای پیشین حفظ شده‌اند.';
            $effect=['status'=>'repealed','implementation_snapshots'=>true];
        } elseif ($request_type==='review_expiry') {
            $new_expiry=(string)($row['requested_effective_at']??'');
            $date=DateTime::createFromFormat('Y-m-d H:i:s',$new_expiry);
            if ($row['law_status']!=='approved' || !$date ||
                $date->format('Y-m-d H:i:s')!==$new_expiry || $date->getTimestamp()<=time()) return false;
            $update_law=$conn->prepare(
                "UPDATE community_laws SET expires_at=?,last_status_change_case_id=?
                 WHERE id=? AND status='approved' AND repealed_at IS NULL"
            );
            if (!$update_law) return false;
            $update_law->bind_param('sii',$new_expiry,$case_id,$law_id);
            $ok=$update_law->execute(); $update_law->close();
            if (!$ok) return false;
            $notice_event='expiry_updated'; $history_event='law_expiry_updated';
            $notice_text='تاریخ پایان اعتبار قانون طبق رأی معتبر جامعهٔ مادر بازبینی و به‌روزرسانی شد.';
            $effect=['expires_at'=>$new_expiry];
        } elseif ($request_type==='reinstate') {
            if ($row['law_status']!=='suspended' || $row['suspension_case_id']===null ||
                ($row['expires_at']!==null && strtotime((string)$row['expires_at'])<=time())) return false;
            $suspension_case_id=(int)$row['suspension_case_id'];
            $restore=$conn->prepare(
                "UPDATE community_law_implementations i
                 JOIN community_law_change_implementation_snapshots s
                   ON s.community_id=i.community_id AND s.law_version_id=i.law_version_id
                 SET i.responsible_user_id=s.previous_responsible_user_id,
                     i.local_plan=s.previous_local_plan,i.status=s.previous_status,
                     i.started_at=s.previous_started_at,i.target_date=s.previous_target_date,
                     i.completed_at=s.previous_completed_at,i.progress_report=s.previous_progress_report
                 WHERE s.case_id=? AND s.law_version_id=?"
            );
            if (!$restore) return false;
            $restore->bind_param('ii',$suspension_case_id,$version_id);
            $ok=$restore->execute(); $restore->close();
            if (!$ok) return false;
            $update_law=$conn->prepare(
                "UPDATE community_laws SET status='approved',suspended_at=NULL,
                    suspension_case_id=NULL,last_status_change_case_id=?
                 WHERE id=? AND status='suspended' AND repealed_at IS NULL"
            );
            if (!$update_law) return false;
            $update_law->bind_param('ii',$case_id,$law_id);
            $ok=$update_law->execute() && $update_law->affected_rows===1; $update_law->close();
            if (!$ok) return false;
            $notice_event='reinstated'; $history_event='law_reinstated';
            $notice_text='قانون پس از رأی معتبر جامعهٔ مادر از تعلیق خارج شد و وضعیت اجرای قبلی زیرجامعه‌ها بازیابی شد.';
            $effect=['status'=>'approved','restored_from_suspension_case'=>$suspension_case_id];
        } else {
            return false;
        }

        $effect_json=json_encode($effect,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($effect_json) || !community_law_record_history(
            $conn,$law_id,$version_id,$origin_id,$actor_user_id,$history_event,$effect_json
        )) return false;

        $scope_ids=community_law_scope_community_ids($conn,$version_id);
        if ($scope_ids===null) return false;
        $scope_ids[]=$origin_id;
        $scope_ids=array_values(array_unique(array_map('intval',$scope_ids)));
        $notice=$conn->prepare(
            'INSERT IGNORE INTO community_law_community_notices
             (case_id,law_id,law_version_id,community_id,event_type,notice_text) VALUES (?,?,?,?,?,?)'
        );
        if (!$notice) return false;
        foreach ($scope_ids as $community_id) {
            $notice->bind_param('iiiiss',$case_id,$law_id,$version_id,$community_id,$notice_event,$notice_text);
            if (!$notice->execute()) { $notice->close(); return false; }
        }
        $notice->close();

        $mark=$conn->prepare(
            'UPDATE community_law_change_cases
             SET effect_applied_at=NOW(),effect_applied_by=?,effect_summary=?
             WHERE id=? AND effect_applied_at IS NULL'
        );
        if (!$mark) return false;
        $mark->bind_param('isi',$actor_user_id,$effect_json,$case_id);
        $ok=$mark->execute() && $mark->affected_rows===1; $mark->close();
        return $ok;
    }
}

if (!function_exists('community_law_change_finalize_vote')) {
    function community_law_change_finalize_vote(mysqli $conn,int $case_id): string|false {
        if ($case_id<=0 || !$conn->begin_transaction()) return false;
        try {
            $lock=$conn->prepare(
                'SELECT law_id,law_version_id,status,electorate_count,turnout_numerator,
                        turnout_denominator,approval_numerator,approval_denominator,
                        (vote_closes_at IS NOT NULL AND vote_closes_at<=NOW()) AS vote_closed
                 FROM community_law_change_cases WHERE id=? FOR UPDATE'
            );
            if (!$lock) throw new RuntimeException('vote case lock failed');
            $lock->bind_param('i',$case_id);
            if (!$lock->execute()) throw new RuntimeException('vote case read failed');
            $case=$lock->get_result()->fetch_assoc(); $lock->close();
            if (!$case || $case['status']!=='awaiting_vote' || (int)$case['vote_closed']!==1) throw new RuntimeException('vote is not closed');

            $counts=$conn->prepare("SELECT COUNT(*) AS turnout,SUM(vote='yes') AS yes_count,SUM(vote='no') AS no_count FROM community_law_change_votes WHERE case_id=?");
            if (!$counts) throw new RuntimeException('vote count prepare failed');
            $counts->bind_param('i',$case_id);
            if (!$counts->execute()) throw new RuntimeException('vote count failed');
            $votes=$counts->get_result()->fetch_assoc(); $counts->close();
            $turnout=(int)$votes['turnout']; $yes=(int)$votes['yes_count']; $no=(int)$votes['no_count'];
            $electorate=(int)$case['electorate_count'];
            if ($electorate<3) throw new RuntimeException('invalid electorate snapshot');
            $turnout_ok=($turnout*(int)$case['turnout_denominator'])>=($electorate*(int)$case['turnout_numerator']);
            $valid_votes=$yes+$no;
            $approval_ok=$valid_votes>0 && ($yes*(int)$case['approval_denominator'])>=($valid_votes*(int)$case['approval_numerator']);
            if (!$turnout_ok) { $result='insufficient_turnout'; $status='rejected'; }
            elseif ($approval_ok) { $result='approved'; $status='approved'; }
            else { $result='rejected'; $status='rejected'; }
            $note=json_encode([
                'electorate'=>$electorate,'turnout'=>$turnout,'yes'=>$yes,'no'=>$no,
                'abstain'=>$turnout-$valid_votes,'turnout_required'=>'2/3',
                'approval_required'=>'2/3','result'=>$result,'law_effect_applied'=>($status==='approved')
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $update=$conn->prepare("UPDATE community_law_change_cases SET status=?,vote_result=?,decided_at=NOW(),decision_note=? WHERE id=? AND status='awaiting_vote'");
            if (!$update) throw new RuntimeException('vote result update prepare failed');
            $update->bind_param('sssi',$status,$result,$note,$case_id);
            if (!$update->execute() || $update->affected_rows!==1) throw new RuntimeException('vote result update failed');
            $update->close();
            if ($status==='approved' && !community_law_change_apply_approved_case($conn,$case_id,null)) {
                throw new RuntimeException('approved law change could not be applied atomically');
            }
            if (!community_law_record_history($conn,(int)$case['law_id'],(int)$case['law_version_id'],null,null,'change_case_parent_vote_finalized',$note)) throw new RuntimeException('vote result history failed');

            $scope_ids=community_law_scope_community_ids($conn,(int)$case['law_version_id']);
            if ($scope_ids===null) throw new RuntimeException('law scope unavailable');
            $scope_ids[]=(int)$conn->query('SELECT originating_community_id FROM community_laws WHERE id='.(int)$case['law_id'])->fetch_assoc()['originating_community_id'];
            $scope_ids=array_values(array_unique(array_map('intval',$scope_ids)));
            $message=$status==='approved'
                ? 'درخواست تغییر قانون با رأی معتبر جامعهٔ مادر تصویب شد و اثر حقوقی آن در همان تراکنش اعمال گردید.'
                : 'درخواست تغییر قانون در رأی جامعهٔ مادر پذیرفته نشد؛ قانون بدون تغییر به اعتبار خود ادامه می‌دهد.';
            $notice=$conn->prepare("INSERT IGNORE INTO community_law_community_notices (case_id,law_id,law_version_id,community_id,event_type,notice_text) VALUES (?,?,?,?, 'change_vote_result',?)");
            if (!$notice) throw new RuntimeException('vote notice prepare failed');
            foreach ($scope_ids as $community_id) {
                $law_id=(int)$case['law_id']; $version_id=(int)$case['law_version_id'];
                $notice->bind_param('iiiis',$case_id,$law_id,$version_id,$community_id,$message);
                if (!$notice->execute()) throw new RuntimeException('vote notice insert failed');
            }
            $notice->close();
            if (!$conn->commit()) throw new RuntimeException('vote finalize commit failed');
            return $result;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}
?>
