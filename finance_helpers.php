<?php
/**
 * Finalize expired community finance votes and reserve funds internally.
 * Approval is at least two-thirds of approve/reject votes; abstentions are excluded.
 * This is bookkeeping only and never transfers real money.
 */
function finance_available_balance(mysqli $conn, int $community_id): float {
    $q = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount WHEN transaction_type='expense' THEN -amount ELSE amount END),0) AS balance FROM community_finance_transactions WHERE community_id=?");
    $q->bind_param('i', $community_id);
    $q->execute();
    $balance = (float)($q->get_result()->fetch_assoc()['balance'] ?? 0);
    $q->close();
    $q = $conn->prepare("SELECT COALESCE(SUM(GREATEST(COALESCE(approved_amount,0)-COALESCE(actual_spent,0),0)),0) AS reserved FROM community_budget_requests WHERE community_id=? AND status='allocated'");
    $q->bind_param('i', $community_id);
    $q->execute();
    $reserved = (float)($q->get_result()->fetch_assoc()['reserved'] ?? 0);
    $q->close();
    return $balance - $reserved;
}
function finance_finalize_expired_requests(mysqli $conn, int $community_id): void {
    $community_id = (int)$community_id;
    $seed = $conn->prepare("UPDATE community_budget_requests SET vote_started_at=COALESCE(vote_started_at,NOW()), vote_deadline=COALESCE(vote_deadline,DATE_ADD(NOW(), INTERVAL 3 DAY)) WHERE community_id=? AND status='voting' AND (vote_started_at IS NULL OR vote_deadline IS NULL)");
    $seed->bind_param('i', $community_id); $seed->execute(); $seed->close();
    $ids = [];
    $q = $conn->prepare("SELECT id FROM community_budget_requests WHERE community_id=? AND status='voting' AND vote_deadline<=NOW() ORDER BY vote_deadline,created_at,id LIMIT 100");
    $q->bind_param('i', $community_id); $q->execute(); $res = $q->get_result();
    while ($row = $res->fetch_assoc()) $ids[] = (int)$row['id'];
    $q->close();
    foreach ($ids as $request_id) {
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT id FROM communities WHERE id=? FOR UPDATE");
            $lock->bind_param('i', $community_id); $lock->execute(); $lock->get_result()->fetch_assoc(); $lock->close();
            $rq = $conn->prepare("SELECT id,requested_amount FROM community_budget_requests WHERE id=? AND community_id=? AND status='voting' AND vote_deadline<=NOW() FOR UPDATE");
            $rq->bind_param('ii', $request_id, $community_id); $rq->execute(); $request = $rq->get_result()->fetch_assoc(); $rq->close();
            if (!$request) { $conn->commit(); continue; }
            $vq = $conn->prepare("SELECT SUM(vote_choice='approve') AS approvals, SUM(vote_choice='reject') AS rejections FROM community_budget_request_votes WHERE request_id=? AND community_id=?");
            $vq->bind_param('ii', $request_id, $community_id); $vq->execute(); $votes = $vq->get_result()->fetch_assoc() ?: []; $vq->close();
            $approvals = (int)($votes['approvals'] ?? 0); $rejections = (int)($votes['rejections'] ?? 0); $valid_votes = $approvals + $rejections;
            if ($valid_votes < 1 || ($approvals * 3) < ($valid_votes * 2)) {
                $note = 'رأی‌گیری پس از سه روز پایان یافت؛ حدنصاب دوسوم آرای موافق و مخالف به دست نیامد.';
                $upd = $conn->prepare("UPDATE community_budget_requests SET status='rejected',decision_at=NOW(),decision_note=? WHERE id=? AND community_id=? AND status='voting'");
                $upd->bind_param('sii', $note, $request_id, $community_id); $upd->execute(); $upd->close();
            } else {
                $amount = (float)$request['requested_amount']; $available = finance_available_balance($conn, $community_id);
                if ($available >= $amount) {
                    $note = 'تصویب شد؛ مبلغ به‌صورت خودکار در بانک داخلی رزرو شد. پرداخت واقعی جداگانه ثبت می‌شود.';
                    $upd = $conn->prepare("UPDATE community_budget_requests SET status='allocated',approved_amount=requested_amount,decision_at=NOW(),decision_note=? WHERE id=? AND community_id=? AND status='voting'");
                } else {
                    $note = 'حدنصاب دوسوم به دست آمد، اما موجودی آزاد کافی نبود؛ درخواست تصویب‌شده و در انتظار تأمین منابع است.';
                    $upd = $conn->prepare("UPDATE community_budget_requests SET status='approved',approved_amount=requested_amount,decision_at=NOW(),decision_note=? WHERE id=? AND community_id=? AND status='voting'");
                }
                $upd->bind_param('sii', $note, $request_id, $community_id); $upd->execute(); $upd->close();
            }
            $conn->commit();
        } catch (Throwable $err) {
            $conn->rollback(); error_log('Finance request finalization failed: ' . $err->getMessage());
        }
    }
    $pending = [];
    $q = $conn->prepare("SELECT id FROM community_budget_requests WHERE community_id=? AND status='approved' AND decision_note LIKE 'حدنصاب دوسوم به دست آمد، اما موجودی آزاد کافی نبود%' ORDER BY created_at,id LIMIT 100");
    $q->bind_param('i', $community_id); $q->execute(); $res = $q->get_result();
    while ($row = $res->fetch_assoc()) $pending[] = (int)$row['id'];
    $q->close();
    foreach ($pending as $request_id) {
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT id FROM communities WHERE id=? FOR UPDATE");
            $lock->bind_param('i', $community_id); $lock->execute(); $lock->get_result()->fetch_assoc(); $lock->close();
            $rq = $conn->prepare("SELECT id,requested_amount FROM community_budget_requests WHERE id=? AND community_id=? AND status='approved' AND decision_note LIKE 'حدنصاب دوسوم به دست آمد، اما موجودی آزاد کافی نبود%' FOR UPDATE");
            $rq->bind_param('ii', $request_id, $community_id); $rq->execute(); $request = $rq->get_result()->fetch_assoc(); $rq->close();
            if (!$request) { $conn->commit(); continue; }
            if (finance_available_balance($conn, $community_id) >= (float)$request['requested_amount']) {
                $note = 'مبلغ پس از تأمین موجودی آزاد به‌صورت خودکار در بانک داخلی رزرو شد. پرداخت واقعی جداگانه ثبت می‌شود.';
                $upd = $conn->prepare("UPDATE community_budget_requests SET status='allocated',approved_amount=requested_amount,decision_note=? WHERE id=? AND community_id=? AND status='approved'");
                $upd->bind_param('sii', $note, $request_id, $community_id); $upd->execute(); $upd->close();
            }
            $conn->commit();
        } catch (Throwable $err) {
            $conn->rollback(); error_log('Finance pending allocation failed: ' . $err->getMessage());
        }
    }
}
