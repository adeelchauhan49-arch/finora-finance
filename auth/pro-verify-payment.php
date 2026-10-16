<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_method('POST');

$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in before verifying a Finora Pro payment.'], 401);
}

if (!finora_pro_enabled()) {
    json_response(['error' => 'Not available'], 404);
}

require_once __DIR__ . '/../includes/razorpay.php';

$data = request_body();
$orderId = trim((string) ($data['razorpay_order_id'] ?? ''));
$paymentId = trim((string) ($data['razorpay_payment_id'] ?? ''));
$signature = trim((string) ($data['razorpay_signature'] ?? ''));
if (!preg_match('/^order_[A-Za-z0-9]+$/', $orderId)
    || !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)
    || !preg_match('/^[a-f0-9]{64}$/i', $signature)) {
    json_response(['ok' => false, 'message' => 'Razorpay returned invalid payment details.'], 422);
}

$credentials = razorpay_credentials();
if ($credentials['key_secret'] === '') {
    json_response(['ok' => false, 'message' => 'Razorpay is not configured on the server.'], 503);
}

$statement = db()->prepare('SELECT id, amount_paise, currency, status, razorpay_payment_id FROM pro_subscriptions WHERE user_id = :user_id AND razorpay_order_id = :order_id LIMIT 1');
$statement->execute(['user_id' => $user['id'], 'order_id' => $orderId]);
$subscription = $statement->fetch();
if (!$subscription) {
    json_response(['ok' => false, 'message' => 'Payment order was not found for this account.'], 404);
}
if ($subscription['status'] === 'active') {
    if (hash_equals((string) $subscription['razorpay_payment_id'], $paymentId)) {
        json_response(['ok' => true, 'message' => 'Finora Pro is active for one year.']);
    }
    json_response(['ok' => false, 'message' => 'This order has already been used.'], 409);
}

$expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $credentials['key_secret']);
if (!hash_equals($expectedSignature, $signature)) {
    json_response(['ok' => false, 'message' => 'Payment verification failed. No subscription was activated.'], 400);
}

try {
    $payment = razorpay_request('GET', 'payments/' . rawurlencode($paymentId));
} catch (RuntimeException $exception) {
    finora_log_exception('Finora Pro payment lookup failed', $exception);
    json_response(['ok' => false, 'message' => 'Could not confirm payment with Razorpay. Please try again.'], 502);
}

if (($payment['order_id'] ?? null) !== $orderId
    || ($payment['status'] ?? null) !== 'captured'
    || (int) ($payment['amount'] ?? 0) !== (int) $subscription['amount_paise']
    || ($payment['currency'] ?? null) !== $subscription['currency']) {
    json_response(['ok' => false, 'message' => 'Razorpay has not confirmed the expected payment. No subscription was activated.'], 400);
}

$connection = db();
$connection->beginTransaction();
try {
    $locked = $connection->prepare('SELECT status, razorpay_payment_id FROM pro_subscriptions WHERE id = :id AND user_id = :user_id FOR UPDATE');
    $locked->execute(['id' => $subscription['id'], 'user_id' => $user['id']]);
    $current = $locked->fetch();
    if (!$current) {
        $connection->rollBack();
        json_response(['ok' => false, 'message' => 'Payment order was not found for this account.'], 404);
    }
    if ($current['status'] === 'active') {
        $connection->commit();
        if (hash_equals((string) $current['razorpay_payment_id'], $paymentId)) {
            json_response(['ok' => true, 'message' => 'Finora Pro is active for one year.']);
        }
        json_response(['ok' => false, 'message' => 'This order has already been used.'], 409);
    }

    $activate = $connection->prepare("UPDATE pro_subscriptions SET razorpay_payment_id = :payment_id, status = 'active', starts_at = CURRENT_TIMESTAMP, ends_at = CURRENT_TIMESTAMP + INTERVAL '365 days' WHERE id = :id AND status = 'pending'");
    $activate->execute(['payment_id' => $paymentId, 'id' => $subscription['id']]);
    if ($activate->rowCount() !== 1) {
        throw new RuntimeException('Payment activation did not update the pending order.');
    }
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    finora_log_exception('Finora Pro subscription activation failed', $exception);
    json_response(['ok' => false, 'message' => 'Payment was received, but Pro activation needs support. Contact Finora support with your Razorpay payment ID.'], 503);
}

json_response(['ok' => true, 'message' => 'Payment verified. Finora Pro is active for 365 days.']);
