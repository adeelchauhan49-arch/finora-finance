import unittest
from pathlib import Path


class OnboardingSavingsTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.client = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.onboarding_endpoint = self.root.joinpath('auth', 'onboarding.php').read_text(encoding='utf-8')

    def test_step_one_income_drives_two_way_savings_sync(self):
        sync = self.client.split('const syncSavingsFields =', 1)[1].split('const updateSetupIncome', 1)[0]
        self.assertIn("document.querySelectorAll('.setup-income')", self.client)
        self.assertIn("input === savingsAmountInput ? 'amount' : 'percentage'", self.client)
        self.assertIn('calculateSavingsPercentage(amount, income)', sync)
        self.assertIn('calculateSavingsAmount(clampedPercentage, income)', sync)
        self.assertIn("syncSavingsFields(lastSavingsSource);", self.client)
        self.assertIn("if (savingsAmountInput.value === '') lastSavingsSource = 'percentage';", sync)
        self.assertIn("if (savingsPercentageInput.value === '') lastSavingsSource = 'amount';", sync)

    def test_zero_income_clamping_rounding_and_finite_output_are_handled(self):
        helpers = self.client.split('const calculateSavingsPercentage =', 1)[1].split('const escapeHtml', 1)[0]
        self.assertIn("return Math.min(100, Math.round((amount / income * 100) * 10) / 10);", helpers)
        self.assertIn('Math.min(100, Math.max(0, percentage))', helpers)
        self.assertIn('Math.round(income * clampedPercentage / 100)', helpers)
        self.assertIn("savingsPercentageInput.value = ''", self.client)
        self.assertIn('Number.isFinite(investmentShare)', self.client)
        self.assertIn('Enter your monthly income in step 1 to calculate this automatically.', self.client)

    def test_live_helpers_and_non_blocking_warnings_use_current_values(self):
        self.assertIn('money(monthlySavings)', self.client)
        self.assertIn('money(income)', self.client)
        self.assertIn('Your savings and investment goals are more than your income.', self.client)
        self.assertIn('currentMonthExpenses()', self.client)
        self.assertIn('emergencyTarget < expenses', self.client)
        self.assertIn("setAttribute('aria-live', 'polite')", self.client)

    def test_saved_payload_keys_and_server_contract_are_unchanged(self):
        payload = self.client.split('const payload = {', 1)[1].split('\n    };', 1)[0]
        self.assertIn('monthly_savings_goal:', payload)
        self.assertIn('savings_percentage:', payload)
        self.assertIn('await apiRequest(\'auth/onboarding.php\', { method: \'POST\', body: JSON.stringify(payload) });', self.client)
        self.assertIn("$data['monthly_savings_goal']", self.onboarding_endpoint)
        self.assertIn("$data['savings_percentage']", self.onboarding_endpoint)


if __name__ == '__main__':
    unittest.main()
