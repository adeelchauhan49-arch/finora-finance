import unittest
from pathlib import Path


class ForgotPasswordFlowTests(unittest.TestCase):
    def test_forgot_password_ui_is_present(self):
        root = Path(__file__).resolve().parents[1]
        html = root.joinpath('index.html').read_text(encoding='utf-8')
        self.assertIn('Forgot password', html)
        self.assertIn('Send code', html)
        self.assertIn('6-digit code sent to your email', html)


if __name__ == '__main__':
    unittest.main()
