# Read-only live database preflight — 2026-11-10

## Purpose and safety boundary

This checklist is for comparing the live database with the supplied backup before any migration is considered. Every query below is read-only. It uses only `SELECT` against the current database and `information_schema`.

Do not run migrations from phpMyAdmin. Do not call the migration runner with `mode=apply` during this preflight. A successful backup inspection is not proof that the live database has the same schema or data.

## 1. Confirm database and migration registry

```sql
SELECT DATABASE() AS active_database;

SELECT
    COUNT(*) AS registered_migrations,
    COUNT(DISTINCT migration_name) AS unique_migration_names,
    COUNT(DISTINCT checksum) AS unique_checksums
FROM app_schema_migrations;
```

Expected from the supplied backup: database `if0_43167600_dolateeshgh`; 25 rows, 25 unique names, 25 unique checksums. Any difference must be investigated before proceeding.

## 2. Check key schema objects

```sql
SELECT
    wanted.object_name,
    CASE WHEN t.table_name IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS live_status
FROM (
    SELECT 'community_law_objections' AS object_name UNION ALL
    SELECT 'community_laws' UNION ALL
    SELECT 'community_law_versions' UNION ALL
    SELECT 'community_law_change_cases' UNION ALL
    SELECT 'community_law_community_notices' UNION ALL
    SELECT 'community_decision_records' UNION ALL
    SELECT 'community_hierarchy_change_history' UNION ALL
    SELECT 'community_law_outcome_corrective_actions' UNION ALL
    SELECT 'community_law_outcome_corrective_completion_reviews'
) AS wanted
LEFT JOIN information_schema.tables t
    ON t.table_schema = DATABASE()
   AND t.table_name = wanted.object_name
ORDER BY wanted.object_name;
```

The supplied backup showed these nine tables as missing. If any is present on the live host, inspect its columns, indexes and constraints before considering the corresponding migration.

## 3. Check columns that are added to existing tables

```sql
SELECT
    wanted.table_name,
    wanted.column_name,
    CASE WHEN c.column_name IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS live_status
FROM (
    SELECT 'topics' AS table_name, 'topic_intent' AS column_name UNION ALL
    SELECT 'community_decision_records', 'topic_intent_snapshot' UNION ALL
    SELECT 'community_law_outcome_corrective_actions', 'completion_status' UNION ALL
    SELECT 'community_law_outcome_corrective_actions', 'completion_review_round' UNION ALL
    SELECT 'community_law_outcome_corrective_completion_reviews', 'review_round'
) AS wanted
LEFT JOIN information_schema.columns c
    ON c.table_schema = DATABASE()
   AND c.table_name = wanted.table_name
   AND c.column_name = wanted.column_name
ORDER BY wanted.table_name, wanted.column_name;
```

A missing parent table makes its column result appear as `MISSING`; do not treat that alone as a separate defect.

## 4. Verify historical decision backfill prerequisites

```sql
SELECT
    COUNT(*) AS finalized_decisions,
    SUM(t.id IS NOT NULL) AS decisions_with_matching_topics,
    SUM(
        d.selected_idea_id IS NOT NULL
        AND d.selected_idea_id <> 0
        AND i.id IS NULL
    ) AS missing_selected_ideas
FROM collective_knowledge_decisions d
LEFT JOIN topics t ON t.id = d.topic_id
LEFT JOIN ideas i ON i.id = d.selected_idea_id
WHERE d.finalized_at IS NOT NULL;
```

Expected from the supplied backup: 99 finalized decisions, 99 matching topics, and 0 missing selected ideas. The backfill uses `INSERT IGNORE` and a unique source-topic key, but its target ledger must still be checked before execution.

## 5. Verify legacy topic classification inputs

```sql
SELECT topic_purpose, COUNT(*) AS topic_count
FROM topics
GROUP BY topic_purpose
ORDER BY topic_purpose;
```

Expected from the supplied backup: 1 `knowledge`, 2 `execution`, and 108 `mixed` topics. The classification migration maps only `knowledge` to `knowledge`; all other legacy purposes map to `decision`. It does not automatically classify historical topics as laws.

## 6. Release gate

Before any approved apply operation:

1. Confirm the active database name and registry match the intended host.
2. Compare the live table/column results with the backup and investigate every unexpected `PRESENT` or `MISSING` result.
3. Inspect the migration runner's default read-only plan; confirm the pending list, checksums, dependency order and recovery-marker state.
4. Review every data-changing migration and the expected row counts above.
5. Take a fresh database and file backup immediately before the approved change window.
6. Apply only after explicit approval, in the corrected dependency order, with post-migration checks.

This checklist does not authorize migration execution, registry edits, deployment, or changes to the live host.


## 7. Recorded live-host results (user-run, read-only checks)

The user ran the checks above against the active database on 2026-11-10. The reported active database and registry matched the supplied backup: `if0_43167600_dolateeshgh`, 25 registered migrations, 25 unique names and 25 unique checksums.

The live schema query reported these three knowledge-library tables as present:
- `collective_knowledge_entries`
- `collective_knowledge_revisions`
- `collective_knowledge_reviews`

The supplied SQL backup was independently inspected earlier: these tables have 16, 15 and 8 columns respectively, with primary keys and the expected named indexes matching `20261106_collective_knowledge_library.sql`. The live query confirmed table presence, not a fresh live column/index comparison. The migration file uses `CREATE TABLE IF NOT EXISTS` only; it does not insert, update or delete knowledge rows. The registry discrepancy remains: this migration is not among the 25 registered names even though its tables exist. Do not alter the registry manually; any reconciliation must be reviewed as a separate controlled step.

The live schema query reported the following target objects as missing, consistently with the supplied backup:
- Charter foundation: `community_charter_versions`, `community_charter_articles`, `community_law_charter_references`, `community_law_charter_assessments`.
- Expertise ballot snapshots: `topic_expertise_voter_weight_snapshots`.
- Decision ledger: `community_decision_records`.
- Corrective completion workflow: `community_law_outcome_corrective_actions`, `community_law_outcome_corrective_completion_reviews`.
- Added columns: `topics.topic_intent`, `community_decision_records.topic_intent_snapshot`, `community_law_outcome_corrective_actions.completion_status`, `community_law_outcome_corrective_actions.completion_review_round`, and `community_law_outcome_corrective_completion_reviews.review_round`. Missing columns whose parent tables are missing are expected consequences, not separate defects.

The user also confirmed all 13 legacy prerequisite objects/columns checked earlier were present. The live data checks returned:
- 99 finalized decisions; all 99 have matching source topics; 0 reference a missing selected idea.
- 64 ballots, 64 distinct topic/user pairs, across 41 topics.
- Topic-purpose distribution: 1 `knowledge`, 2 `execution`, 108 `mixed`, with no NULL values.

These checks support the migration preconditions and match the supplied backup. They do not constitute a migration-runner plan, a restore test, or authorization to apply migrations. No live database, host files, `main`, or deployment were changed during these checks.
