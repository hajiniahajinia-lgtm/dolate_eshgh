import importlib.util
import unittest
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


if __name__ == "__main__":
    unittest.main()
