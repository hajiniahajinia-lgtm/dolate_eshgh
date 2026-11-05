# Optional systemd deployment (Oracle Cloud Linux VM)

This is a deployment template only; it does not provision a VM or deploy itself.

## One-time setup

1. Create a dedicated unprivileged Linux account named `dolate-test`.
2. Place this repository checkout at `/opt/dolate-eshgh` and make the directory readable by `dolate-test`.
3. Run the registration command once as `dolate-test` against the test site to create the first test account and local credential file:

   ```sh
   sudo -u dolate-test python3 /opt/dolate-eshgh/tools/ai-bridge/site_smoke_test.py --base-url https://dolateeshgh.kesug.com/ --count 1
   ```

4. Confirm `test_accounts.local.json` is owned by `dolate-test` and mode `600`. Never copy it into Git, a public artifact, or logs.
5. Copy the supplied service and timer files into `/etc/systemd/system/`, then enable the timer:

   ```sh
   sudo systemctl daemon-reload
   sudo systemctl enable --now dolate-eshgh-smoke.timer
   sudo systemctl start dolate-eshgh-smoke.service
   sudo systemctl status dolate-eshgh-smoke.service
   sudo journalctl -u dolate-eshgh-smoke.service -n 50 --no-pager
   ```

The timer only attempts login with the most recently saved test account. It does not create accounts repeatedly, modify application data, or delete users. Review the site/database is still a test environment before enabling it. If the login-rate-limit blocks the VM's outbound IP, stop the timer and investigate rather than repeatedly retrying.

## Files

- `dolate-eshgh-smoke.service`
- `dolate-eshgh-smoke.timer`

The VM, firewall, SSH access, and DNS/network configuration must be provisioned separately. Do not put SSH private keys, passwords, or database credentials in this repository.
