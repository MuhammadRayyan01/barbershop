<?php
declare(strict_types=1);

function readJsonResponse(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

    if($mediaType !== 'application/json'){
        jsonResponse(415, ['success' => false, 'message' => 'Content-Type must be json']);
    }

    $rawBody = file_get_contents('php://input');

    if($rawBody === false || trim($rawBody) === ''){
        jsonResponse(400, ['success' => false, 'message' => 'Request body is required.']);
    }

    try{
        $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);


    }catch(JsonException $e){
        jsonResponse(400, ['success' => false, 'message' => 'Invalid JSON request body']);
    }

    if(!is_array($data) || array_is_list($data)){
        jsonResponse(400, ['success' => false, 'message' => 'Request body must be json object']);
    }
    return $data;
    
    function requireHttpMethod(string $method): void{
        if(($_SERVER['REQUEST_METHOD'] ?? '') !== $method){
            header(('Allow: '. $method));

            jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
        }
    }


}
?>