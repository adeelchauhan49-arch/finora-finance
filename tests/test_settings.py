import unittest
from pathlib import Path


class SettingsTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.endpoint = self.root.joinpath('auth', 'settings.php').read_text(encoding='utf-8')
        self.app_js = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.html = self.root.joinpath('index.html').read_text(encoding='utf-8')

    def test_profile_and_preferences_are_scoped_and_use_supabase_for_auth_changes(self):
        self.assertIn("$user = require_verified_user()", self.endpoint)
        self.assertIn('Change your email through Supabase Auth', self.endpoint)
        self.assertIn('ON CONFLICT (user_id) DO UPDATE SET currency = EXCLUDED.currency', self.endpoint)
        self.assertIn('budget_allocations = EXCLUDED.budget_allocations', self.endpoint)
        self.assertIn('auth.updateUser({ email: requestedEmail })', self.app_js)

    def test_missing_profiles_are_created_from_postgres_defaults_without_overwriting_existing_rows(self):
        auth = self.root.joinpath('includes', 'auth.php').read_text(encoding='utf-8')
        login = self.root.joinpath('auth', 'login.php').read_text(encoding='utf-8')
        register = self.root.joinpath('auth', 'register.php').read_text(encoding='utf-8')
        session = self.root.joinpath('auth', 'session.php').read_text(encoding='utf-8')
        schema = self.root.joinpath('database', 'jarvis_actions.sql').read_text(encoding='utf-8')

        self.assertIn('INSERT INTO financial_profile (user_id) VALUES (:user_id) ON CONFLICT (user_id) DO NOTHING', auth)
        self.assertIn('handle_new_supabase_user', self.root.joinpath('supabase', 'migrations', '20261014000000_finora_supabase.sql').read_text(encoding='utf-8'))
        self.assertIn('on conflict (user_id) do nothing', self.root.joinpath('supabase', 'migrations', '20261014000000_finora_supabase.sql').read_text(encoding='utf-8'))
        self.assertIn('ensure_financial_profile($user[\'id\'])', session)
        self.assertIn('ensure_financial_profile($user[\'id\'])', self.endpoint)
        self.assertIn('DEFAULT 2400.00', schema)

    def test_password_change_uses_supabase_auth(self):
        self.assertIn("auth.updateUser({ password: newPassword })", self.app_js)
        self.assertIn('Password changes are handled by Supabase Auth.', self.endpoint)

    def test_settings_ui_covers_currency_budgets_password_and_notifications(self):
        for settings_id in ('settingsProfileForm', 'settingsPreferencesForm', 'settingsPasswordForm', 'settingsNotificationsForm'):
            self.assertIn(f'id="{settings_id}"', self.html)
        self.assertIn('id="settingsJarvisActionsForm"', self.html)
        self.assertIn('id="settingsJarvisAutoAdd"', self.html)
        self.assertIn("jarvis_auto_add_enabled'] = (bool)", self.endpoint)
        self.assertIn("? (float) $profile['jarvis_auto_add_limit'] : 5000.0", self.endpoint)
        self.assertIn('USD', self.html)
        self.assertIn('EUR', self.html)
        self.assertIn('finora-notifications-${activeUser.id}', self.app_js)
        self.assertIn('categoryLimitFor(category, 1)', self.app_js)

    def test_category_defaults_scale_with_monthly_budget_and_show_overruns(self):
        self.assertIn('const defaultCategoryBudgetAmounts = { Housing: 1200, Dining: 400', self.app_js)
        self.assertIn('const defaultAmount = defaultCategoryBudgetAmounts[category] ?? defaultBudgetLimit * 0.1;', self.app_js)
        self.assertIn('(budgetLimit * defaultAmount / defaultBudgetLimit) * monthCount', self.app_js)
        self.assertIn("amount.textContent = `${money(total)} / ${money(limit)} · Over by ${money(overage)}`;", self.app_js)
        self.assertIn("row.className = 'over-budget-category';", self.app_js)
        stylesheet = self.root.joinpath('assets', 'css', 'style.css').read_text(encoding='utf-8')
        self.assertIn('.budget-categories > div.over-budget-category .category-name', stylesheet)
        self.assertIn('.budget-categories > div.over-budget-category .category-budget-overrun', stylesheet)


if __name__ == '__main__':
    unittest.main()