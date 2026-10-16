<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/jarvis_actions.php';
require_method('POST');

$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in to let Jarvis update your Finora account.'], 401);
}

$data = request_body();
$mode = (string) ($data['mode'] ?? '');
if ($mode === 'preview') {
    $command = (string) ($data['command'] ?? '');
    try {
        jarvis_action_explicitly_forbidden($command);
    } catch (DomainException $exception) {
        json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
    }
}
$connection = db();
$isUndo = $mode === 'undo';
if (!$isUndo && finora_pro_enabled()) {
    $subscription = $connection->prepare("SELECT id FROM pro_subscriptions WHERE user_id = :user_id AND status = 'active' AND ends_at > CURRENT_TIMESTAMP LIMIT 1");
    $subscription->execute(['user_id' => $user['id']]);
    if (!$subscription->fetchColumn()) {
        json_response(['ok' => false, 'message' => 'Jarvis account actions are included with Finora Pro. You can still add expenses and income manually.'], 403);
    }
}

$connection->beginTransaction();
$connection->prepare(
    'INSERT INTO jarvis_action_rate_limits (user_id, window_started_at, request_count)
     VALUES (:user_id, CURRENT_TIMESTAMP, 1)
     ON CONFLICT (user_id) DO UPDATE SET
        request_count = CASE WHEN jarvis_action_rate_limits.window_started_at <= CURRENT_TIMESTAMP - INTERVAL \'1 minute\' THEN 1 ELSE jarvis_action_rate_limits.request_count + 1 END,
        window_started_at = CASE WHEN jarvis_action_rate_limits.window_started_at <= CURRENT_TIMESTAMP - INTERVAL \'1 minute\' THEN CURRENT_TIMESTAMP ELSE jarvis_action_rate_limits.window_started_at END'
)->execute(['user_id' => $user['id']]);
$rateStatement = $connection->prepare('SELECT request_count FROM jarvis_action_rate_limits WHERE user_id = :user_id FOR UPDATE');
$rateStatement->execute(['user_id' => $user['id']]);
$requestCount = (int) $rateStatement->fetchColumn();
$connection->commit();
if ($requestCount > 15) {
    json_response(['ok' => false, 'message' => 'Please wait a moment before asking Jarvis to make another change.'], 429);
}

$audit = static function (string $type, ?int $targetId, ?float $amount, ?string $date, string $outcome) use ($connection, $user): void {
    $connection->prepare(
        'INSERT INTO jarvis_action_audit (user_id, action_type, target_id, amount, action_date, outcome)
         VALUES (:user_id, :action_type, :target_id, :amount, :action_date, :outcome)'
    )->execute([
        'user_id' => $user['id'],
        'action_type' => $type,
        'target_id' => $targetId,
        'amount' => $amount,
        'action_date' => $date,
        'outcome' => $outcome,
    ]);
};

$createProposal = static function (array $action) use ($connection, $user, $audit): array {
    $token = bin2hex(random_bytes(32));
    $connection->prepare(
        'INSERT INTO jarvis_action_proposals (user_id, token_hash, action_type, payload, status, expires_at)
         VALUES (:user_id, :token_hash, :action_type, CAST(:payload AS jsonb), \'pending\', CURRENT_TIMESTAMP + INTERVAL \'15 minutes\')'
    )->execute([
        'user_id' => $user['id'],
        'token_hash' => hash('sha256', $token),
        'action_type' => $action['type'],
        'payload' => json_encode($action, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    $amount = isset($action['amount']) ? (float) $action['amount'] : (float) ($action['transaction']['amount'] ?? 0);
    $date = $action['date'] ?? ($action['transaction']['date'] ?? null);
    $audit($action['type'], isset($action['transaction']['id']) ? (int) $action['transaction']['id'] : ($action['goal_id'] ?? null), $amount, is_string($date) ? $date : null, 'previewed');
    return ['token' => $token, 'action' => $action];
};

$executeAction = static function (array $action, string $source = 'jarvis_confirmed') use ($connection, $user): array {
    $type = $action['type'];
    if (in_array($type, ['add_expense', 'add_income'], true)) {
        $notes = 'Added via confirmed Jarvis action';
        if ($source === 'jarvis_auto') {
            $notes = 'Added via confirmed Jarvis action';
        }
        $insert = $connection->prepare(
            'INSERT INTO transactions (user_id, type, merchant, category, amount, transaction_date, transaction_time, notes, category_confidence, source)
             VALUES (:user_id, :type, :merchant, :category, :amount, :transaction_date, CURRENT_TIME, :notes, 100, :source)
             RETURNING id'
        );
        $insert->execute([
            'user_id' => $user['id'],
            'type' => $type === 'add_income' ? 'income' : 'expense',
            'merchant' => $action['merchant'],
            'category' => $action['category'],
            'amount' => $action['amount'],
            'transaction_date' => $action['date'],
            'notes' => $notes,
            'source' => $source,
        ]);
        return ['target_id' => (int) $insert->fetchColumn(), 'amount' => (float) $action['amount'], 'date' => $action['date']];
    }
    if ($type === 'edit_transaction') {
        $transaction = $action['transaction'];
        $statement = $connection->prepare(
            'UPDATE transactions SET merchant = :merchant, category = :category, amount = :amount, transaction_date = :date
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'merchant' => $transaction['merchant'],
            'category' => $transaction['category'],
            'amount' => $transaction['amount'],
            'date' => $transaction['date'],
            'id' => $transaction['id'],
            'user_id' => $user['id'],
        ]);
        if ($statement->rowCount() === 0) {
            $check = $connection->prepare('SELECT 1 FROM transactions WHERE id = :id AND user_id = :user_id');
            $check->execute(['id' => $transaction['id'], 'user_id' => $user['id']]);
            if (!$check->fetchColumn()) {
                throw new DomainException('That transaction was not found in your account.');
            }
        }
        return ['target_id' => (int) $transaction['id'], 'amount' => (float) $transaction['amount'], 'date' => $transaction['date']];
    }
    if ($type === 'add_goal_contribution') {
        $insert = $connection->prepare(
            'INSERT INTO savings_goal_contributions (user_id, goal_id, amount, contribution_date)
             VALUES (:user_id, :goal_id, :amount, :contribution_date)
             RETURNING id'
        );
        $insert->execute([
            'user_id' => $user['id'],
            'goal_id' => $action['goal_id'],
            'amount' => $action['amount'],
            'contribution_date' => $action['date'],
        ]);
        $contributionId = (int) $insert->fetchColumn();
        $update = $connection->prepare('UPDATE savings_goals SET current_amount = current_amount + :amount WHERE id = :goal_id AND user_id = :user_id');
        $update->execute(['amount' => $action['amount'], 'goal_id' => $action['goal_id'], 'user_id' => $user['id']]);
        if ($update->rowCount() === 0) {
            throw new DomainException('That savings goal was not found in your account.');
        }
        return ['target_id' => $contributionId, 'amount' => (float) $action['amount'], 'date' => $action['date']];
    }
    if ($type === 'set_budget') {
        $connection->prepare(
            'INSERT INTO financial_profile (user_id, monthly_budget)
             VALUES (:user_id, :monthly_budget)
             ON CONFLICT (user_id) DO UPDATE SET monthly_budget = EXCLUDED.monthly_budget'
        )->execute(['user_id' => $user['id'], 'monthly_budget' => $action['amount']]);
        return ['target_id' => null, 'amount' => (float) $action['amount'], 'date' => null];
    }
    throw new DomainException('That action is not available through Jarvis.');
};

if ($mode === 'preview') {
    $command = trim((string) ($data['command'] ?? ''));
    if ($command === '' || mb_strlen($command) > 1000) {
        json_response(['ok' => false, 'message' => 'Enter a clear action for Jarvis to review.'], 422);
    }
    try {
        $parsed = jarvis_parse_action_command($connection, (int) $user['id'], $command);
        if ($parsed === null) {
            $audit('unrecognized', null, null, null, 'rejected');
            $message = jarvis_action_is_hinglish($command)
                ? 'Main ek clear supported action samajh nahi paaya, isliye kuch save nahi hua. Expense ya income mein se ek specify karein.'
                : 'I could not identify one clear supported action, so nothing was saved. Please specify either an expense or income.';
            json_response(['ok' => false, 'message' => $message], 422);
        }
        $requiresExplicitConfirmation = !empty($parsed['requires_confirmation']);
        $action = jarvis_validate_action($connection, (int) $user['id'], $parsed);
    } catch (DomainException $exception) {
        $audit('rejected', null, null, null, 'rejected');
        json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
    }

    if (in_array($action['type'], ['add_expense', 'add_income'], true)) {
        $settings = $connection->prepare('SELECT jarvis_auto_add_enabled, jarvis_auto_add_limit FROM financial_profile WHERE user_id = :user_id LIMIT 1');
        $settings->execute(['user_id' => $user['id']]);
        $preferences = $settings->fetch(PDO::FETCH_ASSOC) ?: [];
        $autoAddEnabled = (int) ($preferences['jarvis_auto_add_enabled'] ?? 0) === 1;
        $autoAddLimit = (float) ($preferences['jarvis_auto_add_limit'] ?? 5000);
        if (!$requiresExplicitConfirmation && jarvis_auto_add_allowed($autoAddEnabled, $action['type'], (float) $action['amount'], $autoAddLimit)) {
            $connection->beginTransaction();
            try {
                $result = $executeAction($action, 'jarvis_auto');
                $undoToken = bin2hex(random_bytes(32));
                $connection->prepare(
                    'INSERT INTO jarvis_action_undo_tokens (user_id, transaction_id, token_hash, expires_at)
                     VALUES (:user_id, :transaction_id, :token_hash, CURRENT_TIMESTAMP + INTERVAL \'2 minutes\')'
                )->execute([
                    'user_id' => $user['id'],
                    'transaction_id' => $result['target_id'],
                    'token_hash' => hash('sha256', $undoToken),
                ]);
                $audit($action['type'], $result['target_id'], $result['amount'], $result['date'], 'auto_added');
                $connection->commit();
                json_response(['ok' => true, 'auto_added' => true, 'undo_token' => $undoToken, 'transaction_id' => $result['target_id'], 'message' => 'Added via Jarvis. Undo is available for 2 minutes.'], 201);
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $exception;
            }
        }
    }

    $proposal = $createProposal($action);
    json_response(['ok' => true, 'proposal_token' => $proposal['token'], 'proposal' => $proposal['action']]);
}

if ($mode === 'revise') {
    $token = (string) ($data['proposal_token'] ?? '');
    $fields = $data['fields'] ?? null;
    if (strlen($token) !== 64 || !is_array($fields)) {
        json_response(['ok' => false, 'message' => 'This action preview is invalid or expired.'], 422);
    }
    $statement = $connection->prepare('SELECT id, payload FROM jarvis_action_proposals WHERE user_id = :user_id AND token_hash = :token_hash AND status = \'pending\' AND expires_at > CURRENT_TIMESTAMP LIMIT 1');
    $statement->execute(['user_id' => $user['id'], 'token_hash' => hash('sha256', $token)]);
    $pending = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$pending) {
        json_response(['ok' => false, 'message' => 'This action preview is invalid or expired.'], 404);
    }
    $action = json_decode((string) $pending['payload'], true);
    if (!is_array($action)) {
        json_response(['ok' => false, 'message' => 'This action preview is invalid or expired.'], 422);
    }
    $categoryCorrection = !empty($action['category_correction']);
    $originalCategory = ($action['type'] ?? '') === 'edit_transaction'
        ? (string) ($action['transaction']['category'] ?? '')
        : (string) ($action['category'] ?? '');
    foreach (['amount', 'merchant', 'category', 'date'] as $field) {
        if (array_key_exists($field, $fields)) {
            if ($field === 'category' && (string) $fields[$field] !== $originalCategory) {
                $categoryCorrection = true;
            }
            if (($action['type'] ?? '') === 'edit_transaction') {
                $action['transaction'][$field] = $fields[$field];
            } else {
                $action[$field] = $fields[$field];
            }
        }
    }
    try {
        $action = jarvis_validate_action($connection, (int) $user['id'], $action);
    } catch (DomainException $exception) {
        json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
    }
    if ($categoryCorrection && in_array($action['type'], ['add_expense', 'add_income', 'edit_transaction'], true)) {
        $action['category_correction'] = true;
    }
    $newToken = bin2hex(random_bytes(32));
    $connection->prepare(
        'UPDATE jarvis_action_proposals SET token_hash = :new_hash, payload = CAST(:payload AS jsonb), expires_at = CURRENT_TIMESTAMP + INTERVAL \'15 minutes\'
         WHERE id = :id AND user_id = :user_id AND token_hash = :old_hash AND status = \'pending\''
    )->execute([
        'new_hash' => hash('sha256', $newToken),
        'payload' => json_encode($action, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'id' => $pending['id'],
        'user_id' => $user['id'],
        'old_hash' => hash('sha256', $token),
    ]);
    $amount = isset($action['amount']) ? (float) $action['amount'] : (float) ($action['transaction']['amount'] ?? 0);
    $date = $action['date'] ?? ($action['transaction']['date'] ?? null);
    $audit($action['type'], isset($action['transaction']['id']) ? (int) $action['transaction']['id'] : ($action['goal_id'] ?? null), $amount, is_string($date) ? $date : null, 'revised');
    json_response(['ok' => true, 'proposal_token' => $newToken, 'proposal' => $action]);
}

if ($mode === 'cancel') {
    $token = (string) ($data['proposal_token'] ?? '');
    if (strlen($token) !== 64) {
        json_response(['ok' => false, 'message' => 'This action preview is invalid or expired.'], 422);
    }
    $statement = $connection->prepare('SELECT payload FROM jarvis_action_proposals WHERE user_id = :user_id AND token_hash = :token_hash AND status = \'pending\' LIMIT 1');
    $statement->execute(['user_id' => $user['id'], 'token_hash' => hash('sha256', $token)]);
    $payload = json_decode((string) $statement->fetchColumn(), true);
    $statement = $connection->prepare('UPDATE jarvis_action_proposals SET status = \'cancelled\' WHERE user_id = :user_id AND token_hash = :token_hash AND status = \'pending\'');
    $statement->execute(['user_id' => $user['id'], 'token_hash' => hash('sha256', $token)]);
    if (is_array($payload)) {
        $amount = isset($payload['amount']) ? (float) $payload['amount'] : (float) ($payload['transaction']['amount'] ?? 0);
        $date = $payload['date'] ?? ($payload['transaction']['date'] ?? null);
        $audit((string) $payload['type'], isset($payload['transaction']['id']) ? (int) $payload['transaction']['id'] : ($payload['goal_id'] ?? null), $amount, is_string($date) ? $date : null, 'cancelled');
    }
    json_response(['ok' => true, 'message' => 'Action cancelled.']);
}

if ($mode === 'confirm') {
    $token = (string) ($data['proposal_token'] ?? '');
    if (strlen($token) !== 64) {
        json_response(['ok' => false, 'message' => 'This action preview is invalid or expired.'], 422);
    }
    $connection->beginTransaction();
    try {
        $statement = $connection->prepare(
            'SELECT id, payload FROM jarvis_action_proposals
             WHERE user_id = :user_id AND token_hash = :token_hash AND status = \'pending\' AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['user_id' => $user['id'], 'token_hash' => hash('sha256', $token)]);
        $pending = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$pending) {
            $connection->rollBack();
            json_response(['ok' => false, 'message' => 'This action preview is invalid or expired.'], 404);
        }
        $decoded = json_decode((string) $pending['payload'], true);
        if (!is_array($decoded)) {
            throw new DomainException('This action preview is invalid or expired.');
        }
        $categoryCorrection = !empty($decoded['category_correction']);
        $action = jarvis_validate_action($connection, (int) $user['id'], $decoded);
        $result = $executeAction($action);
        $categoryLearningDetails = null;
        if ($categoryCorrection && in_array($action['type'], ['add_expense', 'add_income', 'edit_transaction'], true)) {
            $categoryLearningDetails = $action['type'] === 'edit_transaction' ? $action['transaction'] : $action;
        }
        $undoToken = null;
        if (in_array($action['type'], ['add_expense', 'add_income'], true)) {
            $undoToken = bin2hex(random_bytes(32));
            $connection->prepare(
                'INSERT INTO jarvis_action_undo_tokens (user_id, transaction_id, token_hash, expires_at)
                 VALUES (:user_id, :transaction_id, :token_hash, CURRENT_TIMESTAMP + INTERVAL \'2 minutes\')'
            )->execute([
                'user_id' => $user['id'],
                'transaction_id' => $result['target_id'],
                'token_hash' => hash('sha256', $undoToken),
            ]);
        }
        $connection->prepare('UPDATE jarvis_action_proposals SET status = \'confirmed\', confirmed_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $pending['id'], 'user_id' => $user['id']]);
        $audit($action['type'], $result['target_id'], $result['amount'], $result['date'], 'confirmed');
        $connection->commit();
        $categoryLearning = $categoryLearningDetails === null
            ? null
            : category_classifier_save_user_rule(
                $connection,
                (int) $user['id'],
                (string) $categoryLearningDetails['merchant'],
                (string) $categoryLearningDetails['category']
            );
        $response = ['ok' => true, 'action_type' => $action['type'], 'message' => 'Confirmed Jarvis action applied.', 'transaction_id' => $result['target_id']];
        if ($undoToken !== null) {
            $response['undo_token'] = $undoToken;
        }
        if ($categoryLearning !== null) {
            $response['category_learning'] = $categoryLearning;
        }
        json_response($response);
    } catch (DomainException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

if ($mode === 'undo') {
    $token = (string) ($data['undo_token'] ?? '');
    if (strlen($token) !== 64) {
        json_response(['ok' => false, 'message' => 'This Undo link is invalid or expired.'], 422);
    }
    $connection->beginTransaction();
    try {
        jarvis_undo_transaction($connection, (int) $user['id'], $token);
        $connection->commit();
        json_response(['ok' => true, 'message' => 'Jarvis transaction undone.']);
    } catch (DomainException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

json_response(['ok' => false, 'message' => 'That Jarvis action is not supported. No changes were made.'], 422);
