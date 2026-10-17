import unittest
from pathlib import Path


class SupabaseFrontendTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.app = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.config = self.root.joinpath('assets', 'js', 'supabase-config.js').read_text(encoding='utf-8')
        self.html = self.root.joinpath('index.html').read_text(encoding='utf-8')
        self.migration = self.root.joinpath('supabase', 'migrations', '20261016000000_browser_access.sql').read_text(encoding='utf-8')

    def test_app_is_static_and_has_no_local_php_or_database_runtime(self):
        for name in ('auth', 'includes', 'database', 'scripts', 'vendor'):
            self.assertFalse(self.root.joinpath(name).exists(), name)
        for name in ('.env', '.env.example', 'composer.json', 'composer.lock', 'router.php', 'starter.py'):
            self.assertFalse(self.root.joinpath(name).exists(), name)
        for directory in ('auth', 'includes', 'database', 'scripts', 'vendor'):
            self.assertFalse(self.root.joinpath(directory).exists(), directory)
        self.assertIn('assets/js/supabase-config.js', self.html)
        self.assertNotIn('supabase-config.php', self.html)
        self.assertNotIn("fetch('auth/", self.app)
        self.assertIn(".from('transactions')", self.app)
        self.assertIn(".from('financial_profile')", self.app)
        self.assertIn(".from('savings_goals')", self.app)

    def test_browser_configuration_contains_only_public_supabase_settings(self):
        self.assertIn('url:', self.config)
        self.assertIn('publishableKey:', self.config)
        self.assertIn('proEnabled: false', self.config)
        for secret_name in ('SUPABASE_SERVICE_ROLE_KEY', 'JARVIS_API_KEY', 'RAZORPAY_KEY_SECRET', 'SMTP_PASSWORD'):
            self.assertNotIn(secret_name, self.config)
        self.assertNotIn('service_role', self.config.lower())

    def test_authenticated_data_access_is_owner_scoped_by_rls(self):
        self.assertIn('create policy users_select_own_authenticated', self.migration)
        self.assertIn('auth_user_id = (select auth.uid())', self.migration)
        self.assertIn('public.finora_user_id()', self.migration)
        policy_tables = self.migration.split('foreach table_name in array array[', 1)[1].split(']', 1)[0]
        for table in ('transactions', 'financial_profile', 'investment_plan', 'savings_goals', 'savings_goal_contributions', 'user_category_rules'):
            self.assertIn(f"'{table}'", policy_tables)
        self.assertNotIn("'pro_subscriptions'", policy_tables)
        self.assertIn('for all to authenticated using (user_id = public.finora_user_id())', self.migration)
        self.assertNotIn('grant select on public.pro_subscriptions to authenticated', self.migration)
        self.assertIn('revoke all on function public.finora_confirm_jarvis_action(text) from public, anon', self.migration)
        self.assertIn('grant execute on function public.finora_confirm_jarvis_action(text) to authenticated', self.migration)

    def test_onboarding_and_goal_contributions_use_atomic_database_operations(self):
        self.assertIn("rpc('finora_save_onboarding'", self.app)
        self.assertIn('public.finora_save_onboarding(', self.migration)
        self.assertIn("rpc('finora_delete_goal_contribution'", self.app)
        self.assertIn('public.finora_delete_goal_contribution(', self.migration)
        self.assertIn('create trigger finora_goal_contribution_total', self.migration)
        self.assertIn('current_amount = current_amount + new.amount', self.migration)
        self.assertIn('current_amount = greatest(0, current_amount - contribution.amount)', self.migration)

    def test_provider_work_is_delegated_to_authenticated_edge_functions(self):
        for name in ('jarvis', 'jarvis-action'):
            function = self.root.joinpath('supabase', 'functions', name, 'index.ts').read_text(encoding='utf-8')
            self.assertIn('auth.getUser()', function)
            self.assertIn('email_confirmed_at', function)
        self.assertIn("functions.invoke(name, { body })", self.app)
        jarvis = self.root.joinpath('supabase', 'functions', 'jarvis', 'index.ts').read_text(encoding='utf-8')
        self.assertIn('Deno.env.get("JARVIS_API_KEY")', jarvis)
        self.assertIn('generativelanguage.googleapis.com', jarvis)
        self.assertNotIn('JARVIS_API_KEY', self.app)
        for name in ('pro-status', 'pro-create-order', 'pro-verify-payment'):
            function = self.root.joinpath('supabase', 'functions', name, 'index.ts').read_text(encoding='utf-8')
            self.assertIn('FINORA_PRO_ENABLED', function)
            self.assertIn('RAZORPAY_KEY_SECRET', function) if name != 'pro-status' else None
        for secret_name in ('RAZORPAY_KEY_SECRET', 'SUPABASE_SERVICE_ROLE_KEY'):
            self.assertNotIn(secret_name, self.app)

    def test_jarvis_uses_gemini_with_bounded_current_tab_history(self):
        jarvis = self.root.joinpath('supabase', 'functions', 'jarvis', 'index.ts').read_text(encoding='utf-8')
        action = self.root.joinpath('supabase', 'functions', 'jarvis-action', 'index.ts').read_text(encoding='utf-8')
        self.assertIn('gemini-2.5-flash', jarvis)
        self.assertIn('generativelanguage.googleapis.com/v1beta/models/', jarvis)
        self.assertIn('contents: [', jarvis)
        self.assertIn('...history,', jarvis)
        self.assertIn('slice(-12)', jarvis)
        self.assertIn('current financial snapshot is the source of truth', jarvis)
        self.assertNotIn('if (snapshot.data_is_limited) {', jarvis)
        self.assertIn('finora-jarvis-session-${userId}', self.app)
        self.assertIn('sessionStorage.setItem(', self.app)
        self.assertIn('sessionStorage.getItem(jarvisConversationStorageKey', self.app)
        self.assertIn('restoreJarvisConversation(result.user.id)', self.app)
        self.assertIn('history: jarvisConversation', self.app)
        self.assertIn('parseContextualAction(admin, userId, command, history)', action)
        self.assertIn("JSON.stringify({ mode: 'confirm'", self.app)
        self.assertIn('rpc("finora_confirm_jarvis_action"', action)

    def test_setup_explains_migrations_static_host_and_server_secret_handling(self):
        setup = self.root.joinpath('SETUP.md').read_text(encoding='utf-8')
        self.assertIn('static HTML/CSS/JavaScript frontend', setup)
        self.assertIn('supabase/migrations', setup)
        self.assertIn('supabase functions deploy jarvis', setup)
        self.assertIn('service-role key', setup)
        self.assertIn('Rotate any credentials that were previously stored in `.env`', setup)
        self.assertIn("Google's Gemini", setup)
        self.assertIn('per-tab `sessionStorage`', setup)
        self.assertIn('Do not upload a complete `.env` file', setup)
        self.assertNotIn('composer install', setup)


if __name__ == '__main__':
    unittest.main()
