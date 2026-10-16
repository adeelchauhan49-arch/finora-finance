<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in to manage savings goals.'], 401);
}

$requestMethod = $_SERVER['REQUEST_METHOD'];
if (!in_array($requestMethod, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PATCH, DELETE');
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$validDate = static function (string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    $errors = DateTime::getLastErrors();
    return $parsed !== false
        && $parsed->format('Y-m-d') === $date
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
};

if ($requestMethod === 'GET') {
    $statement = db()->prepare('SELECT id, title, target_amount, current_amount, deadline FROM savings_goals WHERE user_id = :user_id ORDER BY deadline IS NULL, deadline ASC, id DESC');
    $statement->execute(['user_id' => $user['id']]);
    $goals = $statement->fetchAll();
    if (!$goals) {
        json_response(['ok' => true, 'goals' => []]);
    }

    $contributionStatement = db()->prepare(
        'SELECT c.id, c.goal_id, c.amount, c.contribution_date
         FROM savings_goal_contributions c
         INNER JOIN savings_goals g ON g.id = c.goal_id AND g.user_id = c.user_id
         WHERE c.user_id = :user_id
         ORDER BY c.contribution_date DESC, c.id DESC'
    );
    $contributionStatement->execute(['user_id' => $user['id']]);
    $contributions = [];
    foreach ($contributionStatement->fetchAll() as $contribution) {
        $goalId = (int) $contribution['goal_id'];
        $contribution['id'] = (int) $contribution['id'];
        $contribution['amount'] = (float) $contribution['amount'];
        $contributions[$goalId][] = $contribution;
    }

    foreach ($goals as &$goal) {
        $goalId = (int) $goal['id'];
        $goal['id'] = $goalId;
        $goal['target_amount'] = (float) $goal['target_amount'];
        $goal['current_amount'] = (float) $goal['current_amount'];
        $goal['contributions'] = $contributions[$goalId] ?? [];
    }
    unset($goal);

    json_response(['ok' => true, 'goals' => $goals]);
}

$data = request_body();

if ($requestMethod === 'POST') {
    $action = (string) ($data['action'] ?? 'goal');
    if ($action === 'goal') {
        $title = trim((string) ($data['title'] ?? ''));
        $targetAmount = filter_var($data['target_amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $startingAmount = filter_var($data['starting_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $deadline = trim((string) ($data['deadline'] ?? ''));

        if ($title === ''
            || mb_strlen($title) > 120
            || $targetAmount === false
            || !is_finite((float) $targetAmount)
            || $targetAmount <= 0
            || $targetAmount > 9999999999.99
            || $startingAmount === false
            || !is_finite((float) $startingAmount)
            || $startingAmount < 0
            || $startingAmount > $targetAmount
            || !$validDate($deadline)) {
            json_response(['ok' => false, 'message' => 'Enter a valid goal name, target, starting amount, and target date.'], 422);
        }

        $statement = db()->prepare('INSERT INTO savings_goals (user_id, title, target_amount, current_amount, deadline) VALUES (:user_id, :title, :target_amount, :current_amount, :deadline) RETURNING id');
        $statement->execute([
            'user_id' => $user['id'],
            'title' => $title,
            'target_amount' => $targetAmount,
            'current_amount' => $startingAmount,
            'deadline' => $deadline,
        ]);
        json_response(['ok' => true, 'goal_id' => (int) $statement->fetchColumn(), 'message' => 'Savings goal created.'], 201);
    }

    if ($action === 'contribution') {
        $goalId = filter_var($data['goal_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $date = trim((string) ($data['date'] ?? ''));
        if ($goalId === false || $amount === false || !is_finite((float) $amount) || $amount <= 0 || $amount > 9999999999.99 || !$validDate($date)) {
            json_response(['ok' => false, 'message' => 'Enter a valid contribution amount and date.'], 422);
        }

        $connection = db();
        $connection->beginTransaction();
        try {
            $goalStatement = $connection->prepare('SELECT id FROM savings_goals WHERE id = :id AND user_id = :user_id FOR UPDATE');
            $goalStatement->execute(['id' => $goalId, 'user_id' => $user['id']]);
            if (!$goalStatement->fetch()) {
                $connection->rollBack();
                json_response(['ok' => false, 'message' => 'Savings goal was not found.'], 404);
            }

            $statement = $connection->prepare('INSERT INTO savings_goal_contributions (user_id, goal_id, amount, contribution_date) VALUES (:user_id, :goal_id, :amount, :contribution_date) RETURNING id');
            $statement->execute(['user_id' => $user['id'], 'goal_id' => $goalId, 'amount' => $amount, 'contribution_date' => $date]);
            $connection->prepare('UPDATE savings_goals SET current_amount = current_amount + :amount WHERE id = :id AND user_id = :user_id')
                ->execute(['amount' => $amount, 'id' => $goalId, 'user_id' => $user['id']]);
            $contributionId = (int) $statement->fetchColumn();
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            finora_log_exception('Finora savings goal contribution failed', $exception);
            json_response(['ok' => false, 'message' => 'Could not save this contribution. Please try again.'], 503);
        }

        json_response(['ok' => true, 'contribution_id' => $contributionId, 'message' => 'Contribution added.'], 201);
    }

    json_response(['ok' => false, 'message' => 'Invalid savings goal action.'], 400);
}

if ($requestMethod === 'PATCH') {
    $goalId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $title = trim((string) ($data['title'] ?? ''));
    $targetAmount = filter_var($data['target_amount'] ?? null, FILTER_VALIDATE_FLOAT);
    $deadline = trim((string) ($data['deadline'] ?? ''));
    if ($goalId === false
        || $title === ''
        || mb_strlen($title) > 120
        || $targetAmount === false
        || !is_finite((float) $targetAmount)
        || $targetAmount <= 0
        || $targetAmount > 9999999999.99
        || !$validDate($deadline)) {
        json_response(['ok' => false, 'message' => 'Enter a valid goal name, target, and target date.'], 422);
    }

    $statement = db()->prepare('UPDATE savings_goals SET title = :title, target_amount = :target_amount, deadline = :deadline WHERE id = :id AND user_id = :user_id');
    $statement->execute(['title' => $title, 'target_amount' => $targetAmount, 'deadline' => $deadline, 'id' => $goalId, 'user_id' => $user['id']]);
    if ($statement->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM savings_goals WHERE id = :id AND user_id = :user_id');
        $exists->execute(['id' => $goalId, 'user_id' => $user['id']]);
        if (!$exists->fetchColumn()) {
            json_response(['ok' => false, 'message' => 'Savings goal was not found.'], 404);
        }
    }

    json_response(['ok' => true, 'message' => 'Savings goal updated.']);
}

$action = (string) ($data['action'] ?? 'goal');
$recordId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($recordId === false) {
    json_response(['ok' => false, 'message' => 'Choose a valid savings goal or contribution.'], 422);
}

if ($action === 'contribution') {
    $connection = db();
    $connection->beginTransaction();
    try {
        $goalStatement = $connection->prepare(
            'SELECT g.id
             FROM savings_goal_contributions c
             INNER JOIN savings_goals g ON g.id = c.goal_id AND g.user_id = c.user_id
             WHERE c.id = :id AND c.user_id = :user_id AND g.user_id = :owner_id
             FOR UPDATE'
        );
        $goalStatement->execute(['id' => $recordId, 'user_id' => $user['id'], 'owner_id' => $user['id']]);
        $goalId = $goalStatement->fetchColumn();
        if ($goalId === false) {
            $connection->rollBack();
            json_response(['ok' => false, 'message' => 'Contribution was not found.'], 404);
        }

        $goalLock = $connection->prepare('SELECT id FROM savings_goals WHERE id = :id AND user_id = :user_id FOR UPDATE');
        $goalLock->execute(['id' => $goalId, 'user_id' => $user['id']]);
        $contributionStatement = $connection->prepare('SELECT amount FROM savings_goal_contributions WHERE id = :id AND goal_id = :goal_id AND user_id = :user_id FOR UPDATE');
        $contributionStatement->execute(['id' => $recordId, 'goal_id' => $goalId, 'user_id' => $user['id']]);
        $amount = $contributionStatement->fetchColumn();
        if ($amount === false) {
            $connection->rollBack();
            json_response(['ok' => false, 'message' => 'Contribution was not found.'], 404);
        }

        $connection->prepare('DELETE FROM savings_goal_contributions WHERE id = :id AND goal_id = :goal_id AND user_id = :user_id')
            ->execute(['id' => $recordId, 'goal_id' => $goalId, 'user_id' => $user['id']]);
        $connection->prepare('UPDATE savings_goals SET current_amount = current_amount - :amount WHERE id = :id AND user_id = :user_id')
            ->execute(['amount' => $amount, 'id' => $goalId, 'user_id' => $user['id']]);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        finora_log_exception('Finora savings goal contribution delete failed', $exception);
        json_response(['ok' => false, 'message' => 'Could not delete this contribution. Please try again.'], 503);
    }

    json_response(['ok' => true, 'message' => 'Contribution deleted.']);
}

if ($action !== 'goal') {
    json_response(['ok' => false, 'message' => 'Invalid savings goal action.'], 400);
}

$statement = db()->prepare('DELETE FROM savings_goals WHERE id = :id AND user_id = :user_id');
$statement->execute(['id' => $recordId, 'user_id' => $user['id']]);
if ($statement->rowCount() === 0) {
    json_response(['ok' => false, 'message' => 'Savings goal was not found.'], 404);
}

json_response(['ok' => true, 'message' => 'Savings goal deleted.']);
