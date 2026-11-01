<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit("ابتدا وارد شوید."); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit("درخواست نامعتبر است."); }
$user_id = (int)$_SESSION['user_id'];
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['community_duty_csrf']) || $csrf === '' || !hash_equals($_SESSION['community_duty_csrf'], $csrf)) {
    http_response_code(403); exit("نشست معتبر نیست؛ صفحه را تازه‌سازی کنید.");
}
$action = (string)($_POST['action'] ?? '');
$community_id = (int)($_POST['community_id'] ?? 0);
$app_id = (int)($_POST['application_id'] ?? 0);
function expertise_member(mysqli $conn, int $community_id, int $user_id): bool {
    $s=$conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
    if(!$s) return false; $s->bind_param("ii",$community_id,$user_id); $s->execute();
    $ok=(bool)$s->get_result()->fetch_row(); $s->close(); return $ok;
}
function expertise_is_requester(mysqli $conn, int $app_id, int $user_id): bool {
    $s=$conn->prepare("SELECT 1 FROM expertise_application_requesters WHERE application_id=? AND requester_user_id=? LIMIT 1");
    if(!$s) return true;
    $s->bind_param("ii",$app_id,$user_id);$s->execute();
    $found=(bool)$s->get_result()->fetch_row();$s->close();return $found;
}
function expertise_redirect(int $community_id): void {
    header("Location: expertise.php".($community_id>0?"?community_id=".$community_id:"")); exit;
}
try {
    if ($action === 'submit') {
        $domain_id=(int)($_POST['domain_id']??0);
        $level=(int)($_POST['requested_level']??0);
        if($community_id<1 || $domain_id<1 || $level<1 || $level>4 || !expertise_member($conn,$community_id,$user_id))
            throw new RuntimeException("اطلاعات درخواست یا عضویت جامعه معتبر نیست.");
        $d=$conn->prepare("SELECT id FROM expertise_domains WHERE id=? AND status='approved' LIMIT 1");
        $d->bind_param("i",$domain_id); $d->execute(); $valid=(bool)$d->get_result()->fetch_row(); $d->close();
        if(!$valid) throw new RuntimeException("حوزه تخصصی انتخاب‌شده تأیید نشده است.");
        $dup=$conn->prepare("SELECT id FROM expertise_applications WHERE target_user_id=? AND domain_id=? AND status='pending' AND decision_at IS NULL LIMIT 1");
        $dup->bind_param("ii",$user_id,$domain_id); $dup->execute(); $exists=(bool)$dup->get_result()->fetch_row(); $dup->close();
        if($exists) throw new RuntimeException("برای این حوزه یک درخواست در حال بررسی دارید.");
        $old=$conn->prepare("SELECT level, valid_until FROM expertise_competencies WHERE user_id=? AND domain_id=? LIMIT 1");
        $old->bind_param("ii",$user_id,$domain_id); $old->execute(); $prior=$old->get_result()->fetch_assoc(); $old->close();
        $type=(!$prior || strtotime($prior['valid_until'])<=time())?'initial':($level>(int)$prior['level']?'upgrade':'renewal');
        $code=bin2hex(random_bytes(16));
        // استفاده از ستون‌های مرجع در migration اصلی؛ بدون وابستگی به ستون‌های قدیمی user_id و level
        $s=$conn->prepare("INSERT INTO expertise_applications (application_code,community_id,domain_id,target_user_id,created_by,request_type,requested_level,status,expires_at) VALUES (?,?,?,?,?,?,?,'pending',NULL)");
        $s->bind_param("siiiisi",$code,$community_id,$domain_id,$user_id,$user_id,$type,$level);
        if(!$s->execute()) throw new RuntimeException("ثبت درخواست انجام نشد."); $new_id=(int)$s->insert_id; $s->close();
        $rq=$conn->prepare("INSERT INTO expertise_application_requesters (application_id,requester_user_id) VALUES (?,?)");
        $rq->bind_param("ii",$new_id,$user_id); $rq->execute(); $rq->close();
        $h=$conn->prepare("INSERT INTO expertise_application_history (application_id,actor_user_id,event_type,details) VALUES (?,?,'submitted',?)");
        $detail="درخواست سطح ".$level."؛ ابتدا سه ارزیابی مستقل انجام می‌شود و سپس مهلت رأی‌گیری ۷ روزه آغاز خواهد شد.";
        $h->bind_param("iis",$new_id,$user_id,$detail); $h->execute(); $h->close();
        expertise_redirect($community_id);
    }
    if ($action === 'evidence') {
        $title=trim((string)($_POST['title']??'')); $description=trim((string)($_POST['description']??''));
        $url=trim((string)($_POST['reference_url']??''));
        if($app_id<1 || $title==='' || $description==='') throw new RuntimeException("عنوان و شرح مدرک الزامی است.");
        if($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !preg_match('~^https?://~i',$url))) throw new RuntimeException("نشانی مرجع معتبر نیست.");
        $s=$conn->prepare("SELECT community_id FROM expertise_applications WHERE id=? AND target_user_id=? AND status='pending' AND decision_at IS NULL");
        $s->bind_param("ii",$app_id,$user_id); $s->execute(); $a=$s->get_result()->fetch_assoc(); $s->close();
        if(!$a || !expertise_member($conn,(int)$a['community_id'],$user_id)) throw new RuntimeException("درخواست در دسترس نیست.");
        $ev=$conn->prepare("INSERT INTO expertise_application_evidence (application_id,submitted_by,title,description,reference_url) VALUES (?,?,?,?,NULLIF(?,''))");
        $ev->bind_param("iisss",$app_id,$user_id,$title,$description,$url); $ev->execute(); $ev->close();
        expertise_redirect((int)$a['community_id']);
    }
    if ($action === 'vote') {
        $vote=(string)($_POST['vote']??'');
        if($app_id<1 || !in_array($vote,['yes','no'],true)) throw new RuntimeException("رأی معتبر نیست.");
        $conn->begin_transaction();
        $s=$conn->prepare("SELECT community_id,target_user_id,status,expires_at,decision_at FROM expertise_applications WHERE id=? FOR UPDATE");
        $s->bind_param("i",$app_id); $s->execute(); $a=$s->get_result()->fetch_assoc(); $s->close();
        if(!$a || (int)$a['community_id']<1 || $a['status']!=='pending' || $a['decision_at']!==null)
            throw new RuntimeException("درخواست بسته است.");
        if((int)$a['target_user_id']===$user_id || !expertise_member($conn,(int)$a['community_id'],$user_id) || expertise_is_requester($conn,$app_id,$user_id))
            throw new RuntimeException("متقاضی و درخواست‌دهندگان نمی‌توانند به این درخواست رأی دهند؛ رأی‌دهنده باید عضو مستقل جامعه باشد.");
        $rc=$conn->prepare("SELECT COUNT(*) total FROM expertise_application_reviews WHERE application_id=? AND status='submitted' AND conflict_of_interest='none'");
        $rc->bind_param("i",$app_id);$rc->execute();$reviewCount=(int)$rc->get_result()->fetch_assoc()['total'];$rc->close();
        if($reviewCount<3) throw new RuntimeException("رأی‌گیری نهایی پس از تکمیل دست‌کم سه ارزیابی مستقل آغاز می‌شود.");
        if(empty($a['expires_at']) || strtotime($a['expires_at'])<=time()) throw new RuntimeException("مهلت رأی‌گیری هنوز آغاز نشده یا پایان یافته است.");
        $v=$conn->prepare("INSERT INTO expertise_application_votes (application_id,voter_user_id,vote) VALUES (?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote),updated_at=CURRENT_TIMESTAMP");
        $v->bind_param("iis",$app_id,$user_id,$vote); $v->execute(); $v->close();
        $conn->commit(); expertise_redirect((int)$a['community_id']);
    }
    if ($action === 'review_join') {
        if($app_id<1) throw new RuntimeException("درخواست معتبر نیست.");
        $s=$conn->prepare("SELECT community_id,target_user_id,status,decision_at,expires_at FROM expertise_applications WHERE id=?");
        $s->bind_param("i",$app_id);$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();
        if(!$a || $a['status']!=='pending' || $a['decision_at']!==null) throw new RuntimeException("این درخواست برای ارزیابی فعال نیست.");
        if((int)$a['target_user_id']===$user_id || !expertise_member($conn,(int)$a['community_id'],$user_id) || expertise_is_requester($conn,$app_id,$user_id)) throw new RuntimeException("متقاضی یا درخواست‌دهنده نمی‌تواند ارزیاب باشد؛ ارزیاب باید عضو مستقل جامعه باشد.");
        $count=$conn->prepare("SELECT COUNT(*) total FROM expertise_application_reviews WHERE application_id=? AND status='submitted' AND conflict_of_interest='none'");
        $count->bind_param("i",$app_id);$count->execute();$completed=(int)$count->get_result()->fetch_assoc()['total'];$count->close();
        if($completed>=3) throw new RuntimeException("سه ارزیابی مستقل تکمیل شده و مرحله ارزیابی بسته است.");
        $s=$conn->prepare("INSERT IGNORE INTO expertise_application_reviews (application_id,reviewer_user_id,status) VALUES (?,?,'pending')");
        $s->bind_param("ii",$app_id,$user_id);$s->execute();$s->close();
        expertise_redirect((int)$a['community_id']);
    }
    if ($action === 'review_submit') {
        $scores=[];
        foreach(['theoretical_score','practical_score','achievements_score','professional_score'] as $field){
            $value=filter_var($_POST[$field]??null,FILTER_VALIDATE_INT);
            if($value===false || $value===null || $value<0 || $value>100) throw new RuntimeException("هر چهار امتیاز باید عددی بین صفر تا صد باشد.");
            $scores[]=$value;
        }
        $conflict=(string)($_POST['conflict_of_interest']??'none');
        if(!in_array($conflict,['none','declared'],true)) throw new RuntimeException("وضعیت تعارض منافع معتبر نیست.");
        $notes=trim((string)($_POST['review_notes']??''));
        if(mb_strlen($notes)>12000) throw new RuntimeException("توضیحات ارزیابی بیش از حد طولانی است.");
        $conn->begin_transaction();
        $s=$conn->prepare("SELECT a.community_id,a.target_user_id,a.status,a.decision_at,a.expires_at,r.status review_status FROM expertise_applications a JOIN expertise_application_reviews r ON r.application_id=a.id AND r.reviewer_user_id=? WHERE a.id=? FOR UPDATE");
        $s->bind_param("ii",$user_id,$app_id);$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();
        if(!$a || $a['status']!=='pending' || $a['decision_at']!==null || (int)$a['target_user_id']===$user_id || !expertise_member($conn,(int)$a['community_id'],$user_id) || expertise_is_requester($conn,$app_id,$user_id)) throw new RuntimeException("شما ارزیاب مجاز این درخواست نیستید.");
        if($a['review_status']==='submitted' || $a['review_status']==='recused') throw new RuntimeException("ارزیابی قبلاً ثبت شده یا ارزیاب کنار رفته است.");
        $done=$conn->prepare("SELECT COUNT(*) total FROM expertise_application_reviews WHERE application_id=? AND status='submitted' AND conflict_of_interest='none'");
        $done->bind_param("i",$app_id);$done->execute();$doneCount=(int)$done->get_result()->fetch_assoc()['total'];$done->close();
        if($doneCount>=3) throw new RuntimeException("سه ارزیابی مستقل تکمیل شده و مرحله ارزیابی بسته است.");
        if($conflict==='declared'){
            $u=$conn->prepare("UPDATE expertise_application_reviews SET conflict_of_interest='declared',status='recused',review_notes=?,submitted_at=NOW() WHERE application_id=? AND reviewer_user_id=? AND status='pending'");
            $u->bind_param("sii",$notes,$app_id,$user_id);$u->execute();$u->close();
        } else {
            $u=$conn->prepare("UPDATE expertise_application_reviews SET theoretical_score=?,practical_score=?,achievements_score=?,professional_score=?,conflict_of_interest='none',review_notes=?,status='submitted',submitted_at=NOW() WHERE application_id=? AND reviewer_user_id=? AND status='pending'");
            $u->bind_param("iiiisii",$scores[0],$scores[1],$scores[2],$scores[3],$notes,$app_id,$user_id);$u->execute();$u->close();
        }
        if($conflict==='none'){
            $done=$conn->prepare("SELECT COUNT(*) total FROM expertise_application_reviews WHERE application_id=? AND status='submitted' AND conflict_of_interest='none'");
            $done->bind_param("i",$app_id);$done->execute();$completed=(int)$done->get_result()->fetch_assoc()['total'];$done->close();
            if($completed===3){
                $deadline=$conn->prepare("UPDATE expertise_applications SET expires_at=DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id=? AND status='pending' AND decision_at IS NULL");
                $deadline->bind_param("i",$app_id);$deadline->execute();$deadline->close();
                $detail="سه ارزیابی مستقل تکمیل شد؛ مهلت رأی‌گیری عمومی هفت روزه آغاز شد.";
                $hist=$conn->prepare("INSERT INTO expertise_application_history (application_id,actor_user_id,event_type,details) VALUES (?,? ,'reviews_completed',?)");
                $hist->bind_param("iis",$app_id,$user_id,$detail);$hist->execute();$hist->close();
            }
        }
        $conn->commit();
        expertise_redirect((int)$a['community_id']);
    }
    throw new RuntimeException("عملیات ناشناخته است.");
} catch(Throwable $e) {
    if($conn->errno===0) { /* no-op */ }
    try { $conn->rollback(); } catch(Throwable $ignored) {}
    http_response_code(400); exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));
}
