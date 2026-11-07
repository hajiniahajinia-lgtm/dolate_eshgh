<?php
require_once __DIR__ . '/../community_law_helpers.php';

$checks = 0;
function check_charter_amendment(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$assessments = [];
for ($i = 1; $i <= 5; $i++) {
    $assessments[] = [
        'reviewer_user_id' => $i,
        'verdict' => $i <= 4 ? 'supports' : 'unclear',
        'rights_protection' => 'maintained',
        'equality_and_non_discrimination' => 'improved',
        'living_beings_and_environment' => 'maintained',
        'power_and_accountability' => 'maintained',
        'future_generations' => 'maintained'
    ];
}
check_charter_amendment(
    community_charter_amendment_reviewer_result($assessments) === 'approved',
    'four of five independent reviewers can approve a fully safeguarded proposal'
);
check_charter_amendment(
    community_charter_amendment_reviewer_result(array_slice($assessments, 0, 4)) === 'pending',
    'fewer than five reviewers cannot approve'
);

$conflict = $assessments;
$conflict[0]['verdict'] = 'conflict';
check_charter_amendment(
    community_charter_amendment_reviewer_result($conflict) === 'rejected',
    'a recorded charter conflict blocks the amendment'
);

$weakening = $assessments;
$weakening[0]['rights_protection'] = 'weakened';
check_charter_amendment(
    community_charter_amendment_reviewer_result($weakening) === 'rejected',
    'weakening fundamental rights blocks the amendment'
);

$duplicate = $assessments;
$duplicate[4]['reviewer_user_id'] = 4;
check_charter_amendment(
    community_charter_amendment_reviewer_result($duplicate) === 'pending',
    'duplicate reviewer identities are not counted as independent'
);

check_charter_amendment(
    community_charter_amendment_vote_result(90, 60, 10, 0) === 'approved',
    'two-thirds turnout and three-quarters approval pass'
);
check_charter_amendment(
    community_charter_amendment_vote_result(90, 50, 20, 0) === 'rejected',
    'sufficient turnout but less than three-quarters approval fails'
);
check_charter_amendment(
    community_charter_amendment_vote_result(90, 40, 10, 0) === 'no_quorum',
    'insufficient participation cannot approve'
);
check_charter_amendment(
    community_charter_amendment_vote_result(90, 55, 10, 25) === 'approved',
    'abstentions count toward turnout but not the approval ratio'
);

check_charter_amendment(
    community_charter_amendment_items_are_valid([[
        'action' => 'replace', 'target_article_id' => 7, 'article_code' => 'C-07',
        'proposed_title' => 'عنوان اصلاحی', 'proposed_text' => 'متن اصلاحی',
        'principle_group' => 'environment', 'is_core' => 1
    ]]),
    'a core article may be amended through enhanced review'
);
check_charter_amendment(
    !community_charter_amendment_items_are_valid([[
        'action' => 'remove', 'target_article_id' => 7, 'article_code' => 'C-07',
        'is_core' => 1
    ]]),
    'a core article cannot be removed'
);
check_charter_amendment(
    community_charter_amendment_items_are_valid([[
        'action' => 'remove', 'target_article_id' => 8, 'article_code' => 'C-08',
        'is_core' => 0
    ]]),
    'a non-core article can be proposed for removal'
);
check_charter_amendment(
    !community_charter_amendment_items_are_valid([
        ['action' => 'remove', 'target_article_id' => 8, 'article_code' => 'C-08', 'is_core' => 0],
        ['action' => 'replace', 'target_article_id' => 8, 'article_code' => 'C-08',
         'proposed_title' => 'تکراری', 'proposed_text' => 'متن', 'principle_group' => 'environment']
    ]),
    'duplicate target articles are rejected'
);

fwrite(STDOUT, "OK: {$checks} charter amendment checks passed.\n");
