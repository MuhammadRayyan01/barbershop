<?php
declare(strict_types=1);

require_once __DIR__ . '/load_env.php';

// Environment configuration

function midtransEnv(string $key): string
{
    $value = $_ENV[$key]
        ?? $_SERVER[$key]
        ?? getenv($key);

    return is_string($value) ? trim($value) : '';
}

// Midtrans configuration

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

// Midtrans API URLs

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

// Since 28 October 2026 Midtrans signs notifications with a dedicated
// Signature Secret Key (Settings > Access Keys). Older accounts started with
// the same value as their legacy server key, so fall back to the server key
// when MIDTRANS_SIGNATURE_SECRET_KEY is not set.
function midtransSignatureSecretKey(): string
{
    $key = midtransEnv('MIDTRANS_SIGNATURE_SECRET_KEY');

    return $key !== '' ? $key : midtransServerKey();
}

// General Midtrans API request

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
        CURLOPT_USERAGENT => 'PemonBarbershop/1.0',
        CURLOPT_CUSTOMREQUEST => strtoupper($method)
    ];

    // A POST without a body (for example the cancel call) must still send
    // "Content-Length: 0". With only CURLOPT_CUSTOMREQUEST, cURL leaves the
    // header out, and some servers reject such a request.
    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = '';
    }

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
        error_log(
            'Invalid Midtrans JSON response for ' .
            strtoupper($method) . ' ' . $endpoint .
            ' (HTTP ' . $httpStatus . '): ' .
            substr(trim((string) $response), 0, 300)
        );

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
        // Log what Midtrans actually said so the real reason is visible.
        $gatewayCode = is_scalar($decodedResponse['status_code'] ?? null)
            ? (string) $decodedResponse['status_code']
            : 'n/a';

        $gatewayMessage = is_string($decodedResponse['status_message'] ?? null)
            ? $decodedResponse['status_message']
            : 'no message';

        error_log(
            'Midtrans API ' . strtoupper($method) . ' ' . $endpoint .
            ' returned HTTP ' . $httpStatus .
            ' (status_code ' . $gatewayCode . '): ' . $gatewayMessage .
            ' | body: ' . substr(trim((string) $response), 0, 500)
        );

        throw new RuntimeException(
            'MIDTRANS_API_ERROR:' . $gatewayCode . ': ' . $gatewayMessage
        );
    }

    return $decodedResponse;
}

// Create Snap transaction

function midtransCreateSnapTransaction(array $payload): array
{
    return midtransApiRequest(
        'POST',
        '/snap/v1/transactions',
        $payload
    );
}

// Retrieve transaction status

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

// Midtrans only supports gateway refunds for a subset of payment methods.
// Unknown or newly added methods must use the merchant's manual refund flow.
function midtransPaymentSupportsGatewayRefund(mixed $paymentType): bool
{
    if (!is_string($paymentType)) {
        return false;
    }

    return in_array(strtolower(trim($paymentType)), [
        'credit_card',
        'gopay',
        'qris',
        'shopeepay',
        'akulaku',
        'kredivo'
    ], true);
}

// Request a refund from Midtrans.


function midtransRequestRefund(
    string $transactionIdentifier,
    array $payload
): array {
    if ($transactionIdentifier === '') {
        throw new InvalidArgumentException(
            'Transaction identifier cannot be empty.'
        );
    }

    if (
        !isset($payload['refund_key']) ||
        !is_string($payload['refund_key']) ||
        $payload['refund_key'] === ''
    ) {
        throw new InvalidArgumentException(
            'A valid refund key is required.'
        );
    }

    if (
        !isset($payload['amount']) ||
        !is_numeric($payload['amount']) ||
        (float) $payload['amount'] <= 0
    ) {
        throw new InvalidArgumentException(
            'A valid refund amount is required.'
        );
    }

    $amount = (float) $payload['amount'];

    if ($amount !== (float) (int) $amount) {
        throw new InvalidArgumentException(
            'Refund amount must be a whole IDR amount.'
        );
    }

    $payload['amount'] = (int) $amount;

    $delays = [1, 5];

    for ($attempt = 0; $attempt <= count($delays); $attempt++) {
        try {
            return midtransApiRequest(
                'POST',
                '/v2/' . rawurlencode($transactionIdentifier) . '/refund',
                $payload
            );

        } catch (RuntimeException $e) {
            $isTemporaryProcessingError = str_contains(
                $e->getMessage(),
                'MIDTRANS_API_ERROR:2007:'
            );

            if (!$isTemporaryProcessingError) {
                throw $e;
            }

            if ($attempt >= count($delays)) {
                throw $e;
            }

            error_log(
                'Midtrans refund processing conflict (2007). Retrying after ' .
                $delays[$attempt] . ' seconds. Attempt ' .
                ($attempt + 1)
            );

            sleep($delays[$attempt]);
        }
    }

    throw new RuntimeException(
        'Unable to complete the Midtrans refund request.'
    );
}

// Cancel a transaction that has not reached a refundable state.
function midtransCancelTransaction(string $orderId): array
{
    if ($orderId === '') {
        throw new InvalidArgumentException(
            'Order ID cannot be empty.'
        );
    }

    return midtransApiRequest(
        'POST',
        '/v2/' . rawurlencode($orderId) . '/cancel'
    );
}

// Verify Midtrans notification signature

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
        midtransSignatureSecretKey()
    );

    return hash_equals(
        $expectedSignature,
        $signatureKey
    );
}
