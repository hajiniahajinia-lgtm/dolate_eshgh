<?php
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../community_law_conformity_helpers.php');
if(!is_string($source)){fwrite(STDERR,"FAIL: unable to read conformity helpers\n");exit(1);}
function conformity_test_section(string $source,string $function_name): string {
    $start=strpos($source,'function '.$function_name.'(');
    if($start===false)return '';
    $next=strpos($source,"\nif (!function_exists(",$start+10);
    return substr($source,$start,$next===false?null:$next-$start);
}
$add=conformity_test_section($source,'community_law_conformity_add_conflict');
$review=conformity_test_section($source,'community_law_conformity_review_conflict');
$expected='if (!$conn->commit()) throw new RuntimeException(\'conformity transaction commit failed\');';
$checks=[];
$checks['conflict identification checks transaction commit result']=strpos($add,$expected)!==false;
$checks['conflict review checks transaction commit result']=strpos($review,$expected)!==false;
$checks['both operations roll back after any transaction failure']=strpos($add,'$conn->rollback();')!==false && strpos($review,'$conn->rollback();')!==false;
$failed=0;foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed)exit(1);echo 'PASS: conformity transaction completion contract ('.count($checks).' checks)'.PHP_EOL;
