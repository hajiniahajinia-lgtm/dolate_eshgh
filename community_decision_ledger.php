<?php
declare(strict_types=1);

/**
 * ثبت snapshot یک نتیجهٔ جمعی در دفتر مستقل تصمیم‌ها.
 *
 * این تابع به جدول‌های کتابخانهٔ دانش وابستگی ندارد. برای هر موضوع فقط
 * نخستین snapshot ثبت می‌شود؛ اجرای دوباره، نتیجهٔ تاریخی را بازنویسی نمی‌کند.
 */
function community_decision_record_snapshot(mysqli $conn, array $decision): bool
{
    $communityId = (int)($decision['community_id'] ?? 0);
    $topicId = (int)($decision['source_topic_id'] ?? 0);
    $ideaId = array_key_exists('source_idea_id', $decision) && $decision['source_idea_id'] !== null
        ? (int)$decision['source_idea_id']
        : null;
    $purpose = (string)($decision['topic_purpose_snapshot'] ?? 'mixed');
    $ideaContent = array_key_exists('selected_idea_content', $decision) && $decision['selected_idea_content'] !== null
        ? (string)$decision['selected_idea_content']
        : null;
    $acceptance = (string)($decision['acceptance_status'] ?? 'no_votes');
    $enforceability = (string)($decision['enforceability_status'] ?? 'undecided');
    $yesCount = (int)($decision['yes_count'] ?? 0);
    $noCount = (int)($decision['no_count'] ?? 0);
    $yesWeight = (float)($decision['yes_weight'] ?? 0);
    $noWeight = (float)($decision['no_weight'] ?? 0);
    $yesPercent = (float)($decision['yes_percent'] ?? 0);
    $weightedYesPercent = (float)($decision['weighted_yes_percent'] ?? 0);
    $enforceYes = (int)($decision['enforce_yes_count'] ?? 0);
    $enforceNo = (int)($decision['enforce_no_count'] ?? 0);
    $decidedAt = (string)($decision['decided_at'] ?? '');

    if (
        $communityId <= 0 ||
        $topicId <= 0 ||
        ($ideaId !== null && $ideaId <= 0) ||
        !in_array($purpose, ['knowledge', 'execution', 'mixed'], true) ||
        !in_array($acceptance, ['accepted', 'not_accepted', 'no_votes'], true) ||
        !in_array($enforceability, ['enforceable', 'not_enforceable', 'undecided'], true) ||
        $yesCount < 0 || $noCount < 0 || $enforceYes < 0 || $enforceNo < 0 ||
        !is_finite($yesWeight) || !is_finite($noWeight) ||
        !is_finite($yesPercent) || !is_finite($weightedYesPercent) ||
        $yesWeight < 0 || $noWeight < 0 ||
        $yesPercent < 0 || $yesPercent > 100 ||
        $weightedYesPercent < 0 || $weightedYesPercent > 100 ||
        !preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $decidedAt)
    ) {
        return false;
    }

    $stmt = $conn->prepare(
        "INSERT INTO community_decision_records
            (community_id, source_topic_id, source_idea_id, topic_purpose_snapshot,
             selected_idea_content, acceptance_status, enforceability_status,
             yes_count, no_count, yes_weight, no_weight, yes_percent,
             weighted_yes_percent, enforce_yes_count, enforce_no_count, decided_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id"
    );
    if (!$stmt) {
        error_log('Community decision ledger prepare failed: ' . $conn->error);
        return false;
    }

    $stmt->bind_param(
        'iiissssiidddd iis',
        $communityId,
        $topicId,
        $ideaId,
        $purpose,
        $ideaContent,
        $acceptance,
        $enforceability,
        $yesCount,
        $noCount,
        $yesWeight,
        $noWeight,
        $yesPercent,
        $weightedYesPercent,
        $enforceYes,
        $enforceNo,
        $decidedAt
    );
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('Community decision ledger insert failed: ' . $stmt->error);
    }
    $stmt->close();
    return $ok;
}
