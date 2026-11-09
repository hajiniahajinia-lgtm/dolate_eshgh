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

$appeal_start=strpos($source,"if (isset(\\$_POST['submit_law_appeal']))");
$assessment_start=strpos($source,"if (isset(\\$_POST['submit_law_appeal_assessment']))",$appeal_start===false?0:$appeal_start);
$enact_start=strpos($source,"if (isset(\\$_POST['enact_law']))");
$appeal_handler=$appeal_start!==false&&$assessment_start!==false?substr($source,$appeal_start,$assessment_start-$appeal_start):'';
$enact_handler=$enact_start!==false?substr($source,$enact_start):'';
$amendment_source=file_get_contents(__DIR__.'/../community_law_amendment_helpers.php');
$checks += [
 'appeal submission serializes against enactment on the final-review row' =>
    strpos($appeal_handler,'fc.status AS final_status')!==false &&
    strpos($appeal_handler,'WHERE v.id=? FOR UPDATE')!==false,
 'appeal submission is rejected after the published deadline' =>
    strpos($appeal_handler,"strtotime($"."law['appeal_deadline']) < time()")!==false,
 'appeal electorate requires at least three eligible independent reviewers' =>
    strpos($appeal_handler,'
foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed>0)exit(1);
echo 'PASS: concurrent multi-appeal completion contract ('.count($checks).' checks)'.PHP_EOL;
."appeal_capacity < 3")!==false,
 'an accepted appeal atomically places the final-review case on hold' =>
    strpos($appeal_handler,"SET status='appeal_hold'")!==false &&
    strpos($appeal_handler,'
foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed>0)exit(1);
echo 'PASS: concurrent multi-appeal completion contract ('.count($checks).' checks)'.PHP_EOL;
."conn->commit()")!==false,
 'ordinary law enactment blocks unresolved and upheld appeals' =>
    strpos($enact_handler,"status IN ('submitted','under_review')")!==false &&
    strpos($enact_handler,"status='upheld'")!==false &&
    strpos($enact_handler,"enactment_appeals_pending")!==false,
 'amendment enactment independently blocks unresolved and upheld appeals' =>
    $amendment_source!==false &&
    strpos($amendment_source,"SUM(status IN ('submitted','under_review')) AS unresolved")!==false &&
    strpos($amendment_source,"SUM(status='upheld') AS upheld")!==false &&
    strpos($amendment_source,'amendment appeals are unresolved')!==false,
 'the enactment control is shown only after deadline and without open or upheld appeals' =>
    strpos($source,"(int)$"."final_law['unresolved_appeals'] === 0")!==false &&
    strpos($source,"(int)$"."final_law['upheld_appeals'] === 0")!==false
];

$failed=0;
foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed>0)exit(1);
echo 'PASS: concurrent multi-appeal completion contract ('.count($checks).' checks)'.PHP_EOL;
