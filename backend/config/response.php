<?php
declare(strict_types=1);

function jsonResponse(int $statuscode, array $data): never{
    http_response_code($statuscode);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    try{
        echo  json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }catch(JsonException $e){
        http_response_code(500);
        echo '{"success":false, "message":"Internal server error."}';
    }
    exit;
}
?>