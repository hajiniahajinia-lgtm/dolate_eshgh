<?php
declare(strict_types=1);

function topic_intent_contract_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$community = file_get_contents(__DIR__ . '/../community.php');
$handler = file_get_contents(__DIR__ . '/../add_topic.php');

topic_intent_contract_check(
    is_string($community) &&
    str_contains($community, 'name="topic_intent"') &&
    str_contains($community, 'value="decision"') &&
    str_contains($community, 'value="knowledge"') &&
    str_contains($community, 'value="law"'),
    'topic creation explicitly offers decision, knowledge, and lawmaking tracks'
);
topic_intent_contract_check(
    str_contains($community, 'هیچ ایده یا رأیی خودکار قانون نمی‌شود'),
    'the form explains that a topic or vote does not automatically create a law'
);
topic_intent_contract_check(
    is_string($handler) &&
    str_contains($handler, "['knowledge', 'decision', 'law']") &&
    str_contains($handler, '$topic_intent') &&
    str_contains($handler, 'topic_intent)'),
    'the server validates and persists the selected topic intent'
);
topic_intent_contract_check(
    str_contains($handler, '$topic_purpose = $topic_intent === \'knowledge\' ? \'knowledge\' : \'execution\';'),
    'legacy knowledge-library purpose remains compatible without treating law as enacted'
);

topic_intent_contract_check(
    is_string($law = file_get_contents(__DIR__ . '/../community_laws.php')) &&
    substr_count($law, "t.topic_intent IN ('decision','law')") >= 2,
    'only decision and lawmaking tracks can supply a formal accepted decision as law provenance'
);


fwrite(STDOUT, "PASS: topic intent classification contract checks passed.\n");
