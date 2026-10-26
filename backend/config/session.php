<?php
declare(strict_types = 1);

if(session_status() !== PHP_SESSION_ACTIVE){
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    $isHttp = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttp, 'httponly' => true, 'samesite' => 'Lax',]);

        session_start();
}
?>