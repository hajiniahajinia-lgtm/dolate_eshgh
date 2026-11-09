<?php
declare(strict_types=1);

$migrationsDir=__DIR__.'/../migrations';
$files=glob($migrationsDir.'/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.sql') ?: [];
$names=array_map('basename',$files);
sort($names,SORT_STRING);

$runner=file_get_contents($migrationsDir.'/auto_apply.php');
if(!is_string($runner) || strpos($runner,'$migration_dependencies')===false) {
    fwrite(STDERR,"FAIL: automatic migration runner must declare explicit prerequisite ordering\\n");
    exit(1);
}
$effectiveNames=$names;
$ledgerIndex=array_search('20261108_community_decision_ledger.sql',$effectiveNames,true);
$knowledgeIndex=array_search('20261108_community_decision_knowledge_link.sql',$effectiveNames,true);
if($ledgerIndex===false || $knowledgeIndex===false) {
    fwrite(STDERR,"FAIL: decision ledger migrations are missing\\n");
    exit(1);
}
$knowledgeName=$effectiveNames[$knowledgeIndex];
array_splice($effectiveNames,$knowledgeIndex,1);
$ledgerIndex=array_search('20261108_community_decision_ledger.sql',$effectiveNames,true);
array_splice($effectiveNames,$ledgerIndex+1,0,[$knowledgeName]);

$requiredOrder=[
    '20261106_community_law_objections_schema_repair.sql',
    '20261107_community_law_framework.sql',
    '20261108_community_law_conformity_workflow.sql',
    '20261108_community_law_decision_origin.sql',
    '20261108_community_law_z1_amendment_00_workflow.sql',
    '20261108_community_law_z1_amendment_10_submission.sql',
    '20261108_community_law_z1_amendment_20_voting.sql',
    '20261108_community_law_z1_amendment_30_parent_review.sql',
    '20261108_community_law_z2_scope_notices.sql',
    '20261108_community_law_z3_version_notices.sql',
    '20261109_community_law_amendment_parent_review_rounds.sql',
    '20261109_community_law_conformity_review_rounds.sql'
];
$positions=[];
foreach($requiredOrder as $name) {
    $position=array_search($name,$names,true);
    if($position===false) {
        fwrite(STDERR,"FAIL: required migration missing: {$name}\n");
        exit(1);
    }
    $positions[]=$position;
}
for($i=1;$i<count($positions);$i++) {
    if($positions[$i]<=$positions[$i-1]) {
        fwrite(STDERR,"FAIL: law and amendment migrations are not ordered after their prerequisites\n");
        exit(1);
    }
}
$changeControl=array_search('20261108_community_law_change_control.sql',$names,true);
$changeEffects=array_search('20261108_community_law_change_effects.sql',$names,true);
$changeReview=array_search('20261108_community_law_change_review.sql',$names,true);
$changeVoting=array_search('20261108_community_law_change_voting.sql',$names,true);
$decisionLedger=array_search('20261108_community_decision_ledger.sql',$effectiveNames,true);
$decisionOrigin=array_search('20261108_community_law_decision_origin.sql',$effectiveNames,true);
$decisionKnowledge=array_search('20261108_community_decision_knowledge_link.sql',$effectiveNames,true);
if($changeControl===false || $changeEffects===false || $changeReview===false || $changeVoting===false ||
   !($changeControl<$changeEffects && $changeEffects<$changeReview && $changeReview<$changeVoting)) {
    fwrite(STDERR,"FAIL: law change schema migrations must follow their lexical dependency order\n");
    exit(1);
}
if($decisionLedger===false || $decisionOrigin===false || $decisionKnowledge===false ||
   $decisionLedger>=$decisionOrigin || $decisionLedger>=$decisionKnowledge) {
    fwrite(STDERR,"FAIL: law origin and knowledge links must follow the decision ledger\n");
    exit(1);
}
$obsolete=[
    '20261108_community_law_amendment_workflow.sql',
    '20261108_community_law_amendment_submission.sql',
    '20261108_community_law_amendment_voting.sql',
    '20261108_community_law_amendment_parent_review.sql'
];
foreach($obsolete as $name) {
    if(in_array($name,$names,true)) {
        fwrite(STDERR,"FAIL: obsolete unordered migration remains: {$name}\n");
        exit(1);
    }
}
echo "PASS: schema repair, decision ledger, law change and parent-child amendment migrations follow dependency order\n";
