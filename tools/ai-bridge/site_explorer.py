#!/usr/bin/env python3
"""Read-only, browserless page explorer for the Dolate Eshgh test site.

Only performs GET requests to a small allowlist. It never submits forms,
follows arbitrary links, stores page bodies, or records CSRF/input values.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
import sys
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import parse_qs, urljoin, urlparse

HERE = Path(__file__).resolve().parent
RUNNER_PATH = HERE / "site_smoke_test.py"
_SPEC = importlib.util.spec_from_file_location("site_smoke_test", RUNNER_PATH)
if _SPEC is None or _SPEC.loader is None:
    raise RuntimeError("بارگذاری ابزار ورود آزمایشی ممکن نشد.")
_runner = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(_runner)
SiteClient = _runner.SiteClient
load_accounts = _runner.load_accounts

# Deliberately excludes community.php, archived_topics.php and votes.php:
# their GET handlers may finalize records or create/update database rows.
SAFE_PATHS = {"profile.php", "stats.php", "community_stats.php"}
MAX_PAGES = 12
MAX_BODY_BYTES = 2_000_000


class PageParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.title_parts: list[str] = []
        self.in_title = False
        self.links: list[str] = []
        self.forms: list[dict] = []
        self.current_form: dict | None = None

    def handle_starttag(self, tag: str, attrs) -> None:
        a = dict(attrs)
        tag = tag.lower()
        if tag == "title":
            self.in_title = True
        elif tag == "a" and a.get("href"):
            self.links.append(a["href"])
        elif tag == "form":
            self.current_form = {
                "method": (a.get("method") or "get").lower(),
                "action": a.get("action") or "",
                "fields": [],
            }
            self.forms.append(self.current_form)
        elif tag in {"input", "select", "textarea", "button"} and self.current_form is not None:
            name = a.get("name")
            if name:
                self.current_form["fields"].append(name)

    def handle_endtag(self, tag: str) -> None:
        if tag.lower() == "title":
            self.in_title = False
        elif tag.lower() == "form":
            self.current_form = None

    def handle_data(self, data: str) -> None:
        if self.in_title:
            self.title_parts.append(data.strip())


def safe_target(base_url: str, current_url: str, href: str) -> str | None:
    parsed_base = urlparse(base_url)
    target = urlparse(urljoin(current_url, href))
    if (
        target.scheme != "https"
        or (target.hostname or "").lower() != (parsed_base.hostname or "").lower()
        or (target.port or 443) != (parsed_base.port or 443)
        or target.username is not None
        or target.password is not None
    ):
        return None
    path = target.path.rsplit("/", 1)[-1]
    if path not in SAFE_PATHS:
        return None
    if path == "community_stats.php":
        ids = parse_qs(target.query).get("community_id", [])
        if len(ids) != 1 or not re.fullmatch(r"[1-9][0-9]{0,8}", ids[0]):
            return None
        return path + "?community_id=" + ids[0]
    if target.query:
        return None
    return path


def explore(client, base_url: str, start_paths: list[str]) -> dict:
    queue = list(dict.fromkeys(start_paths))
    visited: set[str] = set()
    pages: list[dict] = []
    while queue and len(visited) < MAX_PAGES:
        path = queue.pop(0)
        if path in visited or path.split("?", 1)[0] not in SAFE_PATHS:
            continue
        visited.add(path)
        body, final_url, status = client.request(path)
        item = {"path": path.split("?", 1)[0], "status": status}
        if status != 200:
            pages.append(item)
            continue
        if len(body.encode("utf-8", errors="replace")) > MAX_BODY_BYTES:
            item["error"] = "response_too_large"
            pages.append(item)
            continue
        parser = PageParser()
        parser.feed(body)
        item["title"] = " ".join(x for x in parser.title_parts if x)[:160]
        item["links_discovered"] = 0
        item["forms"] = [
            {
                "method": form["method"],
                "action": (urlparse(urljoin(final_url, form["action"])).path.rsplit("/", 1)[-1]
                           if form["action"] else item["path"]),
                "field_names": sorted(set(form["fields"])),
                "submitted": False,
            }
            for form in parser.forms
        ]
        for href in parser.links:
            target = safe_target(base_url, final_url, href)
            if target and target not in visited and target not in queue:
                queue.append(target)
                item["links_discovered"] += 1
        pages.append(item)
    return {
        "mode": "read_only_get",
        "pages_checked": len(pages),
        "pages": pages,
        "excluded_pages": ["community.php", "archived_topics.php", "votes.php"],
        "note": "هیچ فرم یا درخواست تغییردهنده‌ای ارسال نشده و محتوای صفحات/توکن‌ها ذخیره نشده است.",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="کاوش فقط‌خواندنی صفحات امن دولت عشق")
    parser.add_argument("--base-url", default=_runner.DEFAULT_BASE_URL)
    parser.add_argument("--start", choices=("profile", "stats"), default="profile")
    parser.add_argument("--output", default="site_explorer_report.json")
    args = parser.parse_args()
    try:
        accounts = load_accounts()
        if not accounts:
            raise RuntimeError("ابتدا یک حساب آزمایشی را با site_smoke_test.py بسازید.")
        client = SiteClient(args.base_url)
        client.login(accounts[-1])
        starts = ["profile.php"] if args.start == "profile" else ["stats.php"]
        report = explore(client, args.base_url.rstrip("/") + "/", starts)
        output = Path(args.output)
        output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"کاوش فقط‌خواندنی تمام شد: {report['pages_checked']} صفحه؛ گزارش: {output}")
        return 0
    except (OSError, ValueError, RuntimeError, json.JSONDecodeError) as exc:
        print(f"کاوش متوقف شد: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
