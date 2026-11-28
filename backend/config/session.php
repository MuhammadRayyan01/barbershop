<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    // Detect HTTPS directly or through Render's reverse proxy
    $forwardedProto = strtolower(
        trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0])
    );

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || $forwardedProto === 'https'
    );

    // Configure secure session cookies
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}
