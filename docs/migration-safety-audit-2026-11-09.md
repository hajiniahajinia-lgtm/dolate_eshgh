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
