<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/csrf.php';

if($_SERVER['REQUEST_METHOD'] !== 'GET'){
    header('Allow: GET');
    jsonResponse(405, ['success' => false, 'message' => 'Mothed no allowed.']);
}

jsonResponse(200, ['success' => true, 'csrf_token' => getCsrfToken()]);
?>