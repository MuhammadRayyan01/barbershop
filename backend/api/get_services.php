<?php
// backend/api/get_services.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT service_id, service_name, description, price FROM service WHERE status = 'active' ORDER BY service_name");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'status' => 'success',
        'data' => $services
    ]);
} catch (PDOException $e) {
    error_log('Booking service list error: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error', 
        'message' => 'Daftar layanan gagal dimuat.'
    ]);
}