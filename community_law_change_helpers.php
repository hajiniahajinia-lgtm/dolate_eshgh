<?php
/**
 * توابع امن مرحلهٔ آغاز گردش‌کار تغییر وضعیت قوانین.
 * درخواست یا ادعای اضطراری به‌تنهایی قانون را تعلیق/لغو نمی‌کند.
 */
require_once __DIR__ . '/community_law_helpers.php';

if (!function_exists('community_law_open_change_case')) {
    /**
     * درخواست را فقط برای قانون جاری و لازم‌الاجرا، از سوی عضو جامعهٔ مبدأ یا جامعهٔ مشمول ثبت می‌کند.
     * فراخواننده نباید تراکنش باز داشته باشد؛ شناسهٔ پرونده یا false برمی‌گرداند.
     */
    function community_law_open_change_case(
        mysqli $conn,
        int $law_id,
        int $actor_user_id,
        int $requester_community_id,
        string $request_type,
        string $reason,
        ?string $evidence = null,
        bool $emergency_requested = false,
        ?string $requested_effective_at = null
    ): int|false {
        $allowed_types = ['suspend', 'repeal', 'review_expiry'];
        $reason = trim($reason);
        if (
            $law_id <= 0 || $actor_user_id <= 0 || $requester_community_id <= 0 ||
            !in_array($request_type, $allowed_types, true) ||
            $reason === '' || mb_strlen($reason) > 30000 ||
            ($evidence !== null && mb_strlen($evidence) > 30000) ||
            ($emergency_requested && $request_type !== 'suspend')
        ) return false;

        $member = $conn->prepare(
            'SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1'
        );
        if (!$member) return false;
        $member->bind_param('ii', $requester_community_id, $actor_user_id);
        if (!$member->execute()) {
            $member->close();
            return false;
        }
        $is_member = (bool)$member->get_result()->fetch_row();
        $member->close();
        if (!$is_member) return false;

        $law = $conn->prepare(
            "SELECT l.originating_community_id, l.current_version_id
             FROM community_laws l
             JOIN community_law_versions v ON v.id=l.current_version_id AND v.law_id=l.id
             WHERE l.id=? AND l.status='approved' AND v.status='approved'
               AND (l.effective_at IS NULL OR l.effective_at<=NOW())
               AND (l.expires_at IS NULL OR l.expires_at>NOW())
               AND l.repealed_at IS NULL
             LIMIT 1"
        );
        if (!$law) return false;
        $law->bind_param('i', $law_id);
        if (!$law->execute()) {
            $law->close();
            return false;
        }
        $law_row = $law->get_result()->fetch_assoc();
        $law->close();
        if (!$law_row) return false;

        $version_id = (int)$law_row['current_version_id'];
        $origin_id = (int)$law_row['originating_community_id'];
        if (
            $requester_community_id !== $origin_id &&
            !community_law_scope_matches($conn, $version_id, $requester_community_id)
        ) return false;

        if ($requested_effective_at !== null) {
            $date = DateTime::createFromFormat('Y-m-d H:i:s', $requested_effective_at);
            if (!$date || $date->format('Y-m-d H:i:s') !== $requested_effective_at) return false;
        }

        if (!$conn->begin_transaction()) return false;
        try {
            $insert = $conn->prepare(
                "INSERT INTO community_law_change_cases
                    (law_id, law_version_id, request_type, requested_by, requester_community_id,
                     reason, evidence, emergency_requested, requested_effective_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$insert) throw new RuntimeException('change case prepare failed');
            $emergency = $emergency_requested ? 1 : 0;
            $insert->bind_param(
                'iisiissis',
                $law_id, $version_id, $request_type, $actor_user_id, $requester_community_id,
                $reason, $evidence, $emergency, $requested_effective_at
            );
            if (!$insert->execute()) {
                $insert->close();
                throw new RuntimeException('change case insert failed');
            }
            $case_id = (int)$conn->insert_id;
            $insert->close();

            $details = json_encode([
                'case_id' => $case_id,
                'request_type' => $request_type,
                'requester_community_id' => $requester_community_id,
                'emergency_requested' => $emergency_requested,
                'status_effect_changed' => false
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!community_law_record_history(
                $conn, $law_id, $version_id, $requester_community_id,
                $actor_user_id, 'change_case_submitted', $details
            )) throw new RuntimeException('change case history failed');

            if (!$conn->commit()) throw new RuntimeException('change case commit failed');
            return $case_id;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}
?>
