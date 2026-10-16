import re
import unittest
from pathlib import Path


class VisualThemeTests(unittest.TestCase):
    def setUp(self):
        root = Path(__file__).resolve().parents[1]
        self.html = root.joinpath('index.html').read_text(encoding='utf-8')
        self.css = root.joinpath('assets', 'css', 'style.css').read_text(encoding='utf-8')

    def test_app_loads_landing_page_fonts(self):
        self.assertIn(
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&'
            'family=Poppins:wght@600;700;800&display=swap',
            self.html,
        )
        self.assertIn('href="https://fonts.googleapis.com"', self.html)
        self.assertIn('href="https://fonts.gstatic.com" crossorigin', self.html)

    def test_brand_and_semantic_color_tokens_are_defined(self):
        for token in (
            '--g1', '--g2', '--g3', '--lime', '--pink', '--teal', '--ink',
            '--muted', '--bg', '--card', '--line', '--soft', '--ok', '--bad',
        ):
            with self.subTest(token=token):
                self.assertRegex(self.css, rf'{re.escape(token)}\s*:')

    def test_danger_color_is_not_the_primary_pink(self):
        tokens = dict(re.findall(r'(--(?:bad|pink))\s*:\s*(#[0-9A-Fa-f]{3,8})', self.css))
        self.assertIn('--bad', tokens)
        self.assertIn('--pink', tokens)
        self.assertNotEqual(tokens['--bad'].lower(), tokens['--pink'].lower())


if __name__ == '__main__':
    unittest.main()
