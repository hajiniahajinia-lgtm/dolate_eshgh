<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../community_laws.php');
if($source===false){fwrite(STDERR,"FAIL: unable to read community law UI source".PHP_EOL);exit(1);}
$checks=[
 'completion review schema is part of UI readiness gate' =>
    strpos($source,"'community_law_outcome_corrective_completion_reviews'")!==false &&
    strpos($source,"fetch_assoc()['total']===8")!==false,
 'completion review history is loaded for displayed corrective actions' =>
    strpos($source,"FROM community_law_outcome_corrective_completion_reviews")!==false &&
    strpos($source,"$"."outcome_completion_reviews_by_action")!==false,
 'completion status is displayed with Persian labels' =>
    strpos($source,"'pending'=>'در انتظار بررسی مستقل'")!==false &&
    strpos($source,"'verified'=>'تکمیل با بررسی مستقل تأیید شد'")!==false &&
    strpos($source,"'rejected'=>'تکمیل در بررسی مستقل تأیید نشد")!==false,
 'independent completion review form is connected to its action handler' =>
    strpos($source,'name="verify_corrective_completion"')!==false &&
    strpos($source,'name="completion_verdict"')!==false &&
    strpos($source,'name="completion_rationale"')!==false,
 'review eligibility excludes report and reassessment reviewers and conflicted roles' =>
    strpos($source,"$"."has_prior_review_conflict")!==false &&
    strpos($source,"$"."corrective_action['created_by']!==$user_id")!==false &&
    strpos($source,"$"."corrective_action['responsible_user_id']!==$user_id")!==false,
 'completion claims are not presented as independently verified' =>
    strpos($source,"'completion_claimed'=>'در انتظار تأیید مستقل'")!==false &&
    strpos($source,"'completion_status'=>'pending'")===false
];
$failed=0;
foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed>0)exit(1);
echo 'PASS: corrective completion UI contract ('.count($checks).' checks)'.PHP_EOL;
