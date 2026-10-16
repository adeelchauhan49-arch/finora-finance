<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in to manage settings.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        ensure_financial_profile($user['id']);
    } catch (PDOException $exception) {
        finora_log_exception('Finora settings profile initialization failed', $exception);
        json_response(['ok' => false, 'message' => 'Could not load your financial profile. Please try again.'], 503);
    }

    $statement = db()->prepare('SELECT currency, monthly_budget, budget_allocations, jarvis_auto_add_enabled, jarvis_auto_add_limit, monthly_salary, business_income, freelance_income, other_income, monthly_income, monthly_savings_goal, savings_percentage FROM financial_profile WHERE user_id = :user_id LIMIT 1');
    $statement->execute(['user_id' => $user['id']]);
    $profile = $statement->fetch() ?: [];
    $profile['currency'] = $profile['currency'] ?? 'INR';
    $profile['monthly_budget'] = isset($profile['monthly_budget']) ? (float) $profile['monthly_budget'] : 2400.0;
    $profile['jarvis_auto_add_enabled'] = (bool) ($profile['jarvis_auto_add_enabled'] ?? false);
    $profile['jarvis_auto_add_limit'] = isset($profile['jarvis_auto_add_limit']) ? (float) $profile['jarvis_auto_add_limit'] : 5000.0;
    $allocations = !empty($profile['budget_allocations'])
        ? json_decode((string) $profile['budget_allocations'], true)
        : [];
    $profile['budget_allocations'] = is_array($allocations) ? $allocations : [];
    json_response(['ok' => true, 'user' => $user, 'settings' => $profile]);
}

require_method('POST');
$data = request_body();
$action = (string) ($data['action'] ?? '');

if ($action === 'jarvis_actions') {
    $enabled = $data['jarvis_auto_add_enabled'] ?? null;
    $limit = filter_var($data['jarvis_auto_add_limit'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!is_bool($enabled)
        || $limit === false
        || !is_finite((float) $limit)
        || $limit < 1
        || $limit > 9999999999.99) {
        json_response(['ok' => false, 'message' => 'Choose whether to enable auto-add and enter a valid transaction limit.'], 422);
    }
    $statement = db()->prepare(
        'INSERT INTO financial_profile (user_id, jarvis_auto_add_enabled, jarvis_auto_add_limit)
         VALUES (:user_id, :enabled, :limit)
         ON CONFLICT (user_id) DO UPDATE SET jarvis_auto_add_enabled = EXCLUDED.jarvis_auto_add_enabled, jarvis_auto_add_limit = EXCLUDED.jarvis_auto_add_limit'
    );
    $statement->execute(['user_id' => $user['id'], 'enabled' => $enabled ? 1 : 0, 'limit' => $limit]);
    json_response([
        'ok' => true,
        'settings' => ['jarvis_auto_add_enabled' => $enabled, 'jarvis_auto_add_limit' => (float) $limit],
        'message' => 'Jarvis action settings saved.',
    ]);
}

if ($action === 'profile') {
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'message' => 'Enter a valid name and email address.'], 422);
    }
    if (strtolower($email) !== strtolower((string) $user['email'])) {
        json_response(['ok' => false, 'message' => 'Change your email through Supabase Auth, then save your profile name.'], 422);
    }

    $connection = db();
    try {
        $connection->prepare('UPDATE users SET name = :name WHERE id = :user_id')
            ->execute(['name' => $name, 'user_id' => $user['id']]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[1] ?? null) === 1062) {
            json_response(['ok' => false, 'message' => 'Email already in use.'], 409);
        }
        finora_log_exception('Finora settings profile update failed', $exception);
        json_response(['ok' => false, 'message' => 'Could not save profile settings.'], 503);
    }

    $responseUser = ['id' => $user['id'], 'name' => $name, 'email' => $user['email'], 'pending_email' => null];
    $response = [
        'ok' => true,
        'user' => $responseUser,
        'message' => 'Saved successfully.',
        'verification_required' => false,
    ];
    json_response($response);
}

if ($action === 'financial_profile') {
    $incomeFields = ['monthly_salary', 'business_income', 'freelance_income', 'other_income'];
    $incomeValues = [];
    foreach ($incomeFields as $field) {
        $value = filter_var($data[$field] ?? null, FILTER_VALIDATE_FLOAT);
        if ($value === false || !is_finite((float) $value) || $value < 0 || $value > 9999999999.99) {
            json_response(['ok' => false, 'message' => 'Enter valid non-negative monthly income amounts.'], 422);
        }
        $incomeValues[$field] = (float) $value;
    }
    $monthlyIncome = array_sum($incomeValues);
    $savingsGoal = filter_var($data['monthly_savings_goal'] ?? null, FILTER_VALIDATE_FLOAT);
    $savingsPercentage = filter_var($data['savings_percentage'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!is_finite($monthlyIncome)
        || $monthlyIncome > 9999999999.99
        || $savingsGoal === false
        || !is_finite((float) $savingsGoal)
        || $savingsGoal < 0
        || $savingsGoal > 9999999999.99
        || $savingsPercentage === false
        || !is_finite((float) $savingsPercentage)
        || $savingsPercentage < 0
        || $savingsPercentage > 100) {
        json_response(['ok' => false, 'message' => 'Enter a valid savings goal and savings percentage from 0 to 100.'], 422);
    }

    $statement = db()->prepare(
        'INSERT INTO financial_profile (user_id, monthly_salary, business_income, freelance_income, other_income, monthly_income, monthly_savings_goal, savings_percentage)
         VALUES (:user_id, :monthly_salary, :business_income, :freelance_income, :other_income, :monthly_income, :monthly_savings_goal, :savings_percentage)
         ON CONFLICT (user_id) DO UPDATE SET monthly_salary = EXCLUDED.monthly_salary, business_income = EXCLUDED.business_income, freelance_income = EXCLUDED.freelance_income, other_income = EXCLUDED.other_income, monthly_income = EXCLUDED.monthly_income, monthly_savings_goal = EXCLUDED.monthly_savings_goal, savings_percentage = EXCLUDED.savings_percentage'
    );
    $statement->execute([
        'user_id' => $user['id'],
        'monthly_salary' => $incomeValues['monthly_salary'],
        'business_income' => $incomeValues['business_income'],
        'freelance_income' => $incomeValues['freelance_income'],
        'other_income' => $incomeValues['other_income'],
        'monthly_income' => $monthlyIncome,
        'monthly_savings_goal' => (float) $savingsGoal,
        'savings_percentage' => (float) $savingsPercentage,
    ]);
    json_response([
        'ok' => true,
        'settings' => [
            'monthly_salary' => $incomeValues['monthly_salary'],
            'business_income' => $incomeValues['business_income'],
            'freelance_income' => $incomeValues['freelance_income'],
            'other_income' => $incomeValues['other_income'],
            'monthly_income' => $monthlyIncome,
            'monthly_savings_goal' => (float) $savingsGoal,
            'savings_percentage' => (float) $savingsPercentage,
        ],
        'message' => 'Financial profile saved successfully.',
    ]);
}

if ($action === 'preferences') {
    $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
    $monthlyBudget = filter_var($data['monthly_budget'] ?? null, FILTER_VALIDATE_FLOAT);
    $categoryLimits = $data['category_limits'] ?? null;
    if (!in_array($currency, ['INR', 'USD', 'EUR'], true)
        || $monthlyBudget === false
        || !is_finite((float) $monthlyBudget)
        || $monthlyBudget < 1
        || $monthlyBudget > 9999999999.99
        || !is_array($categoryLimits)) {
        json_response(['ok' => false, 'message' => 'Enter a valid currency and monthly budget.'], 422);
    }

    $allocations = [];
    foreach ($categoryLimits as $category => $limit) {
        $category = trim((string) $category);
        $limit = filter_var($limit, FILTER_VALIDATE_FLOAT);
        if ($category === '' || mb_strlen($category) > 80 || $limit === false || !is_finite((float) $limit) || $limit < 0.01 || $limit > 9999999999.99) {
            json_response(['ok' => false, 'message' => 'Enter valid limits for every category.'], 422);
        }
        $allocations[$category] = ((float) $limit / (float) $monthlyBudget) * 100;
    }

    $statement = db()->prepare('INSERT INTO financial_profile (user_id, currency, monthly_budget, budget_allocations) VALUES (:user_id, :currency, :monthly_budget, CAST(:budget_allocations AS jsonb)) ON CONFLICT (user_id) DO UPDATE SET currency = EXCLUDED.currency, monthly_budget = EXCLUDED.monthly_budget, budget_allocations = EXCLUDED.budget_allocations');
    $statement->execute([
        'user_id' => $user['id'],
        'currency' => $currency,
        'monthly_budget' => $monthlyBudget,
        'budget_allocations' => json_encode($allocations, JSON_THROW_ON_ERROR),
    ]);
    json_response(['ok' => true, 'settings' => ['currency' => $currency, 'monthly_budget' => (float) $monthlyBudget, 'budget_allocations' => $allocations], 'message' => 'Saved successfully.']);
}

if ($action === 'password') {
    json_response(['ok' => false, 'message' => 'Password changes are handled by Supabase Auth.'], 410);
}

json_response(['ok' => false, 'message' => 'Invalid settings action.'], 400);