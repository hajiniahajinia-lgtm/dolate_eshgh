# Dolate Eshgh AI Bridge — Local Mock

This is an isolated prototype only. It is not connected to the real site or database and must not be treated as production-ready.

## Requirements

- Python 3.10+
- No third-party packages required for this mock

## Run locally

Linux/macOS:

```sh
export AI_BRIDGE_TOKEN="$(python -c 'import secrets; print(secrets.token_urlsafe(32))')"
python mock_server.py
```

Windows PowerShell:

```powershell
$env:AI_BRIDGE_TOKEN = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
python .\mock_server.py
```

The server binds to `127.0.0.1:8787` by default. It has no database connection, does not execute SQL, and has no write routes.

## Test

Use the token from the same terminal session:

```sh
curl -H "Authorization: Bearer $AI_BRIDGE_TOKEN" http://127.0.0.1:8787/health
curl -H "Authorization: Bearer $AI_BRIDGE_TOKEN" http://127.0.0.1:8787/tools
```

Expected health response explicitly states that database connectivity and SQL execution are disabled. Requests without the correct token should receive HTTP 401.

## Before any real connection

Do not expose this mock to the public internet. A production service needs HTTPS, stronger identity/authentication and secret rotation, rate limiting, audit logging, a dedicated least-privilege database account, an allowlist of parameterized operations, and an independently hosted runtime. Never add database passwords or API keys to GitHub. Do not add an arbitrary SQL execution tool.


## Local MCP scaffold

A separate `mcp_server.py` provides a minimal MCP server over standard input/output.
It currently exposes only `health_check` and `available_capabilities`. It does not
connect to the website, database, browser, filesystem, or shell, and it cannot write
or execute SQL.

Install and run locally:

```powershell
python -m pip install -r requirements.txt
python .\\mcp_server.py
```

This process is a development scaffold, not yet a remote ChatGPT connector.
Do not publish it as a network service. Real read-only database access and browser
automation require separate security reviews and explicit implementation.


## Browserless website smoke test (real test database)

The HTTP runner below uses the existing `register.php` and `login.php` forms.
It preserves the site's CSRF and session-cookie flow and does not need a browser,
direct SQL access, or database credentials. It creates actual user rows, so use
only where the database contains test data and test accounts are authorized.

From this directory:

```sh
python site_smoke_test.py --base-url https://dolateeshgh.kesug.com/ --count 1
python site_smoke_test.py --base-url https://dolateeshgh.kesug.com/ --login-latest
```

The runner labels names with `TEST_BOT_`, generates checksum-valid test national
IDs and unique-format mobile numbers, then verifies the redirect to `profile.php`.
Credentials are stored locally in `test_accounts.local.json`, which is ignored by
Git and restricted to the current OS user where supported. Protect this file like
a password vault. Never upload it, paste its contents into chat, or commit it.

Each run creates persistent test users in the existing database. The runner does
not delete accounts or other records. Start with `--count 1`; increase only when
needed. It currently tests registration and authentication only; community,
topic, idea, voting, and scheduled monitoring checks are later phases.
