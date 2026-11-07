<?php
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/community_law_helpers.php';

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
        $source_topic_id = $source_topic_id > 0 ? $source_topic_id : null;
        $source_idea_id = $source_idea_id > 0 ? $source_idea_id : null;
        $include_descendants = isset($_POST['include_descendants']) ? 1 : 0;

        if ($title === '' || mb_strlen($title) > 200 || $law_text === '' || mb_strlen($law_text) > 50000 || mb_strlen($implementation_plan) > 30000) {
            community_laws_redirect($community_id, 'invalid');
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
                    (law_id, version_number, title, law_text, implementation_plan, source_topic_id, source_idea_id, proposed_by, status)
                 VALUES (?, 1, ?, ?, ?, ?, ?, ?, 'draft')"
            );
            if (!$version_insert) throw new Exception('version insert prepare failed');
            $version_insert->bind_param(
                'isssiii',
                $law_id,
                $title,
                $law_text,
                $implementation_plan,
                $source_topic_id,
                $source_idea_id,
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
                    if (community_law_scope_matches($conn, $reference_version, $scope_target_id)) {
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
            error_log('[Dolate Eshgh law submission] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['start_law_vote'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $law_id = (int)($_POST['law_id'] ?? 0);
        $version_id = (int)($_POST['version_id'] ?? 0);
        if ($law_id <= 0 || $version_id <= 0) community_laws_redirect($community_id, 'invalid_vote_start');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $lock = $conn->prepare(
                "SELECT l.status, l.current_version_id, v.status AS version_status
                 FROM community_laws l
                 JOIN community_law_versions v ON v.law_id=l.id
                 WHERE l.id=? AND v.id=? AND l.originating_community_id=?
                 FOR UPDATE"
            );
            if (!$lock) throw new Exception('law vote lock prepare failed');
            $lock->bind_param('iii', $law_id, $version_id, $community_id);
            if (!$lock->execute()) throw new Exception('law vote lock failed');
            $record = $lock->get_result()->fetch_assoc();
            $lock->close();

            if (
                !$record ||
                $record['status'] !== 'proposed' ||
                $record['version_status'] !== 'proposed' ||
                (int)$record['current_version_id'] !== $version_id
            ) {
                throw new RuntimeException('invalid_law_vote_state');
            }

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
                $rules['jurisdiction_level'] !== $authority_case['derived_jurisdiction_level']
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

            $law_update = $conn->prepare(
                "UPDATE community_laws SET status='voting' WHERE id=? AND status='proposed'"
            );
            if (!$law_update) throw new Exception('law start prepare failed');
            $law_update->bind_param('i', $law_id);
            if (!$law_update->execute() || $law_update->affected_rows !== 1) {
                throw new Exception('law start failed');
            }
            $law_update->close();

            if (!community_law_record_history(
                $conn, $law_id, $version_id, $community_id, $user_id,
                'voting_started',
                'رأی‌گیری هفت‌روزه آغاز شد؛ فهرست اعضای واجد شرایط هر جامعه مشمول در زمان آغاز ثبت شد.'
            )) throw new Exception('law vote history failed');

            if (!$conn->commit()) throw new Exception('law vote commit failed');
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
            }
            error_log('[Dolate Eshgh law vote start] ' . $e->getMessage());
            community_laws_redirect($community_id, 'save_error');
        }
    }

    if (isset($_POST['finalize_law_tally'])) {
        if (!$is_member) community_laws_redirect($community_id, 'not_member');

        $law_id = (int)($_POST['law_id'] ?? 0);
        $version_id = (int)($_POST['version_id'] ?? 0);
        if ($law_id <= 0 || $version_id <= 0) community_laws_redirect($community_id, 'invalid_tally');

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $lock = $conn->prepare(
                "SELECT l.status, l.current_version_id, v.status AS version_status, v.vote_deadline
                 FROM community_laws l
                 JOIN community_law_versions v ON v.law_id=l.id
                 WHERE l.id=? AND v.id=? AND l.originating_community_id=?
                 FOR UPDATE"
            );
            if (!$lock) throw new Exception('tally lock prepare failed');
            $lock->bind_param('iii', $law_id, $version_id, $community_id);
            if (!$lock->execute()) throw new Exception('tally lock failed');
            $record = $lock->get_result()->fetch_assoc();
            $lock->close();

            if (
                !$record || $record['status'] !== 'voting' ||
                $record['version_status'] !== 'voting' ||
                (int)$record['current_version_id'] !== $version_id ||
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

                // موضع رسمی جامعه فقط وقتی تعیین می‌شود که قواعد، الزام تصویب جامعه را مقرر کرده باشند.
                if ((int)$approved_rules['require_community_approval'] === 1) {
                    $community_quorum = (float)$approved_rules['community_quorum_percent'];
                    $community_approval = (float)$approved_rules['community_approval_percent'];
                    $turnout_percent = (int)$tally['electorate_count'] > 0
                        ? ($cast_count / (int)$tally['electorate_count']) * 100
                        : 0;
                    $decisive_votes = $yes + $no;
                    if (
                        $cast_count === 0 ||
                        $turnout_percent + 0.00001 < $community_quorum ||
                        $decisive_votes === 0
                    ) {
                        $position_status = 'insufficient';
                    } else {
                        $yes_percent = ($yes / $decisive_votes) * 100;
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

            if (!community_law_record_history(
                $conn, $law_id, $version_id, $community_id, $user_id,
                'voting_result_calculated',
                'نتیجه مرکب رأی‌گیری بر اساس قواعد تأییدشده محاسبه شد؛ این نتیجه به‌تنهایی موجب لازم‌الاجرا شدن قانون نیست.'
            )) throw new Exception('tally history failed');

            if (!$conn->commit()) throw new Exception('tally commit failed');
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
        $version_id = (int)($_POST['version_id'] ?? 0);
        $vote = (string)($_POST['direct_vote'] ?? '');
        $comment = trim((string)($_POST['direct_comment'] ?? ''));
        if ($version_id <= 0 || !in_array($vote, ['yes','no','abstain'], true) || mb_strlen($comment) > 5000) {
            community_laws_redirect($community_id, 'invalid_law_vote');
        }

        if (!$conn->begin_transaction()) community_laws_redirect($community_id, 'save_error');
        try {
            $eligible = $conn->prepare(
                "SELECT e.vote_weight
                 FROM community_law_direct_electorate e
                 JOIN community_law_versions v ON v.id=e.law_version_id
                 JOIN community_laws l ON l.id=v.law_id
                 WHERE e.law_version_id=? AND e.user_id=?
                   AND v.status='voting' AND l.status='voting'
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
        $version_id = (int)($_POST['version_id'] ?? 0);
        $vote = (string)($_POST['vote'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));
        if ($version_id <= 0 || !in_array($vote, ['yes','no','abstain'], true) || mb_strlen($comment) > 5000) {
            community_laws_redirect($community_id, 'invalid_law_vote');
        }

        $eligible = $conn->prepare(
            "SELECT e.vote_weight
             FROM community_law_electorate e
             JOIN community_law_versions v ON v.id=e.law_version_id
             JOIN community_laws l ON l.id=v.law_id
             WHERE e.law_version_id=? AND e.voting_community_id=? AND e.user_id=?
               AND v.status='voting' AND l.status='voting'
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
        community_laws_redirect($community_id, 'law_vote_saved');
    }

    if (isset($_POST['submit_position_discussion'])) {
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
               AND v.status='voting' AND l.status='voting'
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

if ($schema_ready) {
    $law_result = $conn->query(
        "SELECT l.id AS law_id, l.law_code, l.originating_community_id,
                l.effective_at, l.expires_at,
                v.id AS version_id, v.version_number, v.title, v.law_text,
                v.implementation_plan, v.approved_at
         FROM community_laws l
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
    'submitted_for_review' => 'پیشنهاد برای غربالگری حقوقی مستقل ارسال شد؛ رأی‌گیری تا تکمیل این مرحله متوقف است.',
    'legal_assessment_saved' => 'ارزیابی مستقل ثبت شد.',
    'legal_assessment_not_allowed' => 'شما برای ارزیابی این پیشنهاد واجد شرایط نیستید یا مرحله بررسی بسته شده است.',
    'legal_screening_required' => 'غربالگری حقوقی مستقل هنوز تکمیل نشده یا تعارض و ابهامی در آن ثبت شده است؛ آغاز رأی‌گیری مجاز نیست.',
    'authority_review_required' => 'بررسی مستقل صلاحیت و انطباق با قوانین معتبر هنوز تأیید نشده است؛ آغاز رأی‌گیری مجاز نیست.',
    'population_snapshot_required' => 'وزن‌دهی بر اساس جمعیت تا زمان فراهم شدن آمار رسمی و snapshot معتبر جمعیت قابل استفاده نیست.',
    'invalid_legal_assessment' => 'اطلاعات ارزیابی حقوقی معتبر نیست.',
    'law_scope_exceeds_authority' => 'جامعه نمی‌تواند به‌تنهایی قانون الزام‌آور را به جامعه مادر یا سطح بالاتر گسترش دهد.',
    'authority_assessment_saved' => 'ارزیابی صلاحیت و قوانین مرتبط ثبت شد.',
    'authority_assessment_not_allowed' => 'شما برای این ارزیابی واجد شرایط نیستید یا مرحله بررسی بسته شده است.',
    'invalid_authority_assessment' => 'اطلاعات ارزیابی صلاحیت یا تعارض قوانین معتبر نیست.',
    'invalid_law_scope' => 'ساختار سلسله‌مراتب جوامع یا دامنه شمول معتبر نیست؛ بررسی صلاحیت متوقف شد.',
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
            <div class="card" style="background:#fbfdff;box-shadow:none">
                <strong><?= community_laws_escape($law['title']) ?></strong>
                <div class="meta">
                    شناسه: <?= community_laws_escape($law['law_code']) ?>
                    — نسخه <?= (int)$law['version_number'] ?>
                    <?= $law['originating_community_id'] === $community_id ? '— مصوب همین جامعه' : '— قانون بالادستی' ?>
                </div>
                <div class="law-text"><?= community_laws_escape($law['law_text']) ?></div>
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
                    <div class="meta">ارزیابی‌های ثبت‌شده: <?= (int)$proposal['reviewer_count'] ?> از <?= (int)($proposal['reviewer_quorum'] ?? 3) ?> ارزیاب مستقل</div>
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
                            <div class="meta">ارزیابی‌های مستقل ثبت‌شده: <?= (int)$proposal['authority_reviewer_count'] ?> از <?= (int)($proposal['authority_reviewer_quorum'] ?? 3) ?></div>

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
        <label>شناسه موضوع مرتبط (اختیاری)</label>
        <input type="number" name="source_topic_id" min="1">
        <label>شناسه ایده مرتبط (اختیاری)</label>
        <input type="number" name="source_idea_id" min="1">
        <label class="scope-check">
            <input type="checkbox" name="include_descendants" value="1" checked>
            <span>در صورت تصویب و با رعایت صلاحیت قانونی، این قانون در زیرشاخه‌های این جامعه نیز لازم‌الرعایه باشد.</span>
        </label>
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
