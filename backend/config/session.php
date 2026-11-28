<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/session_handler.php';

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    // How long an idle session stays valid (default 2 hours). Override with SESSION_LIFETIME.
    $sessionLifetime = (int) (getenv('SESSION_LIFETIME') ?: 7200);
    ini_set('session.gc_maxlifetime', (string) max(300, $sessionLifetime));

    // Clean up expired sessions on roughly 1 in 100 requests.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    // Detect HTTPS directly or through a reverse proxy (Vercel, Render, etc.)
    $forwardedProto = strtolower(
        trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0])
    );

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || $forwardedProto === 'https'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Keep sessions in the database so they survive across serverless instances.
    session_set_save_handler(new PdoSessionHandler($pdo), true);

    session_start();
}