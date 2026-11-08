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
            !in_array($request_type,['suspend','repeal','review_expiry'],true) ||
            $reason==='' || mb_strlen($reason)>30000 ||
            ($evidence!==null && mb_strlen($evidence)>30000) ||
            ($emergency_requested && $request_type!=='suspend')) return false;

        if ($requested_effective_at!==null) {
            $date=DateTime::createFromFormat('Y-m-d H:i:s',$requested_effective_at);
            if (!$date || $date->format('Y-m-d H:i:s')!==$requested_effective_at) return false;
        }

        $member=$conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
        if (!$member) return false;
        $member->bind_param('ii',$requester_community_id,$actor_user_id);
        if (!$member->execute()) { $member->close(); return false; }
        $is_member=(bool)$member->get_result()->fetch_row();
        $member->close();
        if (!$is_member) return false;

        $law=$conn->prepare(
            "SELECT l.originating_community_id,l.current_version_id,l.created_by
             FROM community_laws l
             JOIN community_law_versions v ON v.id=l.current_version_id AND v.law_id=l.id
             WHERE l.id=? AND l.status='approved' AND v.status='approved'
               AND (l.effective_at IS NULL OR l.effective_at<=NOW())
               AND (l.expires_at IS NULL OR l.expires_at>NOW())
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
        $proposer_id=(int)$law_row['created_by'];
        if ($requester_community_id!==$origin_id &&
            !community_law_scope_matches($conn,$version_id,$requester_community_id)) return false;

        // ارزیابان باید عضو جامعهٔ مادر باشند و در پیشنهاد یا رأی‌گیری اولیه دخالت نداشته باشند.
        $candidates_stmt=$conn->prepare(
            "SELECT DISTINCT cm.user_id
             FROM community_members cm
             WHERE cm.community_id=?
               AND cm.user_id<>? AND cm.user_id<>?
               AND NOT EXISTS (
                   SELECT 1 FROM community_law_votes lv
                   WHERE lv.law_version_id=? AND lv.voter_user_id=cm.user_id
               )
             ORDER BY cm.user_id"
        );
        if (!$candidates_stmt) return false;
        $candidates_stmt->bind_param('iiii',$origin_id,$actor_user_id,$proposer_id,$version_id);
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
            $lock=$conn->prepare('SELECT law_id,law_version_id,status FROM community_law_change_cases WHERE id=? FOR UPDATE');
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

            $new_status='under_review'; $review_result=null;
            if ((int)$summary['total']>=5) {
                if ((int)$summary['uphold_count']>=4) {
                    $new_status='awaiting_vote'; $review_result='supported';
                } elseif ((int)$summary['dismiss_count']>=4) {
                    $new_status='rejected'; $review_result='dismissed';
                } else {
                    $new_status='review_complete'; $review_result='inconclusive';
                }
                $summary_text=json_encode([
                    'reviewer_count'=>(int)$summary['total'],
                    'uphold_count'=>(int)$summary['uphold_count'],
                    'dismiss_count'=>(int)$summary['dismiss_count'],
                    'result'=>$review_result
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $update=$conn->prepare(
                    'UPDATE community_law_change_cases SET status=?,review_result=?,review_summary=?,review_completed_at=NOW() WHERE id=? AND status=\'under_review\''
                );
                if (!$update) throw new RuntimeException('case result update prepare failed');
                $update->bind_param('sssi',$new_status,$review_result,$summary_text,$case_id);
                if (!$update->execute() || $update->affected_rows!==1) throw new RuntimeException('case result update failed');
                $update->close();
                if (!community_law_record_history($conn,(int)$case['law_id'],(int)$case['law_version_id'],null,
                    $reviewer_user_id,'change_case_independent_review_completed',$summary_text)) {
                    throw new RuntimeException('review history failed');
                }
            }
            if (!$conn->commit()) throw new RuntimeException('assessment commit failed');
            return $new_status;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}
?>
