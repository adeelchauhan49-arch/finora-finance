import unittest
from html.parser import HTMLParser
from pathlib import Path


class DashboardNavigationParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = set()
        self.nav_targets = []

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if 'id' in attributes:
            self.ids.add(attributes['id'])
        if tag == 'a' and 'nav-link' in attributes.get('class', '').split():
            target = attributes.get('href', '')
            if target.startswith('#'):
                self.nav_targets.append(target[1:])


class DashboardTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.html = self.root.joinpath('index.html').read_text(encoding='utf-8')
        self.app_js = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')

    def test_sidebar_links_point_to_existing_sections(self):
        parser = DashboardNavigationParser()
        parser.feed(self.html)
        self.assertTrue(parser.nav_targets)
        self.assertEqual([], [target for target in parser.nav_targets if target not in parser.ids])

    def test_dashboard_periods_and_totals_use_transaction_data(self):
        self.assertIn('data-period="this-month"', self.html)
        self.assertIn('data-period="last-month"', self.html)
        self.assertIn('data-period="three-months"', self.html)
        self.assertIn('selectedDateRange', self.app_js)
        self.assertIn('localStorage.setItem(periodStorageKey, selectedDateRange)', self.app_js)
        self.assertIn('item.date >= range.start && item.date < range.end', self.app_js)
        self.assertIn('refreshCashflowChart(periodTransactions, range)', self.app_js)
        self.assertIn('No transactions this period.', self.app_js)
        self.assertNotIn('12840.5', self.app_js)
        self.assertNotIn('Tuesday, September 16, 2026', self.html)

    def test_transaction_text_is_escaped_before_rendering(self):
        render = self.app_js.split('const renderTransactions = () => {', 1)[1].split('\n  const categoryLimitFor', 1)[0]
        self.assertIn('name.append(type, document.createTextNode(item.merchant))', render)
        self.assertIn('details.textContent = `${item.category}', render)

    def test_recent_transactions_can_expand_and_collapse_without_limiting_calculations(self):
        render = self.app_js.split('const renderTransactions = () => {', 1)[1].split('\n  const categoryLimitFor', 1)[0]
        summary = self.app_js.split('const updateSummary = () => {', 1)[1].split('\n  const renderReports', 1)[0]
        self.assertIn('visibleTransactions.slice(0, 5)', render)
        self.assertIn('transactionListToggle.textContent = transactionsExpanded ? \'Show less\' : `View all (${visibleTransactions.length})`', render)
        self.assertIn("transactionListToggle.hidden = visibleTransactions.length <= 5", render)
        self.assertIn("transactionListToggle.setAttribute('aria-expanded', String(transactionsExpanded))", render)
        self.assertIn("transactionListToggle.className = 'transaction-list-toggle'", self.app_js)
        self.assertIn(".transaction-list-toggle:focus-visible", self.root.joinpath('assets', 'css', 'style.css').read_text(encoding='utf-8'))
        self.assertIn("transactionListToggle.addEventListener('click'", self.app_js)
        self.assertIn("scrollIntoView({ behavior: 'smooth', block: 'start' })", self.app_js)
        self.assertIn('transactionsExpanded = false;', self.app_js)
        self.assertIn('transactionSeries = button.dataset.series;', self.app_js)
        period_handler = self.app_js.split("document.querySelectorAll('.period-btn')", 1)[1].split('seriesBar.querySelectorAll', 1)[0]
        series_handler = self.app_js.split('seriesBar.querySelectorAll', 1)[1].split("document.querySelectorAll('.setup-income')", 1)[0]
        self.assertIn('selectedDateRange = button.dataset.period;', period_handler)
        self.assertIn('transactionsExpanded = false;', period_handler)
        self.assertIn('transactionsExpanded = false;', series_handler)
        self.assertNotIn('.innerHTML', render)
        self.assertIn('const periodTransactions = transactions.filter(', summary)
        self.assertIn('periodTransactions.filter((item) => item.type === \'expense\')', summary)
        self.assertIn('refreshCashflowChart(periodTransactions, range)', summary)
        self.assertIn('refreshCashflowChart(periodTransactions, range)', self.app_js)

    def test_recent_transaction_delete_and_jarvis_note_rendering_survive_expansion(self):
        self.assertIn("document.getElementById('transactionList').addEventListener('click', async (event)", self.app_js)
        self.assertIn('button.dataset.transactionId', self.app_js)
        self.assertIn('window.confirm(`Are you sure you want to delete', self.app_js)
        self.assertIn('Added via confirmed Jarvis action', self.root.joinpath('auth', 'jarvis-action.php').read_text(encoding='utf-8'))
        self.assertIn('details.textContent = `${item.category}', self.app_js)
        self.assertIn("row.className = 'transaction-row timeline-transaction'", self.app_js)
        self.assertIn("deleteButton.dataset.transactionId = String(item.id)", self.app_js)
        delete_handler = self.app_js.split("document.getElementById('transactionList').addEventListener", 1)[1].split('\n  renderTransactions();', 1)[0]
        self.assertIn('await loadServerTransactions();', delete_handler)

    def test_forecast_uses_server_values_for_card_and_shared_budget_insight(self):
        endpoint = self.root.joinpath('auth', 'transactions.php').read_text(encoding='utf-8')
        snapshot = self.root.joinpath('includes', 'jarvis_financial_snapshot.php').read_text(encoding='utf-8')
        self.assertIn('jarvis_build_month_end_forecast(db(), (int) $user[\'id\'])', endpoint)
        self.assertIn('monthEndForecast = result.month_end_forecast', self.app_js)
        self.assertIn('money(monthEndForecast.low)', self.app_js)
        self.assertIn('money(monthEndForecast.high)', self.app_js)
        self.assertIn('monthEndForecast.budget_comparison_text', self.app_js)
        self.assertNotIn('(spending / todayDate.getDate())', self.app_js)
        self.assertNotIn('projectedSpending', self.app_js)
        self.assertIn('Current month-end spending forecast', self.html)
        self.assertIn('jarvis_build_month_end_forecast($connection, $userId, $today)', snapshot)
        self.assertIn("$forecast['budget_comparison_text']", snapshot)

    def test_personalized_category_endpoint_and_manual_corrections_are_wired(self):
        suggestion_endpoint = self.root.joinpath('auth', 'category-suggestion.php').read_text(encoding='utf-8')
        transaction_endpoint = self.root.joinpath('auth', 'transactions.php').read_text(encoding='utf-8')
        classifier = self.root.joinpath('includes', 'category_classifier.php').read_text(encoding='utf-8')
        migration_path = self.root.joinpath('database', 'user_category_rules.sql')
        self.assertTrue(migration_path.is_file())
        self.assertIn('category_classifier_for_user(db(), (int) $user[\'id\'], $merchant)', suggestion_endpoint)
        self.assertIn("$data['category_explicit']", transaction_endpoint)
        self.assertIn('category_classifier_save_user_rule($connection', transaction_endpoint)
        self.assertIn('auth/category-suggestion.php', self.app_js)
        self.assertIn('Pakka nahi hun', self.app_js)
        self.assertIn('category_classifier_find_user_rule', classifier)
        self.assertNotIn('JARVIS_API_KEY', suggestion_endpoint)
        self.assertIn('database/user_category_rules.sql', suggestion_endpoint)


if __name__ == '__main__':
    unittest.main()
