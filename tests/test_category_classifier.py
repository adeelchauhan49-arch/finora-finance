import base64
import json
import shutil
import subprocess
import unittest
from pathlib import Path


class CategoryClassifierTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.helper = self.root.joinpath('includes', 'category_classifier.php')

    def run_php(self, source):
        php = shutil.which('php') or r'C:\xampp\php\php.exe'
        if not Path(php).is_file():
            self.skipTest('PHP CLI is not installed.')
        helper_path = str(self.helper).replace('\\', '/')
        script = source.replace('HELPER_PATH', helper_path)
        completed = subprocess.run([php, '-r', script], capture_output=True, text=True)
        self.assertEqual(0, completed.returncode, completed.stderr)
        return json.loads(completed.stdout)

    def test_labelled_english_hinglish_and_typo_accuracy(self):
        examples = [
            ('Swiggy', 'Food & dining'), ('Zomato', 'Food & dining'), ('Blinkit', 'Food & dining'),
            ('Zepto', 'Food & dining'), ('BigBasket', 'Food & dining'), ('Big Basket grocery', 'Food & dining'),
            ('DMart', 'Food & dining'), ('grocery store', 'Food & dining'), ('groceries', 'Food & dining'),
            ('restaurant in Delhi', 'Food & dining'), ('Dominos', 'Food & dining'), ('Domino Pizza', 'Food & dining'),
            ('Pizza Hut', 'Food & dining'), ('McDonalds', 'Food & dining'), ('KFC', 'Food & dining'),
            ('Subway', 'Food & dining'), ('Dunzo', 'Food & dining'), ('Instamart', 'Food & dining'),
            ('JioMart', 'Food & dining'), ('Kirana store', 'Food & dining'), ('rashan dukaan', 'Food & dining'),
            ('sabzi mandi', 'Food & dining'), ('khana order', 'Food & dining'), ('nashta', 'Food & dining'),
            ('Uber', 'Transport'), ('Ola Cabs', 'Transport'), ('Rapido', 'Transport'), ('IRCTC ticket', 'Transport'),
            ('petrol pump', 'Transport'), ('diesel', 'Transport'), ('metro card', 'Transport'), ('bus ticket', 'Transport'),
            ('auto fare', 'Transport'), ('fuel', 'Transport'), ('transport', 'Transport'), ('travel', 'Transport'),
            ('taxi', 'Transport'), ('train ticket', 'Transport'), ('railway ticket', 'Transport'),
            ('Namma Metro', 'Transport'), ('safar', 'Transport'), ('Jio recharge', 'Utilities'),
            ('Airtel bill', 'Utilities'), ('electricity bill', 'Utilities'), ('bijli bill', 'Utilities'),
            ('water bill', 'Utilities'), ('internet', 'Utilities'), ('broadband', 'Utilities'),
            ('mobile bill', 'Utilities'), ('gas bill', 'Utilities'), ('phone bill', 'Utilities'),
            ('rent', 'Housing'), ('landlord', 'Housing'), ('housing', 'Housing'), ('home rent', 'Housing'),
            ('maintenance', 'Housing'), ('kiraya', 'Housing'), ('Netflix', 'Entertainment'),
            ('Hotstar', 'Entertainment'), ('Disney Hotstar', 'Entertainment'), ('movie ticket', 'Entertainment'),
            ('cinema', 'Entertainment'), ('Spotify', 'Entertainment'), ('Prime Video', 'Entertainment'),
            ('PVR', 'Entertainment'), ('INOX', 'Entertainment'), ('BookMyShow', 'Entertainment'),
            ('gaming', 'Entertainment'), ('Amazon', 'Shopping'), ('Flipkart', 'Shopping'), ('Myntra', 'Shopping'),
            ('shopping', 'Shopping'), ('clothing store', 'Shopping'), ('fashion', 'Shopping'), ('Ajio', 'Shopping'),
            ('Nykaa', 'Shopping'), ('Meesho', 'Shopping'), ('Croma', 'Shopping'), ('Reliance Digital', 'Shopping'),
            ('kapde', 'Shopping'), ('pharmacy', 'Health'), ('doctor', 'Health'), ('clinic', 'Health'),
            ('hospital', 'Health'), ('medicine', 'Health'), ('medical store', 'Health'), ('Apollo pharmacy', 'Health'),
            ('Netmeds', 'Health'), ('tata 1mg', 'Health'), ('diagnostic', 'Health'), ('dawai', 'Health'),
            ('dawa', 'Health'), ('Swiggyy order 123', 'Food & dining'), ('zomtao', 'Food & dining'),
            ('blinkitt', 'Food & dining'), ('zeptoo', 'Food & dining'), ('bigbascket', 'Food & dining'),
            ('dmrat', 'Food & dining'), ('rapdio', 'Transport'), ('petol', 'Transport'),
            ('hotsatr', 'Entertainment'), ('netfilx', 'Entertainment'), ('amzon order 456', 'Shopping'),
            ('flpikart', 'Shopping'), ('phramacy', 'Health'), ('bijlii bill', 'Utilities'),
            ('kiryaa', 'Other'), ('unknown corner shop', 'Other'), ('rent and food', 'Other'),
            ('xyz 12345', 'Other'),
        ]
        encoded = base64.b64encode(json.dumps(examples).encode()).decode()
        result = self.run_php(r'''
require 'HELPER_PATH';
$examples = json_decode(base64_decode('EXAMPLES'), true, 512, JSON_THROW_ON_ERROR);
$correct = 0;
$results = [];
foreach ($examples as [$merchant, $expected]) {
    $actual = category_classifier_suggestion($merchant)['category'];
    if ($actual === $expected) $correct++;
    $results[] = ['merchant' => $merchant, 'expected' => $expected, 'actual' => $actual];
}
echo json_encode(['total' => count($examples), 'correct' => $correct, 'results' => $results], JSON_THROW_ON_ERROR);
'''.replace('EXAMPLES', encoded))

        accuracy = result['correct'] / result['total'] * 100
        print(f"Auto-categorization accuracy: {result['correct']}/{result['total']} ({accuracy:.1f}%)")
        mismatches = [case for case in result['results'] if case['expected'] != case['actual']]
        if mismatches:
            print('Categorization mismatches: ' + json.dumps(mismatches, ensure_ascii=False))
        self.assertGreaterEqual(result['total'], 60)
        self.assertGreaterEqual(accuracy, 90, json.dumps(mismatches, ensure_ascii=False))

    def test_personal_rules_are_owner_scoped_and_corrections_are_reused(self):
        result = self.run_php(r'''
require 'HELPER_PATH';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users VALUES (1), (2)');
$pdo->exec('CREATE TABLE user_category_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, merchant_normalized TEXT NOT NULL, category TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, merchant_normalized))');
$firstSave = category_classifier_save_user_rule($pdo, 1, 'Amazon order #123', 'Transport');
$firstUser = category_classifier_for_user($pdo, 1, 'AMAZON   ORDER 9988');
$secondUser = category_classifier_for_user($pdo, 2, 'Amazon order 9988');
$updatedRule = category_classifier_save_user_rule($pdo, 1, 'Amazon order 9876', 'Health');
$updatedUser = category_classifier_for_user($pdo, 1, 'Amazon order 444');
$pdo->beginTransaction();
for ($index = 0; $index < 500; $index++) {
    $insert = $pdo->prepare('INSERT INTO user_category_rules (user_id, merchant_normalized, category) VALUES (1, ?, ?)');
    $insert->execute(['merchant ' . $index, 'Other']);
}
$pdo->commit();
$limit = category_classifier_save_user_rule($pdo, 1, 'a new merchant', 'Other');
echo json_encode([
  'first_save' => $firstSave,
  'first_user' => $firstUser,
  'second_user' => $secondUser,
  'updated' => $updatedRule,
  'updated_user' => $updatedUser,
  'limit' => $limit,
], JSON_THROW_ON_ERROR);
''')
        self.assertTrue(result['first_save']['saved'])
        self.assertEqual('Transport', result['first_user']['category'])
        self.assertEqual('personal', result['first_user']['source'])
        self.assertEqual('Shopping', result['second_user']['category'])
        self.assertEqual('dictionary', result['second_user']['source'])
        self.assertTrue(result['updated']['saved'])
        self.assertEqual('Health', result['updated_user']['category'])
        self.assertEqual({'saved': False, 'reason': 'limit_reached'}, result['limit'])

    def test_jarvis_parser_uses_the_authenticated_users_merchant_rule(self):
        result = self.run_php(r'''
function config_value(string $key, string $default = ''): string { return ''; }
require 'HELPER_PATH';
require 'JARVIS_ACTIONS';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY, user_id INTEGER, type TEXT, merchant TEXT, category TEXT, amount REAL, transaction_date TEXT)');
$pdo->exec('CREATE TABLE user_category_rules (id INTEGER PRIMARY KEY, user_id INTEGER, merchant_normalized TEXT, category TEXT, UNIQUE(user_id, merchant_normalized))');
category_classifier_save_user_rule($pdo, 1, 'Amazon', 'Transport');
$first = jarvis_validate_action($pdo, 1, jarvis_parse_action_command($pdo, 1, 'add expense 400 for Amazon'));
$second = jarvis_validate_action($pdo, 2, jarvis_parse_action_command($pdo, 2, 'add expense 400 for Amazon'));
echo json_encode(['first' => $first, 'second' => $second], JSON_THROW_ON_ERROR);
'''.replace('JARVIS_ACTIONS', str(self.root.joinpath('includes', 'jarvis_actions.php')).replace('\\', '/')))
        self.assertEqual('Transport', result['first']['category'])
        self.assertEqual('Shopping', result['second']['category'])

    def test_missing_migration_and_merchant_validation_are_explicit(self):
        result = self.run_php(r'''
require 'HELPER_PATH';
$pdo = new PDO('sqlite::memory:');
$suggestion = category_classifier_for_user($pdo, 1, 'Swiggy');
$save = category_classifier_save_user_rule($pdo, 1, 'Swiggy', 'Food & dining');
$invalidRejected = false;
try { category_classifier_validate_merchant(str_repeat('x', 161)); } catch (InvalidArgumentException $exception) { $invalidRejected = true; }
echo json_encode([
  'suggestion' => $suggestion,
  'save' => $save,
  'invalid_rejected' => $invalidRejected,
], JSON_THROW_ON_ERROR);
''')
        self.assertEqual('Food & dining', result['suggestion']['category'])
        self.assertFalse(result['suggestion']['learning_available'])
        self.assertEqual({'saved': False, 'reason': 'migration_required'}, result['save'])
        self.assertTrue(result['invalid_rejected'])

    def test_migration_is_owner_scoped_and_documents_the_500_rule_limit(self):
        migration = self.root.joinpath('database', 'user_category_rules.sql').read_text(encoding='utf-8')
        helper = self.helper.read_text(encoding='utf-8')
        self.assertIn('UNIQUE KEY uq_user_category_rules_owner_merchant (user_id, merchant_normalized)', migration)
        self.assertIn('FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', migration)
        self.assertIn('>= 500', helper)

    def test_category_processing_does_not_send_merchant_or_notes_to_an_llm(self):
        actions = self.root.joinpath('includes', 'jarvis_actions.php').read_text(encoding='utf-8')
        endpoint = self.root.joinpath('auth', 'category-suggestion.php').read_text(encoding='utf-8')
        self.assertNotIn('curl_init', actions)
        self.assertNotIn('jarvis_action_llm_extract', actions)
        self.assertNotIn('curl_init', endpoint)
        self.assertNotIn('JARVIS_API_KEY', endpoint)


if __name__ == '__main__':
    unittest.main()
