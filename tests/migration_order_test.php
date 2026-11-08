<?php
declare(strict_types=1);

$migrationsDir=__DIR__.'/../migrations';
$files=glob($migrationsDir.'/20261108_community_law_*.sql') ?: [];
$names=array_map('basename',$files);
sort($names,SORT_STRING);

$required=[
    '20261108_community_law_conformity_workflow.sql',
    '20261108_community_law_decision_origin.sql',
    '20261108_community_law_z1_amendment_00_workflow.sql',
    '20261108_community_law_z1_amendment_10_submission.sql',
    '20261108_community_law_z1_amendment_20_voting.sql',
    '20261108_community_law_z1_amendment_30_parent_review.sql'
];
$positions=[];
foreach($required as $name) {
    $position=array_search($name,$names,true);
    if($position===false) {
        fwrite(STDERR,"FAIL: required migration missing: {$name}\n");
        exit(1);
    }
    $positions[]=$position;
}
for($i=1;$i<count($positions);$i++) {
    if($positions[$i]<=$positions[$i-1]) {
        fwrite(STDERR,"FAIL: amendment migrations are not ordered after their prerequisites\n");
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
echo "PASS: amendment migrations execute after conformity and decision prerequisites in dependency order\n";
