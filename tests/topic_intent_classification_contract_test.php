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
$archive = file_get_contents(__DIR__ . '/../archived_topics.php');
$library = file_get_contents(__DIR__ . '/../knowledge_library.php');
$community = file_get_contents(__DIR__ . '/../community.php');

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
    str_contains($finalizer, "'topic_intent' => \$topic_intent") &&
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
    is_string($archive) &&
    str_contains($archive, "COALESCE(t.topic_intent, 'decision') AS topic_intent") &&
    str_contains($archive, 'name="topic_intent"') &&
    str_contains($archive, "'topic_intent'"),
    'the archive exposes a filter and visible label for each topic intent'
);

topic_intent_contract_check(
    is_string($library) &&
    str_contains($library, 'archived_topics.php?community_id=<?= (int)$entry_decision_link[\'community_id\'] ?>#decision-') &&
    str_contains($library, 'source_topic_intent') &&
    str_contains($library, 'مسیر مبدأ:') &&
    str_contains($library, "['knowledge'=>'دانش‌محور','decision'=>'تصمیم‌گیری','law'=>'قانون‌گذاری']"),
    'knowledge entries retain and display the intent of their source topic'
);

topic_intent_contract_check(
    is_string($community) &&
    str_contains($community, 'name="topic_intent"') &&
    str_contains($community, "COALESCE(t.topic_intent, 'decision')=?") &&
    str_contains($community, 'مسیر موضوع:'),
    'active topic list supports filtering and labels the three topic tracks'
);

topic_intent_contract_check(
    is_string($archive) &&
    str_contains($archive, "v.id AS version_id, v.status AS version_status") &&
    str_contains($archive, 'مشاهدهٔ پروندهٔ رسمی قانون و مراحل آن') &&
    str_contains($archive, 'community_laws.php?community_id=') &&
    str_contains($archive, 'id="decision-<?= (int)$row[\'decision_link\'][\'decision_id\'] ?>"') &&
    str_contains($archive, 'intent_progress') &&
    str_contains($archive, 'community_law_versions') &&
    str_contains($archive, 'رأی موضوع فقط منشأ پیشنهاد است'),
    'archive progress is sourced from formal law records and never equates a topic vote with enactment'
);

topic_intent_contract_check(
    is_string($community) &&
    str_contains($community, 'مسیر پیش‌رو:') &&
    str_contains($community, 'به‌خودی‌خود الزام اجرایی ندارد') &&
    str_contains($community, 'رأی این موضوع به‌تنهایی قانون نیست'),
    'active topic details explain the next stage and legal meaning of each intent'
);

topic_intent_contract_check(
    is_string($law = file_get_contents(__DIR__ . '/../community_laws.php')) &&
    str_contains($law, 'id="law-<?= (int)$draft[\'law_id\'] ?>"') &&
    str_contains($law, 'id="law-<?= (int)$proposal[\'law_id\'] ?>"') &&
    str_contains($law, 'id="law-<?= (int)$active_law[\'law_id\'] ?>"') &&
    str_contains($law, 'id="law-<?= (int)$law[\'law_id\'] ?>"') &&
    str_contains($law, 'LEFT JOIN topics source_topic ON source_topic.id=v.source_topic_id') &&
    str_contains($law, 'منشأ قانون:') &&
    str_contains($law, 'archived_topics.php?community_id=<?= (int)$law[\'originating_community_id\'] ?>#topic-card-') &&
    str_contains($law, 'archived_topics.php?community_id=<?= (int)$law[\'originating_community_id\'] ?>#decision-'),

    'law records link to the archived source topic and its accepted decision'
);

topic_intent_contract_check(
    is_string($law = file_get_contents(__DIR__ . '/../community_laws.php')) &&
    str_contains($law, "t.topic_intent='law'") &&
    str_contains($law, 'community_law_resolve_accepted_lawmaking_origin'),
    'only explicitly lawmaking topics and their ideas or accepted decisions can supply formal law provenance'
);

topic_intent_contract_check(
    str_contains($law, '$source_decision_id === null && $source_topic_id === null && $source_idea_id === null') &&
    str_contains($law, "community_laws_redirect(\$community_id, 'invalid_source')"),
    'a formal law draft cannot be created without traceable lawmaking provenance'
);


topic_intent_contract_check(
    is_string($law = file_get_contents(__DIR__ . '/../community_laws.php')) &&
    str_contains($law, 'community_law_resolve_accepted_lawmaking_origin') &&
    str_contains($law, "d.topic_intent_snapshot='law'") &&
    !str_contains($law, 'name="source_topic_id"') &&
    !str_contains($law, 'name="source_idea_id"'),
    'initial law drafts require a single accepted lawmaking decision and do not accept manually mixed source identifiers'
);

topic_intent_contract_check(
    is_string($lawHelpers = file_get_contents(__DIR__ . '/../community_law_helpers.php')) &&
    str_contains($lawHelpers, 'community_law_resolve_accepted_lawmaking_origin') &&
    str_contains($lawHelpers, "d.acceptance_status='accepted'") &&
    str_contains($lawHelpers, "d.record_status='recorded'") &&
    str_contains($lawHelpers, "d.topic_intent_snapshot='law'") &&
    str_contains($lawHelpers, 'i.topic_id=d.source_topic_id') &&
    str_contains($lawHelpers, "t.community_id=d.community_id"),
    'backend origin resolver enforces accepted status, immutable lawmaking intent, community ownership, and idea-topic consistency'
);

topic_intent_contract_check(
    str_contains($law, 'name="source_decision_id" required') &&
    str_contains($law, 'تا نصب آن و ثبت یک تصمیم معتبر قانون‌گذاری، امکان ایجاد پیش‌نویس وجود ندارد') &&
    str_contains($law, "(!\$decision_ledger_ready || !\$source_decisions) ? 'disabled' : ''"),
    'the law draft form cannot invite manual topic or idea fallback when no accepted decision is available'
);

fwrite(STDOUT, "PASS: topic intent classification contract checks passed.\n");
