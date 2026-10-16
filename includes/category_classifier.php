<?php
declare(strict_types=1);

function category_classifier_categories(): array
{
    return ['Food & dining', 'Shopping', 'Transport', 'Housing', 'Health', 'Utilities', 'Entertainment', 'Other', 'Salary'];
}

function category_classifier_normalize_merchant(string $merchant): string
{
    $merchant = mb_strtolower(trim($merchant), 'UTF-8');
    $merchant = preg_replace('/[\p{N}\p{P}\p{S}]+/u', ' ', $merchant) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $merchant) ?? '');
}

function category_classifier_validate_merchant(string $merchant): string
{
    $merchant = trim($merchant);
    if (!mb_check_encoding($merchant, 'UTF-8') || $merchant === '' || mb_strlen($merchant, 'UTF-8') > 160) {
        throw new InvalidArgumentException('Enter a valid merchant or transaction title.');
    }
    return $merchant;
}

function category_classifier_dictionary(): array
{
    return [
        'Food & dining' => [
            'swiggy', 'zomato', 'blinkit', 'zepto', 'bigbasket', 'big basket', 'dmart', 'dm art',
            'grocery', 'groceries', 'food', 'restaurant', 'dominos', 'domino', 'pizza hut',
            'mcdonalds', 'mcdonald', 'kfc', 'subway', 'dunzo', 'instamart', 'jiomart',
            'kirana', 'rashan', 'ration', 'sabzi', 'khana', 'nashta',
        ],
        'Transport' => [
            'uber', 'ola', 'rapido', 'irctc', 'petrol', 'diesel', 'metro', 'bus', 'auto',
            'fuel', 'transport', 'travel', 'taxi', 'train', 'railway', 'namma metro', 'safar', 'gaadi',
        ],
        'Utilities' => [
            'jio', 'airtel', 'electricity', 'electric bill', 'water bill', 'utility', 'utilities',
            'internet', 'broadband', 'recharge', 'mobile bill', 'gas bill', 'phone bill', 'bijli', 'bijli bill',
        ],
        'Housing' => ['rent', 'landlord', 'housing', 'home rent', 'maintenance', 'kiraya'],
        'Entertainment' => [
            'netflix', 'hotstar', 'disney hotstar', 'movie', 'cinema', 'spotify', 'prime video',
            'pvr', 'inox', 'bookmyshow', 'gaming', 'youtube premium', 'picture',
        ],
        'Shopping' => [
            'amazon', 'flipkart', 'myntra', 'shopping', 'clothing', 'clothes', 'fashion',
            'ajio', 'nykaa', 'meesho', 'croma', 'reliance digital', 'kapde',
        ],
        'Health' => [
            'pharmacy', 'doctor', 'clinic', 'hospital', 'medicine', 'medical', 'apollo pharmacy',
            'netmeds', '1mg', 'tata 1mg', 'diagnostic', 'dawai', 'dawa',
        ],
    ];
}

function category_classifier_suggestion(string $merchant): array
{
    $normalized = category_classifier_normalize_merchant($merchant);
    if ($normalized === '') {
        return ['category' => 'Other', 'source' => 'none', 'uncertain' => true];
    }

    $dictionary = category_classifier_dictionary();
    $exactMatches = [];
    foreach ($dictionary as $category => $terms) {
        foreach ($terms as $term) {
            $normalizedTerm = category_classifier_normalize_merchant($term);
            if (preg_match('/(?:^| )' . preg_quote($normalizedTerm, '/') . '(?: |$)/u', $normalized) === 1) {
                $exactMatches[$category] = true;
            }
        }
    }
    if (count($exactMatches) === 1) {
        return ['category' => (string) array_key_first($exactMatches), 'source' => 'dictionary', 'uncertain' => false];
    }
    if (count($exactMatches) > 1) {
        return ['category' => 'Other', 'source' => 'ambiguous', 'uncertain' => true];
    }

    $words = explode(' ', $normalized);
    $fuzzyMatches = [];
    foreach ($words as $word) {
        if (!preg_match('/^[a-z]+$/i', $word) || strlen($word) < 5) {
            continue;
        }
        foreach ($dictionary as $category => $terms) {
            foreach ($terms as $term) {
                $normalizedTerm = category_classifier_normalize_merchant($term);
                if (str_contains($normalizedTerm, ' ') || abs(strlen($word) - strlen($normalizedTerm)) > 2) {
                    continue;
                }
                $distance = levenshtein($word, $normalizedTerm);
                if ($distance > 0 && $distance <= 2 && $distance / max(strlen($word), strlen($normalizedTerm)) <= 0.40) {
                    $fuzzyMatches[$category] = true;
                }
            }
        }
    }
    if (count($fuzzyMatches) === 1) {
        return ['category' => (string) array_key_first($fuzzyMatches), 'source' => 'fuzzy', 'uncertain' => false];
    }
    return [
        'category' => 'Other',
        'source' => count($fuzzyMatches) > 1 ? 'ambiguous' : 'unknown',
        'uncertain' => true,
    ];
}

function category_classifier_rules_table_exists(PDO $connection): bool
{
    $driver = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $statement = $connection->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
        );
        $statement->execute(['table_name' => 'user_category_rules']);
        return (bool) $statement->fetchColumn();
    }
    if ($driver === 'sqlite') {
        $statement = $connection->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1"
        );
        $statement->execute(['table_name' => 'user_category_rules']);
        return (bool) $statement->fetchColumn();
    }
    if ($driver === 'pgsql') {
        $statement = $connection->prepare("SELECT to_regclass('public.user_category_rules') IS NOT NULL");
        $statement->execute();
        return (bool) $statement->fetchColumn();
    }
    throw new RuntimeException('Unsupported database driver for category rules.');
}

function category_classifier_find_user_rule(PDO $connection, int $userId, string $merchant): ?string
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user is required for personalized categories.');
    }
    $normalized = category_classifier_normalize_merchant($merchant);
    if ($normalized === '' || !category_classifier_rules_table_exists($connection)) {
        return null;
    }
    $statement = $connection->prepare(
        'SELECT category FROM user_category_rules WHERE user_id = :user_id AND merchant_normalized = :merchant LIMIT 1'
    );
    $statement->execute(['user_id' => $userId, 'merchant' => $normalized]);
    $category = $statement->fetchColumn();
    return is_string($category) ? $category : null;
}

function category_classifier_for_user(PDO $connection, int $userId, string $merchant): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user is required for personalized categories.');
    }
    $merchant = category_classifier_validate_merchant($merchant);
    $ruleTableAvailable = category_classifier_rules_table_exists($connection);
    $rule = $ruleTableAvailable ? category_classifier_find_user_rule($connection, $userId, $merchant) : null;
    $suggestion = $rule !== null
        ? ['category' => $rule, 'source' => 'personal', 'uncertain' => false]
        : category_classifier_suggestion($merchant);
    $suggestion['learning_available'] = $ruleTableAvailable;
    return $suggestion;
}

function category_classifier_save_user_rule(PDO $connection, int $userId, string $merchant, string $category): array
{
    $merchant = category_classifier_validate_merchant($merchant);
    $normalized = category_classifier_normalize_merchant($merchant);
    if ($userId < 1 || $normalized === '' || !in_array($category, category_classifier_categories(), true)) {
        throw new InvalidArgumentException('The merchant category rule is invalid.');
    }
    try {
        $rulesTableAvailable = category_classifier_rules_table_exists($connection);
    } catch (PDOException $exception) {
        finora_log_exception('Personalized category rule storage check failed', $exception);
        return ['saved' => false, 'reason' => 'save_failed'];
    }
    if (!$rulesTableAvailable) {
        return ['saved' => false, 'reason' => 'migration_required'];
    }

    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $connection->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE')->execute(['user_id' => $userId]);
        }
        $existing = $connection->prepare(
            'SELECT id FROM user_category_rules WHERE user_id = :user_id AND merchant_normalized = :merchant LIMIT 1'
        );
        $existing->execute(['user_id' => $userId, 'merchant' => $normalized]);
        $ruleId = $existing->fetchColumn();
        if ($ruleId === false) {
            $count = $connection->prepare('SELECT COUNT(*) FROM user_category_rules WHERE user_id = :user_id');
            $count->execute(['user_id' => $userId]);
            if ((int) $count->fetchColumn() >= 500) {
                if ($ownsTransaction) {
                    $connection->commit();
                }
                return ['saved' => false, 'reason' => 'limit_reached'];
            }
            $connection->prepare(
                'INSERT INTO user_category_rules (user_id, merchant_normalized, category) VALUES (:user_id, :merchant, :category)'
            )->execute(['user_id' => $userId, 'merchant' => $normalized, 'category' => $category]);
        } else {
            $connection->prepare(
                'UPDATE user_category_rules SET category = :category, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id'
            )->execute(['category' => $category, 'id' => $ruleId, 'user_id' => $userId]);
        }
        if ($ownsTransaction) {
            $connection->commit();
        }
        return ['saved' => true, 'reason' => 'saved'];
    } catch (PDOException $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        finora_log_exception('Personalized category rule save failed', $exception);
        return ['saved' => false, 'reason' => 'save_failed'];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}
