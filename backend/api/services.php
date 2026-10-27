<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'){
    header('Allowed: GET');

    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed']);
}

try{
    $stmt = $pdo->prepare("
                            select service_id, service_name, description, price, category
                            from service where status = :status order by service_name");

    $stmt->execute(['status' => 'active']);

    $service = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(200, ['success' => true, 'data' => $service]);
}catch (PDOException $e){
    error_log('Service API error : ' .$e->getMessage());

    jsonResponse(500, ['success' => false, 'message' => 'Unable to load service']);
}s
?>