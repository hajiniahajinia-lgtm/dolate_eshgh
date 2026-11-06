import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import http.cookiejar
from html.parser import HTMLParser

BASE_URL = os.environ.get("SITE_BASE_URL", "https://dolateeshgh.kesug.com").rstrip("/")
TIMEOUT = 20

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
        if tag == "a" and re.search(r"guest_community\.php\?id=\d+", href):
            self.community_path = href

def request(opener, url, data=None):
    req = urllib.request.Request(
        url,
        data=data,
        headers={"User-Agent": "DolateEshgh-ReadOnly-SmokeTest/1.0"},
        method="POST" if data is not None else "GET",
    )
    return opener.open(req, timeout=TIMEOUT)

def check_public(opener, path, label):
    try:
        with request(opener, BASE_URL + path) as response:
            body = response.read()
            if response.status != 200 or len(body) < 100:
                raise RuntimeError("unexpected response")
            print(f"PASS public page: {label} (HTTP {response.status})")
            return body.decode("utf-8", errors="replace")
    except Exception:
        print(f"FAIL public page: {label}")
        raise

def main():
    for key in ("TEST_PHONE", "TEST_NATIONAL_ID", "TEST_PASSWORD"):
        if not os.environ.get(key):
            print("FAIL: required GitHub secret is missing.")
            return 2

    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    try:
        about = check_public(opener, "/about.html", "about")
        listing = check_public(opener, "/guest_communities.php", "public communities")
        parser = FormTokenParser()
        parser.feed(listing)
        if parser.community_path:
            path = parser.community_path
            if path.startswith("http://") or path.startswith("https://"):
                target = path
            else:
                target = urllib.parse.urljoin(BASE_URL + "/", path)
            with opener.open(target, timeout=TIMEOUT) as response:
                if response.status != 200 or len(response.read()) < 100:
                    raise RuntimeError("public community page returned an unexpected response")
                print("PASS public page: sample community")
        else:
            print("INFO no public community link found; sample community check skipped")

        with request(opener, BASE_URL + "/login.php") as response:
            login_html = response.read().decode("utf-8", errors="replace")
            login_status = response.status
            login_url = response.geturl()
            login_content_type = response.headers.get("Content-Type", "unknown")
        if login_status != 200:
            print(f"FAIL login page: HTTP {login_status}; final URL: {urllib.parse.urlparse(login_url).path or '/'}")
            return 1
        token_parser = FormTokenParser()
        token_parser.feed(login_html)
        if not token_parser.csrf:
            title_match = re.search(r"<title[^>]*>(.*?)</title>", login_html, re.I | re.S)
            page_title = re.sub(r"\\s+", " ", title_match.group(1)).strip()[:80] if title_match else "not found"
            has_login_heading = "ورود به سامانه" in login_html
            has_login_form = bool(re.search(r"<form\\b", login_html, re.I))
            has_csrf_name = bool(re.search(r'name\\s*=\\s*["\\']csrf_token["\\']', login_html, re.I))
            has_cloudflare_marker = any(marker in login_html.lower() for marker in ("cloudflare", "cf-challenge", "just a moment"))
            print("FAIL login page: CSRF token not found; no login POST was sent.")
            print(f"DIAG login GET: HTTP {login_status}; final path={urllib.parse.urlparse(login_url).path or '/'}; content-type={login_content_type}")
            print(f"DIAG page title={page_title!r}; login heading={has_login_heading}; form={has_login_form}; csrf field name={has_csrf_name}; challenge marker={has_cloudflare_marker}")
            return 1

        form = urllib.parse.urlencode({
            "csrf_token": token_parser.csrf,
            "phone": os.environ["TEST_PHONE"],
            "national_id": os.environ["TEST_NATIONAL_ID"],
            "password": os.environ["TEST_PASSWORD"],
        }).encode("utf-8")

        # Exactly one login POST. Never retry: login.php has IP-based rate limiting.
        with request(opener, BASE_URL + "/login.php", data=form) as response:
            final_url = response.geturl()
            profile_html = response.read().decode("utf-8", errors="replace")

        if not final_url.rstrip("/").endswith("/profile.php"):
            print("FAIL login test: authentication did not reach the profile page; credentials are not shown.")
            return 1

        if re.search(r'name=["\']csrf_token["\']', profile_html, re.I) and "ورود به سامانه" in profile_html:
            print("FAIL login test: profile redirected to login.")
            return 1

        print("PASS login test: authenticated session reached profile.php")
        print("PASS smoke tests completed. No voting, posting, or community mutation was attempted.")
        return 0

    except urllib.error.HTTPError as exc:
        # Do not print response bodies, request data, cookies, or credentials.
        print(f"FAIL HTTP check: status {exc.code}; no login retry was attempted.")
        return 1
    except Exception as exc:
        print(f"FAIL smoke test: {type(exc).__name__}; details withheld to protect account data.")
        return 1

if __name__ == "__main__":
    sys.exit(main())
