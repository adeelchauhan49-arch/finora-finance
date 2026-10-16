<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_method('POST');

$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in before purchasing Finora Pro.'], 401);
}

if (!finora_pro_enabled()) {
    json_response(['error' => 'Not available'], 404);
}

require_once __DIR__ . '/../includes/razorpay.php';

$data = request_body();
$promoCode = trim((string) ($data['promo_code'] ?? ''));
if ($promoCode !== '') {
    $configuredPromoCode = trim((string) config_value('FINORA_PRO_PROMO_CODE', ''));
    if (!finora_promo_code_matches($promoCode, $configuredPromoCode)) {
        json_response(['ok' => false, 'message' => 'That promo code is invalid.'], 422);
    }

    $connection = db();
    $connection->beginTransaction();
    try {
        $lockUser = $connection->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
        $lockUser->execute(['user_id' => $user['id']]);
        if (!$lockUser->fetchColumn()) {
            $connection->rollBack();
            json_response(['ok' => false, 'message' => 'Your account could not be found. Please sign in again.'], 401);
        }

        $activeSubscription = $connection->prepare("SELECT id FROM pro_subscriptions WHERE user_id = :user_id AND status = 'active' AND ends_at > CURRENT_TIMESTAMP LIMIT 1");
        $activeSubscription->execute(['user_id' => $user['id']]);
        if ($activeSubscription->fetchColumn()) {
            $connection->rollBack();
            json_response(['ok' => false, 'message' => 'Your Finora Pro plan is already active.'], 409);
        }

        $previousPromo = $connection->prepare('SELECT id FROM pro_subscriptions WHERE user_id = :user_id AND amount_paise = 0 LIMIT 1');
        $previousPromo->execute(['user_id' => $user['id']]);
        if ($previousPromo->fetchColumn()) {
            $connection->rollBack();
            json_response(['ok' => false, 'message' => 'This promo code has already been redeemed on your account.'], 409);
        }

        $activate = $connection->prepare("INSERT INTO pro_subscriptions (user_id, amount_paise, currency, status, starts_at, ends_at) VALUES (:user_id, 0, 'INR', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP + INTERVAL '365 days')");
        $activate->execute(['user_id' => $user['id']]);
        $connection->commit();

        json_response([
            'ok' => true,
            'promo_redeemed' => true,
            'active' => true,
            'message' => 'Promo code accepted. Finora Pro is active for 365 days.',
        ], 201);
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        finora_log_exception('Finora Pro promo activation failed', $exception);
        json_response(['ok' => false, 'message' => 'Could not activate your promo code. Please try again.'], 503);
    }
}

$activeSubscription = db()->prepare("SELECT id FROM pro_subscriptions WHERE user_id = :user_id AND status = 'active' AND ends_at > CURRENT_TIMESTAMP LIMIT 1");
$activeSubscription->execute(['user_id' => $user['id']]);
if ($activeSubscription->fetchColumn()) {
    json_response(['ok' => false, 'message' => 'Your Finora Pro plan is already active.'], 409);
}

$credentials = razorpay_credentials();
if ($credentials['key_id'] === '' || $credentials['key_secret'] === '') {
    json_response(['ok' => false, 'message' => 'Finora Pro payments are not configured yet. Add Razorpay API keys to the server .env file.'], 503);
}

$amountPaise = 54900;
$receipt = 'finora_' . $user['id'] . '_' . bin2hex(random_bytes(8));
try {
    $order = razorpay_request('POST', 'orders', [
        'amount' => $amountPaise,
        'currency' => 'INR',
        'receipt' => $receipt,
        'notes' => ['user_id' => (string) $user['id'], 'plan' => 'finora-pro-365-days'],
    ]);
    $orderId = $order['id'] ?? null;
    if (!is_string($orderId) || !preg_match('/^order_[A-Za-z0-9]+$/', $orderId)) {
        error_log('Razorpay created an order without a valid order ID.');
        json_response(['ok' => false, 'message' => 'Razorpay returned an invalid order. Please try again.'], 502);
    }

    $statement = db()->prepare("INSERT INTO pro_subscriptions (user_id, razorpay_order_id, amount_paise, currency, status) VALUES (:user_id, :order_id, :amount, 'INR', 'pending')");
    $statement->execute(['user_id' => $user['id'], 'order_id' => $orderId, 'amount' => $amountPaise]);

    json_response([
        'ok' => true,
        'key_id' => $credentials['key_id'],
        'order_id' => $orderId,
        'amount' => $amountPaise,
        'currency' => 'INR',
        'name' => 'Finora by Adeel',
        'description' => 'Finora Pro annual plan (365 days)',
        'prefill' => ['name' => $user['name'], 'email' => $user['email']],
    ], 201);
} catch (RuntimeException $exception) {
    finora_log_exception('Finora Pro order creation failed', $exception);
    json_response(['ok' => false, 'message' => 'Could not create the payment order. Please try again.'], 502);
} catch (PDOException $exception) {
    finora_log_exception('Finora Pro order could not be saved', $exception);
    json_response(['ok' => false, 'message' => 'Could not save the payment order. Please try again.'], 503);
}
