import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

TIMEOUT = 15
TARGETS = [
    ("https-apex-login", "https://dolateeshgh.kesug.com/login.php"),
    ("https-www-login", "https://www.dolateeshgh.kesug.com/login.php"),
    ("http-apex-login", "http://dolateeshgh.kesug.com/login.php"),
    ("https-apex-home", "https://dolateeshgh.kesug.com/"),
]
USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
)


class RedirectRecorder(urllib.request.HTTPRedirectHandler):
    def __init__(self):
        super().__init__()
        self.chain = []

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        self.chain.append((code, urllib.parse.urlparse(newurl).netloc,
                           urllib.parse.urlparse(newurl).path or "/"))
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class FormParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.csrf = False
        self.text_parts = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "input" and attrs.get("name") == "csrf_token" and attrs.get("value"):
            self.csrf = True

    def handle_data(self, data):
        cleaned = re.sub(r"\s+", " ", data).strip()
        if cleaned:
            self.text_parts.append(cleaned)


def check(label, url):
    recorder = RedirectRecorder()
    opener = urllib.request.build_opener(recorder)
    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7",
            "Cache-Control": "no-cache",
        },
        method="GET",
    )
    try:
        with opener.open(request, timeout=TIMEOUT) as response:
            body = response.read(300000).decode("utf-8", errors="replace")
            final = urllib.parse.urlparse(response.geturl())
            parser = FormParser()
            parser.feed(body)
            title = re.search(r"<title[^>]*>(.*?)</title>", body, re.I | re.S)
            title_text = re.sub(r"\s+", " ", title.group(1)).strip()[:70] if title else "(none)"
            has_form = bool(re.search(r"<form\b", body, re.I))
            preview = re.sub(r"\s+", " ", " ".join(parser.text_parts)).strip()[:240]
            print(
                f"DIAG {label}: HTTP {response.status}; final={final.scheme}://{final.netloc}{final.path or '/'}; "
                f"type={response.headers.get('Content-Type', 'unknown')}; "
                f"server={response.headers.get('Server', 'unknown')}; "
                f"bytes={len(body.encode('utf-8'))}; title={title_text!r}; "
                f"login_form={has_form}; csrf={parser.csrf}; text_preview={preview!r}"
            )
            if recorder.chain:
                print(f"REDIRECTS {label}: {recorder.chain}")
    except urllib.error.HTTPError as exc:
        final = urllib.parse.urlparse(exc.geturl())
        print(f"DIAG {label}: HTTP {exc.code}; final={final.netloc}{final.path}; redirects={recorder.chain}")
    except Exception as exc:
        print(f"DIAG {label}: request-error={type(exc).__name__}; redirects={recorder.chain}")


def main():
    print("Read-only routing diagnostic: GET requests only.")
    print("No credentials, cookies, login POSTs, voting, posting, or database changes.")
    for label, url in TARGETS:
        check(label, url)
    print("Diagnostic complete.")


if __name__ == "__main__":
    sys.exit(main())
