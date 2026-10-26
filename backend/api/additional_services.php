<?php
declare(strict_types=1);
 
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'){
    header('Allow: GET');

    jsonResponse(405, [])
}
?>