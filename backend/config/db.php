<?php

require_once __DIR__ . '/load_env.php';

if (isset($pdo) && $pdo instanceof PDO) {
    return;
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'barber_shop';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') ?: '';

// Supabase requires SSL, so default to "require" for Supabase hosts.
$isSupabase = str_contains($host, 'supabase.co') || str_contains($host, 'supabase.com');
$sslmode = getenv('DB_SSLMODE') ?: ($isSupabase ? 'require' : 'prefer');

try {

    if ($username === '' || $password === '') {
        throw new RuntimeException('Database credentials are missing.');
    }

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode;connect_timeout=10";

    $pdo = new PDO($dsn, $username, $password);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Supabase's transaction pooler (port 6543) does not support server-side
    // prepared statements, so emulate them in PHP when using the pooler.
    $usesTransactionPooler = $port === '6543' || getenv('DB_EMULATE_PREPARES') === 'true';
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $usesTransactionPooler);

} catch (Throwable $e) {

    error_log("Database connection failed: " . $e->getMessage());

    http_response_code(500);
    exit('Database connection failed');

}