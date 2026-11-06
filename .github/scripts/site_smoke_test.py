import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import http.cookiejar
from html.parser import HTMLParser

BASE_URL = "https://dolateeshgh.kesug.com"
TIMEOUT = 20

USER_AGENTS = {
    "custom-test-agent": "DolateEshgh-ReadOnly-SmokeTest/1.0",
    "browser-like-agent": (
        "Mozilla/5.0 (X11; Linux x86_64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/131.0.0.0 Safari/537.36"
    ),
}


class FormTokenParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.csrf = None
        self.community_path = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "input" and attrs.get("name") == "csrf_token":
            self.csrf = attrs.get("value")
        href = attrs.get("href", "")
        if tag == "a" and re.search(r"guest_community\\.php\\?id=\\d+", href):
            self.community_path = href


def diagnose_login(user_agent_name, user_agent):
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    req = urllib.request.Request(
        BASE_URL + "/login.php",
        headers={"User-Agent": user_agent},
        method="GET",
    )
    try:
        with opener.open(req, timeout=TIMEOUT) as response:
            body = response.read().decode("utf-8", errors="replace")
            status = response.status
            final_url = response.geturl()
            content_type = response.headers.get("Content-Type", "unknown")
    except urllib.error.HTTPError as exc:
        print(f"DIAG {user_agent_name}: HTTP {exc.code}; no POST was sent.")
        return False
    except Exception as exc:
        print(f"DIAG {user_agent_name}: request failed ({type(exc).__name__}); no POST was sent.")
        return False

    parser = FormTokenParser()
    parser.feed(body)
    title_match = re.search(r"<title[^>]*>(.*?)</title>", body, re.I | re.S)
    page_title = (
        re.sub(r"\\s+", " ", title_match.group(1)).strip()[:80]
        if title_match else "not found"
    )
    has_login_heading = "ورود به سامانه" in body
    has_form = bool(re.search(r"<form\\b", body, re.I))
    has_csrf = bool(parser.csrf)
    path = urllib.parse.urlparse(final_url).path or "/"

    print(
        f"DIAG {user_agent_name}: HTTP {status}; path={path}; "
        f"content-type={content_type}; title={page_title!r}; "
        f"login-heading={has_login_heading}; form={has_form}; csrf={has_csrf}"
    )
    return has_csrf


def main():
    print("Read-only login page diagnostic started.")
    print("This run performs GET requests only; it will not submit login credentials.")
    results = {}
    for name, agent in USER_AGENTS.items():
        results[name] = diagnose_login(name, agent)

    if results.get("custom-test-agent") != results.get("browser-like-agent"):
        print("RESULT: login response differs by User-Agent.")
    elif results.get("browser-like-agent"):
        print("RESULT: both User-Agents received a page containing a CSRF token.")
    else:
        print("RESULT: neither User-Agent received a login page with a CSRF token.")

    print("No login POST, voting, posting, or database mutation was attempted.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
