# Browserless test-account runner

This tool exercises the existing public registration and login forms over HTTPS.
It does not use a browser, direct SQL, database credentials, or destructive cleanup.

## Requirements

- Python 3.10 or newer
- Network access to the test site over HTTPS
- A test database/site only; registration creates persistent user records

## Run

From the repository root:

```sh
python tools/ai-bridge/site_smoke_test.py --base-url https://dolateeshgh.kesug.com/ --count 1
python tools/ai-bridge/site_smoke_test.py --base-url https://dolateeshgh.kesug.com/ --login-latest
```

Start with one account. The runner uses the site's actual CSRF tokens and session cookies,
then verifies that the authenticated profile page displays the generated test username.

Generated credentials are stored in `test_accounts.local.json` beside the script.
That file is git-ignored and restricted to owner read/write where the operating system
supports POSIX permissions. Keep it private and back it up securely if the account is
needed later. Do not paste credentials into chat, issues, logs, or commits.

Accounts are persistent. The runner does not delete users or alter database records
except for creating the explicitly requested test account through the normal form.
Do not point it at a production database.


## Read-only page explorer

After a test account has been created and saved locally, run:

```sh
python tools/ai-bridge/site_explorer.py --start profile
python tools/ai-bridge/site_explorer.py --start stats
```

The explorer logs in with the latest locally saved test account and performs GET requests
only to `profile.php`, `stats.php`, and numeric `community_stats.php?community_id=...`
links discovered from the profile. It records page titles, HTTP statuses, and form field
names—not page contents, personal values, passwords, cookies, or CSRF values.

Important: `community.php`, `archived_topics.php`, and `votes.php` are intentionally
excluded because their GET handlers can finalize elections or create/update database rows.
No forms are submitted. The report is local and can be deleted after review.
