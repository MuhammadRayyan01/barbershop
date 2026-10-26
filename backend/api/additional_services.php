<?php
declare(strict_types=1);
 
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'){ 
    header('Allow: GET');

    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

try{
    $stmt = $pdo->query('
                            select additional_service_id, service_id, additional_service_name, additional_service_price
                            from additional_service order by additional_service_name');

                            $additionalServices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(200, ['success' => true, 'data' => $additionalServices]);
}catch (PDOException $e){
    error_log('Additional service API error: ' .$e->getMessage());

    jsonResponse(500, ['success' => false, 'message' => 'Unable to load additional services.']);
}
?>