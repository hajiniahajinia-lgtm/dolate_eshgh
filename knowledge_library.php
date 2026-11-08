<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];
if (empty($_SESSION['knowledge_csrf'])) {
    $_SESSION['knowledge_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['knowledge_csrf'];

function kl_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function kl_type_label($type) {
    $labels = ['experience'=>'تجربه','solution'=>'راه‌حل','research'=>'پژوهش و دانش','lesson'=>'درس‌آموخته'];
    return $labels[$type] ?? 'دانش جمعی';
}
function kl_review_label($vote) {
    $labels = ['approve'=>'تأیید','reject'=>'مخالفت','revise'=>'نیازمند اصلاح'];
    return $labels[$vote] ?? 'ارزیابی';
}
function kl_status_label($status) {
    $labels = ['unreviewed'=>'بررسی‌نشده','under_review'=>'در حال بررسی','verified'=>'تأییدشده با شواهد','rejected'=>'ردشده با دلیل','needs_update'=>'نیازمند به‌روزرسانی'];
    return $labels[$status] ?? 'وضعیت نامشخص';
}
$GLOBALS['kl_db_read_error'] = false;
function kl_fetch_one($stmt) {
    if (!$stmt || !$stmt->execute()) {
        $GLOBALS['kl_db_read_error'] = true;
        return null;
    }
    $result = $stmt->get_result();
    if (!$result) {
        $GLOBALS['kl_db_read_error'] = true;
        return null;
    }
    return $result->fetch_assoc();
}
function kl_fetch_all($stmt) {
    if (!$stmt || !$stmt->execute()) {
        $GLOBALS['kl_db_read_error'] = true;
        return null;
    }
    $result = $stmt->get_result();
    if (!$result) {
        $GLOBALS['kl_db_read_error'] = true;
        return null;
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}
function kl_member($conn, $community_id, $user_id) {
    $stmt = $conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
    if (!$stmt) {
        $GLOBALS['kl_db_read_error'] = true;
        return false;
    }
    $stmt->bind_param('ii', $community_id, $user_id);
    if (!$stmt->execute()) {
        $GLOBALS['kl_db_read_error'] = true;
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    if (!$result) $GLOBALS['kl_db_read_error'] = true;
    $ok = $result ? (bool)$result->fetch_row() : false;
    $stmt->close();
    return $ok;
}

$message = '';
$error = '';
$allowed_types = ['experience','solution','research','lesson'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    if ($posted_csrf === '' || !hash_equals($csrf, $posted_csrf)) {
        http_response_code(403);
        exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.');
    }

    $action = (string)($_POST['action'] ?? '');

    // پیشنهاد عمومی‌کردن دانش؛ منشأ و جامعهٔ اولیه در پرونده حفظ می‌شوند.
    if ($action === 'propose_public') {
        $proposal_entry_id = (int)($_POST['entry_id'] ?? 0);
        $proposal_ok = false;
        if ($proposal_entry_id > 0 && $conn->begin_transaction()) {
            $lock_entry = $conn->prepare("SELECT id, visibility, community_id, source_topic_id
                FROM collective_knowledge_entries WHERE id=? LIMIT 1 FOR UPDATE");
            if ($lock_entry) {
                $lock_entry->bind_param('i', $proposal_entry_id);
                $proposal_entry = kl_fetch_one($lock_entry);
                $lock_entry->close();
                $source_community_id = 0;
                if ($proposal_entry && (int)$proposal_entry['source_topic_id'] > 0) {
                    $source_check = $conn->prepare("SELECT community_id FROM topics WHERE id=? LIMIT 1");
                    if ($source_check) {
                        $source_topic_id_for_proposal = (int)$proposal_entry['source_topic_id'];
                        $source_check->bind_param('i', $source_topic_id_for_proposal);
                        $source_community = kl_fetch_one($source_check);
                        $source_check->close();
                        $source_community_id = (int)($source_community['community_id'] ?? 0);
                    }
                }
                if ($proposal_entry && $proposal_entry['visibility'] === 'community' &&
                    (int)$proposal_entry['community_id'] > 0 &&
                    $source_community_id === (int)$proposal_entry['community_id'] &&
                    kl_member($conn, (int)$proposal_entry['community_id'], $user_id)) {
                    $pending_check = $conn->prepare("SELECT id FROM collective_knowledge_publication_proposals WHERE entry_id=? AND status='pending' LIMIT 1");
                    if ($pending_check) {
                        $pending_check->bind_param('i', $proposal_entry_id);
                        $pending = kl_fetch_one($pending_check);
                        $pending_check->close();
                        if ($pending) {
                            $proposal_ok = true;
                        } else {
                            $insert_proposal = $conn->prepare("INSERT INTO collective_knowledge_publication_proposals (entry_id, community_id, proposed_by, status) VALUES (?, ?, ?, 'pending')");
                            if ($insert_proposal) {
                                $community_value = (int)$proposal_entry['community_id'];
                                $insert_proposal->bind_param('iii', $proposal_entry_id, $community_value, $user_id);
                                $proposal_ok = $insert_proposal->execute();
                                $insert_proposal->close();
                            }
                        }
                    }
                }
            }
            if ($proposal_ok) {
                $proposal_ok = $conn->commit();
                if (!$proposal_ok) $conn->rollback();
            } else {
                $conn->rollback();
            }
        }
        header('Location: knowledge_library.php?id=' . $proposal_entry_id . ($proposal_ok ? '&publication_proposed=1' : '&publication_error=1'));
        exit;
    }

    // رأی اعضای جامعه دربارهٔ عمومی‌شدن پرونده.
    if ($action === 'vote_public') {
        $proposal_id = (int)($_POST['proposal_id'] ?? 0);
        $publication_vote = (string)($_POST['publication_vote'] ?? '');
        $publication_ok = false;
        if ($proposal_id > 0 && in_array($publication_vote, ['approve', 'reject'], true) && $conn->begin_transaction()) {
            // ترتیب قفل‌ها در همهٔ عملیات یکسان است: ابتدا پرونده، سپس پیشنهاد.
            $proposal_ref_stmt = $conn->prepare("SELECT entry_id FROM collective_knowledge_publication_proposals WHERE id=? LIMIT 1");
            $proposal_entry_id = 0;
            if ($proposal_ref_stmt) {
                $proposal_ref_stmt->bind_param('i', $proposal_id);
                $proposal_ref = kl_fetch_one($proposal_ref_stmt);
                $proposal_entry_id = (int)($proposal_ref['entry_id'] ?? 0);
                $proposal_ref_stmt->close();
            }
            $entry_lock = $proposal_entry_id > 0 ? $conn->prepare("SELECT visibility, community_id FROM collective_knowledge_entries WHERE id=? LIMIT 1 FOR UPDATE") : false;
            $locked_entry = null;
            if ($entry_lock) {
                $entry_lock->bind_param('i', $proposal_entry_id);
                $locked_entry = kl_fetch_one($entry_lock);
                $entry_lock->close();
            }
            $proposal_lock = $proposal_entry_id > 0 ? $conn->prepare("SELECT id, entry_id, community_id, status FROM collective_knowledge_publication_proposals WHERE id=? AND entry_id=? LIMIT 1 FOR UPDATE") : false;
            if ($proposal_lock && $locked_entry) {
                $proposal_lock->bind_param('ii', $proposal_id, $proposal_entry_id);
                $proposal_row = kl_fetch_one($proposal_lock);
                $proposal_lock->close();
                if ($proposal_row && $proposal_row['status'] === 'pending' &&
                    $locked_entry['visibility'] === 'community' &&
                    (int)$proposal_row['community_id'] === (int)$locked_entry['community_id'] &&
                    kl_member($conn, (int)$proposal_row['community_id'], $user_id)) {
                    $save_publication_vote = $conn->prepare("INSERT INTO collective_knowledge_publication_votes (proposal_id, user_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote=VALUES(vote), updated_at=CURRENT_TIMESTAMP");
                    if ($save_publication_vote) {
                        $save_publication_vote->bind_param('iis', $proposal_id, $user_id, $publication_vote);
                        $publication_ok = $save_publication_vote->execute();
                        $save_publication_vote->close();
                    }
                    if ($publication_ok) {
                        $count_publication_votes = $conn->prepare("SELECT COUNT(*) AS total, SUM(vote='approve') AS approvals, SUM(vote='reject') AS rejections FROM collective_knowledge_publication_votes WHERE proposal_id=?");
                        if ($count_publication_votes) {
                            $count_publication_votes->bind_param('i', $proposal_id);
                            $vote_totals = kl_fetch_one($count_publication_votes);
                            $count_publication_votes->close();
                            if ($vote_totals === null) {
                                $publication_ok = false;
                            }
                            $total_votes = (int)($vote_totals['total'] ?? 0);
                            $approval_count = (int)($vote_totals['approvals'] ?? 0);
                            $rejection_count = (int)($vote_totals['rejections'] ?? 0);
                            if ($publication_ok && $total_votes >= 3) {
                                $threshold = (int)ceil($total_votes * 2 / 3);
                                if ($approval_count >= $threshold) {
                                    $publish_entry = $conn->prepare("UPDATE collective_knowledge_entries SET visibility='public' WHERE id=? AND visibility='community' AND community_id=?");
                                    if ($publish_entry) {
                                        $entry_id_to_publish = (int)$proposal_row['entry_id'];
                                        $community_to_preserve = (int)$proposal_row['community_id'];
                                        $publish_entry->bind_param('ii', $entry_id_to_publish, $community_to_preserve);
                                        $publication_ok = $publish_entry->execute() && $publish_entry->affected_rows === 1;
                                        $publish_entry->close();
                                    } else {
                                        $publication_ok = false;
                                    }
                                    if ($publication_ok) {
                                        $finish_proposal = $conn->prepare("UPDATE collective_knowledge_publication_proposals SET status='approved', decided_at=NOW() WHERE id=? AND status='pending'");
                                        if ($finish_proposal) {
                                            $finish_proposal->bind_param('i', $proposal_id);
                                            $publication_ok = $finish_proposal->execute();
                                            $finish_proposal->close();
                                        } else {
                                            $publication_ok = false;
                                        }
                                    }
                                } elseif ($rejection_count >= $threshold) {
                                    $finish_proposal = $conn->prepare("UPDATE collective_knowledge_publication_proposals SET status='rejected', decided_at=NOW() WHERE id=? AND status='pending'");
                                    if ($finish_proposal) {
                                        $finish_proposal->bind_param('i', $proposal_id);
                                        $publication_ok = $finish_proposal->execute();
                                        $finish_proposal->close();
                                    } else {
                                        $publication_ok = false;
                                    }
                                }
                            }
                        } else {
                            $publication_ok = false;
                        }
                    }
                } else {
                    $publication_ok = false;
                }
            }
            if ($publication_ok) {
                $publication_ok = $conn->commit();
                if (!$publication_ok) $conn->rollback();
            } else {
                $conn->rollback();
            }
        }
        $redirect_entry_id = 0;
        if ($proposal_id > 0) {
            $lookup_entry = $conn->prepare("SELECT entry_id FROM collective_knowledge_publication_proposals WHERE id=? LIMIT 1");
            if ($lookup_entry) {
                $lookup_entry->bind_param('i', $proposal_id);
                $lookup_row = kl_fetch_one($lookup_entry);
                if ($lookup_row) $redirect_entry_id = (int)$lookup_row['entry_id'];
                $lookup_entry->close();
            }
        }
        header('Location: knowledge_library.php?id=' . $redirect_entry_id . ($publication_ok ? '&publication_voted=1' : '&publication_error=1'));
        exit;
    }

    // ارزیابی مستقل اعضا برای نسخهٔ جاری مدخل
    if ($action === 'review') {
        $review_entry_id = (int)($_POST['entry_id'] ?? 0);
        $review_vote = (string)($_POST['review_vote'] ?? '');
        $review_reason = trim((string)($_POST['review_reason'] ?? ''));
        $review_error = false;
        $transaction_open = false;

        if ($review_entry_id <= 0 ||
            !in_array($review_vote, ['approve', 'reject', 'revise'], true) ||
            mb_strlen($review_reason, 'UTF-8') < 5 ||
            mb_strlen($review_reason, 'UTF-8') > 4000) {
            $review_error = true;
        } elseif (!$conn->begin_transaction()) {
            $review_error = true;
        } else {
            $transaction_open = true;
            // قفل مدخل تا رأی هم‌زمان با ویرایش، روی نسخهٔ اشتباه ثبت نشود.
            $review_check = $conn->prepare('SELECT id, version_no, created_by, visibility, community_id FROM collective_knowledge_entries WHERE id=? LIMIT 1 FOR UPDATE');
            if (!$review_check) {
                $review_error = true;
            } else {
                $review_check->bind_param('i', $review_entry_id);
                $review_entry = kl_fetch_one($review_check);
                if (!$review_entry ||
                    (int)$review_entry['created_by'] === $user_id ||
                    ($review_entry['visibility'] === 'community' && !kl_member($conn, (int)$review_entry['community_id'], $user_id))) {
                    $review_error = true;
                }
                $review_check->close();
            }

            if (!$review_error) {
                $version_no = (int)$review_entry['version_no'];
                $save_review = $conn->prepare('INSERT INTO collective_knowledge_reviews (entry_id, version_no, reviewer_user_id, vote, reason) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE vote=VALUES(vote), reason=VALUES(reason), updated_at=CURRENT_TIMESTAMP');
                if (!$save_review) {
                    $review_error = true;
                } else {
                    $save_review->bind_param('iiiss', $review_entry_id, $version_no, $user_id, $review_vote, $review_reason);
                    if (!$save_review->execute()) $review_error = true;
                    $save_review->close();
                }
            }

            if (!$review_error) {
                $count_review = $conn->prepare('SELECT vote, COUNT(*) AS total FROM collective_knowledge_reviews WHERE entry_id=? AND version_no=? GROUP BY vote');
                if (!$count_review) {
                    $review_error = true;
                } else {
                    $count_review->bind_param('ii', $review_entry_id, $version_no);
                    $count_rows = kl_fetch_all($count_review);
                    if ($count_rows === null) {
                        $review_error = true;
                    } else {
                        $counts = ['approve'=>0, 'reject'=>0, 'revise'=>0];
                        foreach ($count_rows as $count_row) {
                            if (isset($counts[$count_row['vote']])) $counts[$count_row['vote']] = (int)$count_row['total'];
                        }
                        $total_reviews = array_sum($counts);
                        $new_status = 'unreviewed';
                        if ($total_reviews > 0) {
                            $new_status = 'under_review';
                            $threshold = (int)ceil($total_reviews * 2 / 3);
                            if ($total_reviews >= 3) {
                                if ($counts['approve'] >= $threshold) $new_status = 'verified';
                                elseif ($counts['reject'] >= $threshold) $new_status = 'rejected';
                                elseif ($counts['revise'] >= $threshold) $new_status = 'needs_update';
                            }
                        }
                        $update_status = $conn->prepare('UPDATE collective_knowledge_entries SET status=? WHERE id=? AND version_no=?');
                        if (!$update_status) {
                            $review_error = true;
                        } else {
                            $update_status->bind_param('sii', $new_status, $review_entry_id, $version_no);
                            if (!$update_status->execute()) $review_error = true;
                            $update_status->close();
                        }
                    }
                    $count_review->close();
                }
            }
            if ($review_error) {
                $conn->rollback();
                $transaction_open = false;
            } elseif (!$conn->commit()) {
                $conn->rollback();
                $transaction_open = false;
                $review_error = true;
            } else {
                $transaction_open = false;
            }
            if ($transaction_open) $conn->rollback();
        }

        if ($review_error) {
            header('Location: knowledge_library.php?id=' . $review_entry_id . '&review_error=1');
        } else {
            header('Location: knowledge_library.php?id=' . $review_entry_id . '&reviewed=1');
        }
        exit;
    }
    // ثبت دستی دانش غیرفعال است؛ مدخل‌ها باید از فرایندهای جمعی سامانه شکل بگیرند.
    if ($action !== 'edit') {
        http_response_code(403);
        exit('ثبت مستقیم دانش غیرفعال است؛ دانش جمعی از مباحثه‌ها و رأی‌گیری‌ها شکل می‌گیرد.');
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $type = (string)($_POST['entry_type'] ?? '');
    $summary = trim((string)($_POST['summary'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $evidence = trim((string)($_POST['evidence'] ?? ''));
    $visibility = (string)($_POST['visibility'] ?? 'public');
    $community_id = (int)($_POST['community_id'] ?? 0);
    $source_topic_id = (int)($_POST['source_topic_id'] ?? 0);
    $source_idea_id = (int)($_POST['source_idea_id'] ?? 0);
    $revision_note = trim((string)($_POST['revision_note'] ?? ''));

    if ($title === '' || mb_strlen($title, 'UTF-8') > 180 ||
        !in_array($type, $allowed_types, true) ||
        $summary === '' || mb_strlen($summary, 'UTF-8') > 1000 ||
        $content === '' || mb_strlen($content, 'UTF-8') > 60000 ||
        mb_strlen($evidence, 'UTF-8') > 12000) {
        $error = 'لطفاً عنوان، نوع، خلاصه و متن را کامل کنید و محدودیت طول نوشته‌ها را رعایت کنید.';
    } elseif (!in_array($visibility, ['public','community'], true)) {
        $error = 'نوع دسترسی انتخاب‌شده معتبر نیست.';
    } elseif ($visibility === 'community' && ($community_id <= 0 || !kl_member($conn, $community_id, $user_id))) {
        $error = 'برای ثبت دانش مخصوص یک جامعه، باید عضو همان جامعه باشید.';
    } else {
        if ($source_topic_id > 0) {
            $source_check = $conn->prepare('SELECT community_id FROM topics WHERE id=? LIMIT 1');
            $source_row = null;
            if ($source_check) {
                $source_check->bind_param('i', $source_topic_id);
                $source_row = kl_fetch_one($source_check);
                $source_check->close();
            }
            if (!$source_row || ($visibility === 'community' && (int)$source_row['community_id'] !== $community_id)) {
                $error = 'موضوع مرجع پیدا نشد یا با جامعه انتخاب‌شده هم‌خوانی ندارد.';
            }
        }
        if ($error === '' && $source_idea_id > 0) {
            $source_check = $conn->prepare('SELECT t.community_id, i.topic_id FROM ideas i JOIN topics t ON t.id=i.topic_id WHERE i.id=? LIMIT 1');
            $source_row = null;
            if ($source_check) {
                $source_check->bind_param('i', $source_idea_id);
                $source_row = kl_fetch_one($source_check);
                $source_check->close();
            }
            if (!$source_row || ($visibility === 'community' && (int)$source_row['community_id'] !== $community_id) || ($source_topic_id > 0 && (int)$source_row['topic_id'] !== $source_topic_id)) {
                $error = 'ایدهٔ مرجع پیدا نشد یا با موضوع و جامعهٔ انتخاب‌شده هم‌خوانی ندارد.';
            }
        }
    }

    if ($error === '' && $action === 'edit') {
        $entry_id = (int)($_POST['entry_id'] ?? 0);
        if ($entry_id <= 0 || !$conn->begin_transaction()) {
            $error = 'شروع ثبت امن ویرایش ناموفق بود؛ تغییری اعمال نشد.';
        } else {
            // قفل ردیف، تا دو ویرایش هم‌زمان نسخهٔ قبلی یکسان ثبت نکنند.
            $check = $conn->prepare('SELECT * FROM collective_knowledge_entries WHERE id=? AND created_by=? LIMIT 1 FOR UPDATE');
            $old = null;
            if ($check) {
                $check->bind_param('ii', $entry_id, $user_id);
                $old = kl_fetch_one($check);
                $check->close();
            }
            if (!$old) {
                $conn->rollback();
                http_response_code(403);
                exit('مدخل پیدا نشد یا فقط ثبت‌کنندهٔ آن می‌تواند ویرایش کند.');
            }

            // تا پایان رأی عمومی‌شدن، متن پرونده ثابت می‌ماند تا رأی‌ها دربارهٔ همان نسخه باشند.
            $pending_publication_check = $conn->prepare("SELECT id FROM collective_knowledge_publication_proposals WHERE entry_id=? AND status='pending' LIMIT 1 FOR UPDATE");
            if (!$pending_publication_check) {
                $conn->rollback();
                http_response_code(500);
                exit('بررسی وضعیت پیشنهاد عمومی‌شدن ناموفق بود؛ تغییری اعمال نشد.');
            }
            $pending_publication_check->bind_param('i', $entry_id);
            if (!$pending_publication_check->execute()) {
                $pending_publication_check->close();
                $conn->rollback();
                http_response_code(500);
                exit('بررسی وضعیت پیشنهاد عمومی‌شدن ناموفق بود؛ تغییری اعمال نشد.');
            }
            $pending_publication_result = $pending_publication_check->get_result();
            if (!$pending_publication_result) {
                $pending_publication_check->close();
                $conn->rollback();
                http_response_code(500);
                exit('خواندن وضعیت پیشنهاد عمومی‌شدن ناموفق بود؛ تغییری اعمال نشد.');
            }
            $has_pending_publication = (bool)$pending_publication_result->fetch_assoc();
            $pending_publication_check->close();
            if ($has_pending_publication) {
                $conn->rollback();
                header('Location: knowledge_library.php?id=' . $entry_id . '&publication_pending=1');
                exit;
            }

            // ویرایش محتوا مجاز است، اما منشأ اولیه و دامنهٔ دسترسی پرونده تغییر نمی‌کند.
            $visibility = (string)$old['visibility'];
            $scope_community = $old['community_id'] !== null ? (int)$old['community_id'] : null;
            $topic_value = $old['source_topic_id'] !== null ? (int)$old['source_topic_id'] : null;
            $idea_value = $old['source_idea_id'] !== null ? (int)$old['source_idea_id'] : null;
            $rev = $conn->prepare('INSERT INTO collective_knowledge_revisions (entry_id, editor_user_id, previous_title, previous_entry_type, previous_summary, previous_content, previous_evidence, previous_visibility, previous_community_id, previous_source_topic_id, previous_source_idea_id, previous_status, revision_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$rev) {
                $conn->rollback();
                $error = 'ثبت سابقهٔ ویرایش ناموفق بود؛ متن قبلی حفظ شد و تغییری اعمال نشد.';
            } else {
                $rev->bind_param('iissssssiiiss', $entry_id, $user_id, $old['title'], $old['entry_type'], $old['summary'], $old['content'], $old['evidence'], $old['visibility'], $old['community_id'], $old['source_topic_id'], $old['source_idea_id'], $old['status'], $revision_note);
                if (!$rev->execute()) $error = 'ثبت سابقهٔ ویرایش ناموفق بود؛ متن قبلی حفظ شد و تغییری اعمال نشد.';
                $rev->close();
                if ($error !== '') $conn->rollback();
            }
            if ($error === '') {
                $update = $conn->prepare("UPDATE collective_knowledge_entries SET title=?, entry_type=?, summary=?, content=?, evidence=?, visibility=?, community_id=?, source_topic_id=?, source_idea_id=?, updated_by=?, version_no=version_no+1, status='unreviewed' WHERE id=? AND created_by=?");
                if (!$update) {
                    $conn->rollback();
                    $error = 'آماده‌سازی ویرایش ناموفق بود؛ متن قبلی حفظ شد.';
                } else {
                    $update->bind_param('ssssssiiiiii', $title, $type, $summary, $content, $evidence, $visibility, $scope_community, $topic_value, $idea_value, $user_id, $entry_id, $user_id);
                    if (!$update->execute()) {
                        $conn->rollback();
                        $error = 'ویرایش انجام نشد؛ نسخهٔ قبلی حفظ شد.';
                    }
                    $update->close();
                    if ($error === '') {
                        if ($conn->commit()) {
                            header('Location: knowledge_library.php?id=' . $entry_id . '&updated=1');
                            exit;
                        }
                        $conn->rollback();
                        $error = 'تأیید نهایی ویرایش در پایگاه داده ناموفق بود.';
                    }
                }
            }
        }
    }
}

if (isset($_GET['saved'])) $message = 'مدخل دانش با موفقیت ثبت شد.';
if (isset($_GET['updated'])) $message = 'مدخل به‌روزرسانی شد؛ ارزیابی‌های نسخهٔ قبلی برای نسخهٔ تازه محاسبه نمی‌شوند.';
if (isset($_GET['reviewed'])) $message = 'ارزیابی شما ثبت شد؛ با رسیدن تعداد ارزیابی‌ها به حد نصاب، وضعیت اعتبار به‌روزرسانی می‌شود.';
if (isset($_GET['review_error'])) $error = 'ارزیابی ثبت نشد؛ مطمئن شوید که نویسندهٔ مدخل نیستید، عضو جامعهٔ مربوط هستید و دلیل کافی نوشته‌اید.';
if (isset($_GET['publication_proposed'])) $message = 'پیشنهاد عمومی‌شدن برای رأی اعضای جامعه ثبت شد.';
if (isset($_GET['publication_voted'])) $message = 'رأی شما ثبت شد؛ نتیجه با حداقل ۳ رأی و موافقت دوسوم مشخص می‌شود.';
if (isset($_GET['publication_error'])) $error = 'ثبت پیشنهاد یا رأی انجام نشد؛ عضویت در جامعه و وضعیت پرونده را بررسی کنید.';
if (isset($_GET['publication_pending'])) $error = 'تا پایان رأی‌گیری عمومی‌شدن، ویرایش متن پرونده موقتاً متوقف است تا رأی‌ها دربارهٔ یک نسخه باشند.';
if (!empty($GLOBALS['kl_db_read_error'])) {
    $error = 'خواندن اطلاعات از پایگاه داده ناموفق بود. لطفاً صفحه را تازه‌سازی کنید؛ اگر مشکل ادامه داشت، گزارش خطای سامانه باید بررسی شود.';
}

$communities = [];
$cs = $conn->prepare('SELECT c.id, c.name FROM communities c JOIN community_members m ON m.community_id=c.id WHERE m.user_id=? ORDER BY c.name');
if ($cs) {
    $cs->bind_param('i', $user_id);
    $community_rows = kl_fetch_all($cs);
    if ($community_rows !== null) $communities = $community_rows;
    $cs->close();
}

$selected_community_id = max(0, (int)($_GET['community_id'] ?? 0));
$selected_category_id = max(0, (int)($_GET['category_id'] ?? 0));
$selected_community_name = '';
if ($selected_community_id > 0) {
    foreach ($communities as $community_option) {
        if ((int)$community_option['id'] === $selected_community_id) {
            $selected_community_name = (string)$community_option['name'];
            break;
        }
    }
    // فقط جامعه‌ای که کاربر عضو آن است قابل انتخاب است.
    if ($selected_community_name === '') $selected_community_id = 0;
}
$default_source_topic_id = max(0, (int)($_GET['source_topic_id'] ?? 0));
$default_source_idea_id = max(0, (int)($_GET['source_idea_id'] ?? 0));
$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 120, 'UTF-8');
$like = '%' . $q . '%';
$entry_id = (int)($_GET['id'] ?? 0);
$edit_mode = isset($_GET['edit']) && $_GET['edit'] === '1';
$entry = null;
$revisions = [];
$review_counts = ['approve'=>0, 'reject'=>0, 'revise'=>0];
$my_review = null;
$review_details = [];
$publication_proposal = null;
$publication_vote_counts = ['approve'=>0, 'reject'=>0];
$my_publication_vote = null;
$entry_decision_link = null;

// در صورت نصب دفتر تصمیم، پیوند آن را فقط در محدودهٔ دسترسی به موضوع مرجع می‌خوانیم.
$decision_ledger_ready = false;
$decision_ledger_check = $conn->query("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='community_decision_records'");
if ($decision_ledger_check) {
    $decision_ledger_ready = (int)($decision_ledger_check->fetch_assoc()['total'] ?? 0) === 1;
    $decision_ledger_check->close();
}
if ($decision_ledger_ready) require_once __DIR__ . '/community_decision_ledger.php';

if ($entry_id > 0) {
    $stmt = $conn->prepare("SELECT k.*, u.username, c.name AS community_name, t.title AS source_topic_title, t.community_id AS source_topic_community_id,
        i.content AS source_idea_content, it.id AS source_idea_topic_id, it.community_id AS source_idea_community_id
        FROM collective_knowledge_entries k
        JOIN users u ON u.id=k.created_by
        LEFT JOIN communities c ON c.id=k.community_id
        LEFT JOIN topics t ON t.id=k.source_topic_id
        LEFT JOIN ideas i ON i.id=k.source_idea_id
        LEFT JOIN topics it ON it.id=i.topic_id
        WHERE k.id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $entry_id);
        $entry = kl_fetch_one($stmt);
        $stmt->close();
    }

    // نمایش اطلاعات مرجع فقط برای اعضای جامعهٔ مرجع
    $can_view_source_topic = false;
    $can_view_source_idea = false;
    if ($entry && !empty($entry['source_topic_id'])) {
        $can_view_source_topic = kl_member($conn, (int)$entry['source_topic_community_id'], $user_id);
        if ($can_view_source_topic && $decision_ledger_ready) {
            $candidate_decision_link = community_decision_get_knowledge_link($conn, (int)$entry['source_topic_id']);
            if (
                $candidate_decision_link &&
                (int)$candidate_decision_link['community_id'] === (int)$entry['source_topic_community_id'] &&
                (int)($candidate_decision_link['knowledge_entry_id'] ?? 0) === $entry_id
            ) {
                $entry_decision_link = $candidate_decision_link;
            }
        }
    }
    if ($entry && !empty($entry['source_idea_id'])) {
        $can_view_source_idea = kl_member($conn, (int)$entry['source_idea_community_id'], $user_id);
    }
    if ($entry && $entry['visibility'] === 'community' && !kl_member($conn, (int)$entry['community_id'], $user_id)) {
        http_response_code(403);
        exit('این مدخل فقط برای اعضای جامعهٔ مربوط قابل مشاهده است.');
    }

    // همهٔ ایده‌های موضوع مادر را مستقیم از جدول اصلی می‌خوانیم؛ متن موازی ساخته نمی‌شود.
    $source_ideas = [];
    if ($entry && !empty($entry['source_topic_id']) && $can_view_source_topic) {
        $ideas_stmt = $conn->prepare("
            SELECT i.id, i.content, i.user_id, u.username
            FROM ideas i
            JOIN users u ON u.id = i.user_id
            WHERE i.topic_id = ?
            ORDER BY i.id ASC
        ");
        if ($ideas_stmt) {
            $source_topic_id = (int)$entry['source_topic_id'];
            $ideas_stmt->bind_param('i', $source_topic_id);
            $ideas_rows = kl_fetch_all($ideas_stmt);
            if ($ideas_rows !== null) $source_ideas = $ideas_rows;
            $ideas_stmt->close();
        }
    }

    // بازخوردهای تمام ایده‌های موضوع مادر را بدون کپی‌کردن داده‌ها در پرونده نشان بده.
    $source_feedback = [];
    if ($entry && !empty($entry['source_topic_id']) && $can_view_source_topic) {
        $feedback_stmt = $conn->prepare("
            SELECT f.id, f.idea_id, f.content, f.review_status, f.created_at,
                   u.username, i.content AS idea_content
            FROM community_idea_feedback f
            JOIN users u ON u.id = f.user_id
            JOIN ideas i ON i.id = f.idea_id AND i.topic_id = f.topic_id
            WHERE f.topic_id = ? AND f.community_id = ?
            ORDER BY f.created_at ASC, f.id ASC
        ");
        if ($feedback_stmt) {
            $source_topic_id = (int)$entry['source_topic_id'];
            $source_community_id = (int)$entry['source_topic_community_id'];
            $feedback_stmt->bind_param('ii', $source_topic_id, $source_community_id);
            $feedback_stmt->execute();
            $feedback_result = kl_fetch_all($feedback_stmt);
            if ($feedback_result !== null) $source_feedback = $feedback_result;
            $feedback_stmt->close();
        }
    }

    if ($entry) {
        $review_count_stmt = $conn->prepare('SELECT vote, COUNT(*) AS total FROM collective_knowledge_reviews WHERE entry_id=? AND version_no=? GROUP BY vote');
        $current_version = (int)$entry['version_no'];
        if ($review_count_stmt) {
            $review_count_stmt->bind_param('ii', $entry_id, $current_version);
            $review_count_rows = kl_fetch_all($review_count_stmt);
            if ($review_count_rows !== null) {
                foreach ($review_count_rows as $review_count_row) {
                    if (isset($review_counts[$review_count_row['vote']])) $review_counts[$review_count_row['vote']] = (int)$review_count_row['total'];
                }
            }
            $review_count_stmt->close();
        }

        $my_review_stmt = $conn->prepare('SELECT vote, reason FROM collective_knowledge_reviews WHERE entry_id=? AND version_no=? AND reviewer_user_id=? LIMIT 1');
        if ($my_review_stmt) {
            $my_review_stmt->bind_param('iii', $entry_id, $current_version, $user_id);
            $my_review = kl_fetch_one($my_review_stmt);
            $my_review_stmt->close();
        }

        $review_details_stmt = $conn->prepare('SELECT r.vote, r.reason, r.updated_at, u.username FROM collective_knowledge_reviews r JOIN users u ON u.id=r.reviewer_user_id WHERE r.entry_id=? AND r.version_no=? ORDER BY r.updated_at DESC, r.id DESC');
        if ($review_details_stmt) {
            $review_details_stmt->bind_param('ii', $entry_id, $current_version);
            $review_rows = kl_fetch_all($review_details_stmt);
            if ($review_rows !== null) $review_details = $review_rows;
            $review_details_stmt->close();
        }

        $publication_stmt = $conn->prepare("SELECT p.id, p.community_id, p.proposed_by, p.status, p.created_at, c.name AS community_name, u.username AS proposer_name
            FROM collective_knowledge_publication_proposals p
            LEFT JOIN communities c ON c.id=p.community_id
            LEFT JOIN users u ON u.id=p.proposed_by
            WHERE p.entry_id=? ORDER BY p.id DESC LIMIT 1");
        if ($publication_stmt) {
            $publication_stmt->bind_param('i', $entry_id);
            $publication_proposal = kl_fetch_one($publication_stmt);
            $publication_stmt->close();
        }
        if ($publication_proposal) {
            $publication_id_for_view = (int)$publication_proposal['id'];
            $publication_count_stmt = $conn->prepare("SELECT vote, COUNT(*) AS total FROM collective_knowledge_publication_votes WHERE proposal_id=? GROUP BY vote");
            if ($publication_count_stmt) {
                $publication_count_stmt->bind_param('i', $publication_id_for_view);
                $publication_count_rows = kl_fetch_all($publication_count_stmt);
                if ($publication_count_rows !== null) {
                    foreach ($publication_count_rows as $publication_count_row) {
                        if (isset($publication_vote_counts[$publication_count_row['vote']])) $publication_vote_counts[$publication_count_row['vote']] = (int)$publication_count_row['total'];
                    }
                }
                $publication_count_stmt->close();
            }
            $my_publication_stmt = $conn->prepare("SELECT vote FROM collective_knowledge_publication_votes WHERE proposal_id=? AND user_id=? LIMIT 1");
            if ($my_publication_stmt) {
                $my_publication_stmt->bind_param('ii', $publication_id_for_view, $user_id);
                $my_publication_row = kl_fetch_one($my_publication_stmt);
                if ($my_publication_row) $my_publication_vote = $my_publication_row['vote'];
                $my_publication_stmt->close();
            }
        }
    }
    if ($entry && $edit_mode && (int)$entry['created_by'] === $user_id) {
        $rs = $conn->prepare('SELECT r.*, u.username FROM collective_knowledge_revisions r JOIN users u ON u.id=r.editor_user_id WHERE r.entry_id=? ORDER BY r.id DESC LIMIT 20');
        if ($rs) {
            $rs->bind_param('i', $entry_id);
            $revision_rows = kl_fetch_all($rs);
            if ($revision_rows !== null) $revisions = $revision_rows;
            $rs->close();
        }
    }
}

$category_options = [];
$category_sql = "SELECT DISTINCT tc.id, tc.name FROM topic_categories tc WHERE tc.is_active=1 AND (tc.community_id IS NULL OR EXISTS (SELECT 1 FROM community_members cm WHERE cm.community_id=tc.community_id AND cm.user_id=?))";
if ($selected_community_id > 0) $category_sql .= " AND (tc.community_id IS NULL OR tc.community_id=?)";
$category_sql .= " ORDER BY tc.name ASC";
$category_stmt = $conn->prepare($category_sql);
if ($category_stmt) {
    if ($selected_community_id > 0) $category_stmt->bind_param('ii', $user_id, $selected_community_id);
    else $category_stmt->bind_param('i', $user_id);
    $category_rows = kl_fetch_all($category_stmt);
    if ($category_rows !== null) $category_options = $category_rows;
    $category_stmt->close();
}
if ($selected_category_id > 0 && !array_filter($category_options, static function($item) use ($selected_category_id) { return (int)$item['id'] === $selected_category_id; })) $selected_category_id = 0;

$entries = [];
if ($entry_id === 0) {
    if ($selected_community_id > 0) {
        // در نمای یک جامعه، دانش اختصاصی همان جامعه به‌همراه دانش‌های عمومی نمایش داده می‌شود.
        $stmt = $conn->prepare("SELECT k.id, k.title, k.entry_type, k.summary, k.visibility, k.community_id, k.status, k.created_at, u.username, c.name AS community_name, tc.name AS topic_category_name
            FROM collective_knowledge_entries k
            JOIN users u ON u.id=k.created_by
            LEFT JOIN communities c ON c.id=k.community_id
            LEFT JOIN topics st ON st.id=k.source_topic_id
            LEFT JOIN topic_categories tc ON tc.id=st.category_id
            WHERE (k.visibility='public' OR (k.visibility='community' AND k.community_id=?))
              AND (?=0 OR st.category_id=?)
              AND (?='' OR k.title LIKE ? OR k.summary LIKE ? OR k.content LIKE ?)
            ORDER BY k.updated_at DESC, k.id DESC LIMIT 100");
        if ($stmt) {
            $stmt->bind_param('iiissss', $selected_community_id, $selected_category_id, $selected_category_id, $q, $like, $like, $like);
            $entry_rows = kl_fetch_all($stmt);
            if ($entry_rows !== null) $entries = $entry_rows;
            $stmt->close();
        }
    } else {
        // نمای همهٔ جوامع: دانش عمومی و دانش جوامعی که کاربر عضو آن‌هاست.
        $stmt = $conn->prepare("SELECT k.id, k.title, k.entry_type, k.summary, k.visibility, k.community_id, k.status, k.created_at, u.username, c.name AS community_name, tc.name AS topic_category_name
            FROM collective_knowledge_entries k
            JOIN users u ON u.id=k.created_by
            LEFT JOIN communities c ON c.id=k.community_id
            LEFT JOIN topics st ON st.id=k.source_topic_id
            LEFT JOIN topic_categories tc ON tc.id=st.category_id
            WHERE (k.visibility='public' OR (k.visibility='community' AND EXISTS (
                SELECT 1 FROM community_members m WHERE m.community_id=k.community_id AND m.user_id=?
            ))) AND (?=0 OR st.category_id=?) AND (?='' OR k.title LIKE ? OR k.summary LIKE ? OR k.content LIKE ?)
            ORDER BY k.updated_at DESC, k.id DESC LIMIT 100");
        if ($stmt) {
            $stmt->bind_param('iiissss', $user_id, $selected_category_id, $selected_category_id, $q, $like, $like, $like);
            $entry_rows = kl_fetch_all($stmt);
            if ($entry_rows !== null) $entries = $entry_rows;
            $stmt->close();
        }
    }
}

function kl_render_form($csrf, $communities, $entry = null, $source_topic_id = 0, $source_idea_id = 0) {
    $editing = (bool)$entry;
    $type = $entry['entry_type'] ?? 'experience';
    $visibility = $entry['visibility'] ?? 'public';
    $community_id = (int)($entry['community_id'] ?? 0);
    echo '<form method="post" class="entry-form">';
    echo '<input type="hidden" name="csrf_token" value="' . kl_h($csrf) . '">';
    echo '<input type="hidden" name="action" value="' . ($editing ? 'edit' : 'create') . '">';
    if ($editing) echo '<input type="hidden" name="entry_id" value="' . (int)$entry['id'] . '">';
    echo '<label>عنوان مدخل</label><input name="title" maxlength="180" required value="' . kl_h($entry['title'] ?? '') . '">';
    echo '<label>نوع دانش</label><select name="entry_type" required>';
    foreach (['experience'=>'تجربه','solution'=>'راه‌حل','research'=>'پژوهش و دانش','lesson'=>'درس‌آموخته'] as $key=>$label) echo '<option value="' . $key . '"' . ($type===$key?' selected':'') . '>' . $label . '</option>';
    echo '</select>';
    echo '<label>خلاصهٔ کوتاه</label><textarea name="summary" rows="2" maxlength="1000" required>' . kl_h($entry['summary'] ?? '') . '</textarea>';
    echo '<label>شرح کامل و جزئیات</label><textarea name="content" rows="8" maxlength="60000" required>' . kl_h($entry['content'] ?? '') . '</textarea>';
    echo '<label>شواهد و منابع (نشانی اینترنتی یا مشخصات منبع؛ اختیاری)</label><textarea name="evidence" rows="3" maxlength="12000">' . kl_h($entry['evidence'] ?? '') . '</textarea>';
    echo '<p class="muted">منشأ و دامنهٔ دسترسی این پرونده ثابت می‌ماند: ' . ($visibility==='public'?'عمومی':'اختصاصی جامعهٔ مربوط') . '؛ جامعه و موضوع مبدأ از پروندهٔ اصلی حفظ می‌شوند.</p>';
    if ($editing) echo '<label>دلیل یا توضیح ویرایش (اختیاری)</label><input name="revision_note" maxlength="1000">';
    echo '<button type="submit">' . ($editing ? 'ذخیرهٔ نسخهٔ جدید' : 'ثبت در کتابخانه') . '</button></form>';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>کتابخانهٔ دانش جمعی</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:12px;background:#eef3f8;color:#263238;font-family:Tahoma,Arial,sans-serif;line-height:1.9}
.container{max-width:920px;margin:0 auto}.panel,.entry{background:#fff;border:1px solid #d8e2eb;border-radius:10px;padding:14px;margin-bottom:12px}
h1{font-size:21px;margin:0}h2{font-size:17px;margin:0 0 9px}.top{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
a{color:#1769aa;text-decoration:none;font-weight:bold}.muted{font-size:12px;color:#687789}.summary{margin:7px 0}
.entry-form label{display:block;margin:10px 0 3px;font-size:13px;font-weight:bold}
input,select,textarea{width:100%;padding:9px;border:1px solid #bdc9d4;border-radius:7px;font:inherit;background:#fff}
textarea{resize:vertical}button,.button{display:inline-block;border:0;border-radius:7px;padding:9px 14px;background:#2065d1;color:#fff;font:inherit;font-weight:bold;cursor:pointer;margin-top:10px}
.button.secondary{background:#64748b}.notice{padding:9px;border-radius:7px;background:#e8f7ef;color:#17633d;margin-bottom:10px}.error{padding:9px;border-radius:7px;background:#fff0ef;color:#a12622;margin-bottom:10px}
.tag{display:inline-block;padding:2px 8px;border-radius:5px;background:#eaf2fb;color:#315a83;font-size:12px;margin-left:4px}
.entry-title{font-size:16px}.content{white-space:pre-wrap;overflow-wrap:anywhere}.entry-actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:520px){body{padding:8px}.panel,.entry{padding:11px}h1{font-size:19px}}
</style>
</head>
<body><main class="container">
<section class="panel">
<div class="top"><h1>📚 کتابخانهٔ دانش جمعی</h1><a href="profile.php">بازگشت به پروفایل</a></div>
<p>تجربه‌ها، راه‌حل‌ها، پژوهش‌ها و درس‌آموخته‌ها را ثبت کنید تا دانش مفید برای دیگر جوامع نیز قابل استفاده باشد.</p>
<?php if ($message): ?><div class="notice"><?= kl_h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?= kl_h($error) ?></div><?php endif; ?>
</section>

<?php if ($entry_id > 0): ?>
<?php if (!$entry): ?>
<section class="panel"><?= !empty($GLOBALS['kl_db_read_error']) ? 'به‌دلیل خطا در خواندن پایگاه داده، امکان بررسی مدخل وجود ندارد.' : 'مدخل موردنظر پیدا نشد.' ?> <a href="knowledge_library.php">بازگشت به کتابخانه</a></section>
<?php elseif ($edit_mode && (int)$entry['created_by'] === $user_id): ?>
<section class="panel"><h2>ویرایش مدخل دانش</h2>
<?php kl_render_form($csrf, $communities, $entry); ?>
<h2 style="margin-top:20px">سابقهٔ نسخه‌ها</h2>
<?php if (!$revisions): ?><p class="muted">هنوز نسخهٔ قبلی ثبت نشده است.</p><?php endif; ?>
<?php foreach ($revisions as $rev): ?><details class="entry"><summary>نسخهٔ قبلی — <?= kl_h($rev['username']) ?> — <?= kl_h($rev['created_at']) ?></summary><p><strong><?= kl_h($rev['previous_title']) ?></strong></p><p class="muted">نوع: <?= kl_h(kl_type_label($rev['previous_entry_type'])) ?> · دسترسی: <?= $rev['previous_visibility']==='public'?'عمومی':'ویژهٔ جامعهٔ شمارهٔ '.(int)$rev['previous_community_id'] ?> · وضعیت: <?= kl_h(kl_status_label($rev['previous_status'])) ?></p><p><?= nl2br(kl_h($rev['previous_summary'])) ?></p><div class="content"><?= kl_h($rev['previous_content']) ?></div><?php if ($rev['revision_note']): ?><p class="muted">یادداشت ویرایش: <?= kl_h($rev['revision_note']) ?></p><?php endif; ?></details><?php endforeach; ?>
</section>
<?php else: ?>
<section class="panel">
<div class="top"><h2><?= kl_h($entry['title']) ?></h2><span class="tag"><?= kl_h(kl_type_label($entry['entry_type'])) ?></span></div>
<p class="muted">ثبت‌کننده: <?= kl_h($entry['username']) ?> · <?= $entry['visibility']==='public'?'دانش عمومی':'ویژهٔ جامعهٔ '.kl_h($entry['community_name'] ?? '') ?> · وضعیت: <?= kl_h(kl_status_label($entry['status'])) ?></p>
<p class="muted">نسخهٔ جاری: <?= (int)$entry['version_no'] ?> · ارزیابی‌ها: تأیید <?= (int)$review_counts['approve'] ?>، مخالفت <?= (int)$review_counts['reject'] ?>، نیازمند اصلاح <?= (int)$review_counts['revise'] ?></p>
<h3>خلاصه</h3><p><?= nl2br(kl_h($entry['summary'])) ?></p>
<h3>شرح و جزئیات</h3><div class="content"><?= kl_h($entry['content']) ?></div>
<?php if (!empty($entry['evidence'])): ?><h3>شواهد و منابع</h3><div class="content"><?= kl_h($entry['evidence']) ?></div><?php endif; ?>
<?php if (!empty($entry['source_topic_id']) && $can_view_source_topic): ?><p>موضوع مرتبط: <a href="community.php?id=<?= (int)($entry['source_topic_community_id'] ?? 0) ?>#topic-<?= (int)$entry['source_topic_id'] ?>">موضوع شمارهٔ <?= (int)$entry['source_topic_id'] ?></a> — <?= kl_h($entry['source_topic_title'] ?? '') ?></p><?php elseif (!empty($entry['source_topic_id'])): ?><p class="muted">موضوع مرجع در جامعهٔ دیگری است؛ برای مشاهده باید عضو آن جامعه باشید.</p><?php endif; ?>
<?php if ($entry_decision_link): ?>
<p class="muted">📋 تصمیم جمعی مرتبط: شمارهٔ <?= (int)$entry_decision_link['decision_id'] ?> ·
پذیرش: <?= $entry_decision_link['acceptance_status']==='accepted'?'پذیرفته‌شده':($entry_decision_link['acceptance_status']==='not_accepted'?'پذیرفته‌نشده':'بدون رأی') ?> ·
وضعیت الزام: <?= $entry_decision_link['enforceability_status']==='enforceable'?'لازم‌الاجرا':($entry_decision_link['enforceability_status']==='not_enforceable'?'غیرلازم‌الاجرا':'تعیین‌نشده') ?></p>
<?php endif; ?>
<?php if (!empty($entry['source_idea_id']) && $can_view_source_idea): ?><p>ایدهٔ مرتبط: <a href="community.php?id=<?= (int)($entry['source_idea_community_id'] ?? 0) ?>#topic-<?= (int)($entry['source_idea_topic_id'] ?? 0) ?>">مشاهدهٔ موضوع و ایدهٔ مرتبط</a> — <?= kl_h(mb_substr(strip_tags((string)($entry['source_idea_content'] ?? '')),0,180,'UTF-8')) ?></p><?php elseif (!empty($entry['source_idea_id'])): ?><p class="muted">ایدهٔ مرجع در جامعهٔ دیگری است؛ برای مشاهده باید عضو آن جامعه باشید.</p><?php endif; ?>

<?php if ($entry['visibility'] === 'public' && (int)($entry['community_id'] ?? 0) > 0): ?>
<section class="panel"><h3>منشأ دانش</h3><p class="muted">این دانش اکنون عمومی است؛ منشأ اولیهٔ آن جامعهٔ <?= kl_h($entry['community_name'] ?? 'مبدأ') ?> است و این سابقه حفظ شده است.</p></section>
<?php elseif ($entry['visibility'] === 'community' && (int)($entry['community_id'] ?? 0) > 0): ?>
<section class="panel">
<h3>پیشنهاد عمومی‌شدن دانش</h3>
<p class="muted">این پرونده از جامعهٔ <?= kl_h($entry['community_name'] ?? '') ?> تولید شده و فعلاً فقط برای اعضای همان جامعه قابل مشاهده است. عمومی‌شدن فقط با رأی اعضای همین جامعه انجام می‌شود.</p>
<?php if ($publication_proposal && $publication_proposal['status'] === 'pending'): ?>
<p>پیشنهاد ثبت‌شده توسط <?= kl_h($publication_proposal['proposer_name'] ?? 'یکی از اعضا') ?> · موافق: <?= (int)$publication_vote_counts['approve'] ?> · مخالف: <?= (int)$publication_vote_counts['reject'] ?></p>
<p class="muted">برای نتیجه، دست‌کم ۳ رأی و موافقت دوسوم رأی‌دهندگان لازم است. هر عضو می‌تواند رأی خود را تغییر دهد.</p>
<form method="post" class="entry-form">
<input type="hidden" name="csrf_token" value="<?= kl_h($csrf) ?>">
<input type="hidden" name="action" value="vote_public">
<input type="hidden" name="proposal_id" value="<?= (int)$publication_proposal['id'] ?>">
<label>رأی شما</label>
<select name="publication_vote" required>
<option value="approve" <?= $my_publication_vote==='approve'?'selected':'' ?>>موافق عمومی‌شدن</option>
<option value="reject" <?= $my_publication_vote==='reject'?'selected':'' ?>>مخالف عمومی‌شدن</option>
</select>
<button type="submit">ثبت یا تغییر رأی</button>
</form>
<?php elseif ($publication_proposal && $publication_proposal['status'] === 'approved'): ?>
<p>این دانش با رأی اعضای جامعه عمومی شده است.</p>
<?php else: ?>
<?php if ($publication_proposal && $publication_proposal['status'] === 'rejected'): ?><p class="muted">پیشنهاد قبلی پذیرفته نشد؛ اعضا می‌توانند پیشنهاد تازه‌ای ثبت کنند.</p><?php endif; ?>
<form method="post" class="entry-form">
<input type="hidden" name="csrf_token" value="<?= kl_h($csrf) ?>">
<input type="hidden" name="action" value="propose_public">
<input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
<button type="submit">پیشنهاد عمومی‌شدن</button>
</form>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($entry['source_topic_id']) && $can_view_source_topic): ?>
<section class="panel">
<h3>ایده‌های ثبت‌شده برای این موضوع (<?= count($source_ideas) ?>)</h3>
<p class="muted">ایده‌ها از جدول اصلی خوانده می‌شوند؛ چه منتخب باشند و چه رقیب، در پرونده باقی می‌مانند.</p>
<?php if (!$source_ideas): ?><p class="muted">هنوز ایده‌ای برای این موضوع ثبت نشده است.</p><?php endif; ?>
<?php foreach ($source_ideas as $idea_item): ?>
<article class="entry">
<strong>ایدهٔ شمارهٔ <?= (int)$idea_item['id'] ?></strong>
<div class="content"><?= nl2br(kl_h($idea_item['content'])) ?></div>
<p class="muted">ثبت‌کننده: <?= kl_h($idea_item['username']) ?></p>
</article>
<?php endforeach; ?>
</section>
<section class="panel">
<h3>نقدها و بازخوردهای همهٔ ایده‌های این موضوع (<?= count($source_feedback) ?>)</h3>
<p class="muted">این بازخوردها مستقیماً از سوابق موضوع خوانده می‌شوند؛ ایدهٔ منتخب یا رقیب بودن، باعث حذف آن‌ها نمی‌شود. وضعیت بررسی نیز همان وضعیت ثبت‌شده در جامعه است.</p>
<?php if (!$source_feedback): ?><p class="muted">برای این موضوع هنوز بازخوردی ثبت نشده است.</p><?php endif; ?>
<?php foreach ($source_feedback as $feedback_item): ?>
<article class="entry">
<div class="top">
<strong>ایدهٔ شمارهٔ <?= (int)$feedback_item['idea_id'] ?></strong>
<span class="tag"><?= kl_h(($feedback_item['review_status'] ?? '') === 'accepted' ? 'پذیرفته‌شده' : (($feedback_item['review_status'] ?? '') === 'rejected' ? 'پذیرفته‌نشده' : 'در انتظار بررسی')) ?></span>
</div>
<?php if (!empty($feedback_item['idea_content'])): ?><p class="muted">ایده: <?= kl_h(mb_substr(strip_tags((string)$feedback_item['idea_content']), 0, 220, 'UTF-8')) ?></p><?php endif; ?>
<div class="content"><?= nl2br(kl_h($feedback_item['content'])) ?></div>
<p class="muted">ثبت‌کننده: <?= kl_h($feedback_item['username']) ?> · <?= kl_h($feedback_item['created_at']) ?></p>
</article>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (strpos((string)$entry['title'], 'پروندهٔ در حال شکل‌گیری:') === 0): ?>
<section class="panel">
<h3>وضعیت پرونده</h3>
<p class="muted">این پرونده هنوز در حال شکل‌گیری است. تا نهایی‌شدن موضوع، نتیجهٔ جمعی یا اعتبار نهایی برای آن ثبت نمی‌شود.</p>
</section>
<?php elseif ((int)$entry['created_by'] !== $user_id): ?>
<section class="panel">
<h3>ارزیابی جمعی این نسخه</h3>
<p class="muted">ارزیابی باید همراه با دلیل باشد. هر عضو برای هر نسخه یک رأی دارد و می‌تواند آن را اصلاح کند. نتیجهٔ اعتبار فقط با دست‌کم ۳ ارزیابی و موافقت دوسوم ارزیابان تعیین می‌شود.</p>
<form method="post" class="entry-form">
<input type="hidden" name="csrf_token" value="<?= kl_h($csrf) ?>">
<input type="hidden" name="action" value="review">
<input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
<label>نظر شما</label>
<select name="review_vote" required>
<option value="approve" <?= (($my_review['vote'] ?? '')==='approve'?'selected':'') ?>>تأیید؛ با دلیل</option>
<option value="reject" <?= (($my_review['vote'] ?? '')==='reject'?'selected':'') ?>>مخالفت؛ با دلیل</option>
<option value="revise" <?= (($my_review['vote'] ?? '')==='revise'?'selected':'') ?>>نیازمند اصلاح؛ با دلیل</option>
</select>
<label>دلیل و شواهد ارزیابی (حداقل ۵ نویسه)</label>
<textarea name="review_reason" rows="3" maxlength="4000" required><?= kl_h($my_review['reason'] ?? '') ?></textarea>
<button type="submit">ثبت ارزیابی</button>
</form>
</section>
<?php endif; ?>
<section class="panel">
<h3>دلایل ارزیابی اعضا</h3>
<?php if (!$review_details): ?><p class="muted">هنوز ارزیابی‌ای برای این نسخه ثبت نشده است.</p><?php endif; ?>
<?php foreach ($review_details as $review_item): ?>
<article class="entry">
<div class="top"><strong><?= kl_h(kl_review_label($review_item['vote'])) ?></strong><span class="muted"><?= kl_h($review_item['username']) ?> · <?= kl_h($review_item['updated_at']) ?></span></div>
<p class="content"><?= nl2br(kl_h($review_item['reason'])) ?></p>
</article>
<?php endforeach; ?>
</section>
<div class="entry-actions"><a class="button secondary" href="knowledge_library.php<?= $selected_community_id > 0 ? '?community_id=' . (int)$selected_community_id : '' ?>">بازگشت به فهرست</a><?php if ((int)$entry['created_by']===$user_id && strpos((string)$entry['title'], 'پروندهٔ در حال شکل‌گیری:') !== 0): ?><a class="button" href="knowledge_library.php?id=<?= (int)$entry['id'] ?>&edit=1">ویرایش و تکمیل</a><?php endif; ?></div>
</section>
<?php endif; ?>
<?php else: ?>
<section class="panel">
<h2>انتخاب جامعه</h2>
<form method="get" class="entry-form">
<label for="knowledge-community">دانش‌های کدام جامعه نمایش داده شود؟</label>
<select id="knowledge-community" name="community_id" onchange="this.form.submit()">
<option value="0" <?= $selected_community_id===0?'selected':'' ?>>همهٔ جوامع و دانش‌های عمومی</option>
<?php foreach ($communities as $community_option): ?>
<option value="<?= (int)$community_option['id'] ?>" <?= $selected_community_id===(int)$community_option['id']?'selected':'' ?>><?= kl_h($community_option['name']) ?></option>
<?php endforeach; ?>
</select>
<?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= kl_h($q) ?>"><?php endif; ?>
<?php if ($selected_category_id > 0): ?><input type="hidden" name="category_id" value="<?= (int)$selected_category_id ?>"><?php endif; ?>
<noscript><button type="submit">نمایش دانش‌های جامعه</button></noscript>
</form>
</section>
<section class="panel"><h2>جست‌وجو در کتابخانه</h2><form method="get">
<?php if ($selected_community_id > 0): ?><input type="hidden" name="community_id" value="<?= (int)$selected_community_id ?>"><?php endif; ?>
<input type="search" name="q" maxlength="120" value="<?= kl_h($q) ?>" placeholder="عنوان، خلاصه یا متن دانش را جست‌وجو کنید">
<label for="knowledge-category">دسته‌بندی موضوع</label>
<select id="knowledge-category" name="category_id">
<option value="0">همهٔ دسته‌های موضوع</option>
<?php foreach ($category_options as $category_option): ?>
<option value="<?= (int)$category_option['id'] ?>" <?= $selected_category_id===(int)$category_option['id']?'selected':'' ?>><?= kl_h($category_option['name']) ?></option>
<?php endforeach; ?>
</select><button type="submit">جست‌وجو</button></form></section>
<section class="panel"><h2><?= $selected_community_id > 0 ? 'دانش‌های جامعهٔ ' . kl_h($selected_community_name) . ' و دانش‌های عمومی' : 'مدخل‌های همهٔ جوامع' ?> (<?= count($entries) ?>)</h2>
<?php if (!$entries): ?><p class="muted">هنوز مدخلی ثبت نشده یا نتیجه‌ای با این عبارت پیدا نشد.</p><?php endif; ?>
<?php foreach ($entries as $item): ?><article class="entry">
<div class="top"><a class="entry-title" href="knowledge_library.php?id=<?= (int)$item['id'] ?><?= $selected_community_id > 0 ? '&amp;community_id=' . (int)$selected_community_id : '' ?>"><?= kl_h($item['title']) ?></a><span class="tag"><?= kl_h(kl_type_label($item['entry_type'])) ?></span></div>
<p class="summary"><?= nl2br(kl_h($item['summary'])) ?></p>
<?php if (!empty($item['topic_category_name'])): ?><span class="tag"><?= kl_h($item['topic_category_name']) ?></span><?php endif; ?>
<div class="muted">ثبت‌کننده: <?= kl_h($item['username']) ?> · <?= $item['visibility']==='public'?'عمومی':'ویژهٔ '.kl_h($item['community_name'] ?? 'جامعه') ?> · <?= kl_h(kl_status_label($item['status'])) ?></div>
</article><?php endforeach; ?>
</section>
<?php endif; ?>
</main></body></html>
