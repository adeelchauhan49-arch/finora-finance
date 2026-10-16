import unittest
from pathlib import Path


class TransactionDeleteTests(unittest.TestCase):
    def test_supabase_delete_is_scoped_to_authenticated_owner(self):
        root = Path(__file__).resolve().parents[1]
        app_js = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        migration = root.joinpath('supabase', 'migrations', '20261016000000_browser_access.sql').read_text(encoding='utf-8')
        delete = app_js.split("if (method === 'DELETE')", 1)[1].split("if (resource === 'onboarding')", 1)[0]
        self.assertIn(".from('transactions').delete()", delete)
        self.assertIn(".eq('id', body.id).eq('user_id', user.id)", delete)
        self.assertIn("create policy %I on public.%I for all to authenticated", migration)
        self.assertIn('user_id = public.finora_user_id()', migration)

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