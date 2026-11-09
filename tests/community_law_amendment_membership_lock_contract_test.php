<?php
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../community_law_amendment_helpers.php');
if(!is_string($source)){fwrite(STDERR,"FAIL: unable to read amendment helpers\n");exit(1);}
$checks=[];
$checks['membership helper locks the exact community-member row']=strpos($source,'function community_law_amendment_lock_member(')!==false && strpos($source,'FROM community_members WHERE community_id=? AND user_id=? LIMIT 1 FOR UPDATE')!==false;
$submit=strpos($source,'function community_law_submit_amendment_for_vote(');$review=strpos($source,'function community_law_submit_amendment_parent_review(');
$st=$submit===false?false:strpos($source,'$conn->begin_transaction()',$submit);$sl=$submit===false?false:strpos($source,'community_law_amendment_lock_member(',$submit);
$rt=$review===false?false:strpos($source,'$conn->begin_transaction()',$review);$rl=$review===false?false:strpos($source,'community_law_amendment_lock_member(',$review);
$checks['amendment submission locks membership inside its transaction']=$st!==false&&$sl!==false&&$st<$sl;
$checks['parent compatibility review locks membership inside its transaction']=$rt!==false&&$rl!==false&&$rt<$rl;
$checks['both paths use the shared locking helper']=substr_count($source,'community_law_amendment_lock_member(')===3;
$failed=0;foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed)exit(1);echo 'PASS: amendment membership locking contract ('.count($checks).' checks)'.PHP_EOL;
