<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function razorpay_credentials(): array
{
    return [
        'key_id' => trim((string) config_value('RAZORPAY_KEY_ID', '')),
        'key_secret' => trim((string) config_value('RAZORPAY_KEY_SECRET', '')),
    ];
}

function finora_promo_code_matches(string $providedCode, string $configuredCode): bool
{
    $providedCode = trim($providedCode);
    $configuredCode = trim($configuredCode);
    return $providedCode !== ''
        && $configuredCode !== ''
        && hash_equals(strtolower($configuredCode), strtolower($providedCode));
}

function razorpay_request(string $method, string $path, ?array $payload = null): array
{
    $credentials = razorpay_credentials();
    if ($credentials['key_id'] === '' || $credentials['key_secret'] === '') {
        throw new RuntimeException('Razorpay is not configured. Add RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET to .env.');
    }

    $curl = curl_init('https://api.razorpay.com/v1/' . ltrim($path, '/'));
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $credentials['key_id'] . ':' . $credentials['key_secret'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl);
    curl_close($curl);

    if (!is_string($responseBody) || $curlError !== 0) {
        error_log('Razorpay API request failed with cURL error ' . $curlError . '.');
        throw new RuntimeException('Could not connect to Razorpay. Please try again.');
    }

    $response = json_decode($responseBody, true);
    if (!is_array($response) || $status < 200 || $status >= 300) {
        $providerMessage = is_array($response) ? ($response['error']['description'] ?? null) : null;
        error_log('Razorpay API request failed with HTTP status ' . $status . '.');
        throw new RuntimeException(is_string($providerMessage) && $providerMessage !== ''
            ? 'Razorpay error: ' . $providerMessage
            : 'Razorpay could not process this request. Please try again.');
    }

    return $response;
}
