<?php
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/community_law_helpers.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('ابتدا وارد سامانه شوید.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر است.');
}

$user_id = (int)$_SESSION['user_id'];
$csrf = $_POST['csrf_token'] ?? '';
if (
    empty($_SESSION['charter_amendment_csrf']) ||
    !is_string($csrf) || $csrf === '' ||
    !hash_equals($_SESSION['charter_amendment_csrf'], $csrf)
) {
    http_response_code(403);
    exit('نشست معتبر نیست؛ صفحه را تازه‌سازی کنید.');
}

function cam_redirect(string $status): void {
    header('Location: community_charter_amendments.php?status=' . rawurlencode($status));
    exit;
}

function cam_tables_ready(mysqli $conn): bool {
    $required = [
        'users', 'community_charter_versions', 'community_charter_articles',
        'community_charter_amendments', 'community_charter_amendment_items',
        'community_charter_amendment_reviewers', 'community_charter_amendment_assessments',
        'community_charter_amendment_electorate', 'community_charter_amendment_votes',
        'community_charter_amendment_appeals', 'community_charter_amendment_appeal_reviewers',
        'community_charter_amendment_appeal_reviewer_assignments'
    ];
    $marks = implode(',', array_fill(0, count($required), '?'));
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name IN ($marks)"
    );
    if (!$stmt) return false;
    $types = str_repeat('s', count($required));
    $params = array_merge([$types], $required);
    $refs = [];
    foreach ($params as $i => &$value) $refs[$i] = &$value;
    call_user_func_array([$stmt, 'bind_param'], $refs);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $count = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $count === count($required);
}

function cam_assessments(mysqli $conn, int $amendment_id): ?array {
    $stmt = $conn->prepare(
        "SELECT reviewer_user_id, verdict, rights_protection,
                equality_and_non_discrimination, living_beings_and_environment,
                power_and_accountability, future_generations
         FROM community_charter_amendment_assessments
         WHERE amendment_id=? ORDER BY reviewer_user_id"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $amendment_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function cam_reject_amendment(mysqli $conn, int $amendment_id, int $version_id): void {
    $amendment = $conn->prepare(
        "UPDATE community_charter_amendments SET status='rejected' WHERE id=?"
    );
    if (!$amendment) throw new RuntimeException('ثبت رد اصلاحیه ممکن نیست.');
    $amendment->bind_param('i', $amendment_id);
    if (!$amendment->execute()) throw new RuntimeException('ثبت رد اصلاحیه انجام نشد.');
    $amendment->close();

    $version = $conn->prepare(
        "UPDATE community_charter_versions SET status='rejected' WHERE id=? AND status<>'effective'"
    );
    if (!$version) throw new RuntimeException('بستن نسخه پیشنهادی ممکن نیست.');
    $version->bind_param('i', $version_id);
    if (!$version->execute()) throw new RuntimeException('بستن نسخه پیشنهادی انجام نشد.');
    $version->close();
}

if (!cam_tables_ready($conn)) {
    http_response_code(503);
    exit('ساختار لازم هنوز آماده نیست. ابتدا migrationهای مربوط به منشور باید در محیط آزمایشی بررسی شوند.');
}

$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'create_draft') {
        $title = trim((string)($_POST['title'] ?? ''));
        $justification = trim((string)($_POST['justification'] ?? ''));
        $impact = trim((string)($_POST['impact_assessment'] ?? ''));
        $actions = $_POST['item_action'] ?? [];
        $targets = $_POST['target_article_id'] ?? [];
        $codes = $_POST['article_code'] ?? [];
        $titles = $_POST['proposed_title'] ?? [];
        $texts = $_POST['proposed_text'] ?? [];
        $groups = $_POST['principle_group'] ?? [];
        $rationales = $_POST['item_rationale'] ?? [];

        if (
            $title === '' || mb_strlen($title) > 200 ||
            mb_strlen($justification) < 30 || mb_strlen($justification) > 30000 ||
            mb_strlen($impact) < 30 || mb_strlen($impact) > 30000 ||
            !is_array($actions) || !is_array($targets) || !is_array($codes) ||
            !is_array($titles) || !is_array($texts) || !is_array($groups) ||
            !is_array($rationales) || count($actions) < 1 || count($actions) > 30
        ) {
            throw new RuntimeException('اطلاعات پیشنهاد یا اصلاح مواد کامل و معتبر نیست.');
        }
        $item_count = count($actions);
        foreach ([$targets, $codes, $titles, $texts, $groups, $rationales] as $field) {
            if (count($field) !== $item_count) throw new RuntimeException('تعداد فیلدهای اصلاح مواد هماهنگ نیست.');
        }

        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $active = $conn->query(
            "SELECT id, version_number FROM community_charter_versions
             WHERE status='effective' AND effective_at<=NOW() FOR UPDATE"
        );
        if (!$active) throw new RuntimeException('قفل نسخه جاری منشور ممکن نیست.');
        $active_rows = [];
        while ($row = $active->fetch_assoc()) $active_rows[] = $row;
        $active->free();
        if (count($active_rows) !== 1) throw new RuntimeException('نسخه جاری منشور یکتا و معتبر نیست.');
        $base_version_id = (int)$active_rows[0]['id'];

        $base_stmt = $conn->prepare(
            "SELECT id, article_code, title, article_text, principle_group, is_core, display_order
             FROM community_charter_articles WHERE charter_version_id=? ORDER BY display_order,id"
        );
        if (!$base_stmt) throw new RuntimeException('خواندن مواد منشور ممکن نیست.');
        $base_stmt->bind_param('i', $base_version_id);
        if (!$base_stmt->execute()) throw new RuntimeException('خواندن مواد منشور انجام نشد.');
        $base_articles = [];
        $base_by_id = [];
        $base_by_code = [];
        $base_result = $base_stmt->get_result();
        while ($article = $base_result->fetch_assoc()) {
            $article['id'] = (int)$article['id'];
            $article['is_core'] = (int)$article['is_core'];
            $base_articles[] = $article;
            $base_by_id[$article['id']] = $article;
            $base_by_code[$article['article_code']] = $article;
        }
        $base_stmt->close();
        if (!$base_articles) throw new RuntimeException('مواد نسخه جاری منشور پیدا نشد.');

        $allowed_groups = [
            'human_dignity','equality','freedom','justice','peace','living_beings',
            'environment','shared_resources','self_governance','accountability',
            'future_generations','technology'
        ];
        $items = [];
        $validation_items = [];
        for ($i = 0; $i < $item_count; $i++) {
            $item_action = (string)$actions[$i];
            $code = strtoupper(trim((string)$codes[$i]));
            $target_id = (int)$targets[$i];
            $proposed_title = trim((string)$titles[$i]);
            $proposed_text = trim((string)$texts[$i]);
            $group = (string)$groups[$i];
            $rationale = trim((string)$rationales[$i]);
            if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/', $code) ||
                mb_strlen($rationale) < 10 || mb_strlen($rationale) > 10000) {
                throw new RuntimeException('شناسه ماده یا دلیل اصلاح معتبر نیست.');
            }

            $target = $target_id > 0 ? ($base_by_id[$target_id] ?? null) : null;
            if ($item_action === 'add') {
                if ($target || isset($base_by_code[$code]) ||
                    !in_array($group, $allowed_groups, true) ||
                    $proposed_title === '' || mb_strlen($proposed_title) > 200 ||
                    $proposed_text === '' || mb_strlen($proposed_text) > 30000) {
                    throw new RuntimeException('ماده تازه باید شناسه یکتا، عنوان، متن و دسته معتبر داشته باشد.');
                }
            } elseif (in_array($item_action, ['replace','remove'], true)) {
                if (!$target || $target['article_code'] !== $code) {
                    throw new RuntimeException('ماده هدف در نسخه جاری منشور معتبر نیست.');
                }
                if ($item_action === 'replace' &&
                    (!in_array($group, $allowed_groups, true) || $proposed_title === '' ||
                     mb_strlen($proposed_title) > 200 || $proposed_text === '' ||
                     mb_strlen($proposed_text) > 30000)) {
                    throw new RuntimeException('متن جایگزین ماده کامل یا معتبر نیست.');
                }
            } else {
                throw new RuntimeException('نوع اصلاح ماده معتبر نیست.');
            }

            $items[] = [
                'action' => $item_action,
                'target_article_id' => $target_id > 0 ? $target_id : null,
                'article_code' => $code,
                'proposed_title' => $proposed_title,
                'proposed_text' => $proposed_text,
                'principle_group' => $group,
                'rationale' => $rationale,
                'is_core' => $target ? $target['is_core'] : 0
            ];
            $validation_items[] = $items[count($items)-1];
        }
        if (!community_charter_amendment_items_are_valid($validation_items)) {
            throw new RuntimeException('ترکیب اصلاح مواد تکراری یا ناقض قواعد منشور است.');
        }

        $number_result = $conn->query(
            "SELECT COALESCE(MAX(version_number),0)+1 AS next_version
             FROM community_charter_versions"
        );
        if (!$number_result) throw new RuntimeException('تعیین شماره نسخه جدید ممکن نیست.');
        $version_number = (int)$number_result->fetch_assoc()['next_version'];
        $number_result->free();

        $version_title = 'پیشنهاد اصلاح منشور: ' . $title;
        $summary = 'پیش‌نویس اصلاح منشور بنیادین؛ ' . $justification;
        $version_insert = $conn->prepare(
            "INSERT INTO community_charter_versions
                (version_number,title,status,change_summary,proposed_by)
             VALUES (?,?,'draft',?,?)"
        );
        if (!$version_insert) throw new RuntimeException('ساخت نسخه پیشنهادی ممکن نیست.');
        $version_insert->bind_param('issi', $version_number, $version_title, $summary, $user_id);
        if (!$version_insert->execute()) throw new RuntimeException('ساخت نسخه پیشنهادی انجام نشد.');
        $proposed_version_id = (int)$version_insert->insert_id;
        $version_insert->close();

        $clone = $conn->prepare(
            "INSERT INTO community_charter_articles
                (charter_version_id,article_code,title,article_text,principle_group,is_core,display_order)
             SELECT ?,article_code,title,article_text,principle_group,is_core,display_order
             FROM community_charter_articles WHERE charter_version_id=?"
        );
        if (!$clone) throw new RuntimeException('کپی مواد منشور ممکن نیست.');
        $clone->bind_param('ii', $proposed_version_id, $base_version_id);
        if (!$clone->execute()) throw new RuntimeException('کپی مواد منشور انجام نشد.');
        $clone->close();

        $amendment_insert = $conn->prepare(
            "INSERT INTO community_charter_amendments
                (base_charter_version_id,proposed_charter_version_id,proposer_user_id,
                 title,justification,impact_assessment,status)
             VALUES (?,?,?,?,?,?,'draft')"
        );
        if (!$amendment_insert) throw new RuntimeException('ثبت پرونده اصلاح منشور ممکن نیست.');
        $amendment_insert->bind_param(
            'iiisss', $base_version_id, $proposed_version_id, $user_id,
            $title, $justification, $impact
        );
        if (!$amendment_insert->execute()) throw new RuntimeException('ثبت پرونده اصلاح منشور انجام نشد.');
        $amendment_id = (int)$amendment_insert->insert_id;
        $amendment_insert->close();

        $add_order = count($base_articles) + 1;
        foreach ($items as $item) {
            if ($item['action'] === 'add') {
                $insert_article = $conn->prepare(
                    "INSERT INTO community_charter_articles
                        (charter_version_id,article_code,title,article_text,principle_group,is_core,display_order)
                     VALUES (?,?,?,?,?,0,?)"
                );
                if (!$insert_article) throw new RuntimeException('افزودن ماده پیشنهادی ممکن نیست.');
                $insert_article->bind_param(
                    'issssi', $proposed_version_id, $item['article_code'],
                    $item['proposed_title'], $item['proposed_text'],
                    $item['principle_group'], $add_order
                );
                if (!$insert_article->execute()) throw new RuntimeException('افزودن ماده پیشنهادی انجام نشد.');
                $insert_article->close();
                $add_order++;
            } elseif ($item['action'] === 'replace') {
                $replace = $conn->prepare(
                    "UPDATE community_charter_articles
                     SET title=?,article_text=?,principle_group=?
                     WHERE charter_version_id=? AND article_code=?"
                );
                if (!$replace) throw new RuntimeException('اصلاح متن ماده ممکن نیست.');
                $replace->bind_param(
                    'sssis', $item['proposed_title'], $item['proposed_text'],
                    $item['principle_group'], $proposed_version_id, $item['article_code']
                );
                if (!$replace->execute() || $replace->affected_rows !== 1) {
                    throw new RuntimeException('اصلاح متن ماده انجام نشد.');
                }
                $replace->close();
            } else {
                $remove = $conn->prepare(
                    "DELETE FROM community_charter_articles
                     WHERE charter_version_id=? AND article_code=? AND is_core=0"
                );
                if (!$remove) throw new RuntimeException('حذف ماده غیر‌بنیادین ممکن نیست.');
                $remove->bind_param('is', $proposed_version_id, $item['article_code']);
                if (!$remove->execute() || $remove->affected_rows !== 1) {
                    throw new RuntimeException('حذف ماده غیر‌بنیادین انجام نشد.');
                }
                $remove->close();
            }

            $item_insert = $conn->prepare(
                "INSERT INTO community_charter_amendment_items
                    (amendment_id,action,target_article_id,article_code,proposed_title,
                     proposed_text,principle_group,rationale)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            if (!$item_insert) throw new RuntimeException('ثبت جزئیات اصلاح ممکن نیست.');
            $target_id = $item['target_article_id'];
            $proposed_title = $item['proposed_title'] !== '' ? $item['proposed_title'] : null;
            $proposed_text = $item['proposed_text'] !== '' ? $item['proposed_text'] : null;
            $group = in_array($item['action'], ['add','replace'], true) ? $item['principle_group'] : null;
            $item_insert->bind_param(
                'isisssss', $amendment_id, $item['action'], $target_id,
                $item['article_code'], $proposed_title, $proposed_text,
                $group, $item['rationale']
            );
            if (!$item_insert->execute()) throw new RuntimeException('ثبت جزئیات اصلاح انجام نشد.');
            $item_insert->close();
        }

        if (!$conn->commit()) throw new RuntimeException('ذخیره پیش‌نویس نهایی نشد.');
        cam_redirect('draft_created');
    }

    if ($action === 'submit_for_review') {
        $amendment_id = (int)($_POST['amendment_id'] ?? 0);
        if ($amendment_id <= 0) throw new RuntimeException('شناسه پیشنهاد معتبر نیست.');
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');

        $active = $conn->query(
            "SELECT id FROM community_charter_versions
             WHERE status='effective' AND effective_at<=NOW() FOR UPDATE"
        );
        if (!$active) throw new RuntimeException('قفل منشور جاری ممکن نیست.');
        $active_ids = [];
        while ($row = $active->fetch_assoc()) $active_ids[] = (int)$row['id'];
        $active->free();
        if (count($active_ids) !== 1) throw new RuntimeException('نسخه جاری منشور یکتا نیست.');

        $lock = $conn->prepare(
            "SELECT id,base_charter_version_id,proposed_charter_version_id,
                    proposer_user_id,status
             FROM community_charter_amendments WHERE id=? FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('قفل پرونده اصلاح ممکن نیست.');
        $lock->bind_param('i', $amendment_id);
        if (!$lock->execute()) throw new RuntimeException('خواندن پرونده اصلاح انجام نشد.');
        $amendment = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$amendment || (int)$amendment['proposer_user_id'] !== $user_id ||
            $amendment['status'] !== 'draft' ||
            (int)$amendment['base_charter_version_id'] !== $active_ids[0]) {
            throw new RuntimeException('پیش‌نویس متعلق به شما نیست یا منشور پایه آن دیگر جاری نیست.');
        }

        $users = $conn->query("SELECT id FROM users WHERE id<>" . $user_id . " ORDER BY id");
        if (!$users) throw new RuntimeException('خواندن فهرست کاربران ممکن نیست.');
        $candidates = [];
        while ($row = $users->fetch_assoc()) $candidates[] = (int)$row['id'];
        $users->free();
        if (count($candidates) < 8) {
            throw new RuntimeException('برای آغاز بررسی مستقل دست‌کم پنج ارزیاب و سه رأی‌دهنده دیگر لازم است.');
        }
        shuffle($candidates);
        $reviewer_ids = array_slice($candidates, 0, 5);
        $reviewer_map = array_fill_keys($reviewer_ids, true);

        $reviewer_insert = $conn->prepare(
            "INSERT INTO community_charter_amendment_reviewers (amendment_id,reviewer_user_id)
             VALUES (?,?)"
        );
        if (!$reviewer_insert) throw new RuntimeException('ثبت ارزیابان مستقل ممکن نیست.');
        foreach ($reviewer_ids as $reviewer_id) {
            $reviewer_insert->bind_param('ii', $amendment_id, $reviewer_id);
            if (!$reviewer_insert->execute()) throw new RuntimeException('ثبت ارزیاب مستقل انجام نشد.');
        }
        $reviewer_insert->close();

        $electorate_insert = $conn->prepare(
            "INSERT INTO community_charter_amendment_electorate (amendment_id,user_id) VALUES (?,?)"
        );
        if (!$electorate_insert) throw new RuntimeException('ثبت فهرست رأی‌دهندگان ممکن نیست.');
        $electorate_count = 0;
        foreach ($candidates as $candidate_id) {
            if (isset($reviewer_map[$candidate_id])) continue;
            $electorate_insert->bind_param('ii', $amendment_id, $candidate_id);
            if (!$electorate_insert->execute()) throw new RuntimeException('ثبت رأی‌دهندگان انجام نشد.');
            $electorate_count++;
        }
        $electorate_insert->close();
        if ($electorate_count < 3) throw new RuntimeException('فهرست رأی‌دهندگان برای رأی‌گیری معتبر کافی نیست.');

        $update = $conn->prepare(
            "UPDATE community_charter_amendments
             SET status='independent_review',review_opens_at=NOW(),
                 review_closes_at=DATE_ADD(NOW(),INTERVAL 14 DAY)
             WHERE id=? AND status='draft'"
        );
        if (!$update) throw new RuntimeException('آغاز بررسی مستقل ممکن نیست.');
        $update->bind_param('i', $amendment_id);
        if (!$update->execute() || $update->affected_rows !== 1) {
            throw new RuntimeException('آغاز بررسی مستقل انجام نشد.');
        }
        $update->close();
        if (!$conn->commit()) throw new RuntimeException('ثبت آغاز بررسی مستقل نهایی نشد.');
        cam_redirect('review_started');
    }

    if ($action === 'assess') {
        $amendment_id = (int)($_POST['amendment_id'] ?? 0);
        $verdict = (string)($_POST['verdict'] ?? '');
        $dimensions = [
            'rights_protection' => (string)($_POST['rights_protection'] ?? ''),
            'equality_and_non_discrimination' => (string)($_POST['equality_and_non_discrimination'] ?? ''),
            'living_beings_and_environment' => (string)($_POST['living_beings_and_environment'] ?? ''),
            'power_and_accountability' => (string)($_POST['power_and_accountability'] ?? ''),
            'future_generations' => (string)($_POST['future_generations'] ?? '')
        ];
        $reasoning = trim((string)($_POST['reasoning'] ?? ''));
        $evidence = trim((string)($_POST['evidence'] ?? ''));
        $dimension_values = ['maintained','improved','weakened','unclear'];
        if ($amendment_id <= 0 || !in_array($verdict, ['supports','conflict','unclear'], true) ||
            mb_strlen($reasoning) < 20 || mb_strlen($reasoning) > 20000 ||
            mb_strlen($evidence) > 20000) {
            throw new RuntimeException('ارزیابی یا دلایل آن معتبر نیست.');
        }
        foreach ($dimensions as $value) {
            if (!in_array($value, $dimension_values, true)) throw new RuntimeException('همه محورهای منشور باید ارزیابی شوند.');
        }
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $lock = $conn->prepare(
            "SELECT id,proposed_charter_version_id,status,review_closes_at
             FROM community_charter_amendments WHERE id=? FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('قفل پرونده ممکن نیست.');
        $lock->bind_param('i', $amendment_id);
        if (!$lock->execute()) throw new RuntimeException('خواندن پرونده انجام نشد.');
        $amendment = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$amendment || $amendment['status'] !== 'independent_review' ||
            empty($amendment['review_closes_at']) ||
            strtotime($amendment['review_closes_at']) < time()) {
            throw new RuntimeException('مهلت بررسی پایان یافته یا پرونده در مرحله بررسی نیست.');
        }
        $assigned = $conn->prepare(
            "SELECT 1 FROM community_charter_amendment_reviewers
             WHERE amendment_id=? AND reviewer_user_id=? LIMIT 1"
        );
        if (!$assigned) throw new RuntimeException('بررسی صلاحیت ارزیاب ممکن نیست.');
        $assigned->bind_param('ii', $amendment_id, $user_id);
        if (!$assigned->execute() || !$assigned->get_result()->fetch_row()) {
            throw new RuntimeException('شما ارزیاب مستقل این پیشنهاد نیستید.');
        }
        $assigned->close();

        $save = $conn->prepare(
            "INSERT INTO community_charter_amendment_assessments
                (amendment_id,reviewer_user_id,verdict,rights_protection,
                 equality_and_non_discrimination,living_beings_and_environment,
                 power_and_accountability,future_generations,reasoning,evidence)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        if (!$save) throw new RuntimeException('ثبت ارزیابی ممکن نیست.');
        $save->bind_param(
            'iissssssss', $amendment_id, $user_id, $verdict,
            $dimensions['rights_protection'],
            $dimensions['equality_and_non_discrimination'],
            $dimensions['living_beings_and_environment'],
            $dimensions['power_and_accountability'],
            $dimensions['future_generations'], $reasoning, $evidence
        );
        if (!$save->execute()) throw new RuntimeException('ثبت ارزیابی انجام نشد؛ هر ارزیاب فقط یک ارزیابی نهایی دارد.');
        $save->close();

        $assessments = cam_assessments($conn, $amendment_id);
        if ($assessments === null) throw new RuntimeException('محاسبه نتیجه بررسی ممکن نیست.');
        $decision = community_charter_amendment_reviewer_result($assessments);
        if ($decision === 'approved') {
            $advance = $conn->prepare(
                "UPDATE community_charter_amendments
                 SET status='public_voting',voting_opens_at=NOW(),
                     voting_closes_at=DATE_ADD(NOW(),INTERVAL 14 DAY)
                 WHERE id=? AND status='independent_review'"
            );
            if (!$advance) throw new RuntimeException('آغاز رأی‌گیری عمومی ممکن نیست.');
            $advance->bind_param('i', $amendment_id);
            if (!$advance->execute() || $advance->affected_rows !== 1) throw new RuntimeException('آغاز رأی‌گیری عمومی انجام نشد.');
            $advance->close();
        } elseif ($decision === 'rejected') {
            cam_reject_amendment($conn, $amendment_id, (int)$amendment['proposed_charter_version_id']);
        }
        if (!$conn->commit()) throw new RuntimeException('ثبت ارزیابی نهایی نشد.');
        cam_redirect($decision === 'approved' ? 'public_voting_started' : ($decision === 'rejected' ? 'amendment_rejected' : 'assessment_saved'));
    }

    if ($action === 'finalize_review') {
        $amendment_id = (int)($_POST['amendment_id'] ?? 0);
        if ($amendment_id <= 0) throw new RuntimeException('شناسه پیشنهاد معتبر نیست.');
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $lock = $conn->prepare(
            "SELECT proposed_charter_version_id,status,review_closes_at
             FROM community_charter_amendments WHERE id=? FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('قفل پرونده ممکن نیست.');
        $lock->bind_param('i', $amendment_id);
        if (!$lock->execute()) throw new RuntimeException('خواندن پرونده انجام نشد.');
        $amendment = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$amendment || $amendment['status'] !== 'independent_review' ||
            empty($amendment['review_closes_at']) || strtotime($amendment['review_closes_at']) > time()) {
            throw new RuntimeException('جمع‌بندی فقط پس از پایان مهلت بررسی مستقل ممکن است.');
        }
        $assessments = cam_assessments($conn, $amendment_id);
        if ($assessments === null) throw new RuntimeException('خواندن ارزیابی‌ها ممکن نیست.');
        $decision = community_charter_amendment_reviewer_result($assessments);
        if ($decision === 'approved') {
            $advance = $conn->prepare(
                "UPDATE community_charter_amendments
                 SET status='public_voting',voting_opens_at=NOW(),
                     voting_closes_at=DATE_ADD(NOW(),INTERVAL 14 DAY)
                 WHERE id=? AND status='independent_review'"
            );
            if (!$advance) throw new RuntimeException('آغاز رأی‌گیری عمومی ممکن نیست.');
            $advance->bind_param('i', $amendment_id);
            if (!$advance->execute() || $advance->affected_rows !== 1) throw new RuntimeException('آغاز رأی‌گیری عمومی انجام نشد.');
            $advance->close();
        } else {
            cam_reject_amendment($conn, $amendment_id, (int)$amendment['proposed_charter_version_id']);
            $decision = 'rejected';
        }
        if (!$conn->commit()) throw new RuntimeException('جمع‌بندی بررسی نهایی نشد.');
        cam_redirect($decision === 'approved' ? 'public_voting_started' : 'amendment_rejected');
    }

    if ($action === 'vote') {
        $amendment_id = (int)($_POST['amendment_id'] ?? 0);
        $vote = (string)($_POST['vote'] ?? '');
        if ($amendment_id <= 0 || !in_array($vote, ['yes','no','abstain'], true)) {
            throw new RuntimeException('رأی ارسالی معتبر نیست.');
        }
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $lock = $conn->prepare(
            "SELECT status,voting_opens_at,voting_closes_at
             FROM community_charter_amendments WHERE id=? FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('قفل رأی‌گیری ممکن نیست.');
        $lock->bind_param('i', $amendment_id);
        if (!$lock->execute()) throw new RuntimeException('خواندن رأی‌گیری انجام نشد.');
        $amendment = $lock->get_result()->fetch_assoc();
        $lock->close();
        $now = time();
        if (!$amendment || $amendment['status'] !== 'public_voting' ||
            empty($amendment['voting_opens_at']) || empty($amendment['voting_closes_at']) ||
            strtotime($amendment['voting_opens_at']) > $now || strtotime($amendment['voting_closes_at']) < $now) {
            throw new RuntimeException('رأی‌گیری باز نیست یا مهلت آن پایان یافته است.');
        }
        $eligible = $conn->prepare(
            "SELECT 1 FROM community_charter_amendment_electorate
             WHERE amendment_id=? AND user_id=? LIMIT 1"
        );
        if (!$eligible) throw new RuntimeException('بررسی صلاحیت رأی‌دهنده ممکن نیست.');
        $eligible->bind_param('ii', $amendment_id, $user_id);
        if (!$eligible->execute() || !$eligible->get_result()->fetch_row()) {
            throw new RuntimeException('شما در فهرست رأی‌دهندگان این اصلاحیه نیستید.');
        }
        $eligible->close();
        $save = $conn->prepare(
            "INSERT INTO community_charter_amendment_votes (amendment_id,user_id,vote)
             VALUES (?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote),updated_at=CURRENT_TIMESTAMP"
        );
        if (!$save) throw new RuntimeException('ثبت رأی ممکن نیست.');
        $save->bind_param('iis', $amendment_id, $user_id, $vote);
        if (!$save->execute()) throw new RuntimeException('ثبت رأی انجام نشد.');
        $save->close();
        if (!$conn->commit()) throw new RuntimeException('ثبت رأی نهایی نشد.');
        cam_redirect('vote_saved');
    }

    if ($action === 'finalize_vote') {
        $amendment_id = (int)($_POST['amendment_id'] ?? 0);
        if ($amendment_id <= 0) throw new RuntimeException('شناسه پیشنهاد معتبر نیست.');
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $lock = $conn->prepare(
            "SELECT proposed_charter_version_id,status,voting_closes_at
             FROM community_charter_amendments WHERE id=? FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('قفل رأی‌گیری ممکن نیست.');
        $lock->bind_param('i', $amendment_id);
        if (!$lock->execute()) throw new RuntimeException('خواندن رأی‌گیری انجام نشد.');
        $amendment = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$amendment || $amendment['status'] !== 'public_voting' ||
            empty($amendment['voting_closes_at']) || strtotime($amendment['voting_closes_at']) > time()) {
            throw new RuntimeException('جمع‌بندی فقط پس از پایان مهلت رأی‌گیری ممکن است.');
        }
        $count = $conn->prepare(
            "SELECT
                (SELECT COUNT(*) FROM community_charter_amendment_electorate WHERE amendment_id=?) AS electorate,
                SUM(vote='yes') AS yes_count,
                SUM(vote='no') AS no_count,
                SUM(vote='abstain') AS abstain_count
             FROM community_charter_amendment_votes WHERE amendment_id=?"
        );
        if (!$count) throw new RuntimeException('محاسبه آرا ممکن نیست.');
        $count->bind_param('ii', $amendment_id, $amendment_id);
        if (!$count->execute()) throw new RuntimeException('محاسبه آرا انجام نشد.');
        $counts = $count->get_result()->fetch_assoc();
        $count->close();
        $result = community_charter_amendment_vote_result(
            (int)$counts['electorate'], (int)$counts['yes_count'],
            (int)$counts['no_count'], (int)$counts['abstain_count']
        );
        if ($result === 'approved') {
            $update = $conn->prepare(
                "UPDATE community_charter_amendments
                 SET status='appeal_period',approved_at=NOW(),
                     appeal_closes_at=DATE_ADD(NOW(),INTERVAL 7 DAY)
                 WHERE id=? AND status='public_voting'"
            );
            if (!$update) throw new RuntimeException('ثبت نتیجه رأی‌گیری ممکن نیست.');
            $update->bind_param('i', $amendment_id);
            if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('ثبت نتیجه رأی‌گیری انجام نشد.');
            $update->close();
        } else {
            cam_reject_amendment($conn, $amendment_id, (int)$amendment['proposed_charter_version_id']);
        }
        if (!$conn->commit()) throw new RuntimeException('جمع‌بندی رأی‌گیری نهایی نشد.');
        cam_redirect($result === 'approved' ? 'appeal_period' : 'amendment_rejected');
    }

    if ($action === 'appeal') {
        $amendment_id = (int)($_POST['amendment_id'] ?? 0);
        $grounds = trim((string)($_POST['grounds'] ?? ''));
        $evidence = trim((string)($_POST['evidence'] ?? ''));
        if ($amendment_id <= 0 || mb_strlen($grounds) < 30 ||
            mb_strlen($grounds) > 20000 || mb_strlen($evidence) > 20000) {
            throw new RuntimeException('دلایل اعتراض باید روشن و مستند باشد.');
        }
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $lock = $conn->prepare(
            "SELECT proposer_user_id,status,appeal_closes_at
             FROM community_charter_amendments WHERE id=? FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('قفل پرونده ممکن نیست.');
        $lock->bind_param('i', $amendment_id);
        if (!$lock->execute()) throw new RuntimeException('خواندن پرونده انجام نشد.');
        $amendment = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$amendment || $amendment['status'] !== 'appeal_period' ||
            empty($amendment['appeal_closes_at']) || strtotime($amendment['appeal_closes_at']) < time() ||
            (int)$amendment['proposer_user_id'] === $user_id) {
            throw new RuntimeException('مهلت اعتراض باز نیست یا شرایط ثبت اعتراض را ندارید.');
        }
        $eligible = $conn->prepare(
            "SELECT 1 FROM community_charter_amendment_electorate
             WHERE amendment_id=? AND user_id=? LIMIT 1"
        );
        if (!$eligible) throw new RuntimeException('بررسی حق اعتراض ممکن نیست.');
        $eligible->bind_param('ii', $amendment_id, $user_id);
        if (!$eligible->execute() || !$eligible->get_result()->fetch_row()) {
            throw new RuntimeException('ثبت اعتراض برای اعضای فهرست رأی‌دهندگان این دور امکان‌پذیر است.');
        }
        $eligible->close();

        $duplicate = $conn->prepare(
            "SELECT id FROM community_charter_amendment_appeals
             WHERE amendment_id=? AND appellant_user_id=?
               AND status IN ('submitted','under_review','upheld','dismissed') LIMIT 1"
        );
        if (!$duplicate) throw new RuntimeException('بررسی اعتراض قبلی ممکن نیست.');
        $duplicate->bind_param('ii', $amendment_id, $user_id);
        if (!$duplicate->execute()) throw new RuntimeException('بررسی اعتراض قبلی انجام نشد.');
        $has_appeal = (bool)$duplicate->get_result()->fetch_row();
        $duplicate->close();
        if ($has_appeal) throw new RuntimeException('برای این اصلاحیه قبلاً اعتراض ثبت کرده‌اید.');

        $insert = $conn->prepare(
            "INSERT INTO community_charter_amendment_appeals
                (amendment_id,appellant_user_id,grounds,evidence,status)
             VALUES (?,?,?,?,'submitted')"
        );
        if (!$insert) throw new RuntimeException('ثبت اعتراض ممکن نیست.');
        $insert->bind_param('iiss', $amendment_id, $user_id, $grounds, $evidence);
        if (!$insert->execute()) throw new RuntimeException('ثبت اعتراض انجام نشد.');
        $appeal_id = (int)$insert->insert_id;
        $insert->close();

        $excluded = [$user_id => true, (int)$amendment['proposer_user_id'] => true];
        $original_reviewers = $conn->prepare(
            "SELECT reviewer_user_id FROM community_charter_amendment_reviewers WHERE amendment_id=?"
        );
        if (!$original_reviewers) throw new RuntimeException('خواندن ارزیابان اولیه ممکن نیست.');
        $original_reviewers->bind_param('i', $amendment_id);
        if (!$original_reviewers->execute()) throw new RuntimeException('خواندن ارزیابان اولیه انجام نشد.');
        $rr = $original_reviewers->get_result();
        while ($row = $rr->fetch_assoc()) $excluded[(int)$row['reviewer_user_id']] = true;
        $original_reviewers->close();

        $voters = $conn->prepare(
            "SELECT user_id FROM community_charter_amendment_votes WHERE amendment_id=?"
        );
        if (!$voters) throw new RuntimeException('خواندن رأی‌دهندگان قبلی ممکن نیست.');
        $voters->bind_param('i', $amendment_id);
        if (!$voters->execute()) throw new RuntimeException('خواندن رأی‌دهندگان قبلی انجام نشد.');
        $vr = $voters->get_result();
        while ($row = $vr->fetch_assoc()) $excluded[(int)$row['user_id']] = true;
        $voters->close();

        $all_users = $conn->query('SELECT id FROM users ORDER BY id');
        if (!$all_users) throw new RuntimeException('خواندن کاربران برای رسیدگی به اعتراض ممکن نیست.');
        $appeal_candidates = [];
        while ($row = $all_users->fetch_assoc()) {
            $candidate = (int)$row['id'];
            if (!isset($excluded[$candidate])) $appeal_candidates[] = $candidate;
        }
        $all_users->free();
        if (count($appeal_candidates) < 3) throw new RuntimeException('برای رسیدگی مستقل به اعتراض سه ارزیاب بی‌طرف در دسترس نیست.');
        shuffle($appeal_candidates);
        $assignment = $conn->prepare(
            "INSERT INTO community_charter_amendment_appeal_reviewer_assignments
                (appeal_id,reviewer_user_id) VALUES (?,?)"
        );
        if (!$assignment) throw new RuntimeException('تخصیص ارزیابان اعتراض ممکن نیست.');
        foreach (array_slice($appeal_candidates, 0, 3) as $reviewer_id) {
            $assignment->bind_param('ii', $appeal_id, $reviewer_id);
            if (!$assignment->execute()) throw new RuntimeException('تخصیص ارزیاب اعتراض انجام نشد.');
        }
        $assignment->close();

        $under_review = $conn->prepare(
            "UPDATE community_charter_amendment_appeals SET status='under_review' WHERE id=?"
        );
        if (!$under_review) throw new RuntimeException('آغاز رسیدگی به اعتراض ممکن نیست.');
        $under_review->bind_param('i', $appeal_id);
        if (!$under_review->execute()) throw new RuntimeException('آغاز رسیدگی به اعتراض انجام نشد.');
        $under_review->close();
        if (!$conn->commit()) throw new RuntimeException('ثبت اعتراض نهایی نشد.');
        cam_redirect('appeal_submitted');
    }

    if ($action === 'assess_appeal') {
        $appeal_id = (int)($_POST['appeal_id'] ?? 0);
        $verdict = (string)($_POST['verdict'] ?? '');
        $reasoning = trim((string)($_POST['reasoning'] ?? ''));
        if ($appeal_id <= 0 || !in_array($verdict, ['uphold','dismiss','unclear'], true) ||
            mb_strlen($reasoning) < 20 || mb_strlen($reasoning) > 20000) {
            throw new RuntimeException('ارزیابی اعتراض معتبر نیست.');
        }
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $appeal_lock = $conn->prepare(
            "SELECT amendment_id,status FROM community_charter_amendment_appeals
             WHERE id=? FOR UPDATE"
        );
        if (!$appeal_lock) throw new RuntimeException('قفل اعتراض ممکن نیست.');
        $appeal_lock->bind_param('i', $appeal_id);
        if (!$appeal_lock->execute()) throw new RuntimeException('خواندن اعتراض انجام نشد.');
        $appeal = $appeal_lock->get_result()->fetch_assoc();
        $appeal_lock->close();
        if (!$appeal || !in_array($appeal['status'], ['submitted','under_review'], true)) {
            throw new RuntimeException('این اعتراض در وضعیت قابل رسیدگی نیست.');
        }
        $assigned = $conn->prepare(
            "SELECT 1 FROM community_charter_amendment_appeal_reviewer_assignments
             WHERE appeal_id=? AND reviewer_user_id=? LIMIT 1"
        );
        if (!$assigned) throw new RuntimeException('بررسی صلاحیت ارزیاب اعتراض ممکن نیست.');
        $assigned->bind_param('ii', $appeal_id, $user_id);
        if (!$assigned->execute() || !$assigned->get_result()->fetch_row()) {
            throw new RuntimeException('شما ارزیاب مستقل این اعتراض نیستید.');
        }
        $assigned->close();

        $save = $conn->prepare(
            "INSERT INTO community_charter_amendment_appeal_reviewers
                (appeal_id,reviewer_user_id,verdict,reasoning)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE verdict=VALUES(verdict),reasoning=VALUES(reasoning),submitted_at=CURRENT_TIMESTAMP"
        );
        if (!$save) throw new RuntimeException('ثبت ارزیابی اعتراض ممکن نیست.');
        $save->bind_param('iiss', $appeal_id, $user_id, $verdict, $reasoning);
        if (!$save->execute()) throw new RuntimeException('ثبت ارزیابی اعتراض انجام نشد.');
        $save->close();

        $tally = $conn->prepare(
            "SELECT COUNT(*) AS total,SUM(verdict='uphold') AS uphold_count,
                    SUM(verdict='dismiss') AS dismiss_count
             FROM community_charter_amendment_appeal_reviewers WHERE appeal_id=?"
        );
        if (!$tally) throw new RuntimeException('جمع‌بندی ارزیابی اعتراض ممکن نیست.');
        $tally->bind_param('i', $appeal_id);
        if (!$tally->execute()) throw new RuntimeException('جمع‌بندی ارزیابی اعتراض انجام نشد.');
        $counts = $tally->get_result()->fetch_assoc();
        $tally->close();
        $appeal_status = 'under_review';
        if ((int)$counts['total'] >= 3 && (int)$counts['uphold_count'] >= 2) $appeal_status = 'upheld';
        elseif ((int)$counts['total'] >= 3 && (int)$counts['dismiss_count'] >= 2) $appeal_status = 'dismissed';

        if ($appeal_status !== 'under_review') {
            $update_appeal = $conn->prepare(
                "UPDATE community_charter_amendment_appeals SET status=?,resolved_at=NOW() WHERE id=?"
            );
            if (!$update_appeal) throw new RuntimeException('ثبت نتیجه اعتراض ممکن نیست.');
            $update_appeal->bind_param('si', $appeal_status, $appeal_id);
            if (!$update_appeal->execute()) throw new RuntimeException('ثبت نتیجه اعتراض انجام نشد.');
            $update_appeal->close();
            if ($appeal_status === 'upheld') {
                $amendment_lock = $conn->prepare(
                    "SELECT proposed_charter_version_id FROM community_charter_amendments WHERE id=? FOR UPDATE"
                );
                if (!$amendment_lock) throw new RuntimeException('قفل اصلاحیه ممکن نیست.');
                $amendment_id = (int)$appeal['amendment_id'];
                $amendment_lock->bind_param('i', $amendment_id);
                if (!$amendment_lock->execute()) throw new RuntimeException('خواندن اصلاحیه انجام نشد.');
                $amendment = $amendment_lock->get_result()->fetch_assoc();
                $amendment_lock->close();
                if (!$amendment) throw new RuntimeException('اصلاحیه پیدا نشد.');
                cam_reject_amendment($conn, $amendment_id, (int)$amendment['proposed_charter_version_id']);
            }
        }
        if (!$conn->commit()) throw new RuntimeException('ثبت نتیجه رسیدگی نهایی نشد.');
        cam_redirect($appeal_status === 'under_review' ? 'appeal_assessment_saved' : 'appeal_' . $appeal_status);
    }

    if ($action === 'activate') {
        $amendment_id = (int)($_POST['amendment_id'] ?? 0);
        if ($amendment_id <= 0) throw new RuntimeException('شناسه پیشنهاد معتبر نیست.');
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');

        $active = $conn->query(
            "SELECT id FROM community_charter_versions
             WHERE status='effective' AND effective_at<=NOW() FOR UPDATE"
        );
        if (!$active) throw new RuntimeException('قفل منشور جاری ممکن نیست.');
        $active_ids = [];
        while ($row = $active->fetch_assoc()) $active_ids[] = (int)$row['id'];
        $active->free();
        if (count($active_ids) !== 1) throw new RuntimeException('نسخه جاری منشور یکتا نیست.');

        $lock = $conn->prepare(
            "SELECT base_charter_version_id,proposed_charter_version_id,status,appeal_closes_at
             FROM community_charter_amendments WHERE id=? FOR UPDATE"
        );
        if (!$lock) throw new RuntimeException('قفل اصلاحیه ممکن نیست.');
        $lock->bind_param('i', $amendment_id);
        if (!$lock->execute()) throw new RuntimeException('خواندن اصلاحیه انجام نشد.');
        $amendment = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$amendment || $amendment['status'] !== 'appeal_period' ||
            (int)$amendment['base_charter_version_id'] !== $active_ids[0] ||
            empty($amendment['appeal_closes_at']) || strtotime($amendment['appeal_closes_at']) > time()) {
            throw new RuntimeException('فعال‌سازی فقط پس از پایان مهلت اعتراض و در صورت جاری بودن منشور پایه ممکن است.');
        }

        $open_appeals = $conn->prepare(
            "SELECT COUNT(*) AS total FROM community_charter_amendment_appeals
             WHERE amendment_id=? AND status IN ('submitted','under_review')"
        );
        if (!$open_appeals) throw new RuntimeException('بررسی اعتراض‌های باز ممکن نیست.');
        $open_appeals->bind_param('i', $amendment_id);
        if (!$open_appeals->execute()) throw new RuntimeException('بررسی اعتراض‌های باز انجام نشد.');
        $open_count = (int)$open_appeals->get_result()->fetch_assoc()['total'];
        $open_appeals->close();
        if ($open_count > 0) throw new RuntimeException('تا رسیدگی نهایی به همه اعتراض‌های ثبت‌شده، منشور فعال نمی‌شود.');

        $pending = $conn->prepare(
            "UPDATE community_charter_amendments SET status='approved_pending_activation'
             WHERE id=? AND status='appeal_period'"
        );
        if (!$pending) throw new RuntimeException('آماده‌سازی فعال‌سازی ممکن نیست.');
        $pending->bind_param('i', $amendment_id);
        if (!$pending->execute() || $pending->affected_rows !== 1) throw new RuntimeException('آماده‌سازی فعال‌سازی انجام نشد.');
        $pending->close();

        $supersede = $conn->prepare(
            "UPDATE community_charter_versions SET status='superseded'
             WHERE id=? AND status='effective'"
        );
        if (!$supersede) throw new RuntimeException('بستن نسخه قبلی منشور ممکن نیست.');
        $supersede->bind_param('i', $active_ids[0]);
        if (!$supersede->execute() || $supersede->affected_rows !== 1) {
            throw new RuntimeException('بستن نسخه قبلی منشور انجام نشد.');
        }
        $supersede->close();

        $new_version_id = (int)$amendment['proposed_charter_version_id'];
        $activate_version = $conn->prepare(
            "UPDATE community_charter_versions
             SET status='effective',approved_at=NOW(),effective_at=NOW()
             WHERE id=? AND status='draft'"
        );
        if (!$activate_version) throw new RuntimeException('فعال‌سازی نسخه تازه ممکن نیست.');
        $activate_version->bind_param('i', $new_version_id);
        if (!$activate_version->execute() || $activate_version->affected_rows !== 1) {
            throw new RuntimeException('فعال‌سازی نسخه تازه انجام نشد.');
        }
        $activate_version->close();

        $activated = $conn->prepare(
            "UPDATE community_charter_amendments SET status='activated',activated_at=NOW()
             WHERE id=? AND status='approved_pending_activation'"
        );
        if (!$activated) throw new RuntimeException('ثبت وضعیت نهایی اصلاحیه ممکن نیست.');
        $activated->bind_param('i', $amendment_id);
        if (!$activated->execute() || $activated->affected_rows !== 1) {
            throw new RuntimeException('ثبت وضعیت نهایی اصلاحیه انجام نشد.');
        }
        $activated->close();
        if (!$conn->commit()) throw new RuntimeException('فعال‌سازی منشور نهایی نشد.');
        cam_redirect('amendment_activated');
    }

    throw new RuntimeException('عملیات ناشناخته است.');
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('[Dolate Eshgh charter amendment] ' . $e->getMessage());
    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
