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
$finalizer = file_get_contents(__DIR__ . '/../collective_knowledge_finalizer.php');
$ledger = file_get_contents(__DIR__ . '/../community_decision_ledger.php');

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
    is_string($finalizer) &&
    str_contains($finalizer, "COALESCE(t.topic_intent, 'decision') AS topic_intent") &&
    str_contains($finalizer, "'topic_intent' => $topic_intent") &&
    str_contains($finalizer, '"مسیر موضوع: " . $intent_label'),
    'topic intent is carried into final knowledge dossiers and decision snapshots'
);

topic_intent_contract_check(
    is_string($ledger) &&
    str_contains($ledger, 'topic_intent_snapshot') &&
    str_contains($ledger, "['knowledge', 'decision', 'law']"),
    'the independent decision ledger preserves the original topic track'
);

topic_intent_contract_check(
    is_string($law = file_get_contents(__DIR__ . '/../community_laws.php')) &&
    substr_count($law, "topic_intent='law'") >= 2 &&
    str_contains($law, "t.topic_intent = 'law'"),
    'only explicitly lawmaking topics and their ideas or accepted decisions can supply formal law provenance'
);

topic_intent_contract_check(
    str_contains($law, '$source_decision_id === null && $source_topic_id === null && $source_idea_id === null') &&
    str_contains($law, "community_laws_redirect(\$community_id, 'invalid_source')"),
    'a formal law draft cannot be created without traceable lawmaking provenance'
);


fwrite(STDOUT, "PASS: topic intent classification contract checks passed.\n");
