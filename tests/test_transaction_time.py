import json
import shutil
import subprocess
import unittest
from pathlib import Path


class TransactionTimeTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.app_js = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.endpoint = self.root.joinpath('auth', 'transactions.php').read_text(encoding='utf-8')

    def test_modal_defaults_to_browser_local_date_and_time_and_save_uses_current_time_only_for_today(self):
        self.assertIn("transactionModalElement.addEventListener('shown.bs.modal'", self.app_js)
        self.assertIn('transactionDateInput.value = now.date', self.app_js)
        self.assertIn('transactionTimeInput.value = now.time', self.app_js)
        self.assertIn('date === saveDateTime.date ? saveDateTime.time :', self.app_js)
        self.assertIn('const saveTime = enteredTime ||', self.app_js)
        self.assertIn("transactionDateInput.addEventListener('change'", self.app_js)
        self.assertIn("transactionTimeInput.value = ''", self.app_js)
        self.assertIn('const formatLocalDate = (date)', self.app_js)
        self.assertIn('date.getHours()', self.app_js)
        self.assertIn('date.getMinutes()', self.app_js)

    def test_missing_historical_time_is_not_rendered_as_a_placeholder(self):
        renderer_start = self.app_js.index('const renderTransactions = () => {')
        renderer_end = self.app_js.index('\n  const categoryLimitFor', renderer_start)
        renderer = self.app_js[renderer_start:renderer_end]
        self.assertNotIn('Time not set', self.app_js)
        self.assertIn("${item.time ? ` · ${timeLabel(item.time)}` : ''}", renderer)
        self.assertIn("dateText.textContent = dateLabel(date)", renderer)

    def test_server_accepts_only_valid_hh_mm_and_saves_time_owner_scoped(self):
        php = shutil.which('php') or r'C:\xampp\php\php.exe'
        if not Path(php).is_file():
            self.skipTest('PHP CLI is not installed.')
        helper = str(self.root.joinpath('includes', 'transaction_validation.php')).replace('\\', '/')
        script = f"""
require '{helper}';
echo json_encode([
    'valid_midnight' => transaction_time_is_valid('00:00'),
    'valid_last_minute' => transaction_time_is_valid('23:59'),
    'valid_hour' => transaction_time_is_valid('09:05'),
    'invalid_hour' => transaction_time_is_valid('24:00'),
    'invalid_minute' => transaction_time_is_valid('12:60'),
    'seconds_rejected' => transaction_time_is_valid('12:30:00'),
    'bad_format_rejected' => transaction_time_is_valid('9:05'),
    'empty_rejected_by_format' => transaction_time_is_valid(''),
]);
"""
        completed = subprocess.run([php, '-r', script], capture_output=True, text=True)
        self.assertEqual(0, completed.returncode, completed.stderr)
        result = json.loads(completed.stdout)
        self.assertTrue(result['valid_midnight'])
        self.assertTrue(result['valid_last_minute'])
        self.assertTrue(result['valid_hour'])
        self.assertFalse(result['invalid_hour'])
        self.assertFalse(result['invalid_minute'])
        self.assertFalse(result['seconds_rejected'])
        self.assertFalse(result['bad_format_rejected'])
        self.assertFalse(result['empty_rejected_by_format'])
        self.assertIn("($time !== '' && !transaction_time_is_valid($time))", self.endpoint)
        self.assertIn('transaction_time, payment_method', self.endpoint)
        self.assertIn("'transaction_time' => $time ?: null", self.endpoint)
        self.assertIn("'user_id' => $user['id']", self.endpoint)


if __name__ == '__main__':
    unittest.main()
