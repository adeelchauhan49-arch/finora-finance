import unittest
from pathlib import Path


class TransactionDeleteTests(unittest.TestCase):
    def test_delete_route_is_authenticated_and_scoped_to_owner(self):
        root = Path(__file__).resolve().parents[1]
        endpoint = root.joinpath('auth', 'transactions.php').read_text(encoding='utf-8')
        self.assertIn("$_SERVER['REQUEST_METHOD'] === 'DELETE'", endpoint)
        self.assertIn('DELETE FROM transactions WHERE id = :id AND user_id = :user_id', endpoint)
        self.assertIn("'user_id' => $user['id']", endpoint)
        self.assertIn('FILTER_VALIDATE_INT', endpoint)

    def test_delete_control_confirms_and_refreshes_derived_views(self):
        root = Path(__file__).resolve().parents[1]
        app_js = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.assertIn("deleteButton.className = 'delete-transaction'", app_js)
        self.assertIn('window.confirm(`Are you sure', app_js)
        self.assertIn("method: 'DELETE'", app_js)
        self.assertIn('renderTransactions();', app_js)
        self.assertIn('updateSummary();', app_js)
        self.assertIn('refreshCashflowChart(periodTransactions, range)', app_js)


if __name__ == '__main__':
    unittest.main()