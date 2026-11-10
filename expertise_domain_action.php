<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit("ابتدا وارد شوید."); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit("درخواست نامعتبر است."); }
$user_id=(int)$_SESSION['user_id'];
$csrf=(string)($_POST['csrf_token']??'');
if(empty($_SESSION['community_duty_csrf']) || $csrf==='' || !hash_equals($_SESSION['community_duty_csrf'],$csrf)){
 http_response_code(403);exit("نشست معتبر نیست؛ صفحه را تازه‌سازی کنید.");
}
$action=(string)($_POST['action']??'');
$community_id=(int)($_POST['community_id']??0);
function ed_member(mysqli $conn,int $community_id,int $user_id):bool{
 $s=$conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
 if(!$s)return false;$s->bind_param("ii",$community_id,$user_id);$s->execute();$ok=(bool)$s->get_result()->fetch_row();$s->close();return $ok;
}
function ed_redirect(int $community_id):void{
 header("Location: expertise.php".($community_id>0?"?community_id=".$community_id:""));exit;
}
try{
 if($community_id<1 || !ed_member($conn,$community_id,$user_id))throw new RuntimeException("برای این کار باید عضو همان جامعه باشید.");
 if($action==='propose'){
  $name=trim((string)($_POST['name']??''));$description=trim((string)($_POST['description']??''));
  if($name==='' || mb_strlen($name)>190 || $description==='' || mb_strlen($description)>5000)throw new RuntimeException("نام حوزه و توضیح معتبر الزامی است.");
  $dupe=$conn->prepare("SELECT id FROM expertise_domains WHERE proposed_in_community_id=? AND LOWER(name)=LOWER(?) AND status IN ('pending','approved') LIMIT 1");
  $dupe->bind_param("is",$community_id,$name);$dupe->execute();$exists=(bool)$dupe->get_result()->fetch_row();$dupe->close();
  if($exists)throw new RuntimeException("حوزه‌ای با این نام در این جامعه در حال بررسی است یا قبلاً تأیید شده است.");
  $s=$conn->prepare("INSERT INTO expertise_domains (name,description,status,created_by,proposed_in_community_id) VALUES (?,?, 'pending',?,?)");
  $s->bind_param("ssii",$name,$description,$user_id,$community_id);$s->execute();$domain_id=(int)$s->insert_id;$s->close();
  // پیشنهاددهنده نیز می‌تواند در رأی‌گیری شرکت کند.
  ed_redirect($community_id);
 }
 if($action==='vote'){
  $domain_id=(int)($_POST['domain_id']??0);$vote=(string)($_POST['vote']??'');
  if($domain_id<1 || !in_array($vote,['yes','no'],true))throw new RuntimeException("رأی معتبر نیست.");
  $conn->begin_transaction();
  $s=$conn->prepare("SELECT id,status,proposed_in_community_id,created_at,decided_at FROM expertise_domains WHERE id=? FOR UPDATE");
  $s->bind_param("i",$domain_id);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();
  if(!$d || (int)$d['proposed_in_community_id']!==$community_id || $d['status']!=='pending' || $d['decided_at']!==null || strtotime($d['created_at'].' +7 days')<=time())
   throw new RuntimeException("این حوزه در رأی‌گیری فعال نیست یا مهلت آن پایان یافته است.");
  $s=$conn->prepare("INSERT INTO expertise_domain_votes (domain_id,community_id,user_id,vote) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote),updated_at=CURRENT_TIMESTAMP");
  $s->bind_param("iiis",$domain_id,$community_id,$user_id,$vote);$s->execute();$s->close();
  $conn->commit();ed_redirect($community_id);
 }
 throw new RuntimeException("عملیات ناشناخته است.");
}catch(Throwable $e){
 try{$conn->rollback();}catch(Throwable $ignored){}
 http_response_code(400);exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));
}
