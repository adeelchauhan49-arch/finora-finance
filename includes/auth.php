<?php
declare(strict_types=1);

ob_start();
$finoraErrorResponseSent = false;
$finoraRespondWithGenericError = static function (string $logMessage) use (&$finoraErrorResponseSent): void {
    if ($finoraErrorResponseSent) {
        return;
    }
    $finoraErrorResponseSent = true;
    error_log($logMessage);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Something went wrong. Please try again.'], JSON_UNESCAPED_SLASHES);
    exit;
};
set_exception_handler(static function (Throwable $exception) use ($finoraRespondWithGenericError): void {
    $finoraRespondWithGenericError(finora_safe_exception_log_message('Finora uncaught endpoint exception', $exception));
});
register_shutdown_function(static function () use (&$finoraErrorResponseSent, $finoraRespondWithGenericError): void {
    if ($finoraErrorResponseSent) {
        return;
    }
    $error = error_get_last();
    $fatalErrorTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
    if ($error !== null && ($error['type'] & $fatalErrorTypes) !== 0) {
        $message = finora_sanitize_error_log_message((string) ($error['message'] ?? 'Unknown fatal error'));
        $file = (string) ($error['file'] ?? 'unknown file');
        $line = (int) ($error['line'] ?? 0);
        $finoraRespondWithGenericError("Finora fatal endpoint error: {$message} in {$file}:{$line}");
    }
});

function finora_sanitize_error_log_message(string $message): string
{
    $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?? '';
    $sensitiveValues = [];
    $collectSensitiveValues = static function (array $values) use (&$collectSensitiveValues, &$sensitiveValues): void {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $collectSensitiveValues($value);
            } elseif (is_scalar($value)
                && preg_match('/password|passwd|otp|verification[_-]?code|api[_-]?key|token|secret/i', (string) $key) === 1) {
                $secret = (string) $value;
                if ($secret !== '') {
                    $sensitiveValues[] = $secret;
                }
            }
        }
    };
    if (isset($GLOBALS['finoraRequestData']) && is_array($GLOBALS['finoraRequestData'])) {
        $collectSensitiveValues($GLOBALS['finoraRequestData']);
    }
    if (isset($_POST) && is_array($_POST)) {
        $collectSensitiveValues($_POST);
    }
    if (isset($_GET) && is_array($_GET)) {
        $collectSensitiveValues($_GET);
    }
    if (function_exists('config_value')) {
        foreach (['SMTP_USERNAME', 'SMTP_PASSWORD', 'DB_PASS', 'SUPABASE_DB_URL', 'SUPABASE_PUBLISHABLE_KEY', 'SUPABASE_SERVICE_ROLE_KEY', 'JARVIS_API_KEY', 'RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET', 'FINORA_PRO_PROMO_CODE'] as $key) {
            $secret = (string) config_value($key, '');
            if ($secret !== '') {
                $sensitiveValues[] = $secret;
            }
        }
    }
    usort($sensitiveValues, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
    foreach (array_unique($sensitiveValues) as $secret) {
        $message = str_ireplace($secret, '[REDACTED]', $message);
    }
    $message = preg_replace(
        '/\b(password|passwd|otp|verification[_-]?code|api[_-]?key|token|secret)\b\s*[:=]\s*[^\s,;|]+/i',
        '$1=[REDACTED]',
        $message
    ) ?? '';
    return substr($message, 0, 2000);
}

function finora_safe_exception_log_message(string $context, Throwable $exception): string
{
    return $context . ' [' . get_class($exception) . ']: ' . finora_sanitize_error_log_message($exception->getMessage());
}

function finora_log_exception(string $context, Throwable $exception): void
{
    error_log(finora_safe_exception_log_message($context, $exception));
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function supabase_auth_user_from_request(): ?array
{
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $header => $value) {
            if (strcasecmp((string) $header, 'Authorization') === 0) {
                $authorization = (string) $value;
                break;
            }
        }
    }
    if (!preg_match('/^Bearer\s+([^\s]+)$/i', $authorization, $matches)) {
        return null;
    }

    $projectUrl = rtrim((string) config_value('SUPABASE_URL', ''), '/');
    $publishableKey = (string) config_value('SUPABASE_PUBLISHABLE_KEY', '');
    if ($projectUrl === '' || $publishableKey === '') {
        throw new RuntimeException('Supabase Auth is not configured on the server.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required to validate Supabase sessions.');
    }

    $curl = curl_init($projectUrl . '/auth/v1/user');
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the Supabase Auth request.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $publishableKey,
            'Authorization: Bearer ' . $matches[1],
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if (!is_string($responseBody)) {
        throw new RuntimeException('Supabase Auth request failed: ' . $curlError);
    }
    if ($status === 401 || $status === 403) {
        return null;
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Supabase Auth returned HTTP ' . $status . '.');
    }
    $authUser = json_decode($responseBody, true);
    if (!is_array($authUser) || !isset($authUser['id'], $authUser['email'])) {
        throw new RuntimeException('Supabase Auth returned an invalid user response.');
    }
    $GLOBALS['finoraSupabaseAuthUser'] = $authUser;
    return $authUser;
}

function current_user(): ?array
{
    $authUser = supabase_auth_user_from_request();
    if ($authUser === null) {
        return null;
    }

    try {
        $statement = db()->prepare('SELECT id, name, email, pending_email, email_verified_at FROM users WHERE auth_user_id = :auth_user_id LIMIT 1');
        $statement->execute(['auth_user_id' => $authUser['id']]);
        $user = $statement->fetch();
        if (!$user) {
            throw new RuntimeException('The Supabase account is not linked to a Finora profile.');
        }
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'pending_email' => $user['pending_email'],
            'email_verified_at' => $authUser['email_confirmed_at'] ?? $authUser['confirmed_at'] ?? null,
        ];
    } catch (Throwable $exception) {
        finora_log_exception('Finora current user lookup failed', $exception);
        throw $exception;
    }
}

function require_verified_user(): ?array
{
    $user = current_user();
    if ($user === null) {
        return null;
    }
    if ($user['email_verified_at'] === null) {
        json_response(['ok' => false, 'message' => 'Please verify your email first.'], 403);
    }
    return $user;
}

function ensure_financial_profile(int $userId): void
{
    $statement = db()->prepare(
        'INSERT INTO financial_profile (user_id) VALUES (:user_id) ON CONFLICT (user_id) DO NOTHING'
    );
    $statement->execute(['user_id' => $userId]);
}

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
}
