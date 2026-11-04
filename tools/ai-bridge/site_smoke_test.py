#!/usr/bin/env python3
"""Create and authenticate clearly labelled test users without a browser.

Uses the existing register.php/login.php HTTP forms and their CSRF/session
protections. Credentials are written only to a local, git-ignored JSON file.
No SQL, database credentials, arbitrary endpoints, or destructive operations.
"""
from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import secrets
import string
import sys
import time
from html.parser import HTMLParser
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urljoin, urlparse
from urllib.request import HTTPCookieProcessor, Request, build_opener

DEFAULT_BASE_URL = "https://dolateeshgh.kesug.com/"
ACCOUNT_FILE = Path(__file__).with_name("test_accounts.local.json")
TIMEOUT_SECONDS = 20


class HiddenInputParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.hidden: dict[str, str] = {}

    def handle_starttag(self, tag: str, attrs) -> None:
        if tag.lower() != "input":
            return
        item = dict(attrs)
        if item.get("type", "").lower() == "hidden" and item.get("name"):
            self.hidden[item["name"]] = item.get("value", "")


def parse_hidden(html: str) -> dict[str, str]:
    parser = HiddenInputParser()
    parser.feed(html)
    return parser.hidden


def national_id() -> str:
    """Generate a checksum-valid 10-digit Iranian national ID."""
    while True:
        digits = [secrets.randbelow(10) for _ in range(9)]
        if len(set(digits)) == 1:
            continue
        total = sum(digits[i] * (10 - i) for i in range(9))
        remainder = total % 11
        check = remainder if remainder < 2 else 11 - remainder
        return "".join(map(str, digits)) + str(check)


def new_account() -> dict[str, str]:
    suffix = secrets.token_hex(4).upper()
    phone = "099" + "".join(secrets.choice(string.digits) for _ in range(8))
    return {
        "username": "TEST_BOT_" + suffix,
        "phone": phone,
        "national_id": national_id(),
        "password": secrets.token_urlsafe(18),
        "created_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }


class SiteClient:
    def __init__(self, base_url: str) -> None:
        parsed = urlparse(base_url)
        if parsed.scheme != "https" or not parsed.netloc:
            raise ValueError("برای جلوگیری از افشای رمز، نشانی سایت باید HTTPS باشد.")
        self.base_url = base_url.rstrip("/") + "/"
        self.opener = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, path: str, data: dict[str, str] | None = None) -> tuple[str, str, int]:
        url = urljoin(self.base_url, path)
        payload = urlencode(data).encode() if data is not None else None
        req = Request(url, data=payload, headers={
            "User-Agent": "DolateEshgh-TestRunner/1.0",
            "Accept": "text/html,application/xhtml+xml",
        })
        try:
            with self.opener.open(req, timeout=TIMEOUT_SECONDS) as response:
                body = response.read().decode("utf-8", errors="replace")
                return body, response.geturl(), response.status
        except HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace")
            return body, exc.geturl(), exc.code

    def csrf(self, page: str, field: str) -> str:
        body, _, status = self.request(page)
        if status != 200:
            raise RuntimeError(f"دریافت {page} ناموفق بود (HTTP {status}).")
        token = parse_hidden(body).get(field, "")
        if not token:
            raise RuntimeError(f"توکن امنیتی فرم {page} پیدا نشد.")
        return token

    def register(self, account: dict[str, str]) -> None:
        token = self.csrf("register.php", "csrf_token")
        body, final_url, status = self.request("register.php", {
            "csrf_token": token,
            "username": account["username"],
            "phone": account["phone"],
            "national_id": account["national_id"],
            "password": account["password"],
        })
        if status not in (200, 302):
            raise RuntimeError(f"ثبت‌نام ناموفق بود (HTTP {status}).")
        if "قبلاً ثبت شده" in body or "معتبر نیست" in body or "ثبت‌نام انجام نشد" in body:
            raise RuntimeError("سایت ثبت‌نام را نپذیرفت؛ جزئیات حساس نمایش داده نمی‌شود.")
        if not urlparse(final_url).path.endswith("/login.php"):
            raise RuntimeError("ثبت‌نام به صفحه ورود هدایت نشد؛ پاسخ سایت نیاز به بررسی دارد.")

    def login(self, account: dict[str, str]) -> str:
        token = self.csrf("login.php", "csrf_token")
        body, final_url, status = self.request("login.php", {
            "csrf_token": token,
            "phone": account["phone"],
            "national_id": account["national_id"],
            "password": account["password"],
        })
        if status != 200:
            raise RuntimeError(f"ورود ناموفق بود (HTTP {status}).")
        if not urlparse(final_url).path.endswith("/profile.php"):
            raise RuntimeError("ورود تأیید نشد؛ مقصد نهایی صفحه پروفایل نیست.")
        profile, profile_url, profile_status = self.request("profile.php")
        if profile_status != 200 or not urlparse(profile_url).path.endswith("/profile.php"):
            raise RuntimeError("نشست ورود ساخته شد اما صفحه پروفایل قابل تأیید نیست.")
        if account["username"] not in profile:
            raise RuntimeError("پروفایل باز شد اما هویت حساب آزمایشی در آن تأیید نشد.")
        return "authenticated"


def load_accounts() -> list[dict[str, str]]:
    if not ACCOUNT_FILE.exists():
        return []
    return json.loads(ACCOUNT_FILE.read_text(encoding="utf-8"))


def save_accounts(accounts: list[dict[str, str]]) -> None:
    ACCOUNT_FILE.write_text(json.dumps(accounts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    try:
        os.chmod(ACCOUNT_FILE, 0o600)
    except OSError:
        pass


def main() -> int:
    parser = argparse.ArgumentParser(description="آزمون ثبت‌نام و ورود دولت عشق بدون مرورگر")
    parser.add_argument("--base-url", default=DEFAULT_BASE_URL, help="نشانی HTTPS سایت")
    parser.add_argument("--count", type=int, default=1, help="تعداد حساب‌های جدید (۱ تا ۱۰)")
    parser.add_argument("--login-latest", action="store_true", help="ورود با آخرین حساب ذخیره‌شده")
    args = parser.parse_args()

    if not 1 <= args.count <= 10:
        parser.error("--count باید بین ۱ تا ۱۰ باشد.")

    try:
        accounts = load_accounts()
        client = SiteClient(args.base_url)

        if args.login_latest:
            if not accounts:
                raise RuntimeError("هنوز حساب آزمایشی ذخیره‌شده‌ای وجود ندارد.")
            account = accounts[-1]
            client.login(account)
            print(f"ورود موفق: {account['username']}")
            return 0

        for _ in range(args.count):
            account = new_account()
            client.register(account)
            accounts.append(account)
            save_accounts(accounts)
            client.login(account)
            print(f"ثبت‌نام و ورود موفق: {account['username']}")

        print(f"تعداد حساب‌های ذخیره‌شده محلی: {len(accounts)}")
        print(f"فایل محرمانه حساب‌ها: {ACCOUNT_FILE.name} (در Git ثبت نمی‌شود)")
        return 0
    except (OSError, ValueError, RuntimeError, URLError, json.JSONDecodeError) as exc:
        print(f"آزمون متوقف شد: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
