<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/category_classifier.php';

function jarvis_action_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    $today = new DateTimeImmutable('today');
    return $parsed !== false
        && $parsed->format('Y-m-d') === $date
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed >= $today->modify('-10 years')
        && $parsed <= $today;
}

function jarvis_action_date_from_text(string $text): string
{
    if (preg_match('/\b(today|aaj)\b/iu', $text) === 1) {
        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }
    if (preg_match('/\bkal\b/iu', $text) === 1) {
        throw new DomainException('Please clarify whether "kal" means yesterday or tomorrow.');
    }
    if (preg_match('/\byesterday\b/iu', $text) === 1) {
        return (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    }
    if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $match) === 1) {
        return $match[1];
    }
    if (preg_match('/\b(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})\b/', $text, $match) === 1) {
        return sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
    }
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

function jarvis_action_amount_from_text(string $text): ?float
{
    $pattern = '/(?<![\p{L}\d,.+\-])(?:rs\.?|inr|usd|eur|[$€₹])?\s*((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)\s*(k)?\s*(?:rs\.?|inr|usd|eur)?(?![\p{L}\d,.])/iu';
    if (preg_match($pattern, $text, $match) !== 1) {
        return null;
    }
    $amount = (float) str_replace(',', '', $match[1]);
    return !empty($match[2]) ? $amount * 1000 : $amount;
}

function jarvis_action_uses_shorthand_amount(string $text): bool
{
    return preg_match('/(?<![\p{L}\d,.+\-])(?:rs\.?|inr|usd|eur|[$€₹])?\s*(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?\s*k\s*(?:rs\.?|inr|usd|eur)?(?![\p{L}\d,.])/iu', $text) === 1;
}

function jarvis_action_category(string $text): string
{
    $categories = [
        'Salary' => ['salary', 'income'],
        'Transport' => ['travel', 'uber', 'ola', 'petrol', 'bus', 'metro', 'auto', 'transport', 'fuel', 'taxi', 'train'],
        'Food & dining' => ['grocery', 'groceries', 'food', 'restaurant', 'dominos', 'swiggy', 'zomato', 'dining', 'cafe', 'coffee', 'lunch', 'dinner', 'breakfast'],
        'Housing' => ['rent', 'housing', 'home'],
        'Utilities' => ['electricity', 'bill', 'utilities', 'water', 'internet', 'recharge'],
        'Shopping' => ['shopping', 'amazon', 'flipkart', 'clothes', 'clothing', 'shop'],
        'Health' => ['health', 'medical', 'doctor', 'pharmacy', 'medicine', 'hospital'],
        'Entertainment' => ['entertainment', 'movie', 'netflix', 'spotify', 'gaming'],
    ];
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $matches = [];
    foreach ($categories as $category => $terms) {
        foreach ($terms as $term) {
            $termWords = preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($termWords && array_diff($termWords, $words) === []) {
                $matches[$category] = true;
            }
        }
    }
    return count($matches) === 1 ? (string) array_key_first($matches) : 'Other';
}

function jarvis_action_is_hinglish(string $text): bool
{
    return preg_match('/\b(aaya|aayi|mila|kharch|kharcha|kiya|karo|kar|meri|mera|mere|mein|hai|nahi|ka|ke|ko|se|paise|rupees?)\b/iu', $text) === 1;
}

function jarvis_action_explicitly_forbidden(string $text): bool
{
    if (preg_match('/\b(?:delete|remove)\b.*\b(?:transaction|transactions|expense|expenses|income|record)\b|\b(?:transaction|transactions|expense|expenses|income|record)\b.*\b(?:delete|remove)\b/iu', $text) === 1) {
        throw new DomainException('Main transaction delete nahi kar sakta. Transactions list mein trash icon se khud delete karo, ya abhi add ki hui transaction ke liye Undo dabao.');
    }
    return preg_match('/\b(delete|remove|refund|payment|pay|razorpay|purchase\s+(?:pro|premium)|change\s+(?:my\s+)?(?:password|email|profile)|update\s+(?:my\s+)?(?:password|email|profile)|ignore|disregard|override|forget|system\s+prompt|developer\s+instructions?|act\s+as)\b/iu', $text) === 1;
}

function jarvis_action_word_matches(string $word, array $aliases): bool
{
    if (in_array($word, $aliases, true)) {
        return true;
    }
    foreach ($aliases as $alias) {
        $distance = levenshtein($word, $alias);
        $maxDistance = mb_strlen($alias) >= 6 ? 2 : 1;
        if ($distance > 0 && $distance <= $maxDistance) {
            return true;
        }
    }
    return false;
}

function jarvis_rule_action_type(string $command): ?string
{
    if (preg_match('/\b(set|change)\b.*\b(budget|limit)\b|\b(budget|limit)\b.*\b(set|to)\b/iu', $command) === 1) {
        return 'set_budget';
    }
    if (preg_match('/\b(contribute|contribution|add\s+to\s+(?:my\s+)?(?:fund|goal)|add\s+(?:rs\.?\s*)?[\d,.]+\s+to\b)/iu', $command) === 1) {
        return 'add_goal_contribution';
    }
    if (preg_match('/\b(edit|update)\s+(?:my\s+)?transaction\b/iu', $command) === 1) {
        return 'edit_transaction';
    }
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($command), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $expenseAliases = ['expense', 'expence', 'expens', 'spent', 'spend', 'paid', 'kharch', 'kharcha', 'grocery', 'groceries', 'bought', 'buy'];
    $incomeAliases = ['income', 'salary', 'received', 'earned', 'aaya', 'aayi', 'mila', 'credit', 'freelance', 'credited'];
    $hasExpense = false;
    $hasIncome = false;
    foreach ($words as $word) {
        $hasExpense = $hasExpense || jarvis_action_word_matches($word, $expenseAliases);
        $hasIncome = $hasIncome || jarvis_action_word_matches($word, $incomeAliases);
    }
    if ($hasExpense === $hasIncome) {
        return null;
    }
    return $hasIncome ? 'add_income' : 'add_expense';
}

function jarvis_parse_action_command(PDO $connection, int $userId, string $command): ?array
{
    if (jarvis_action_explicitly_forbidden($command)) {
        throw new DomainException('That action is not available through Jarvis.');
    }
    $ruleType = jarvis_rule_action_type($command);
    if ($ruleType === null) {
        return null;
    }

    $extracted = [];
    $type = $ruleType;

    if ($type === 'set_budget') {
        return ['type' => $type, 'amount' => $extracted['amount'] ?? jarvis_action_amount_from_text($command)];
    }
    if ($type === 'add_goal_contribution') {
        $goalName = trim((string) ($extracted['goal_name'] ?? ''));
        if ($goalName === '') {
            $goalName = trim(preg_replace('/\b(add|contribute|contribution|save|saved|to|for|goal|my|rs\.?|inr|usd|eur)\b|[0-9][0-9,.]*/iu', ' ', $command) ?? '');
        }
        $goalStatement = $connection->prepare('SELECT id, title FROM savings_goals WHERE user_id = :user_id ORDER BY id');
        $goalStatement->execute(['user_id' => $userId]);
        $goals = $goalStatement->fetchAll(PDO::FETCH_ASSOC);
        $matched = null;
        foreach ($goals as $goal) {
            if (mb_stripos($command, (string) $goal['title']) !== false || mb_strtolower(trim((string) $goal['title'])) === mb_strtolower($goalName)) {
                if ($matched !== null) {
                    throw new DomainException('Please specify which savings goal you mean.');
                }
                $matched = $goal;
            }
        }
        if ($matched === null) {
            throw new DomainException('I could not match that to one of your savings goals.');
        }
        return [
            'type' => $type,
            'goal_id' => (int) $matched['id'],
            'goal_name' => (string) $matched['title'],
            'amount' => $extracted['amount'] ?? jarvis_action_amount_from_text($command),
            'date' => ($extracted['date'] ?? '') !== '' ? (string) $extracted['date'] : jarvis_action_date_from_text($command),
        ];
    }
    if ($type === 'edit_transaction') {
        $transactionId = filter_var($extracted['transaction_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($transactionId === false || $transactionId === null) {
            if (preg_match('/\btransaction\s+#?(\d+)\b/iu', $command, $match) !== 1) {
                throw new DomainException('Include the transaction number you want to edit.');
            }
            $transactionId = (int) $match[1];
        }
        $statement = $connection->prepare('SELECT id, type, merchant, category, amount, transaction_date AS date FROM transactions WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $transactionId, 'user_id' => $userId]);
        $transaction = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$transaction) {
            throw new DomainException('That transaction was not found in your account.');
        }
        $amount = $extracted['amount'] ?? null;
        if ($amount === null && preg_match('/\bamount\s+(?:to\s+)?(?:rs\.?|inr|usd|eur|[$€₹])?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $command, $match) === 1) {
            $amount = (float) str_replace(',', '', $match[1]);
        }
        if ($amount !== null) {
            $transaction['amount'] = $amount;
        }
        if (!empty($extracted['category'])) {
            $transaction['category'] = (string) $extracted['category'];
        }
        if (!empty($extracted['date'])) {
            $transaction['date'] = (string) $extracted['date'];
        }
        if (!empty($extracted['merchant'])) {
            $transaction['merchant'] = trim((string) $extracted['merchant']);
        }
        return ['type' => $type, 'transaction' => $transaction];
    }

    $amount = jarvis_action_amount_from_text($command);
    $date = ($extracted['date'] ?? '') !== '' ? (string) $extracted['date'] : jarvis_action_date_from_text($command);
    $category = jarvis_action_category($command);
    $merchant = trim((string) ($extracted['merchant'] ?? ''));
    if ($merchant === '' && $type === 'add_income') {
        $merchant = preg_match('/\bsalary\b/iu', $command) === 1 ? 'Salary' : (preg_match('/\bfreelance\b/iu', $command) === 1 ? 'Freelance' : 'Income');
    }
    if ($merchant === '') {
        $merchant = preg_replace('/(?<![\p{L}\d])(?:rs\.?|inr|usd|eur|[$€₹])?\s*(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?\s*k?\s*(?:rs\.?|inr|usd|eur)?(?![\p{L}\d])|\b(today|aaj|yesterday|kal|\d{4}-\d{2}-\d{2}|\d{1,2}[\/-]\d{1,2}[\/-]\d{4}|add|record|log|expense|expence|expens|income|spent|spend|paid|bought|buy|salary|received|credited|aayi|aaya|mila|earned|freelance|credit|kharch|kharcha|kiya|karo|ka|ke|ko|se|mein|for|on|in|date|category)\b/iu', ' ', $command) ?? '';
        $merchant = trim(preg_replace('/\s+/', ' ', $merchant) ?? '');
        $merchant = trim($merchant, " \t\n\r\0\x0B,.;:-");
    }
    $personalCategory = category_classifier_find_user_rule($connection, $userId, $merchant);
    if ($personalCategory !== null) {
        $category = $personalCategory;
    } elseif ($category === 'Other') {
        $category = category_classifier_suggestion($merchant)['category'];
    }
    $action = [
        'type' => $type,
        'amount' => $amount,
        'merchant' => $merchant,
        'category' => $category,
        'date' => $date,
    ];
    if (jarvis_action_uses_shorthand_amount($command)) {
        $action['requires_confirmation'] = true;
    }
    return $action;
}

function jarvis_auto_add_allowed(bool $enabled, string $type, float $amount, float $limit): bool
{
    return $enabled
        && in_array($type, ['add_expense', 'add_income'], true)
        && is_finite($amount)
        && is_finite($limit)
        && $amount > 0
        && $amount <= $limit;
}

function jarvis_undo_transaction(PDO $connection, int $userId, string $token): array
{
    $lock = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $statement = $connection->prepare(
        'SELECT id, transaction_id FROM jarvis_action_undo_tokens
         WHERE user_id = :user_id AND token_hash = :token_hash AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP
         LIMIT 1' . $lock
    );
    $statement->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $token)]);
    $undo = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$undo) {
        throw new DomainException('This Undo link is invalid or expired.');
    }

    $transactionStatement = $connection->prepare(
        'SELECT type, amount, transaction_date FROM transactions
         WHERE id = :id AND user_id = :user_id AND source IN (\'jarvis_auto\', \'jarvis_confirmed\')
           AND notes = \'Added via confirmed Jarvis action\'
         LIMIT 1' . $lock
    );
    $transactionStatement->execute(['id' => $undo['transaction_id'], 'user_id' => $userId]);
    $transaction = $transactionStatement->fetch(PDO::FETCH_ASSOC);
    if (!$transaction) {
        throw new DomainException('This transaction can no longer be undone.');
    }

    $markUsed = $connection->prepare(
        'UPDATE jarvis_action_undo_tokens SET used_at = CURRENT_TIMESTAMP
         WHERE id = :id AND user_id = :user_id AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    );
    $markUsed->execute(['id' => $undo['id'], 'user_id' => $userId]);
    if ($markUsed->rowCount() !== 1) {
        throw new DomainException('This Undo link is invalid or expired.');
    }

    $delete = $connection->prepare(
        'DELETE FROM transactions
         WHERE id = :id AND user_id = :user_id AND source IN (\'jarvis_auto\', \'jarvis_confirmed\')
           AND notes = \'Added via confirmed Jarvis action\''
    );
    $delete->execute(['id' => $undo['transaction_id'], 'user_id' => $userId]);
    if ($delete->rowCount() !== 1) {
        throw new DomainException('This transaction can no longer be undone.');
    }

    $connection->prepare(
        'INSERT INTO jarvis_action_audit (user_id, action_type, target_id, amount, action_date, outcome)
         VALUES (:user_id, :action_type, :target_id, :amount, :action_date, \'undone\')'
    )->execute([
        'user_id' => $userId,
        'action_type' => 'undo_add_' . $transaction['type'],
        'target_id' => $undo['transaction_id'],
        'amount' => $transaction['amount'],
        'action_date' => $transaction['transaction_date'],
    ]);

    return ['target_id' => (int) $undo['transaction_id']];
}

function jarvis_validate_action(PDO $connection, int $userId, array $action): array
{
    $type = $action['type'] ?? null;
    $amountSource = $type === 'edit_transaction' ? ($action['transaction']['amount'] ?? null) : ($action['amount'] ?? null);
    $amount = filter_var($amountSource, FILTER_VALIDATE_FLOAT);
    if (!in_array($type, ['add_expense', 'add_income', 'edit_transaction', 'add_goal_contribution', 'set_budget'], true)) {
        throw new DomainException('That action is not available through Jarvis.');
    }
    if ($amount === false || !is_finite((float) $amount) || $amount <= 0 || $amount > 9999999999.99) {
        throw new DomainException('Enter a positive amount within the supported limit.');
    }

    if ($type === 'set_budget') {
        return ['type' => $type, 'amount' => (float) $amount];
    }
    if ($type === 'add_goal_contribution') {
        $goalId = filter_var($action['goal_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $date = (string) ($action['date'] ?? '');
        if ($goalId === false || !jarvis_action_valid_date($date)) {
            throw new DomainException('Enter a valid goal contribution date and amount.');
        }
        $statement = $connection->prepare('SELECT title FROM savings_goals WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $goalId, 'user_id' => $userId]);
        $goalName = $statement->fetchColumn();
        if (!is_string($goalName)) {
            throw new DomainException('That savings goal was not found in your account.');
        }
        return ['type' => $type, 'goal_id' => (int) $goalId, 'goal_name' => $goalName, 'amount' => (float) $amount, 'date' => $date];
    }
    if ($type === 'edit_transaction') {
        $transaction = $action['transaction'] ?? null;
        $transactionId = is_array($transaction) ? filter_var($transaction['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($transactionId === false || !is_array($transaction)) {
            throw new DomainException('Choose a valid transaction to edit.');
        }
        $statement = $connection->prepare('SELECT id, type, merchant, category, amount, transaction_date AS date FROM transactions WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $transactionId, 'user_id' => $userId]);
        $current = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            throw new DomainException('That transaction was not found in your account.');
        }
        $merchant = trim((string) ($transaction['merchant'] ?? $current['merchant']));
        $category = trim((string) ($transaction['category'] ?? $current['category']));
        $date = (string) ($transaction['date'] ?? $current['date']);
        if ($merchant === '' || mb_strlen($merchant) > 160 || $category === '' || mb_strlen($category) > 80
            || ($category !== (string) $current['category'] && !in_array($category, category_classifier_categories(), true))
            || !jarvis_action_valid_date($date)) {
            throw new DomainException('Enter valid transaction details.');
        }
        return ['type' => $type, 'transaction' => [
            'id' => (int) $transactionId,
            'type' => $current['type'],
            'merchant' => $merchant,
            'category' => $category,
            'amount' => (float) $amount,
            'date' => $date,
        ]];
    }

    $merchant = trim((string) ($action['merchant'] ?? ''));
    $category = trim((string) ($action['category'] ?? 'Other')) ?: 'Other';
    $date = (string) ($action['date'] ?? '');
    if ($merchant === '' || mb_strlen($merchant) > 160 || mb_strlen($category) > 80 || !in_array($category, category_classifier_categories(), true) || !jarvis_action_valid_date($date)) {
        throw new DomainException('Enter a title, category, and valid date for the transaction.');
    }
    return ['type' => $type, 'amount' => (float) $amount, 'merchant' => $merchant, 'category' => $category, 'date' => $date];
}
