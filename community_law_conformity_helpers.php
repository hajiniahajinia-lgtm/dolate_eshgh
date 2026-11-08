<?php
declare(strict_types=1);
require_once __DIR__ . '/community_law_helpers.php';

if (!function_exists('community_law_conformity_tables_ready')) {
    function community_law_conformity_tables_ready(mysqli $conn): bool {
        $tables = [
            'community_law_conformity_cases',
            'community_law_conformity_conflicts',
            'community_law_conformity_events',
            'community_law_conformity_conflict_reviews'
        ];
        $marks = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name IN ($marks)"
        );
        if (!$stmt) return false;
        $types = str_repeat('s', count($tables));
        $params = array_merge([$types], $tables);
        $refs = [];
        foreach ($params as $i => &$value) $refs[$i] = &$value;
        call_user_func_array([$stmt, 'bind_param'], $refs);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $count = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $count === count($tables);
    }
}

if (!function_exists('community_law_conformity_is_member')) {
    function community_law_conformity_is_member(mysqli $conn, int $community_id, int $user_id): bool {
        $stmt = $conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('ii', $community_id, $user_id);
        $ok = $stmt->execute() && (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('community_law_conformity_add_conflict')) {
    function community_law_conformity_add_conflict(
        mysqli $conn, int $case_id, int $user_id, string $local_reference,
        string $parent_reference, string $description, ?string $evidence = null
    ): int|false {
        $local_reference = trim($local_reference);
        $parent_reference = trim($parent_reference);
        $description = trim($description);
        $evidence = $evidence !== null ? trim($evidence) : null;
        if ($case_id <= 0 || $user_id <= 0 || $local_reference === '' || $parent_reference === '' ||
            $description === '' || mb_strlen($local_reference) > 120 || mb_strlen($parent_reference) > 120 ||
            mb_strlen($description) > 30000 || ($evidence !== null && mb_strlen($evidence) > 30000) ||
            !$conn->begin_transaction()) return false;
        try {
            $lock = $conn->prepare(
                "SELECT community_id,parent_community_id,local_law_id,local_law_version_id,status
                 FROM community_law_conformity_cases WHERE id=? FOR UPDATE"
            );
            if (!$lock) throw new RuntimeException('conformity case lock failed');
            $lock->bind_param('i', $case_id);
            if (!$lock->execute()) throw new RuntimeException('conformity case read failed');
            $case = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$case || !in_array($case['status'], ['review_required','amendment_required'], true) ||
                !community_law_conformity_is_member($conn, (int)$case['community_id'], $user_id)) {
                throw new RuntimeException('not authorized to identify conflict');
            }
            $insert = $conn->prepare(
                "INSERT INTO community_law_conformity_conflicts
                 (case_id,local_article_reference,parent_article_reference,conflict_description,evidence,identified_by)
                 VALUES (?,?,?,?,?,?)"
            );
            if (!$insert) throw new RuntimeException('conflict insert prepare failed');
            $insert->bind_param('issssi', $case_id, $local_reference, $parent_reference, $description, $evidence, $user_id);
            if (!$insert->execute()) throw new RuntimeException('conflict insert failed');
            $conflict_id = (int)$conn->insert_id;
            $insert->close();
            $details = json_encode([
                'conflict_id'=>$conflict_id,'local_article_reference'=>$local_reference,
                'parent_article_reference'=>$parent_reference,'finding'=>'unreviewed'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $event = $conn->prepare(
                "INSERT INTO community_law_conformity_events (case_id,actor_user_id,event_type,details)
                 VALUES (?,?,'conflict_identified',?)"
            );
            if (!$event) throw new RuntimeException('event prepare failed');
            $event->bind_param('iis', $case_id, $user_id, $details);
            if (!$event->execute()) throw new RuntimeException('event insert failed');
            $event->close();
            if (!community_law_record_history(
                $conn, (int)$case['local_law_id'], (int)$case['local_law_version_id'],
                (int)$case['community_id'], $user_id, 'conformity_conflict_identified', $details
            )) throw new RuntimeException('law history insert failed');
            $conn->commit();
            return $conflict_id;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[Dolate Eshgh conformity] '.$e->getMessage());
            return false;
        }
    }
}

if (!function_exists('community_law_conformity_review_conflict')) {
    /**
     * هر تعارض باید از سوی یک عضو جامعهٔ محلی و یک عضو جامعهٔ مادر بررسی شود.
     * هیچ‌یک از طرفین به‌تنهایی نمی‌تواند تعارض را قطعی یا منتفی اعلام کند.
     */
    function community_law_conformity_review_conflict(
        mysqli $conn, int $conflict_id, int $user_id, int $reviewer_community_id,
        string $finding, string $reasoning, ?string $evidence = null
    ): bool {
        $reasoning = trim($reasoning);
        $evidence = $evidence !== null ? trim($evidence) : null;
        if ($conflict_id <= 0 || $user_id <= 0 || $reviewer_community_id <= 0 ||
            !in_array($finding, ['conflict_confirmed','no_conflict'], true) ||
            mb_strlen($reasoning) < 20 || mb_strlen($reasoning) > 20000 ||
            ($evidence !== null && mb_strlen($evidence) > 20000) || !$conn->begin_transaction()) return false;
        try {
            $lock = $conn->prepare(
                "SELECT f.id,f.case_id,f.identified_by,c.community_id,c.parent_community_id,
                        c.local_law_id,c.local_law_version_id,c.status
                 FROM community_law_conformity_conflicts f
                 JOIN community_law_conformity_cases c ON c.id=f.case_id
                 WHERE f.id=? FOR UPDATE"
            );
            if (!$lock) throw new RuntimeException('conflict lock failed');
            $lock->bind_param('i', $conflict_id);
            if (!$lock->execute()) throw new RuntimeException('conflict read failed');
            $row = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$row || !in_array($row['status'], ['review_required','amendment_required'], true) ||
                !in_array($reviewer_community_id, [(int)$row['community_id'],(int)$row['parent_community_id']], true) ||
                !community_law_conformity_is_member($conn, $reviewer_community_id, $user_id) ||
                (int)$row['identified_by'] === $user_id) {
                throw new RuntimeException('reviewer is not eligible');
            }
            $save = $conn->prepare(
                "INSERT INTO community_law_conformity_conflict_reviews
                 (conflict_id,reviewer_user_id,reviewer_community_id,finding,reasoning,evidence)
                 VALUES (?,?,?,?,?,?)"
            );
            if (!$save) throw new RuntimeException('review insert prepare failed');
            $save->bind_param('iiisss', $conflict_id, $user_id, $reviewer_community_id, $finding, $reasoning, $evidence);
            if (!$save->execute()) throw new RuntimeException('review insert failed');
            $save->close();

            $reviews = $conn->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(reviewer_community_id=?) AS local_count,
                        SUM(reviewer_community_id=?) AS parent_count,
                        SUM(finding='conflict_confirmed') AS conflict_count,
                        SUM(finding='no_conflict') AS no_conflict_count
                 FROM community_law_conformity_conflict_reviews WHERE conflict_id=?"
            );
            if (!$reviews) throw new RuntimeException('review tally prepare failed');
            $local_id = (int)$row['community_id'];
            $parent_id = (int)$row['parent_community_id'];
            $reviews->bind_param('iii', $local_id, $parent_id, $conflict_id);
            if (!$reviews->execute()) throw new RuntimeException('review tally failed');
            $tally = $reviews->get_result()->fetch_assoc();
            $reviews->close();

            $aggregate = 'unreviewed';
            if ((int)$tally['local_count'] > 0 && (int)$tally['parent_count'] > 0) {
                if ((int)$tally['conflict_count'] > 0) $aggregate = 'conflict_confirmed';
                elseif ((int)$tally['no_conflict_count'] === 2) $aggregate = 'no_conflict';
            }
            $update = $conn->prepare(
                "UPDATE community_law_conformity_conflicts SET finding=?,
                 finding_reason=?,reviewed_by=?,reviewed_at=IF(?='unreviewed',NULL,NOW())
                 WHERE id=?"
            );
            if (!$update) throw new RuntimeException('conflict update prepare failed');
            $update->bind_param('ssisi', $aggregate, $reasoning, $user_id, $aggregate, $conflict_id);
            if (!$update->execute()) throw new RuntimeException('conflict update failed');
            $update->close();

            $case_status = (string)$row['status'];
            $resolved_at_sql = null;
            if ($aggregate === 'conflict_confirmed') {
                // تأیید یک‌طرفه برای احتیاط، اصلاحیه را لازم می‌کند؛ اما به معنی توافق دوطرفه نیست.
                $case_status = 'amendment_required';
            } elseif ($aggregate === 'no_conflict') {
                // پرونده فقط وقتی بسته/حل‌شده می‌شود که هیچ تعارض دیگری باز یا تأییدشده نمانده باشد.
                $remaining = $conn->prepare(
                    "SELECT COUNT(*) AS total FROM community_law_conformity_conflicts
                     WHERE case_id=? AND id<>? AND finding<>'no_conflict'"
                );
                if (!$remaining) throw new RuntimeException('remaining conflict check prepare failed');
                $case_id_for_check = (int)$row['case_id'];
                $remaining->bind_param('ii', $case_id_for_check, $conflict_id);
                if (!$remaining->execute()) throw new RuntimeException('remaining conflict check failed');
                $remaining_count = (int)$remaining->get_result()->fetch_assoc()['total'];
                $remaining->close();
                if ($remaining_count === 0) {
                    $case_status = 'resolved';
                    $resolved_at_sql = date('Y-m-d H:i:s');
                }
            }
            $case_update = $conn->prepare(
                'UPDATE community_law_conformity_cases SET status=?, resolved_at=? WHERE id=?'
            );
            if (!$case_update) throw new RuntimeException('case update prepare failed');
            $case_update->bind_param('ssi', $case_status, $resolved_at_sql, $row['case_id']);
            if (!$case_update->execute()) throw new RuntimeException('case update failed');
            $case_update->close();

            $details = json_encode([
                'conflict_id'=>$conflict_id,'reviewer_community_id'=>$reviewer_community_id,
                'finding'=>$finding,'aggregate_finding'=>$aggregate,'case_status'=>$case_status
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $event = $conn->prepare(
                "INSERT INTO community_law_conformity_events (case_id,actor_user_id,event_type,details)
                 VALUES (?,?,'conflict_reviewed',?)"
            );
            if (!$event) throw new RuntimeException('event insert prepare failed');
            $case_id = (int)$row['case_id'];
            $event->bind_param('iis', $case_id, $user_id, $details);
            if (!$event->execute()) throw new RuntimeException('event insert failed');
            $event->close();
            if (!community_law_record_history(
                $conn, (int)$row['local_law_id'], (int)$row['local_law_version_id'],
                (int)$row['community_id'], $user_id, 'conformity_conflict_reviewed', $details
            )) throw new RuntimeException('law history insert failed');
            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[Dolate Eshgh conformity] '.$e->getMessage());
            return false;
        }
    }
}
