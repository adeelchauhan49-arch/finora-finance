<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/jarvis_financial_snapshot.php';
require_once __DIR__ . '/../includes/category_classifier.php';
require_once __DIR__ . '/../includes/transaction_validation.php';

$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in to manage transactions.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $statement = db()->prepare('SELECT id, type, merchant, category, amount, transaction_date AS date, transaction_time AS time, payment_method, location, notes, category_confidence FROM transactions WHERE user_id = :user_id ORDER BY transaction_date DESC, transaction_time DESC, id DESC');
    $statement->execute(['user_id' => $user['id']]);
    json_response(['ok' => true, 'transactions' => array_map(static function (array $item): array {
        $item['id'] = (int) $item['id'];
        $item['amount'] = (float) $item['amount'];
        return $item;
    }, $statement->fetchAll()), 'month_end_forecast' => jarvis_build_month_end_forecast(db(), (int) $user['id'])]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = request_body();
    $type = (string) ($data['type'] ?? '');
    $merchant = trim((string) ($data['merchant'] ?? ''));
    $category = trim((string) ($data['category'] ?? 'Other')) ?: 'Other';
    $categoryExplicit = ($data['category_explicit'] ?? false) === true;
    $amount = (float) ($data['amount'] ?? 0);
    $date = (string) ($data['date'] ?? '');
    $time = (string) ($data['time'] ?? '');
    $paymentMethod = (string) ($data['payment_method'] ?? '');
    $location = trim((string) ($data['location'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));
    $confidence = (float) ($data['category_confidence'] ?? 0);

    $validDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!in_array($type, ['income', 'expense'], true)
        || $merchant === ''
        || mb_strlen($merchant) > 160
        || mb_strlen($category) > 80
        || ($categoryExplicit && (!in_array($category, category_classifier_categories(), true)
            || ($category === 'Salary' && $type !== 'income')))
        || !is_finite($amount)
        || $amount <= 0
        || !$validDate
        || $validDate->format('Y-m-d') !== $date
        || ($time !== '' && !transaction_time_is_valid($time))
        || ($paymentMethod !== '' && !in_array($paymentMethod, ['cash', 'upi', 'credit_card', 'debit_card', 'wallet', 'bank_transfer'], true))
        || mb_strlen($location) > 160
        || mb_strlen($notes) > 500
        || !is_finite($confidence)) {
        json_response(['ok' => false, 'message' => 'Enter valid transaction details.'], 422);
    }
    $confidence = max(0, min(100, $confidence));

    $connection = db();
    $connection->beginTransaction();
    $statement = $connection->prepare('INSERT INTO transactions (user_id, type, merchant, category, amount, transaction_date, transaction_time, payment_method, location, notes, category_confidence) VALUES (:user_id, :type, :merchant, :category, :amount, :transaction_date, :transaction_time, :payment_method, :location, :notes, :category_confidence) RETURNING id');
    $statement->execute([
        'user_id' => $user['id'],
        'type' => $type,
        'merchant' => $merchant,
        'category' => $category,
        'amount' => $amount,
        'transaction_date' => $date,
        'transaction_time' => $time ?: null,
        'payment_method' => $paymentMethod ?: null,
        'location' => $location ?: null,
        'notes' => $notes ?: null,
        'category_confidence' => $confidence ?: null,
    ]);
    $transactionId = (int) $statement->fetchColumn();
    $connection->commit();
    $categoryLearning = $categoryExplicit
        ? category_classifier_save_user_rule($connection, (int) $user['id'], $merchant, $category)
        : null;
    json_response([
        'ok' => true,
        'transaction' => ['id' => $transactionId, 'type' => $type, 'merchant' => $merchant, 'category' => $category, 'amount' => $amount, 'date' => $date, 'time' => $time, 'payment_method' => $paymentMethod, 'location' => $location, 'notes' => $notes, 'category_confidence' => $confidence],
        'category_learning' => $categoryLearning,
    ], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = request_body();
    $transactionId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($transactionId === false) {
        json_response(['ok' => false, 'message' => 'Choose a valid transaction to delete.'], 422);
    }

    $statement = db()->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :user_id');
    $statement->execute(['id' => $transactionId, 'user_id' => $user['id']]);
    if ($statement->rowCount() === 0) {
        json_response(['ok' => false, 'message' => 'Transaction was not found.'], 404);
    }

    json_response(['ok' => true, 'message' => 'Transaction deleted.']);
}

json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
