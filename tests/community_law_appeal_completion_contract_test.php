<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../community_laws.php');
$amendment_source=file_get_contents(__DIR__.'/../community_law_amendment_helpers.php');
if($source===false || $amendment_source===false){
    fwrite(STDERR,"FAIL: unable to read law workflow sources".PHP_EOL);
    exit(1);
}
$appeal_start=strpos($source,"if (isset(\$_POST['submit_law_appeal']))");
$assessment_start=strpos($source,"if (isset(\$_POST['submit_law_appeal_assessment']))",$appeal_start===false?0:$appeal_start);
$enact_start=strpos($source,"if (isset(\$_POST['enact_law']))");
$assessment_end=strpos($source,"if (isset(\$_POST['enact_law']))",$assessment_start===false?0:$assessment_start);
if($appeal_start===false || $assessment_start===false || $enact_start===false || $assessment_end===false){
    fwrite(STDERR,"FAIL: appeal workflow boundaries not found".PHP_EOL);
    exit(1);
}
$appeal_handler=substr($source,$appeal_start,$assessment_start-$appeal_start);
$assessment_handler=substr($source,$assessment_start,$assessment_end-$assessment_start);
$enact_handler=substr($source,$enact_start);
$checks=[
 'appeal submission locks the law version and final-review case' =>
    strpos($appeal_handler,'fc.status AS final_status')!==false &&
    strpos($appeal_handler,'WHERE v.id=? FOR UPDATE')!==false,
 'appeal submission rejects requests after the appeal deadline' =>
    strpos($appeal_handler,"strtotime(\$law['appeal_deadline']) < time()")!==false,
 'appeal electorate requires at least three independent reviewers' =>
    strpos($appeal_handler,'$appeal_capacity < 3')!==false,
 'appeal submission places the final-review case on hold' =>
    strpos($appeal_handler,"SET status='appeal_hold'")!==false,
 'appeal assessments serialize on the shared final-review case' =>
    strpos($assessment_handler,'SELECT status FROM community_law_final_review_cases WHERE law_version_id=? FOR UPDATE')!==false,
 'one upheld appeal does not close other pending appeals' =>
    strpos($assessment_handler,'if ($open_count===0)')!==false &&
    strpos($assessment_handler,"status IN ('submitted','under_review')")!==false,
 'all appeals are considered before the law returns to revision' =>
    strpos($assessment_handler,"WHERE law_version_id=? AND status='upheld'")!==false &&
    strpos($assessment_handler,"SET status='needs_revision'")!==false,
 'the appeal period resumes only when no appeal is upheld' =>
    strpos($assessment_handler,"SET status='appeal_period'")!==false &&
    strpos($assessment_handler,'if ($upheld_count>0)')!==false,
 'ordinary enactment blocks unresolved and upheld appeals' =>
    strpos($enact_handler,"status IN ('submitted','under_review')")!==false &&
    strpos($enact_handler,"status='upheld'")!==false &&
    strpos($enact_handler,'enactment_appeals_pending')!==false,
 'amendment enactment independently blocks unresolved and upheld appeals' =>
    strpos($amendment_source,"SUM(status IN ('submitted','under_review')) AS unresolved")!==false &&
    strpos($amendment_source,"SUM(status='upheld') AS upheld")!==false &&
    strpos($amendment_source,'amendment appeals are unresolved')!==false
];
$failed=0;
foreach($checks as $label=>$passed){
    echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;
    if(!$passed)$failed++;
}
if($failed>0)exit(1);
echo 'PASS: appeal completion and enactment safeguards ('.count($checks).' checks)'.PHP_EOL;
