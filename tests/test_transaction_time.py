import unittest
from pathlib import Path


class TransactionTimeTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.app_js = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')

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

    def test_browser_validates_hh_mm_and_saves_time_to_supabase(self):
        self.assertIn("!/^([01]\\d|2[0-3]):[0-5]\\d$/.test(values.transaction_time)", self.app_js)
        self.assertIn("transaction_time: body.time || null", self.app_js)
        self.assertIn(".from('transactions')", self.app_js)


if __name__ == '__main__':
    unittest.main()
