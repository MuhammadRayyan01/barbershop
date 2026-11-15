<?php
declare(strict_types=1);

require_once __DIR__ . '/load_env.php';

/*
|--------------------------------------------------------------------------
| Environment configuration
|--------------------------------------------------------------------------
*/

function midtransEnv(string $key): string
{
    $value = $_ENV[$key]
        ?? $_SERVER[$key]
        ?? getenv($key);

    return is_string($value) ? trim($value) : '';
}

/*
|--------------------------------------------------------------------------
| Midtrans configuration
|--------------------------------------------------------------------------
*/

function midtransIsProduction(): bool
{
    return filter_var(
        midtransEnv('MIDTRANS_IS_PRODUCTION'),
        FILTER_VALIDATE_BOOLEAN
    );
}

function midtransServerKey(): string
{
    $key = midtransEnv('MIDTRANS_SERVER_KEY');

    if ($key === '') {
        throw new RuntimeException('Midtrans server key is not configured.');
    }

    return $key;
}

function midtransClientKey(): string
{
    $key = midtransEnv('MIDTRANS_CLIENT_KEY');

    if ($key === '') {
        throw new RuntimeException('Midtrans client key is not configured.');
    }

    return $key;
}

/*
|--------------------------------------------------------------------------
| Midtrans API URLs
|--------------------------------------------------------------------------
*/

function midtransApiBaseUrl(): string
{
    return midtransIsProduction()
        ? 'https://api.midtrans.com'
        : 'https://api.sandbox.midtrans.com';
}

function midtransSnapBaseUrl(): string
{
    return midtransIsProduction()
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js';
}

/*
|--------------------------------------------------------------------------
| General Midtrans API request
|--------------------------------------------------------------------------
*/

function midtransApiRequest(
    string $method,
    string $endpoint,
    ?array $payload = null
): array {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }

    $url = midtransApiBaseUrl() . $endpoint;

    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json'
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERPWD => midtransServerKey() . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => strtoupper($method)
    ];

    if ($payload !== null) {
        $jsonPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );

        $options[CURLOPT_POSTFIELDS] = $jsonPayload;
    }

    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);

    $curlError = curl_error($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($response === false) {
        error_log('Midtrans cURL error: ' . $curlError);

        throw new RuntimeException(
            'Unable to communicate with the payment gateway.'
        );
    }

    try {
        $decodedResponse = json_decode(
            $response,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        error_log('Invalid Midtrans JSON response.');

        throw new RuntimeException(
            'Invalid response received from the payment gateway.'
        );
    }

    if (!is_array($decodedResponse)) {
        throw new RuntimeException(
            'Unexpected response from the payment gateway.'
        );
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        error_log(
            'Midtrans API returned HTTP status: ' . $httpStatus
        );

        throw new RuntimeException(
            'Payment gateway request failed.'
        );
    }

    return $decodedResponse;
}

/*
|--------------------------------------------------------------------------
| Create Snap transaction
|--------------------------------------------------------------------------
*/

function midtransCreateSnapTransaction(array $payload): array
{
    return midtransApiRequest(
        'POST',
        '/snap/v1/transactions',
        $payload
    );
}

/*
|--------------------------------------------------------------------------
| Retrieve transaction status
|--------------------------------------------------------------------------
*/

function midtransGetTransactionStatus(string $orderId): array
{
    if ($orderId === '') {
        throw new InvalidArgumentException(
            'Order ID cannot be empty.'
        );
    }

    return midtransApiRequest(
        'GET',
        '/v2/' . rawurlencode($orderId) . '/status'
    );
}

/*
|--------------------------------------------------------------------------
| Verify Midtrans notification signature
|--------------------------------------------------------------------------
*/

function midtransVerifyNotificationSignature(
    array $notification
): bool {
    $orderId = $notification['order_id'] ?? null;
    $statusCode = $notification['status_code'] ?? null;
    $grossAmount = $notification['gross_amount'] ?? null;
    $signatureKey = $notification['signature_key'] ?? null;

    if (
        !is_string($orderId) ||
        !is_string($statusCode) ||
        !is_string($grossAmount) ||
        !is_string($signatureKey)
    ) {
        return false;
    }

    $expectedSignature = hash(
        'sha512',
        $orderId .
        $statusCode .
        $grossAmount .
        midtransServerKey()
    );

    return hash_equals(
        $expectedSignature,
        $signatureKey
    );
}
