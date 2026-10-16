<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in to manage your financial setup.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $statement = db()->prepare('SELECT age, occupation, city, currency, monthly_salary, business_income, freelance_income, other_income, monthly_income, monthly_budget, monthly_savings_goal, monthly_investment_target, savings_percentage, emergency_fund_target, goal_deadline, dream_goal, lifestyle, spending_style, budget_allocations, onboarding_completed, onboarding_completed_at FROM financial_profile WHERE user_id = :user_id LIMIT 1');
    $statement->execute(['user_id' => $user['id']]);
    $profile = $statement->fetch();
    if ($profile && $profile['budget_allocations']) {
        $profile['budget_allocations'] = json_decode($profile['budget_allocations'], true);
    }
    $investmentStatement = db()->prepare('SELECT monthly_amount, investment_type FROM investment_plan WHERE user_id = :user_id ORDER BY id');
    $investmentStatement->execute(['user_id' => $user['id']]);
    $investmentPlans = $investmentStatement->fetchAll();
    $goalsStatement = db()->prepare('SELECT id, title, target_amount, current_amount, deadline FROM savings_goals WHERE user_id = :user_id ORDER BY deadline IS NULL, deadline ASC, id DESC LIMIT 20');
    $goalsStatement->execute(['user_id' => $user['id']]);
    json_response([
        'ok' => true,
        'profile' => $profile ?: null,
        'savings_goals' => $goalsStatement->fetchAll(),
        'investment_plans' => $investmentPlans,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$data = request_body();
$age = max(0, min(120, (int) ($data['age'] ?? 0)));
$fullName = trim((string) ($data['full_name'] ?? ''));
$occupation = trim((string) ($data['occupation'] ?? ''));
$city = trim((string) ($data['city'] ?? ''));
$currency = strtoupper(trim((string) ($data['currency'] ?? 'INR')));
$salary = max(0, (float) ($data['monthly_salary'] ?? 0));
$businessIncome = max(0, (float) ($data['business_income'] ?? 0));
$freelanceIncome = max(0, (float) ($data['freelance_income'] ?? 0));
$otherIncome = max(0, (float) ($data['other_income'] ?? 0));
$income = max(0, (float) ($data['monthly_income'] ?? 0));
$savingsGoal = max(0, (float) ($data['monthly_savings_goal'] ?? 0));
$investmentTarget = max(0, (float) ($data['monthly_investment_target'] ?? ($data['monthly_investment_amount'] ?? 0)));
$savingsPercentage = max(0, min(100, (float) ($data['savings_percentage'] ?? 0)));
$emergencyFund = max(0, (float) ($data['emergency_fund_target'] ?? 0));
$deadline = (string) ($data['goal_deadline'] ?? '');
$dreamGoal = trim((string) ($data['dream_goal'] ?? ''));
$lifestyle = (string) ($data['lifestyle'] ?? 'working_professional');
$spendingStyle = (string) ($data['spending_style'] ?? 'balanced');
$allocations = is_array($data['budget_allocations'] ?? null) ? $data['budget_allocations'] : [];
$investments = is_array($data['investment_types'] ?? null) ? $data['investment_types'] : [];
$investmentAmount = max(0, (float) ($data['monthly_investment_amount'] ?? 0));

if ($deadline !== '') {
    $deadlineDate = DateTime::createFromFormat('!Y-m-d', $deadline);
    $dateErrors = DateTime::getLastErrors();
    if (!$deadlineDate || $deadlineDate->format('Y-m-d') !== $deadline || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        json_response(['ok' => false, 'message' => 'Choose a valid goal deadline.'], 422);
    }
}
if (!in_array($lifestyle, ['student', 'working_professional', 'self_employed', 'freelancer'], true) || !in_array($spendingStyle, ['saver', 'balanced', 'spender'], true)) {
    json_response(['ok' => false, 'message' => 'Choose a valid lifestyle and spending style.'], 422);
}
if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
    json_response(['ok' => false, 'message' => 'Choose a valid three-letter currency code.'], 422);
}
$income = $salary + $businessIncome + $freelanceIncome + $otherIncome;

$connection = db();
$connection->beginTransaction();
try {
    if ($fullName !== '' && mb_strlen($fullName) >= 2) {
        $connection->prepare('UPDATE users SET name = :name WHERE id = :user_id')->execute(['name' => $fullName, 'user_id' => $user['id']]);
    }
    $profileStatement = $connection->prepare(
        'INSERT INTO financial_profile (
            user_id, age, occupation, city, currency, monthly_salary, business_income,
            freelance_income, other_income, monthly_income, monthly_savings_goal,
            monthly_investment_target, savings_percentage, emergency_fund_target,
            goal_deadline, dream_goal, lifestyle, spending_style, budget_allocations,
            onboarding_completed, onboarding_completed_at
        ) VALUES (
            :user_id, :age, :occupation, :city, :currency, :salary, :business_income,
            :freelance_income, :other_income, :income, :savings_goal, :investment_target,
            :savings_percentage, :emergency_fund, :deadline, :dream_goal, :lifestyle,
            :spending_style, CAST(:allocations AS jsonb), true, CURRENT_TIMESTAMP
        )
        ON CONFLICT (user_id) DO UPDATE SET
            age = EXCLUDED.age, occupation = EXCLUDED.occupation, city = EXCLUDED.city,
            currency = EXCLUDED.currency, monthly_salary = EXCLUDED.monthly_salary,
            business_income = EXCLUDED.business_income, freelance_income = EXCLUDED.freelance_income,
            other_income = EXCLUDED.other_income, monthly_income = EXCLUDED.monthly_income,
            monthly_savings_goal = EXCLUDED.monthly_savings_goal,
            monthly_investment_target = EXCLUDED.monthly_investment_target,
            savings_percentage = EXCLUDED.savings_percentage,
            emergency_fund_target = EXCLUDED.emergency_fund_target,
            goal_deadline = EXCLUDED.goal_deadline, dream_goal = EXCLUDED.dream_goal,
            lifestyle = EXCLUDED.lifestyle, spending_style = EXCLUDED.spending_style,
            budget_allocations = EXCLUDED.budget_allocations,
            onboarding_completed = true, onboarding_completed_at = CURRENT_TIMESTAMP'
    );
    $profileStatement->execute(['user_id' => $user['id'], 'age' => $age ?: null, 'occupation' => $occupation ?: null, 'city' => $city ?: null, 'currency' => $currency, 'salary' => $salary, 'business_income' => $businessIncome, 'freelance_income' => $freelanceIncome, 'other_income' => $otherIncome, 'income' => $income, 'savings_goal' => $savingsGoal, 'investment_target' => $investmentTarget, 'savings_percentage' => $savingsPercentage, 'emergency_fund' => $emergencyFund, 'deadline' => $deadline ?: null, 'dream_goal' => $dreamGoal ?: null, 'lifestyle' => $lifestyle, 'spending_style' => $spendingStyle, 'allocations' => json_encode($allocations, JSON_THROW_ON_ERROR)]);
    $connection->prepare('DELETE FROM investment_plan WHERE user_id = :user_id')->execute(['user_id' => $user['id']]);
    $investmentStatement = $connection->prepare('INSERT INTO investment_plan (user_id, monthly_amount, investment_type) VALUES (:user_id, :amount, :type)');
    foreach ($investments as $investment) {
        $investmentStatement->execute(['user_id' => $user['id'], 'amount' => $investmentAmount, 'type' => substr((string) $investment, 0, 40)]);
    }
    $connection->commit();
    json_response(['ok' => true, 'message' => 'Your financial setup is saved.']);
} catch (Throwable $exception) {
    finora_log_exception('Finora onboarding save failed', $exception);
    $connection->rollBack();
    json_response(['ok' => false, 'message' => 'Could not save your financial setup. Import database/premium_upgrade.sql and database/onboarding_completed.sql first.'], 503);
}
