<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../community_laws.php');
if($source===false){fwrite(STDERR,"FAIL: unable to read community law UI source".PHP_EOL);exit(1);}
$checks=[
 'authority assessment verdicts have Persian labels' =>
    strpos($source,"'confirm' => 'با صلاحیت و دامنه اختیار سازگار است'")!==false &&
    strpos($source,"'dispute' => 'از حدود صلاحیت فراتر می‌رود'")!==false &&
    strpos($source,"'unclear' => 'اطلاعات برای تشخیص کافی نیست'")!==false,
 'precedent compatibility verdicts have Persian labels' =>
    strpos($source,"'compatible' => 'با قانون مرجع تعارض ندارد'")!==false &&
    strpos($source,"'conflict' => 'با قانون مرجع تعارض دارد'")!==false &&
    strpos($source,"'unclear' => 'اطلاعات برای تشخیص تعارض کافی نیست'")!==false,
 'community law objection statuses have Persian labels' =>
    strpos($source,"'pending' => 'ثبت‌شده؛ در انتظار بررسی'")!==false &&
    strpos($source,"'under_review' => 'در حال رسیدگی'")!==false &&
    strpos($source,"'upheld' => 'اعتراض وارد تشخیص داده شد'")!==false &&
    strpos($source,"'dismissed' => 'اعتراض وارد تشخیص داده نشد'")!==false &&
    strpos($source,"'withdrawn' => 'اعتراض پس گرفته شد'")!==false,
 'raw authority and objection status codes are not printed directly' =>
    strpos($source,"community_laws_escape($"."authority_assessment['authority_verdict'])")===false &&
    strpos($source,"community_laws_escape($"."precedent_assessment['compatibility_verdict'])")===false &&
    strpos($source,"community_laws_escape($"."objection['status'])")===false
];
$failed=0;
foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed>0)exit(1);
echo 'PASS: community law Persian UI labels contract ('.count($checks).' checks)'.PHP_EOL;
