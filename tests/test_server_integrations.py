import unittest
import re
import shutil
import subprocess
from pathlib import Path


class ServerIntegrationTests(unittest.TestCase):
    def test_jarvis_api_keeps_provider_key_server_side(self):
        root = Path(__file__).resolve().parents[1]
        endpoint = root.joinpath('auth', 'jarvis.php').read_text(encoding='utf-8')
        client = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.assertIn('JARVIS_API_KEY', endpoint)
        self.assertIn("auth/jarvis.php", client)
        self.assertNotIn('JARVIS_API_KEY', client)

    def test_legacy_password_reset_endpoint_is_disabled_in_favor_of_supabase(self):
        root = Path(__file__).resolve().parents[1]
        endpoint = root.joinpath('auth', 'forgot-password.php').read_text(encoding='utf-8')
        client = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.assertIn('Password recovery is handled by Supabase Auth.', endpoint)
        self.assertIn("auth.resetPasswordForEmail(email", client)
        self.assertIn("auth.verifyOtp({ email, token: otp, type: 'recovery' })", client)
        self.assertIn('auth.updateUser({ password })', client)
        self.assertIn('410', endpoint)

    def test_legacy_reset_endpoint_does_not_lookup_accounts_or_issue_codes(self):
        root = Path(__file__).resolve().parents[1]
        endpoint = root.joinpath('auth', 'forgot-password.php').read_text(encoding='utf-8')
        self.assertNotIn('FROM users', endpoint)
        self.assertNotIn('otp', endpoint.lower())
        self.assertIn('Password recovery is handled by Supabase Auth.', endpoint)

    def test_supabase_password_recovery_uses_recovery_token(self):
        root = Path(__file__).resolve().parents[1]
        client = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.assertIn('auth.resetPasswordForEmail(email', client)
        self.assertIn("auth.verifyOtp({ email, token: otp, type: 'recovery' })", client)
        self.assertIn('auth.updateUser({ password })', client)

    def test_verification_email_uses_plain_html_and_logs_no_otp(self):
        root = Path(__file__).resolve().parents[1]
        helper = root.joinpath('includes', 'email_verification.php').read_text(encoding='utf-8')
        mailer = helper.split('function email_verification_send_email', 1)[1].split('function email_verification_generic_response', 1)[0]
        self.assertIn('your verification code is {$otp}', mailer)
        self.assertIn('Use this code to verify your Finora account.', mailer)
        self.assertIn('$mailer->AltBody', mailer)
        self.assertIn('isHTML(true)', mailer)
        self.assertIn('This code expires in 10 minutes.', mailer)
        self.assertIn("If you didn\\'t request this, you can ignore this email.", mailer)
        self.assertIn("setFrom($fromAddress, 'Finora Finance')", mailer)
        self.assertIn("addReplyTo($fromAddress, 'Finora Finance')", mailer)
        self.assertIn('MessageID =', mailer)
        self.assertIn("error_log('Email verification send: succeeded.')", mailer)
        self.assertIn("error_log('Email verification send: failed.')", mailer)
        self.assertNotIn('$exception->', mailer)
        self.assertNotRegex(mailer, r'aapka|daalo|hoga|nahi|karo|ke liye')
        self.assertNotIn('debug_otp', helper)
        self.assertNotIn('debug_otp', root.joinpath('auth', 'email-verification.php').read_text(encoding='utf-8'))
        self.assertNotIn('debug_otp', root.joinpath('auth', 'register.php').read_text(encoding='utf-8'))
        self.assertNotIn('debug_otp', root.joinpath('auth', 'settings.php').read_text(encoding='utf-8'))
        self.assertNotIn("'otp' => $otp", root.joinpath('auth', 'email-verification.php').read_text(encoding='utf-8'))
        self.assertNotRegex(root.joinpath('auth', 'email-verification.php').read_text(encoding='utf-8'), r"json_response\([^;]*\$otp")
        self.assertNotIn('error_log($otp', helper)

    def test_supabase_recovery_is_used_instead_of_legacy_code_templates(self):
        root = Path(__file__).resolve().parents[1]
        reset = root.joinpath('auth', 'forgot-password.php').read_text(encoding='utf-8')
        client = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.assertNotIn('{$otp}', reset)
        self.assertIn('auth.resetPasswordForEmail(email', client)

    def test_both_otp_screens_show_spam_hint_using_text_content(self):
        root = Path(__file__).resolve().parents[1]
        client = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        hint = "Didn't get the email? Check your spam or Promotions folder."
        self.assertEqual(2, client.count(f'textContent = "{hint}"'))
        self.assertIn('verificationSpamHint.hidden = false', client)
        self.assertIn('forgotPasswordSpamHint.hidden = false', client)
        self.assertIn("forgotPasswordMessage.textContent = 'A password recovery code has been sent if this email is registered.'", client)

    def test_auth_bootstrap_returns_generic_json_for_uncaught_endpoint_exceptions(self):
        root = Path(__file__).resolve().parents[1]
        auth = root.joinpath('includes', 'auth.php').read_text(encoding='utf-8')
        handler = auth.split('$finoraRespondWithGenericError =', 1)[1].split("require_once __DIR__ . '/db.php';", 1)[0]
        self.assertIn('set_exception_handler(static function (Throwable $exception)', auth)
        self.assertIn("finora_safe_exception_log_message('Finora uncaught endpoint exception', $exception)", auth)
        self.assertIn('function finora_sanitize_error_log_message(string $message): string', auth)
        self.assertIn('function finora_log_exception(string $context, Throwable $exception): void', auth)
        self.assertIn("$GLOBALS['finoraRequestData'] = $data;", root.joinpath('includes', 'db.php').read_text(encoding='utf-8'))
        self.assertIn("password|passwd|otp|verification[_-]?code|api[_-]?key|token|secret", auth)
        self.assertNotIn("Finora uncaught endpoint exception: ' . $exception", auth)
        self.assertIn('ob_start();', auth)
        self.assertLess(auth.index('set_exception_handler('), auth.index("require_once __DIR__ . '/db.php';"))
        self.assertLess(auth.index('set_exception_handler('), auth.index("require_once __DIR__ . '/config.php';"))
        self.assertIn('http_response_code(500);', handler)
        self.assertIn("header('Content-Type: application/json; charset=utf-8');", handler)
        self.assertIn("echo json_encode(['error' => 'Something went wrong. Please try again.'], JSON_UNESCAPED_SLASHES);", handler)
        self.assertIn('register_shutdown_function(', auth)
        self.assertIn('Finora fatal endpoint error: ', auth)
        self.assertIn('E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR', auth)
        endpoints = [
            endpoint for endpoint in root.joinpath('auth').glob('*.php')
            if endpoint.name != 'supabase-config.php'
        ]
        self.assertGreater(len(endpoints), 0)
        for endpoint in endpoints:
            source = endpoint.read_text(encoding='utf-8')
            bootstrap = "require_once __DIR__ . '/../includes/auth.php';"
            self.assertIn(bootstrap, source, endpoint.name)
            php_body = source.split('<?php', 1)[1].lstrip()
            php_body = re.sub(r'^declare\(strict_types\s*=\s*1\);\s*', '', php_body)
            self.assertTrue(php_body.lstrip().startswith(bootstrap), endpoint.name)

    def test_caught_auth_exceptions_are_logged_safely_and_not_returned_to_clients(self):
        root = Path(__file__).resolve().parents[1]
        for endpoint in root.joinpath('auth').glob('*.php'):
            source = endpoint.read_text(encoding='utf-8')
            self.assertNotRegex(source, r"error_log\([^\\n]*\$exception->getMessage\(\)", endpoint.name)
        order = root.joinpath('auth', 'pro-create-order.php').read_text(encoding='utf-8')
        self.assertIn("finora_log_exception('Finora Pro order creation failed', $exception);", order)
        self.assertIn("'Could not create the payment order. Please try again.'", order)
        self.assertNotIn("'message' => $exception->getMessage()", order)

    def test_pro_promo_code_is_server_configured_and_disabled_by_default(self):
        root = Path(__file__).resolve().parents[1]
        endpoint = root.joinpath('auth', 'pro-create-order.php').read_text(encoding='utf-8')
        example = root.joinpath('.env.example').read_text(encoding='utf-8')
        helper = root.joinpath('includes', 'razorpay.php').read_text(encoding='utf-8')
        setup = root.joinpath('SETUP.md').read_text(encoding='utf-8')
        self.assertIn("config_value('FINORA_PRO_PROMO_CODE', '')", endpoint)
        self.assertIn('finora_promo_code_matches($promoCode, $configuredPromoCode)', endpoint)
        self.assertIn("&& $configuredCode !== ''", helper)
        self.assertIn('hash_equals(strtolower($configuredCode), strtolower($providedCode))', helper)
        self.assertIn('FINORA_PRO_PROMO_CODE=', example)
        self.assertIn('An optional Pro promotion code can be configured with `FINORA_PRO_PROMO_CODE`.', setup)
        self.assertIn('It is disabled when the setting is blank (the default)', setup)
        self.assertNotRegex(endpoint, r"hash_equals\(\s*['\"][^'\"]+['\"]")
        self.assertLess(endpoint.index('$user = require_verified_user();'), endpoint.index('finora_promo_code_matches('))
        self.assertLess(endpoint.index('finora_promo_code_matches('), endpoint.index('INSERT INTO pro_subscriptions'))

        php = shutil.which('php') or r'C:\xampp\php\php.exe'
        if not Path(php).is_file():
            self.skipTest('PHP CLI is not installed.')
        helper_path = str(root.joinpath('includes', 'razorpay.php')).replace('\\', '/')
        script = f"""
require '{helper_path}';
echo json_encode([
    'blank_config_rejects_any_code' => !finora_promo_code_matches('ANY-CODE', ''),
    'blank_input_is_not_a_promo' => !finora_promo_code_matches('', 'Configured-Code'),
    'wrong_code_rejected' => !finora_promo_code_matches('Wrong-Code', 'Configured-Code'),
    'configured_code_accepted' => finora_promo_code_matches('Configured-Code', 'Configured-Code'),
    'matching_is_case_insensitive' => finora_promo_code_matches('configured-code', 'Configured-Code'),
]);
"""
        completed = subprocess.run([php, '-r', script], capture_output=True, text=True)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertEqual({
            'blank_config_rejects_any_code': True,
            'blank_input_is_not_a_promo': True,
            'wrong_code_rejected': True,
            'configured_code_accepted': True,
            'matching_is_case_insensitive': True,
        }, __import__('json').loads(completed.stdout))

    def test_pro_feature_flag_defaults_off_and_toggles_pro_validation(self):
        root = Path(__file__).resolve().parents[1]
        config = root.joinpath('includes', 'config.php').read_text(encoding='utf-8')
        example = root.joinpath('.env.example').read_text(encoding='utf-8')
        endpoint = root.joinpath('auth', 'jarvis-action.php').read_text(encoding='utf-8')
        self.assertIn("config_value('FINORA_PRO_ENABLED', 'false')", config)
        self.assertIn('FINORA_PRO_ENABLED=false', example)
        self.assertIn('if (!$isUndo && finora_pro_enabled())', endpoint)
        self.assertIn("$requestCount > 15", endpoint)
        self.assertIn('jarvis_action_undo_tokens', endpoint)

        php = shutil.which('php') or r'C:\xampp\php\php.exe'
        if not Path(php).is_file():
            self.skipTest('PHP CLI is not installed.')
        config_path = str(root.joinpath('includes', 'config.php')).replace('\\', '/')
        script = f"""
$_ENV['FINORA_PRO_ENABLED'] = 'false';
require '{config_path}';
$disabled = finora_pro_enabled();
$_ENV['FINORA_PRO_ENABLED'] = 'true';
$enabled = finora_pro_enabled();
$_ENV['FINORA_PRO_ENABLED'] = 'invalid';
$invalid = finora_pro_enabled();
echo json_encode(['disabled' => $disabled, 'enabled' => $enabled, 'invalid' => $invalid]);
"""
        completed = subprocess.run([php, '-r', script], capture_output=True, text=True)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertEqual({'disabled': False, 'enabled': True, 'invalid': False}, __import__('json').loads(completed.stdout))

    def test_pro_disabled_routes_return_generic_404_before_payment_or_promo_work(self):
        root = Path(__file__).resolve().parents[1]
        endpoint_names = ('pro-create-order.php', 'pro-verify-payment.php', 'pro-status.php')
        for filename in endpoint_names:
            endpoint = root.joinpath('auth', filename).read_text(encoding='utf-8')
            self.assertIn('require_verified_user()', endpoint, filename)
            self.assertIn("if (!finora_pro_enabled())", endpoint, filename)
            disabled_branch = endpoint.split("if (!finora_pro_enabled())", 1)[1].split('}', 1)[0]
            self.assertIn("json_response(['error' => 'Not available'], 404);", disabled_branch, filename)
            self.assertLess(endpoint.index('require_verified_user()'), endpoint.index('finora_pro_enabled()'), filename)
        create = root.joinpath('auth', 'pro-create-order.php').read_text(encoding='utf-8')
        verify = root.joinpath('auth', 'pro-verify-payment.php').read_text(encoding='utf-8')
        status = root.joinpath('auth', 'pro-status.php').read_text(encoding='utf-8')
        for endpoint in (create, verify):
            self.assertLess(endpoint.index('finora_pro_enabled()'), endpoint.index("require_once __DIR__ . '/../includes/razorpay.php';"))
            self.assertLess(endpoint.index('finora_pro_enabled()'), endpoint.index('$data = request_body();'))
        self.assertLess(create.index('finora_pro_enabled()'), create.index('FINORA_PRO_PROMO_CODE'))
        self.assertIn("SELECT id FROM pro_subscriptions WHERE user_id = :user_id AND status = 'active'", create)
        self.assertIn("SELECT ends_at FROM pro_subscriptions WHERE user_id = :user_id AND status = 'active'", status)
        self.assertIn("razorpay_request('GET', 'payments/'", verify)

    def test_verified_non_pro_feature_routes_are_not_gated_by_subscription(self):
        root = Path(__file__).resolve().parents[1]
        endpoint_names = (
            'jarvis.php', 'goals.php', 'settings.php', 'onboarding.php',
            'category-suggestion.php', 'transactions.php',
        )
        for filename in endpoint_names:
            endpoint = root.joinpath('auth', filename).read_text(encoding='utf-8')
            self.assertIn('require_verified_user()', endpoint, filename)
            self.assertNotIn('pro_subscriptions', endpoint, filename)
        dashboard = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.assertIn('const renderReports = () =>', dashboard)
        self.assertIn('href="#reports"', root.joinpath('index.html').read_text(encoding='utf-8'))
        self.assertNotIn('finora_pro_enabled()', dashboard)

    def test_session_flag_and_sidebar_upgrade_visibility_follow_server_setting(self):
        root = Path(__file__).resolve().parents[1]
        session = root.joinpath('auth', 'session.php').read_text(encoding='utf-8')
        client = root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        html = root.joinpath('index.html').read_text(encoding='utf-8')
        self.assertIn("'pro_enabled' => finora_pro_enabled()", session)
        self.assertIn("'user' => $user, 'pro_enabled' => finora_pro_enabled()", session)
        self.assertIn('updateProAvailability(result.pro_enabled)', client)
        self.assertIn('upgradeCard.hidden = enabled !== true', client)
        self.assertRegex(html, r'<div class="upgrade-card" hidden>')


if __name__ == '__main__':
    unittest.main()