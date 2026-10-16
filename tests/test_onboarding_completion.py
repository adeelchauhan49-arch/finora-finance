import unittest
from pathlib import Path


class OnboardingCompletionTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.app = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.html = self.root.joinpath('index.html').read_text(encoding='utf-8')
        self.onboarding = self.root.joinpath('auth', 'onboarding.php').read_text(encoding='utf-8')
        self.settings = self.root.joinpath('auth', 'settings.php').read_text(encoding='utf-8')
        self.session = self.root.joinpath('auth', 'session.php').read_text(encoding='utf-8')
        self.login = self.root.joinpath('auth', 'login.php').read_text(encoding='utf-8')
        self.verification = self.root.joinpath('auth', 'email-verification.php').read_text(encoding='utf-8')
        self.migration = self.root.joinpath('database', 'onboarding_completed.sql').read_text(encoding='utf-8')
        self.preview = self.root.joinpath('database', 'onboarding_completed_preview.sql').read_text(encoding='utf-8')

    def test_login_and_restored_session_check_explicit_completion_timestamp(self):
        show = self.app.split('const showOnboardingIfNeeded =', 1)[1].split('const loadServerTransactions', 1)[0]
        self.assertIn("Boolean(result.profile?.onboarding_completed_at)", show)
        self.assertIn('localStorage.getItem(onboardingPromptedKey(activeUser.id))', show)
        self.assertIn('localStorage.setItem(onboardingPromptedKey(activeUser.id), \'shown\')', show)
        self.assertIn('sessionStorage.getItem(finishSetupDismissedKey(activeUser.id))', show)
        login_flow = self.app.split("document.getElementById('loginForm').addEventListener", 1)[1].split("const jarvisMessages", 1)[0]
        self.assertIn('await showOnboardingIfNeeded();', login_flow)
        self.assertIn('await showOnboardingIfNeeded();', self.app.split("apiRequest('auth/session.php')", 1)[1].split("menuToggle.addEventListener", 1)[0])
        self.assertNotIn('Number(result.profile.onboarding_completed)', show)

    def test_completion_is_saved_only_by_successful_onboarding_submission(self):
        self.assertIn('onboarding_completed_at FROM financial_profile', self.onboarding)
        self.assertIn('onboarding_completed_at\n        ) VALUES', self.onboarding)
        self.assertIn('onboarding_completed_at = CURRENT_TIMESTAMP', self.onboarding)
        self.assertIn('$connection->commit();', self.onboarding)
        self.assertIn("require_verified_user()", self.onboarding)
        self.assertNotIn('onboarding_completed_at = CURRENT_TIMESTAMP', self.settings)

    def test_skip_card_is_dismissible_and_settings_can_reopen_prefilled_form(self):
        self.assertIn('id="finishSetupCard"', self.html)
        self.assertIn('id="dismissFinishSetup"', self.html)
        self.assertIn('id="closeOnboardingButton"', self.html)
        self.assertIn('Skip setup for now? You can finish it later from the dashboard.', self.app)
        self.assertIn('id="settingsOpenOnboarding"', self.html)
        self.assertIn('const applyOnboardingProfile =', self.app)
        self.assertIn('const legacyIncome = Number(saved.monthly_income || 0)', self.app)
        self.assertIn('(saved.monthly_salary ?? 0)', self.app)
        self.assertIn('investmentPlans.map((plan) => plan.investment_type)', self.app)

    def test_unverified_accounts_remain_blocked_from_onboarding(self):
        self.assertIn('$user = require_verified_user();', self.onboarding)
        self.assertIn("if ($user['email_verified_at'] === null)", self.session)
        self.assertIn('Email verification is handled by Supabase Auth.', self.verification)
        self.assertIn("if ($user['email_verified_at'] === null)", self.session)
        self.assertIn("auth.verifyOtp({ email, token: otp, type: 'signup' })", self.app)
        self.assertIn('await showOnboardingIfNeeded();', self.app.split("document.getElementById('verifyEmailButton').addEventListener", 1)[1])

    def test_migration_counts_and_only_marks_profiles_with_positive_income(self):
        self.assertIn('ADD COLUMN onboarding_completed_at DATETIME NULL DEFAULT NULL', self.migration)
        self.assertIn('WHERE monthly_income > 0', self.migration)
        self.assertIn('onboarding_completed_at IS NULL', self.migration)
        self.assertIn('CASE WHEN monthly_income > 0 THEN 1 ELSE 0 END), 0) AS profiles_to_mark_completed', self.preview)
        self.assertIn('CASE WHEN monthly_income <= 0 OR monthly_income IS NULL THEN 1 ELSE 0 END), 0) AS profiles_left_incomplete', self.preview)
        self.assertEqual(self.preview.count('UPDATE '), 0)
        self.assertEqual(self.preview.count('ALTER TABLE'), 0)

    def test_settings_financial_fields_validate_and_scope_updates_to_verified_owner(self):
        self.assertIn("$user = require_verified_user();", self.settings)
        self.assertIn("if ($action === 'financial_profile')", self.settings)
        self.assertIn('FILTER_VALIDATE_FLOAT', self.settings)
        self.assertIn('$monthlyIncome > 9999999999.99', self.settings)
        self.assertIn('savingsPercentage > 100', self.settings)
        self.assertIn('monthly_income, monthly_savings_goal, savings_percentage)', self.settings)
        self.assertIn("'monthly_income' => $monthlyIncome", self.settings)
        self.assertIn("'user_id' => $user['id']", self.settings)
        self.assertIn('WHERE user_id = :user_id', self.settings)
        for element_id in ('settingsFinancialProfileForm', 'settingsMonthlySalary', 'settingsIncomeTotal', 'settingsMonthlySavingsGoal', 'settingsSavingsPercentage'):
            self.assertIn(f'id="{element_id}"', self.html)


if __name__ == '__main__':
    unittest.main()
