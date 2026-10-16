import unittest
import json
import shutil
import subprocess
from pathlib import Path


class JarvisChatbotTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.endpoint = self.root.joinpath('auth', 'jarvis.php').read_text(encoding='utf-8')
        self.app_js = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.css = self.root.joinpath('assets', 'css', 'style.css').read_text(encoding='utf-8')
        self.snapshot_helper = self.root.joinpath('includes', 'jarvis_financial_snapshot.php').read_text(encoding='utf-8')

    def test_jarvis_is_present_in_ui(self):
        html = self.root.joinpath('index.html').read_text(encoding='utf-8')
        self.assertIn('Jarvis', html)
        self.assertIn('Jarvis', self.app_js)
        self.assertIn('Ask Jarvis', html)
        self.assertTrue(self.root.joinpath('assets', 'img', 'jarvis.svg').is_file())
        self.assertGreaterEqual(html.count('assets/img/jarvis.svg'), 3)
        self.assertIn("avatar.src = 'assets/img/jarvis.svg'", self.app_js)
        self.assertIn('@media (prefers-reduced-motion: reduce)', self.css)
        self.assertIn('.jarvis-toggle:hover', self.css)

    def test_snapshot_is_owner_scoped_and_empty_accounts_have_no_invented_metrics(self):
        php = shutil.which('php') or r'C:\xampp\php\php.exe'
        if not Path(php).is_file():
            self.skipTest('PHP CLI is not installed.')
        helper_path = str(self.root.joinpath('includes', 'jarvis_financial_snapshot.php')).replace('\\', '/')
        script = r'''
require 'HELPER_PATH';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, type TEXT NOT NULL, merchant TEXT NOT NULL, category TEXT NOT NULL, amount REAL NOT NULL, transaction_date TEXT NOT NULL)');
$pdo->exec('CREATE TABLE financial_profile (user_id INTEGER PRIMARY KEY, monthly_budget REAL NOT NULL, currency TEXT NOT NULL)');
$pdo->exec("INSERT INTO financial_profile VALUES (1, 30000, 'INR'), (2, 9000, 'USD')");
$insert = $pdo->prepare('INSERT INTO transactions (user_id, type, merchant, category, amount, transaction_date) VALUES (?, ?, ?, ?, ?, ?)');
foreach ([
    [1,'income','Employer','Salary',50000,'2026-04-01'],
    [1,'expense','Metro Pass','Transport',30000,'2026-03-05'],
    [1,'expense','Cafe Plan','Dining',2500,'2026-03-08'],
    [1,'income','Employer','Salary',50000,'2026-03-01'],
    [1,'expense','Metro Pass','Transport',30000,'2026-04-05'],
    [1,'expense','Cafe Plan','Dining',2500,'2026-04-08'],
    [1,'income','Employer','Salary',50000,'2026-05-01'],
    [1,'expense','Metro Pass','Transport',30000,'2026-05-05'],
    [1,'expense','Cafe Plan','Dining',2500,'2026-05-08'],
    [1,'income','Employer','Salary',50000,'2026-06-01'],
    [1,'expense','Metro Pass','Transport',30000,'2026-06-05'],
    [1,'expense','Cafe Plan','Dining',2500,'2026-06-08'],
    [1,'expense','Grocery Market','Food & dining',3000,'2026-06-10'],
    [2,'income','Different Employer','Salary',5000,'2026-06-01'],
    [2,'expense','Different Rent','Housing',1000,'2026-06-03'],
    [3,'expense','Other User Secret Merchant','Other',999999,'2026-06-04'],
] as $row) $insert->execute($row);
$today = new DateTimeImmutable('2026-06-15');
$first = jarvis_build_financial_snapshot($pdo, 1, 'three-months', $today);
$thisMonth = jarvis_build_financial_snapshot($pdo, 1, 'this-month', $today);
$lastMonth = jarvis_build_financial_snapshot($pdo, 1, 'last-month', $today);
$second = jarvis_build_financial_snapshot($pdo, 2, 'three-months', $today);
$empty = jarvis_build_financial_snapshot($pdo, 4, 'three-months', $today);
$lowData = jarvis_build_financial_snapshot($pdo, 2, 'this-month', $today);
echo json_encode([
    'first' => $first,
    'last_month' => $lastMonth,
    'second' => $second,
    'empty' => $empty,
    'empty_reply' => jarvis_deterministic_reply('help me save', $empty),
    'empty_budget_reply' => jarvis_deterministic_reply('budget status', $empty),
    'low_data_afford_reply' => jarvis_deterministic_reply('can I afford 500?', $lowData),
    'first_llm_snapshot' => jarvis_llm_snapshot($first),
    'first_summary' => jarvis_deterministic_reply('summary', $first),
    'first_summary_hinglish' => jarvis_deterministic_reply('summary batao', $first),
    'overspending_reply' => jarvis_deterministic_reply('where am I overspending', $first),
    'save_reply' => jarvis_deterministic_reply('how can I save?', $first),
    'save_how_to_reply' => jarvis_deterministic_reply('how to save?', $first),
    'save_reply_hinglish' => jarvis_deterministic_reply('paise kaise bachau?', $first),
    'budget_reply' => jarvis_deterministic_reply('budget status', $first),
    'budget_reply_hinglish' => jarvis_deterministic_reply('budget status kya hai?', $first),
    'forecast_reply' => jarvis_deterministic_reply('forecast spending', $thisMonth),
    'forecast_reply_hinglish' => jarvis_deterministic_reply('is mahine kitna kharch hoga?', $thisMonth),
    'afford_reply' => jarvis_deterministic_reply('can I afford Rs 500?', $first),
    'afford_reply_hinglish' => jarvis_deterministic_reply('kya main Rs 500 afford kar sakta hoon?', $first),
    'unknown_reply' => jarvis_deterministic_reply('tell me a joke', $first),
], JSON_THROW_ON_ERROR);
'''.replace('HELPER_PATH', helper_path)
        completed = subprocess.run([php, '-r', script], capture_output=True, text=True)
        self.assertEqual(0, completed.returncode, completed.stderr)
        result = json.loads(completed.stdout)

        first = result['first']
        self.assertEqual(150000, first['income'])
        self.assertEqual(100500, first['spending'])
        self.assertAlmostEqual(33, first['savings_rate_percent'])
        self.assertEqual(10, first['transaction_count'])
        self.assertEqual(30000, first['largest_expense']['amount'])
        self.assertEqual('Transport', first['largest_expense']['category'])
        self.assertEqual(10500, first['budget_overage'])
        self.assertEqual(90000, first['budget_for_period'])
        self.assertEqual(3, len(first['top_categories']))
        self.assertEqual(['Transport', 'Dining', 'Food & dining'], [category['category'] for category in first['top_categories']])
        self.assertAlmostEqual(89.55, first['top_categories'][0]['percent_of_spending'], places=2)
        self.assertAlmostEqual(7.46, first['top_categories'][1]['percent_of_spending'], places=2)
        self.assertAlmostEqual(2.99, first['top_categories'][2]['percent_of_spending'], places=2)
        self.assertEqual(2, first['recurring_count'])
        self.assertEqual('Metro Pass', first['recurring_transactions'][0]['merchant'])
        self.assertEqual(50000, result['last_month']['income'])
        self.assertEqual(32500, result['last_month']['spending'])
        self.assertEqual(3, result['last_month']['transaction_count'])
        self.assertEqual(2, result['last_month']['recurring_count'])
        self.assertIn('Rs 7,500.00', result['overspending_reply'])

        second = result['second']
        self.assertEqual(5000, second['income'])
        self.assertEqual(1000, second['spending'])
        self.assertEqual(2, second['transaction_count'])
        self.assertNotIn('Metro Pass', json.dumps(second))
        self.assertNotIn('Different Rent', json.dumps(first))
        self.assertNotIn('Other User Secret Merchant', json.dumps(first))
        self.assertIn('Over the last 3 months:', result['first_summary'])
        self.assertIn('Pichhle 3 mahino mein', result['first_summary_hinglish'])
        self.assertIn('Rs 100,500.00', result['first_summary'])
        self.assertIn('10 transactions', result['first_summary'])
        self.assertIn('33.0%', result['first_summary'])
        self.assertIn('Rs 90,000.00', result['overspending_reply'])
        self.assertIn('Rs 10,500.00', result['overspending_reply'])
        self.assertIn('Rs 90,000.00', result['budget_reply'])
        self.assertIn('Rs 100,500.00', result['budget_reply'])
        self.assertIn('Rs 10,500.00', result['budget_reply'])
        self.assertIn('Rs 100,500.00', result['save_reply'])
        self.assertIn('Rs 10,500.00', result['save_reply'])
        self.assertIn('Rs 100,500.00', result['save_how_to_reply'])
        self.assertIn('Rs 100,500.00', result['save_reply_hinglish'])
        self.assertIn('needs at least 14 elapsed days and 10 expense transactions', result['forecast_reply'])
        self.assertIn('Abhi forecast ke liye data kam hai', result['forecast_reply_hinglish'])
        self.assertIn('Rs 100,500.00', result['afford_reply'])
        self.assertIn('Rs 100,500.00', result['afford_reply_hinglish'])
        self.assertIn('savings, overspending', result['unknown_reply'])

        empty = result['empty']
        self.assertEqual(0, empty['transaction_count'])
        self.assertEqual(0, empty['spending'])
        self.assertTrue(empty['data_is_limited'])
        self.assertIn('only 0 transactions', result['empty_reply'])
        self.assertIn('only 0 transactions', result['empty_budget_reply'])
        self.assertIn('2 transactions', result['low_data_afford_reply'])
        self.assertNotIn('Rs ', result['empty_reply'])
        self.assertNotIn('Rs ', result['empty_budget_reply'])
        self.assertNotIn('Rs ', result['low_data_afford_reply'])

        llm_snapshot = json.dumps(result['first_llm_snapshot'])
        self.assertNotIn('Metro Pass', llm_snapshot)
        self.assertNotIn('Cafe Plan', llm_snapshot)
        self.assertNotIn('notes', llm_snapshot.lower())
        self.assertEqual(100500, result['first_llm_snapshot']['spending'])
        self.assertEqual(150000, result['first_llm_snapshot']['income'])
        self.assertEqual(10, result['first_llm_snapshot']['transaction_count'])
        self.assertEqual(2, result['first_llm_snapshot']['recurring_count'])
        self.assertEqual(32500, result['first_llm_snapshot']['recurring_average_monthly_total'])
        self.assertIn('Rs 100,500.00', result['first_summary'])

    def test_endpoint_is_authenticated_aggregates_only_and_has_per_user_rate_limit(self):
        self.assertIn("$user = require_verified_user()", self.endpoint)
        self.assertIn("jarvis_build_financial_snapshot($connection, (int) $user['id'], $period)", self.endpoint)
        self.assertIn('jarvis_llm_snapshot($snapshot)', self.endpoint)
        self.assertIn("'question' => $question", self.endpoint)
        self.assertNotIn('messages: history', self.app_js)
        self.assertIn('jarvis_rate_limits', self.endpoint)
        self.assertIn('$requestCount > 15', self.endpoint)
        self.assertIn("'role' => 'system'", self.endpoint)
        self.assertIn('do not invent', self.endpoint.lower())
        self.assertIn("$snapshot['transaction_count'] < 3", self.endpoint)
        self.assertIn('JSON_THROW_ON_ERROR', self.endpoint)

    def test_selected_period_is_validated_and_client_sends_latest_question_only(self):
        self.assertIn("['this-month', 'last-month', 'three-months']", self.endpoint)
        self.assertIn('JSON.stringify({ question, period: selectedDateRange })', self.app_js)
        self.assertNotIn('getJarvisReply(question)', self.app_js)
        self.assertIn('message.textContent = text;', self.app_js)
        self.assertNotIn('messages: history', self.app_js)
        self.assertIn("WHERE user_id = :user_id AND transaction_date >= :recurrence_start", self.snapshot_helper)

    def test_rate_limit_migration_is_scoped_to_user(self):
        migration = self.root.joinpath('database', 'jarvis_rate_limits.sql').read_text(encoding='utf-8')
        self.assertIn('user_id INT UNSIGNED NOT NULL PRIMARY KEY', migration)
        self.assertIn('FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', migration)

    def test_shared_month_end_forecast_recurring_isolation_and_historical_backtest(self):
        php = shutil.which('php') or r'C:\xampp\php\php.exe'
        if not Path(php).is_file():
            self.skipTest('PHP CLI is not installed.')
        helper_path = str(self.root.joinpath('includes', 'jarvis_financial_snapshot.php')).replace('\\', '/')
        script = r'''
require 'HELPER_PATH';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, type TEXT NOT NULL, merchant TEXT NOT NULL, category TEXT NOT NULL, amount REAL NOT NULL, transaction_date TEXT NOT NULL)');
$pdo->exec('CREATE TABLE financial_profile (user_id INTEGER PRIMARY KEY, monthly_budget REAL NOT NULL, currency TEXT NOT NULL)');
$pdo->exec("INSERT INTO financial_profile VALUES (1, 35000, 'INR'), (2, 1000, 'INR'), (3, 1000, 'INR')");
$insert = $pdo->prepare('INSERT INTO transactions (user_id, type, merchant, category, amount, transaction_date) VALUES (?, ?, ?, ?, ?, ?)');
foreach ([['2026-03-12'], ['2026-04-12'], ['2026-05-12']] as [$date]) {
    $insert->execute([1, 'expense', 'Car EMI', 'Transport', 30000, $date]);
}
for ($day = 1; $day <= 10; $day++) {
    $date = sprintf('2026-06-%02d', $day);
    $insert->execute([1, 'expense', 'Daily purchase ' . $day, 'Other', 100, $date]);
}
$insert->execute([1, 'income', 'Salary', 'Income', 1000000, '2026-06-01']);
$insert->execute([2, 'expense', 'Other user secret', 'Other', 999999, '2026-06-15']);
$start = new DateTimeImmutable('2026-03-17');
$end = new DateTimeImmutable('2026-06-30');
for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
    $insert->execute([3, 'expense', 'Unique purchase ' . $date->format('Y-m-d'), 'Other', 100, $date->format('Y-m-d')]);
}
$today = new DateTimeImmutable('2026-06-15');
$first = jarvis_build_month_end_forecast($pdo, 1, $today);
$snapshot = jarvis_build_financial_snapshot($pdo, 1, 'this-month', $today);
$second = jarvis_build_month_end_forecast($pdo, 2, $today);
$paidEmi = jarvis_calculate_month_end_forecast($pdo, 1, new DateTimeImmutable('2026-06-15'));
$insert->execute([1, 'expense', 'Car EMI', 'Transport', 30000, '2026-06-11']);
$afterEmiPaid = jarvis_calculate_month_end_forecast($pdo, 1, new DateTimeImmutable('2026-06-15'));
$backtest = [];
foreach ([10, 15, 20] as $day) {
    $date = new DateTimeImmutable(sprintf('2026-06-%02d', $day));
    $estimate = jarvis_calculate_month_end_forecast($pdo, 3, $date);
    $published = jarvis_build_month_end_forecast($pdo, 3, $date);
    $actual = 3000;
    $backtest[(string) $day] = [
        'estimate' => $estimate['model_estimate']['point'],
        'available' => $published['available'],
        'error_percent' => abs($estimate['model_estimate']['point'] - $actual) / $actual * 100,
    ];
}
echo json_encode([
    'first' => $first,
    'snapshot' => $snapshot,
    'jarvis_forecast_reply' => jarvis_deterministic_reply('forecast spending', $snapshot),
    'second' => $second,
    'paid_emi_estimate' => $paidEmi['model_estimate']['point'],
    'after_emi_paid_estimate' => $afterEmiPaid['model_estimate']['point'],
    'paid_emi_recurring_add' => $afterEmiPaid['model_estimate']['recurring_added'],
    'backtest' => $backtest,
], JSON_THROW_ON_ERROR);
'''.replace('HELPER_PATH', helper_path)
        completed = subprocess.run([php, '-r', script], capture_output=True, text=True)
        self.assertEqual(0, completed.returncode, completed.stderr)
        result = json.loads(completed.stdout)

        forecast = result['first']
        self.assertTrue(forecast['available'])
        self.assertAlmostEqual(31166.67, forecast['point'], places=2)
        self.assertLess(forecast['high'], 40000)
        self.assertEqual(10, forecast['expense_transaction_count'])
        self.assertEqual('Low', forecast['confidence'])
        self.assertIsNone(result['second']['point'])
        self.assertFalse(result['second']['available'])
        self.assertEqual(forecast, result['snapshot']['month_end_forecast'])
        self.assertAlmostEqual(31166.67, result['paid_emi_estimate'], places=2)
        self.assertAlmostEqual(31166.67, result['after_emi_paid_estimate'], places=2)
        self.assertEqual(0, result['paid_emi_recurring_add'])
        self.assertIn(forecast['budget_comparison_text'], result['jarvis_forecast_reply'])
        self.assertIn('confidence: Low', result['jarvis_forecast_reply'])

        backtest = result['backtest']
        self.assertFalse(backtest['10']['available'])
        self.assertTrue(backtest['15']['available'])
        self.assertTrue(backtest['20']['available'])
        self.assertAlmostEqual(2.96, backtest['10']['error_percent'], places=2)
        self.assertAlmostEqual(0, backtest['15']['error_percent'], places=2)
        self.assertAlmostEqual(0, backtest['20']['error_percent'], places=2)
        print(
            'Historical month-end forecast error: '
            + ', '.join(f"day {day}: {data['error_percent']:.2f}%" for day, data in backtest.items())
        )


if __name__ == '__main__':
    unittest.main()
