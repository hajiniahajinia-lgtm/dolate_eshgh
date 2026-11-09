<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../community_laws.php');
if($source===false){fwrite(STDERR,"FAIL: unable to read law workflow source".PHP_EOL);exit(1);}
$checks=[
 'appeal workflow statuses are presented in Persian' =>
    strpos($source,"'submitted' => 'ثبت‌شده؛ در انتظار آغاز رسیدگی'")!==false &&
    strpos($source,"'under_review' => 'در حال رسیدگی'")!==false &&
    strpos($source,"'upheld' => 'اعتراض وارد تشخیص داده شد'")!==false &&
    strpos($source,"'dismissed' => 'اعتراض وارد تشخیص داده نشد'")!==false,
 'appeal grounds are presented in Persian' =>
    strpos($source,"$"."appeal_type_labels")!==false &&
    strpos($source,"'environmental_harm' => 'آسیب زیست‌محیطی'")!==false &&
    strpos($source,"'future_generations' => 'آسیب به نسل‌های آینده یا سایر جانداران'")!==false,
 'review verdicts are presented in Persian' =>
    strpos($source,"$"."appeal_verdict_labels")!==false &&
    strpos($source,"'unclear' => 'شواهد برای تصمیم قطعی کافی نیست'")!==false,
 'the interface no longer exposes raw appeal status and verdict codes' =>
    strpos($source,"community_laws_escape($"."appeal['appeal_status'])")===false &&
    strpos($source,"community_laws_escape($"."appeal_assessment['verdict'])")===false,
 'each appeal retains its own reviewer progress count' =>
    strpos($source,"(int)$"."appeal['assessment_count']")!==false &&
    strpos($source,"(int)$"."appeal['reviewer_quorum']")!==false
];
$failed=0;
foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed>0)exit(1);
echo 'PASS: Persian appeal tracking interface contract ('.count($checks).' checks)'.PHP_EOL;
