<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_method('GET');

$user = current_user();
if ($user) {
    if ($user['email_verified_at'] === null) {
        json_response([
            'ok' => true,
            'user' => null,
            'requires_verification' => true,
            'email' => $user['email'],
            'pro_enabled' => finora_pro_enabled(),
        ]);
    }
    try {
        ensure_financial_profile($user['id']);
    } catch (PDOException $exception) {
        finora_log_exception('Finora session profile initialization failed', $exception);
        json_response(['ok' => false, 'message' => 'Could not load your financial profile. Please try again.'], 503);
    }
}

json_response(['ok' => true, 'user' => $user, 'pro_enabled' => finora_pro_enabled()]);
