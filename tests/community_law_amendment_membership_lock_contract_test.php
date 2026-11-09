<?php
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../community_law_amendment_helpers.php');
if(!is_string($source)){fwrite(STDERR,"FAIL: unable to read amendment helpers\n");exit(1);}
function amendment_test_section(string $source,string $function_name): string {
    $start=strpos($source,'function '.$function_name.'(');
    if($start===false)return '';
    $next=strpos($source,"\nif (!function_exists(",$start+10);
    return substr($source,$start,$next===false?null:$next-$start);
}
$submit=amendment_test_section($source,'community_law_submit_amendment_for_vote');
$review=amendment_test_section($source,'community_law_submit_amendment_parent_review');
$helper_start=strpos($source,'function community_law_amendment_lock_member(');
$helper_end=$helper_start===false?false:strpos($source,"\nif (!function_exists(",$helper_start+10);
$helper=$helper_start===false?'':substr($source,$helper_start,$helper_end===false?null:$helper_end-$helper_start);
$checks=[];
$checks['shared helper locks the exact member row']=$helper!=='' && strpos($helper,'FROM community_members WHERE community_id=? AND user_id=? LIMIT 1 FOR UPDATE')!==false;
$checks['amendment submission calls the locking helper after starting its transaction']=strpos($submit,'$conn->begin_transaction()')!==false && strpos($submit,'community_law_amendment_lock_member($conn,$community_id,$user_id)')!==false && strpos($submit,'community_law_amendment_lock_member($conn,$community_id,$user_id)')>strpos($submit,'$conn->begin_transaction()');
$checks['parent compatibility review calls the locking helper after starting its transaction']=strpos($review,'$conn->begin_transaction()')!==false && strpos($review,'community_law_amendment_lock_member($conn,$parent_community_id,$user_id)')!==false && strpos($review,'community_law_amendment_lock_member($conn,$parent_community_id,$user_id)')>strpos($review,'$conn->begin_transaction()');
$checks['neither action path uses an unlocked membership query']=strpos($submit,'FROM community_members')===false && strpos($review,'FROM community_members')===false;
$failed=0;foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed)exit(1);echo 'PASS: amendment membership locking contract ('.count($checks).' checks)'.PHP_EOL;
