import importlib.util
import unittest
from urllib.error import URLError
from urllib.request import Request
from pathlib import Path

path = Path(__file__).with_name("site_smoke_test.py")
spec = importlib.util.spec_from_file_location("site_runner", path)
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class RunnerTests(unittest.TestCase):
    def test_csrf_parser(self):
        html = '<input type="hidden" name="csrf_token" value="token123">'
        self.assertEqual(runner.parse_hidden(html)["csrf_token"], "token123")

    def test_generated_account_formats(self):
        account = runner.new_account()
        self.assertTrue(account["username"].startswith("TEST_BOT_"))
        self.assertRegex(account["phone"], r"^09[0-9]{9}$")
        self.assertRegex(account["national_id"], r"^[0-9]{10}$")

    def test_national_id_checksum(self):
        for _ in range(50):
            code = runner.national_id()
            digits = [int(x) for x in code]
            remainder = sum(digits[i] * (10 - i) for i in range(9)) % 11
            expected = remainder if remainder < 2 else 11 - remainder
            self.assertEqual(digits[9], expected)

    def test_plain_http_is_rejected(self):
        with self.assertRaises(ValueError):
            runner.SiteClient("http" + "://" + "example.invalid")

    def test_cross_origin_redirect_is_rejected(self):
        handler = runner.SameOriginHTTPSRedirectHandler(("https", "example.com", 443))
        request = Request("https://example.com/login.php")
        with self.assertRaises(URLError):
            handler.redirect_request(
                request, None, 307, "Temporary Redirect", {},
                "https://attacker.invalid/collect",
            )

    def test_https_downgrade_redirect_is_rejected(self):
        handler = runner.SameOriginHTTPSRedirectHandler(("https", "example.com", 443))
        request = Request("https://example.com/login.php")
        with self.assertRaises(URLError):
            handler.redirect_request(
                request, None, 302, "Found", {},
                "http://example.com/login.php",
            )


# Read-only explorer safety checks
explorer_path = Path(__file__).with_name("site_explorer.py")
explorer_spec = importlib.util.spec_from_file_location("site_explorer", explorer_path)
explorer = importlib.util.module_from_spec(explorer_spec)
explorer_spec.loader.exec_module(explorer)


class ExplorerSafetyTests(unittest.TestCase):
    base = "https://dolateeshgh.kesug.com/"

    def test_allows_only_approved_pages(self):
        self.assertEqual(
            explorer.safe_target(self.base, self.base + "profile.php", "stats.php"),
            "stats.php",
        )

    def test_allows_numeric_community_stats_id(self):
        self.assertEqual(
            explorer.safe_target(self.base, self.base + "profile.php",
                                 "community_stats.php?community_id=12"),
            "community_stats.php?community_id=12",
        )

    def test_rejects_mutating_or_side_effect_pages(self):
        for path in ("community.php?id=1", "archived_topics.php?community_id=1",
                     "votes.php?community_id=1", "add_topic.php", "logout.php"):
            with self.subTest(path=path):
                self.assertIsNone(explorer.safe_target(self.base, self.base, path))

    def test_rejects_external_and_http_links(self):
        self.assertIsNone(explorer.safe_target(
            self.base, self.base, "https://attacker.invalid/profile.php"))
        self.assertIsNone(explorer.safe_target(
            self.base, self.base, "http://dolateeshgh.kesug.com/stats.php"))

    def test_parser_records_names_not_hidden_values(self):
        parser = explorer.PageParser()
        parser.feed('<form method="post"><input type="hidden" name="csrf_token" value="SECRET"></form>')
        self.assertEqual(parser.forms[0]["fields"], ["csrf_token"])
        self.assertNotIn("SECRET", str(parser.forms))


if __name__ == "__main__":
    unittest.main()
