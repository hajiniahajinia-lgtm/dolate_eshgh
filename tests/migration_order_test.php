<?php
declare(strict_types=1);

$migrationsDir=__DIR__.'/../migrations';
$files=glob($migrationsDir.'/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.sql') ?: [];
$names=array_map('basename',$files);
sort($names,SORT_STRING);

$runner=file_get_contents(__DIR__.'/../migration_runner.php');
if(!is_string($runner) || strpos($runner,'$migration_dependencies')===false) {
    fwrite(STDERR,"FAIL: migration runner dependency map is missing\n");
    exit(1);
}

$dependencies=[
    '20261106_community_law_objections_schema_repair.sql'=>['20261107_community_law_framework.sql'],
    '20261107_community_law_framework.sql'=>['20261107_community_law_final_review_appeals.sql'],
    '20261107_community_charter_foundation.sql'=>['20261107_community_charter_amendments.sql'],
    '20261107_community_charter_amendments.sql'=>['20261110_community_charter_initial_adoption.sql'],
    '20261107_community_law_voting_rules.sql'=>['20261107_community_law_voting_rule_approval.sql'],
    '20261108_community_decision_ledger.sql'=>['20261108_community_decision_knowledge_link.sql'],
    '20261108_community_law_change_control.sql'=>['20261108_community_law_change_review.sql'],
    '20261108_community_law_change_review.sql'=>['20261108_community_law_change_voting.sql'],
    '20261108_community_law_change_voting.sql'=>['20261108_community_law_change_effects.sql'],
    '20261109_community_law_corrective_completion_reviews.sql'=>['20261109_community_law_corrective_completion_retries.sql'],
    '20261031_topic_expertise_proposals.sql'=>['20261031_topic_expertise_proposal_voting.sql']
];

$effectiveNames=$names;
foreach($dependencies as $prerequisite=>$dependents) {
    if(!in_array($prerequisite,$effectiveNames,true) ||
       strpos($runner,"'{$prerequisite}' => [")===false) {
        fwrite(STDERR,"FAIL: prerequisite migration missing from runner: {$prerequisite}\n");
        exit(1);
    }
    foreach($dependents as $dependent) {
        if(!in_array($dependent,$effectiveNames,true) || strpos($runner,"'{$dependent}'")===false) {
            fwrite(STDERR,"FAIL: dependent migration missing from runner: {$dependent}\n");
            exit(1);
        }
        $dependentIndex=array_search($dependent,$effectiveNames,true);
        array_splice($effectiveNames,$dependentIndex,1);
        $prerequisiteIndex=array_search($prerequisite,$effectiveNames,true);
        array_splice($effectiveNames,$prerequisiteIndex+1,0,[$dependent]);
    }
}

$assertBefore=function(string $first,string $second) use (&$effectiveNames): void {
    $a=array_search($first,$effectiveNames,true);
    $b=array_search($second,$effectiveNames,true);
    if($a===false || $b===false || $a >= $b) {
        fwrite(STDERR,"FAIL: invalid migration order: {$first} must precede {$second}\n");
        exit(1);
    }
};

$assertBefore('20261106_community_law_objections_schema_repair.sql','20261107_community_law_framework.sql');
$assertBefore('20261107_community_law_framework.sql','20261107_community_law_final_review_appeals.sql');
$assertBefore('20261107_community_charter_foundation.sql','20261107_community_charter_amendments.sql');
$assertBefore('20261107_community_charter_amendments.sql','20261110_community_charter_initial_adoption.sql');
$assertBefore('20261107_community_law_voting_rules.sql','20261107_community_law_voting_rule_approval.sql');
$assertBefore('20261108_community_decision_ledger.sql','20261108_community_decision_knowledge_link.sql');
$assertBefore('20261108_community_law_change_control.sql','20261108_community_law_change_review.sql');
$assertBefore('20261108_community_law_change_review.sql','20261108_community_law_change_voting.sql');
$assertBefore('20261108_community_law_change_voting.sql','20261108_community_law_change_effects.sql');
$assertBefore('20261109_community_law_corrective_completion_reviews.sql','20261109_community_law_corrective_completion_retries.sql');
$assertBefore('20261031_topic_expertise_proposals.sql','20261031_topic_expertise_proposal_voting.sql');

$requiredOrder=[
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
    $position=array_search($name,$effectiveNames,true);
    if($position===false) {
        fwrite(STDERR,"FAIL: required migration missing: {$name}\n");
        exit(1);
    }
    $positions[]=$position;
}
for($i=1;$i<count($positions);$i++) {
    if($positions[$i] <= $positions[$i-1]) {
        fwrite(STDERR,"FAIL: parent-child law workflow migrations are out of order\n");
        exit(1);
    }
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

echo "PASS: migration dependency ordering covers law lifecycle, decision ledger, charter amendments and retry workflows\n";
