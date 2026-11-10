# Migration safety audit — 2026-11-09

Scope: read-only review of the 70 dated SQL migrations on the development branch. No host request, database connection, SQL execution, or migration-file rewrite was performed.

## Findings requiring attention before production apply

### A. DDL that is not guarded against partial reruns

These migrations contain direct `ALTER TABLE ... ADD COLUMN`, `ADD KEY`, `DROP INDEX`, or multi-table DDL without checking the current schema first:

- `20261104_community_budget_request_links_indexes.sql` — adds two indexes directly.
- `20261107_community_law_final_review_appeals.sql` — modifies two status enums directly.
- `20261108_community_law_change_effects.sql` — adds/modifies columns, indexes and enums directly.
- `20261108_community_law_change_review.sql` — adds columns and modifies enum/column directly.
- `20261108_community_law_change_voting.sql` — adds voting columns and modifies enum directly.
- `20261108_community_law_z1_amendment_10_submission.sql` — adds columns and index directly.
- `20261108_community_law_z1_amendment_20_voting.sql` — modifies status enum directly.
- `20261108_community_law_z1_amendment_30_parent_review.sql` — modifies status enum directly.
- `20261109_community_law_amendment_parent_review_rounds.sql` — adds a column, drops an index and adds a replacement unique index.
- `20261109_community_law_conformity_review_rounds.sql` — adds a column, drops an index and adds a replacement unique index.
- `20261109_community_law_corrective_completion_reviews.sql` — adds a column and modifies an enum before creating a table.
- `20261109_community_law_corrective_completion_retries.sql` — adds columns/indexes and replaces a unique index.

A failure after one DDL statement may leave part of the migration applied while its registry row is absent. Retrying the whole file can then fail on already-existing columns or indexes. MySQL DDL is not generally transactional.

### B. Data-changing migrations whose replay deserves special protection

- `20261101_preserve_historical_vote_weights.sql` resets all existing ballot weights to 1.00. This is a deliberate historical normalization, but replay after later weighted votes exist would overwrite them.
- `20261105_expertise_open_deadline_alignment.sql` resets the expiry of eligible pending requests to NOW()+7 days. Replay can extend an existing voting deadline.
- `20261031_expertise_vote_weights.sql` recalculates weighted idea totals. It is repeatable in form, but its result depends on the current vote-weight data.
- `20261105_topic_categories.sql` uses an upsert that can overwrite category labels/descriptions/order if those seed rows have subsequently been customized.

### C. Migrations with explicit idempotency protections

The review found schema-existence guards in several newer migrations, including topic category scope, decision-to-knowledge linkage, law decision provenance, topic intent, and decision intent. The decision-ledger backfill uses `INSERT IGNORE`; the expertise column migrations also check for existing columns.

## Required release rule

Do not rewrite historical migration files merely to make them appear repeatable: some may already have been applied on an existing database and their checksums are part of the migration registry.

Before any real apply:
1. Obtain a verified backup of both files and database.
2. Run the read-only migration plan against the actual host database.
3. Reconcile pending migrations, applied checksums and partial schema changes.
4. For any migration known to be partially applied, prepare a separate, narrowly scoped recovery migration or a reviewed host-side recovery procedure.
5. Only then approve and run the explicit apply operation.

## Status

This is a source-code audit, not proof of the live database state. The host's migration registry and actual schema were not queried. No production changes were made.

## Backup and dependency review — 2026-11-10

This supplement reviews the supplied phpMyAdmin database export and the 70 SQL migration files on the development branch. It does not represent a live connection to the host. No SQL was executed against the host, and the backup was not restored into a test server.

### Registry and schema reconciliation

- The backup contains 68 tables and 25 rows in `app_schema_migrations`.
- All 25 registered migration names exist in the branch, and all 25 SHA-256 checksums match the exact migration contents after the runner's `trim()` behavior.
- There are 45 branch migrations not represented in the registry. This count includes `20261106_collective_knowledge_library.sql`.
- The three tables created by the knowledge-library migration already exist in the backup. Their column sets, primary keys and named indexes match that migration. The migration is nevertheless absent from the registry; do not treat this as permission to run it or to edit the registry directly.
- The backup does not contain the law framework, charter foundation, decision ledger, hierarchy-change history, or corrective-completion review tables. It also does not contain `app_schema_migration_recovery`.

### Classification of the 45 unregistered migrations

- 26 are additive table-creation migrations.
- 16 contain schema alterations; this includes guarded additions, enum changes, and three migrations that replace unique indexes to support independent review rounds.
- Five touch existing or seed data; two of those also alter schema. No `DROP TABLE`, `DELETE`, or `TRUNCATE` statement was found in this set. The three `DROP INDEX` statements change uniqueness constraints; they do not delete rows.

Data-touching cases requiring preflight:

- `20261107_community_charter_foundation.sql` seeds the initial charter version and its 12 articles using `INSERT IGNORE`.
- `20261107_topic_expertise_voter_weight_snapshots.sql` captures the earliest ballot weight for each voter/topic pair. Its unique key makes repeated inserts idempotent.
- `20261108_community_decision_ledger_backfill.sql` copies finalized historical decisions. The supplied backup has 99 finalized decision rows, all 99 matching an existing topic. The destination ledger has a unique key on `source_topic_id`, so `INSERT IGNORE` protects against duplicate source topics.
- `20261109_topic_intent_classification.sql` classifies 111 existing topics: 1 with `topic_purpose='knowledge'` becomes `knowledge`; 2 `execution` and 108 `mixed` topics become `decision`. No historical topic is automatically classified as a law.
- `20261109_z_community_decision_topic_intent.sql` backfills the topic-intent snapshot in the new decision ledger.

### Dependency defects found and corrected in the development branch

The runner previously sorted several files lexically even though their schema dependencies required another order. The dependency map and its contract test now enforce:

- objections schema repair → law framework → final-review/appeal schema;
- charter foundation → charter amendments;
- voting rules → voting-rule approval workflow;
- decision ledger → decision/knowledge link;
- law change control → independent review → voting → effects;
- corrective-completion reviews → retry-round schema;
- expertise proposal schema → proposal voting.

The law-change chain is especially important: the review and voting migrations modify the allowed notice-event enum values. The effects migration must run last so the final enum retains the full set, including `change_vote_result` and `expiry_updated`.

Commit: `1d3fb88c32a7433c37583c8f30ca83f181614afc`.

### Verification and release boundary

- Migration dependency-order workflow: success.
- PHP syntax/contract workflow: success.
- Isolated MySQL charter/law integration workflow: success.
- PR #38 remains open and unmerged.
- No host, live database, deployment, or main-branch changes were made. The read-only backup review does not replace a live runner plan and partial-schema reconciliation immediately before any approved apply.
