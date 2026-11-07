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
                if ($e->getMessage() === 'invalid_law_scope') community_laws_redirect($community_id, 'invalid_law_scope');
                if ($e->getMessage() === 'empty_law_electorate') community_laws_redirect($community_id, 'empty_law_electorate');
            }
            error_log('[Dolate Eshgh law vote start] ' . $e->getMessage());
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
        "SELECT l.id AS law_id, l.law_code, v.id AS version_id, v.title,
                v.created_at, v.proposed_by
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         WHERE l.originating_community_id=? AND l.status='proposed' AND v.status='proposed'
         ORDER BY v.created_at DESC LIMIT 30"
    );
    if ($proposed_stmt) {
        $proposed_stmt->bind_param('i', $community_id);
        if ($proposed_stmt->execute()) {
            $proposed_result = $proposed_stmt->get_result();
            while ($row = $proposed_result->fetch_assoc()) $proposed_laws[] = $row;
        }
        $proposed_stmt->close();
    }

    $voting_stmt = $conn->prepare(
        "SELECT l.id AS law_id, l.law_code, v.id AS version_id, v.title,
                v.law_text, v.vote_deadline,
                (SELECT cv.vote FROM community_law_votes cv
                 WHERE cv.law_version_id=v.id AND cv.voting_community_id=? AND cv.voter_user_id=?
                 LIMIT 1) AS current_vote
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         JOIN community_law_electorate e ON e.law_version_id=v.id
             AND e.voting_community_id=? AND e.user_id=?
         WHERE l.status='voting' AND v.status='voting' AND v.vote_deadline > NOW()
         ORDER BY v.vote_deadline ASC LIMIT 50"
    );
    if ($voting_stmt) {
        $voting_stmt->bind_param('iiii', $community_id, $user_id, $community_id, $user_id);
        if ($voting_stmt->execute()) {
            $voting_result = $voting_stmt->get_result();
            while ($row = $voting_result->fetch_assoc()) $voting_laws[] = $row;
        }
        $voting_stmt->close();
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
    'submitted_for_review' => 'پیش‌نویس برای بررسی صلاحیت و دامنه شمول ارسال شد؛ هنوز رأی‌گیری یا الزام‌آوری آغاز نشده است.',
    'law_vote_saved' => 'رأی شما ثبت شد؛ تا پایان مهلت می‌توانید آن را تغییر دهید.',
    'invalid_law_vote' => 'رأی یا اطلاعات ارسالی معتبر نیست.',
    'not_law_elector' => 'نام شما در فهرست رأی‌دهندگان این رأی‌گیری ثبت نشده است.',
    'voting_started' => 'رأی‌گیری هفت‌روزه آغاز شد و فهرست اعضای واجد شرایط ثبت شد.',
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
    <h3>رأی‌گیری‌های فعال این جامعه</h3>
    <?php if (!$voting_laws): ?>
        <p class="muted">رأی‌گیری فعالی که شما در آن واجد شرایط باشید وجود ندارد.</p>
    <?php else: ?>
        <?php foreach ($voting_laws as $active_law): ?>
            <div class="notice">
                <strong><?= community_laws_escape($active_law['title']) ?></strong>
                <div class="meta"><?= community_laws_escape($active_law['law_code']) ?> — پایان رأی‌گیری: <?= community_laws_escape($active_law['vote_deadline']) ?></div>
                <div class="law-text"><?= community_laws_escape($active_law['law_text']) ?></div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                    <input type="hidden" name="version_id" value="<?= (int)$active_law['version_id'] ?>">
                    <label>رأی شما</label>
                    <select name="vote" required>
                        <option value="yes" <?= ($active_law['current_vote'] ?? '') === 'yes' ? 'selected' : '' ?>>موافقم</option>
                        <option value="no" <?= ($active_law['current_vote'] ?? '') === 'no' ? 'selected' : '' ?>>مخالفم</option>
                        <option value="abstain" <?= ($active_law['current_vote'] ?? '') === 'abstain' ? 'selected' : '' ?>>ممتنع</option>
                    </select>
                    <label>توضیح (اختیاری)</label>
                    <textarea name="comment" maxlength="5000"></textarea>
                    <button type="submit" name="cast_law_vote" value="1">ثبت یا تغییر رأی</button>
                </form>
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
                <div class="meta"><?= community_laws_escape($proposal['law_code']) ?> — آماده آغاز رأی‌گیری پس از بررسی صلاحیت و دامنه شمول</div>
                <?php if ($is_member): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= community_laws_escape($csrf_token) ?>">
                    <input type="hidden" name="law_id" value="<?= (int)$proposal['law_id'] ?>">
                    <input type="hidden" name="version_id" value="<?= (int)$proposal['version_id'] ?>">
                    <button type="submit" name="start_law_vote" value="1">آغاز رأی‌گیری هفت‌روزه</button>
                </form>
                <?php endif; ?>
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
