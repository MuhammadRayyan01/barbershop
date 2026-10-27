<?php
declare(strict_types=1);

require_once __DIR__ . '/response.php';

function getCsrfToken(): string {
    if(!isset($_SESSION['csrf_token']) || !is_string(['csrf_token'])){
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireValidCsrfToken(): void{
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !is_string($sessionToken) || $submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        jsonResponse(403, ['success' => false, 'message' => 'Invalid or missing CSRF token']);
    }
}
?>