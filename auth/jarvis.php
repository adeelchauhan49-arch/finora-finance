<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/jarvis_financial_snapshot.php';
require_method('POST');

$user = require_verified_user();
if (!$user) {
    json_response(['ok' => false, 'message' => 'Sign in to get insights based on your financial data.'], 401);
}

$data = request_body();
$question = trim((string) ($data['question'] ?? ''));
$period = (string) ($data['period'] ?? 'this-month');
if ($question === '' || mb_strlen($question) > 1000 || !in_array($period, ['this-month', 'last-month', 'three-months'], true)) {
    json_response(['ok' => false, 'message' => 'Send a valid question and date range to Jarvis.'], 422);
}

$connection = db();
$connection->beginTransaction();
$connection->prepare(
    'INSERT INTO jarvis_rate_limits (user_id, window_started_at, request_count)
     VALUES (:user_id, CURRENT_TIMESTAMP, 1)
     ON CONFLICT (user_id) DO UPDATE SET
        request_count = CASE WHEN jarvis_rate_limits.window_started_at <= CURRENT_TIMESTAMP - INTERVAL \'1 minute\' THEN 1 ELSE jarvis_rate_limits.request_count + 1 END,
        window_started_at = CASE WHEN jarvis_rate_limits.window_started_at <= CURRENT_TIMESTAMP - INTERVAL \'1 minute\' THEN CURRENT_TIMESTAMP ELSE jarvis_rate_limits.window_started_at END'
)->execute(['user_id' => $user['id']]);
$rateStatement = $connection->prepare('SELECT request_count FROM jarvis_rate_limits WHERE user_id = :user_id FOR UPDATE');
$rateStatement->execute(['user_id' => $user['id']]);
$requestCount = (int) $rateStatement->fetchColumn();
$connection->commit();
if ($requestCount > 15) {
    json_response(['ok' => false, 'message' => 'Please wait a moment before asking Jarvis again.'], 429);
}

$snapshot = jarvis_build_financial_snapshot($connection, (int) $user['id'], $period);
$intent = jarvis_detect_intent($question)['intent'];
$fallbackReply = jarvis_deterministic_reply($question, $snapshot);
$apiKey = trim((string) config_value('JARVIS_API_KEY', ''));
if ($apiKey === '' || $snapshot['transaction_count'] < 3 || $intent === 'unknown' || $intent === 'forecast') {
    json_response(['ok' => true, 'reply' => $fallbackReply, 'snapshot' => $snapshot]);
}

if (!function_exists('curl_init')) {
    error_log('Jarvis AI request cannot run because the cURL extension is unavailable.');
    json_response(['ok' => true, 'reply' => $fallbackReply, 'snapshot' => $snapshot]);
}

$messages = [
    [
        'role' => 'system',
        'content' => 'You are Jarvis, a concise personal finance assistant. Reply in the same language as the user question (English or Hinglish). Answer only using the supplied financial snapshot and the user question. Do not invent, extrapolate beyond a supplied forecast, or claim access to individual transaction details. Only use numeric values present in the snapshot or arithmetic directly derived from them. If the snapshot does not contain enough information, say "Mere paas ye data nahi hai." If data_is_limited is true, clearly state the data limitation and do not make a confident recommendation. Never request passwords, OTPs, or payment details.',
    ],
    [
        'role' => 'user',
        'content' => json_encode([
            'question' => $question,
            'financial_snapshot' => jarvis_llm_snapshot($snapshot),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ],
];

$requestBody = json_encode([
    'model' => config_value('JARVIS_MODEL', 'gpt-4o'),
    'messages' => $messages,
    'max_tokens' => 350,
    'temperature' => 0.2,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$curl = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => $requestBody,
]);
$responseBody = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_errno($curl);
curl_close($curl);

if ($responseBody === false || $curlError !== 0 || $status < 200 || $status >= 300) {
    error_log('Jarvis AI request failed with HTTP status ' . $status . ' and cURL error ' . $curlError . '.');
    json_response(['ok' => true, 'reply' => $fallbackReply, 'snapshot' => $snapshot]);
}

$providerResponse = json_decode($responseBody, true);
$reply = $providerResponse['choices'][0]['message']['content'] ?? null;
if (!is_string($reply) || trim($reply) === '') {
    error_log('Jarvis AI response did not contain a chat reply.');
    json_response(['ok' => true, 'reply' => $fallbackReply, 'snapshot' => $snapshot]);
}

json_response(['ok' => true, 'reply' => trim($reply), 'snapshot' => $snapshot]);
