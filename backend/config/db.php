<?php

require_once __DIR__ . '/load_env.php';

// Database configuration
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'barber_shop';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
$sslmode = getenv('DB_SSLMODE') ?: 'prefer';

try {

    if ($username === '' || $password === '') {
        throw new RuntimeException('Database credentials are missing.');
    }

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode";

    $pdo = new PDO($dsn, $username, $password);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

} catch (Throwable $e) {

    error_log("Database connection failed: " . $e->getMessage());

    http_response_code(500);
    exit('Database connection failed');

}
