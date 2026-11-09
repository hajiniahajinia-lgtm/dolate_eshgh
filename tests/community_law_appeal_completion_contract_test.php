<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../community_laws.php');
if($source===false){fwrite(STDERR,"FAIL: unable to read law workflow source".PHP_EOL);exit(1);}
$start=strpos($source,"if (isset(\$_POST['submit_law_appeal_assessment']))");
$end=strpos($source,"if (isset(\$_POST['enact_law']))",$start===false?0:$start);
if($start===false||$end===false||$end<=$start){fwrite(STDERR,"FAIL: appeal assessment handler boundary not found".PHP_EOL);exit(1);}
$handler=substr($source,$start,$end-$start);
$checks=[
 'all appeal assessments serialize on the shared final-review case' =>
    strpos($handler,'SELECT status FROM community_law_final_review_cases WHERE law_version_id=? FOR UPDATE')!==false,
 'an upheld appeal does not end the review window while other appeals remain open' =>
    strpos($handler,"if ($"."open_count===0)")!==false &&
    strpos($handler,"status IN ('submitted','under_review')")!==false,
 'the final result considers every upheld appeal, not only the last submitted assessment' =>
    strpos($handler,"WHERE law_version_id=? AND status='upheld'")!==false &&
    strpos($handler,"if ($"."upheld_count>0)")!==false,
 'the law is returned to revision only after all appeals are resolved' =>
    strpos($handler,"SET status='needs_revision'")!==false &&
    strpos($handler,"if ($"."open_count===0)")!==false,
 'the appeal period resumes only when no unresolved or upheld appeal remains' =>
    strpos($handler,"SET status='appeal_period'")!==false &&
    strpos($handler,"if ($"."upheld_count>0)")!==false,
 'amendment and ordinary law paths both remain supported' =>
    strpos($handler,'$is_amendment_appeal_assessment')!==false &&
    strpos($handler,"UPDATE community_law_amendment_cases")!==false &&
    strpos($handler,"UPDATE community_laws SET status='pending_final_review'")!==false
];
$failed=0;
foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed>0)exit(1);
echo 'PASS: concurrent multi-appeal completion contract ('.count($checks).' checks)'.PHP_EOL;
