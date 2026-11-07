<?php
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('ابتدا وارد شوید.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('درخواست نامعتبر است.'); }
$user_id = (int)$_SESSION['user_id'];
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['community_duty_csrf']) || $csrf === '' || !hash_equals($_SESSION['community_duty_csrf'], $csrf)) {
    http_response_code(403); exit('نشست معتبر نیست؛ صفحه را تازه‌سازی کنید.');
}
$action = (string)($_POST['action'] ?? '');
$community_id = (int)($_POST['community_id'] ?? 0);

function tep_member(mysqli $conn, int $community_id, int $user_id): bool {
    $stmt = $conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ii', $community_id, $user_id);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}
function tep_redirect(int $community_id, int $topic_id): void {
    header('Location: topic_expertise_profile.php?community_id=' . $community_id . '&topic_id=' . $topic_id);
    exit;
}

try {
    if ($community_id <= 0 || !tep_member($conn, $community_id, $user_id)) {
        throw new RuntimeException('برای این کار باید عضو جامعهٔ مربوط باشید.');
    }

    if ($action === 'submit') {
        $topic_id = (int)($_POST['topic_id'] ?? 0);
        $domain_ids = $_POST['domain_id'] ?? [];
        $shares = $_POST['share_percent'] ?? [];
        if ($topic_id <= 0 || !is_array($domain_ids) || !is_array($shares) || count($domain_ids) < 1 || count($domain_ids) > 12 || count($domain_ids) !== count($shares)) {
            throw new RuntimeException('موضوع و دست‌کم یک حوزهٔ تخصصی معتبر انتخاب کنید.');
        }

        $profile_domains = [];
        $sum = 0.0;
        foreach ($domain_ids as $index => $raw_domain_id) {
            $domain_id = filter_var($raw_domain_id, FILTER_VALIDATE_INT);
            $raw_share = trim((string)($shares[$index] ?? ''));
            if ($domain_id === false || $domain_id === null || $domain_id <= 0 || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $raw_share)) {
                throw new RuntimeException('شناسهٔ حوزه یا درصد سهم معتبر نیست.');
            }
            $share = (float)$raw_share;
            if ($share <= 0 || $share > 100 || isset($profile_domains[$domain_id])) {
                throw new RuntimeException('سهم هر حوزه باید مثبت باشد و هر حوزه فقط یک‌بار انتخاب شود.');
            }
            $profile_domains[$domain_id] = $share;
            $sum += $share;
        }
        if (abs($sum - 100.0) > 0.001) {
            throw new RuntimeException('مجموع سهم حوزه‌ها باید دقیقاً ۱۰۰ درصد باشد.');
        }

        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $topic = $conn->prepare('SELECT id FROM topics WHERE id=? AND community_id=? FOR UPDATE');
        if (!$topic) throw new RuntimeException('بررسی موضوع ممکن نیست.');
        $topic->bind_param('ii', $topic_id, $community_id);
        if (!$topic->execute() || !$topic->get_result()->fetch_row()) {
            $topic->close();
            throw new RuntimeException('موضوع انتخاب‌شده متعلق به این جامعه نیست.');
        }
        $topic->close();

        $pending = $conn->prepare("SELECT id FROM topic_expertise_profiles WHERE topic_id=? AND status='pending' LIMIT 1 FOR UPDATE");
        if (!$pending) throw new RuntimeException('بررسی پیشنهادهای قبلی ممکن نیست.');
        $pending->bind_param('i', $topic_id);
        if (!$pending->execute()) throw new RuntimeException('بررسی پیشنهادهای قبلی انجام نشد.');
        $has_pending = (bool)$pending->get_result()->fetch_row();
        $pending->close();
        if ($has_pending) throw new RuntimeException('برای این موضوع یک پروفایل تخصصی در حال رأی‌گیری است.');

        $ids = array_keys($profile_domains);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $domain_sql = "SELECT COUNT(*) AS total FROM expertise_domains WHERE status='approved' AND id IN ($placeholders)";
        $domain_check = $conn->prepare($domain_sql);
        if (!$domain_check) throw new RuntimeException('بررسی حوزه‌های تخصصی ممکن نیست.');
        $types = str_repeat('i', count($ids));
        $bind = [$types];
        foreach ($ids as $i => $id) $bind[] = $id;
        $refs = [];
        foreach ($bind as $i => &$value) $refs[$i] = &$value;
        call_user_func_array([$domain_check, 'bind_param'], $refs);
        if (!$domain_check->execute()) throw new RuntimeException('بررسی حوزه‌ها انجام نشد.');
        $valid_domain_count = (int)$domain_check->get_result()->fetch_assoc()['total'];
        $domain_check->close();
        if ($valid_domain_count !== count($ids)) throw new RuntimeException('همهٔ حوزه‌های انتخاب‌شده باید قبلاً تأیید شده باشند.');

        $version_stmt = $conn->prepare('SELECT COALESCE(MAX(version_number),0)+1 AS next_version FROM topic_expertise_profiles WHERE topic_id=?');
        if (!$version_stmt) throw new RuntimeException('تعیین شمارهٔ نسخه ممکن نیست.');
        $version_stmt->bind_param('i', $topic_id);
        if (!$version_stmt->execute()) throw new RuntimeException('تعیین نسخه انجام نشد.');
        $version_number = (int)$version_stmt->get_result()->fetch_assoc()['next_version'];
        $version_stmt->close();

        $profile = $conn->prepare("INSERT INTO topic_expertise_profiles (topic_id,community_id,version_number,status,proposed_by,expires_at) VALUES (?,?,?,'pending',?,DATE_ADD(NOW(), INTERVAL 7 DAY))");
        if (!$profile) throw new RuntimeException('ثبت پروفایل ممکن نیست.');
        $profile->bind_param('iiii', $topic_id, $community_id, $version_number, $user_id);
        if (!$profile->execute()) throw new RuntimeException('ثبت پروفایل انجام نشد.');
        $profile_id = (int)$profile->insert_id;
        $profile->close();

        $domain_insert = $conn->prepare('INSERT INTO topic_expertise_profile_domains (profile_id,domain_id,share_percent) VALUES (?,?,?)');
        if (!$domain_insert) throw new RuntimeException('ثبت سهم حوزه‌ها ممکن نیست.');
        foreach ($profile_domains as $domain_id => $share) {
            $domain_insert->bind_param('iid', $profile_id, $domain_id, $share);
            if (!$domain_insert->execute()) throw new RuntimeException('ثبت سهم یکی از حوزه‌ها انجام نشد.');
        }
        $domain_insert->close();
        if (!$conn->commit()) throw new RuntimeException('ثبت پروفایل نهایی نشد.');
        tep_redirect($community_id, $topic_id);
    }

    if ($action === 'vote') {
        $profile_id = (int)($_POST['profile_id'] ?? 0);
        $topic_id = (int)($_POST['topic_id'] ?? 0);
        $vote = (string)($_POST['vote'] ?? '');
        if ($profile_id <= 0 || $topic_id <= 0 || !in_array($vote, ['yes','no'], true)) {
            throw new RuntimeException('اطلاعات رأی معتبر نیست.');
        }
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $lock = $conn->prepare('SELECT id,status,expires_at FROM topic_expertise_profiles WHERE id=? AND topic_id=? AND community_id=? FOR UPDATE');
        if (!$lock) throw new RuntimeException('بررسی پروفایل ممکن نیست.');
        $lock->bind_param('iii', $profile_id, $topic_id, $community_id);
        if (!$lock->execute()) throw new RuntimeException('بررسی پروفایل انجام نشد.');
        $profile = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$profile || $profile['status'] !== 'pending' || strtotime($profile['expires_at']) <= time()) {
            throw new RuntimeException('مهلت رأی‌گیری پایان یافته یا پروفایل بسته است.');
        }
        $save = $conn->prepare('INSERT INTO topic_expertise_profile_votes (profile_id,user_id,vote) VALUES (?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote),updated_at=CURRENT_TIMESTAMP');
        if (!$save) throw new RuntimeException('ثبت رأی ممکن نیست.');
        $save->bind_param('iis', $profile_id, $user_id, $vote);
        if (!$save->execute()) throw new RuntimeException('ثبت رأی انجام نشد.');
        $save->close();
        if (!$conn->commit()) throw new RuntimeException('ثبت رأی نهایی نشد.');
        tep_redirect($community_id, $topic_id);
    }

    if ($action === 'finalize') {
        $profile_id = (int)($_POST['profile_id'] ?? 0);
        $topic_id = (int)($_POST['topic_id'] ?? 0);
        if ($profile_id <= 0 || $topic_id <= 0) throw new RuntimeException('پروفایل معتبر نیست.');
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش انجام نشد.');
        $lock = $conn->prepare('SELECT id,status,expires_at FROM topic_expertise_profiles WHERE id=? AND topic_id=? AND community_id=? FOR UPDATE');
        if (!$lock) throw new RuntimeException('بررسی پروفایل ممکن نیست.');
        $lock->bind_param('iii', $profile_id, $topic_id, $community_id);
        if (!$lock->execute()) throw new RuntimeException('بررسی پروفایل انجام نشد.');
        $profile = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$profile || $profile['status'] !== 'pending' || strtotime($profile['expires_at']) > time()) {
            throw new RuntimeException('جمع‌بندی فقط پس از پایان مهلت هفت‌روزه ممکن است.');
        }
        $tally = $conn->prepare("SELECT COUNT(*) AS total,SUM(vote='yes') AS yes_count FROM topic_expertise_profile_votes WHERE profile_id=?");
        if (!$tally) throw new RuntimeException('محاسبهٔ آرا ممکن نیست.');
        $tally->bind_param('i', $profile_id);
        if (!$tally->execute()) throw new RuntimeException('محاسبهٔ آرا انجام نشد.');
        $counts = $tally->get_result()->fetch_assoc();
        $tally->close();
        $total = (int)$counts['total'];
        $yes = (int)$counts['yes_count'];
        $approved = $total >= 3 && ($yes * 3 >= $total * 2);
        $new_status = $approved ? 'approved' : 'rejected';
        if ($approved) {
            $supersede = $conn->prepare("UPDATE topic_expertise_profiles SET status='superseded' WHERE topic_id=? AND status='approved'");
            if (!$supersede) throw new RuntimeException('بستن نسخهٔ قبلی ممکن نیست.');
            $supersede->bind_param('i', $topic_id);
            if (!$supersede->execute()) throw new RuntimeException('بستن نسخهٔ قبلی انجام نشد.');
            $supersede->close();
        }
        $update = $conn->prepare('UPDATE topic_expertise_profiles SET status=?,decided_at=NOW() WHERE id=? AND status=\'pending\'');
        if (!$update) throw new RuntimeException('ثبت نتیجه ممکن نیست.');
        $update->bind_param('si', $new_status, $profile_id);
        if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('ثبت نتیجه انجام نشد.');
        $update->close();
        if (!$conn->commit()) throw new RuntimeException('جمع‌بندی نهایی نشد.');
        tep_redirect($community_id, $topic_id);
    }

    throw new RuntimeException('عملیات ناشناخته است.');
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
