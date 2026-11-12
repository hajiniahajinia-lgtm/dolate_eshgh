<?php
declare(strict_types=1);
$helpers=file_get_contents(__DIR__.'/../community_law_helpers.php');
$page=file_get_contents(__DIR__.'/../community_laws.php');
$migration=file_get_contents(__DIR__.'/../migrations/20261112_parent_law_enactment_conformity.sql');
if(!is_string($helpers)||!is_string($page)||!is_string($migration)){fwrite(STDERR,"FAIL: unable to read conformity sources\n");exit(1);}
$start=strpos($helpers,'function community_law_register_parent_law_enactment_conformity_reviews(');
$end=$start===false?false:strpos($helpers,"\nif (!function_exists(",$start+20);
$helper=($start!==false&&$end!==false)?substr($helpers,$start,$end-$start):'';
$enact_start=strpos($page,"if (isset($_POST['enact_law']))");
$enact_end=$enact_start===false?false:strpos($page,"if (isset($_POST['create_law_revision']))",$enact_start);
$enactment=($enact_start!==false&&$enact_end!==false)?substr($page,$enact_start,$enact_end-$enact_start):'';
$checks=[];
$checks['enactment registers descendant conformity cases before commit']=strpos($enactment,'community_law_register_parent_law_enactment_conformity_reviews(')!==false&&strpos($enactment,'parent law enactment conformity registration failed')!==false;
$checks['registration validates the approved current parent-law version']=strpos($helper,"l.status='approved'")!==false&&strpos($helper,"v.status='approved'")!==false&&strpos($helper,'l.current_version_id')!==false;
$checks['only scoped descendants of the law origin are considered']=strpos($helper,'community_law_scope_ids_for_parent_map(')!==false&&strpos($helper,'community_law_is_descendant_or_same(')!==false;
$checks['only active approved local laws are queued']=strpos($helper,"l.status='approved'")!==false&&strpos($helper,"v.status='approved'")!==false&&strpos($helper,'l.repealed_at IS NULL')!==false;
$checks['cases identify parent-law enactment trigger']=strpos($helper,"'parent_law_enacted'")!==false&&strpos($migration,"'parent_law_enacted'")!==false;
$checks['case, event, and law history are recorded']=strpos($helper,'INSERT IGNORE INTO community_law_conformity_cases')!==false&&strpos($helper,'INSERT INTO community_law_conformity_events')!==false&&strpos($helper,'community_law_record_history(')!==false;
$checks['helper participates in the caller transaction without independent commit']=strpos($helper,'$conn->commit()')===false&&strpos($helper,'$conn->begin_transaction()')===false;
$failed=0;foreach($checks as $label=>$passed){echo ($passed?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$passed)$failed++;}
if($failed)exit(1);echo 'PASS: parent-law enactment conformity contract ('.count($checks).' checks)'.PHP_EOL;
