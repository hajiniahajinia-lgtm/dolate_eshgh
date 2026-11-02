# Secure AI Bridge for Dolate Eshgh

## Status

Planning document only. This branch is isolated from `main`. No production files, hosting configuration, database credentials, or database records are changed by this document.

## Goal

Provide an authenticated, auditable way for an AI agent to inspect the existing Dolate Eshgh application and, only after explicit approval, perform narrowly scoped maintenance actions.

## Architecture

1. **Agent application (separate service):** Uses the OpenAI API. API credentials are stored in the service's secret manager/environment, never in the Git repository or browser.
2. **Authenticated tool server (MCP or a restricted HTTPS API):** Exposes named operations, not arbitrary SQL. It authenticates every request, validates arguments, applies rate limits, and writes audit records.
3. **Database adapter:** Uses a dedicated database account with minimum privileges. Initial phase is read-only and exposes only approved metadata/diagnostic queries.
4. **Repository adapter:** Uses GitHub's authenticated API to read files and propose code changes on a separate branch. Do not put hosting or database secrets in commits.
5. **Browser testing:** A separate browser automation environment logs into a test account only after its credentials are safely provisioned. It must not expose session cookies or passwords to model output.

## Initial read-only tools

- `health_check`: verifies the service and database connection without returning credentials.
- `list_allowed_tables`: lists only explicitly approved tables.
- `describe_allowed_table`: returns columns and indexes for an approved table.
- `run_diagnostic`: runs a named, parameterized diagnostic query from an allowlist.
- `get_recent_errors`: returns sanitized application errors, excluding secrets and personal data.
- `get_deployment_status`: reports known deployment/workflow state without exposing repository secrets.

No general `execute_sql(sql)` tool is permitted.

## Write phase (not enabled initially)

Any future write tools must be operation-specific, validate IDs and values server-side, use transactions where appropriate, log before/after metadata, and require explicit approval for each consequential operation. Schema changes should be versioned SQL migrations reviewed on a separate branch. Destructive operations require a separate confirmation and verified backup.

## Security requirements

- HTTPS only; strong authentication (OAuth or signed short-lived tokens), rotation and revocation.
- Store secrets in environment variables or a secret manager; never commit secrets or log authorization headers.
- Dedicated least-privilege DB user; no global/admin privileges.
- Table/query allowlist; prepared statements; strict input validation; output redaction.
- Rate limits, request IDs, audit logs, and safe error messages.
- No public endpoint that accepts arbitrary SQL, shell commands, file paths, or unrestricted URLs.
- Keep this service separate from the current site until reviewed and tested.
- Never deploy automatically from this branch. The existing workflow deploys only from `main`.

## Deployment compatibility check

The repository's current deployment workflow uses FTP to InfinityFree and excludes `db.php` and `.env`. Before choosing where to host the tool server, verify that the target host supports the required runtime, persistent process/HTTP endpoint, HTTPS, and secret configuration. Do not assume the current shared host supports a long-running MCP server.

## Phased acceptance criteria

1. Review this design and choose the runtime/hosting environment.
2. Implement a local mock with no real credentials or database access.
3. Test authentication, allowlists, redaction, rate limiting, and audit logging.
4. Connect read-only access using a dedicated database user and verify that writes are impossible.
5. Add repository read access and branch/PR-based changes.
6. Add browser testing with a non-production account.
7. Only after explicit review, introduce specific write operations with approval gates and backups.
