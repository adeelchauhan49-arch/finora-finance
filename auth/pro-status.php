<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_method('GET');

$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in to check your Finora Pro status.'], 401);
}

if (!finora_pro_enabled()) {
    json_response(['error' => 'Not available'], 404);
}

$statement = db()->prepare("SELECT ends_at FROM pro_subscriptions WHERE user_id = :user_id AND status = 'active' AND ends_at > CURRENT_TIMESTAMP ORDER BY ends_at DESC LIMIT 1");
$statement->execute(['user_id' => $user['id']]);
$endsAt = $statement->fetchColumn();

json_response([
    'ok' => true,
    'active' => is_string($endsAt),
    'ends_at' => is_string($endsAt) ? $endsAt : null,
]);
