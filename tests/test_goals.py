import unittest
from pathlib import Path


class SavingsGoalsTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.endpoint = self.root.joinpath('auth', 'goals.php').read_text(encoding='utf-8')
        self.migration = self.root.joinpath('database', 'savings_goal_contributions.sql').read_text(encoding='utf-8')
        self.app_js = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.html = self.root.joinpath('index.html').read_text(encoding='utf-8')
        self.css = self.root.joinpath('assets', 'css', 'style.css').read_text(encoding='utf-8')

    def test_goal_page_is_routed_and_has_create_and_empty_states(self):
        self.assertIn('href="#goals"', self.html)
        self.assertIn('id="goals"', self.html)
        self.assertIn('id="goalCreateForm"', self.html)
        self.assertIn('id="goalsEmptyState"', self.html)
        self.assertIn('Create your first goal', self.html)
        self.assertIn("location.hash === '#goals' ? 'goals'", self.app_js)
        self.assertIn("document.getElementById('goals').hidden = currentView !== 'goals'", self.app_js)
        self.assertIn('.goals-page', self.css)

    def test_contributions_are_owner_scoped_and_cascade_with_goals(self):
        self.assertIn("$user = require_verified_user()", self.endpoint)
        self.assertIn('WHERE user_id = :user_id', self.endpoint)
        self.assertIn('WHERE id = :id AND user_id = :user_id', self.endpoint)
        self.assertIn('INNER JOIN savings_goals g ON g.id = c.goal_id AND g.user_id = c.user_id', self.endpoint)
        self.assertIn('FOREIGN KEY (user_id, goal_id) REFERENCES savings_goals (user_id, id) ON DELETE CASCADE', self.migration)
        self.assertIn('CREATE TABLE savings_goal_contributions', self.migration)
        self.assertIn('contribution_date DATE NOT NULL', self.migration)
        self.assertIn('FOR UPDATE', self.endpoint)

    def test_goal_and_contribution_inputs_are_validated(self):
        for method in ('GET', 'POST', 'PATCH', 'DELETE'):
            self.assertIn(f"'{method}'", self.endpoint)
        for field in ("$data['title']", "$data['target_amount']", "$data['starting_amount']", "$data['deadline']", "$data['amount']", "$data['date']"):
            self.assertIn(field, self.endpoint)
        self.assertIn('FILTER_VALIDATE_INT', self.endpoint)
        self.assertIn('FILTER_VALIDATE_FLOAT', self.endpoint)
        self.assertIn('!$validDate($deadline)', self.endpoint)

    def test_goal_cards_render_user_data_as_text_and_cover_progress_states(self):
        renderer = self.app_js.split('const renderSavingsGoals = () => {', 1)[1].split('const loadSavingsGoals =', 1)[0]
        self.assertIn("makeGoalElement('h3', 'goal-title', goal.title)", renderer)
        self.assertIn('goal.title));', renderer)
        self.assertIn("makeGoalElement('span', 'goal-completed-badge', 'Completed')", renderer)
        self.assertIn("makeGoalElement('div', 'progress goal-progress')", renderer)
        self.assertIn('aria-valuenow', renderer)
        self.assertIn('days left', renderer)
        self.assertIn('save about ${money(remaining * 30.4375 / daysLeft)} per month.', renderer)
        self.assertNotIn('innerHTML', renderer)
        self.assertIn('current_amount', renderer)

    def test_demo_mode_uses_local_storage_without_server_session(self):
        self.assertIn("const demoGoalsStorageKey = 'finora-savings-goals';", self.app_js)
        self.assertIn("const isDemoGoalsMode = () => location.protocol === 'file:' || !serverUserAuthenticated || !activeUser;", self.app_js)
        self.assertIn('JSON.parse(localStorage.getItem(demoGoalsStorageKey) ||', self.app_js)
        self.assertIn('const saveDemoGoals = () => localStorage.setItem(demoGoalsStorageKey', self.app_js)


if __name__ == '__main__':
    unittest.main()
