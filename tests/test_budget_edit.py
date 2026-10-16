import unittest
from pathlib import Path


class BudgetEditTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.html = self.root.joinpath('index.html').read_text(encoding='utf-8')
        self.client = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.settings = self.root.joinpath('auth', 'settings.php').read_text(encoding='utf-8')

    def test_budget_card_opens_accessible_edit_modal_with_required_fields(self):
        self.assertIn('id="editBudgetButton" type="button" aria-label="Edit budget"', self.html)
        for element_id in (
            'editBudgetModal', 'editBudgetForm', 'editMonthlyBudget',
            'editBudgetCategoryLimits', 'editBudgetAllocationSummary',
            'editBudgetAllocationWarning', 'resetBudgetDefaults',
            'saveBudgetButton', 'editBudgetMessage',
        ):
            self.assertIn(f'id="{element_id}"', self.html)
        self.assertIn('editBudgetModal.show()', self.client)
        self.assertIn('editBudgetModal.hide()', self.client)
        self.assertIn("document.getElementById('editBudgetCurrencyPrefix').textContent = currencyConfig.symbol", self.client)

    def test_modal_lists_default_and_transaction_categories_with_current_month_spend(self):
        for category in ("'Housing'", "'Food & dining'", "'Transport'", "'Shopping'", "'Utilities'", "'Entertainment'", "'Health'", "'Other'"):
            self.assertIn(category, self.client)
        categories = self.client.split('const getBudgetCategories = () =>', 1)[1].split('const categorySpendingThisMonth', 1)[0]
        self.assertNotIn('Object.keys(defaultCategoryBudgetAmounts)', categories)
        self.assertIn('...transactions.map((item) => String(item.category || \'Other\').trim() || \'Other\')', self.client)
        self.assertIn("transaction.type !== 'expense'", self.client)
        self.assertIn("String(transaction.date || '').startsWith(monthPrefix)", self.client)
        renderer = self.client.split('const renderEditBudgetCategories = () => {', 1)[1].split('const updateBudgetAllocationSummary', 1)[0]
        self.assertIn('spent.textContent = `Spent: ${money(spending[category] || 0)}`;', renderer)
        self.assertNotIn('innerHTML', renderer)
        self.assertIn('textContent = `Category limits ka total ${money(total)} hai, monthly budget ${money(monthlyBudget)} hai`', self.client)
        self.assertIn('Category limits ka total monthly budget se zyada hai.', self.client)

    def test_budget_saves_through_owner_scoped_preferences_and_validates_positive_amounts(self):
        save = self.client.split('const saveBudgetPreferences = async', 1)[1].split('const editBudgetModal', 1)[0]
        self.assertIn("action: 'preferences'", save)
        self.assertIn("apiRequest('auth/settings.php'", save)
        self.assertIn('monthlyBudget < 1', save)
        self.assertIn('amount < 0.01', save)
        self.assertIn('amount > 9999999999.99', save)
        self.assertIn('!Number.isFinite(amount)', save)
        self.assertIn("'user_id' => $user['id']", self.settings)
        self.assertIn('limit < 0.01', self.settings)
        self.assertIn('budget_allocations = EXCLUDED.budget_allocations', self.settings)
        self.assertIn("json_encode($allocations, JSON_THROW_ON_ERROR)", self.settings)

    def test_default_reset_scales_existing_default_percentages_to_budget(self):
        self.assertIn('const defaultBudgetLimit = 2400;', self.client)
        self.assertIn('monthlyBudget * defaultAmount / defaultBudgetLimit', self.client)
        self.assertIn('categoryBudgetAllocationKeys', self.client)

    def test_settings_modal_forecast_and_jarvis_share_saved_budget_state(self):
        self.assertIn('const syncBudgetSettingsFields = () =>', self.client)
        self.assertIn('document.getElementById(\'settingsMonthlyBudget\').value = budgetLimit.toFixed(2)', self.client)
        self.assertIn('renderSettingsCategoryLimits();', self.client)
        self.assertIn('await loadServerTransactions();', self.client)
        self.assertIn('monthEndForecast = result.month_end_forecast', self.client)
        self.assertIn('const demoBudgetStorageKey = \'finora-budget-preferences\';', self.client)
        self.assertIn('persistDemoBudget(savedSettings)', self.client)
        self.assertIn('updateSummary();', self.client)
        self.assertIn('Saved successfully.', self.client)


if __name__ == '__main__':
    unittest.main()
