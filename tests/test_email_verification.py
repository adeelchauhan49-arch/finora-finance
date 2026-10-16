import json
import shutil
import subprocess
import unittest
from pathlib import Path


class EmailVerificationTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.helper = self.root.joinpath('includes', 'email_verification.php').read_text(encoding='utf-8')
        self.auth = self.root.joinpath('includes', 'auth.php').read_text(encoding='utf-8')

    def run_php(self, code):
        php = shutil.which('php') or r'C:\xampp\php\php.exe'
        if not Path(php).is_file():
            self.skipTest('PHP CLI is not installed.')
        helper_path = str(self.root.joinpath('includes', 'email_verification.php')).replace('\\', '/')
        script = code.replace('HELPER_PATH', helper_path)
        completed = subprocess.run([php, '-r', script], capture_output=True, text=True)
        self.assertEqual(0, completed.returncode, completed.stderr)
        return json.loads(completed.stdout)

    def test_supabase_auth_replaces_legacy_registration_and_login(self):
        register = self.root.joinpath('auth', 'register.php').read_text(encoding='utf-8')
        login = self.root.joinpath('auth', 'login.php').read_text(encoding='utf-8')
        session = self.root.joinpath('auth', 'session.php').read_text(encoding='utf-8')
        client = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        self.assertIn('Account creation is handled by Supabase Auth.', register)
        self.assertIn('Sign in is handled by Supabase Auth.', login)
        self.assertIn('410', register)
        self.assertIn('410', login)
        self.assertIn('auth_user_id = :auth_user_id', self.auth)
        self.assertIn('Bearer ${accessToken}', client)
        self.assertIn('auth.signUp(', client)
        self.assertIn('auth.signInWithPassword(', client)
        self.assertIn('email_confirmed_at', self.auth)
        self.assertIn("'requires_verification' => true", session)

        protected_endpoints = [
            'transactions.php', 'goals.php', 'settings.php', 'onboarding.php',
            'jarvis.php', 'jarvis-action.php', 'category-suggestion.php',
            'pro-status.php', 'pro-create-order.php', 'pro-verify-payment.php',
        ]
        for filename in protected_endpoints:
            endpoint = self.root.joinpath('auth', filename).read_text(encoding='utf-8')
            self.assertIn('require_verified_user()', endpoint, filename)
        self.assertIn("['ok' => false, 'message' => 'Please verify your email first.'], 403", self.auth)

    def test_otp_hash_attempt_expiry_single_use_owner_isolation_and_email_change(self):
        result = self.run_php(r'''
function config_value(string $key, string $default = ''): string { return $default; }
require 'HELPER_PATH';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT UNIQUE, pending_email TEXT UNIQUE, email_verified_at TEXT)');
$pdo->exec("INSERT INTO users VALUES (1, 'One', 'one@example.test', NULL, NULL), (2, 'Two', 'two@example.test', NULL, NULL), (3, 'Old', 'old@example.test', 'new@example.test', '2026-01-01 00:00:00'), (4, 'Four', 'four@example.test', NULL, NULL)");
$pdo->exec('CREATE TABLE email_verification_otps (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, target_email TEXT, otp_hash TEXT, expires_at TEXT, attempts INTEGER DEFAULT 0, consumed_at TEXT, invalidated_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$captured = null;
$sender = static function (string $email, string $name, string $otp) use (&$captured): bool { $captured = $otp; return true; };
$now = time();
$firstSend = email_verification_send_for_user($pdo, 1, 'one@example.test', $sender, $now);
$storedHash = $pdo->query('SELECT otp_hash FROM email_verification_otps WHERE user_id = 1')->fetchColumn();
$otherUserTry = email_verification_check_code($pdo, 2, 'one@example.test', $captured, $now);
$wrongAttempts = [];
for ($i = 0; $i < 5; $i++) $wrongAttempts[] = email_verification_check_code($pdo, 1, 'one@example.test', '999999', $now)['reason'];
$lockedRow = $pdo->query('SELECT invalidated_at FROM email_verification_otps WHERE user_id = 1')->fetchColumn();
$resendTooSoon = email_verification_send_for_user($pdo, 1, 'one@example.test', $sender, $now + 30);
$newSend = email_verification_send_for_user($pdo, 1, 'one@example.test', $sender, $now + 61);
$newCode = $captured;
$verified = email_verification_check_code($pdo, 1, 'one@example.test', $newCode, $now + 62);
$reused = email_verification_check_code($pdo, 1, 'one@example.test', $newCode, $now + 63);
$expiredSender = static function (string $email, string $name, string $otp): bool { $GLOBALS['expiredOtp'] = $otp; return true; };
$expiredSend = email_verification_send_for_user($pdo, 2, 'two@example.test', $expiredSender, $now);
$expired = email_verification_check_code($pdo, 2, 'two@example.test', $GLOBALS['expiredOtp'], $now + 601);
$stageWorked = email_verification_stage_email($pdo, 3, 'fresh@example.test');
$stageSend = email_verification_send_for_user($pdo, 3, 'fresh@example.test', $sender, $now + 61);
$activeEmailBeforeChangeVerification = $pdo->query('SELECT email FROM users WHERE id = 3')->fetchColumn();
$stageVerified = email_verification_check_code($pdo, 3, 'fresh@example.test', $captured, $now + 62);
$oldEmail = $pdo->query('SELECT email FROM users WHERE id = 3')->fetchColumn();
$firstCodeForFour = null;
$latestCodeForFour = null;
$senderFour = static function (string $email, string $name, string $otp) use (&$firstCodeForFour, &$latestCodeForFour): bool {
  $firstCodeForFour ??= $otp;
  $latestCodeForFour = $otp;
  return true;
};
$sendFourFirst = email_verification_send_for_user($pdo, 4, 'four@example.test', $senderFour, $now);
$sendFourAgain = email_verification_send_for_user($pdo, 4, 'four@example.test', $senderFour, $now + 61);
$oldTokenAfterResend = email_verification_check_code($pdo, 4, 'four@example.test', $firstCodeForFour, $now + 62);
$newTokenAfterResend = email_verification_check_code($pdo, 4, 'four@example.test', $latestCodeForFour, $now + 62);
echo json_encode([
  'first_send' => $firstSend,
  'stored_plaintext' => $storedHash === $captured,
  'password_hash' => password_get_info($storedHash)['algoName'] !== 'unknown',
  'other_user_reason' => $otherUserTry['reason'],
  'other_user_still_unverified' => $pdo->query('SELECT email_verified_at FROM users WHERE id = 2')->fetchColumn() === null,
  'wrong_attempts' => $wrongAttempts,
  'locked' => $lockedRow !== false && $lockedRow !== null,
  'cooldown_reason' => $resendTooSoon['reason'],
  'new_send' => $newSend['sent'],
  'verified' => $verified['verified'],
  'reused_reason' => $reused['reason'],
  'expired_reason' => $expired['reason'],
  'stage_worked' => $stageWorked,
  'active_email_before_change_verification' => $activeEmailBeforeChangeVerification,
  'stage_verified' => $stageVerified['verified'],
  'active_email_after_change' => $oldEmail,
  'old_token_after_resend' => $oldTokenAfterResend,
  'new_token_after_resend' => $newTokenAfterResend['verified'],
], JSON_THROW_ON_ERROR);
''')
        self.assertTrue(result['first_send']['sent'])
        self.assertFalse(result['stored_plaintext'])
        self.assertTrue(result['password_hash'])
        self.assertEqual('invalid', result['other_user_reason'])
        self.assertTrue(result['other_user_still_unverified'])
        self.assertEqual(['incorrect', 'incorrect', 'incorrect', 'incorrect', 'too_many_attempts'], result['wrong_attempts'])
        self.assertTrue(result['locked'])
        self.assertEqual('cooldown', result['cooldown_reason'])
        self.assertTrue(result['new_send'])
        self.assertTrue(result['verified'])
        self.assertEqual('invalid', result['reused_reason'])
        self.assertEqual('expired', result['expired_reason'])
        self.assertTrue(result['stage_worked'])
        self.assertEqual('old@example.test', result['active_email_before_change_verification'])
        self.assertTrue(result['stage_verified'])
        self.assertEqual('fresh@example.test', result['active_email_after_change'])
        self.assertFalse(result['old_token_after_resend']['verified'])
        self.assertTrue(result['new_token_after_resend'])

    def test_resend_cooldown_hourly_limit_and_no_otp_logs(self):
        result = self.run_php(r'''
function config_value(string $key, string $default = ''): string { return $key === 'APP_ENV' ? 'production' : 'false'; }
require 'HELPER_PATH';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT UNIQUE, pending_email TEXT, email_verified_at TEXT)');
$pdo->exec("INSERT INTO users VALUES (1, 'One', 'one@example.test', NULL, NULL)");
$pdo->exec('CREATE TABLE email_verification_otps (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, target_email TEXT, otp_hash TEXT, expires_at TEXT, attempts INTEGER DEFAULT 0, consumed_at TEXT, invalidated_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$now = time();
$sent = 0;
$sender = static function (string $email, string $name, string $otp) use (&$sent): bool { $sent++; return true; };
$reasons = [];
for ($i = 0; $i < 6; $i++) {
    $reasons[] = email_verification_send_for_user($pdo, 1, 'one@example.test', $sender, $now + ($i * 61))['reason'];
    if ($reasons[$i] === 'sent') {
        $pdo->prepare('UPDATE email_verification_otps SET created_at = :created_at WHERE id = (SELECT MAX(id) FROM email_verification_otps)')
            ->execute(['created_at' => date('Y-m-d H:i:s', $now + ($i * 61))]);
    }
}
echo json_encode([
  'reasons' => $reasons,
  'sent_count' => $sent,
  'generic_message' => email_verification_generic_message(),
], JSON_THROW_ON_ERROR);
''')
        self.assertEqual(['sent', 'sent', 'sent', 'sent', 'sent', 'hourly_limit'], result['reasons'])
        self.assertEqual(5, result['sent_count'])
        self.assertEqual('If this email can be verified, a code has been sent.', result['generic_message'])
        self.assertNotIn("'otp' =>", self.helper)
        self.assertNotIn('error_log($otp)', self.helper)
        self.assertNotIn('debug_otp', self.helper)

    def test_supabase_email_verification_replaces_legacy_otp_endpoints(self):
        migration = self.root.joinpath('database', 'email_verification.sql').read_text(encoding='utf-8')
        endpoint = self.root.joinpath('auth', 'email-verification.php').read_text(encoding='utf-8')
        settings = self.root.joinpath('auth', 'settings.php').read_text(encoding='utf-8')
        html = self.root.joinpath('index.html').read_text(encoding='utf-8')
        client = self.root.joinpath('assets', 'js', 'app.js').read_text(encoding='utf-8')
        schema = self.root.joinpath('supabase', 'migrations', '20261014000000_finora_supabase.sql').read_text(encoding='utf-8')
        self.assertIn('Email verification is handled by Supabase Auth.', endpoint)
        self.assertIn("auth.verifyOtp({ email, token: otp, type: 'signup' })", client)
        self.assertIn('email_confirmed_at', schema)
        self.assertIn('enable_confirmations = true', self.root.joinpath('supabase', 'config.toml').read_text(encoding='utf-8'))
        self.assertIn('Change your email through Supabase Auth', settings)
        self.assertIn('Resend code', html)
        self.assertIn('Sign out', html)
        self.assertIn('Send code', html)
        self.assertIn('verificationIntro.textContent =', client)
        self.assertIn('A verification code has been sent to your email.', client)
        self.assertIn('Resend code (${remaining}s)', client)
        self.assertIn("Didn't get the email? Check your spam or Promotions folder.", client)
        self.assertIn('verificationMessage.textContent', client)
        self.assertNotIn('result.debug_otp', client)
        self.assertNotIn('debug_otp', self.root.joinpath('auth', 'settings.php').read_text(encoding='utf-8'))


if __name__ == '__main__':
    unittest.main()
