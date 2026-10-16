import unittest
from pathlib import Path


class ProfileDropdownTests(unittest.TestCase):
    def setUp(self):
        root = Path(__file__).resolve().parents[1]
        self.html = root.joinpath('index.html').read_text(encoding='utf-8')
        self.app_js = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')

    def test_dropdown_contains_account_identity_settings_and_signout(self):
        self.assertIn('id="profileDropdownName"', self.html)
        self.assertIn('id="profileDropdownEmail"', self.html)
        self.assertIn('href="#settings"', self.html)
        self.assertIn('id="profileDropdownSignout"', self.html)

    def test_dropdown_and_legacy_signout_share_logout_handler(self):
        self.assertIn('supabaseClient.auth.signOut()', self.app_js)
        self.assertIn("addEventListener('click', signOut)", self.app_js)
        self.assertIn("document.querySelector('.signin-btn').addEventListener('click'", self.app_js)
        self.assertIn("event.currentTarget.textContent.trim() !== 'Sign out'", self.app_js)
        self.assertIn('await signOut();', self.app_js)
        self.assertIn("localStorage.removeItem('finora-profile')", self.app_js)

    def test_dropdown_closes_on_outside_click_and_escape(self):
        self.assertIn('!profileMenuAnchor.contains(event.target)', self.app_js)
        self.assertIn("event.key === 'Escape'", self.app_js)

    def test_profile_name_is_rendered_as_text_in_dashboard_greeting(self):
        self.assertIn('greeting.replaceChildren(document.createTextNode(`Good morning, ${user.name.split(\' \')[0]} `), wave);', self.app_js)

    def test_profile_name_is_never_interpreted_as_html(self):
        self.assertIn("document.getElementById('profileDropdownName').textContent = user.name;", self.app_js)
        self.assertIn("document.getElementById('sidebarUserName').textContent = user.name;", self.app_js)
        self.assertNotRegex(self.app_js, r'innerHTML\s*=.*user\.name')
        self.assertNotRegex(self.app_js, r'user\.name.*innerHTML\s*=')

    def test_api_message_is_rendered_as_text_not_html(self):
        self.assertIn("toastElement.querySelector('.toast-body').textContent = message;", self.app_js)
        self.assertIn('element.textContent = message;', self.app_js)
        self.assertNotIn('innerHTML = `${result.message}`', self.app_js)


if __name__ == '__main__':
    unittest.main()