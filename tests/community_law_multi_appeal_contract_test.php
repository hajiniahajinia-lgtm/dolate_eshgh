<?php
declare(strict_types=1);

/** Multiple communities may file distinct appeals during the same published appeal window. */
$source=file_get_contents(__DIR__.'/../community_laws.php');
if($source===false){fwrite(STDERR,"FAIL: unable to read law workflow source\\n");exit(1);}
$start=strpos($source,"if (isset(\$_POST['submit_law_appeal']))");
$end=strpos($source,"if (isset(\$_POST['submit_law_appeal_assessment']))",$start===false?0:$start);
if($start===false||$end===false||$end<=$start){fwrite(STDERR,"FAIL: appeal submission handler boundary not found\\n");exit(1);}
$handler=substr($source,$start,$end-$start);
$checks=[
 'a second appeal remains admissible while the first appeal has paused enactment' =>
     strpos($handler,'!in_array($law[\'final_status\'],[\'appeal_period\',\'appeal_hold\'],true)')!==false,
 'the original appeal deadline still governs every additional appeal' =>
     strpos($handler,'empty($law[\'appeal_deadline\']) || strtotime($law[\'appeal_deadline\']) < time()')!==false,
 'submitting another appeal preserves the shared appeal hold' =>
     strpos($handler,"status IN ('appeal_period','appeal_hold')")!==false,
 'an already-held appeal case does not fail merely because its status was unchanged' =>
     strpos($handler,'if (!$hold->execute()) throw new Exception(\'appeal hold update failed\');')!==false,
 'each appeal keeps its own electorate and independent assessment record' =>
     strpos($handler,"INSERT IGNORE INTO community_law_appeal_electorate (appeal_id, user_id)")!==false &&
     strpos($handler,"INSERT INTO community_law_appeals")!==false,
];
$failed=0;
foreach($checks as $label=>$passed){echo ($passed?"PASS: ":"FAIL: ").$label.PHP_EOL;if(!$passed)$failed++;}
if($failed>0)exit(1);
echo "PASS: multi-community appeal window contract (".count($checks)." checks)\\n";
