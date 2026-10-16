<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/category_classifier.php';
require_method('POST');

$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in to get personalized category suggestions.'], 401);
}

$data = request_body();
try {
    $merchant = category_classifier_validate_merchant((string) ($data['merchant'] ?? ''));
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
}

$suggestion = category_classifier_for_user(db(), (int) $user['id'], $merchant);
$suggestion['message'] = $suggestion['uncertain']
    ? 'Pakka nahi hun — Other'
    : 'Suggested category: ' . $suggestion['category'];
if (!$suggestion['learning_available']) {
    $suggestion['learning_message'] = 'Personalized categories are unavailable until database/user_category_rules.sql is imported.';
}

json_response(['ok' => true, 'suggestion' => $suggestion]);
