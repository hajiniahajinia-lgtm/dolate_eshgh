<?php
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/community_law_helpers.php';
require_once __DIR__ . '/community_law_amendment_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$community_id = isset($_GET['community_id']) ? (int)$_GET['community_id'] : 0;
if ($community_id <= 0) {
    http_response_code(400);
    exit('شناسه جامعه معتبر نیست.');
}

$community_stmt = $conn->prepare('SELECT id, name, parent_community_id FROM communities WHERE id=? LIMIT 1');
$community_stmt->bind_param('i', $community_id);
$community_stmt->execute();
$community = $community_stmt->get_result()->fetch_assoc();
$community_stmt->close();

if (!$community) {
    http_response_code(404);
    exit('جامعه پیدا نشد.');
}

$member_stmt = $conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
$member_stmt->bind_param('ii', $community_id, $user_id);
$member_stmt->execute();
$is_member = (bool)$member_stmt->get_result()->fetch_row();
$member_stmt->close();

if (empty($_SESSION['community_law_csrf'])) {
    $_SESSION['community_law_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['community_law_csrf'];

function community_laws_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function community_laws_redirect(int $community_id, string $status): void {
    header('Location: community_laws.php?community_id=' . $community_id . '&status=' . rawurlencode($status));
    exit;
}

$schema_ready = community_law_tables_ready($conn);
$amendment_schema_ready = community_law_amendment_tables_ready($conn);
$outcome_reports_ready=false;
if ($schema_ready) {
    $outcome_table_check=$conn->query(
        "SELECT COUNT(*) AS total FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name IN ('community_law_outcome_reports','community_law_outcome_reviews','community_law_outcome_followups','community_law_outcome_reassessments','community_law_outcome_reassessment_reviews','community_law_outcome_corrective_actions','community_law_outcome_corrective_updates')"
    );
    $outcome_reports_ready=$outcome_table_check &&
        (int)$outcome_table_check->fetch_assoc()['total']===7;
    if ($outcome_table_check) $outcome_table_check->free();
}

// فهرست تصمیم‌های پذیرفته‌شده فقط از دفتر مستقل تصمیم‌ها خوانده می‌شود.
$decision_ledger_ready = false;
$source_decisions = [];
if ($schema_ready) {
    $ledger_table_check = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name='community_decision_records'"
    );
    $decision_ledger_ready = $ledger_table_check &&
        (int)$ledger_table_check->fetch_assoc()['total'] === 1;
    if ($ledger_table_check) $ledger_table_check->free();

    if ($decision_ledger_ready) {
        $source_decision_stmt = $conn->prepare(
            "SELECT d.id, d.source_topic_id, t.title, d.decided_at
             FROM community_decision_records d
             JOIN topics t ON t.id=d.source_topic_id
             WHERE d.community_id=? AND d.record_status='recorded'
               AND d.acceptance_status='accepted'
             ORDER BY d.decided_at DESC, d.id DESC
             LIMIT 100"
        );
        if ($source_decision_stmt) {
            $source_decision_stmt->bind_param('i', $community_id);
            if ($source_decision_stmt->execute()) {
                $source_decision_result = $source_decision_stmt->get_result();
                while ($source_decision_row = $source_decision_result->fetch_assoc()) {
                    $source_decisions[] = $source_decision_row;
                }
            }
            $source_decision_stmt->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['community_law_csrf']) ||
        !isset($_POST['csrf_token']) ||
        !is_string($_POST['csrf_token']) ||
        !hash_equals($_SESSION['community_law_csrf'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.');
    }

    if (!$schema_ready) community_laws_redirect($community_id, 'schema');

    if (isset($_POST['create_law_draft'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $title = trim((string)($_POST['title'] ?? ''));
        $law_text = trim((string)($_POST['law_text'] ?? ''));
        $implementation_plan = trim((string)($_POST['implementation_plan'] ?? ''));
        $source_topic_id = (int)($_POST['source_topic_id'] ?? 0);
        $source_idea_id = (int)($_POST['source_idea_id'] ?? 0);
        $source_decision_id = (int)($_POST['source_decision_id'] ?? 0);
        $source_topic_id = $source_topic_id > 0 ? $source_topic_id : null;
        $source_idea_id = $source_idea_id > 0 ? $source_idea_id : null;
        $source_decision_id = $source_decision_id > 0 ? $source_decision_id : null;
        // قانون مصوب هر جامعه، در صورت احراز صلاحیت، برای همه زیرشاخه‌های آن نیز لازم‌الرعایه است.
        $include_descendants = 1;

        if ($title === '' || mb_strlen($title) > 200 || $law_text === '' || mb_strlen($law_text) > 50000 || mb_strlen($implementation_plan) > 30000) {
            community_laws_redirect($community_id, 'invalid');
        }

        // اگر پیش‌نویس از یک تصمیم جمعی منشأ گرفته باشد، پیوند باید به تصمیم ثبت‌شدهٔ همین جامعه باشد.
        if ($source_decision_id !== null) {
            $decision_source_stmt = $conn->prepare(
                "SELECT source_topic_id, source_idea_id
                 FROM community_decision_records
                 WHERE id=? AND community_id=? AND record_status='recorded'
                   AND acceptance_status='accepted'
                 LIMIT 1"
            );
            if (!$decision_source_stmt) community_laws_redirect($community_id, 'invalid_source');
            $decision_source_stmt->bind_param('ii', $source_decision_id, $community_id);
            if (!$decision_source_stmt->execute()) community_laws_redirect($community_id, 'invalid_source');
            $decision_source = $decision_source_stmt->get_result()->fetch_assoc();
            $decision_source_stmt->close();
            if (
                !$decision_source ||
                ($source_topic_id !== null && $source_topic_id !== (int)$decision_source['source_topic_id']) ||
                ($source_idea_id !== null && (int)($decision_source['source_idea_id'] ?? 0) !== $source_idea_id)
            ) {
                community_laws_redirect($community_id, 'invalid_source');
            }
            $source_topic_id = (int)$decision_source['source_topic_id'];
            $source_idea_id = $decision_source['source_idea_id'] !== null
                ? (int)$decision_source['source_idea_id']
                : null;
        }

        if ($source_idea_id !== null) {
            $source_stmt = $conn->prepare(
                'SELECT t.id AS topic_id
                 FROM ideas i
                 JOIN topics t ON t.id=i.topic_id
                 WHERE i.id=? AND t.community_id=? LIMIT 1'
            );
            $source_stmt->bind_param('ii', $source_idea_id, $community_id);
            $source_stmt->execute();
            $source_row = $source_stmt->get_result()->fetch_assoc();
            $source_stmt->close();
            if (!$source_row || ($source_topic_id !== null && $source_topic_id !== (int)$source_row['topic_id'])) {
                community_laws_redirect($community_id, 'invalid_source');
            }
            $source_topic_id = (int)$source_row['topic_id'];
        } elseif ($source_topic_id !== null) {
            $source_stmt = $conn->prepare('SELECT id FROM topics WHERE id=? AND community_id=? LIMIT 1');
            $source_stmt->bind_param('ii', $source_topic_id, $community_id);
            $source_stmt->execute();
            $source_exists = (bool)$source_stmt->get_result()->fetch_row();
            $source_stmt->close();
            if (!$source_exists) community_laws_redirect($community_id, 'invalid_source');
        }

        $law_code = 'LAW-' . strtoupper(bin2hex(random_bytes(8)));
        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');

        try {
            $law_insert = $conn->prepare(
                "INSERT INTO community_laws (law_code, originating_community_id, created_by, status)
                 VALUES (?, ?, ?, 'draft')"
            );
            if (!$law_insert) throw new Exception('law insert prepare failed');
            $law_insert->bind_param('sii', $law_code, $community_id, $user_id);
            if (!$law_insert->execute()) throw new Exception('law insert failed');
            $law_id = (int)$conn->insert_id;
            $law_insert->close();

            $version_insert = $conn->prepare(
                "INSERT INTO community_law_versions
                    (law_id, version_number, title, law_text, implementation_plan,
                     source_topic_id, source_idea_id, source_decision_id, proposed_by, status)
                 VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, 'draft')"
            );
            if (!$version_insert) throw new Exception('version insert prepare failed');
            $version_insert->bind_param(
                'isssiiii',
                $law_id,
                $title,
                $law_text,
                $implementation_plan,
                $source_topic_id,
                $source_idea_id,
                $source_decision_id,
                $user_id
            );
            if (!$version_insert->execute()) throw new Exception('version insert failed');
            $version_id = (int)$conn->insert_id;
            $version_insert->close();

            $update_law = $conn->prepare('UPDATE community_laws SET current_version_id=? WHERE id=?');
            if (!$update_law) throw new Exception('law update prepare failed');
            $update_law->bind_param('ii', $version_id, $law_id);
            if (!$update_law->execute()) throw new Exception('law update failed');
            $update_law->close();

            $scope_insert = $conn->prepare(
                'INSERT INTO community_law_scopes (law_version_id, scope_community_id, include_descendants)
                 VALUES (?, ?, ?)'
            );
            if (!$scope_insert) throw new Exception('scope insert prepare failed');
            $scope_insert->bind_param('iii', $version_id, $community_id, $include_descendants);
            if (!$scope_insert->execute()) throw new Exception('scope insert failed');
            $scope_insert->close();

            if (!community_law_record_history(
                $conn,
                $law_id,
                $version_id,
                $community_id,
                $user_id,
                'draft_created',
                'پیش‌نویس قانون ایجاد شد.'
            )) {
                throw new Exception('history insert failed');
            }

            if (!$conn->commit()) throw new Exception('transaction commit failed');
            community_laws_redirect($community_id, 'draft_saved');
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[Dolate Eshgh law draft] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['submit_law_for_review'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $law_id = (int)($_POST['law_id'] ?? 0);
        $version_id = (int)($_POST['version_id'] ?? 0);
        if ($law_id <= 0 || $version_id <= 0) {
            community_laws_redirect($community_id, 'invalid_submission');
        }

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            // همان ترتیب قفل مسیر تغییر والد: ابتدا کل نقشه جوامع، سپس رکورد قانون.
            // این قفل snapshot صلاحیت و قوانین مرجع را با تغییر هم‌زمان سلسله‌مراتب هماهنگ می‌کند.
            $hierarchy_lock = $conn->query(
                "SELECT id, parent_community_id FROM communities ORDER BY id FOR UPDATE"
            );
            if (!$hierarchy_lock) throw new Exception('lock hierarchy for law review failed');
            $hierarchy_lock->free();

            $lock = $conn->prepare(
                "SELECT l.id, l.status, l.current_version_id, v.id AS version_id,
                        v.status AS version_status, v.proposed_by
                 FROM community_laws l
                 JOIN community_law_versions v ON v.law_id=l.id
                 WHERE l.id=? AND v.id=? AND l.originating_community_id=?
                 FOR UPDATE"
            );
            if (!$lock) throw new Exception('law review lock prepare failed');
            $lock->bind_param('iii', $law_id, $version_id, $community_id);
            if (!$lock->execute()) throw new Exception('law review lock failed');
            $record = $lock->get_result()->fetch_assoc();
            $lock->close();

            if (
                !$record ||
                $record['status'] !== 'draft' ||
                $record['version_status'] !== 'draft' ||
                (int)$record['current_version_id'] !== $version_id ||
                (int)$record['proposed_by'] !== $user_id
            ) {
                throw new RuntimeException('invalid_draft_state');
            }

            $update = $conn->prepare(
                "UPDATE community_laws SET status='proposed' WHERE id=? AND status='draft'"
            );
            if (!$update) throw new Exception('law proposal update prepare failed');
            $update->bind_param('i', $law_id);
            if (!$update->execute() || $update->affected_rows !== 1) {
                throw new Exception('law proposal update failed');
            }
            $update->close();

            $update_version = $conn->prepare(
                "UPDATE community_law_versions SET status='proposed' WHERE id=? AND status='draft'"
            );
            if (!$update_version) throw new Exception('law version proposal prepare failed');
            $update_version->bind_param('i', $version_id);
            if (!$update_version->execute() || $update_version->affected_rows !== 1) {
                throw new Exception('law version proposal update failed');
            }
            $update_version->close();

            $review_scope_ids = community_law_scope_community_ids($conn, $version_id);
            if (!$review_scope_ids) throw new RuntimeException('invalid_law_scope');

            $authority_parent_map = community_law_load_parent_map($conn);
            if (
                $authority_parent_map === null ||
                !isset($authority_parent_map[$community_id]) ||
                !community_law_parent_map_is_valid($authority_parent_map)
            ) {
                throw new RuntimeException('invalid_law_scope');
            }
            $has_unrelated_scope = false;
            $has_descendant_scope = false;
            foreach ($review_scope_ids as $scope_target_id) {
                if (
                    $scope_target_id !== $community_id &&
                    community_law_is_descendant_or_same($authority_parent_map, $community_id, $scope_target_id)
                ) {
                    // قانون جامعه زیرمجموعه نباید دامنه الزام‌آور را به جامعه مادر گسترش دهد.
                    throw new RuntimeException('law_scope_exceeds_authority');
                }
                if (community_law_is_descendant_or_same(
                    $authority_parent_map, $scope_target_id, $community_id
                )) {
                    if ($scope_target_id !== $community_id) $has_descendant_scope = true;
                } else {
                    $has_unrelated_scope = true;
                }
            }
            $derived_jurisdiction = $has_unrelated_scope
                ? 'intercommunity'
                : ($has_descendant_scope ? 'parent_shared' : 'local');

            $review_case = $conn->prepare(
                "INSERT INTO community_law_review_cases
                    (law_version_id, originating_community_id, status, reviewer_quorum, opened_by)
                 VALUES (?, ?, 'pending', 3, ?)"
            );
            if (!$review_case) throw new Exception('legal review case prepare failed');
            $review_case->bind_param('iii', $version_id, $community_id, $user_id);
            if (!$review_case->execute()) throw new Exception('legal review case insert failed');
            $review_case->close();

            $authority_case = $conn->prepare(
                "INSERT INTO community_law_authority_cases
                    (law_version_id, derived_jurisdiction_level, status, reviewer_quorum, opened_by)
                 VALUES (?, ?, 'pending', 3, ?)"
            );
            if (!$authority_case) throw new Exception('authority case prepare failed');
            $authority_case->bind_param('isi', $version_id, $derived_jurisdiction, $user_id);
            if (!$authority_case->execute()) throw new Exception('authority case insert failed');
            $authority_case->close();

            $authority_reference_insert = $conn->prepare(
                "INSERT IGNORE INTO community_law_authority_references
                    (law_version_id, reference_law_id, reference_law_version_id,
                     reference_origin_community_id, relation_type)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$authority_reference_insert) throw new Exception('authority reference prepare failed');

            $active_laws_result = $conn->query(
                "SELECT l.id AS law_id, l.originating_community_id,
                        l.current_version_id AS reference_version_id
                 FROM community_laws l
                 JOIN community_law_versions v ON v.id=l.current_version_id
                 WHERE l.status='approved' AND v.status='approved'
                   AND (l.effective_at IS NULL OR l.effective_at<=NOW())
                   AND (l.expires_at IS NULL OR l.expires_at>NOW())
                   AND l.repealed_at IS NULL"
            );
            if (!$active_laws_result) throw new Exception('active laws lookup failed');
            while ($active_law = $active_laws_result->fetch_assoc()) {
                $reference_origin = (int)$active_law['originating_community_id'];
                $reference_version = (int)$active_law['reference_version_id'];
                $applies_to_scope = false;
                foreach ($review_scope_ids as $scope_target_id) {
                    $reference_applies = community_law_scope_match_checked(
                        $conn, $reference_version, $scope_target_id
                    );
                    if ($reference_applies === null) {
                        $active_laws_result->free();
                        throw new Exception('authority reference scope check failed');
                    }
                    if ($reference_applies) {
                        $applies_to_scope = true;
                        break;
                    }
                }
                if (!$applies_to_scope) continue;

                $is_higher_for_scope = false;
                foreach ($review_scope_ids as $scope_target_id) {
                    if (
                        $scope_target_id !== $reference_origin &&
                        community_law_is_descendant_or_same(
                            $authority_parent_map, $scope_target_id, $reference_origin
                        )
                    ) {
                        $is_higher_for_scope = true;
                        break;
                    }
                }
                if ($is_higher_for_scope) {
                    $relation_type = 'higher';
                } elseif ($reference_origin === $community_id) {
                    $relation_type = 'same_level';
                } elseif (community_law_is_descendant_or_same(
                    $authority_parent_map, $reference_origin, $community_id
                )) {
                    $relation_type = 'lower_level';
                } else {
                    $relation_type = 'peer';
                }

                $reference_law_id = (int)$active_law['law_id'];
                $authority_reference_insert->bind_param(
                    'iiiis', $version_id, $reference_law_id, $reference_version,
                    $reference_origin, $relation_type
                );
                if (!$authority_reference_insert->execute()) {
                    $active_laws_result->free();
                    throw new Exception('authority reference insert failed');
                }
            }
            $active_laws_result->free();
            $authority_reference_insert->close();

            $review_elector_insert = $conn->prepare(
                "INSERT IGNORE INTO community_law_review_electorate (law_version_id, user_id)
                 SELECT ?, user_id FROM community_members
                 WHERE community_id=? AND user_id<>?"
            );
            if (!$review_elector_insert) throw new Exception('legal review electorate prepare failed');
            foreach ($review_scope_ids as $review_community_id) {
                $review_elector_insert->bind_param('iii', $version_id, $review_community_id, $user_id);
                if (!$review_elector_insert->execute()) throw new Exception('legal review electorate snapshot failed');
            }
            $review_elector_insert->close();

            $reviewer_capacity_stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM community_law_review_electorate WHERE law_version_id=?"
            );
            if (!$reviewer_capacity_stmt) throw new Exception('reviewer capacity prepare failed');
            $reviewer_capacity_stmt->bind_param('i', $version_id);
            if (!$reviewer_capacity_stmt->execute()) throw new Exception('reviewer capacity lookup failed');
            $reviewer_capacity = (int)$reviewer_capacity_stmt->get_result()->fetch_assoc()['total'];
            $reviewer_capacity_stmt->close();
            if ($reviewer_capacity < 3) throw new RuntimeException('insufficient_reviewers');

            if (!community_law_record_history(
                $conn, $law_id, $version_id, $community_id, $user_id,
                'legal_screening_opened',
                'غربالگری اولیه حقوقی و پیامدها آغاز شد؛ رأی‌گیری تا تکمیل بررسی مستقل مجاز نیست.'
            )) throw new Exception('legal review case history failed');

            if (!community_law_record_history(
                $conn, $law_id, $version_id, $community_id, $user_id,
                'submitted_for_review',
                'پیش‌نویس برای بررسی صلاحیت، دامنه شمول و پیامدها ارسال شد.'
            )) {
                throw new Exception('law proposal history failed');
            }

            if (!$conn->commit()) throw new Exception('law proposal commit failed');
            community_laws_redirect($community_id, 'submitted_for_review');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException && $e->getMessage() === 'invalid_draft_state') {
                community_laws_redirect($community_id, 'invalid_submission');
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'law_scope_exceeds_authority') {
                community_laws_redirect($community_id, 'law_scope_exceeds_authority');
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'invalid_law_scope') {
                community_laws_redirect($community_id, 'invalid_law_scope');
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'insufficient_reviewers') {
                community_laws_redirect($community_id, 'insufficient_reviewers');
            }
            error_log('[Dolate Eshgh law submission] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['submit_law_voting_rules'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $law_id = (int)($_POST['law_id'] ?? 0);
        $version_id = (int)($_POST['version_id'] ?? 0);
        $individual_quorum = $_POST['individual_quorum_percent'] ?? '';
        $individual_approval = $_POST['individual_approval_percent'] ?? '';
        $require_community = (string)($_POST['require_community_approval'] ?? '');
        $community_quorum = $_POST['community_quorum_percent'] ?? '';
        $community_approval = $_POST['community_approval_percent'] ?? '';
        $aggregate_quorum = $_POST['community_aggregate_quorum_percent'] ?? '';
        $aggregate_approval = $_POST['community_aggregate_approval_percent'] ?? '';
        $rationale = trim((string)($_POST['rules_rationale'] ?? ''));
        $individual_basis = (string)($_POST['individual_vote_basis'] ?? '');

        $percent_valid = static function ($value, float $minimum): bool {
            return $value !== '' && is_numeric($value)
                && (float)$value >= $minimum && (float)$value <= 100;
        };
        if (
            $law_id <= 0 || $version_id <= 0 ||
            !$percent_valid($individual_quorum, 1) ||
            !$percent_valid($individual_approval, 50.01) ||
            !in_array($require_community, ['0','1'], true) ||
            !in_array($individual_basis, ['count','weighted'], true) ||
            mb_strlen($rationale) < 20 || mb_strlen($rationale) > 20000 ||
            ($require_community === '1' && (
                !$percent_valid($community_quorum, 1) ||
                !$percent_valid($community_approval, 50.01) ||
                !$percent_valid($aggregate_quorum, 1) ||
                !$percent_valid($aggregate_approval, 50.01)
            ))
        ) community_laws_redirect($community_id, 'invalid_voting_rules');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $lock = $conn->prepare(
                "SELECT l.status AS law_status, l.current_version_id, l.originating_community_id,
                        v.status AS version_status, v.proposed_by, ac.status AS authority_status,
                        ac.derived_jurisdiction_level
                 FROM community_laws l
                 JOIN community_law_versions v ON v.law_id=l.id
                 JOIN community_law_authority_cases ac ON ac.law_version_id=v.id
                 WHERE l.id=? AND v.id=? FOR UPDATE"
            );
            if (!$lock) throw new Exception('rule proposal lock prepare failed');
            $lock->bind_param('ii', $law_id, $version_id);
            if (!$lock->execute()) throw new Exception('rule proposal lock failed');
            $law = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (
                !$law || (int)$law['originating_community_id'] !== $community_id ||
                $law['law_status'] !== 'proposed' || $law['version_status'] !== 'proposed' ||
                (int)$law['current_version_id'] !== $version_id ||
                $law['authority_status'] !== 'confirmed' ||
                (int)$law['proposed_by'] === $user_id
            ) throw new RuntimeException('voting_rules_not_configurable');

            $existing = $conn->prepare(
                "SELECT status FROM community_law_voting_rules
                 WHERE law_version_id=? LIMIT 1 FOR UPDATE"
            );
            if (!$existing) throw new Exception('existing rule lookup prepare failed');
            $existing->bind_param('i', $version_id);
            if (!$existing->execute()) throw new Exception('existing rule lookup failed');
            $existing_row = $existing->get_result()->fetch_assoc();
            $existing->close();
            if ($existing_row && $existing_row['status'] === 'approved') {
                throw new RuntimeException('voting_rules_already_approved');
            }

            $pending = $conn->prepare(
                "SELECT id FROM community_law_voting_rule_proposals
                 WHERE law_version_id=? AND status='pending_review' LIMIT 1 FOR UPDATE"
            );
            if (!$pending) throw new Exception('pending rule lookup prepare failed');
            $pending->bind_param('i', $version_id);
            if (!$pending->execute()) throw new Exception('pending rule lookup failed');
            $has_pending = (bool)$pending->get_result()->fetch_row();
            $pending->close();
            if ($has_pending) throw new RuntimeException('voting_rules_review_pending');

            $scope_ids = community_law_scope_community_ids($conn, $version_id);
            if (!$scope_ids) throw new RuntimeException('invalid_law_scope');

            $number_stmt = $conn->prepare(
                "SELECT COALESCE(MAX(proposal_number),0)+1 AS next_number
                 FROM community_law_voting_rule_proposals WHERE law_version_id=?"
            );
            if (!$number_stmt) throw new Exception('rule proposal number prepare failed');
            $number_stmt->bind_param('i', $version_id);
            if (!$number_stmt->execute()) throw new Exception('rule proposal number failed');
            $proposal_number = (int)$number_stmt->get_result()->fetch_assoc()['next_number'];
            $number_stmt->close();

            $community_basis = $require_community === '1' ? 'one_community_one_vote' : null;
            $community_quorum_value = $require_community === '1' ? (float)$community_quorum : null;
            $community_approval_value = $require_community === '1' ? (float)$community_approval : null;
            $aggregate_quorum_value = $require_community === '1' ? (float)$aggregate_quorum : null;
            $aggregate_approval_value = $require_community === '1' ? (float)$aggregate_approval : null;
            $individual_quorum_value = (float)$individual_quorum;
            $individual_approval_value = (float)$individual_approval;
            $require_community_value = (int)$require_community;
            $jurisdiction = $law['derived_jurisdiction_level'];

            $insert = $conn->prepare(
                "INSERT INTO community_law_voting_rule_proposals
                    (law_version_id, proposal_number, jurisdiction_level,
                     individual_quorum_percent, individual_approval_percent,
                     community_quorum_percent, community_approval_percent,
                     community_aggregate_quorum_percent, community_aggregate_approval_percent,
                     require_community_approval, individual_vote_basis, community_vote_basis,
                     rationale, configured_by, review_deadline)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))"
            );
            if (!$insert) throw new Exception('rule proposal insert prepare failed');
            $insert->bind_param(
                'iisddddddisssi',
                $version_id, $proposal_number, $jurisdiction,
                $individual_quorum_value, $individual_approval_value,
                $community_quorum_value, $community_approval_value,
                $aggregate_quorum_value, $aggregate_approval_value,
                $require_community_value, $individual_basis, $community_basis,
                $rationale, $user_id
            );
            if (!$insert->execute()) throw new Exception('rule proposal insert failed');
            $proposal_id = (int)$conn->insert_id;
            $insert->close();

            $elector_insert = $conn->prepare(
                "INSERT IGNORE INTO community_law_voting_rule_electorate (proposal_id, user_id)
                 SELECT ?, cm.user_id
                 FROM community_members cm
                 WHERE cm.community_id=? AND cm.user_id<>?
                   AND cm.user_id<>(SELECT proposed_by FROM community_law_versions WHERE id=?)
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_review_electorate re
                       WHERE re.law_version_id=? AND re.user_id=cm.user_id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_authority_assessments aa
                       WHERE aa.law_version_id=? AND aa.reviewer_user_id=cm.user_id
                   )"
            );
            if (!$elector_insert) throw new Exception('rule electorate insert prepare failed');
            foreach ($scope_ids as $scope_id) {
                $elector_insert->bind_param(
                    'iiiiii', $proposal_id, $scope_id, $user_id,
                    $version_id, $version_id, $version_id
                );
                if (!$elector_insert->execute()) throw new Exception('rule electorate insert failed');
            }
            $elector_insert->close();

            $count_stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM community_law_voting_rule_electorate WHERE proposal_id=?"
            );
            if (!$count_stmt) throw new Exception('rule electorate count prepare failed');
            $count_stmt->bind_param('i', $proposal_id);
            if (!$count_stmt->execute()) throw new Exception('rule electorate count failed');
            $electorate_count = (int)$count_stmt->get_result()->fetch_assoc()['total'];
            $count_stmt->close();
            if ($electorate_count < 3) throw new RuntimeException('insufficient_rule_reviewers');

            if (!community_law_record_history(
                $conn, $law_id, $version_id, $community_id, $user_id,
                'voting_rules_proposed',
                'پیشنهاد قواعد رأی‌گیری شماره ' . $proposal_number . ' برای بررسی مستقل هفت‌روزه ثبت شد؛ ارزیابان قبلی و پیشنهاددهنده از پنل کنار گذاشته شدند.'
            )) throw new Exception('rule proposal history failed');

            if (!$conn->commit()) throw new Exception('rule proposal commit failed');
            community_laws_redirect($community_id, 'voting_rules_submitted');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException) {
                if ($e->getMessage() === 'voting_rules_not_configurable') community_laws_redirect($community_id, 'voting_rules_not_configurable');
                if ($e->getMessage() === 'voting_rules_already_approved') community_laws_redirect($community_id, 'voting_rules_already_approved');
                if ($e->getMessage() === 'voting_rules_review_pending') community_laws_redirect($community_id, 'voting_rules_review_pending');
                if ($e->getMessage() === 'insufficient_rule_reviewers') community_laws_redirect($community_id, 'insufficient_rule_reviewers');
                if ($e->getMessage() === 'invalid_law_scope') community_laws_redirect($community_id, 'invalid_law_scope');
            }
            error_log('[Dolate Eshgh voting rules proposal] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['submit_law_voting_rule_assessment'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');
        $proposal_id = (int)($_POST['proposal_id'] ?? 0);
        $decision = (string)($_POST['rule_decision'] ?? '');
        $reasoning = trim((string)($_POST['rule_reasoning'] ?? ''));
        if ($proposal_id <= 0 || !in_array($decision, ['approve','reject'], true) ||
            $reasoning === '' || mb_strlen($reasoning) > 20000) {
            community_laws_redirect($community_id, 'invalid_voting_rule_assessment');
        }

        $proposal_lock = $conn->prepare(
            "SELECT p.law_version_id, p.status, p.review_deadline, l.id AS law_id,
                    l.status AS law_status, l.current_version_id, l.originating_community_id,
                    v.status AS version_status
             FROM community_law_voting_rule_proposals p
             JOIN community_law_versions v ON v.id=p.law_version_id
             JOIN community_laws l ON l.id=v.law_id
             WHERE p.id=? LIMIT 1"
        );
        if (!$proposal_lock) community_laws_redirect($community_id, 'save_error');
        $proposal_lock->bind_param('i', $proposal_id);
        if (!$proposal_lock->execute()) {
            $proposal_lock->close();
            community_laws_redirect($community_id, 'save_error');
        }
        $proposal = $proposal_lock->get_result()->fetch_assoc();
        $proposal_lock->close();
        if (
            !$proposal || $proposal['status'] !== 'pending_review' ||
            strtotime($proposal['review_deadline']) <= time() ||
            $proposal['law_status'] !== 'proposed' || $proposal['version_status'] !== 'proposed' ||
            (int)$proposal['current_version_id'] !== (int)$proposal['law_version_id'] ||
            community_law_scope_match_checked(
                $conn, (int)$proposal['law_version_id'], $community_id
            ) !== true
        ) community_laws_redirect($community_id, 'voting_rule_assessment_not_allowed');

        $eligible = $conn->prepare(
            "SELECT 1 FROM community_law_voting_rule_electorate
             WHERE proposal_id=? AND user_id=? LIMIT 1"
        );
        if (!$eligible) community_laws_redirect($community_id, 'save_error');
        $eligible->bind_param('ii', $proposal_id, $user_id);
        if (!$eligible->execute()) {
            $eligible->close();
            community_laws_redirect($community_id, 'save_error');
        }
        $is_eligible = (bool)$eligible->get_result()->fetch_row();
        $eligible->close();
        if (!$is_eligible) community_laws_redirect($community_id, 'voting_rule_assessment_not_allowed');

        $vote_insert = $conn->prepare(
            "INSERT INTO community_law_voting_rule_votes
                (proposal_id, reviewer_user_id, decision, reasoning)
             VALUES (?, ?, ?, ?)"
        );
        if (!$vote_insert) community_laws_redirect($community_id, 'save_error');
        $vote_insert->bind_param('iiss', $proposal_id, $user_id, $decision, $reasoning);
        if (!$vote_insert->execute()) {
            $vote_insert->close();
            community_laws_redirect($community_id, 'voting_rule_vote_duplicate');
        }
        $vote_insert->close();
        community_laws_redirect($community_id, 'voting_rule_assessment_saved');
    }

    if (isset($_POST['finalize_law_voting_rules'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');
        $proposal_id = (int)($_POST['proposal_id'] ?? 0);
        if ($proposal_id <= 0) community_laws_redirect($community_id, 'invalid_voting_rule_assessment');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $lock = $conn->prepare(
                "SELECT p.*, v.id AS version_id, v.status AS version_status,
                        l.id AS law_id, l.status AS law_status, l.current_version_id,
                        l.originating_community_id, ac.status AS authority_status,
                        ac.derived_jurisdiction_level
                 FROM community_law_voting_rule_proposals p
                 JOIN community_law_versions v ON v.id=p.law_version_id
                 JOIN community_laws l ON l.id=v.law_id
                 JOIN community_law_authority_cases ac ON ac.law_version_id=v.id
                 WHERE p.id=? FOR UPDATE"
            );
            if (!$lock) throw new Exception('rule finalization lock prepare failed');
            $lock->bind_param('i', $proposal_id);
            if (!$lock->execute()) throw new Exception('rule finalization lock failed');
            $proposal = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (
                !$proposal || $proposal['status'] !== 'pending_review' ||
                strtotime($proposal['review_deadline']) > time() ||
                $proposal['law_status'] !== 'proposed' || $proposal['version_status'] !== 'proposed' ||
                (int)$proposal['current_version_id'] !== (int)$proposal['law_version_id'] ||
                community_law_scope_match_checked(
                    $conn, (int)$proposal['law_version_id'], $community_id
                ) !== true ||
                $proposal['authority_status'] !== 'confirmed' ||
                $proposal['jurisdiction_level'] !== $proposal['derived_jurisdiction_level']
            ) throw new RuntimeException('voting_rules_not_finalizable');

            $tally = $conn->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN decision='approve' THEN 1 ELSE 0 END) AS approvals
                 FROM community_law_voting_rule_votes WHERE proposal_id=?"
            );
            if (!$tally) throw new Exception('rule tally prepare failed');
            $tally->bind_param('i', $proposal_id);
            if (!$tally->execute()) throw new Exception('rule tally failed');
            $votes = $tally->get_result()->fetch_assoc();
            $tally->close();
            $total_votes = (int)$votes['total'];
            $approvals = (int)$votes['approvals'];
            if ($total_votes < (int)$proposal['reviewer_quorum']) {
                throw new RuntimeException('insufficient_rule_votes');
            }
            // نسبت دقیق دوسوم؛ از خطای گردکردن 66.67 جلوگیری می‌شود.
            $approved = ($approvals * 3) >= ($total_votes * 2);
            $new_status = $approved ? 'approved' : 'rejected';

            $decision_update = $conn->prepare(
                "UPDATE community_law_voting_rule_proposals
                 SET status=?, decided_at=NOW() WHERE id=? AND status='pending_review'"
            );
            if (!$decision_update) throw new Exception('rule decision update prepare failed');
            $decision_update->bind_param('si', $new_status, $proposal_id);
            if (!$decision_update->execute() || $decision_update->affected_rows !== 1) {
                throw new Exception('rule decision update failed');
            }
            $decision_update->close();

            if ($approved) {
                $existing = $conn->prepare(
                    "SELECT status FROM community_law_voting_rules
                     WHERE law_version_id=? LIMIT 1 FOR UPDATE"
                );
                if (!$existing) throw new Exception('approved rule existence prepare failed');
                $existing->bind_param('i', $proposal['law_version_id']);
                if (!$existing->execute()) throw new Exception('approved rule existence lookup failed');
                $existing_rule = $existing->get_result()->fetch_assoc();
                $existing->close();
                if ($existing_rule && $existing_rule['status'] === 'approved') {
                    throw new RuntimeException('voting_rules_already_approved');
                }

                $rule_insert = $conn->prepare(
                    "INSERT INTO community_law_voting_rules
                        (law_version_id, jurisdiction_level,
                         individual_quorum_percent, individual_approval_percent,
                         community_quorum_percent, community_approval_percent,
                         community_aggregate_quorum_percent, community_aggregate_approval_percent,
                         require_community_approval, individual_vote_basis, community_vote_basis,
                         status, configured_by, configured_at, approved_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        jurisdiction_level=VALUES(jurisdiction_level),
                        individual_quorum_percent=VALUES(individual_quorum_percent),
                        individual_approval_percent=VALUES(individual_approval_percent),
                        community_quorum_percent=VALUES(community_quorum_percent),
                        community_approval_percent=VALUES(community_approval_percent),
                        community_aggregate_quorum_percent=VALUES(community_aggregate_quorum_percent),
                        community_aggregate_approval_percent=VALUES(community_aggregate_approval_percent),
                        require_community_approval=VALUES(require_community_approval),
                        individual_vote_basis=VALUES(individual_vote_basis),
                        community_vote_basis=VALUES(community_vote_basis),
                        status='approved', configured_by=VALUES(configured_by),
                        configured_at=VALUES(configured_at), approved_at=VALUES(approved_at)"
                );
                if (!$rule_insert) throw new Exception('approved rule insert prepare failed');
                $rule_insert->bind_param(
                    'isddddddissi',
                    $proposal['law_version_id'], $proposal['jurisdiction_level'],
                    $proposal['individual_quorum_percent'], $proposal['individual_approval_percent'],
                    $proposal['community_quorum_percent'], $proposal['community_approval_percent'],
                    $proposal['community_aggregate_quorum_percent'], $proposal['community_aggregate_approval_percent'],
                    $proposal['require_community_approval'], $proposal['individual_vote_basis'],
                    $proposal['community_vote_basis'], $proposal['configured_by']
                );
                if (!$rule_insert->execute()) throw new Exception('approved rule insert failed');
                $rule_insert->close();
            }

            if (!community_law_record_history(
                $conn, (int)$proposal['law_id'], (int)$proposal['law_version_id'],
                $community_id, $user_id, 'voting_rules_' . $new_status,
                'پیشنهاد قواعد رأی‌گیری شماره ' . (int)$proposal['proposal_number'] .
                ' پس از پایان مهلت و ثبت ' . $total_votes . ' ارزیابی، با ' . $approvals .
                ' رأی موافق از حدنصاب دوسوم ' . ($approved ? 'تأیید شد.' : 'عبور نکرد و رد شد.')
            )) throw new Exception('rule decision history failed');

            if (!$conn->commit()) throw new Exception('rule decision commit failed');
            community_laws_redirect($community_id, $approved ? 'voting_rules_approved' : 'voting_rules_rejected');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException) {
                if ($e->getMessage() === 'voting_rules_not_finalizable') community_laws_redirect($community_id, 'voting_rules_not_finalizable');
                if ($e->getMessage() === 'insufficient_rule_votes') community_laws_redirect($community_id, 'insufficient_rule_votes');
                if ($e->getMessage() === 'voting_rules_already_approved') community_laws_redirect($community_id, 'voting_rules_already_approved');
            }
            error_log('[Dolate Eshgh voting rules finalization] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['start_law_vote'])) {
        if (!$amendment_schema_ready) community_laws_redirect($community_id, 'schema');
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $law_id = (int)($_POST['law_id'] ?? 0);
        $version_id = (int)($_POST['version_id'] ?? 0);
        if ($law_id <= 0 || $version_id <= 0) community_laws_redirect($community_id, 'invalid_vote_start');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $lock = $conn->prepare(
                "SELECT l.status, l.current_version_id, v.status AS version_status,
                        v.source_topic_id,a.id AS amendment_case_id,a.status AS amendment_status,
                        a.base_version_id AS amendment_base_version_id,a.conformity_case_id,
                        cc.status AS conformity_status,pl.status AS parent_law_status,
                        pl.current_version_id AS parent_current_version_id,
                        pl.effective_at AS parent_effective_at,pl.expires_at AS parent_expires_at,
                        pl.repealed_at AS parent_repealed_at,pv.status AS parent_version_status,
                        cc.parent_law_version_id
                 FROM community_laws l
                 JOIN community_law_versions v ON v.law_id=l.id
                 LEFT JOIN community_law_amendment_cases a ON a.proposed_version_id=v.id
                 LEFT JOIN community_law_conformity_cases cc ON cc.id=a.conformity_case_id
                 LEFT JOIN community_laws pl ON pl.id=cc.parent_law_id
                 LEFT JOIN community_law_versions pv ON pv.id=cc.parent_law_version_id AND pv.law_id=pl.id
                 WHERE l.id=? AND v.id=? AND l.originating_community_id=?
                 FOR UPDATE"
            );
            if (!$lock) throw new Exception('law vote lock prepare failed');
            $lock->bind_param('iii', $law_id, $version_id, $community_id);
            if (!$lock->execute()) throw new Exception('law vote lock failed');
            $record = $lock->get_result()->fetch_assoc();
            $lock->close();

            $is_amendment_vote = $record && !empty($record['amendment_case_id']);
            $standard_vote_state = $record &&
                $record['status'] === 'proposed' &&
                $record['version_status'] === 'proposed' &&
                (int)$record['current_version_id'] === $version_id;
            $amendment_vote_state = $is_amendment_vote &&
                $record['status'] === 'approved' &&
                $record['version_status'] === 'proposed' &&
                $record['amendment_status'] === 'submitted' &&
                $record['conformity_status'] === 'amendment_required' &&
                $record['parent_law_status'] === 'approved' &&
                $record['parent_version_status'] === 'approved' &&
                (int)$record['parent_current_version_id'] === (int)$record['parent_law_version_id'] &&
                empty($record['parent_repealed_at']) &&
                (empty($record['parent_effective_at']) || strtotime($record['parent_effective_at']) <= time()) &&
                (empty($record['parent_expires_at']) || strtotime($record['parent_expires_at']) > time()) &&
                (int)$record['current_version_id'] === (int)$record['amendment_base_version_id'];
            if (!$standard_vote_state && !$amendment_vote_state) {
                throw new RuntimeException('invalid_law_vote_state');
            }

            if (!$is_amendment_vote) {
            $screening_stmt = $conn->prepare(
                "SELECT status FROM community_law_review_cases
                 WHERE law_version_id=? FOR UPDATE"
            );
            if (!$screening_stmt) throw new Exception('legal screening state prepare failed');
            $screening_stmt->bind_param('i', $version_id);
            if (!$screening_stmt->execute()) throw new Exception('legal screening state lookup failed');
            $screening = $screening_stmt->get_result()->fetch_assoc();
            $screening_stmt->close();
            if (!$screening || $screening['status'] !== 'screening_clear') {
                throw new RuntimeException('legal_screening_required');
            }

            $authority_stmt = $conn->prepare(
                "SELECT status, derived_jurisdiction_level
                 FROM community_law_authority_cases
                 WHERE law_version_id=? FOR UPDATE"
            );
            if (!$authority_stmt) throw new Exception('authority state prepare failed');
            $authority_stmt->bind_param('i', $version_id);
            if (!$authority_stmt->execute()) throw new Exception('authority state lookup failed');
            $authority_case = $authority_stmt->get_result()->fetch_assoc();
            $authority_stmt->close();
            if (!$authority_case || $authority_case['status'] !== 'confirmed') {
                throw new RuntimeException('authority_review_required');
            }
            }

            // آغاز رأی‌گیری بدون قواعد تصویبِ تکمیل و تأییدشده مجاز نیست.
            $rules_stmt = $conn->prepare(
                "SELECT status, jurisdiction_level,
                        individual_quorum_percent, individual_approval_percent,
                        community_quorum_percent, community_approval_percent,
                        community_aggregate_quorum_percent, community_aggregate_approval_percent,
                        require_community_approval, individual_vote_basis,
                        community_vote_basis
                 FROM community_law_voting_rules
                 WHERE law_version_id=? LIMIT 1 FOR UPDATE"
            );
            if (!$rules_stmt) throw new Exception('voting rules prepare failed');
            $rules_stmt->bind_param('i', $version_id);
            if (!$rules_stmt->execute()) throw new Exception('voting rules lookup failed');
            $rules = $rules_stmt->get_result()->fetch_assoc();
            $rules_stmt->close();

            $rules_complete = $rules
                && $rules['status'] === 'approved'
                && !empty($rules['jurisdiction_level'])
                && $rules['individual_quorum_percent'] !== null
                && $rules['individual_approval_percent'] !== null
                && $rules['individual_vote_basis'] !== null
                && $rules['require_community_approval'] !== null
                && (
                    (int)$rules['require_community_approval'] === 0
                    || (
                        $rules['community_quorum_percent'] !== null
                        && $rules['community_approval_percent'] !== null
                        && $rules['community_aggregate_quorum_percent'] !== null
                        && $rules['community_aggregate_approval_percent'] !== null
                        && $rules['community_vote_basis'] !== null
                    )
                );
            if (
                !$rules_complete ||
                (!$is_amendment_vote && $rules['jurisdiction_level'] !== $authority_case['derived_jurisdiction_level'])
            ) {
                throw new RuntimeException('voting_rules_not_approved');
            }
            if (
                (int)$rules['require_community_approval'] === 1 &&
                $rules['community_vote_basis'] === 'population_weighted'
            ) {
                throw new RuntimeException('population_snapshot_required');
            }

            $affected_communities = community_law_scope_community_ids($conn, $version_id);
            if (!$affected_communities) throw new RuntimeException('invalid_law_scope');

            $insert_elector = $conn->prepare(
                "INSERT IGNORE INTO community_law_electorate
                    (law_version_id, voting_community_id, user_id, vote_weight)
                 SELECT ?, ?, cm.user_id, 1.00
                 FROM community_members cm
                 WHERE cm.community_id=?"
            );
            $insert_position = $conn->prepare(
                "INSERT INTO community_law_positions
                    (law_version_id, community_id, electorate_count, position_result)
                 VALUES (?, ?, 0, 'pending')"
            );

            // مسیر رأی مستقیم: هر کاربر از اجتماع اعضای همه جوامع مشمول فقط یک‌بار وارد فهرست می‌شود.
            $insert_direct_electorate = $conn->prepare(
                "INSERT IGNORE INTO community_law_direct_electorate
                    (law_version_id, user_id, vote_weight)
                 SELECT ?, e.user_id, 1.00
                 FROM community_law_electorate e
                 WHERE e.law_version_id=?"
            );
            $count_members = $conn->prepare(
                "SELECT COUNT(*) AS total FROM community_members WHERE community_id=?"
            );
            $set_position_count = $conn->prepare(
                "UPDATE community_law_positions SET electorate_count=? 
                 WHERE law_version_id=? AND community_id=?"
            );
            if (!$insert_elector || !$insert_position || !$count_members || !$set_position_count) {
                throw new Exception('law electorate prepare failed');
            }

            $total_electorate = 0;
            foreach ($affected_communities as $affected_id) {
                $count_members->bind_param('i', $affected_id);
                if (!$count_members->execute()) throw new Exception('member count failed');
                $member_count = (int)$count_members->get_result()->fetch_assoc()['total'];

                if ($member_count === 0) continue;

                $insert_position->bind_param('ii', $version_id, $affected_id);
                if (!$insert_position->execute()) throw new Exception('position snapshot failed');

                $insert_elector->bind_param('iii', $version_id, $affected_id, $affected_id);
                if (!$insert_elector->execute()) throw new Exception('electorate snapshot failed');

                $set_position_count->bind_param('iii', $member_count, $version_id, $affected_id);
                if (!$set_position_count->execute()) throw new Exception('position count update failed');
                $total_electorate += $member_count;
            }
            $insert_elector->close();
            $insert_position->close();
            $count_members->close();
            $set_position_count->close();

            if ($total_electorate === 0) throw new RuntimeException('empty_law_electorate');

            if (!$insert_direct_electorate) throw new Exception('direct electorate prepare failed');
            $insert_direct_electorate->bind_param('ii', $version_id, $version_id);
            if (!$insert_direct_electorate->execute()) throw new Exception('direct electorate snapshot failed');
            $insert_direct_electorate->close();

            $direct_count_stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM community_law_direct_electorate WHERE law_version_id=?"
            );
            if (!$direct_count_stmt) throw new Exception('direct electorate count prepare failed');
            $direct_count_stmt->bind_param('i', $version_id);
            if (!$direct_count_stmt->execute()) throw new Exception('direct electorate count failed');
            $direct_electorate_count = (int)$direct_count_stmt->get_result()->fetch_assoc()['total'];
            $direct_count_stmt->close();
            if ($direct_electorate_count === 0) throw new RuntimeException('empty_law_electorate');

            // رأی وزن‌دار فقط با پروفایل چندحوزه‌ایِ تأییدشده و snapshot کامل مجاز است.
            if ($rules['individual_vote_basis'] === 'weighted') {
                $expertise_schema = $conn->query(
                    "SELECT COUNT(*) AS total FROM information_schema.tables
                     WHERE table_schema=DATABASE()
                       AND table_name IN (
                         'topic_expertise_profiles',
                         'topic_expertise_profile_domains',
                         'community_law_expertise_profile_snapshots',
                         'community_law_expertise_profile_domains',
                         'community_law_expertise_voter_weights',
                         'community_law_expertise_weight_details'
                       )"
                );
                if (!$expertise_schema) throw new Exception('expertise schema check failed');
                $expertise_tables_ready = (int)$expertise_schema->fetch_assoc()['total'] === 6;
                $expertise_schema->free();
                if (!$expertise_tables_ready) throw new RuntimeException('weighted_expertise_schema_missing');

                $source_topic_id = (int)($record['source_topic_id'] ?? 0);
                if ($source_topic_id <= 0) throw new RuntimeException('weighted_expertise_profile_required');

                $profile_stmt = $conn->prepare(
                    "SELECT id FROM topic_expertise_profiles
                     WHERE topic_id=? AND status='approved'
                     ORDER BY version_number DESC LIMIT 1 FOR UPDATE"
                );
                if (!$profile_stmt) throw new Exception('expertise profile lookup prepare failed');
                $profile_stmt->bind_param('i', $source_topic_id);
                if (!$profile_stmt->execute()) throw new Exception('expertise profile lookup failed');
                $expertise_profile = $profile_stmt->get_result()->fetch_assoc();
                $profile_stmt->close();
                if (!$expertise_profile) throw new RuntimeException('weighted_expertise_profile_required');
                $profile_id = (int)$expertise_profile['id'];

                $profile_check = $conn->prepare(
                    "SELECT COUNT(*) AS domain_count,
                            COALESCE(SUM(share_percent),0) AS share_total,
                            COUNT(DISTINCT domain_id) AS distinct_domains
                     FROM topic_expertise_profile_domains WHERE profile_id=?"
                );
                if (!$profile_check) throw new Exception('expertise profile validation prepare failed');
                $profile_check->bind_param('i', $profile_id);
                if (!$profile_check->execute()) throw new Exception('expertise profile validation failed');
                $profile_stats = $profile_check->get_result()->fetch_assoc();
                $profile_check->close();
                if (
                    (int)$profile_stats['domain_count'] < 1 ||
                    (int)$profile_stats['domain_count'] !== (int)$profile_stats['distinct_domains'] ||
                    abs((float)$profile_stats['share_total'] - 100.0) > 0.001
                ) throw new RuntimeException('weighted_expertise_profile_invalid');

                $snapshot = $conn->prepare(
                    "INSERT INTO community_law_expertise_profile_snapshots
                        (law_version_id, profile_id, topic_id)
                     VALUES (?, ?, ?)"
                );
                if (!$snapshot) throw new Exception('expertise profile snapshot prepare failed');
                $snapshot->bind_param('iii', $version_id, $profile_id, $source_topic_id);
                if (!$snapshot->execute()) throw new Exception('expertise profile snapshot failed');
                $snapshot->close();

                $snapshot_domains = $conn->prepare(
                    "INSERT INTO community_law_expertise_profile_domains
                        (law_version_id, domain_id, share_percent)
                     SELECT ?, domain_id, share_percent
                     FROM topic_expertise_profile_domains WHERE profile_id=?"
                );
                if (!$snapshot_domains) throw new Exception('expertise domain snapshot prepare failed');
                $snapshot_domains->bind_param('ii', $version_id, $profile_id);
                if (!$snapshot_domains->execute()) throw new Exception('expertise domain snapshot failed');
                $snapshot_domains->close();

                // نبود شایستگی معتبر در یک حوزه، وزن پایهٔ ۱ همان حوزه را اعمال می‌کند.
                $weight_details = $conn->prepare(
                    "INSERT INTO community_law_expertise_weight_details
                        (law_version_id, user_id, domain_id, share_percent,
                         competency_id, source_application_id, competency_weight, weighted_contribution)
                     SELECT ?, e.user_id, pd.domain_id, pd.share_percent,
                            ec.id, ec.source_application_id,
                            LEAST(3.00, GREATEST(1.00, COALESCE(ec.weight, 1.00))),
                            (pd.share_percent * LEAST(3.00, GREATEST(1.00, COALESCE(ec.weight, 1.00)))) / 100.00
                     FROM community_law_direct_electorate e
                     CROSS JOIN topic_expertise_profile_domains pd
                     LEFT JOIN expertise_competencies ec
                       ON ec.user_id=e.user_id
                      AND ec.domain_id=pd.domain_id
                      AND ec.valid_from<=NOW()
                      AND ec.valid_until>NOW()
                     WHERE e.law_version_id=? AND pd.profile_id=?"
                );
                if (!$weight_details) throw new Exception('expertise weight detail prepare failed');
                $weight_details->bind_param('iii', $version_id, $version_id, $profile_id);
                if (!$weight_details->execute()) throw new Exception('expertise weight details snapshot failed');
                $weight_details->close();

                $weight_snapshot = $conn->prepare(
                    "INSERT INTO community_law_expertise_voter_weights
                        (law_version_id, user_id, profile_id, final_vote_weight)
                     SELECT law_version_id, user_id, ?, LEAST(3.00, GREATEST(1.00, SUM(weighted_contribution)))
                     FROM community_law_expertise_weight_details
                     WHERE law_version_id=?
                     GROUP BY law_version_id, user_id"
                );
                if (!$weight_snapshot) throw new Exception('expertise voter weight snapshot prepare failed');
                $weight_snapshot->bind_param('ii', $profile_id, $version_id);
                if (!$weight_snapshot->execute() || $weight_snapshot->affected_rows !== $direct_electorate_count) {
                    throw new Exception('expertise voter weight snapshot incomplete');
                }
                $weight_snapshot->close();

                $apply_direct_weights = $conn->prepare(
                    "UPDATE community_law_direct_electorate e
                     JOIN community_law_expertise_voter_weights w
                       ON w.law_version_id=e.law_version_id AND w.user_id=e.user_id
                     SET e.vote_weight=w.final_vote_weight
                     WHERE e.law_version_id=?"
                );
                if (!$apply_direct_weights) throw new Exception('direct expertise weights prepare failed');
                $apply_direct_weights->bind_param('i', $version_id);
                if (!$apply_direct_weights->execute() || $apply_direct_weights->affected_rows < 0) {
                    throw new Exception('direct expertise weights update failed');
                }
                $apply_direct_weights->close();

                $apply_community_weights = $conn->prepare(
                    "UPDATE community_law_electorate e
                     JOIN community_law_expertise_voter_weights w
                       ON w.law_version_id=e.law_version_id AND w.user_id=e.user_id
                     SET e.vote_weight=w.final_vote_weight
                     WHERE e.law_version_id=?"
                );
                if (!$apply_community_weights) throw new Exception('community expertise weights prepare failed');
                $apply_community_weights->bind_param('i', $version_id);
                if (!$apply_community_weights->execute()) throw new Exception('community expertise weights update failed');
                $apply_community_weights->close();
            }

            $deadline = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);
            $version_update = $conn->prepare(
                "UPDATE community_law_versions
                 SET status='voting', vote_started_at=NOW(), vote_deadline=?
                 WHERE id=? AND status='proposed'"
            );
            if (!$version_update) throw new Exception('law version start prepare failed');
            $version_update->bind_param('si', $deadline, $version_id);
            if (!$version_update->execute() || $version_update->affected_rows !== 1) {
                throw new Exception('law version start failed');
            }
            $version_update->close();

            if (!$is_amendment_vote) {
                $law_update = $conn->prepare(
                    "UPDATE community_laws SET status='voting' WHERE id=? AND status='proposed'"
                );
                if (!$law_update) throw new Exception('law start prepare failed');
                $law_update->bind_param('i', $law_id);
                if (!$law_update->execute() || $law_update->affected_rows !== 1) {
                    throw new Exception('law start failed');
                }
                $law_update->close();
            } else {
                $amendment_update=$conn->prepare(
                    "UPDATE community_law_amendment_cases SET status='local_voting'
                     WHERE id=? AND status='submitted'"
                );
                if (!$amendment_update) throw new Exception('amendment vote state prepare failed');
                $amendment_id=(int)$record['amendment_case_id'];
                $amendment_update->bind_param('i',$amendment_id);
                if (!$amendment_update->execute() || $amendment_update->affected_rows!==1) {
                    throw new Exception('amendment vote state update failed');
                }
                $amendment_update->close();
                if ($final_status!=='vote_passed') {
                    $conformity_reset=$conn->prepare(
                        "UPDATE community_law_conformity_cases
                         SET status='amendment_required'
                         WHERE id=? AND status='amendment_in_progress'"
                    );
                    if (!$conformity_reset) throw new Exception('conformity retry state prepare failed');
                    $conformity_case_id=(int)$record['conformity_case_id'];
                    $conformity_reset->bind_param('i',$conformity_case_id);
                    if (!$conformity_reset->execute() || $conformity_reset->affected_rows!==1) {
                        throw new Exception('conformity retry state update failed');
                    }
                    $conformity_reset->close();
                }
                $amendment_details=json_encode([
                    'base_version_id'=>(int)$record['amendment_base_version_id'],
                    'proposed_version_id'=>$version_id,'current_law_unchanged'=>true,
                    'electorate_scope'=>'approved amendment version scope snapshot'
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $amendment_event=$conn->prepare(
                    "INSERT INTO community_law_amendment_events (case_id,actor_user_id,event_type,details)
                     VALUES (?,?,'local_voting_started',?)"
                );
                if (!$amendment_event) throw new Exception('amendment vote event prepare failed');
                $amendment_event->bind_param('iis',$amendment_id,$user_id,$amendment_details);
                if (!$amendment_event->execute()) throw new Exception('amendment vote event failed');
                $amendment_event->close();
            }

            if (!community_law_record_history(
                $conn, $law_id, $version_id, $community_id, $user_id,
                $is_amendment_vote ? 'amendment_local_voting_started' : 'voting_started',
                $is_amendment_vote
                    ? 'رأی‌گیری محلی اصلاحیه آغاز شد؛ نسخهٔ مصوب قبلی و وضعیت جاری قانون بدون تغییر باقی ماند.'
                    : 'رأی‌گیری هفت‌روزه آغاز شد؛ فهرست اعضای واجد شرایط هر جامعه مشمول در زمان آغاز ثبت شد.'
            )) throw new Exception('law vote history failed');

            if (!$conn->commit()) throw new Exception('law vote commit failed');
            if ($is_amendment_vote) {
                header('Location: community_law_amendments.php?community_id='.$community_id.'&status=amendment_voting_started');
                exit;
            }
            community_laws_redirect($community_id, 'voting_started');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException) {
                if ($e->getMessage() === 'invalid_law_vote_state') community_laws_redirect($community_id, 'invalid_vote_start');
                if ($e->getMessage() === 'voting_rules_not_approved') community_laws_redirect($community_id, 'voting_rules_required');
                if ($e->getMessage() === 'authority_review_required') community_laws_redirect($community_id, 'authority_review_required');
                if ($e->getMessage() === 'population_snapshot_required') community_laws_redirect($community_id, 'population_snapshot_required');
                if ($e->getMessage() === 'invalid_law_scope') community_laws_redirect($community_id, 'invalid_law_scope');
                if ($e->getMessage() === 'empty_law_electorate') community_laws_redirect($community_id, 'empty_law_electorate');
                if ($e->getMessage() === 'weighted_expertise_profile_required') community_laws_redirect($community_id, 'weighted_expertise_profile_required');
                if ($e->getMessage() === 'weighted_expertise_schema_missing') community_laws_redirect($community_id, 'weighted_expertise_schema_missing');
                if ($e->getMessage() === 'weighted_expertise_profile_invalid') community_laws_redirect($community_id, 'weighted_expertise_profile_invalid');
            }
            error_log('[Dolate Eshgh law vote start] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['finalize_law_tally'])) {
        if (!$amendment_schema_ready) community_laws_redirect($community_id, 'schema');
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $law_id = (int)($_POST['law_id'] ?? 0);
        $version_id = (int)($_POST['version_id'] ?? 0);
        if ($law_id <= 0 || $version_id <= 0) community_laws_redirect($community_id, 'invalid_tally');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $lock = $conn->prepare(
                "SELECT l.status, l.current_version_id, v.status AS version_status, v.vote_deadline,
                        a.id AS amendment_case_id,a.status AS amendment_status,
                        a.base_version_id AS amendment_base_version_id,a.conformity_case_id
                 FROM community_laws l
                 JOIN community_law_versions v ON v.law_id=l.id
                 LEFT JOIN community_law_amendment_cases a ON a.proposed_version_id=v.id
                 WHERE l.id=? AND v.id=? AND l.originating_community_id=?
                 FOR UPDATE"
            );
            if (!$lock) throw new Exception('tally lock prepare failed');
            $lock->bind_param('iii', $law_id, $version_id, $community_id);
            if (!$lock->execute()) throw new Exception('tally lock failed');
            $record = $lock->get_result()->fetch_assoc();
            $lock->close();

            $is_amendment_tally = $record && !empty($record['amendment_case_id']);
            $standard_tally_state = $record &&
                $record['status']==='voting' && $record['version_status']==='voting' &&
                (int)$record['current_version_id']===$version_id;
            $amendment_tally_state = $is_amendment_tally &&
                $record['status']==='approved' && $record['version_status']==='voting' &&
                $record['amendment_status']==='local_voting' &&
                (int)$record['current_version_id']===(int)$record['amendment_base_version_id'];
            if (
                (!$standard_tally_state && !$amendment_tally_state) ||
                empty($record['vote_deadline']) ||
                strtotime($record['vote_deadline']) > time()
            ) {
                throw new RuntimeException('tally_not_ready');
            }

            $already_counted = $conn->prepare(
                "SELECT 1 FROM community_law_final_results WHERE law_version_id=? LIMIT 1"
            );
            if (!$already_counted) throw new Exception('final result existence prepare failed');
            $already_counted->bind_param('i', $version_id);
            if (!$already_counted->execute()) throw new Exception('final result existence lookup failed');
            $has_final_result = (bool)$already_counted->get_result()->fetch_row();
            $already_counted->close();
            if ($has_final_result) throw new RuntimeException('tally_already_done');

            $rules_stmt = $conn->prepare(
                "SELECT individual_quorum_percent, individual_approval_percent,
                        community_quorum_percent, community_approval_percent,
                        community_aggregate_quorum_percent, community_aggregate_approval_percent,
                        require_community_approval, individual_vote_basis,
                        community_vote_basis
                 FROM community_law_voting_rules
                 WHERE law_version_id=? AND status='approved' LIMIT 1"
            );
            if (!$rules_stmt) throw new Exception('tally rules prepare failed');
            $rules_stmt->bind_param('i', $version_id);
            if (!$rules_stmt->execute()) throw new Exception('tally rules lookup failed');
            $approved_rules = $rules_stmt->get_result()->fetch_assoc();
            $rules_stmt->close();
            if (!$approved_rules) throw new RuntimeException('tally_rules_missing');
            if (
                (int)$approved_rules['require_community_approval'] === 1 &&
                $approved_rules['community_vote_basis'] === 'population_weighted'
            ) throw new RuntimeException('population_snapshot_required');

            $aggregate = $conn->prepare(
                "SELECT e.voting_community_id,
                        COUNT(e.id) AS electorate_count,
                        COUNT(v.id) AS cast_count,
                        COALESCE(SUM(e.vote_weight),0) AS electorate_weight,
                        COALESCE(SUM(CASE WHEN v.id IS NOT NULL THEN v.vote_weight ELSE 0 END),0) AS cast_weight,
                        SUM(CASE WHEN v.vote='yes' THEN 1 ELSE 0 END) AS yes_count,
                        SUM(CASE WHEN v.vote='no' THEN 1 ELSE 0 END) AS no_count,
                        SUM(CASE WHEN v.vote='abstain' THEN 1 ELSE 0 END) AS abstain_count,
                        COALESCE(SUM(CASE WHEN v.vote='yes' THEN v.vote_weight ELSE 0 END),0) AS yes_weight,
                        COALESCE(SUM(CASE WHEN v.vote='no' THEN v.vote_weight ELSE 0 END),0) AS no_weight
                 FROM community_law_electorate e
                 LEFT JOIN community_law_votes v
                   ON v.law_version_id=e.law_version_id
                  AND v.voting_community_id=e.voting_community_id
                  AND v.voter_user_id=e.user_id
                 WHERE e.law_version_id=?
                 GROUP BY e.voting_community_id"
            );
            if (!$aggregate) throw new Exception('tally aggregate prepare failed');
            $aggregate->bind_param('i', $version_id);
            if (!$aggregate->execute()) throw new Exception('tally aggregate failed');
            $rows = $aggregate->get_result();

            $update_position = $conn->prepare(
                "UPDATE community_law_positions
                 SET yes_count=?, no_count=?, abstain_count=?,
                     yes_weight=?, no_weight=?,
                     position_result=?
                 WHERE law_version_id=? AND community_id=?"
            );
            if (!$update_position) throw new Exception('position update prepare failed');

            while ($tally = $rows->fetch_assoc()) {
                $yes = (int)$tally['yes_count'];
                $no = (int)$tally['no_count'];
                $abstain = (int)$tally['abstain_count'];
                $yes_weight = (float)$tally['yes_weight'];
                $no_weight = (float)$tally['no_weight'];
                $cast_count = (int)$tally['cast_count'];
                $affected_id = (int)$tally['voting_community_id'];
                $position_status = 'pending';

                // در رأی‌گیری وزن‌دار، حدنصاب و درصد موافقت درون هر جامعه نیز با وزن snapshotشده محاسبه می‌شود.
                if ((int)$approved_rules['require_community_approval'] === 1) {
                    $community_quorum = (float)$approved_rules['community_quorum_percent'];
                    $community_approval = (float)$approved_rules['community_approval_percent'];
                    $weighted = $approved_rules['individual_vote_basis'] === 'weighted';
                    $eligible_metric = $weighted
                        ? (float)$tally['electorate_weight'] : (float)$tally['electorate_count'];
                    $cast_metric = $weighted
                        ? (float)$tally['cast_weight'] : (float)$cast_count;
                    $yes_metric = $weighted ? $yes_weight : (float)$yes;
                    $no_metric = $weighted ? $no_weight : (float)$no;
                    $turnout_percent = $eligible_metric > 0
                        ? ($cast_metric / $eligible_metric) * 100 : 0;
                    $decisive_votes = $yes_metric + $no_metric;
                    if (
                        $cast_metric <= 0 ||
                        $turnout_percent + 0.00001 < $community_quorum ||
                        $decisive_votes <= 0
                    ) {
                        $position_status = 'insufficient';
                    } else {
                        $yes_percent = ($yes_metric / $decisive_votes) * 100;
                        $position_status = $yes_percent + 0.00001 >= $community_approval
                            ? 'approve'
                            : 'reject';
                    }
                }

                $update_position->bind_param(
                    'iiiddsii',
                    $yes, $no, $abstain, $yes_weight, $no_weight,
                    $position_status, $version_id, $affected_id
                );
                if (!$update_position->execute()) throw new Exception('position tally update failed');
            }
            $update_position->close();
            $aggregate->close();

            // شمارش مسیر مستقیم افراد؛ هر کاربر فقط یک‌بار در این جدول حضور دارد.
            $direct_aggregate = $conn->prepare(
                "SELECT COUNT(e.user_id) AS electorate_count,
                        SUM(CASE WHEN v.vote='yes' THEN 1 ELSE 0 END) AS yes_count,
                        SUM(CASE WHEN v.vote='no' THEN 1 ELSE 0 END) AS no_count,
                        SUM(CASE WHEN v.vote='abstain' THEN 1 ELSE 0 END) AS abstain_count,
                        COALESCE(SUM(CASE WHEN v.vote='yes' THEN v.vote_weight ELSE 0 END),0) AS yes_weight,
                        COALESCE(SUM(CASE WHEN v.vote='no' THEN v.vote_weight ELSE 0 END),0) AS no_weight
                 FROM community_law_direct_electorate e
                 LEFT JOIN community_law_direct_votes v
                   ON v.law_version_id=e.law_version_id
                  AND v.voter_user_id=e.user_id
                 WHERE e.law_version_id=?"
            );
            if (!$direct_aggregate) throw new Exception('direct tally prepare failed');
            $direct_aggregate->bind_param('i', $version_id);
            if (!$direct_aggregate->execute()) throw new Exception('direct tally failed');
            $direct_result = $direct_aggregate->get_result()->fetch_assoc();
            $direct_aggregate->close();

            $save_direct_result = $conn->prepare(
                "INSERT INTO community_law_direct_results
                    (law_version_id, electorate_count, yes_count, no_count, abstain_count,
                     yes_weight, no_weight, counted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    electorate_count=VALUES(electorate_count),
                    yes_count=VALUES(yes_count),
                    no_count=VALUES(no_count),
                    abstain_count=VALUES(abstain_count),
                    yes_weight=VALUES(yes_weight),
                    no_weight=VALUES(no_weight),
                    counted_at=NOW()"
            );
            if (!$save_direct_result) throw new Exception('direct result save prepare failed');
            $direct_electorate = (int)$direct_result['electorate_count'];
            $direct_yes = (int)$direct_result['yes_count'];
            $direct_no = (int)$direct_result['no_count'];
            $direct_abstain = (int)$direct_result['abstain_count'];
            $direct_yes_weight = (float)$direct_result['yes_weight'];
            $direct_no_weight = (float)$direct_result['no_weight'];
            $save_direct_result->bind_param(
                'iiiiidd',
                $version_id, $direct_electorate, $direct_yes, $direct_no,
                $direct_abstain, $direct_yes_weight, $direct_no_weight
            );
            if (!$save_direct_result->execute()) throw new Exception('direct result save failed');
            $save_direct_result->close();

            // ارزیابی مسیر مستقیم افراد طبق مبنای شمارش مصوب همین نسخه.
            $direct_metrics_stmt = $conn->prepare(
                "SELECT COUNT(e.user_id) AS eligible_count,
                        COALESCE(SUM(e.vote_weight),0) AS eligible_weight,
                        COUNT(v.voter_user_id) AS cast_count,
                        COALESCE(SUM(v.vote_weight),0) AS cast_weight,
                        COALESCE(SUM(CASE WHEN v.vote='yes' THEN 1 ELSE 0 END),0) AS yes_count,
                        COALESCE(SUM(CASE WHEN v.vote='no' THEN 1 ELSE 0 END),0) AS no_count,
                        COALESCE(SUM(CASE WHEN v.vote='abstain' THEN 1 ELSE 0 END),0) AS abstain_count,
                        COALESCE(SUM(CASE WHEN v.vote='yes' THEN v.vote_weight ELSE 0 END),0) AS yes_weight,
                        COALESCE(SUM(CASE WHEN v.vote='no' THEN v.vote_weight ELSE 0 END),0) AS no_weight,
                        COALESCE(SUM(CASE WHEN v.vote='abstain' THEN v.vote_weight ELSE 0 END),0) AS abstain_weight
                 FROM community_law_direct_electorate e
                 LEFT JOIN community_law_direct_votes v
                   ON v.law_version_id=e.law_version_id AND v.voter_user_id=e.user_id
                 WHERE e.law_version_id=?"
            );
            if (!$direct_metrics_stmt) throw new Exception('direct metrics prepare failed');
            $direct_metrics_stmt->bind_param('i', $version_id);
            if (!$direct_metrics_stmt->execute()) throw new Exception('direct metrics failed');
            $direct_metrics = $direct_metrics_stmt->get_result()->fetch_assoc();
            $direct_metrics_stmt->close();

            $individual_basis = $approved_rules['individual_vote_basis'];
            if (!in_array($individual_basis, ['count','weighted'], true)) {
                throw new RuntimeException('invalid_individual_vote_basis');
            }
            $individual_eligible = $individual_basis === 'weighted'
                ? (float)$direct_metrics['eligible_weight'] : (float)$direct_metrics['eligible_count'];
            $individual_participating = $individual_basis === 'weighted'
                ? (float)$direct_metrics['cast_weight'] : (float)$direct_metrics['cast_count'];
            $individual_yes_metric = $individual_basis === 'weighted'
                ? (float)$direct_metrics['yes_weight'] : (float)$direct_metrics['yes_count'];
            $individual_no_metric = $individual_basis === 'weighted'
                ? (float)$direct_metrics['no_weight'] : (float)$direct_metrics['no_count'];
            $individual_abstain_metric = $individual_basis === 'weighted'
                ? (float)$direct_metrics['abstain_weight'] : (float)$direct_metrics['abstain_count'];
            $individual_decisive = $individual_yes_metric + $individual_no_metric;
            $individual_turnout_percent = $individual_eligible > 0
                ? ($individual_participating / $individual_eligible) * 100 : 0;
            $individual_approval_percent = $individual_decisive > 0
                ? ($individual_yes_metric / $individual_decisive) * 100 : 0;

            $require_community = (int)$approved_rules['require_community_approval'];
            $community_basis = $require_community ? $approved_rules['community_vote_basis'] : null;
            $community_eligible_metric = 0.0;
            $community_participating_metric = 0.0;
            $community_yes_metric = 0.0;
            $community_no_metric = 0.0;
            $community_turnout_percent = 0.0;
            $community_approval_percent = 0.0;
            $community_quorum_met = true;
            $community_approval_met = true;

            if ($require_community === 1) {
                if ($community_basis !== 'one_community_one_vote') {
                    throw new RuntimeException('population_snapshot_required');
                }
                $community_metrics_stmt = $conn->prepare(
                    "SELECT COUNT(*) AS eligible_communities,
                            SUM(CASE WHEN position_result IN ('approve','reject') THEN 1 ELSE 0 END) AS participating_communities,
                            SUM(CASE WHEN position_result='approve' THEN 1 ELSE 0 END) AS yes_communities,
                            SUM(CASE WHEN position_result='reject' THEN 1 ELSE 0 END) AS no_communities
                     FROM community_law_positions WHERE law_version_id=?"
                );
                if (!$community_metrics_stmt) throw new Exception('community metrics prepare failed');
                $community_metrics_stmt->bind_param('i', $version_id);
                if (!$community_metrics_stmt->execute()) throw new Exception('community metrics failed');
                $community_metrics = $community_metrics_stmt->get_result()->fetch_assoc();
                $community_metrics_stmt->close();

                $community_eligible_metric = (float)$community_metrics['eligible_communities'];
                $community_participating_metric = (float)$community_metrics['participating_communities'];
                $community_yes_metric = (float)$community_metrics['yes_communities'];
                $community_no_metric = (float)$community_metrics['no_communities'];
                $community_decisive = $community_yes_metric + $community_no_metric;
                $community_turnout_percent = $community_eligible_metric > 0
                    ? ($community_participating_metric / $community_eligible_metric) * 100 : 0;
                $community_approval_percent = $community_decisive > 0
                    ? ($community_yes_metric / $community_decisive) * 100 : 0;
                $community_quorum_met = $community_eligible_metric > 0
                    && $community_turnout_percent + 0.00001 >= (float)$approved_rules['community_aggregate_quorum_percent']
                    && $community_decisive > 0;
                $community_approval_met = $community_quorum_met
                    && $community_approval_percent + 0.00001 >= (float)$approved_rules['community_aggregate_approval_percent'];
            }

            $individual_quorum_met = $individual_eligible > 0
                && $individual_turnout_percent + 0.00001 >= (float)$approved_rules['individual_quorum_percent']
                && $individual_decisive > 0;
            $individual_approval_met = $individual_quorum_met
                && $individual_approval_percent + 0.00001 >= (float)$approved_rules['individual_approval_percent'];

            if (!$individual_quorum_met || ($require_community === 1 && !$community_quorum_met)) {
                $final_status = 'insufficient';
            } elseif (!$individual_approval_met || ($require_community === 1 && !$community_approval_met)) {
                $final_status = 'vote_rejected';
            } else {
                $final_status = 'vote_passed';
            }

            $save_final_result = $conn->prepare(
                "INSERT INTO community_law_final_results
                    (law_version_id, individual_vote_basis,
                     individual_eligible, individual_participating,
                     individual_yes, individual_no, individual_abstain,
                     individual_turnout_percent, individual_approval_percent,
                     require_community_approval, community_vote_basis,
                     community_aggregate_quorum_percent, community_aggregate_approval_percent,
                     community_eligible, community_participating,
                     community_yes, community_no,
                     community_turnout_percent, community_approval_percent,
                     final_status, calculated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$save_final_result) throw new Exception('final result insert prepare failed');
            $individual_turnout_percent = round($individual_turnout_percent, 3);
            $individual_approval_percent = round($individual_approval_percent, 3);
            $community_turnout_percent = round($community_turnout_percent, 3);
            $community_approval_percent = round($community_approval_percent, 3);
            $save_final_result->bind_param(
                'isdddddddisddddddddsi',
                $version_id, $individual_basis,
                $individual_eligible, $individual_participating,
                $individual_yes_metric, $individual_no_metric, $individual_abstain_metric,
                $individual_turnout_percent, $individual_approval_percent,
                $require_community, $community_basis,
                $approved_rules['community_aggregate_quorum_percent'],
                $approved_rules['community_aggregate_approval_percent'],
                $community_eligible_metric, $community_participating_metric,
                $community_yes_metric, $community_no_metric,
                $community_turnout_percent, $community_approval_percent,
                $final_status, $user_id
            );
            if (!$save_final_result->execute()) throw new Exception('final result insert failed');
            $save_final_result->close();

            if ($is_amendment_tally) {
                $next_amendment_status = $final_status==='vote_passed'
                    ? 'parent_review'
                    : ($final_status==='insufficient' ? 'vote_insufficient' : 'rejected');
                $decision_note='نتیجه رأی محلی اصلاحیه: '.$final_status;
                $amendment_update=$conn->prepare(
                    "UPDATE community_law_amendment_cases
                     SET status=?,decided_at=NOW(),decision_note=?
                     WHERE id=? AND status='local_voting'"
                );
                if (!$amendment_update) throw new Exception('amendment tally status prepare failed');
                $amendment_id=(int)$record['amendment_case_id'];
                $amendment_update->bind_param('ssi',$next_amendment_status,$decision_note,$amendment_id);
                if (!$amendment_update->execute() || $amendment_update->affected_rows!==1) {
                    throw new Exception('amendment tally status update failed');
                }
                $amendment_update->close();
                $amendment_details=json_encode([
                    'final_status'=>$final_status,'individual_turnout_percent'=>$individual_turnout_percent,
                    'individual_approval_percent'=>$individual_approval_percent,
                    'community_turnout_percent'=>$community_turnout_percent,
                    'community_approval_percent'=>$community_approval_percent,
                    'current_approved_law_unchanged'=>true,
                    'next_stage'=>$next_amendment_status
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $amendment_event=$conn->prepare(
                    "INSERT INTO community_law_amendment_events (case_id,actor_user_id,event_type,details)
                     VALUES (?,?,'local_vote_calculated',?)"
                );
                if (!$amendment_event) throw new Exception('amendment tally event prepare failed');
                $amendment_event->bind_param('iis',$amendment_id,$user_id,$amendment_details);
                if (!$amendment_event->execute()) throw new Exception('amendment tally event failed');
                $amendment_event->close();
            }

            if (!community_law_record_history(
                $conn, $law_id, $version_id, $community_id, $user_id,
                $is_amendment_tally ? 'amendment_local_vote_calculated' : 'voting_result_calculated',
                $is_amendment_tally
                    ? 'نتیجه رأی محلی اصلاحیه ثبت شد؛ قانون مصوب جاری تا پایان بررسی انطباق و تصویب نهایی بدون تغییر باقی می‌ماند.'
                    : 'نتیجه مرکب رأی‌گیری بر اساس قواعد تأییدشده محاسبه شد؛ این نتیجه به‌تنهایی موجب لازم‌الاجرا شدن قانون نیست.'
            )) throw new Exception('tally history failed');

            if (!$conn->commit()) throw new Exception('tally commit failed');
            if ($is_amendment_tally) {
                header('Location: community_law_amendments.php?community_id='.$community_id.'&status=amendment_tally_saved');
                exit;
            }
            community_laws_redirect($community_id, 'tally_saved');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException && $e->getMessage() === 'tally_not_ready') {
                community_laws_redirect($community_id, 'tally_not_ready');
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'tally_rules_missing') {
                community_laws_redirect($community_id, 'voting_rules_required');
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'tally_already_done') {
                community_laws_redirect($community_id, 'tally_already_done');
            }
            if ($e instanceof RuntimeException && $e->getMessage() === 'population_snapshot_required') {
                community_laws_redirect($community_id, 'population_snapshot_required');
            }
            error_log('[Dolate Eshgh law tally] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['cast_law_direct_vote'])) {
        if (!$amendment_schema_ready) community_laws_redirect($community_id, 'schema');
        $version_id = (int)($_POST['version_id'] ?? 0);
        $vote = (string)($_POST['direct_vote'] ?? '');
        $comment = trim((string)($_POST['direct_comment'] ?? ''));
        if ($version_id <= 0 || !in_array($vote, ['yes','no','abstain'], true) || mb_strlen($comment) > 5000) {
            community_laws_redirect($community_id, 'invalid_law_vote');
        }

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $eligible = $conn->prepare(
                "SELECT e.vote_weight,
                        EXISTS(SELECT 1 FROM community_law_amendment_cases a
                               WHERE a.proposed_version_id=e.law_version_id AND a.status='local_voting') AS is_amendment_vote
                 FROM community_law_direct_electorate e
                 JOIN community_law_versions v ON v.id=e.law_version_id
                 JOIN community_laws l ON l.id=v.law_id
                 WHERE e.law_version_id=? AND e.user_id=?
                   AND v.status='voting' AND (
                    l.status='voting' OR (
                        l.status='approved' AND EXISTS (
                            SELECT 1 FROM community_law_amendment_cases a
                            WHERE a.proposed_version_id=v.id AND a.status='local_voting'
                              AND a.base_version_id=l.current_version_id
                        )
                    )
                )
                   AND v.vote_deadline > NOW()
                 LIMIT 1 FOR UPDATE"
            );
            if (!$eligible) throw new Exception('direct vote eligibility prepare failed');
            $eligible->bind_param('ii', $version_id, $user_id);
            if (!$eligible->execute()) throw new Exception('direct vote eligibility failed');
            $eligibility = $eligible->get_result()->fetch_assoc();
            $eligible->close();
            if (!$eligibility) throw new RuntimeException('not_law_elector');

            $vote_stmt = $conn->prepare(
                "INSERT INTO community_law_direct_votes
                    (law_version_id, voter_user_id, vote, vote_weight, comment)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    vote=VALUES(vote), vote_weight=VALUES(vote_weight),
                    comment=VALUES(comment), voted_at=NOW()"
            );
            if (!$vote_stmt) throw new Exception('direct vote insert prepare failed');
            $weight = (float)$eligibility['vote_weight'];
            $vote_stmt->bind_param('iisds', $version_id, $user_id, $vote, $weight, $comment);
            if (!$vote_stmt->execute()) throw new Exception('direct vote insert failed');
            $vote_stmt->close();

            if (!$conn->commit()) throw new Exception('direct vote commit failed');
            if (!empty($eligibility['is_amendment_vote'])) {
                header('Location: community_law_amendments.php?community_id='.$community_id.'&status=amendment_vote_saved');
                exit;
            }
            community_laws_redirect($community_id, 'direct_law_vote_saved');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException && $e->getMessage() === 'not_law_elector') {
                community_laws_redirect($community_id, 'not_law_elector');
            }
            error_log('[Dolate Eshgh direct law vote] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['cast_law_vote'])) {
        if (!$amendment_schema_ready) community_laws_redirect($community_id, 'schema');
        $version_id = (int)($_POST['version_id'] ?? 0);
        $vote = (string)($_POST['vote'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));
        if ($version_id <= 0 || !in_array($vote, ['yes','no','abstain'], true) || mb_strlen($comment) > 5000) {
            community_laws_redirect($community_id, 'invalid_law_vote');
        }

        $eligible = $conn->prepare(
            "SELECT e.vote_weight,
                    EXISTS(SELECT 1 FROM community_law_amendment_cases a
                           WHERE a.proposed_version_id=e.law_version_id AND a.status='local_voting') AS is_amendment_vote
             FROM community_law_electorate e
             JOIN community_law_versions v ON v.id=e.law_version_id
             JOIN community_laws l ON l.id=v.law_id
             WHERE e.law_version_id=? AND e.voting_community_id=? AND e.user_id=?
               AND v.status='voting' AND (
                    l.status='voting' OR (
                        l.status='approved' AND EXISTS (
                            SELECT 1 FROM community_law_amendment_cases a
                            WHERE a.proposed_version_id=v.id AND a.status='local_voting'
                              AND a.base_version_id=l.current_version_id
                        )
                    )
                )
               AND v.vote_deadline > NOW()
             LIMIT 1"
        );
        if (!$eligible) community_laws_redirect($community_id, 'save_error');
        $eligible->bind_param('iii', $version_id, $community_id, $user_id);
        if (!$eligible->execute()) {
            $eligible->close();
            community_laws_redirect($community_id, 'save_error');
        }
        $eligibility = $eligible->get_result()->fetch_assoc();
        $eligible->close();
        if (!$eligibility) community_laws_redirect($community_id, 'not_law_elector');

        $vote_stmt = $conn->prepare(
            "INSERT INTO community_law_votes
                (law_version_id, voting_community_id, voter_user_id, vote, vote_weight, comment)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                vote=VALUES(vote), vote_weight=VALUES(vote_weight),
                comment=VALUES(comment), voted_at=NOW()"
        );
        if (!$vote_stmt) community_laws_redirect($community_id, 'save_error');
        $weight = (float)$eligibility['vote_weight'];
        $vote_stmt->bind_param('iiisds', $version_id, $community_id, $user_id, $vote, $weight, $comment);
        $saved = $vote_stmt->execute();
        $vote_stmt->close();
        if (!$saved) community_laws_redirect($community_id, 'save_error');
        if (!empty($eligibility['is_amendment_vote'])) {
            header('Location: community_law_amendments.php?community_id='.$community_id.'&status=amendment_vote_saved');
            exit;
        }
        community_laws_redirect($community_id, 'law_vote_saved');
    }

    if (isset($_POST['submit_position_discussion'])) {
        if (!$amendment_schema_ready) community_laws_redirect($community_id, 'schema');
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $version_id = (int)($_POST['version_id'] ?? 0);
        $stance = (string)($_POST['stance'] ?? 'neutral');
        $argument = trim((string)($_POST['argument'] ?? ''));
        $evidence = trim((string)($_POST['evidence'] ?? ''));
        if (
            $version_id <= 0 ||
            !in_array($stance, ['support','oppose','neutral'], true) ||
            $argument === '' || mb_strlen($argument) > 20000 ||
            mb_strlen($evidence) > 20000
        ) {
            community_laws_redirect($community_id, 'invalid_discussion');
        }

        $eligible = $conn->prepare(
            "SELECT 1
             FROM community_law_electorate e
             JOIN community_law_versions v ON v.id=e.law_version_id
             JOIN community_laws l ON l.id=v.law_id
             WHERE e.law_version_id=? AND e.voting_community_id=? AND e.user_id=?
               AND v.status='voting' AND (
                    l.status='voting' OR (
                        l.status='approved' AND EXISTS (
                            SELECT 1 FROM community_law_amendment_cases a
                            WHERE a.proposed_version_id=v.id AND a.status='local_voting'
                              AND a.base_version_id=l.current_version_id
                        )
                    )
                )
               AND v.vote_deadline > NOW()
             LIMIT 1"
        );
        if (!$eligible) community_laws_redirect($community_id, 'save_error');
        $eligible->bind_param('iii', $version_id, $community_id, $user_id);
        if (!$eligible->execute()) {
            $eligible->close();
            community_laws_redirect($community_id, 'save_error');
        }
        $is_eligible = (bool)$eligible->get_result()->fetch_row();
        $eligible->close();
        if (!$is_eligible) community_laws_redirect($community_id, 'not_law_elector');

        $discussion = $conn->prepare(
            "INSERT INTO community_law_position_discussions
                (law_version_id, community_id, author_user_id, stance, argument, evidence)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$discussion) community_laws_redirect($community_id, 'save_error');
        $discussion->bind_param(
            'iiisss', $version_id, $community_id, $user_id, $stance, $argument, $evidence
        );
        $saved = $discussion->execute();
        $discussion->close();
        if (!$saved) community_laws_redirect($community_id, 'save_error');

        $law_lookup = $conn->prepare('SELECT law_id FROM community_law_versions WHERE id=? LIMIT 1');
        if ($law_lookup) {
            $law_lookup->bind_param('i', $version_id);
            if ($law_lookup->execute()) {
                $law_ref = $law_lookup->get_result()->fetch_assoc();
                if ($law_ref) {
                    community_law_record_history(
                        $conn, (int)$law_ref['law_id'], $version_id, $community_id,
                        $user_id, 'community_position_discussion',
                        'عضو دیدگاه مستدل خود را درباره موضع جامعه ثبت کرد.'
                    );
                }
            }
            $law_lookup->close();
        }
        community_laws_redirect($community_id, 'discussion_saved');
    }

    if (isset($_POST['submit_law_legal_assessment'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');
        $version_id = (int)($_POST['law_version_id'] ?? 0);
        $dimensions = [
            'charter_compatibility' => (string)($_POST['charter_compatibility'] ?? ''),
            'hierarchy_compatibility' => (string)($_POST['hierarchy_compatibility'] ?? ''),
            'rights_impact' => (string)($_POST['rights_impact'] ?? ''),
            'environmental_impact' => (string)($_POST['environmental_impact'] ?? ''),
            'implementation_feasibility' => (string)($_POST['implementation_feasibility'] ?? '')
        ];
        $allowed_assessments = ['compliant','conflict','unclear'];
        $reasoning = trim((string)($_POST['reasoning'] ?? ''));
        $evidence = trim((string)($_POST['evidence'] ?? ''));
        foreach ($dimensions as $dimension_value) {
            if (!in_array($dimension_value, $allowed_assessments, true)) {
                community_laws_redirect($community_id, 'invalid_legal_assessment');
            }
        }
        if (
            $version_id <= 0 || $reasoning === '' ||
            mb_strlen($reasoning) > 30000 || mb_strlen($evidence) > 30000 ||
            !community_law_scope_matches($conn, $version_id, $community_id)
        ) community_laws_redirect($community_id, 'invalid_legal_assessment');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $case_lock = $conn->prepare(
                "SELECT l.id AS law_id, l.status AS law_status, l.current_version_id,
                        v.status AS version_status, v.proposed_by,
                        rc.status AS review_status, rc.reviewer_quorum
                 FROM community_law_versions v
                 JOIN community_laws l ON l.id=v.law_id
                 JOIN community_law_review_cases rc ON rc.law_version_id=v.id
                 WHERE v.id=? FOR UPDATE"
            );
            if (!$case_lock) throw new Exception('legal assessment lock prepare failed');
            $case_lock->bind_param('i', $version_id);
            if (!$case_lock->execute()) throw new Exception('legal assessment lock failed');
            $case = $case_lock->get_result()->fetch_assoc();
            $case_lock->close();
            if (
                !$case || $case['law_status'] !== 'proposed' ||
                $case['version_status'] !== 'proposed' ||
                (int)$case['current_version_id'] !== $version_id ||
                (int)$case['proposed_by'] === $user_id ||
                $case['review_status'] !== 'pending'
            ) throw new RuntimeException('legal_assessment_not_allowed');

            $eligible_stmt = $conn->prepare(
                "SELECT 1 FROM community_law_review_electorate
                 WHERE law_version_id=? AND user_id=? LIMIT 1"
            );
            if (!$eligible_stmt) throw new Exception('legal assessor eligibility prepare failed');
            $eligible_stmt->bind_param('ii', $version_id, $user_id);
            if (!$eligible_stmt->execute()) throw new Exception('legal assessor eligibility lookup failed');
            $is_eligible_reviewer = (bool)$eligible_stmt->get_result()->fetch_row();
            $eligible_stmt->close();
            if (!$is_eligible_reviewer) throw new RuntimeException('legal_assessment_not_allowed');

            $assessment_insert = $conn->prepare(
                "INSERT INTO community_law_review_assessments
                    (law_version_id, reviewer_user_id, charter_compatibility,
                     hierarchy_compatibility, rights_impact, environmental_impact,
                     implementation_feasibility, reasoning, evidence)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$assessment_insert) throw new Exception('legal assessment insert prepare failed');
            $assessment_insert->bind_param(
                'iisssssss', $version_id, $user_id,
                $dimensions['charter_compatibility'],
                $dimensions['hierarchy_compatibility'],
                $dimensions['rights_impact'],
                $dimensions['environmental_impact'],
                $dimensions['implementation_feasibility'],
                $reasoning, $evidence
            );
            if (!$assessment_insert->execute()) throw new Exception('legal assessment insert failed');
            $assessment_insert->close();

            $aggregate_stmt = $conn->prepare(
                "SELECT COUNT(*) AS reviewer_count,
                        COALESCE(SUM(
                            charter_compatibility='conflict' OR
                            hierarchy_compatibility='conflict' OR
                            rights_impact='conflict' OR
                            environmental_impact='conflict' OR
                            implementation_feasibility='conflict'
                        ),0) AS conflict_count,
                        COALESCE(SUM(
                            charter_compatibility='unclear' OR
                            hierarchy_compatibility='unclear' OR
                            rights_impact='unclear' OR
                            environmental_impact='unclear' OR
                            implementation_feasibility='unclear'
                        ),0) AS unclear_count
                 FROM community_law_review_assessments WHERE law_version_id=?"
            );
            if (!$aggregate_stmt) throw new Exception('legal assessment aggregate prepare failed');
            $aggregate_stmt->bind_param('i', $version_id);
            if (!$aggregate_stmt->execute()) throw new Exception('legal assessment aggregate failed');
            $aggregate = $aggregate_stmt->get_result()->fetch_assoc();
            $aggregate_stmt->close();

            $review_status = 'pending';
            if ((int)$aggregate['reviewer_count'] >= (int)$case['reviewer_quorum']) {
                if ((int)$aggregate['conflict_count'] > 0) $review_status = 'conflict_found';
                elseif ((int)$aggregate['unclear_count'] > 0) $review_status = 'needs_revision';
                else $review_status = 'screening_clear';
            }

            $case_update = $conn->prepare(
                "UPDATE community_law_review_cases SET status=? WHERE law_version_id=? AND status='pending'"
            );
            if (!$case_update) throw new Exception('legal review status update prepare failed');
            $case_update->bind_param('si', $review_status, $version_id);
            if (!$case_update->execute()) throw new Exception('legal review status update failed');
            $case_update->close();

            // ایجاد پرونده اجرای اولیه برای تمام جوامع مشمول، بخشی از خود ابلاغ است.
            // در صورت شکست حتی یک رکورد، کل تراکنش ابلاغ rollback می‌شود.
            if (!community_law_initialize_implementations(
                $conn,
                (int)$law['law_id'],
                $version_id,
                $user_id,
                $implementation_community_ids
            )) throw new Exception('implementation initialization failed');

            if (!community_law_record_history(
                $conn, (int)$case['law_id'], $version_id, $community_id, $user_id,
                'legal_screening_assessment',
                'یک ارزیابی مستقل ثبت شد؛ وضعیت غربالگری: ' . $review_status
            )) throw new Exception('legal assessment history failed');
            if (!$conn->commit()) throw new Exception('legal assessment commit failed');
            community_laws_redirect($community_id, 'legal_assessment_saved');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException && $e->getMessage() === 'legal_assessment_not_allowed') {
                community_laws_redirect($community_id, 'legal_assessment_not_allowed');
            }
            error_log('[Dolate Eshgh legal assessment] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['submit_law_authority_assessment'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $version_id = (int)($_POST['law_version_id'] ?? 0);
        $authority_verdict = (string)($_POST['authority_verdict'] ?? '');
        $reasoning = trim((string)($_POST['authority_reasoning'] ?? ''));
        $evidence = trim((string)($_POST['authority_evidence'] ?? ''));
        $precedent_values = $_POST['precedent_assessment'] ?? [];
        $precedent_reasons = $_POST['precedent_reasoning'] ?? [];
        $precedent_evidence = $_POST['precedent_evidence'] ?? [];

        if (
            $version_id <= 0 ||
            !in_array($authority_verdict, ['confirm','dispute','unclear'], true) ||
            $reasoning === '' || mb_strlen($reasoning) > 30000 ||
            mb_strlen($evidence) > 30000 ||
            !is_array($precedent_values) || !is_array($precedent_reasons) ||
            !is_array($precedent_evidence) ||
            !community_law_scope_matches($conn, $version_id, $community_id)
        ) community_laws_redirect($community_id, 'invalid_authority_assessment');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $case_lock = $conn->prepare(
                "SELECT l.id AS law_id, l.status AS law_status, l.current_version_id,
                        v.status AS version_status, v.proposed_by,
                        rc.status AS screening_status,
                        ac.status AS authority_status, ac.reviewer_quorum,
                        ac.derived_jurisdiction_level
                 FROM community_law_versions v
                 JOIN community_laws l ON l.id=v.law_id
                 JOIN community_law_review_cases rc ON rc.law_version_id=v.id
                 JOIN community_law_authority_cases ac ON ac.law_version_id=v.id
                 WHERE v.id=? FOR UPDATE"
            );
            if (!$case_lock) throw new Exception('authority assessment lock prepare failed');
            $case_lock->bind_param('i', $version_id);
            if (!$case_lock->execute()) throw new Exception('authority assessment lock failed');
            $case = $case_lock->get_result()->fetch_assoc();
            $case_lock->close();

            if (
                !$case || $case['law_status'] !== 'proposed' ||
                $case['version_status'] !== 'proposed' ||
                (int)$case['current_version_id'] !== $version_id ||
                (int)$case['proposed_by'] === $user_id ||
                $case['screening_status'] !== 'screening_clear' ||
                $case['authority_status'] !== 'pending'
            ) throw new RuntimeException('authority_assessment_not_allowed');

            $eligible_stmt = $conn->prepare(
                "SELECT 1 FROM community_law_review_electorate
                 WHERE law_version_id=? AND user_id=? LIMIT 1"
            );
            if (!$eligible_stmt) throw new Exception('authority reviewer eligibility prepare failed');
            $eligible_stmt->bind_param('ii', $version_id, $user_id);
            if (!$eligible_stmt->execute()) throw new Exception('authority reviewer eligibility lookup failed');
            $is_eligible_reviewer = (bool)$eligible_stmt->get_result()->fetch_row();
            $eligible_stmt->close();
            if (!$is_eligible_reviewer) throw new RuntimeException('authority_assessment_not_allowed');

            $reference_stmt = $conn->prepare(
                "SELECT id, reference_law_id, reference_law_version_id,
                        reference_origin_community_id, relation_type
                 FROM community_law_authority_references
                 WHERE law_version_id=? ORDER BY relation_type, reference_law_id"
            );
            if (!$reference_stmt) throw new Exception('authority references prepare failed');
            $reference_stmt->bind_param('i', $version_id);
            if (!$reference_stmt->execute()) throw new Exception('authority references lookup failed');
            $reference_result = $reference_stmt->get_result();
            $references = [];
            while ($reference_row = $reference_result->fetch_assoc()) {
                $references[(int)$reference_row['id']] = $reference_row;
            }
            $reference_stmt->close();

            if (count($precedent_values) !== count($references)) {
                throw new RuntimeException('authority_assessment_not_allowed');
            }
            foreach ($references as $reference_id => $reference_row) {
                $verdict = $precedent_values[$reference_id] ?? '';
                $reference_reason = trim((string)($precedent_reasons[$reference_id] ?? ''));
                $reference_evidence = trim((string)($precedent_evidence[$reference_id] ?? ''));
                if (
                    !in_array($verdict, ['compatible','conflict','unclear'], true) ||
                    $reference_reason === '' ||
                    mb_strlen($reference_reason) > 30000 ||
                    mb_strlen($reference_evidence) > 30000
                ) throw new RuntimeException('invalid_authority_assessment');
            }

            $authority_insert = $conn->prepare(
                "INSERT INTO community_law_authority_assessments
                    (law_version_id, reviewer_user_id, authority_verdict, reasoning, evidence)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$authority_insert) throw new Exception('authority assessment insert prepare failed');
            $authority_insert->bind_param(
                'iisss', $version_id, $user_id, $authority_verdict, $reasoning, $evidence
            );
            if (!$authority_insert->execute()) throw new Exception('authority assessment insert failed');
            $authority_insert->close();

            $precedent_insert = $conn->prepare(
                "INSERT INTO community_law_precedent_assessments
                    (reference_id, law_version_id, reviewer_user_id,
                     compatibility_verdict, reasoning, evidence)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if (!$precedent_insert) throw new Exception('precedent assessment insert prepare failed');
            foreach ($references as $reference_id => $reference_row) {
                $verdict = (string)$precedent_values[$reference_id];
                $reference_reason = trim((string)$precedent_reasons[$reference_id]);
                $reference_evidence = trim((string)($precedent_evidence[$reference_id] ?? ''));
                $precedent_insert->bind_param(
                    'iiisss', $reference_id, $version_id, $user_id,
                    $verdict, $reference_reason, $reference_evidence
                );
                if (!$precedent_insert->execute()) throw new Exception('precedent assessment insert failed');
            }
            $precedent_insert->close();

            $aggregate_stmt = $conn->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM community_law_authority_assessments
                     WHERE law_version_id=?) AS reviewer_count,
                    (SELECT COUNT(*) FROM community_law_authority_assessments
                     WHERE law_version_id=? AND authority_verdict='dispute') AS authority_conflicts,
                    (SELECT COUNT(*) FROM community_law_authority_assessments
                     WHERE law_version_id=? AND authority_verdict='unclear') AS authority_unclear,
                    (SELECT COUNT(*) FROM community_law_precedent_assessments
                     WHERE law_version_id=? AND compatibility_verdict='conflict') AS precedent_conflicts,
                    (SELECT COUNT(*) FROM community_law_precedent_assessments
                     WHERE law_version_id=? AND compatibility_verdict='unclear') AS precedent_unclear,
                    (SELECT COUNT(*) FROM community_law_authority_assessments aa
                     WHERE aa.law_version_id=?
                       AND (SELECT COUNT(*) FROM community_law_precedent_assessments pa
                            WHERE pa.law_version_id=aa.law_version_id
                              AND pa.reviewer_user_id=aa.reviewer_user_id)=
                           (SELECT COUNT(*) FROM community_law_authority_references ar
                            WHERE ar.law_version_id=aa.law_version_id)
                    ) AS completed_reviewers"
            );
            if (!$aggregate_stmt) throw new Exception('authority aggregate prepare failed');
            $aggregate_stmt->bind_param(
                'iiiiii', $version_id, $version_id, $version_id,
                $version_id, $version_id, $version_id
            );
            if (!$aggregate_stmt->execute()) throw new Exception('authority aggregate failed');
            $aggregate = $aggregate_stmt->get_result()->fetch_assoc();
            $aggregate_stmt->close();

            $authority_status = 'pending';
            if ((int)$aggregate['completed_reviewers'] >= (int)$case['reviewer_quorum']) {
                if (
                    (int)$aggregate['authority_conflicts'] > 0 ||
                    (int)$aggregate['precedent_conflicts'] > 0
                ) $authority_status = 'conflict_found';
                elseif (
                    (int)$aggregate['authority_unclear'] > 0 ||
                    (int)$aggregate['precedent_unclear'] > 0
                ) $authority_status = 'needs_revision';
                else $authority_status = 'confirmed';
            }

            $case_update = $conn->prepare(
                "UPDATE community_law_authority_cases
                 SET status=? WHERE law_version_id=? AND status='pending'"
            );
            if (!$case_update) throw new Exception('authority status update prepare failed');
            $case_update->bind_param('si', $authority_status, $version_id);
            if (!$case_update->execute()) throw new Exception('authority status update failed');
            $case_update->close();

            if (!community_law_record_history(
                $conn, (int)$case['law_id'], $version_id, $community_id, $user_id,
                'authority_and_precedent_assessment',
                'ارزیابی مستقل صلاحیت و قوانین مرتبط ثبت شد؛ سطح تشخیص‌داده‌شده: ' .
                $case['derived_jurisdiction_level'] . '؛ وضعیت: ' . $authority_status
            )) throw new Exception('authority assessment history failed');

            if (!$conn->commit()) throw new Exception('authority assessment commit failed');
            community_laws_redirect($community_id, 'authority_assessment_saved');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException) {
                if ($e->getMessage() === 'authority_assessment_not_allowed') {
                    community_laws_redirect($community_id, 'authority_assessment_not_allowed');
                }
                if ($e->getMessage() === 'invalid_authority_assessment') {
                    community_laws_redirect($community_id, 'invalid_authority_assessment');
                }
            }
            error_log('[Dolate Eshgh authority assessment] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }


    if (isset($_POST['open_law_final_review'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');
        $law_id = (int)($_POST['law_id'] ?? 0);
        $version_id = (int)($_POST['version_id'] ?? 0);
        if ($law_id <= 0 || $version_id <= 0) community_laws_redirect($community_id, 'invalid_final_review');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            // قفل نقشه سلسله‌مراتب پیش از پرونده قانون؛ هم‌راستا با مسیر تغییر والد.
            // این ترتیب، snapshot قوانین مرجع را با تغییرات هم‌زمان والد/فرزند سریالی می‌کند.
            $hierarchy_lock = $conn->query(
                "SELECT id, parent_community_id FROM communities ORDER BY id FOR UPDATE"
            );
            if (!$hierarchy_lock) throw new Exception('lock hierarchy for final review failed');
            $hierarchy_lock->free();

            $lock = $conn->prepare(
                "SELECT l.status, l.current_version_id, l.originating_community_id,
                        v.status AS version_status, v.proposed_by, fr.final_status
                 FROM community_laws l
                 JOIN community_law_versions v ON v.law_id=l.id
                 JOIN community_law_final_results fr ON fr.law_version_id=v.id
                 WHERE l.id=? AND v.id=? AND l.originating_community_id=?
                 FOR UPDATE"
            );
            if (!$lock) throw new Exception('final review open lock prepare failed');
            $lock->bind_param('iii', $law_id, $version_id, $community_id);
            if (!$lock->execute()) throw new Exception('final review open lock failed');
            $law = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (
                !$law || $law['status'] !== 'voting' || $law['version_status'] !== 'voting' ||
                (int)$law['current_version_id'] !== $version_id || $law['final_status'] !== 'vote_passed'
            ) throw new RuntimeException('final_review_not_ready');

            $exists = $conn->prepare("SELECT 1 FROM community_law_final_review_cases WHERE law_version_id=? LIMIT 1");
            if (!$exists) throw new Exception('final review existence prepare failed');
            $exists->bind_param('i', $version_id);
            if (!$exists->execute()) throw new Exception('final review existence lookup failed');
            $has_case = (bool)$exists->get_result()->fetch_row();
            $exists->close();
            if ($has_case) throw new RuntimeException('final_review_already_open');

            // فهرست ارزیابان نهایی از اعضای snapshot قبلی ساخته می‌شود،
            // اما پیشنهاددهنده و همه ارزیابان مراحل قبلی کنار گذاشته می‌شوند.
            $final_review_scope_ids = community_law_scope_community_ids($conn, $version_id);
            if (!$final_review_scope_ids) throw new RuntimeException('final_review_not_ready');
            $electorate_insert = $conn->prepare(
                "INSERT IGNORE INTO community_law_final_review_electorate (law_version_id, user_id)
                 SELECT ?, cm.user_id
                 FROM community_members cm
                 WHERE cm.community_id=? AND cm.user_id<>?
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_review_assessments a
                       WHERE a.law_version_id=? AND a.reviewer_user_id=cm.user_id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_authority_assessments a
                       WHERE a.law_version_id=? AND a.reviewer_user_id=cm.user_id
                   )"
            );
            if (!$electorate_insert) throw new Exception('final review electorate prepare failed');
            foreach ($final_review_scope_ids as $review_community_id) {
                $electorate_insert->bind_param(
                    'iiiii', $version_id, $review_community_id, $law['proposed_by'],
                    $version_id, $version_id
                );
                if (!$electorate_insert->execute()) throw new Exception('final review electorate snapshot failed');
            }
            $electorate_insert->close();

            $capacity = $conn->prepare("SELECT COUNT(*) AS total FROM community_law_final_review_electorate WHERE law_version_id=?");
            if (!$capacity) throw new Exception('final review capacity prepare failed');
            $capacity->bind_param('i', $version_id);
            if (!$capacity->execute()) throw new Exception('final review capacity lookup failed');
            $reviewer_count = (int)$capacity->get_result()->fetch_assoc()['total'];
            $capacity->close();
            if ($reviewer_count < 3) throw new RuntimeException('insufficient_final_reviewers');

            // نسخه قوانین مرجع باید هنوز همان نسخه معتبر زمان بررسی صلاحیت باشد.
            $references = $conn->prepare(
                "SELECT ar.reference_law_id, ar.reference_law_version_id,
                        rl.current_version_id, rl.status AS reference_status,
                        rv.status AS reference_version_status,
                        rl.effective_at, rl.expires_at, rl.repealed_at
                 FROM community_law_authority_references ar
                 JOIN community_laws rl ON rl.id=ar.reference_law_id
                 JOIN community_law_versions rv ON rv.id=ar.reference_law_version_id
                 WHERE ar.law_version_id=?"
            );
            if (!$references) throw new Exception('final review references prepare failed');
            $references->bind_param('i', $version_id);
            if (!$references->execute()) throw new Exception('final review references lookup failed');
            $reference_rows = $references->get_result();
            $reference_insert = $conn->prepare(
                "INSERT INTO community_law_final_review_references
                    (law_version_id, reference_law_id, reference_law_version_id,
                     reference_origin_community_id, relation_type, scope_fingerprint)
                 SELECT ?, ar.reference_law_id, ar.reference_law_version_id,
                        ar.reference_origin_community_id, ar.relation_type, ?
                 FROM community_law_authority_references ar
                 WHERE ar.law_version_id=? AND ar.reference_law_version_id=?"
            );
            if (!$reference_insert) throw new Exception('final review reference insert prepare failed');
            while ($reference = $reference_rows->fetch_assoc()) {
                if (
                    (int)$reference['current_version_id'] !== (int)$reference['reference_law_version_id'] ||
                    $reference['reference_status'] !== 'approved' ||
                    $reference['reference_version_status'] !== 'approved' ||
                    !empty($reference['repealed_at']) ||
                    (!empty($reference['effective_at']) && strtotime($reference['effective_at']) > time()) ||
                    (!empty($reference['expires_at']) && strtotime($reference['expires_at']) <= time())
                ) throw new RuntimeException('reference_law_changed');
                $scope_stmt = $conn->prepare(
                    "SELECT scope_community_id, include_descendants
                     FROM community_law_scopes WHERE law_version_id=?
                     ORDER BY scope_community_id"
                );
                if (!$scope_stmt) throw new Exception('reference scope fingerprint prepare failed');
                $reference_version_id = (int)$reference['reference_law_version_id'];
                $scope_stmt->bind_param('i', $reference_version_id);
                if (!$scope_stmt->execute()) throw new Exception('reference scope fingerprint lookup failed');
                $scope_rows = [];
                $scope_result = $scope_stmt->get_result();
                while ($scope_row = $scope_result->fetch_assoc()) $scope_rows[] = $scope_row;
                $scope_stmt->close();
                if (!$scope_rows) throw new RuntimeException('reference_law_changed');
                $fingerprint = hash('sha256', json_encode($scope_rows));
                $reference_law_id = (int)$reference['reference_law_id'];
                $reference_insert->bind_param('isii', $version_id, $fingerprint, $version_id, $reference_version_id);
                if (!$reference_insert->execute()) throw new Exception('final review reference snapshot failed');
            }
            $references->close();
            $reference_insert->close();

            // منشور بنیادین و همه مواد نسخه لازم‌الاجرا پیش از بررسی نهایی snapshot می‌شوند.
            if (!community_law_capture_charter_snapshot($conn, $version_id)) {
                throw new RuntimeException('charter_snapshot_unavailable');
            }

            $case_insert = $conn->prepare(
                "INSERT INTO community_law_final_review_cases
                    (law_version_id, status, reviewer_quorum, appeal_period_days, opened_by)
                 VALUES (?, 'pending', 3, 7, ?)"
            );
            if (!$case_insert) throw new Exception('final review case insert prepare failed');
            $case_insert->bind_param('ii', $version_id, $user_id);
            if (!$case_insert->execute()) throw new Exception('final review case insert failed');
            $case_insert->close();

            $law_update = $conn->prepare(
                "UPDATE community_laws SET status='pending_final_review' WHERE id=? AND status='voting'"
            );
            $version_update = $conn->prepare(
                "UPDATE community_law_versions SET status='pending_final_review' WHERE id=? AND status='voting'"
            );
            if (!$law_update || !$version_update) throw new Exception('final review status prepare failed');
            $law_update->bind_param('i', $law_id);
            $version_update->bind_param('i', $version_id);
            if (!$law_update->execute() || $law_update->affected_rows !== 1) throw new Exception('final review law status update failed');
            if (!$version_update->execute() || $version_update->affected_rows !== 1) throw new Exception('final review version status update failed');
            $law_update->close();
            $version_update->close();

            if (!community_law_record_history(
                $conn, $law_id, $version_id, $community_id, $user_id,
                'final_review_opened',
                'نتیجه رأی‌گیری مثبت بود؛ بررسی نهایی مستقل با ارزیابانی جدا از مراحل قبلی آغاز شد.'
            )) throw new Exception('final review history failed');
            if (!$conn->commit()) throw new Exception('final review open commit failed');
            community_laws_redirect($community_id, 'final_review_opened');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException) {
                if ($e->getMessage() === 'final_review_not_ready') community_laws_redirect($community_id, 'final_review_not_ready');
                if ($e->getMessage() === 'final_review_already_open') community_laws_redirect($community_id, 'final_review_already_open');
                if ($e->getMessage() === 'insufficient_final_reviewers') community_laws_redirect($community_id, 'insufficient_final_reviewers');
                if ($e->getMessage() === 'reference_law_changed') community_laws_redirect($community_id, 'reference_law_changed');
            }
            error_log('[Dolate Eshgh final review open] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['submit_law_final_assessment'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');
        $version_id = (int)($_POST['law_version_id'] ?? 0);
        $verdict = (string)($_POST['final_verdict'] ?? '');
        $reasoning = trim((string)($_POST['final_reasoning'] ?? ''));
        $evidence = trim((string)($_POST['final_evidence'] ?? ''));
        $dimensions = [
            'charter_and_rights' => (string)($_POST['charter_and_rights'] ?? ''),
            'non_discrimination' => (string)($_POST['non_discrimination'] ?? ''),
            'power_concentration' => (string)($_POST['power_concentration'] ?? ''),
            'human_and_social_impact' => (string)($_POST['human_and_social_impact'] ?? ''),
            'environmental_impact' => (string)($_POST['environmental_impact'] ?? ''),
            'economic_and_resource_impact' => (string)($_POST['economic_and_resource_impact'] ?? ''),
            'future_generations_and_other_life' => (string)($_POST['future_generations_and_other_life'] ?? ''),
            'implementation_and_accountability' => (string)($_POST['implementation_and_accountability'] ?? '')
        ];
        if (
            $version_id <= 0 || !in_array($verdict, ['confirm','dispute','unclear'], true) ||
            $reasoning === '' || mb_strlen($reasoning) > 30000 || mb_strlen($evidence) > 30000
        ) community_laws_redirect($community_id, 'invalid_final_assessment');
        foreach ($dimensions as $value) {
            if (!in_array($value, ['pass','conflict','unclear'], true)) {
                community_laws_redirect($community_id, 'invalid_final_assessment');
            }
        }

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $case_lock = $conn->prepare(
                "SELECT l.id AS law_id, l.status AS law_status, l.current_version_id,
                        v.status AS version_status, v.proposed_by,
                        fc.status AS final_status, fc.reviewer_quorum, fc.appeal_period_days
                 FROM community_law_versions v
                 JOIN community_laws l ON l.id=v.law_id
                 JOIN community_law_final_review_cases fc ON fc.law_version_id=v.id
                 WHERE v.id=? FOR UPDATE"
            );
            if (!$case_lock) throw new Exception('final assessment lock prepare failed');
            $case_lock->bind_param('i', $version_id);
            if (!$case_lock->execute()) throw new Exception('final assessment lock failed');
            $case = $case_lock->get_result()->fetch_assoc();
            $case_lock->close();
            if (
                !$case || $case['law_status'] !== 'pending_final_review' ||
                $case['version_status'] !== 'pending_final_review' ||
                (int)$case['current_version_id'] !== $version_id ||
                $case['final_status'] !== 'pending' ||
                (int)$case['proposed_by'] === $user_id
            ) throw new RuntimeException('final_assessment_not_allowed');

            $eligible = $conn->prepare(
                "SELECT 1 FROM community_law_final_review_electorate
                 WHERE law_version_id=? AND user_id=? LIMIT 1"
            );
            if (!$eligible) throw new Exception('final assessment eligibility prepare failed');
            $eligible->bind_param('ii', $version_id, $user_id);
            if (!$eligible->execute()) throw new Exception('final assessment eligibility lookup failed');
            $is_eligible = (bool)$eligible->get_result()->fetch_row();
            $eligible->close();
            if (!$is_eligible) throw new RuntimeException('final_assessment_not_allowed');

            $insert = $conn->prepare(
                "INSERT INTO community_law_final_review_assessments
                    (law_version_id, reviewer_user_id, verdict, charter_and_rights,
                     non_discrimination, power_concentration, human_and_social_impact,
                     environmental_impact, economic_and_resource_impact,
                     future_generations_and_other_life, implementation_and_accountability,
                     reasoning, evidence)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$insert) throw new Exception('final assessment insert prepare failed');
            $insert->bind_param(
                'iisssssssssss',
                $version_id, $user_id, $verdict,
                $dimensions['charter_and_rights'], $dimensions['non_discrimination'],
                $dimensions['power_concentration'], $dimensions['human_and_social_impact'],
                $dimensions['environmental_impact'], $dimensions['economic_and_resource_impact'],
                $dimensions['future_generations_and_other_life'],
                $dimensions['implementation_and_accountability'], $reasoning, $evidence
            );
            if (!$insert->execute()) throw new Exception('final assessment insert failed');
            $insert->close();

            $aggregate = $conn->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN verdict='dispute' OR
                            charter_and_rights='conflict' OR non_discrimination='conflict' OR
                            power_concentration='conflict' OR human_and_social_impact='conflict' OR
                            environmental_impact='conflict' OR economic_and_resource_impact='conflict' OR
                            future_generations_and_other_life='conflict' OR
                            implementation_and_accountability='conflict' THEN 1 ELSE 0 END) AS conflicts,
                        SUM(CASE WHEN verdict='unclear' OR
                            charter_and_rights='unclear' OR non_discrimination='unclear' OR
                            power_concentration='unclear' OR human_and_social_impact='unclear' OR
                            environmental_impact='unclear' OR economic_and_resource_impact='unclear' OR
                            future_generations_and_other_life='unclear' OR
                            implementation_and_accountability='unclear' THEN 1 ELSE 0 END) AS unclear_count
                 FROM community_law_final_review_assessments WHERE law_version_id=?"
            );
            if (!$aggregate) throw new Exception('final assessment aggregate prepare failed');
            $aggregate->bind_param('i', $version_id);
            if (!$aggregate->execute()) throw new Exception('final assessment aggregate failed');
            $totals = $aggregate->get_result()->fetch_assoc();
            $aggregate->close();

            $new_status = 'pending';
            $appeal_deadline = null;
            if ((int)$totals['total'] >= (int)$case['reviewer_quorum']) {
                if ((int)$totals['conflicts'] > 0) $new_status = 'conflict_found';
                elseif ((int)$totals['unclear_count'] > 0) $new_status = 'needs_revision';
                else {
                    $new_status = 'appeal_period';
                    $appeal_deadline = date('Y-m-d H:i:s', time() + ((int)$case['appeal_period_days'] * 86400));
                }
            }
            $case_update = $conn->prepare(
                "UPDATE community_law_final_review_cases
                 SET status=?, appeal_deadline=?, review_completed_at=CASE WHEN ?='pending' THEN NULL ELSE NOW() END
                 WHERE law_version_id=? AND status='pending'"
            );
            if (!$case_update) throw new Exception('final review status update prepare failed');
            $case_update->bind_param('sssi', $new_status, $appeal_deadline, $new_status, $version_id);
            if (!$case_update->execute()) throw new Exception('final review status update failed');
            $case_update->close();

            if ($new_status === 'appeal_period') {
                $law_update = $conn->prepare("UPDATE community_laws SET status='appeal_period' WHERE id=? AND status='pending_final_review'");
                $version_update = $conn->prepare("UPDATE community_law_versions SET status='appeal_period' WHERE id=? AND status='pending_final_review'");
                if (!$law_update || !$version_update) throw new Exception('appeal period status prepare failed');
                $law_update->bind_param('i', $case['law_id']);
                $version_update->bind_param('i', $version_id);
                if (!$law_update->execute() || !$version_update->execute()) throw new Exception('appeal period status update failed');
                $law_update->close();
                $version_update->close();
            }

            if (!community_law_record_history(
                $conn, (int)$case['law_id'], $version_id, $community_id, $user_id,
                'final_review_assessment',
                'ارزیابی نهایی مستقل ثبت شد؛ وضعیت پرونده: ' . $new_status
            )) throw new Exception('final assessment history failed');
            if (!$conn->commit()) throw new Exception('final assessment commit failed');
            community_laws_redirect($community_id, 'final_assessment_saved');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException && $e->getMessage() === 'final_assessment_not_allowed') {
                community_laws_redirect($community_id, 'final_assessment_not_allowed');
            }
            error_log('[Dolate Eshgh final assessment] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['submit_law_appeal'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');
        $version_id = (int)($_POST['law_version_id'] ?? 0);
        $appeal_type = (string)($_POST['appeal_type'] ?? 'other');
        $description = trim((string)($_POST['appeal_description'] ?? ''));
        $evidence = trim((string)($_POST['appeal_evidence'] ?? ''));
        $allowed = [
            'charter_conflict','jurisdiction_conflict','rights_violation','discrimination',
            'power_concentration','environmental_harm','future_generations',
            'implementation_risk','new_evidence','other'
        ];
        if (
            $version_id <= 0 || !in_array($appeal_type, $allowed, true) ||
            $description === '' || mb_strlen($description) > 30000 || mb_strlen($evidence) > 30000 ||
            !community_law_scope_matches($conn, $version_id, $community_id)
        ) community_laws_redirect($community_id, 'invalid_appeal');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $lock = $conn->prepare(
                "SELECT l.id AS law_id, l.status, l.current_version_id,
                        v.status AS version_status, fc.status AS final_status,
                        fc.appeal_deadline
                 FROM community_laws l
                 JOIN community_law_versions v ON v.law_id=l.id
                 JOIN community_law_final_review_cases fc ON fc.law_version_id=v.id
                 WHERE v.id=? FOR UPDATE"
            );
            if (!$lock) throw new Exception('appeal lock prepare failed');
            $lock->bind_param('i', $version_id);
            if (!$lock->execute()) throw new Exception('appeal lock failed');
            $law = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (
                !$law || $law['status'] !== 'appeal_period' ||
                $law['version_status'] !== 'appeal_period' ||
                $law['final_status'] !== 'appeal_period' ||
                (int)$law['current_version_id'] !== $version_id ||
                empty($law['appeal_deadline']) || strtotime($law['appeal_deadline']) < time()
            ) throw new RuntimeException('appeal_period_closed');

            $insert = $conn->prepare(
                "INSERT INTO community_law_appeals
                    (law_version_id, community_id, submitted_by, appeal_type, description, evidence)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if (!$insert) throw new Exception('appeal insert prepare failed');
            $insert->bind_param('iiisss', $version_id, $community_id, $user_id, $appeal_type, $description, $evidence);
            if (!$insert->execute()) throw new Exception('appeal insert failed');
            $appeal_id = (int)$conn->insert_id;
            $insert->close();

            $appeal_scope_ids = community_law_scope_community_ids($conn, $version_id);
            if (!$appeal_scope_ids) throw new RuntimeException('appeal_period_closed');
            $electorate = $conn->prepare(
                "INSERT IGNORE INTO community_law_appeal_electorate (appeal_id, user_id)
                 SELECT ?, cm.user_id FROM community_members cm
                 WHERE cm.community_id=? AND cm.user_id<>?
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_final_review_assessments a
                       WHERE a.law_version_id=? AND a.reviewer_user_id=cm.user_id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_review_assessments a
                       WHERE a.law_version_id=? AND a.reviewer_user_id=cm.user_id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_authority_assessments a
                       WHERE a.law_version_id=? AND a.reviewer_user_id=cm.user_id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_versions v
                       WHERE v.id=? AND v.proposed_by=cm.user_id
                   )"
            );
            if (!$electorate) throw new Exception('appeal electorate prepare failed');
            foreach ($appeal_scope_ids as $appeal_community_id) {
                $electorate->bind_param(
                    'iiiiiii', $appeal_id, $appeal_community_id, $user_id,
                    $version_id, $version_id, $version_id, $version_id
                );
                if (!$electorate->execute()) throw new Exception('appeal electorate snapshot failed');
            }
            $electorate->close();
            $capacity = $conn->prepare("SELECT COUNT(*) AS total FROM community_law_appeal_electorate WHERE appeal_id=?");
            if (!$capacity) throw new Exception('appeal electorate count prepare failed');
            $capacity->bind_param('i', $appeal_id);
            if (!$capacity->execute()) throw new Exception('appeal electorate count failed');
            $appeal_capacity = (int)$capacity->get_result()->fetch_assoc()['total'];
            $capacity->close();
            if ($appeal_capacity < 3) throw new RuntimeException('insufficient_appeal_reviewers');

            $hold = $conn->prepare(
                "UPDATE community_law_final_review_cases SET status='appeal_hold'
                 WHERE law_version_id=? AND status='appeal_period'"
            );
            if (!$hold) throw new Exception('appeal hold prepare failed');
            $hold->bind_param('i', $version_id);
            if (!$hold->execute() || $hold->affected_rows !== 1) throw new Exception('appeal hold update failed');
            $hold->close();

            $version_law_stmt = $conn->prepare("SELECT law_id FROM community_law_versions WHERE id=?");
            if (!$version_law_stmt) throw new Exception('appeal history law lookup prepare failed');
            $version_law_stmt->bind_param('i', $version_id);
            if (!$version_law_stmt->execute()) throw new Exception('appeal history law lookup failed');
            $law_id_row = $version_law_stmt->get_result()->fetch_assoc();
            $version_law_stmt->close();
            if (!community_law_record_history(
                $conn, (int)$law_id_row['law_id'], $version_id, $community_id, $user_id,
                'law_appeal_submitted', $description
            )) throw new Exception('appeal history failed');
            if (!$conn->commit()) throw new Exception('appeal commit failed');
            community_laws_redirect($community_id, 'appeal_saved');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException) {
                if ($e->getMessage() === 'appeal_period_closed') community_laws_redirect($community_id, 'appeal_period_closed');
                if ($e->getMessage() === 'insufficient_appeal_reviewers') community_laws_redirect($community_id, 'insufficient_appeal_reviewers');
            }
            error_log('[Dolate Eshgh law appeal] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['submit_law_appeal_assessment'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');
        $appeal_id = (int)($_POST['appeal_id'] ?? 0);
        $verdict = (string)($_POST['appeal_verdict'] ?? '');
        $reasoning = trim((string)($_POST['appeal_reasoning'] ?? ''));
        $evidence = trim((string)($_POST['appeal_review_evidence'] ?? ''));
        if (
            $appeal_id <= 0 || !in_array($verdict, ['uphold','dismiss','unclear'], true) ||
            $reasoning === '' || mb_strlen($reasoning) > 30000 || mb_strlen($evidence) > 30000
        ) community_laws_redirect($community_id, 'invalid_appeal_assessment');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $lock = $conn->prepare(
                "SELECT a.law_version_id, a.status AS appeal_status, a.submitted_by,
                        a.reviewer_quorum, v.law_id, l.status AS law_status,
                        l.current_version_id, v.status AS version_status,
                        fc.status AS final_status
                 FROM community_law_appeals a
                 JOIN community_law_versions v ON v.id=a.law_version_id
                 JOIN community_laws l ON l.id=v.law_id
                 JOIN community_law_final_review_cases fc ON fc.law_version_id=v.id
                 WHERE a.id=? FOR UPDATE"
            );
            if (!$lock) throw new Exception('appeal assessment lock prepare failed');
            $lock->bind_param('i', $appeal_id);
            if (!$lock->execute()) throw new Exception('appeal assessment lock failed');
            $appeal = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (
                !$appeal || !in_array($appeal['appeal_status'], ['submitted','under_review'], true) ||
                !in_array($appeal['final_status'], ['appeal_hold','appeal_period'], true) ||
                (int)$appeal['submitted_by'] === $user_id ||
                (int)$appeal['current_version_id'] !== (int)$appeal['law_version_id'] ||
                $appeal['law_status'] !== 'appeal_period' || $appeal['version_status'] !== 'appeal_period'
            ) throw new RuntimeException('appeal_assessment_not_allowed');

            $eligible = $conn->prepare(
                "SELECT 1 FROM community_law_appeal_electorate WHERE appeal_id=? AND user_id=? LIMIT 1"
            );
            if (!$eligible) throw new Exception('appeal assessor eligibility prepare failed');
            $eligible->bind_param('ii', $appeal_id, $user_id);
            if (!$eligible->execute()) throw new Exception('appeal assessor eligibility lookup failed');
            $is_eligible = (bool)$eligible->get_result()->fetch_row();
            $eligible->close();
            if (!$is_eligible) throw new RuntimeException('appeal_assessment_not_allowed');

            $insert = $conn->prepare(
                "INSERT INTO community_law_appeal_assessments
                    (appeal_id, reviewer_user_id, verdict, reasoning, evidence)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$insert) throw new Exception('appeal assessment insert prepare failed');
            $insert->bind_param('iisss', $appeal_id, $user_id, $verdict, $reasoning, $evidence);
            if (!$insert->execute()) throw new Exception('appeal assessment insert failed');
            $insert->close();

            $aggregate = $conn->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN verdict='uphold' THEN 1 ELSE 0 END) AS upheld,
                        SUM(CASE WHEN verdict='unclear' THEN 1 ELSE 0 END) AS unclear_count
                 FROM community_law_appeal_assessments WHERE appeal_id=?"
            );
            if (!$aggregate) throw new Exception('appeal assessment aggregate prepare failed');
            $aggregate->bind_param('i', $appeal_id);
            if (!$aggregate->execute()) throw new Exception('appeal assessment aggregate failed');
            $totals = $aggregate->get_result()->fetch_assoc();
            $aggregate->close();

            $appeal_status = 'under_review';
            if ((int)$totals['total'] >= (int)$appeal['reviewer_quorum']) {
                if ((int)$totals['upheld'] > 0) $appeal_status = 'upheld';
                elseif ((int)$totals['unclear_count'] === 0) $appeal_status = 'dismissed';
            }
            $appeal_update = $conn->prepare(
                "UPDATE community_law_appeals SET status=?, decision_note=?, decided_at=IF(? IN ('upheld','dismissed'), NOW(), NULL)
                 WHERE id=? AND status IN ('submitted','under_review')"
            );
            if (!$appeal_update) throw new Exception('appeal status update prepare failed');
            $appeal_update->bind_param('sssi', $appeal_status, $reasoning, $appeal_status, $appeal_id);
            if (!$appeal_update->execute()) throw new Exception('appeal status update failed');
            $appeal_update->close();

            if ($appeal_status === 'upheld') {
                $case_update = $conn->prepare(
                    "UPDATE community_law_final_review_cases SET status='needs_revision'
                     WHERE law_version_id=? AND status IN ('appeal_hold','appeal_period')"
                );
                if (!$case_update) throw new Exception('upheld appeal case update prepare failed');
                $case_update->bind_param('i', $appeal['law_version_id']);
                if (!$case_update->execute()) throw new Exception('upheld appeal case update failed');
                $case_update->close();
                $law_update = $conn->prepare("UPDATE community_laws SET status='pending_final_review' WHERE id=? AND status='appeal_period'");
                $version_update = $conn->prepare("UPDATE community_law_versions SET status='pending_final_review' WHERE id=? AND status='appeal_period'");
                if (!$law_update || !$version_update) throw new Exception('upheld appeal law status prepare failed');
                $law_update->bind_param('i', $appeal['law_id']);
                $version_update->bind_param('i', $appeal['law_version_id']);
                if (!$law_update->execute() || !$version_update->execute()) throw new Exception('upheld appeal law status update failed');
                $law_update->close();
                $version_update->close();
            } elseif ($appeal_status === 'dismissed') {
                $open_appeals = $conn->prepare(
                    "SELECT COUNT(*) AS total FROM community_law_appeals
                     WHERE law_version_id=? AND status IN ('submitted','under_review')"
                );
                if (!$open_appeals) throw new Exception('open appeals count prepare failed');
                $open_appeals->bind_param('i', $appeal['law_version_id']);
                if (!$open_appeals->execute()) throw new Exception('open appeals count failed');
                $open_count = (int)$open_appeals->get_result()->fetch_assoc()['total'];
                $open_appeals->close();
                if ($open_count === 0) {
                    $case_update = $conn->prepare(
                        "UPDATE community_law_final_review_cases SET status='appeal_period'
                         WHERE law_version_id=? AND status='appeal_hold'"
                    );
                    if (!$case_update) throw new Exception('dismissed appeal case update prepare failed');
                    $case_update->bind_param('i', $appeal['law_version_id']);
                    if (!$case_update->execute()) throw new Exception('dismissed appeal case update failed');
                    $case_update->close();
                }
            }

            if (!community_law_record_history(
                $conn, (int)$appeal['law_id'], (int)$appeal['law_version_id'],
                $community_id, $user_id, 'law_appeal_assessed',
                'نتیجه رسیدگی به اعتراض: ' . $appeal_status . '؛ ' . $reasoning
            )) throw new Exception('appeal assessment history failed');
            if (!$conn->commit()) throw new Exception('appeal assessment commit failed');
            community_laws_redirect($community_id, 'appeal_assessment_saved');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException && $e->getMessage() === 'appeal_assessment_not_allowed') {
                community_laws_redirect($community_id, 'appeal_assessment_not_allowed');
            }
            error_log('[Dolate Eshgh appeal assessment] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['enact_law'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');
        $version_id = (int)($_POST['law_version_id'] ?? 0);
        if ($version_id <= 0) community_laws_redirect($community_id, 'enactment_not_ready');
        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            // اعتبار snapshot مرجع‌ها تا پایان بررسی نهایی حفظ می‌شود؛
            // ابتدا نقشه جوامع قفل می‌شود تا با تغییر والد هم‌زمان نشود.
            $hierarchy_lock = $conn->query(
                "SELECT id, parent_community_id FROM communities ORDER BY id FOR UPDATE"
            );
            if (!$hierarchy_lock) throw new Exception('lock hierarchy for enactment failed');
            $hierarchy_lock->free();

            $lock = $conn->prepare(
                "SELECT l.id AS law_id, l.status AS law_status, l.current_version_id,
                        l.originating_community_id, v.status AS version_status,
                        fc.status AS final_status, fc.appeal_deadline,
                        rc.status AS screening_status, ac.status AS authority_status,
                        fr.final_status AS vote_result
                 FROM community_law_versions v
                 JOIN community_laws l ON l.id=v.law_id
                 JOIN community_law_final_review_cases fc ON fc.law_version_id=v.id
                 JOIN community_law_review_cases rc ON rc.law_version_id=v.id
                 JOIN community_law_authority_cases ac ON ac.law_version_id=v.id
                 JOIN community_law_final_results fr ON fr.law_version_id=v.id
                 WHERE v.id=? FOR UPDATE"
            );
            if (!$lock) throw new Exception('enactment lock prepare failed');
            $lock->bind_param('i', $version_id);
            if (!$lock->execute()) throw new Exception('enactment lock failed');
            $law = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (
                !$law || (int)$law['originating_community_id'] !== $community_id ||
                (int)$law['current_version_id'] !== $version_id ||
                $law['law_status'] !== 'appeal_period' ||
                $law['version_status'] !== 'appeal_period' ||
                $law['screening_status'] !== 'screening_clear' ||
                $law['authority_status'] !== 'confirmed' ||
                $law['vote_result'] !== 'vote_passed' ||
                !in_array($law['final_status'], ['appeal_period','appeal_hold'], true) ||
                empty($law['appeal_deadline']) || strtotime($law['appeal_deadline']) > time()
            ) throw new RuntimeException('enactment_not_ready');

            // ابلاغ فقط وقتی مجاز است که snapshot منشور هنوز دقیقاً نسخه جاری و کامل باشد.
            $charter_snapshot_current = community_law_charter_snapshot_is_current($conn, $version_id);
            if ($charter_snapshot_current !== true) {
                throw new RuntimeException('charter_snapshot_changed');
            }

            $appeal_check = $conn->prepare(
                "SELECT
                    SUM(CASE WHEN status IN ('submitted','under_review') THEN 1 ELSE 0 END) AS unresolved,
                    SUM(CASE WHEN status='upheld' THEN 1 ELSE 0 END) AS upheld
                 FROM community_law_appeals WHERE law_version_id=?"
            );
            if (!$appeal_check) throw new Exception('enactment appeal check prepare failed');
            $appeal_check->bind_param('i', $version_id);
            if (!$appeal_check->execute()) throw new Exception('enactment appeal check failed');
            $appeal_totals = $appeal_check->get_result()->fetch_assoc();
            $appeal_check->close();
            if ((int)$appeal_totals['unresolved'] > 0 || (int)$appeal_totals['upheld'] > 0) {
                throw new RuntimeException('enactment_appeals_pending');
            }

            // بازبینی نسخه و دامنه همه قوانین مرجعِ snapshotشده؛ هر تغییر، ابلاغ را متوقف می‌کند.
            $reference_check = $conn->prepare(
                "SELECT fr.reference_law_id, fr.reference_law_version_id, fr.scope_fingerprint,
                        l.current_version_id, l.status AS law_status, l.effective_at,
                        l.expires_at, l.repealed_at, v.status AS version_status
                 FROM community_law_final_review_references fr
                 JOIN community_laws l ON l.id=fr.reference_law_id
                 JOIN community_law_versions v ON v.id=fr.reference_law_version_id
                 WHERE fr.law_version_id=?"
            );
            if (!$reference_check) throw new Exception('enactment references prepare failed');
            $reference_check->bind_param('i', $version_id);
            if (!$reference_check->execute()) throw new Exception('enactment references lookup failed');
            $reference_rows = $reference_check->get_result();
            while ($reference = $reference_rows->fetch_assoc()) {
                if (
                    (int)$reference['current_version_id'] !== (int)$reference['reference_law_version_id'] ||
                    $reference['law_status'] !== 'approved' || $reference['version_status'] !== 'approved' ||
                    !empty($reference['repealed_at']) ||
                    (!empty($reference['effective_at']) && strtotime($reference['effective_at']) > time()) ||
                    (!empty($reference['expires_at']) && strtotime($reference['expires_at']) <= time())
                ) throw new RuntimeException('reference_law_changed');
                $scope_stmt = $conn->prepare(
                    "SELECT scope_community_id, include_descendants
                     FROM community_law_scopes WHERE law_version_id=?
                     ORDER BY scope_community_id"
                );
                if (!$scope_stmt) throw new Exception('enactment scope prepare failed');
                $ref_version = (int)$reference['reference_law_version_id'];
                $scope_stmt->bind_param('i', $ref_version);
                if (!$scope_stmt->execute()) throw new Exception('enactment scope lookup failed');
                $scope_rows = [];
                $scope_result = $scope_stmt->get_result();
                while ($scope_row = $scope_result->fetch_assoc()) $scope_rows[] = $scope_row;
                $scope_stmt->close();
                if (!$scope_rows || !hash_equals((string)$reference['scope_fingerprint'], hash('sha256', json_encode($scope_rows)))) {
                    throw new RuntimeException('reference_law_changed');
                }
            }
            $reference_check->close();

            // علاوه بر تغییر نسخه قوانین مرجع قبلی، هر قانون تازه‌لازم‌الاجرای مرتبط نیز باید در snapshot نهایی وجود داشته باشد.
            $candidate_scope_ids = community_law_scope_community_ids($conn, $version_id);
            if (!$candidate_scope_ids) throw new RuntimeException('reference_law_changed');
            $active_reference_scan = $conn->query(
                "SELECT l.id AS law_id, l.current_version_id
                 FROM community_laws l
                 JOIN community_law_versions v ON v.id=l.current_version_id
                 WHERE l.status='approved' AND v.status='approved'
                   AND (l.effective_at IS NULL OR l.effective_at<=NOW())
                   AND (l.expires_at IS NULL OR l.expires_at>NOW())
                   AND l.repealed_at IS NULL"
            );
            if (!$active_reference_scan) throw new Exception('active reference rescan failed');
            while ($active_reference = $active_reference_scan->fetch_assoc()) {
                $active_version_id = (int)$active_reference['current_version_id'];
                $applies_now = false;
                foreach ($candidate_scope_ids as $target_community_id) {
                    $scope_match = community_law_scope_match_checked(
                        $conn, $active_version_id, (int)$target_community_id
                    );
                    if ($scope_match === null) {
                        $active_reference_scan->free();
                        throw new RuntimeException('reference_law_changed');
                    }
                    if ($scope_match) {
                        $applies_now = true;
                        break;
                    }
                }
                if (!$applies_now) continue;
                $captured_reference = $conn->prepare(
                    "SELECT 1 FROM community_law_final_review_references
                     WHERE law_version_id=? AND reference_law_id=?
                       AND reference_law_version_id=? LIMIT 1"
                );
                if (!$captured_reference) {
                    $active_reference_scan->free();
                    throw new Exception('final reference snapshot verification prepare failed');
                }
                $active_law_id = (int)$active_reference['law_id'];
                $captured_reference->bind_param('iii', $version_id, $active_law_id, $active_version_id);
                if (!$captured_reference->execute()) {
                    $captured_reference->close();
                    $active_reference_scan->free();
                    throw new Exception('final reference snapshot verification failed');
                }
                $was_captured = (bool)$captured_reference->get_result()->fetch_row();
                $captured_reference->close();
                if (!$was_captured) {
                    $active_reference_scan->free();
                    throw new RuntimeException('reference_law_changed');
                }
            }
            $active_reference_scan->free();

            // دامنه نهایی باید در همان snapshot قفل‌شده حل شود؛
            // بدون فهرست معتبر جوامع، قانون ابلاغ نمی‌شود.
            $implementation_community_ids = community_law_scope_community_ids($conn, $version_id);
            if (
                $implementation_community_ids === null ||
                !$implementation_community_ids ||
                !in_array((int)$law['originating_community_id'], $implementation_community_ids, true)
            ) throw new RuntimeException('law_scope_unresolved');

            $law_update = $conn->prepare(
                "UPDATE community_laws
                 SET status='approved', effective_at=NOW()
                 WHERE id=? AND status='appeal_period' AND current_version_id=?"
            );
            $version_update = $conn->prepare(
                "UPDATE community_law_versions SET status='approved', approved_at=NOW()
                 WHERE id=? AND status='appeal_period'"
            );
            $case_update = $conn->prepare(
                "UPDATE community_law_final_review_cases SET status='enacted', enacted_at=NOW()
                 WHERE law_version_id=? AND status IN ('appeal_period','appeal_hold')"
            );
            if (!$law_update || !$version_update || !$case_update) throw new Exception('enactment update prepare failed');
            $law_update->bind_param('ii', $law['law_id'], $version_id);
            $version_update->bind_param('i', $version_id);
            $case_update->bind_param('i', $version_id);
            if (!$law_update->execute() || $law_update->affected_rows !== 1) throw new Exception('enactment law update failed');
            if (!$version_update->execute() || $version_update->affected_rows !== 1) throw new Exception('enactment version update failed');
            if (!$case_update->execute() || $case_update->affected_rows !== 1) throw new Exception('enactment case update failed');
            $law_update->close();
            $version_update->close();
            $case_update->close();

            if (!community_law_record_history(
                $conn, (int)$law['law_id'], $version_id, $community_id, $user_id,
                'law_enacted',
                'پس از رأی مثبت، بررسی نهایی مستقل، پایان مهلت اعتراض و رسیدگی به همه اعتراض‌ها، قانون ابلاغ و لازم‌الاجرا شد.'
            )) throw new Exception('enactment history failed');
            if (!$conn->commit()) throw new Exception('enactment commit failed');
            community_laws_redirect($community_id, 'law_enacted');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException) {
                if ($e->getMessage() === 'enactment_not_ready') community_laws_redirect($community_id, 'enactment_not_ready');
                if ($e->getMessage() === 'enactment_appeals_pending') community_laws_redirect($community_id, 'enactment_appeals_pending');
                if ($e->getMessage() === 'reference_law_changed') community_laws_redirect($community_id, 'reference_law_changed');
            }
            error_log('[Dolate Eshgh enactment] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['create_law_revision'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $version_id = (int)($_POST['law_version_id'] ?? 0);
        $title = trim((string)($_POST['revision_title'] ?? ''));
        $law_text = trim((string)($_POST['revision_law_text'] ?? ''));
        $implementation_plan = trim((string)($_POST['revision_implementation_plan'] ?? ''));
        $change_summary = trim((string)($_POST['revision_change_summary'] ?? ''));

        if (
            $version_id <= 0 || $title === '' || mb_strlen($title) > 200 ||
            $law_text === '' || mb_strlen($law_text) > 50000 ||
            mb_strlen($implementation_plan) > 30000 ||
            $change_summary === '' || mb_strlen($change_summary) > 10000
        ) community_laws_redirect($community_id, 'invalid_law_revision');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $lock = $conn->prepare(
                "SELECT l.id AS law_id, l.originating_community_id, l.status AS law_status,
                        l.current_version_id, v.version_number, v.status AS version_status,
                        v.source_topic_id, v.source_idea_id, v.source_decision_id, fc.status AS final_status,
                        rc.status AS screening_status, ac.status AS authority_status,
                        (SELECT COUNT(*) FROM community_law_appeals ap
                         WHERE ap.law_version_id=v.id
                           AND ap.status IN ('submitted','under_review')) AS unresolved_appeals
                 FROM community_law_versions v
                 JOIN community_laws l ON l.id=v.law_id
                 JOIN community_law_final_review_cases fc ON fc.law_version_id=v.id
                 LEFT JOIN community_law_review_cases rc ON rc.law_version_id=v.id
                 LEFT JOIN community_law_authority_cases ac ON ac.law_version_id=v.id
                 WHERE v.id=? FOR UPDATE"
            );
            if (!$lock) throw new Exception('revision lock prepare failed');
            $lock->bind_param('i', $version_id);
            if (!$lock->execute()) throw new Exception('revision lock failed');
            $previous = $lock->get_result()->fetch_assoc();
            $lock->close();

            if (
                !$previous ||
                (int)$previous['originating_community_id'] !== $community_id ||
                (int)$previous['current_version_id'] !== $version_id ||
                !in_array($previous['law_status'], ['pending_final_review','appeal_period','enactment_pending'], true) ||
                !in_array($previous['version_status'], ['pending_final_review','appeal_period','enactment_pending'], true) ||
                !in_array($previous['final_status'], ['conflict_found','needs_revision'], true) ||
                (int)$previous['unresolved_appeals'] > 0
            ) throw new RuntimeException('law_revision_not_allowed');

            $scope_check = $conn->prepare(
                "SELECT scope_community_id, include_descendants, scope_note
                 FROM community_law_scopes WHERE law_version_id=?
                 ORDER BY scope_community_id"
            );
            if (!$scope_check) throw new Exception('revision scope check prepare failed');
            $scope_check->bind_param('i', $version_id);
            if (!$scope_check->execute()) throw new Exception('revision scope check failed');
            $scope_rows = [];
            $scope_result = $scope_check->get_result();
            while ($scope_row = $scope_result->fetch_assoc()) $scope_rows[] = $scope_row;
            $scope_check->close();
            if (!$scope_rows) throw new RuntimeException('law_revision_scope_missing');

            // در نسخه اصلاحی نیز شمول قانون مادر نسبت به تمام زیرشاخه‌ها حفظ می‌شود.
            $origin_community_id = (int)$previous['originating_community_id'];
            $origin_scope_found = false;
            foreach ($scope_rows as &$scope_row) {
                if ((int)$scope_row['scope_community_id'] === $origin_community_id) {
                    $scope_row['include_descendants'] = 1;
                    $origin_scope_found = true;
                }
            }
            unset($scope_row);
            if (!$origin_scope_found) {
                $scope_rows[] = [
                    'scope_community_id' => $origin_community_id,
                    'include_descendants' => 1,
                    'scope_note' => 'شمول خودکار قوانین جامعه مادر در تمام زیرشاخه‌ها'
                ];
            }

            $next_version = (int)$previous['version_number'] + 1;
            $insert = $conn->prepare(
                "INSERT INTO community_law_versions
                    (law_id, version_number, title, law_text, implementation_plan,
                     change_summary, source_topic_id, source_idea_id, source_decision_id, proposed_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')"
            );
            if (!$insert) throw new Exception('revision insert prepare failed');
            $source_topic_id = $previous['source_topic_id'] !== null ? (int)$previous['source_topic_id'] : null;
            $source_idea_id = $previous['source_idea_id'] !== null ? (int)$previous['source_idea_id'] : null;
            $source_decision_id = $previous['source_decision_id'] !== null ? (int)$previous['source_decision_id'] : null;
            $law_id = (int)$previous['law_id'];
            $insert->bind_param(
                'iissssiiii',
                $law_id, $next_version, $title, $law_text, $implementation_plan,
                $change_summary, $source_topic_id, $source_idea_id, $source_decision_id, $user_id
            );
            if (!$insert->execute()) throw new Exception('revision insert failed');
            $new_version_id = (int)$conn->insert_id;
            $insert->close();

            $scope_insert = $conn->prepare(
                "INSERT INTO community_law_scopes
                    (law_version_id, scope_community_id, include_descendants, scope_note)
                 VALUES (?, ?, ?, ?)"
            );
            if (!$scope_insert) throw new Exception('revision scope insert prepare failed');
            foreach ($scope_rows as $scope_row) {
                $scope_community_id = (int)$scope_row['scope_community_id'];
                $include_descendants = (int)$scope_row['include_descendants'];
                $scope_note = $scope_row['scope_note'];
                $scope_insert->bind_param(
                    'iiis', $new_version_id, $scope_community_id,
                    $include_descendants, $scope_note
                );
                if (!$scope_insert->execute()) throw new Exception('revision scope insert failed');
            }
            $scope_insert->close();

            $old_version_update = $conn->prepare(
                "UPDATE community_law_versions SET status='superseded'
                 WHERE id=? AND status IN ('pending_final_review','appeal_period','enactment_pending')"
            );
            $law_update = $conn->prepare(
                "UPDATE community_laws SET current_version_id=?, status='draft'
                 WHERE id=? AND current_version_id=?
                   AND status IN ('pending_final_review','appeal_period','enactment_pending')"
            );
            if (!$old_version_update || !$law_update) throw new Exception('revision state update prepare failed');
            $old_version_update->bind_param('i', $version_id);
            $law_update->bind_param('iii', $new_version_id, $law_id, $version_id);
            if (!$old_version_update->execute() || $old_version_update->affected_rows !== 1) {
                throw new Exception('revision previous version update failed');
            }
            if (!$law_update->execute() || $law_update->affected_rows !== 1) {
                throw new Exception('revision current version update failed');
            }
            $old_version_update->close();
            $law_update->close();

            if (!community_law_record_history(
                $conn, $law_id, $version_id, $community_id, $user_id,
                'law_revision_created',
                'نسخه ' . $next_version . ' به‌عنوان پیش‌نویس اصلاحی ایجاد شد؛ نسخه پیشین و ارزیابی‌ها و اعتراض‌های آن حفظ شدند. خلاصه اصلاحات: ' . $change_summary
            )) throw new Exception('revision history previous failed');
            if (!community_law_record_history(
                $conn, $law_id, $new_version_id, $community_id, $user_id,
                'law_revision_draft',
                'پیش‌نویس نسخه اصلاحی ایجاد شد. قواعد رأی‌گیری و همه مراحل بررسی باید برای این نسخه جداگانه تکمیل شوند.'
            )) throw new Exception('revision history new failed');

            if (!$conn->commit()) throw new Exception('revision commit failed');
            community_laws_redirect($community_id, 'law_revision_created');
        } catch (Throwable $e) {
            $conn->rollback();
            if ($e instanceof RuntimeException) {
                if ($e->getMessage() === 'law_revision_not_allowed') community_laws_redirect($community_id, 'law_revision_not_allowed');
                if ($e->getMessage() === 'law_revision_scope_missing') community_laws_redirect($community_id, 'law_revision_scope_missing');
            }
            error_log('[Dolate Eshgh law revision] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['submit_law_objection'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $law_version_id = (int)($_POST['law_version_id'] ?? 0);
        $objection_type = (string)($_POST['objection_type'] ?? 'other');
        $description = trim((string)($_POST['description'] ?? ''));
        $evidence = trim((string)($_POST['evidence'] ?? ''));

        $allowed_types = ['charter_conflict','jurisdiction_conflict','rights_impact','implementation_barrier','other'];
        if (
            $law_version_id <= 0 ||
            !in_array($objection_type, $allowed_types, true) ||
            $description === '' ||
            mb_strlen($description) > 30000 ||
            mb_strlen($evidence) > 30000 ||
            !community_law_applies_to_community($conn, $law_version_id, $community_id)
        ) {
            community_laws_redirect($community_id, 'invalid_objection');
        }

        $law_stmt = $conn->prepare('SELECT law_id FROM community_law_versions WHERE id=? LIMIT 1');
        $law_stmt->bind_param('s', $law_version_id);
        $law_stmt->execute();
        $law_row = $law_stmt->get_result()->fetch_assoc();
        $law_stmt->close();
        if (!$law_row) community_laws_redirect($community_id, 'invalid_objection');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $objection_insert = $conn->prepare(
                "INSERT INTO community_law_objections
                    (law_version_id, community_id, submitted_by, objection_type, description, evidence)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if (!$objection_insert) throw new Exception('objection insert prepare failed');
            $objection_insert->bind_param(
                'siisss',
                $law_version_id,
                $community_id,
                $user_id,
                $objection_type,
                $description,
                $evidence
            );
            if (!$objection_insert->execute()) throw new Exception('objection insert failed');
            $objection_insert->close();

            if (!community_law_record_history(
                $conn,
                (int)$law_row['law_id'],
                $law_version_id,
                $community_id,
                $user_id,
                'objection_submitted',
                $description
            )) {
                throw new Exception('objection history insert failed');
            }

            if (!$conn->commit()) throw new Exception('transaction commit failed');
            community_laws_redirect($community_id, 'objection_saved');
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[Dolate Eshgh law objection] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }
}

$effective_laws = [];
$draft_laws = [];
$proposed_laws = [];
$voting_laws = [];
$position_discussions = [];
$closed_voting_laws = [];
$tallied_laws = [];
$recent_objections = [];
$passed_laws_pending_final_review = [];
$final_review_laws = [];
$appeals_by_version = [];
$final_assessments_by_version = [];
$appeal_assessments_by_id = [];

if ($schema_ready) {
    $law_result = $conn->query(
        "SELECT l.id AS law_id, l.law_code, l.originating_community_id,
                origin.name AS originating_community_name,
                l.effective_at, l.expires_at,
                v.id AS version_id, v.version_number, v.title, v.law_text,
                v.implementation_plan, v.approved_at,
                v.source_topic_id, v.source_idea_id, v.source_decision_id
         FROM community_laws l
         JOIN communities origin ON origin.id=l.originating_community_id
         JOIN community_law_versions v ON v.id=l.current_version_id
         WHERE l.status='approved' AND v.status='approved'
         ORDER BY l.effective_at DESC, l.id DESC"
    );
    if ($law_result) {
        while ($row = $law_result->fetch_assoc()) {
            $row['law_id'] = (int)$row['law_id'];
            $row['version_id'] = (int)$row['version_id'];
            $row['originating_community_id'] = (int)$row['originating_community_id'];
            if (community_law_applies_to_community($conn, $row['version_id'], $community_id)) {
                $effective_laws[] = $row;
            }
        }
        $law_result->free();
    }

    // زنجیرهٔ منشأ قانون تا دانش مرتبط فقط به‌صورت ارجاعی نمایش داده می‌شود.
    // شواهد اجرا و اقدام اصلاحی خودکار به مدخل دانش یا تصمیم جدید تبدیل نمی‌شوند.
    $law_source_knowledge_by_decision=[];
    $knowledge_entries_ready=false;
    $knowledge_table_check=$conn->query(
        "SELECT COUNT(*) AS total FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name='collective_knowledge_entries'"
    );
    $knowledge_entries_ready=$knowledge_table_check &&
        (int)$knowledge_table_check->fetch_assoc()['total']===1;
    if ($knowledge_table_check) $knowledge_table_check->free();
    if ($decision_ledger_ready && $knowledge_entries_ready && $effective_laws) {
        $source_decision_ids=array_values(array_unique(array_filter(array_map(
            static fn($law_row)=>(int)($law_row['source_decision_id']??0),
            $effective_laws
        ),static fn($id)=>$id>0)));
        if ($source_decision_ids) {
            $source_decision_id_list=implode(',', $source_decision_ids);
            $source_knowledge_result=$conn->query(
                "SELECT d.id AS decision_id,d.knowledge_entry_id,
                        ke.title AS knowledge_title,ke.status AS knowledge_status,ke.version_no
                 FROM community_decision_records d
                 LEFT JOIN collective_knowledge_entries ke ON ke.id=d.knowledge_entry_id
                 WHERE d.id IN ($source_decision_id_list)"
            );
            if ($source_knowledge_result) {
                while ($source_knowledge_row=$source_knowledge_result->fetch_assoc()) {
                    $law_source_knowledge_by_decision[(int)$source_knowledge_row['decision_id']]=$source_knowledge_row;
                }
                $source_knowledge_result->free();
            }
        }
    }

    $draft_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, v.id AS version_id, v.title, v.created_at, v.proposed_by
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         WHERE l.originating_community_id=? AND l.status='draft' AND v.status='draft'
         ORDER BY v.created_at DESC LIMIT 30"
    );
    if ($draft_stmt) {
        $draft_stmt->bind_param('i', $community_id);
        if ($draft_stmt->execute()) {
            $draft_result = $draft_stmt->get_result();
            while ($row = $draft_result->fetch_assoc()) $draft_laws[] = $row;
        }
        $draft_stmt->close();
    }

    $proposed_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, l.originating_community_id,
                v.id AS version_id, v.title, v.created_at, v.proposed_by,
                rc.status AS review_status, rc.reviewer_quorum,
                ac.status AS authority_status, ac.derived_jurisdiction_level,
                ac.reviewer_quorum AS authority_reviewer_quorum,
                (SELECT COUNT(*) FROM community_law_review_assessments ra
                 WHERE ra.law_version_id=v.id) AS reviewer_count,
                (SELECT COUNT(*) FROM community_law_review_electorate rec
                 WHERE rec.law_version_id=v.id) AS reviewer_electorate_count,
                (SELECT COUNT(*) FROM community_law_review_electorate re
                 WHERE re.law_version_id=v.id AND re.user_id=?) AS is_review_elector,
                (SELECT COUNT(*) FROM community_law_review_assessments mine
                 WHERE mine.law_version_id=v.id AND mine.reviewer_user_id=?) AS has_assessed,
                (SELECT COUNT(*) FROM community_law_authority_assessments aa
                 WHERE aa.law_version_id=v.id) AS authority_reviewer_count,
                (SELECT COUNT(*) FROM community_law_authority_assessments am
                 WHERE am.law_version_id=v.id AND am.reviewer_user_id=?) AS has_authority_assessed
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         LEFT JOIN community_law_review_cases rc ON rc.law_version_id=v.id
         LEFT JOIN community_law_authority_cases ac ON ac.law_version_id=v.id
         WHERE l.status='proposed' AND v.status='proposed'
         ORDER BY v.created_at DESC LIMIT 100"
    );
    if ($proposed_stmt) {
        $proposed_stmt->bind_param('iii', $user_id, $user_id, $user_id);
        if ($proposed_stmt->execute()) {
            $proposed_result = $proposed_stmt->get_result();
            while ($row = $proposed_result->fetch_assoc()) {
                if (
                    (int)$row['originating_community_id'] === $community_id ||
                    community_law_scope_matches($conn, (int)$row['version_id'], $community_id)
                ) $proposed_laws[] = $row;
            }
        }
        $proposed_stmt->close();
    }

    $voting_rule_proposals_by_version = [];
    $voting_rule_votes_by_proposal = [];
    $rule_proposal_result = $conn->query(
        "SELECT rp.*,
                (SELECT COUNT(*) FROM community_law_voting_rule_electorate re
                 WHERE re.proposal_id=rp.id) AS electorate_count,
                (SELECT COUNT(*) FROM community_law_voting_rule_votes rv
                 WHERE rv.proposal_id=rp.id) AS vote_count,
                (SELECT SUM(CASE WHEN rv.decision='approve' THEN 1 ELSE 0 END)
                 FROM community_law_voting_rule_votes rv WHERE rv.proposal_id=rp.id) AS approval_count
         FROM community_law_voting_rule_proposals rp
         JOIN community_law_versions v ON v.id=rp.law_version_id
         JOIN community_laws l ON l.id=v.law_id
         WHERE l.status='proposed' AND v.status='proposed'
         ORDER BY rp.id DESC"
    );
    if ($rule_proposal_result) {
        while ($rule_row = $rule_proposal_result->fetch_assoc()) {
            $voting_rule_proposals_by_version[(int)$rule_row['law_version_id']][] = $rule_row;
        }
        $rule_proposal_result->free();
    }
    $rule_vote_result = $conn->query(
        "SELECT rv.proposal_id, rv.reviewer_user_id, rv.decision, rv.reasoning, rv.voted_at
         FROM community_law_voting_rule_votes rv
         JOIN community_law_voting_rule_proposals rp ON rp.id=rv.proposal_id
         JOIN community_law_versions v ON v.id=rp.law_version_id
         JOIN community_laws l ON l.id=v.law_id
         WHERE l.status='proposed' AND v.status='proposed'
         ORDER BY rv.voted_at ASC"
    );
    if ($rule_vote_result) {
        while ($rule_vote = $rule_vote_result->fetch_assoc()) {
            $voting_rule_votes_by_proposal[(int)$rule_vote['proposal_id']][] = $rule_vote;
        }
        $rule_vote_result->free();
    }

    $legal_assessments_by_version = [];
    $legal_assessment_stmt = $conn->query(
        "SELECT a.law_version_id, a.reviewer_user_id,
                a.charter_compatibility, a.hierarchy_compatibility,
                a.rights_impact, a.environmental_impact,
                a.implementation_feasibility, a.reasoning, a.evidence, a.submitted_at
         FROM community_law_review_assessments a
         JOIN community_law_versions v ON v.id=a.law_version_id
         JOIN community_laws l ON l.id=v.law_id
         WHERE l.status='proposed' AND v.status='proposed'
         ORDER BY a.submitted_at ASC LIMIT 300"
    );
    if ($legal_assessment_stmt) {
        while ($assessment_row = $legal_assessment_stmt->fetch_assoc()) {
            $legal_assessments_by_version[(int)$assessment_row['law_version_id']][] = $assessment_row;
        }
        $legal_assessment_stmt->free();
    }

    $authority_references_by_version = [];
    $authority_reference_scan_ok = false;
    $authority_reference_result = $conn->query(
        "SELECT ar.id, ar.law_version_id, ar.reference_law_id,
                ar.reference_law_version_id, ar.reference_origin_community_id,
                ar.relation_type, rl.law_code, rv.title AS reference_title,
                rv.law_text AS reference_law_text,
                c.name AS reference_community_name
         FROM community_law_authority_references ar
         JOIN community_laws rl ON rl.id=ar.reference_law_id
         JOIN community_law_versions rv ON rv.id=ar.reference_law_version_id
         JOIN communities c ON c.id=ar.reference_origin_community_id
         ORDER BY ar.relation_type, rl.law_code"
    );
    if ($authority_reference_result) {
        $authority_reference_scan_ok = true;
        while ($reference_row = $authority_reference_result->fetch_assoc()) {
            $authority_references_by_version[(int)$reference_row['law_version_id']][] = $reference_row;
        }
        $authority_reference_result->free();
    }

    $authority_assessments_by_version = [];
    $authority_assessment_result = $conn->query(
        "SELECT law_version_id, reviewer_user_id, authority_verdict,
                reasoning, evidence, submitted_at
         FROM community_law_authority_assessments
         ORDER BY submitted_at ASC LIMIT 300"
    );
    if ($authority_assessment_result) {
        while ($authority_assessment_row = $authority_assessment_result->fetch_assoc()) {
            $authority_assessments_by_version[(int)$authority_assessment_row['law_version_id']][] = $authority_assessment_row;
        }
        $authority_assessment_result->free();
    }

    $precedent_assessments_by_reference = [];
    $precedent_assessment_result = $conn->query(
        "SELECT reference_id, reviewer_user_id, compatibility_verdict,
                reasoning, evidence, submitted_at
         FROM community_law_precedent_assessments
         ORDER BY submitted_at ASC LIMIT 500"
    );
    if ($precedent_assessment_result) {
        while ($precedent_assessment_row = $precedent_assessment_result->fetch_assoc()) {
            $precedent_assessments_by_reference[(int)$precedent_assessment_row['reference_id']][] = $precedent_assessment_row;
        }
        $precedent_assessment_result->free();
    }

    $voting_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, v.id AS version_id, v.title,
                v.law_text, v.vote_deadline,
                (SELECT cv.vote FROM community_law_votes cv
                 WHERE cv.law_version_id=v.id AND cv.voting_community_id=? AND cv.voter_user_id=?
                 LIMIT 1) AS community_current_vote,
                (SELECT dv.vote FROM community_law_direct_votes dv
                 WHERE dv.law_version_id=v.id AND dv.voter_user_id=?
                 LIMIT 1) AS direct_current_vote
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         JOIN community_law_electorate e ON e.law_version_id=v.id
             AND e.voting_community_id=? AND e.user_id=?
         WHERE l.status='voting' AND v.status='voting' AND v.vote_deadline > NOW()
         ORDER BY v.vote_deadline ASC LIMIT 50"
    );
    if ($voting_stmt) {
        $voting_stmt->bind_param('iiiii', $community_id, $user_id, $user_id, $community_id, $user_id);
        if ($voting_stmt->execute()) {
            $voting_result = $voting_stmt->get_result();
            while ($row = $voting_result->fetch_assoc()) $voting_laws[] = $row;
        }
        $voting_stmt->close();
    }

    if ($voting_laws) {
        $discussion_stmt = $conn->prepare(
            "SELECT id, law_version_id, author_user_id, stance, argument, evidence, created_at
             FROM community_law_position_discussions
             WHERE community_id=?
             ORDER BY created_at ASC LIMIT 150"
        );
        if ($discussion_stmt) {
            $discussion_stmt->bind_param('i', $community_id);
            if ($discussion_stmt->execute()) {
                $discussion_result = $discussion_stmt->get_result();
                while ($discussion_row = $discussion_result->fetch_assoc()) {
                    $position_discussions[(int)$discussion_row['law_version_id']][] = $discussion_row;
                }
            }
            $discussion_stmt->close();
        }
    }

    $closed_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, v.id AS version_id, v.title,
                v.vote_deadline,
                (SELECT COUNT(*) FROM community_law_positions p
                 WHERE p.law_version_id=v.id) AS community_count
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         WHERE l.originating_community_id=?
           AND l.status='voting' AND v.status='voting'
           AND v.vote_deadline <= NOW()
           AND NOT EXISTS (
               SELECT 1 FROM community_law_final_results fr
               WHERE fr.law_version_id=v.id
           )
         ORDER BY v.vote_deadline DESC LIMIT 30"
    );
    if ($closed_stmt) {
        $closed_stmt->bind_param('i', $community_id);
        if ($closed_stmt->execute()) {
            $closed_result = $closed_stmt->get_result();
            while ($row = $closed_result->fetch_assoc()) $closed_voting_laws[] = $row;
        }
        $closed_stmt->close();
    }

    $tallied_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, v.id AS version_id, v.title,
                v.vote_deadline, p.community_id, c.name AS community_name,
                p.electorate_count, p.yes_count, p.no_count, p.abstain_count,
                p.yes_weight, p.no_weight, p.position_result,
                dr.electorate_count AS direct_electorate_count,
                dr.yes_count AS direct_yes_count,
                dr.no_count AS direct_no_count,
                dr.abstain_count AS direct_abstain_count,
                dr.yes_weight AS direct_yes_weight,
                dr.no_weight AS direct_no_weight,
                fr.final_status,
                fr.individual_turnout_percent,
                fr.individual_approval_percent,
                fr.community_turnout_percent,
                fr.community_approval_percent,
                fr.individual_vote_basis,
                fr.community_vote_basis,
                fr.require_community_approval
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         JOIN community_law_positions p ON p.law_version_id=v.id
         JOIN communities c ON c.id=p.community_id
         LEFT JOIN community_law_direct_results dr ON dr.law_version_id=v.id
         LEFT JOIN community_law_final_results fr ON fr.law_version_id=v.id
         WHERE l.originating_community_id=?
           AND l.status='voting' AND v.status='voting'
           AND v.vote_deadline <= NOW()
         ORDER BY v.vote_deadline DESC, c.name ASC LIMIT 300"
    );
    if ($tallied_stmt) {
        $tallied_stmt->bind_param('i', $community_id);
        if ($tallied_stmt->execute()) {
            $tallied_result = $tallied_stmt->get_result();
            while ($row = $tallied_result->fetch_assoc()) {
                $law_key = (int)$row['law_id'];
                if (!isset($tallied_laws[$law_key])) {
                    $tallied_laws[$law_key] = [
                        'title' => $row['title'],
                        'law_code' => $row['law_code'],
                        'version_id' => (int)$row['version_id'],
                        'vote_deadline' => $row['vote_deadline'],
                        'final_result' => [
                            'status' => $row['final_status'] ?? null,
                            'individual_turnout' => (float)($row['individual_turnout_percent'] ?? 0),
                            'individual_approval' => (float)($row['individual_approval_percent'] ?? 0),
                            'community_turnout' => (float)($row['community_turnout_percent'] ?? 0),
                            'community_approval' => (float)($row['community_approval_percent'] ?? 0),
                            'individual_basis' => $row['individual_vote_basis'] ?? null,
                            'community_basis' => $row['community_vote_basis'] ?? null,
                            'require_community' => (int)($row['require_community_approval'] ?? 0)
                        ],
                        'direct_result' => [
                            'electorate_count' => (int)($row['direct_electorate_count'] ?? 0),
                            'yes_count' => (int)($row['direct_yes_count'] ?? 0),
                            'no_count' => (int)($row['direct_no_count'] ?? 0),
                            'abstain_count' => (int)($row['direct_abstain_count'] ?? 0),
                            'yes_weight' => (float)($row['direct_yes_weight'] ?? 0),
                            'no_weight' => (float)($row['direct_no_weight'] ?? 0)
                        ],
                        'communities' => []
                    ];
                }
                $tallied_laws[$law_key]['communities'][] = $row;
            }
        }
        $tallied_stmt->close();
    }


    $passed_final_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, v.id AS version_id, v.title,
                fr.final_status
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         JOIN community_law_final_results fr ON fr.law_version_id=v.id
         LEFT JOIN community_law_final_review_cases fc ON fc.law_version_id=v.id
         WHERE l.originating_community_id=? AND l.status='voting'
           AND v.status='voting' AND fr.final_status='vote_passed'
           AND fc.law_version_id IS NULL
         ORDER BY v.created_at DESC LIMIT 30"
    );
    if ($passed_final_stmt) {
        $passed_final_stmt->bind_param('i', $community_id);
        if ($passed_final_stmt->execute()) {
            $passed_final_result = $passed_final_stmt->get_result();
            while ($row = $passed_final_result->fetch_assoc()) $passed_laws_pending_final_review[] = $row;
        }
        $passed_final_stmt->close();
    }

    $final_review_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, l.originating_community_id,
                l.status AS law_status, v.id AS version_id, v.title,
                v.law_text, v.implementation_plan, v.source_topic_id, v.source_idea_id,
                v.source_decision_id,
                v.status AS version_status, fc.status AS final_status,
                fc.reviewer_quorum, fc.appeal_period_days, fc.appeal_deadline,
                (SELECT COUNT(*) FROM community_law_final_review_assessments a
                 WHERE a.law_version_id=v.id) AS assessment_count,
                (SELECT COUNT(*) FROM community_law_final_review_electorate e
                 WHERE e.law_version_id=v.id AND e.user_id=?) AS is_final_elector,
                (SELECT COUNT(*) FROM community_law_final_review_assessments a
                 WHERE a.law_version_id=v.id AND a.reviewer_user_id=?) AS has_final_assessed,
                (SELECT COUNT(*) FROM community_law_appeals ap
                 WHERE ap.law_version_id=v.id) AS appeal_count,
                (SELECT COUNT(*) FROM community_law_appeals ap
                 WHERE ap.law_version_id=v.id AND ap.status IN ('submitted','under_review')) AS unresolved_appeals,
                (SELECT COUNT(*) FROM community_law_appeals ap
                 WHERE ap.law_version_id=v.id AND ap.status='upheld') AS upheld_appeals
         FROM community_law_final_review_cases fc
         JOIN community_law_versions v ON v.id=fc.law_version_id
         JOIN community_laws l ON l.id=v.law_id
         WHERE l.current_version_id=v.id
           AND fc.status IN ('pending','review_clear','conflict_found','needs_revision','appeal_period','appeal_hold','ready_for_enactment')
         ORDER BY fc.opened_at DESC LIMIT 100"
    );
    if ($final_review_stmt) {
        $final_review_stmt->bind_param('ii', $user_id, $user_id);
        if ($final_review_stmt->execute()) {
            $final_review_result = $final_review_stmt->get_result();
            while ($row = $final_review_result->fetch_assoc()) {
                if (
                    (int)$row['originating_community_id'] === $community_id ||
                    community_law_scope_matches($conn, (int)$row['version_id'], $community_id)
                ) $final_review_laws[] = $row;
            }
        }
        $final_review_stmt->close();
    }

    $appeal_stmt = $conn->prepare(
        "SELECT ap.id AS appeal_id, ap.law_version_id, ap.community_id,
                ap.submitted_by, ap.appeal_type, ap.description, ap.evidence,
                ap.status AS appeal_status, ap.created_at, ap.reviewer_quorum,
                v.title, l.law_code, l.id AS law_id,
                (SELECT COUNT(*) FROM community_law_appeal_assessments aa
                 WHERE aa.appeal_id=ap.id) AS assessment_count,
                (SELECT COUNT(*) FROM community_law_appeal_electorate ae
                 WHERE ae.appeal_id=ap.id AND ae.user_id=?) AS is_appeal_elector,
                (SELECT COUNT(*) FROM community_law_appeal_assessments mine
                 WHERE mine.appeal_id=ap.id AND mine.reviewer_user_id=?) AS has_appeal_assessed
         FROM community_law_appeals ap
         JOIN community_law_versions v ON v.id=ap.law_version_id
         JOIN community_laws l ON l.id=v.law_id
         ORDER BY ap.created_at DESC LIMIT 200"
    );
    if ($appeal_stmt) {
        $appeal_stmt->bind_param('ii', $user_id, $user_id);
        if ($appeal_stmt->execute()) {
            $appeal_result = $appeal_stmt->get_result();
            while ($appeal_row = $appeal_result->fetch_assoc()) {
                if (
                    (int)$appeal_row['community_id'] === $community_id ||
                    community_law_scope_matches($conn, (int)$appeal_row['law_version_id'], $community_id)
                ) $appeals_by_version[(int)$appeal_row['law_version_id']][] = $appeal_row;
            }
        }
        $appeal_stmt->close();
    }


    $passed_final_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, v.id AS version_id, v.title,
                fr.final_status
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         JOIN community_law_final_results fr ON fr.law_version_id=v.id
         LEFT JOIN community_law_final_review_cases fc ON fc.law_version_id=v.id
         WHERE l.originating_community_id=? AND l.status='voting'
           AND v.status='voting' AND fr.final_status='vote_passed'
           AND fc.law_version_id IS NULL
         ORDER BY v.created_at DESC LIMIT 30"
    );
    if ($passed_final_stmt) {
        $passed_final_stmt->bind_param('i', $community_id);
        if ($passed_final_stmt->execute()) {
            $passed_final_result = $passed_final_stmt->get_result();
            while ($row = $passed_final_result->fetch_assoc()) $passed_laws_pending_final_review[] = $row;
        }
        $passed_final_stmt->close();
    }

    $final_review_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, l.originating_community_id,
                l.status AS law_status, v.id AS version_id, v.title,
                v.status AS version_status, fc.status AS final_status,
                fc.reviewer_quorum, fc.appeal_period_days, fc.appeal_deadline,
                (SELECT COUNT(*) FROM community_law_final_review_assessments a
                 WHERE a.law_version_id=v.id) AS assessment_count,
                (SELECT COUNT(*) FROM community_law_final_review_electorate e
                 WHERE e.law_version_id=v.id AND e.user_id=?) AS is_final_elector,
                (SELECT COUNT(*) FROM community_law_final_review_assessments a
                 WHERE a.law_version_id=v.id AND a.reviewer_user_id=?) AS has_final_assessed,
                (SELECT COUNT(*) FROM community_law_appeals ap
                 WHERE ap.law_version_id=v.id) AS appeal_count,
                (SELECT COUNT(*) FROM community_law_appeals ap
                 WHERE ap.law_version_id=v.id AND ap.status IN ('submitted','under_review')) AS unresolved_appeals,
                (SELECT COUNT(*) FROM community_law_appeals ap
                 WHERE ap.law_version_id=v.id AND ap.status='upheld') AS upheld_appeals
         FROM community_law_final_review_cases fc
         JOIN community_law_versions v ON v.id=fc.law_version_id
         JOIN community_laws l ON l.id=v.law_id
         WHERE l.current_version_id=v.id
           AND fc.status IN ('pending','review_clear','conflict_found','needs_revision','appeal_period','appeal_hold','ready_for_enactment')
         ORDER BY fc.opened_at DESC LIMIT 100"
    );
    if ($final_review_stmt) {
        $final_review_stmt->bind_param('ii', $user_id, $user_id);
        if ($final_review_stmt->execute()) {
            $final_review_result = $final_review_stmt->get_result();
            while ($row = $final_review_result->fetch_assoc()) {
                if (
                    (int)$row['originating_community_id'] === $community_id ||
                    community_law_scope_matches($conn, (int)$row['version_id'], $community_id)
                ) $final_review_laws[] = $row;
            }
        }
        $final_review_stmt->close();
    }

    $appeal_stmt = $conn->prepare(
        "SELECT ap.id AS appeal_id, ap.law_version_id, ap.community_id,
                ap.submitted_by, ap.appeal_type, ap.description, ap.evidence,
                ap.status AS appeal_status, ap.created_at, ap.reviewer_quorum,
                v.title, l.law_code, l.id AS law_id,
                (SELECT COUNT(*) FROM community_law_appeal_assessments aa
                 WHERE aa.appeal_id=ap.id) AS assessment_count,
                (SELECT COUNT(*) FROM community_law_appeal_electorate ae
                 WHERE ae.appeal_id=ap.id AND ae.user_id=?) AS is_appeal_elector,
                (SELECT COUNT(*) FROM community_law_appeal_assessments mine
                 WHERE mine.appeal_id=ap.id AND mine.reviewer_user_id=?) AS has_appeal_assessed
         FROM community_law_appeals ap
         JOIN community_law_versions v ON v.id=ap.law_version_id
         JOIN community_laws l ON l.id=v.law_id
         ORDER BY ap.created_at DESC LIMIT 200"
    );
    if ($appeal_stmt) {
        $appeal_stmt->bind_param('ii', $user_id, $user_id);
        if ($appeal_stmt->execute()) {
            $appeal_result = $appeal_stmt->get_result();
            while ($appeal_row = $appeal_result->fetch_assoc()) {
                if (
                    (int)$appeal_row['community_id'] === $community_id ||
                    community_law_scope_matches($conn, (int)$appeal_row['law_version_id'], $community_id)
                ) $appeals_by_version[(int)$appeal_row['law_version_id']][] = $appeal_row;
            }
        }
        $appeal_stmt->close();
    }


    $final_assessment_result = $conn->query(
        "SELECT law_version_id, reviewer_user_id, verdict,
                charter_and_rights, non_discrimination, power_concentration,
                human_and_social_impact, environmental_impact,
                economic_and_resource_impact, future_generations_and_other_life,
                implementation_and_accountability, reasoning, evidence, submitted_at
         FROM community_law_final_review_assessments
         ORDER BY submitted_at ASC LIMIT 500"
    );
    if ($final_assessment_result) {
        while ($assessment_row = $final_assessment_result->fetch_assoc()) {
            $final_assessments_by_version[(int)$assessment_row['law_version_id']][] = $assessment_row;
        }
        $final_assessment_result->free();
    }

    $appeal_assessment_result = $conn->query(
        "SELECT appeal_id, reviewer_user_id, verdict, reasoning, evidence, submitted_at
         FROM community_law_appeal_assessments
         ORDER BY submitted_at ASC LIMIT 500"
    );
    if ($appeal_assessment_result) {
        while ($assessment_row = $appeal_assessment_result->fetch_assoc()) {
            $appeal_assessments_by_id[(int)$assessment_row['appeal_id']][] = $assessment_row;
        }
        $appeal_assessment_result->free();
    }

    $objection_stmt = $conn->prepare(
        "SELECT o.id, o.objection_type, o.status, o.description, o.created_at,
                v.title, l.law_code
         FROM community_law_objections o
         JOIN community_law_versions v ON v.id=o.law_version_id
         JOIN community_laws l ON l.id=v.law_id
         WHERE o.community_id=?
         ORDER BY o.created_at DESC LIMIT 10"
    );
    if ($objection_stmt) {
        $objection_stmt->bind_param('i', $community_id);
        if ($objection_stmt->execute()) {
            $objection_result = $objection_stmt->get_result();
            while ($row = $objection_result->fetch_assoc()) $recent_objections[] = $row;
        }
        $objection_stmt->close();
    }
}

$status = (string)($_GET['status'] ?? '');
$status_messages = [
    'draft_saved' => 'پیش‌نویس قانون ثبت شد؛ این پیش‌نویس هنوز الزام‌آور نیست.',
    'outcome_report_saved' => 'گزارش ثبت شد؛ تا زمان بررسی مستقل، محتوای آن تأییدشده محسوب نمی‌شود.',
    'outcome_report_failed' => 'گزارش ثبت نشد؛ عضویت، دورهٔ گزارش و ارتباط آن با اجرای همین قانون را بررسی کنید.',
    'outcome_review_saved' => 'ارزیابی مستقل ثبت شد؛ تا تکمیل سه ارزیابی، نتیجه نهایی نیست.',
    'outcome_review_verified' => 'گزارش با رأی دست‌کم دو ارزیاب از سه ارزیاب مستقل تأیید شد.',
    'outcome_review_disputed' => 'گزارش با رأی ارزیابان مورد اختلاف قرار گرفت و برای رسیدگی بیشتر علامت‌گذاری شد.',
    'outcome_review_failed' => 'ارزیابی ثبت نشد؛ عضویت در جامعه، تعارض منافع یا ثبت ارزیابی قبلی را بررسی کنید.',
    'outcome_followup_saved' => 'پاسخ یا شواهد تکمیلی ثبت شد و در تاریخچهٔ پرونده باقی می‌ماند.',
    'outcome_followup_failed' => 'ثبت پاسخ یا شواهد انجام نشد؛ فقط اعضای جامعهٔ مربوط و افراد مجاز می‌توانند اقدام کنند.',
    'outcome_reassessment_saved' => 'ارزیابی مستقل مرحلهٔ دوم ثبت شد؛ نتیجه پس از سه ارزیاب تازه مشخص می‌شود.',
    'outcome_reassessment_finalized' => 'رسیدگی مستقل مرحلهٔ دوم با رأی سه ارزیاب تازه نهایی شد؛ گزارش اولیه و وضعیت قانون تغییر خودکار نکرده‌اند.',
    'outcome_reassessment_failed' => 'رسیدگی تکمیلی ثبت نشد؛ وجود پاسخ مجری و شواهد تازه و نداشتن تعارض منافع را بررسی کنید.',
    'outcome_corrective_action_saved' => 'اقدام اصلاحی ثبت شد و مسئول اجرای تعیین‌شده می‌تواند پیشرفت آن را گزارش کند.',
    'outcome_corrective_action_failed' => 'اقدام اصلاحی ثبت نشد؛ نتیجهٔ نهایی باید اصلاح برنامهٔ اجرا باشد و مسئول اجرا باید تعیین شده باشد.',
    'outcome_corrective_update_saved' => 'گزارش پیشرفت اقدام اصلاحی ثبت شد.',
    'outcome_corrective_update_failed' => 'گزارش پیشرفت ثبت نشد؛ فقط مسئول تعیین‌شده می‌تواند وضعیت اقدام را به‌روزرسانی کند.',
    'submitted_for_review' => 'پیشنهاد برای غربالگری حقوقی مستقل ارسال شد؛ رأی‌گیری تا تکمیل این مرحله متوقف است.',
    'legal_assessment_saved' => 'ارزیابی مستقل ثبت شد.',
    'legal_assessment_not_allowed' => 'شما برای ارزیابی این پیشنهاد واجد شرایط نیستید یا مرحله بررسی بسته شده است.',
    'legal_screening_required' => 'غربالگری حقوقی مستقل هنوز تکمیل نشده یا تعارض و ابهامی در آن ثبت شده است؛ آغاز رأی‌گیری مجاز نیست.',
    'authority_review_required' => 'بررسی مستقل صلاحیت و انطباق با قوانین معتبر هنوز تأیید نشده است؛ آغاز رأی‌گیری مجاز نیست.',
    'population_snapshot_required' => 'وزن‌دهی بر اساس جمعیت تا زمان فراهم شدن آمار رسمی و snapshot معتبر جمعیت قابل استفاده نیست.',
    'weighted_expertise_profile_required' => 'برای رأی وزن‌دار، ابتدا باید پروفایل چندحوزه‌ای تخصصی همین موضوع پیشنهاد و با رأی جامعه تأیید شود.',
    'weighted_expertise_schema_missing' => 'زیرساخت پروفایل و snapshot وزن تخصصی هنوز در پایگاه داده نصب نشده است؛ رأی‌گیری وزن‌دار تا اجرای migration متوقف می‌ماند.',
    'weighted_expertise_profile_invalid' => 'پروفایل تخصصی موضوع ناقص است یا سهم حوزه‌های آن دقیقاً ۱۰۰ درصد نیست؛ آغاز رأی‌گیری متوقف شد.',
    'invalid_legal_assessment' => 'اطلاعات ارزیابی حقوقی معتبر نیست.',
    'law_scope_exceeds_authority' => 'جامعه نمی‌تواند به‌تنهایی قانون الزام‌آور را به جامعه مادر یا سطح بالاتر گسترش دهد.',
    'insufficient_reviewers' => 'برای بررسی مستقل، دست‌کم سه عضو واجد شرایط غیر از پیشنهاددهنده لازم است؛ ابتدا مشارکت اعضای بیشتری فراهم شود.',
    'authority_assessment_saved' => 'ارزیابی صلاحیت و قوانین مرتبط ثبت شد.',
    'authority_assessment_not_allowed' => 'شما برای این ارزیابی واجد شرایط نیستید یا مرحله بررسی بسته شده است.',
    'invalid_authority_assessment' => 'اطلاعات ارزیابی صلاحیت یا تعارض قوانین معتبر نیست.',
    'invalid_law_scope' => 'ساختار سلسله‌مراتب جوامع یا دامنه شمول معتبر نیست؛ بررسی صلاحیت متوقف شد.',
    'final_review_opened' => 'بررسی نهایی مستقل آغاز شد؛ ارزیابان این مرحله از ارزیابان قبلی جدا هستند.',
    'final_review_not_ready' => 'نتیجه رأی‌گیری مثبت و وضعیت قانون برای آغاز بررسی نهایی آماده نیست.',
    'final_review_already_open' => 'پرونده بررسی نهایی این نسخه قبلاً ایجاد شده است.',
    'insufficient_final_reviewers' => 'برای بررسی نهایی دست‌کم سه ارزیاب مستقلِ جدید لازم است؛ فعلاً تعداد کافی وجود ندارد.',
    'reference_law_changed' => 'یکی از قوانین مرجع از زمان بررسی قبلی تغییر کرده یا دیگر معتبر نیست؛ ابلاغ متوقف شد و بازبینی صلاحیت لازم است.',
    'final_assessment_saved' => 'ارزیابی نهایی مستقل ثبت شد.',
    'final_assessment_not_allowed' => 'شما برای این ارزیابی نهایی واجد شرایط نیستید یا مرحله بررسی بسته شده است.',
    'invalid_final_assessment' => 'اطلاعات ارزیابی نهایی معتبر نیست.',
    'appeal_saved' => 'اعتراض ثبت شد؛ تا پایان رسیدگی، ابلاغ قانون متوقف می‌ماند.',
    'appeal_period_closed' => 'مهلت اعتراض به پایان رسیده یا این مرحله باز نیست.',
    'insufficient_appeal_reviewers' => 'برای رسیدگی مستقل به این اعتراض، دست‌کم سه ارزیاب واجد شرایط لازم است.',
    'invalid_appeal' => 'اطلاعات اعتراض معتبر نیست.',
    'appeal_assessment_saved' => 'ارزیابی اعتراض ثبت شد.',
    'appeal_assessment_not_allowed' => 'شما برای رسیدگی به این اعتراض واجد شرایط نیستید یا رسیدگی بسته شده است.',
    'invalid_appeal_assessment' => 'اطلاعات ارزیابی اعتراض معتبر نیست.',
    'enactment_not_ready' => 'مهلت اعتراض هنوز پایان نیافته یا شرایط ابلاغ قانون کامل نیست.',
    'enactment_appeals_pending' => 'تا تعیین تکلیف همه اعتراض‌ها، قانون قابل ابلاغ نیست.',
    'law_enacted' => 'پس از تکمیل بررسی نهایی و مهلت اعتراض، قانون رسماً ابلاغ و لازم‌الاجرا شد.',
    'law_revision_created' => 'پیش‌نویس نسخه اصلاح‌شده ایجاد شد؛ نسخه قبلی و تمام سوابق آن محفوظ‌اند. این نسخه باید همه مراحل را از ابتدا طی کند.',
    'law_revision_not_allowed' => 'این نسخه در وضعیت مجاز برای اصلاح نیست یا هنوز اعتراض باز و تعیین‌تکلیف‌نشده دارد.',
    'law_revision_scope_missing' => 'دامنه شمول نسخه قبلی ناقص است؛ برای جلوگیری از تغییر ناخواسته دامنه، نسخه اصلاحی ساخته نشد.',
    'invalid_law_revision' => 'اطلاعات نسخه اصلاح‌شده معتبر نیست؛ عنوان، متن و خلاصه تغییرات الزامی هستند.',
    'final_review_opened' => 'بررسی نهایی مستقل آغاز شد؛ ارزیابان این مرحله از ارزیابان قبلی جدا هستند.',
    'final_review_not_ready' => 'نتیجه رأی‌گیری مثبت و وضعیت قانون برای آغاز بررسی نهایی آماده نیست.',
    'final_review_already_open' => 'پرونده بررسی نهایی این نسخه قبلاً ایجاد شده است.',
    'insufficient_final_reviewers' => 'برای بررسی نهایی دست‌کم سه ارزیاب مستقلِ جدید لازم است؛ فعلاً تعداد کافی وجود ندارد.',
    'reference_law_changed' => 'یکی از قوانین مرجع از زمان بررسی قبلی تغییر کرده یا دیگر معتبر نیست؛ ابلاغ متوقف شد و بازبینی صلاحیت لازم است.',
    'final_assessment_saved' => 'ارزیابی نهایی مستقل ثبت شد.',
    'final_assessment_not_allowed' => 'شما برای این ارزیابی نهایی واجد شرایط نیستید یا مرحله بررسی بسته شده است.',
    'invalid_final_assessment' => 'اطلاعات ارزیابی نهایی معتبر نیست.',
    'appeal_saved' => 'اعتراض ثبت شد؛ تا پایان رسیدگی، ابلاغ قانون متوقف می‌ماند.',
    'appeal_period_closed' => 'مهلت اعتراض به پایان رسیده یا این مرحله باز نیست.',
    'insufficient_appeal_reviewers' => 'برای رسیدگی مستقل به این اعتراض، دست‌کم سه ارزیاب واجد شرایط لازم است.',
    'invalid_appeal' => 'اطلاعات اعتراض معتبر نیست.',
    'appeal_assessment_saved' => 'ارزیابی اعتراض ثبت شد.',
    'appeal_assessment_not_allowed' => 'شما برای رسیدگی به این اعتراض واجد شرایط نیستید یا رسیدگی بسته شده است.',
    'invalid_appeal_assessment' => 'اطلاعات ارزیابی اعتراض معتبر نیست.',
    'enactment_not_ready' => 'مهلت اعتراض هنوز پایان نیافته یا شرایط ابلاغ قانون کامل نیست.',
    'enactment_appeals_pending' => 'تا تعیین تکلیف همه اعتراض‌ها، قانون قابل ابلاغ نیست.',
    'law_enacted' => 'پس از تکمیل بررسی نهایی و مهلت اعتراض، قانون رسماً ابلاغ و لازم‌الاجرا شد.',
    'tally_saved' => 'نتیجه مرکب رأی‌گیری طبق قواعد تأییدشده ثبت شد؛ لازم‌الاجرا شدن قانون مرحله جداگانه‌ای است.',
    'tally_already_done' => 'نتیجه این رأی‌گیری قبلاً محاسبه شده است.',
    'tally_not_ready' => 'مهلت رأی‌گیری هنوز پایان نیافته یا وضعیت پیشنهاد برای شمارش نهایی معتبر نیست.',
    'invalid_tally' => 'اطلاعات درخواست شمارش آرا معتبر نیست.',
    'law_vote_saved' => 'رأی شما در شکل‌گیری موضع این جامعه ثبت شد؛ تا پایان مهلت می‌توانید آن را تغییر دهید.',
    'direct_law_vote_saved' => 'رأی مستقیم شما ثبت شد؛ این رأی در کل سامانه فقط یک‌بار برای این قانون محاسبه می‌شود.',
    'discussion_saved' => 'دیدگاه مستدل شما در گفت‌وگوی این جامعه ثبت شد.',
    'invalid_discussion' => 'متن دیدگاه یا اطلاعات ارسالی معتبر نیست.',
    'invalid_law_vote' => 'رأی یا اطلاعات ارسالی معتبر نیست.',
    'not_law_elector' => 'نام شما در فهرست رأی‌دهندگان این رأی‌گیری ثبت نشده است.',
    'voting_started' => 'رأی‌گیری هفت‌روزه آغاز شد و فهرست اعضای واجد شرایط ثبت شد.',
    'voting_rules_required' => 'قواعد تصویب این نسخه هنوز تکمیل و تأیید نشده‌اند؛ برای جلوگیری از تصویب خودسرانه، آغاز رأی‌گیری متوقف شد.',
    'invalid_voting_rules' => 'درصدها، روش رأی‌گیری یا استدلال قواعد معتبر نیستند.',
    'voting_rules_not_configurable' => 'قواعد فقط پس از تأیید صلاحیت و برای نسخه جاریِ پیشنهادشده، توسط عضو جامعه مبدأ قابل پیشنهاد هستند.',
    'voting_rules_already_approved' => 'قواعد این نسخه قبلاً تأیید شده‌اند و قابل جایگزینی نیستند.',
    'voting_rules_review_pending' => 'برای این نسخه یک پیشنهاد قواعد در حال بررسی وجود دارد.',
    'insufficient_rule_reviewers' => 'برای بررسی مستقل قواعد، دست‌کم سه عضو واجد شرایط و مستقل لازم است.',
    'voting_rules_submitted' => 'پیشنهاد قواعد رأی‌گیری ثبت شد؛ پنل مستقل هفت روز برای بررسی فرصت دارد.',
    'invalid_voting_rule_assessment' => 'اطلاعات ارزیابی قواعد رأی‌گیری معتبر نیست.',
    'voting_rule_assessment_not_allowed' => 'شما عضو پنل مستقل این پیشنهاد نیستید یا مهلت ارزیابی پایان یافته است.',
    'voting_rule_vote_duplicate' => 'ارزیابی شما قبلاً ثبت شده است و قابل تغییر نیست.',
    'voting_rule_assessment_saved' => 'ارزیابی مستدل شما ثبت شد.',
    'voting_rules_not_finalizable' => 'مهلت بررسی پایان نیافته یا وضعیت پیشنهاد و صلاحیت آن معتبر نیست.',
    'insufficient_rule_votes' => 'برای تصمیم‌گیری دست‌کم سه ارزیابی مستقل لازم است.',
    'voting_rules_approved' => 'قواعد رأی‌گیری با رأی دوسوم ارزیابان مستقل تأیید شد.',
    'voting_rules_rejected' => 'پیشنهاد قواعد به حدنصاب دوسوم موافقت نرسید؛ می‌توان پیشنهاد تازه‌ای ثبت کرد.',
    'invalid_vote_start' => 'این پیشنهاد در وضعیت قابل رأی‌گیری نیست.',
    'invalid_law_scope' => 'دامنه شمول قانون معتبر نیست.',
    'empty_law_electorate' => 'در جوامع مشمول، عضو واجد شرایطی برای رأی‌گیری یافت نشد.',
    'invalid_submission' => 'این پیش‌نویس قابل ارسال نیست؛ ممکن است قبلاً ارسال شده یا متعلق به شما نباشد.',
    'objection_saved' => 'اعتراض شما ثبت شد و برای بررسی باقی می‌ماند.',
    'not_member' => 'برای این عملیات باید عضو جامعه باشید.',
    'invalid' => 'اطلاعات واردشده معتبر نیست یا از حد مجاز بیشتر است.',
    'invalid_source' => 'موضوع یا ایدهٔ مرجع به این جامعه تعلق ندارد.',
    'invalid_objection' => 'قانون یا اطلاعات اعتراض معتبر نیست.',
    'schema' => 'ساختار قوانین هنوز در پایگاه داده نصب نشده است.',
    'save_error' => 'ذخیره‌سازی انجام نشد. لطفاً دوباره تلاش کنید.'
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>قوانین جامعه - <?= community_laws_escape($community['name']) ?></title>
<style>
body{font-family:tahoma,Arial,sans-serif;background:#eef3f8;margin:0;padding:10px;color:#263238}
.container{max-width:900px;margin:0 auto}
.card{background:#fff;border:1px solid #dce5ef;border-radius:12px;padding:14px;margin:0 0 12px;box-shadow:0 2px 8px rgba(25,45,70,.045)}
h2,h3{margin-top:0;line-height:1.8}
p{line-height:1.9}
label{display:block;margin:10px 0 5px;font-size:14px}
input,textarea,select,button{box-sizing:border-box;width:100%;font:inherit;font-size:15px;padding:11px;border:1px solid #c5cfdb;border-radius:8px;margin-top:5px}
textarea{min-height:120px;line-height:1.9;resize:vertical}
button{background:#2065d1;color:#fff;border:0;cursor:pointer;margin-top:12px;font-weight:bold}
a{color:#1769aa;text-decoration:none;font-weight:bold}
.muted{color:#64748b;font-size:13px}
.notice{padding:10px 12px;border-radius:8px;background:#eef6ff;border:1px solid #cfe2f7;line-height:1.9;margin:10px 0}
.warning{background:#fff7e6;border-color:#f2d59a}
.law-text{white-space:pre-wrap;overflow-wrap:anywhere;line-height:2}
.meta{font-size:12px;color:#64748b;line-height:1.9}
.scope-check{display:flex;align-items:flex-start;gap:8px;line-height:1.9}
.scope-check input{width:auto;margin-top:5px}
details>summary{cursor:pointer;font-weight:bold}
@media(max-width:600px){body{padding:7px}.card{padding:12px;border-radius:11px}h2{font-size:19px}input,textarea,select,button{font-size:16px}}
</style>
</head>
<body>
<div class="container">
<div class="card">
    <h2>⚖️ قوانین جامعه: <?= community_laws_escape($community['name']) ?></h2>
    <p class="muted">قوانین معتبر جامعه مادر، در صورت قرار داشتن این جامعه در دامنه شمول آن‌ها، در این صفحه نیز نمایش داده می‌شوند.</p>
    <p><a href="community.php?id=<?= $community_id ?>">↩ بازگشت به صفحه جامعه</a></p>
    <p><a href="community_law_change_cases.php?community_id=<?= $community_id ?>">رسیدگی به درخواست‌های تعلیق، لغو و بازبینی اعتبار قوانین</a></p>
    <p><a href="community_law_conformity_cases.php?community_id=<?= $community_id ?>">پرونده‌های هم‌ترازی قوانین محلی با قوانین مادر</a></p>
    <p><a href="community_law_amendments.php?community_id=<?= $community_id ?>">پیشنهاد اصلاح قوانین محلی و پیگیری نسخه‌ها</a></p>
</div>

<?php if (isset($status_messages[$status])): ?>
<div class="card notice"><?= community_laws_escape($status_messages[$status]) ?></div>
<?php endif; ?>

<?php if (!$schema_ready): ?>
<div class="card notice warning">
    <strong>ساختار پایگاه داده هنوز نصب نشده است.</strong>
    <p>فایل migration نظام قوانین باید ابتدا در پایگاه داده اجرا شود. تا آن زمان این صفحه هیچ داده‌ای را تغییر نمی‌دهد.</p>
</div>
<?php else: ?>

<div class="card">
    <h3>قوانین لازم‌الرعایه در این جامعه</h3>
    <?php if (!$effective_laws): ?>
        <p class="muted">هنوز قانون مصوب و لازم‌الاجرایی برای این جامعه ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($effective_laws as $law): ?>
            <?php
            $previous_versions=[];
            $version_history_stmt=$conn->prepare(
                "SELECT id,version_number,title,law_text,change_summary,approved_at,created_at
                 FROM community_law_versions
                 WHERE law_id=? AND id<>? AND status='superseded'
                 ORDER BY version_number DESC LIMIT 20"
            );
            if ($version_history_stmt) {
                $history_law_id=(int)$law['law_id'];
                $current_version_id=(int)$law['version_id'];
                $version_history_stmt->bind_param('ii',$history_law_id,$current_version_id);
                if ($version_history_stmt->execute()) {
                    $version_history_result=$version_history_stmt->get_result();
                    while ($version_history_row=$version_history_result->fetch_assoc()) {
                        $previous_versions[]=$version_history_row;
                    }
                }
                $version_history_stmt->close();
            }
            $implementation_rows=[];
            $implementation_sql="SELECT i.community_id,cm.name AS community_name,i.responsible_user_id,
                                        i.local_plan,i.status,i.started_at,i.target_date,i.completed_at,i.progress_report
                                 FROM community_law_implementations i
                                 JOIN communities cm ON cm.id=i.community_id
                                 WHERE i.law_version_id=?";
            $show_all_implementations=$is_member &&
                (int)$law['originating_community_id']===$community_id;
            if (!$show_all_implementations) $implementation_sql.=" AND i.community_id=?";
            $implementation_sql.=" ORDER BY cm.name ASC";
            $implementation_stmt=$conn->prepare($implementation_sql);
            if ($implementation_stmt) {
                if ($show_all_implementations) {
                    $implementation_version_id=(int)$law['version_id'];
                    $implementation_stmt->bind_param('i',$implementation_version_id);
                } else {
                    $implementation_version_id=(int)$law['version_id'];
                    $implementation_community_id=$community_id;
                    $implementation_stmt->bind_param('ii',$implementation_version_id,$implementation_community_id);
                }
                if ($implementation_stmt->execute()) {
                    $implementation_result=$implementation_stmt->get_result();
                    while ($implementation_row=$implementation_result->fetch_assoc()) {
                        $implementation_rows[]=$implementation_row;
                    }
                }
                $implementation_stmt->close();
            }
            $outcome_reports=[];
            if ($outcome_reports_ready) {
                $reports_sql="SELECT r.id,r.community_id,cm.name AS community_name,r.submitted_by,
                                     r.report_type,r.period_start,r.period_end,r.summary,r.evidence,
                                     r.metrics_json,r.review_status,r.created_at,
                                     i.responsible_user_id,
                                     (SELECT COUNT(*) FROM community_law_outcome_reviews rv WHERE rv.report_id=r.id) AS review_count,
                                     (SELECT COUNT(*) FROM community_law_outcome_reviews rv WHERE rv.report_id=r.id AND rv.verdict='confirm') AS confirm_count,
                                     (SELECT COUNT(*) FROM community_law_outcome_reviews rv WHERE rv.report_id=r.id AND rv.verdict='challenge') AS challenge_count,
                                     (SELECT COUNT(*) FROM community_law_outcome_reviews rv WHERE rv.report_id=r.id AND rv.reviewer_user_id=?) AS my_review_count
                              FROM community_law_outcome_reports r
                              JOIN communities cm ON cm.id=r.community_id
                              JOIN community_law_implementations i ON i.id=r.implementation_id
                              WHERE r.law_id=? AND r.law_version_id=?";
                if (!$show_all_implementations) $reports_sql.=" AND r.community_id=?";
                $reports_sql.=" ORDER BY r.created_at DESC,r.id DESC LIMIT 100";
                $reports_stmt=$conn->prepare($reports_sql);
                if ($reports_stmt) {
                    $reports_law_id=(int)$law['law_id'];
                    $reports_version_id=(int)$law['version_id'];
                    if ($show_all_implementations) {
                        $reports_stmt->bind_param('iii',$user_id,$reports_law_id,$reports_version_id);
                    } else {
                        $reports_community_id=$community_id;
                        $reports_stmt->bind_param('iiii',$user_id,$reports_law_id,$reports_version_id,$reports_community_id);
                    }
                    if ($reports_stmt->execute()) {
                        $reports_result=$reports_stmt->get_result();
                        while ($report_row=$reports_result->fetch_assoc()) $outcome_reports[]=$report_row;
                    }
                    $reports_stmt->close();
                }
            }
            $outcome_reviews_by_report=[];
            if ($outcome_reports && $outcome_reports_ready) {
                $outcome_report_ids=array_values(array_filter(array_map(
                    static fn($row)=>(int)$row['id'],
                    $outcome_reports
                ),static fn($id)=>$id>0));
                if ($outcome_report_ids) {
                    $report_id_list=implode(',', $outcome_report_ids);
                    $review_result=$conn->query(
                        "SELECT report_id,reviewer_user_id,verdict,rationale,human_impact,social_impact,
                                environmental_impact,economic_impact,evidence,created_at
                         FROM community_law_outcome_reviews
                         WHERE report_id IN ($report_id_list)
                         ORDER BY report_id,id"
                    );
                    if ($review_result) {
                        while ($review_row=$review_result->fetch_assoc()) {
                            $outcome_reviews_by_report[(int)$review_row['report_id']][]=$review_row;
                        }
                        $review_result->free();
                    }
                }
            }
            $outcome_followups_by_report=[];
            if ($outcome_reports && $outcome_reports_ready) {
                $outcome_report_ids=array_values(array_filter(array_map(
                    static fn($row)=>(int)$row['id'],
                    $outcome_reports
                ),static fn($id)=>$id>0));
                if ($outcome_report_ids) {
                    $report_id_list=implode(',', $outcome_report_ids);
                    $followup_result=$conn->query(
                        "SELECT report_id,submitted_by,entry_type,body,evidence,created_at
                         FROM community_law_outcome_followups
                         WHERE report_id IN ($report_id_list)
                         ORDER BY report_id,id"
                    );
                    if ($followup_result) {
                        while ($followup_row=$followup_result->fetch_assoc()) {
                            $outcome_followups_by_report[(int)$followup_row['report_id']][]=$followup_row;
                        }
                        $followup_result->free();
                    }
                }
            }
            $outcome_reassessment_cases_by_report=[];
            $outcome_reassessment_reviews_by_report=[];
            if ($outcome_reports && $outcome_reports_ready) {
                $outcome_report_ids=array_values(array_filter(array_map(
                    static fn($row)=>(int)$row['id'],
                    $outcome_reports
                ),static fn($id)=>$id>0));
                if ($outcome_report_ids) {
                    $report_id_list=implode(',', $outcome_report_ids);
                    $case_result=$conn->query(
                        "SELECT id,report_id,status,final_outcome,finalized_at
                         FROM community_law_outcome_reassessments
                         WHERE report_id IN ($report_id_list)"
                    );
                    if ($case_result) {
                        while ($case_row=$case_result->fetch_assoc()) {
                            $outcome_reassessment_cases_by_report[(int)$case_row['report_id']]=$case_row;
                        }
                        $case_result->free();
                    }
                    $reassessment_review_result=$conn->query(
                        "SELECT report_id,reviewer_user_id,outcome,rationale,evidence,created_at
                         FROM community_law_outcome_reassessment_reviews
                         WHERE report_id IN ($report_id_list)
                         ORDER BY report_id,id"
                    );
                    if ($reassessment_review_result) {
                        while ($review_row=$reassessment_review_result->fetch_assoc()) {
                            $outcome_reassessment_reviews_by_report[(int)$review_row['report_id']][]=$review_row;
                        }
                        $reassessment_review_result->free();
                    }
                }
            }
            $outcome_corrective_actions_by_report=[];
            $outcome_corrective_updates_by_action=[];
            if ($outcome_reports && $outcome_reports_ready) {
                $report_id_list=implode(',',array_values(array_filter(array_map(
                    static fn($row)=>(int)$row['id'],$outcome_reports
                ),static fn($id)=>$id>0)));
                if ($report_id_list!=='') {
                    $actions_result=$conn->query(
                        "SELECT id,report_id,community_id,created_by,responsible_user_id,title,
                                action_details,success_criteria,target_date,created_at
                         FROM community_law_outcome_corrective_actions
                         WHERE report_id IN ($report_id_list) ORDER BY id"
                    );
                    if ($actions_result) {
                        while ($action_row=$actions_result->fetch_assoc()) {
                            $outcome_corrective_actions_by_report[(int)$action_row['report_id']][]=$action_row;
                        }
                        $actions_result->free();
                    }
                    $action_ids=[];
                    foreach ($outcome_corrective_actions_by_report as $rows) {
                        foreach ($rows as $action_row) $action_ids[]=(int)$action_row['id'];
                    }
                    if ($action_ids) {
                        $action_id_list=implode(',',array_filter($action_ids,static fn($id)=>$id>0));
                        $updates_result=$conn->query(
                            "SELECT action_id,status,update_text,evidence,submitted_by,created_at
                             FROM community_law_outcome_corrective_updates
                             WHERE action_id IN ($action_id_list) ORDER BY id"
                        );
                        if ($updates_result) {
                            while ($update_row=$updates_result->fetch_assoc()) {
                                $outcome_corrective_updates_by_action[(int)$update_row['action_id']][]=$update_row;
                            }
                            $updates_result->free();
                        }
                    }
                }
            }
            $can_submit_outcome_report=false;
            if ($is_member && $outcome_reports_ready) {
                foreach ($implementation_rows as $implementation_row) {
                    if ((int)$implementation_row['community_id']===$community_id) {
                        $can_submit_outcome_report=true;
                        break;
                    }
                }
            }
            ?>
            <div class="card" style="background:#fbfdff;box-shadow:none">
                <strong><?= community_laws_escape($law['title']) ?></strong>
                <div class="meta">
                    شناسه: <?= community_laws_escape($law['law_code']) ?>
                    — نسخه <?= (int)$law['version_number'] ?>
                    <?= $law['originating_community_id'] === $community_id
                        ? '— مصوب همین جامعه'
                        : '— قانون بالادستی از جامعهٔ «' . community_laws_escape($law['originating_community_name']) . '» (شناسهٔ جامعه: ' . (int)$law['originating_community_id'] . ')' ?>
                </div>
                <div class="law-text"><?= community_laws_escape($law['law_text']) ?></div>
                <details style="margin-top:10px">
                    <summary>سوابق نسخه‌ها و مقایسه با نسخهٔ فعلی (<?= count($previous_versions) ?> نسخهٔ پیشین)</summary>
                    <?php if (!$previous_versions): ?>
                        <p class="muted">این نخستین نسخهٔ ثبت‌شده و مصوب این قانون است؛ هنوز نسخهٔ اصلاح‌شده‌ای ندارد.</p>
                    <?php else: ?>
                        <p class="muted">نسخهٔ فعلی در بالای پرونده نمایش داده شده است. متن و خلاصهٔ تغییرات نسخه‌های پیشین در ادامه برای مقایسه حفظ شده‌اند.</p>
                        <?php foreach ($previous_versions as $previous_version): ?>
                            <div class="notice" style="background:#f8fafc;border-color:#dbe4ee">
                                <strong>نسخهٔ <?= (int)$previous_version['version_number'] ?> — <?= community_laws_escape($previous_version['title']) ?></strong>
                                <div class="meta">
                                    تصویب: <?= community_laws_escape($previous_version['approved_at'] ?? 'تاریخ ثبت نشده') ?>
                                    — ثبت: <?= community_laws_escape($previous_version['created_at']) ?>
                                </div>
                                <?php if (!empty($previous_version['change_summary'])): ?>
                                    <p><strong>خلاصهٔ تغییرات ثبت‌شده در نسخهٔ بعدی:</strong></p>
                                    <p><?= nl2br(community_laws_escape($previous_version['change_summary'])) ?></p>
                                <?php endif; ?>
                                <div class="law-text"><?= community_laws_escape($previous_version['law_text']) ?></div>
                                <hr style="border:0;border-top:1px solid #dbe4ee;margin:12px 0">
                                <p><strong>متن نسخهٔ فعلی — نسخهٔ <?= (int)$law['version_number'] ?></strong></p>
                                <div class="law-text"><?= community_laws_escape($law['law_text']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </details>
                <details style="margin-top:10px">
                    <summary>وضعیت اجرای قانون در جوامع مشمول (<?= count($implementation_rows) ?>)</summary>
                    <?php if (!$implementation_rows): ?>
                        <p class="muted">برای این جامعه هنوز گزارش اجرایی ثبت نشده یا دسترسی به گزارش‌های سایر زیرجامعه‌ها وجود ندارد.</p>
                    <?php else: ?>
                        <?php foreach ($implementation_rows as $implementation): ?>
                            <?php
                            $implementation_status_labels=[
                                'pending'=>'در انتظار آغاز',
                                'in_progress'=>'در حال اجرا',
                                'blocked'=>'با مانع روبه‌رو',
                                'completed'=>'تکمیل‌شده',
                                'not_applicable'=>'غیرقابل اعمال',
                                'suspended'=>'متوقف به‌علت تعلیق قانون'
                            ];
                            ?>
                            <div class="notice" style="background:#f8fafc;border-color:#dbe4ee">
                                <strong><?= community_laws_escape($implementation['community_name']) ?></strong>
                                <div class="meta">
                                    وضعیت: <?= community_laws_escape($implementation_status_labels[$implementation['status']] ?? $implementation['status']) ?>
                                    <?php if (!empty($implementation['responsible_user_id'])): ?>
                                        — مسئول اجرا تعیین شده است
                                    <?php else: ?>
                                        — مسئول اجرا هنوز تعیین نشده است
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($implementation['local_plan'])): ?>
                                    <p><strong>برنامهٔ محلی اجرا:</strong></p>
                                    <div class="law-text"><?= community_laws_escape($implementation['local_plan']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($implementation['target_date'])): ?>
                                    <p class="meta">تاریخ هدف: <?= community_laws_escape($implementation['target_date']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($implementation['progress_report'])): ?>
                                    <p><strong>گزارش پیشرفت و پیامدها:</strong></p>
                                    <div class="law-text"><?= community_laws_escape($implementation['progress_report']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($implementation['completed_at'])): ?>
                                    <p class="meta">تاریخ تکمیل: <?= community_laws_escape($implementation['completed_at']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </details>
                <details style="margin-top:10px">
                    <summary>گزارش پیشرفت و پیامدهای واقعی قانون (<?= count($outcome_reports) ?>)</summary>
                    <p class="muted">گزارش‌های اجرا، شواهد و اقدام‌های اصلاحی در تاریخچهٔ قانون نگهداری می‌شوند. این اطلاعات به‌تنهایی دانش تأییدشده یا تصمیم جمعی جدید نیستند؛ برای تثبیت آموخته‌ها به‌عنوان دانش، باید از مسیر مستقل دانش و ارزیابی آن عبور کنند.</p>
                    <?php if (!$outcome_reports_ready): ?>
                        <p class="muted">ساختار ثبت گزارش هنوز نصب نشده است؛ پس از نصب migration این بخش فعال می‌شود.</p>
                    <?php elseif (!$outcome_reports): ?>
                        <p class="muted">هنوز گزارشی برای این نسخهٔ قانون ثبت نشده است.</p>
                    <?php else: ?>
                        <?php foreach ($outcome_reports as $outcome_report): ?>
                            <div class="notice" style="background:#f8fafc;border-color:#dbe4ee">
                                <strong><?= $outcome_report['report_type']==='outcome'?'ارزیابی پیامد':'گزارش پیشرفت' ?> — <?= community_laws_escape($outcome_report['community_name']) ?></strong>
                                <div class="meta">
                                    دوره: <?= community_laws_escape($outcome_report['period_start']) ?> تا <?= community_laws_escape($outcome_report['period_end']) ?>
                                    — ثبت: <?= community_laws_escape($outcome_report['created_at']) ?>
                                    — وضعیت بررسی: <?= ['submitted'=>'در انتظار بررسی','under_review'=>'در حال بررسی مستقل','verified'=>'تأییدشده با رأی ارزیابان','disputed'=>'مورد اختلاف؛ نیازمند رسیدگی'][$outcome_report['review_status']] ?? $outcome_report['review_status'] ?>
                                    — ارزیابی مستقل: <?= (int)$outcome_report['review_count'] ?>/۳ (تأیید: <?= (int)$outcome_report['confirm_count'] ?>، اختلاف: <?= (int)$outcome_report['challenge_count'] ?>)
                                </div>
                                <p><?= nl2br(community_laws_escape($outcome_report['summary'])) ?></p>
                                <?php if (!empty($outcome_report['evidence'])): ?>
                                    <p><strong>شواهد:</strong></p>
                                    <p><?= nl2br(community_laws_escape($outcome_report['evidence'])) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($outcome_report['metrics_json'])): ?>
                                    <p><strong>شاخص‌های عددی:</strong></p>
                                    <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= community_laws_escape(json_encode(json_decode($outcome_report['metrics_json'],true),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
                                <?php endif; ?>
                                <?php foreach (($outcome_reviews_by_report[(int)$outcome_report['id']] ?? []) as $outcome_review): ?>
                                    <div class="notice" style="margin-top:8px;background:#fff;border-color:#dbe4ee">
                                        <strong><?= $outcome_review['verdict']==='confirm'?'ارزیابی موافق':'ارزیابی منتقدانه' ?></strong>
                                        <span class="meta"> — <?= community_laws_escape($outcome_review['created_at']) ?></span>
                                        <p><?= nl2br(community_laws_escape($outcome_review['rationale'])) ?></p>
                                        <?php foreach ([
                                            'human_impact'=>'اثر انسانی',
                                            'social_impact'=>'اثر اجتماعی',
                                            'environmental_impact'=>'اثر زیست‌محیطی',
                                            'economic_impact'=>'اثر اقتصادی',
                                            'evidence'=>'شواهد ارزیاب'
                                        ] as $impact_key=>$impact_label): ?>
                                            <?php if (!empty($outcome_review[$impact_key])): ?>
                                                <p><strong><?= $impact_label ?>:</strong> <?= nl2br(community_laws_escape($outcome_review[$impact_key])) ?></p>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach (($outcome_followups_by_report[(int)$outcome_report['id']] ?? []) as $followup): ?>
                                    <div class="notice" style="margin-top:8px;background:#fff;border-color:#dbe4ee">
                                        <strong><?= $followup['entry_type']==='executor_response'?'پاسخ گزارش‌دهنده یا مسئول اجرا':'شواهد تکمیلی عضو جامعه' ?></strong>
                                        <span class="meta"> — <?= community_laws_escape($followup['created_at']) ?></span>
                                        <p><?= nl2br(community_laws_escape($followup['body'])) ?></p>
                                        <?php if (!empty($followup['evidence'])): ?>
                                            <p><strong>مدارک:</strong> <?= nl2br(community_laws_escape($followup['evidence'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php
                                $can_add_outcome_evidence=(bool)$is_member &&
                                    (int)$outcome_report['community_id']===$community_id &&
                                    $outcome_report['review_status']==='disputed';
                                $can_add_executor_response=$can_add_outcome_evidence &&
                                    ($user_id===(int)$outcome_report['submitted_by'] ||
                                     $user_id===(int)($outcome_report['responsible_user_id']??0));
                                ?>
                                <?php if ($can_add_outcome_evidence): ?>
                                    <details style="margin-top:8px">
                                        <summary>افزودن پاسخ یا شواهد برای رسیدگی تکمیلی</summary>
                                        <p class="muted">این ثبت، گزارش و ارزیابی‌های قبلی را تغییر نمی‌دهد و به‌تنهایی قانون را تعلیق نمی‌کند.</p>
                                        <?php if ($can_add_executor_response): ?>
                                            <form method="post" action="community_law_outcome_action.php">
                                                <input type="hidden" name="action" value="followup">
                                                <input type="hidden" name="entry_type" value="executor_response">
                                                <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                                <input type="hidden" name="community_id" value="<?= $community_id ?>">
                                                <input type="hidden" name="report_id" value="<?= (int)$outcome_report['id'] ?>">
                                                <label>پاسخ مستند گزارش‌دهنده یا مسئول اجرا</label>
                                                <textarea name="body" maxlength="20000" required></textarea>
                                                <label>مدارک و منابع پاسخ (اختیاری)</label>
                                                <textarea name="followup_evidence" maxlength="20000"></textarea>
                                                <button type="submit">ثبت پاسخ</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="community_law_outcome_action.php">
                                            <input type="hidden" name="action" value="followup">
                                            <input type="hidden" name="entry_type" value="additional_evidence">
                                            <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                            <input type="hidden" name="community_id" value="<?= $community_id ?>">
                                            <input type="hidden" name="report_id" value="<?= (int)$outcome_report['id'] ?>">
                                            <label>شواهد یا توضیحات تکمیلی عضو جامعه</label>
                                            <textarea name="body" maxlength="20000" required></textarea>
                                            <label>مدارک و منابع (اختیاری)</label>
                                            <textarea name="followup_evidence" maxlength="20000"></textarea>
                                            <button type="submit">ثبت شواهد تکمیلی</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <?php
                                $report_followups=$outcome_followups_by_report[(int)$outcome_report['id']] ?? [];
                                $has_executor_response=false;
                                $has_additional_evidence=false;
                                foreach ($report_followups as $entry) {
                                    if ($entry['entry_type']==='executor_response') $has_executor_response=true;
                                    if ($entry['entry_type']==='additional_evidence') $has_additional_evidence=true;
                                }
                                $reassessment_case=$outcome_reassessment_cases_by_report[(int)$outcome_report['id']] ?? null;
                                $reassessment_reviews=$outcome_reassessment_reviews_by_report[(int)$outcome_report['id']] ?? [];
                                $my_original_review=false;
                                foreach (($outcome_reviews_by_report[(int)$outcome_report['id']] ?? []) as $original_review) {
                                    if ((int)$original_review['reviewer_user_id']===$user_id) { $my_original_review=true; break; }
                                }
                                $my_reassessment_review=false;
                                foreach ($reassessment_reviews as $second_review) {
                                    if ((int)$second_review['reviewer_user_id']===$user_id) { $my_reassessment_review=true; break; }
                                }
                                $can_reassess_outcome=(bool)$is_member &&
                                    (int)$outcome_report['community_id']===$community_id &&
                                    $outcome_report['review_status']==='disputed' &&
                                    $has_executor_response && $has_additional_evidence &&
                                    (int)$outcome_report['submitted_by']!==$user_id &&
                                    (int)($outcome_report['responsible_user_id']??0)!==$user_id &&
                                    !$my_original_review && !$my_reassessment_review &&
                                    (!$reassessment_case || $reassessment_case['status']==='open');
                                ?>
                                <?php if ($outcome_report['review_status']==='disputed'): ?>
                                    <div class="notice" style="margin-top:10px;background:#f8fafc;border-color:#cbd5e1">
                                        <strong>رسیدگی مستقل مرحلهٔ دوم</strong>
                                        <p class="muted">پس از ثبت پاسخ مسئول اجرا و شواهد تکمیلی، سه عضو تازه که در ارزیابی نخست شرکت نکرده‌اند و تعارض منافع ندارند، نتیجه را بررسی می‌کنند.</p>
                                        <p>تعداد ارزیابی‌های تازه: <?= count($reassessment_reviews) ?> از ۳</p>
                                        <?php foreach ($reassessment_reviews as $second_review): ?>
                                            <div style="border-top:1px solid #e2e8f0;padding-top:7px;margin-top:7px">
                                                <strong><?= [
                                                    'confirm_report'=>'تأیید گزارش',
                                                    'revise_execution_plan'=>'اصلاح برنامهٔ اجرا',
                                                    'needs_more_review'=>'نیازمند بررسی بیشتر',
                                                    'recommend_law_review'=>'پیشنهاد بازنگری قانون'
                                                ][$second_review['outcome']] ?? 'نتیجهٔ ثبت‌شده' ?></strong>
                                                <span class="meta"> — <?= community_laws_escape($second_review['created_at']) ?></span>
                                                <p><?= nl2br(community_laws_escape($second_review['rationale'])) ?></p>
                                                <?php if (!empty($second_review['evidence'])): ?>
                                                    <p><strong>شواهد:</strong> <?= nl2br(community_laws_escape($second_review['evidence'])) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($reassessment_case && $reassessment_case['status']==='finalized'): ?>
                                            <p><strong>نتیجهٔ نهایی:</strong> <?= [
                                                'confirm_report'=>'تأیید گزارش',
                                                'revise_execution_plan'=>'اصلاح برنامهٔ اجرا',
                                                'needs_more_review'=>'نیازمند بررسی بیشتر',
                                                'recommend_law_review'=>'پیشنهاد بازنگری قانون'
                                            ][$reassessment_case['final_outcome']] ?? 'نتیجهٔ ثبت‌شده' ?></p>
                                        <?php elseif ($can_reassess_outcome): ?>
                                            <form method="post" action="community_law_outcome_action.php">
                                                <input type="hidden" name="action" value="reassess">
                                                <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                                <input type="hidden" name="community_id" value="<?= $community_id ?>">
                                                <input type="hidden" name="report_id" value="<?= (int)$outcome_report['id'] ?>">
                                                <label>نتیجهٔ پیشنهادی ارزیاب مستقل</label>
                                                <select name="reassessment_outcome" required>
                                                    <option value="">انتخاب نتیجه</option>
                                                    <option value="confirm_report">تأیید گزارش</option>
                                                    <option value="revise_execution_plan">اصلاح برنامهٔ اجرا</option>
                                                    <option value="needs_more_review">نیازمند بررسی بیشتر</option>
                                                    <option value="recommend_law_review">پیشنهاد بازنگری قانون</option>
                                                </select>
                                                <label>استدلال و بررسی شواهد</label>
                                                <textarea name="reassessment_rationale" maxlength="20000" required></textarea>
                                                <label>شواهد و منابع (اختیاری)</label>
                                                <textarea name="reassessment_evidence" maxlength="20000"></textarea>
                                                <button type="submit">ثبت ارزیابی مستقل مرحلهٔ دوم</button>
                                            </form>
                                        <?php elseif (!$has_executor_response || !$has_additional_evidence): ?>
                                            <p class="muted">برای آغاز رسیدگی دوم، ابتدا باید هم پاسخ گزارش‌دهنده یا مسئول اجرا و هم شواهد تکمیلی عضو جامعه ثبت شوند.</p>
                                        <?php endif; ?>
                                        <p class="muted">این نتیجه گزارش اولیه را بازنویسی نمی‌کند و قانون را خودکار تعلیق یا لغو نمی‌کند. پیشنهاد بازنگری قانون باید از فرایند رسمی تغییر قانون عبور کند.</p>
                                    </div>
                                <?php endif; ?>
                                <?php
                                $corrective_actions=$outcome_corrective_actions_by_report[(int)$outcome_report['id']] ?? [];
                                $can_create_corrective_action=(bool)$is_member &&
                                    (int)$outcome_report['community_id']===$community_id &&
                                    $reassessment_case && $reassessment_case['status']==='finalized' &&
                                    $reassessment_case['final_outcome']==='revise_execution_plan' &&
                                    (int)($outcome_report['responsible_user_id']??0)>0;
                                ?>
                                <?php if ($reassessment_case && $reassessment_case['status']==='finalized' &&
                                    $reassessment_case['final_outcome']==='revise_execution_plan'): ?>
                                    <div class="notice" style="margin-top:10px;background:#f8fafc;border-color:#cbd5e1">
                                        <strong>اقدام‌های اصلاحی و پیگیری اجرا</strong>
                                        <p class="muted">اقدام‌ها به مسئول اجرای تعیین‌شده برای همین قانون سپرده می‌شوند؛ این بخش اختیار تغییر مسئول یا تغییر متن قانون را ایجاد نمی‌کند.</p>
                                        <?php foreach ($corrective_actions as $corrective_action): ?>
                                            <?php
                                            $action_updates=$outcome_corrective_updates_by_action[(int)$corrective_action['id']] ?? [];
                                            $latest_action_update=$action_updates ? $action_updates[count($action_updates)-1] : null;
                                            $action_status=$latest_action_update['status'] ?? 'open';
                                            $action_status_labels=['open'=>'در انتظار آغاز','in_progress'=>'در حال اجرا','blocked'=>'با مانع روبه‌رو','completed'=>'تکمیل‌شده'];
                                            ?>
                                            <div style="border-top:1px solid #e2e8f0;padding-top:8px;margin-top:8px">
                                                <strong><?= community_laws_escape($corrective_action['title']) ?></strong>
                                                <p><?= nl2br(community_laws_escape($corrective_action['action_details'])) ?></p>
                                                <p><strong>معیار موفقیت:</strong> <?= nl2br(community_laws_escape($corrective_action['success_criteria'])) ?></p>
                                                <p class="meta">مهلت: <?= community_laws_escape($corrective_action['target_date']) ?> — وضعیت: <?= $action_status_labels[$action_status] ?? $action_status ?></p>
                                                <?php foreach ($action_updates as $action_update): ?>
                                                    <div class="meta" style="margin-top:6px">
                                                        <?= community_laws_escape($action_update['created_at']) ?> — <?= $action_status_labels[$action_update['status']] ?? $action_update['status'] ?>
                                                        <p><?= nl2br(community_laws_escape($action_update['update_text'])) ?></p>
                                                        <?php if (!empty($action_update['evidence'])): ?><p>شواهد: <?= nl2br(community_laws_escape($action_update['evidence'])) ?></p><?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ((int)$corrective_action['responsible_user_id']===$user_id && $action_status!=='completed'): ?>
                                                    <form method="post" action="community_law_outcome_action.php">
                                                        <input type="hidden" name="action" value="update_corrective_action">
                                                        <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                                        <input type="hidden" name="community_id" value="<?= $community_id ?>">
                                                        <input type="hidden" name="corrective_action_id" value="<?= (int)$corrective_action['id'] ?>">
                                                        <label>وضعیت جدید</label>
                                                        <select name="corrective_status" required>
                                                            <option value="in_progress">در حال اجرا</option>
                                                            <option value="blocked">با مانع روبه‌رو</option>
                                                            <option value="completed">تکمیل‌شده</option>
                                                        </select>
                                                        <label>گزارش پیشرفت یا مانع</label>
                                                        <textarea name="corrective_update_text" maxlength="20000" required></textarea>
                                                        <label>شواهد (اختیاری)</label>
                                                        <textarea name="corrective_evidence" maxlength="20000"></textarea>
                                                        <button type="submit">ثبت گزارش پیشرفت</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($can_create_corrective_action): ?>
                                            <details style="margin-top:8px">
                                                <summary>تعریف اقدام اصلاحی جدید</summary>
                                                <form method="post" action="community_law_outcome_action.php">
                                                    <input type="hidden" name="action" value="create_corrective_action">
                                                    <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                                    <input type="hidden" name="community_id" value="<?= $community_id ?>">
                                                    <input type="hidden" name="report_id" value="<?= (int)$outcome_report['id'] ?>">
                                                    <label>عنوان اقدام</label>
                                                    <input type="text" name="action_title" maxlength="200" required>
                                                    <label>شرح اقدام و گام‌های انجام</label>
                                                    <textarea name="action_details" maxlength="20000" required></textarea>
                                                    <label>معیار قابل سنجش موفقیت</label>
                                                    <textarea name="success_criteria" maxlength="12000" required></textarea>
                                                    <label>مهلت اجرا</label>
                                                    <input type="date" name="target_date" required>
                                                    <button type="submit">ثبت اقدام اصلاحی</button>
                                                </form>
                                            </details>
                                        <?php elseif (!$corrective_actions && (int)($outcome_report['responsible_user_id']??0)<=0): ?>
                                            <p class="muted">برای ثبت اقدام اصلاحی، ابتدا باید در بخش اجرای قانون مسئول اجرا تعیین شود.</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php
                                $can_review_outcome=(bool)$is_member &&
                                    (int)$outcome_report['community_id']===$community_id &&
                                    (int)$outcome_report['submitted_by']!==$user_id &&
                                    (int)($outcome_report['responsible_user_id']??0)!==$user_id &&
                                    (int)$outcome_report['review_count']<3 &&
                                    (int)$outcome_report['my_review_count']===0 &&
                                    in_array($outcome_report['review_status'],['submitted','under_review'],true);
                                ?>
                                <?php if ($can_review_outcome): ?>
                                    <details style="margin-top:8px">
                                        <summary>ثبت ارزیابی مستقل این گزارش</summary>
                                        <p class="muted">ارزیابی باید بر شواهد استوار باشد. گزارش‌دهنده و مسئول اجرای قانون نمی‌توانند گزارش خود یا اجرای خود را ارزیابی کنند. پس از سه ارزیابی مستقل، نتیجهٔ اکثریت ثبت می‌شود.</p>
                                        <form method="post" action="community_law_outcome_action.php">
                                            <input type="hidden" name="action" value="review">
                                            <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                            <input type="hidden" name="community_id" value="<?= $community_id ?>">
                                            <input type="hidden" name="report_id" value="<?= (int)$outcome_report['id'] ?>">
                                            <label>نتیجهٔ ارزیابی</label>
                                            <select name="verdict" required>
                                                <option value="confirm">شواهد، گزارش را تأیید می‌کنند</option>
                                                <option value="challenge">شواهد کافی نیست یا گزارش محل اختلاف است</option>
                                            </select>
                                            <label>دلیل و استدلال ارزیابی (الزامی)</label>
                                            <textarea name="rationale" maxlength="20000" required></textarea>
                                            <label>پیامدهای انسانی (اختیاری)</label>
                                            <textarea name="human_impact" maxlength="12000"></textarea>
                                            <label>پیامدهای اجتماعی (اختیاری)</label>
                                            <textarea name="social_impact" maxlength="12000"></textarea>
                                            <label>پیامدهای زیست‌محیطی (اختیاری)</label>
                                            <textarea name="environmental_impact" maxlength="12000"></textarea>
                                            <label>پیامدهای اقتصادی (اختیاری)</label>
                                            <textarea name="economic_impact" maxlength="12000"></textarea>
                                            <label>شواهد و منابع ارزیابی (اختیاری)</label>
                                            <textarea name="review_evidence" maxlength="12000"></textarea>
                                            <button type="submit">ثبت ارزیابی مستقل</button>
                                        </form>
                                    </details>
                                <?php elseif ((int)$outcome_report['my_review_count']>0): ?>
                                    <p class="meta">ارزیابی شما قبلاً ثبت شده و قابل ویرایش یا حذف نیست.</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($can_submit_outcome_report): ?>
                        <p class="muted">گزارش فقط برای جامعه‌ای ثبت می‌شود که عضو آن هستید. ثبت گزارش به معنی تأیید صحت یا موفقیت آن نیست.</p>
                        <form method="post" action="community_law_outcome_action.php">
                            <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                            <input type="hidden" name="community_id" value="<?= $community_id ?>">
                            <input type="hidden" name="law_id" value="<?= (int)$law['law_id'] ?>">
                            <input type="hidden" name="law_version_id" value="<?= (int)$law['version_id'] ?>">
                            <label>نوع گزارش</label>
                            <select name="report_type" required>
                                <option value="progress">گزارش پیشرفت اجرا</option>
                                <option value="outcome">ارزیابی پیامد و نتیجه</option>
                            </select>
                            <label>شروع دوره (میلادی)</label>
                            <input type="date" name="period_start" required>
                            <label>پایان دوره (میلادی)</label>
                            <input type="date" name="period_end" required>
                            <label>شرح نتیجه، پیشرفت یا پیامدهای مشاهده‌شده</label>
                            <textarea name="summary" maxlength="30000" required></textarea>
                            <label>شواهد و منابع (اختیاری)</label>
                            <textarea name="evidence" maxlength="30000"></textarea>
                            <label>شاخص‌های عددی به قالب JSON (اختیاری)</label>
                            <textarea name="metrics_json" placeholder='{"beneficiaries":120,"incidents":3}'></textarea>
                            <button type="submit">ثبت گزارش مستقل</button>
                        </form>
                    <?php endif; ?>
                </details>
                <?php if (!empty($law['source_decision_id']) || !empty($law['source_topic_id'])): ?>
                    <p class="meta" style="margin-top:9px;padding:8px 10px;background:#f3f7fc;border-radius:7px">
                        📋 منشأ این قانون:
                        <?php if (!empty($law['source_decision_id'])): ?>
                            تصمیم جمعی شمارهٔ <?= (int)$law['source_decision_id'] ?>
                        <?php endif; ?>
                        <?php if (!empty($law['source_topic_id'])): ?>
                            — <a href="archived_topics.php?community_id=<?= (int)$law['originating_community_id'] ?>#topic-card-<?= (int)$law['source_topic_id'] ?>">مشاهدهٔ موضوع منشأ در آرشیو جامعهٔ مبدأ</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($law['source_decision_id'])): ?>
                    <?php $source_knowledge=$law_source_knowledge_by_decision[(int)$law['source_decision_id']] ?? null; ?>
                    <p class="meta" style="margin-top:6px;padding:8px 10px;background:#f8fafc;border-radius:7px">
                        <?php if ($source_knowledge && !empty($source_knowledge['knowledge_entry_id']) && !empty($source_knowledge['knowledge_title'])): ?>
                            دانش مرتبط با تصمیم منشأ:
                            <?= community_laws_escape($source_knowledge['knowledge_title']) ?>
                            — وضعیت دانش: <?= community_laws_escape($source_knowledge['knowledge_status']) ?>
                            — نسخه <?= (int)$source_knowledge['version_no'] ?>
                        <?php else: ?>
                            این تصمیم هنوز به مدخل دانش مشخصی پیوند نخورده است؛ تصمیم و قانون مستقل از پروندهٔ دانش باقی می‌مانند.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($law['implementation_plan'])): ?>
                    <details style="margin-top:10px">
                        <summary>برنامه اجرایی</summary>
                        <div class="law-text"><?= community_laws_escape($law['implementation_plan']) ?></div>
                    </details>
                <?php endif; ?>
                <details style="margin-top:10px">
                    <summary>ثبت اعتراض مستدل به این قانون</summary>
                    <?php if ($is_member): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                        <input type="hidden" name="law_version_id" value="<?= (int)$law['version_id'] ?>">
                        <label>موضوع اعتراض</label>
                        <select name="objection_type" required>
                            <option value="charter_conflict">تعارض با منشور بنیادین</option>
                            <option value="jurisdiction_conflict">خروج از صلاحیت جامعه تصویب‌کننده</option>
                            <option value="rights_impact">تضییع حقوق افراد یا جوامع</option>
                            <option value="implementation_barrier">مانع جدی در اجرا</option>
                            <option value="other">سایر موارد</option>
                        </select>
                        <label>شرح و استدلال</label>
                        <textarea name="description" maxlength="30000" required></textarea>
                        <label>شواهد یا منابع (اختیاری)</label>
                        <textarea name="evidence" maxlength="30000"></textarea>
                        <button type="submit" name="submit_law_objection" value="1">ثبت اعتراض</button>
                    </form>
                    <?php else: ?>
                        <p class="muted">برای ثبت اعتراض باید عضو این جامعه باشید.</p>
                    <?php endif; ?>
                </details>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>پیش‌نویس‌های قوانین این جامعه</h3>
    <?php if (!$draft_laws): ?>
        <p class="muted">پیش‌نویسی ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($draft_laws as $draft): ?>
            <div class="notice">
                <strong><?= community_laws_escape($draft['title']) ?></strong>
                <div class="meta"><?= community_laws_escape($draft['law_code']) ?> — پیش‌نویس؛ هنوز الزام‌آور نیست.</div>
                <?php if ($is_member && (int)$draft['proposed_by'] === $user_id): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                    <input type="hidden" name="law_id" value="<?= (int)$draft['law_id'] ?>">
                    <input type="hidden" name="version_id" value="<?= (int)$draft['version_id'] ?>">
                    <button type="submit" name="submit_law_for_review" value="1">ارسال برای بررسی صلاحیت و دامنه شمول</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>گزارش نتایج رأی‌گیری‌های پایان‌یافته</h3>
    <?php if (!$tallied_laws): ?>
        <p class="muted">هنوز گزارشی از رأی‌گیری پایان‌یافته ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($tallied_laws as $tallied_law): ?>
            <div class="notice">
                <strong><?= community_laws_escape($tallied_law['title']) ?></strong>
                <div class="meta"><?= community_laws_escape($tallied_law['law_code']) ?> — پایان مهلت: <?= community_laws_escape($tallied_law['vote_deadline']) ?></div>
                <div class="notice">
                    <strong>نتیجه مرکب رأی‌گیری</strong>
                    <p>
                        <?= $tallied_law['final_result']['status'] === 'vote_passed' ? 'رأی‌گیری به تصویب پیشنهاد انجامید؛ هنوز به معنای لازم‌الاجرا شدن قانون نیست.' : ($tallied_law['final_result']['status'] === 'vote_rejected' ? 'پیشنهاد در رأی‌گیری تصویب نشد.' : ($tallied_law['final_result']['status'] === 'insufficient' ? 'حدنصاب مشارکت در دست‌کم یکی از مسیرهای الزامی حاصل نشد.' : 'نتیجه مرکب هنوز محاسبه نشده است.')) ?>
                    </p>
                    <div>مشارکت مستقیم: <?= number_format($tallied_law['final_result']['individual_turnout'], 2) ?>٪ —
                    موافقت مستقیم: <?= number_format($tallied_law['final_result']['individual_approval'], 2) ?>٪</div>
                    <?php if ($tallied_law['final_result']['require_community'] === 1): ?>
                        <div>مشارکت جوامع: <?= number_format($tallied_law['final_result']['community_turnout'], 2) ?>٪ —
                        موضع موافق جوامع: <?= number_format($tallied_law['final_result']['community_approval'], 2) ?>٪</div>
                    <?php endif; ?>
                </div>
                <div class="notice">
                    <strong>نتیجه رأی مستقیم افراد (مستقل از عضویت چندگانه)</strong>
                    <div>واجدان: <?= (int)$tallied_law['direct_result']['electorate_count'] ?> —
                    موافق: <?= (int)$tallied_law['direct_result']['yes_count'] ?> —
                    مخالف: <?= (int)$tallied_law['direct_result']['no_count'] ?> —
                    ممتنع: <?= (int)$tallied_law['direct_result']['abstain_count'] ?></div>
                </div>
                <table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:13px">
                    <thead><tr>
                        <th>جامعه</th><th>واجدان</th><th>موافق</th><th>مخالف</th><th>ممتنع</th><th>وضعیت</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($tallied_law['communities'] as $result_row): ?>
                        <tr>
                            <td><?= community_laws_escape($result_row['community_name']) ?></td>
                            <td><?= (int)$result_row['electorate_count'] ?></td>
                            <td><?= (int)$result_row['yes_count'] ?></td>
                            <td><?= (int)$result_row['no_count'] ?></td>
                            <td><?= (int)$result_row['abstain_count'] ?></td>
                            <td><?= $result_row['position_result'] === 'approve' ? 'موضع موافق جامعه' : ($result_row['position_result'] === 'reject' ? 'موضع مخالف جامعه' : ($result_row['position_result'] === 'insufficient' ? 'حدنصاب مشارکت حاصل نشد' : ((int)$result_row['yes_count'] + (int)$result_row['no_count'] + (int)$result_row['abstain_count'] === 0 ? 'بدون رأی' : 'گزارش مشورتی؛ تصویب جامعه الزامی نیست'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="muted">نتیجه رأی‌گیری با ترکیب مسیرهای الزامی و حدنصاب‌های تأییدشده محاسبه شده است. حتی در صورت عبور از رأی‌گیری، قانون تا بررسی صلاحیت و تعارض احتمالی با منشور بنیادین، رسیدگی به اعتراض‌های معتبر و ابلاغ رسمی لازم‌الاجرا نمی‌شود.</p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>


<div class="card">
    <h3>پیشنهادهای دارای رأی مثبت؛ در انتظار بررسی نهایی</h3>
    <?php if (!$passed_laws_pending_final_review): ?>
        <p class="muted">پیشنهادی با رأی مثبت که هنوز پرونده بررسی نهایی آن آغاز نشده باشد وجود ندارد.</p>
    <?php else: ?>
        <?php foreach ($passed_laws_pending_final_review as $passed_law): ?>
            <div class="notice">
                <strong><?= community_laws_escape($passed_law['title']) ?></strong>
                <div class="meta"><?= community_laws_escape($passed_law['law_code']) ?> — رأی مثبت ثبت شده؛ هنوز قانون نیست و الزام‌آور نیست.</div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                    <input type="hidden" name="law_id" value="<?= (int)$passed_law['law_id'] ?>">
                    <input type="hidden" name="version_id" value="<?= (int)$passed_law['version_id'] ?>">
                    <button type="submit" name="open_law_final_review" value="1">آغاز بررسی نهایی مستقل</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>بررسی نهایی، اعتراض‌ها و ابلاغ قوانین</h3>
    <?php if (!$final_review_laws): ?>
        <p class="muted">پرونده‌ای در این مرحله وجود ندارد.</p>
    <?php else: ?>
        <?php foreach ($final_review_laws as $final_law): ?>
            <?php
            $final_status_labels = [
                'pending' => 'در انتظار ارزیابی نهایی مستقل',
                'review_clear' => 'بررسی نهایی بدون مانع تکمیل شده',
                'conflict_found' => 'تعارض یا آسیب جدی شناسایی شده؛ ابلاغ متوقف است',
                'needs_revision' => 'ابهام یا نیاز به بازنگری وجود دارد',
                'appeal_period' => 'در مهلت ثبت اعتراض',
                'appeal_hold' => 'در انتظار رسیدگی به اعتراض',
                'ready_for_enactment' => 'آماده ابلاغ پس از کنترل نهایی',
                'enacted' => 'ابلاغ و لازم‌الاجرا شده'
            ];
            ?>
            <div class="notice">
                <strong><?= community_laws_escape($final_law['title']) ?></strong>
                <div class="meta"><?= community_laws_escape($final_law['law_code']) ?> — <?= community_laws_escape($final_status_labels[$final_law['final_status']] ?? $final_law['final_status']) ?></div>
                <?php if (
                    $is_member &&
                    (int)$final_law['originating_community_id'] === $community_id &&
                    in_array($final_law['final_status'], ['conflict_found','needs_revision'], true) &&
                    (int)$final_law['unresolved_appeals'] === 0
                ): ?>
                    <details style="margin-top:10px">
                        <summary>تهیه نسخه اصلاح‌شده و ارائه دوباره</summary>
                        <p class="muted">نسخه قبلی، ارزیابی‌ها و اعتراض‌های آن حفظ می‌شوند. نسخه تازه فقط پیش‌نویس است و باید غربالگری، بررسی صلاحیت، قواعد رأی‌گیری، رأی‌گیری و بررسی نهایی را دوباره طی کند.</p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                            <input type="hidden" name="law_version_id" value="<?= (int)$final_law['version_id'] ?>">
                            <label>عنوان نسخه اصلاح‌شده</label>
                            <input type="text" name="revision_title" maxlength="200" required value="<?= community_laws_escape($final_law['title']) ?>">
                            <label>متن کامل قانون اصلاح‌شده</label>
                            <textarea name="revision_law_text" maxlength="50000" required><?= community_laws_escape($final_law['law_text']) ?></textarea>
                            <label>برنامه اجرا</label>
                            <textarea name="revision_implementation_plan" maxlength="30000"><?= community_laws_escape($final_law['implementation_plan'] ?? '') ?></textarea>
                            <label>خلاصه تغییرات و نحوه پاسخ به ایرادها (الزامی)</label>
                            <textarea name="revision_change_summary" maxlength="10000" required></textarea>
                            <button type="submit" name="create_law_revision" value="1">ایجاد پیش‌نویس نسخه اصلاح‌شده</button>
                        </form>
                    </details>
                <?php endif; ?>
                <?php if (!empty($final_assessments_by_version[(int)$final_law['version_id']])): ?>
                    <details style="margin-top:10px">
                        <summary>مشاهده ارزیابی‌ها و استدلال‌های نهایی</summary>
                        <?php foreach ($final_assessments_by_version[(int)$final_law['version_id']] as $assessment): ?>
                            <div class="notice">
                                <div class="meta">ارزیاب مستقل #<?= (int)$assessment['reviewer_user_id'] ?> — <?= community_laws_escape($assessment['verdict']) ?> — <?= community_laws_escape($assessment['submitted_at']) ?></div>
                                <div class="meta">
                                    اصول و حقوق: <?= community_laws_escape($assessment['charter_and_rights']) ?>؛
                                    برابری: <?= community_laws_escape($assessment['non_discrimination']) ?>؛
                                    تمرکز قدرت: <?= community_laws_escape($assessment['power_concentration']) ?>؛
                                    پیامد اجتماعی: <?= community_laws_escape($assessment['human_and_social_impact']) ?>؛
                                    محیط‌زیست: <?= community_laws_escape($assessment['environmental_impact']) ?>؛
                                    منابع: <?= community_laws_escape($assessment['economic_and_resource_impact']) ?>؛
                                    نسل آینده و جانداران: <?= community_laws_escape($assessment['future_generations_and_other_life']) ?>؛
                                    اجرا و پاسخ‌گویی: <?= community_laws_escape($assessment['implementation_and_accountability']) ?>
                                </div>
                                <p><?= nl2br(community_laws_escape($assessment['reasoning'])) ?></p>
                                <?php if (!empty($assessment['evidence'])): ?><p class="muted">شواهد: <?= nl2br(community_laws_escape($assessment['evidence'])) ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>

                <?php if ($final_law['final_status'] === 'pending'): ?>
                    <p>ارزیابی‌های ثبت‌شده: <?= (int)$final_law['assessment_count'] ?> از <?= (int)$final_law['reviewer_quorum'] ?> ارزیاب مستقل.</p>
                    <p class="muted">ارزیابان این مرحله با ارزیابان غربالگری و صلاحیت قبلی متفاوت‌اند. همه ابعاد حقوقی، اجتماعی، زیست‌محیطی و بلندمدت باید بررسی شوند. تا زمان ایجاد و تصویب رسمی منشور بنیادین مستقل، این ارزیابی جایگزین آن منشور نیست.</p>
                    <?php if (
                        $is_member && (int)$final_law['is_final_elector'] === 1 &&
                        (int)$final_law['has_final_assessed'] === 0
                    ): ?>
                    <details>
                        <summary>ثبت ارزیابی نهایی مستقل</summary>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                            <input type="hidden" name="law_version_id" value="<?= (int)$final_law['version_id'] ?>">
                            <label>جمع‌بندی ارزیابی</label>
                            <select name="final_verdict" required>
                                <option value="">انتخاب کنید</option>
                                <option value="confirm">قابل تأیید است</option>
                                <option value="dispute">تعارض یا آسیب جدی دارد</option>
                                <option value="unclear">اطلاعات کافی نیست</option>
                            </select>
                            <?php
                            $final_dimensions = [
                                'charter_and_rights' => 'سازگاری با اصول بنیادین و حقوق',
                                'non_discrimination' => 'برابری و نبود تبعیض',
                                'power_concentration' => 'پیشگیری از تمرکز و سوءاستفاده از قدرت',
                                'human_and_social_impact' => 'پیامدهای انسانی و اجتماعی',
                                'environmental_impact' => 'پیامدهای زیست‌محیطی',
                                'economic_and_resource_impact' => 'عدالت اقتصادی و استفاده پایدار از منابع',
                                'future_generations_and_other_life' => 'حقوق نسل‌های آینده و سایر جانداران',
                                'implementation_and_accountability' => 'امکان اجرا، شفافیت و پاسخ‌گویی'
                            ];
                            foreach ($final_dimensions as $field => $label):
                            ?>
                                <label><?= community_laws_escape($label) ?></label>
                                <select name="<?= community_laws_escape($field) ?>" required>
                                    <option value="">انتخاب ارزیابی</option>
                                    <option value="pass">قابل قبول</option>
                                    <option value="conflict">تعارض یا آسیب جدی</option>
                                    <option value="unclear">نامشخص؛ شواهد کافی نیست</option>
                                </select>
                            <?php endforeach; ?>
                            <label>استدلال و دلایل (الزامی)</label>
                            <textarea name="final_reasoning" maxlength="30000" required></textarea>
                            <label>شواهد و منابع (اختیاری)</label>
                            <textarea name="final_evidence" maxlength="30000"></textarea>
                            <button type="submit" name="submit_law_final_assessment" value="1">ثبت ارزیابی نهایی</button>
                        </form>
                    </details>
                    <?php endif; ?>
                <?php elseif ($final_law['final_status'] === 'appeal_period'): ?>
                    <p>مهلت اعتراض تا: <?= community_laws_escape($final_law['appeal_deadline']) ?></p>
                    <p class="muted">قانون هنوز لازم‌الاجرا نیست. ثبت اعتراض در مهلت مقرر، ابلاغ را تا پایان رسیدگی متوقف می‌کند.</p>
                    <?php if ($is_member && !empty($final_law['appeal_deadline']) && strtotime($final_law['appeal_deadline']) >= time()): ?>
                    <details>
                        <summary>ثبت اعتراض مستدل</summary>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                            <input type="hidden" name="law_version_id" value="<?= (int)$final_law['version_id'] ?>">
                            <label>موضوع اعتراض</label>
                            <select name="appeal_type" required>
                                <option value="charter_conflict">تعارض با اصول بنیادین</option>
                                <option value="jurisdiction_conflict">خروج از حدود صلاحیت</option>
                                <option value="rights_violation">تضییع حقوق</option>
                                <option value="discrimination">تبعیض</option>
                                <option value="power_concentration">تمرکز قدرت</option>
                                <option value="environmental_harm">آسیب زیست‌محیطی</option>
                                <option value="future_generations">آسیب به نسل‌های آینده یا سایر جانداران</option>
                                <option value="implementation_risk">خطر یا مانع اجرایی</option>
                                <option value="new_evidence">شواهد تازه</option>
                                <option value="other">سایر موارد</option>
                            </select>
                            <label>شرح و استدلال (الزامی)</label>
                            <textarea name="appeal_description" maxlength="30000" required></textarea>
                            <label>شواهد و منابع (اختیاری)</label>
                            <textarea name="appeal_evidence" maxlength="30000"></textarea>
                            <button type="submit" name="submit_law_appeal" value="1">ثبت اعتراض و درخواست رسیدگی</button>
                        </form>
                    </details>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($appeals_by_version[(int)$final_law['version_id']])): ?>
                    <details>
                        <summary>مشاهده اعتراض‌ها و رسیدگی‌ها (<?= count($appeals_by_version[(int)$final_law['version_id']]) ?>)</summary>
                        <?php foreach ($appeals_by_version[(int)$final_law['version_id']] as $appeal): ?>
                            <div class="notice">
                                <div class="meta">اعتراض #<?= (int)$appeal['appeal_id'] ?> — وضعیت: <?= community_laws_escape($appeal['appeal_status']) ?> — ارزیابی‌ها: <?= (int)$appeal['assessment_count'] ?> از <?= (int)$appeal['reviewer_quorum'] ?></div>
                                <p><?= nl2br(community_laws_escape($appeal['description'])) ?></p>
                                <?php if (!empty($appeal['evidence'])): ?><p class="muted">شواهد: <?= nl2br(community_laws_escape($appeal['evidence'])) ?></p><?php endif; ?>
                                <?php if (!empty($appeal_assessments_by_id[(int)$appeal['appeal_id']])): ?>
                                    <details style="margin-top:8px">
                                        <summary>مشاهده استدلال‌های رسیدگی به اعتراض</summary>
                                        <?php foreach ($appeal_assessments_by_id[(int)$appeal['appeal_id']] as $appeal_assessment): ?>
                                            <div class="notice">
                                                <div class="meta">ارزیاب #<?= (int)$appeal_assessment['reviewer_user_id'] ?> — <?= community_laws_escape($appeal_assessment['verdict']) ?> — <?= community_laws_escape($appeal_assessment['submitted_at']) ?></div>
                                                <p><?= nl2br(community_laws_escape($appeal_assessment['reasoning'])) ?></p>
                                                <?php if (!empty($appeal_assessment['evidence'])): ?><p class="muted">شواهد: <?= nl2br(community_laws_escape($appeal_assessment['evidence'])) ?></p><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </details>
                                <?php endif; ?>
                                <?php if (
                                    $is_member &&
                                    in_array($appeal['appeal_status'], ['submitted','under_review'], true) &&
                                    (int)$appeal['is_appeal_elector'] === 1 &&
                                    (int)$appeal['has_appeal_assessed'] === 0
                                ): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                    <input type="hidden" name="appeal_id" value="<?= (int)$appeal['appeal_id'] ?>">
                                    <label>نتیجه بررسی اعتراض</label>
                                    <select name="appeal_verdict" required>
                                        <option value="">انتخاب کنید</option>
                                        <option value="uphold">اعتراض وارد است</option>
                                        <option value="dismiss">اعتراض وارد نیست</option>
                                        <option value="unclear">برای تصمیم قطعی شواهد کافی نیست</option>
                                    </select>
                                    <label>استدلال و دلیل تصمیم (الزامی)</label>
                                    <textarea name="appeal_reasoning" maxlength="30000" required></textarea>
                                    <label>شواهد تکمیلی (اختیاری)</label>
                                    <textarea name="appeal_review_evidence" maxlength="30000"></textarea>
                                    <button type="submit" name="submit_law_appeal_assessment" value="1">ثبت ارزیابی اعتراض</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>

                <?php if (
                    $is_member && (int)$final_law['originating_community_id'] === $community_id &&
                    $final_law['final_status'] === 'appeal_period' &&
                    !empty($final_law['appeal_deadline']) && strtotime($final_law['appeal_deadline']) <= time() &&
                    (int)$final_law['unresolved_appeals'] === 0 && (int)$final_law['upheld_appeals'] === 0
                ): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                        <input type="hidden" name="law_version_id" value="<?= (int)$final_law['version_id'] ?>">
                        <button type="submit" name="enact_law" value="1">کنترل نهایی و ابلاغ قانون</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>رأی‌گیری‌های پایان‌یافته و در انتظار شمارش</h3>
    <?php if (!$closed_voting_laws): ?>
        <p class="muted">رأی‌گیری پایان‌یافته‌ای برای شمارش وجود ندارد.</p>
    <?php else: ?>
        <?php foreach ($closed_voting_laws as $closed_law): ?>
            <div class="notice">
                <strong><?= community_laws_escape($closed_law['title']) ?></strong>
                <div class="meta"><?= community_laws_escape($closed_law['law_code']) ?> — پایان مهلت: <?= community_laws_escape($closed_law['vote_deadline']) ?></div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                    <input type="hidden" name="law_id" value="<?= (int)$closed_law['law_id'] ?>">
                    <input type="hidden" name="version_id" value="<?= (int)$closed_law['version_id'] ?>">
                    <button type="submit" name="finalize_law_tally" value="1">ثبت شمارش آرا به تفکیک جوامع</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>رأی‌گیری‌های فعال این جامعه</h3>
    <?php if (!$voting_laws): ?>
        <p class="muted">رأی‌گیری فعالی که شما در آن واجد شرایط باشید وجود ندارد.</p>
    <?php else: ?>
        <?php foreach ($voting_laws as $active_law): ?>
            <div class="notice">
                <strong><?= community_laws_escape($active_law['title']) ?></strong>
                <div class="meta"><?= community_laws_escape($active_law['law_code']) ?> — پایان رأی‌گیری: <?= community_laws_escape($active_law['vote_deadline']) ?></div>
                <div class="law-text"><?= community_laws_escape($active_law['law_text']) ?></div>
                <div class="notice">
                    <strong>۱. رأی مستقیم شما</strong>
                    <p class="muted">این رأی فقط یک‌بار برای هر فرد و هر قانون ثبت می‌شود؛ عضویت شما در چند جامعه باعث چندبار شمرده‌شدن آن نخواهد شد.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                        <input type="hidden" name="version_id" value="<?= (int)$active_law['version_id'] ?>">
                        <label>نظر مستقیم شما</label>
                        <select name="direct_vote" required>
                            <option value="yes" <?= ($active_law['direct_current_vote'] ?? '') === 'yes' ? 'selected' : '' ?>>موافقم</option>
                            <option value="no" <?= ($active_law['direct_current_vote'] ?? '') === 'no' ? 'selected' : '' ?>>مخالفم</option>
                            <option value="abstain" <?= ($active_law['direct_current_vote'] ?? '') === 'abstain' ? 'selected' : '' ?>>ممتنع</option>
                        </select>
                        <label>توضیح (اختیاری)</label>
                        <textarea name="direct_comment" maxlength="5000"></textarea>
                        <button type="submit" name="cast_law_direct_vote" value="1">ثبت یا تغییر رأی مستقیم</button>
                    </form>
                </div>
                <div class="notice">
                    <strong>گفت‌وگوی اعضای جامعه درباره این قانون</strong>
                    <p class="muted">پیش از شکل‌گیری موضع رسمی، اعضا می‌توانند استدلال موافق، مخالف یا بی‌طرفانه و شواهد خود را ثبت کنند. این دیدگاه‌ها جای رأی را نمی‌گیرند.</p>
                    <?php if (!empty($position_discussions[(int)$active_law['version_id']])): ?>
                        <?php foreach ($position_discussions[(int)$active_law['version_id']] as $discussion_row): ?>
                            <div class="notice">
                                <div class="meta">
                                    عضو شماره <?= (int)$discussion_row['author_user_id'] ?> —
                                    <?= $discussion_row['stance'] === 'support' ? 'موافق' : ($discussion_row['stance'] === 'oppose' ? 'مخالف' : 'بی‌طرف') ?> —
                                    <?= community_laws_escape($discussion_row['created_at']) ?>
                                </div>
                                <p><?= nl2br(community_laws_escape($discussion_row['argument'])) ?></p>
                                <?php if (!empty($discussion_row['evidence'])): ?>
                                    <details><summary>مشاهده شواهد</summary><p><?= nl2br(community_laws_escape($discussion_row['evidence'])) ?></p></details>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="muted">هنوز دیدگاهی ثبت نشده است.</p>
                    <?php endif; ?>
                    <?php if ($is_member): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                        <input type="hidden" name="version_id" value="<?= (int)$active_law['version_id'] ?>">
                        <label>جهت‌گیری دیدگاه</label>
                        <select name="stance" required>
                            <option value="support">استدلال موافق</option>
                            <option value="oppose">استدلال مخالف</option>
                            <option value="neutral">بررسی بی‌طرفانه / پیشنهاد اصلاح</option>
                        </select>
                        <label>استدلال</label>
                        <textarea name="argument" maxlength="20000" required></textarea>
                        <label>شواهد و منابع (اختیاری)</label>
                        <textarea name="evidence" maxlength="20000"></textarea>
                        <button type="submit" name="submit_position_discussion" value="1">ثبت دیدگاه</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="notice">
                    <strong>۲. مشارکت در تعیین موضع این جامعه</strong>
                    <p class="muted">این رأی در شکل‌گیری موضع رسمی همین جامعه نقش دارد و از رأی مستقیم فردی جدا محاسبه می‌شود.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                        <input type="hidden" name="version_id" value="<?= (int)$active_law['version_id'] ?>">
                        <label>رأی شما در این جامعه</label>
                        <select name="vote" required>
                            <option value="yes" <?= ($active_law['community_current_vote'] ?? '') === 'yes' ? 'selected' : '' ?>>موافقم</option>
                            <option value="no" <?= ($active_law['community_current_vote'] ?? '') === 'no' ? 'selected' : '' ?>>مخالفم</option>
                            <option value="abstain" <?= ($active_law['community_current_vote'] ?? '') === 'abstain' ? 'selected' : '' ?>>ممتنع</option>
                        </select>
                        <label>توضیح (اختیاری)</label>
                        <textarea name="comment" maxlength="5000"></textarea>
                        <button type="submit" name="cast_law_vote" value="1">ثبت یا تغییر رأی در این جامعه</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>پیشنهادهای در انتظار بررسی</h3>
    <?php if (!$proposed_laws): ?>
        <p class="muted">پیشنهادی برای بررسی ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($proposed_laws as $proposal): ?>
            <div class="notice">
                <strong><?= community_laws_escape($proposal['title']) ?></strong>
                <div class="meta"><?= community_laws_escape($proposal['law_code']) ?> — پیشنهاد در انتظار غربالگری؛ هنوز رأی‌گیری آغاز نشده است.</div>
                <div class="notice">
                    <strong>غربالگری اولیه حقوقی</strong>
                    <div>وضعیت:
                        <?= ($proposal['review_status'] ?? '') === 'screening_clear' ? 'غربالگری اولیه تکمیل شد؛ این به‌معنای تأیید نهایی حقوقی نیست.' :
                           (($proposal['review_status'] ?? '') === 'conflict_found' ? 'تعارض احتمالی شناسایی شد؛ رأی‌گیری این نسخه متوقف است.' :
                           (($proposal['review_status'] ?? '') === 'needs_revision' ? 'ابهام یا کمبود اطلاعات وجود دارد؛ این نسخه نیازمند بازنگری است.' : 'در انتظار ارزیابی مستقل اعضا.')) ?>
                    </div>
                    <div class="meta">ارزیابی‌های ثبت‌شده: <?= (int)$proposal['reviewer_count'] ?> از <?= (int)($proposal['reviewer_quorum'] ?? 3) ?> ارزیاب مستقل؛ اعضای واجد شرایط: <?= (int)$proposal['reviewer_electorate_count'] ?></div>
                    <?php if (
                        $is_member &&
                        (int)$proposal['proposed_by'] !== $user_id &&
                        (int)$proposal['is_review_elector'] === 1 &&
                        (int)$proposal['has_assessed'] === 0 &&
                        ($proposal['review_status'] ?? '') === 'pending'
                    ): ?>
                    <details style="margin-top:10px">
                        <summary>ثبت ارزیابی مستقل این پیشنهاد</summary>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                            <input type="hidden" name="law_version_id" value="<?= (int)$proposal['version_id'] ?>">
                            <?php
                            $review_dimensions = [
                                'charter_compatibility' => 'سازگاری با منشور بنیادین',
                                'hierarchy_compatibility' => 'سازگاری با قوانین بالادستی',
                                'rights_impact' => 'رعایت حقوق انسان‌ها و جوامع',
                                'environmental_impact' => 'پیامدهای زیست‌محیطی و حقوق نسل‌های آینده و سایر جانداران',
                                'implementation_feasibility' => 'امکان‌پذیری و پیامدهای اجرایی'
                            ];
                            foreach ($review_dimensions as $field_name => $field_label):
                            ?>
                                <label><?= community_laws_escape($field_label) ?></label>
                                <select name="<?= community_laws_escape($field_name) ?>" required>
                                    <option value="">انتخاب ارزیابی</option>
                                    <option value="compliant">تعارض یا مانع آشکاری مشاهده نکردم</option>
                                    <option value="conflict">تعارض یا آسیب جدی مشاهده کردم</option>
                                    <option value="unclear">اطلاعات کافی برای ارزیابی ندارم</option>
                                </select>
                            <?php endforeach; ?>
                            <label>استدلال و توضیح (الزامی)</label>
                            <textarea name="reasoning" maxlength="30000" required></textarea>
                            <label>شواهد و منابع (اختیاری)</label>
                            <textarea name="evidence" maxlength="30000"></textarea>
                            <button type="submit" name="submit_law_legal_assessment" value="1">ثبت ارزیابی مستقل</button>
                        </form>
                    </details>
                    <?php elseif (($proposal['review_status'] ?? '') === 'screening_clear'): ?>
                        <p class="muted">غربالگری اولیه تکمیل شده است. اکنون صلاحیت جامعه و سازگاری با قوانین معتبر بررسی می‌شود.</p>
                        <div class="notice">
                            <strong>بررسی صلاحیت و قوانین بالادستی</strong>
                            <?php
                            $authority_labels = [
                                'local' => 'محلی؛ فقط در محدوده همین جامعه',
                                'parent_shared' => 'مشترک با زیرشاخه‌های این جامعه',
                                'intercommunity' => 'بین‌جوامعی؛ شامل جوامع مستقل دیگر'
                            ];
                            $authority_status_labels = [
                                'pending' => 'در انتظار بررسی مستقل',
                                'confirmed' => 'صلاحیت و انطباق در بررسی مستقل تأیید شده',
                                'conflict_found' => 'تعارض با قانون یا صلاحیت شناسایی شده؛ رأی‌گیری متوقف است',
                                'needs_revision' => 'ابهام یا اطلاعات ناکافی؛ نیازمند بازنگری'
                            ];
                            ?>
                            <p>سطح صلاحیت تشخیص‌داده‌شده: <strong><?= community_laws_escape($authority_labels[$proposal['derived_jurisdiction_level'] ?? ''] ?? 'نامشخص') ?></strong></p>
                            <p>وضعیت: <?= community_laws_escape($authority_status_labels[$proposal['authority_status'] ?? ''] ?? 'پرونده بررسی صلاحیت موجود نیست') ?></p>
                            <div class="meta">ارزیابی‌های مستقل ثبت‌شده: <?= (int)$proposal['authority_reviewer_count'] ?> از <?= (int)($proposal['authority_reviewer_quorum'] ?? 3) ?>؛ اعضای واجد شرایط از مرحله قبل snapshot شده‌اند.</div>
                            <p class="muted">سطح «منشور جهانی» در این مرحله به هیچ جامعه‌ای خودکار داده نمی‌شود؛ ایجاد و تصویب منشور بنیادین، فرایند مستقل و جداگانه می‌خواهد. این تأیید نیز فقط جمع‌بندی بررسی اعضاست، نه حکم حقوقی نهایی.</p>

                            <?php $proposal_references = $authority_references_by_version[(int)$proposal['version_id']] ?? []; ?>
                            <?php if (!$authority_reference_scan_ok): ?>
                                <p class="notice warning">فهرست قوانین مرتبط قابل بازیابی نیست؛ تا رفع مشکل، ثبت ارزیابی و عبور از این مرحله متوقف می‌ماند.</p>
                            <?php elseif (!$proposal_references): ?>
                                <p class="muted">در زمان ثبت پیشنهاد، قانون معتبر دیگری که بر دامنه آن اثر داشته باشد پیدا نشد. این موضوع به‌معنای تأیید خودکار منشور یا صلاحیت نیست.</p>
                            <?php else: ?>
                                <p class="muted">قوانین معتبر مرتبط که در زمان ارسال پیشنهاد شناسایی شده‌اند:</p>
                                <?php foreach ($proposal_references as $reference): ?>
                                    <?php
                                    $relation_labels = [
                                        'higher' => 'بالادستی',
                                        'same_level' => 'هم‌سطح',
                                        'lower_level' => 'پایین‌دستی',
                                        'peer' => 'جامعه مستقل / هم‌سطح بین‌جوامعی'
                                    ];
                                    ?>
                                    <div class="notice">
                                        <strong><?= community_laws_escape($reference['reference_title']) ?></strong>
                                        <div class="meta"><?= community_laws_escape($reference['law_code']) ?> — <?= community_laws_escape($reference['reference_community_name']) ?> — <?= community_laws_escape($relation_labels[$reference['relation_type']] ?? 'مرتبط') ?></div>
                                        <details>
                                            <summary>مشاهده متن قانون مرجع</summary>
                                            <p><?= nl2br(community_laws_escape($reference['reference_law_text'])) ?></p>
                                        </details>
                                        <?php if (!empty($precedent_assessments_by_reference[(int)$reference['id']])): ?>
                                            <?php foreach ($precedent_assessments_by_reference[(int)$reference['id']] as $precedent_assessment): ?>
                                                <p class="muted">ارزیاب #<?= (int)$precedent_assessment['reviewer_user_id'] ?>:
                                                    <?= community_laws_escape($precedent_assessment['compatibility_verdict']) ?> —
                                                    <?= nl2br(community_laws_escape($precedent_assessment['reasoning'])) ?></p>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (
                                $is_member &&
                                (int)$proposal['proposed_by'] !== $user_id &&
                                (int)$proposal['is_review_elector'] === 1 &&
                                (int)$proposal['has_authority_assessed'] === 0 &&
                                ($proposal['authority_status'] ?? '') === 'pending' &&
                                $authority_reference_scan_ok
                            ): ?>
                            <details style="margin-top:10px">
                                <summary>ثبت ارزیابی مستقل صلاحیت و قوانین مرتبط</summary>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                    <input type="hidden" name="law_version_id" value="<?= (int)$proposal['version_id'] ?>">
                                    <label>آیا سطح صلاحیت تشخیص‌داده‌شده و دامنه شمول درست است؟</label>
                                    <select name="authority_verdict" required>
                                        <option value="">انتخاب ارزیابی</option>
                                        <option value="confirm">بله، با ساختار و دامنه اختیار سازگار است</option>
                                        <option value="dispute">خیر، از حدود اختیار فراتر می‌رود</option>
                                        <option value="unclear">برای تشخیص صلاحیت اطلاعات کافی نیست</option>
                                    </select>
                                    <label>استدلال درباره صلاحیت و دامنه اختیار (الزامی)</label>
                                    <textarea name="authority_reasoning" maxlength="30000" required></textarea>
                                    <label>شواهد و مستندات (اختیاری)</label>
                                    <textarea name="authority_evidence" maxlength="30000"></textarea>
                                    <?php foreach ($proposal_references as $reference): ?>
                                        <div class="notice">
                                            <strong>سازگاری با قانون: <?= community_laws_escape($reference['reference_title']) ?></strong>
                                            <div class="meta"><?= community_laws_escape($reference['law_code']) ?> — <?= community_laws_escape($relation_labels[$reference['relation_type']] ?? 'مرتبط') ?></div>
                                            <label>نتیجه بررسی</label>
                                            <select name="precedent_assessment[<?= (int)$reference['id'] ?>]" required>
                                                <option value="">انتخاب ارزیابی</option>
                                                <option value="compatible">با این قانون تعارضی ندارد</option>
                                                <option value="conflict">با این قانون تعارض دارد</option>
                                                <option value="unclear">برای تشخیص تعارض اطلاعات کافی نیست</option>
                                            </select>
                                            <label>دلیل و مستندات بررسی این قانون (الزامی)</label>
                                            <textarea name="precedent_reasoning[<?= (int)$reference['id'] ?>]" maxlength="30000" required></textarea>
                                            <label>شواهد تکمیلی (اختیاری)</label>
                                            <textarea name="precedent_evidence[<?= (int)$reference['id'] ?>]" maxlength="30000"></textarea>
                                        </div>
                                    <?php endforeach; ?>
                                    <button type="submit" name="submit_law_authority_assessment" value="1">ثبت ارزیابی صلاحیت و تعارض قوانین</button>
                                </form>
                            </details>
                            <?php endif; ?>

                            <?php if (!empty($authority_assessments_by_version[(int)$proposal['version_id']])): ?>
                                <details style="margin-top:10px">
                                    <summary>مشاهده استدلال‌های ارزیابی صلاحیت</summary>
                                    <?php foreach ($authority_assessments_by_version[(int)$proposal['version_id']] as $authority_assessment): ?>
                                        <div class="notice">
                                            <div class="meta">ارزیاب #<?= (int)$authority_assessment['reviewer_user_id'] ?> — <?= community_laws_escape($authority_assessment['authority_verdict']) ?> — <?= community_laws_escape($authority_assessment['submitted_at']) ?></div>
                                            <p><?= nl2br(community_laws_escape($authority_assessment['reasoning'])) ?></p>
                                            <?php if (!empty($authority_assessment['evidence'])): ?>
                                                <p class="muted">شواهد: <?= nl2br(community_laws_escape($authority_assessment['evidence'])) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endif; ?>

                            <?php if (
                                $is_member &&
                                (int)$proposal['originating_community_id'] === $community_id &&
                                ($proposal['authority_status'] ?? '') === 'confirmed'
                            ): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                    <input type="hidden" name="law_id" value="<?= (int)$proposal['law_id'] ?>">
                                    <input type="hidden" name="version_id" value="<?= (int)$proposal['version_id'] ?>">
                                    <button type="submit" name="start_law_vote" value="1">ادامه به رأی‌گیری (پس از تأیید قواعد رأی‌گیری)</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php
                    $rule_proposals = $voting_rule_proposals_by_version[(int)$proposal['version_id']] ?? [];
                    $latest_rule_proposal = $rule_proposals[0] ?? null;
                    $approved_rule_stmt = $conn->prepare(
                        "SELECT status FROM community_law_voting_rules WHERE law_version_id=? LIMIT 1"
                    );
                    $approved_rule_status = null;
                    if ($approved_rule_stmt) {
                        $rule_version_id = (int)$proposal['version_id'];
                        $approved_rule_stmt->bind_param('i', $rule_version_id);
                        if ($approved_rule_stmt->execute()) {
                            $approved_rule_status = $approved_rule_stmt->get_result()->fetch_assoc()['status'] ?? null;
                        }
                        $approved_rule_stmt->close();
                    }
                    ?>
                    <div class="notice">
                        <strong>تعیین و تصویب قواعد رأی‌گیری</strong>
                        <?php if ($approved_rule_status === 'approved'): ?>
                            <p>قواعد این نسخه با فرایند مستقل تأیید شده‌اند. آغاز رأی‌گیری همچنان پس از کنترل سایر شرایط انجام می‌شود.</p>
                        <?php else: ?>
                            <?php if ($latest_rule_proposal): ?>
                                <?php
                                $rule_status_labels = [
                                    'pending_review' => 'در انتظار بررسی مستقل',
                                    'approved' => 'تأییدشده',
                                    'rejected' => 'به حدنصاب تصویب نرسید'
                                ];
                                ?>
                                <div class="meta">پیشنهاد شماره <?= (int)$latest_rule_proposal['proposal_number'] ?> — <?= community_laws_escape($rule_status_labels[$latest_rule_proposal['status']] ?? $latest_rule_proposal['status']) ?></div>
                                <div class="meta">پنل مستقل: <?= (int)$latest_rule_proposal['electorate_count'] ?> نفر — ارزیابی ثبت‌شده: <?= (int)$latest_rule_proposal['vote_count'] ?> نفر — موافقت: <?= (int)$latest_rule_proposal['approval_count'] ?> نفر — مهلت: <?= community_laws_escape($latest_rule_proposal['review_deadline']) ?></div>
                                <div class="notice">
                                    <strong>متن قواعد پیشنهادی</strong>
                                    <div>حدنصاب مشارکت رأی مستقیم: <?= number_format((float)$latest_rule_proposal['individual_quorum_percent'], 2) ?>٪</div>
                                    <div>موافقت لازم در رأی مستقیم: <?= number_format((float)$latest_rule_proposal['individual_approval_percent'], 2) ?>٪</div>
                                    <div>تأیید جداگانه جوامع: <?= (int)$latest_rule_proposal['require_community_approval'] === 1 ? 'لازم است' : 'لازم نیست' ?></div>
                                    <?php if ((int)$latest_rule_proposal['require_community_approval'] === 1): ?>
                                        <div>حدنصاب مشارکت هر جامعه: <?= number_format((float)$latest_rule_proposal['community_quorum_percent'], 2) ?>٪</div>
                                        <div>موافقت لازم در هر جامعه: <?= number_format((float)$latest_rule_proposal['community_approval_percent'], 2) ?>٪</div>
                                        <div>حدنصاب مشارکت مجموع جوامع: <?= number_format((float)$latest_rule_proposal['community_aggregate_quorum_percent'], 2) ?>٪</div>
                                        <div>موافقت لازم در مجموع جوامع: <?= number_format((float)$latest_rule_proposal['community_aggregate_approval_percent'], 2) ?>٪</div>
                                    <?php endif; ?>
                                    <div>مبنای رأی فردی: <?= ($latest_rule_proposal['individual_vote_basis'] ?? 'count') === 'weighted' ? 'وزن تخصصی بر اساس حوزه‌های مرتبط با موضوع' : 'شمارش برابر هر فرد' ?> — مبنای رأی جوامع: یک جامعه، یک رأی</div>
                                </div>
                                <p><strong>استدلال پیشنهاددهنده:</strong><br><?= nl2br(community_laws_escape($latest_rule_proposal['rationale'])) ?></p>
                                <p class="muted">حدنصاب بررسی دست‌کم سه ارزیابی و تصویب با موافقت حداقل دوسوم ارزیابان شرکت‌کننده است. مهلت هر دور هفت روز است.</p>
                                <?php if (!empty($voting_rule_votes_by_proposal[(int)$latest_rule_proposal['id']])): ?>
                                    <details>
                                        <summary>مشاهده استدلال‌های ارزیابان قواعد</summary>
                                        <?php foreach ($voting_rule_votes_by_proposal[(int)$latest_rule_proposal['id']] as $rule_vote): ?>
                                            <div class="notice">
                                                <div class="meta">ارزیاب #<?= (int)$rule_vote['reviewer_user_id'] ?> — <?= $rule_vote['decision'] === 'approve' ? 'موافق' : 'مخالف' ?> — <?= community_laws_escape($rule_vote['voted_at']) ?></div>
                                                <p><?= nl2br(community_laws_escape($rule_vote['reasoning'])) ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </details>
                                <?php endif; ?>
                                <?php if (
                                    $latest_rule_proposal['status'] === 'pending_review' &&
                                    $is_member &&
                                    community_law_scope_matches($conn, (int)$proposal['version_id'], $community_id) &&
                                    strtotime($latest_rule_proposal['review_deadline']) <= time()
                                ): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                        <input type="hidden" name="proposal_id" value="<?= (int)$latest_rule_proposal['id'] ?>">
                                        <button type="submit" name="finalize_law_voting_rules" value="1">جمع‌بندی و ثبت نتیجه بررسی قواعد</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (
                                    $latest_rule_proposal['status'] === 'pending_review' &&
                                    $is_member &&
                                    (int)$latest_rule_proposal['configured_by'] !== $user_id &&
                                    strtotime($latest_rule_proposal['review_deadline']) > time()
                                ): ?>
                                    <?php
                                    $my_rule_elector_stmt = $conn->prepare(
                                        "SELECT 1 FROM community_law_voting_rule_electorate
                                         WHERE proposal_id=? AND user_id=? LIMIT 1"
                                    );
                                    $my_rule_elector = false;
                                    $rule_proposal_id = (int)$latest_rule_proposal['id'];
                                    if ($my_rule_elector_stmt) {
                                        $my_rule_elector_stmt->bind_param('ii', $rule_proposal_id, $user_id);
                                        if ($my_rule_elector_stmt->execute()) $my_rule_elector = (bool)$my_rule_elector_stmt->get_result()->fetch_row();
                                        $my_rule_elector_stmt->close();
                                    }
                                    $my_rule_vote_stmt = $conn->prepare(
                                        "SELECT 1 FROM community_law_voting_rule_votes
                                         WHERE proposal_id=? AND reviewer_user_id=? LIMIT 1"
                                    );
                                    $my_rule_voted = false;
                                    if ($my_rule_vote_stmt) {
                                        $my_rule_vote_stmt->bind_param('ii', $rule_proposal_id, $user_id);
                                        if ($my_rule_vote_stmt->execute()) $my_rule_voted = (bool)$my_rule_vote_stmt->get_result()->fetch_row();
                                        $my_rule_vote_stmt->close();
                                    }
                                    ?>
                                    <?php if ($my_rule_elector && !$my_rule_voted): ?>
                                        <details>
                                            <summary>ثبت ارزیابی مستقل قواعد رأی‌گیری</summary>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                                <input type="hidden" name="proposal_id" value="<?= $rule_proposal_id ?>">
                                                <label>نظر شما</label>
                                                <select name="rule_decision" required>
                                                    <option value="">انتخاب کنید</option>
                                                    <option value="approve">موافق قواعد پیشنهادی هستم</option>
                                                    <option value="reject">با قواعد پیشنهادی مخالفم</option>
                                                </select>
                                                <label>استدلال و دلیل تصمیم (الزامی)</label>
                                                <textarea name="rule_reasoning" maxlength="20000" required></textarea>
                                                <button type="submit" name="submit_law_voting_rule_assessment" value="1">ثبت ارزیابی</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (
                                $is_member &&
                                (int)$proposal['originating_community_id'] === $community_id &&
                                ($proposal['authority_status'] ?? '') === 'confirmed' &&
                                (!$latest_rule_proposal || $latest_rule_proposal['status'] === 'rejected')
                            ): ?>
                                <details>
                                    <summary><?= $latest_rule_proposal ? 'ثبت پیشنهاد تازه قواعد رأی‌گیری' : 'پیشنهاد قواعد رأی‌گیری' ?></summary>
                                    <p class="muted">پیشنهاددهنده قواعد، ارزیابان غربالگری و ارزیابان صلاحیت در پنل بررسی این قواعد شرکت نمی‌کنند. حداقل سه ارزیاب مستقل، هفت روز فرصت بررسی دارند و تصویب نیازمند دوسوم موافقت است. رأی فردی می‌تواند برابر یا وزن‌دار باشد؛ در حالت وزن‌دار، پروفایل چندحوزه‌ای تأییدشدهٔ موضوع و snapshot وزن‌ها پیش‌شرط آغاز رأی‌گیری است.</p>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                                        <input type="hidden" name="law_id" value="<?= (int)$proposal['law_id'] ?>">
                                        <input type="hidden" name="version_id" value="<?= (int)$proposal['version_id'] ?>">
                                        <label>حدنصاب مشارکت رأی مستقیم (درصد)</label>
                                        <input type="number" name="individual_quorum_percent" min="1" max="100" step="0.01" required>
                                        <label>درصد موافقت لازم در رأی مستقیم (بیش از ۵۰)</label>
                                        <input type="number" name="individual_approval_percent" min="50.01" max="100" step="0.01" required>
                                        <label>مبنای محاسبه رأی مستقیم</label>
                                        <select name="individual_vote_basis" required>
                                            <option value="count">رأی برابر هر فرد</option>
                                            <option value="weighted">وزن تخصصی بر اساس حوزه‌های مرتبط با موضوع</option>
                                        </select>
                                        <p class="muted">در حالت وزن تخصصی، باید پروفایل چندحوزه‌ای همین موضوع قبلاً به تأیید جامعه رسیده باشد. وزن هر فرد هنگام آغاز رأی‌گیری ثابت می‌شود و در بازه ۱ تا ۳ است.</p>
                                        <label>آیا تأیید جداگانه جوامع نیز لازم است؟</label>
                                        <select name="require_community_approval" required>
                                            <option value="">انتخاب کنید</option>
                                            <option value="1">بله</option>
                                            <option value="0">خیر</option>
                                        </select>
                                        <label>حدنصاب مشارکت در هر جامعه (در صورت انتخاب بله)</label>
                                        <input type="number" name="community_quorum_percent" min="1" max="100" step="0.01">
                                        <label>درصد موافقت لازم در هر جامعه، بیش از ۵۰٪ (در صورت انتخاب بله)</label>
                                        <input type="number" name="community_approval_percent" min="50" max="100" step="0.01">
                                        <label>حدنصاب مشارکت مجموع جوامع (در صورت انتخاب بله)</label>
                                        <input type="number" name="community_aggregate_quorum_percent" min="1" max="100" step="0.01">
                                        <label>درصد موافقت لازم در مجموع جوامع، بیش از ۵۰٪ (در صورت انتخاب بله)</label>
                                        <input type="number" name="community_aggregate_approval_percent" min="50" max="100" step="0.01">
                                        <label>توضیح و استدلال برای انتخاب این قواعد (الزامی)</label>
                                        <textarea name="rules_rationale" maxlength="20000" required></textarea>
                                        <button type="submit" name="submit_law_voting_rules" value="1">ارسال قواعد برای بررسی مستقل</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($legal_assessments_by_version[(int)$proposal['version_id']])): ?>
                        <details style="margin-top:10px">
                            <summary>مشاهده ارزیابی‌ها و استدلال‌ها</summary>
                            <?php foreach ($legal_assessments_by_version[(int)$proposal['version_id']] as $assessment): ?>
                                <div class="notice">
                                    <div class="meta">ارزیاب مستقل #<?= (int)$assessment['reviewer_user_id'] ?> — <?= community_laws_escape($assessment['submitted_at']) ?></div>
                                    <div>منشور: <?= community_laws_escape($assessment['charter_compatibility']) ?>؛
                                    قوانین بالادستی: <?= community_laws_escape($assessment['hierarchy_compatibility']) ?>؛
                                    حقوق: <?= community_laws_escape($assessment['rights_impact']) ?>؛
                                    محیط‌زیست: <?= community_laws_escape($assessment['environmental_impact']) ?>؛
                                    اجرا: <?= community_laws_escape($assessment['implementation_feasibility']) ?></div>
                                    <p><?= nl2br(community_laws_escape($assessment['reasoning'])) ?></p>
                                    <?php if (!empty($assessment['evidence'])): ?>
                                        <p class="muted">شواهد: <?= nl2br(community_laws_escape($assessment['evidence'])) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($is_member): ?>
<div class="card">
    <h3>ثبت پیش‌نویس قانون جدید</h3>
    <p class="muted">پیشنهاد قانون باید روشن، قابل اجرا و در محدوده صلاحیت این جامعه باشد. ثبت پیش‌نویس به‌معنای تصویب یا لازم‌الاجرا شدن آن نیست.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
        <label>عنوان قانون</label>
        <input type="text" name="title" maxlength="200" required>
        <label>متن قانون و قواعد الزام‌آور</label>
        <textarea name="law_text" maxlength="50000" required placeholder="متن دقیق و روشن قانون را بنویسید."></textarea>
        <label>برنامه اجرایی (اختیاری)</label>
        <textarea name="implementation_plan" maxlength="30000" placeholder="مسئول اجرا، منابع، زمان‌بندی و شاخص ارزیابی را مشخص کنید."></textarea>
        <label>تصمیم جمعی منشأ قانون (اختیاری)</label>
        <select name="source_decision_id">
            <option value="0">بدون پیوند مستقیم به تصمیم</option>
            <?php foreach ($source_decisions as $source_decision_option): ?>
                <option value="<?= (int)$source_decision_option['id'] ?>">
                    تصمیم #<?= (int)$source_decision_option['id'] ?> — موضوع: <?= community_laws_escape($source_decision_option['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!$decision_ledger_ready): ?>
            <p class="muted">دفتر تصمیم‌ها هنوز در این پایگاه داده نصب نشده است؛ می‌توانید شناسهٔ موضوع یا ایده را به‌عنوان منشأ ثبت کنید.</p>
        <?php elseif (!$source_decisions): ?>
            <p class="muted">در این جامعه تصمیم پذیرفته‌شده‌ای برای انتخاب وجود ندارد؛ در صورت نیاز، منشأ موضوع را جداگانه مشخص کنید.</p>
        <?php else: ?>
            <p class="muted">فقط تصمیم‌های پذیرفته‌شده و ثبت‌شدهٔ همین جامعه نمایش داده می‌شوند. با انتخاب تصمیم، موضوع و ایدهٔ مرتبط خودکار پیوند می‌خورند. این پیوند به‌تنهایی قانون را تصویب یا لازم‌الاجرا نمی‌کند.</p>
        <?php endif; ?>
        <label>شناسه موضوع مرتبط (اختیاری، در صورت نداشتن شناسه تصمیم)</label>
        <input type="number" name="source_topic_id" min="1">
        <label>شناسه ایده مرتبط (اختیاری، در صورت نداشتن شناسه تصمیم)</label>
        <input type="number" name="source_idea_id" min="1">
        <div class="notice">
            در صورت تصویب و پس از طی بررسی صلاحیت و مراحل نهایی، این قانون برای همه جوامع زیرشاخه نیز لازم‌الرعایه خواهد بود. دامنه شمول به‌صورت خودکار با تغییرات معتبر سلسله‌مراتب به‌روزرسانی و تاریخچه آن ثبت می‌شود.
        </div>
        <div class="notice warning">دامنه شمول در این مرحله فقط ثبت می‌شود؛ پیش از رأی‌گیری باید صلاحیت قانون و آثار آن بررسی شود.</div>
        <button type="submit" name="create_law_draft" value="1">ثبت پیش‌نویس</button>
    </form>
</div>
<?php else: ?>
<div class="card notice">برای ثبت پیش‌نویس قانون یا اعتراض باید عضو این جامعه باشید.</div>
<?php endif; ?>

<div class="card">
    <h3>اعتراض‌های ثبت‌شده در این جامعه</h3>
    <?php if (!$recent_objections): ?>
        <p class="muted">اعتراضی ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($recent_objections as $objection): ?>
            <div class="notice">
                <strong><?= community_laws_escape($objection['title']) ?></strong>
                <div class="meta"><?= community_laws_escape($objection['law_code']) ?> — وضعیت: <?= community_laws_escape($objection['status']) ?></div>
                <p><?= nl2br(community_laws_escape($objection['description'])) ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php endif; ?>
</div>
</body>
</html>
