<?php
declare(strict_types=1);

require_once __DIR__ . '/../middleware/require_admin_api.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/request.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PATCH, DELETE');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

if ($method === 'GET') {
    $status = $_GET['status'] ?? 'active';
    if (!in_array($status, ['active', 'inactive', 'all'], true)) {
        jsonResponse(422, ['success' => false, 'message' => 'Unsupported service status.']);
    }
    try {
        $sql = 'SELECT service_id, service_name, description, price, status, created_at, updated_at FROM service';
        if ($status !== 'all') $sql .= ' WHERE status = :status';
        $sql .= ' ORDER BY service_name';
        $stmt = $pdo->prepare($sql);
        if ($status !== 'all') $stmt->execute(['status' => $status]); else $stmt->execute();
        jsonResponse(200, ['success' => true, 'csrf_token' => getCsrfToken(), 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        error_log('Admin service list error: ' . $e->getMessage());
        jsonResponse(500, ['success' => false, 'message' => 'Unable to load services.']);
    }
}

requireValidCsrfToken();
$data = readJsonResponse();
$serviceId = $data['service_id'] ?? null;

if ($method === 'DELETE') {
    if (!is_int($serviceId) || $serviceId < 1) jsonResponse(422, ['success' => false, 'message' => 'A valid service ID is required.']);
    try {
        $stmt = $pdo->prepare("UPDATE service SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE service_id = :id AND status <> 'inactive' RETURNING service_id");
        $stmt->execute(['id' => $serviceId]);
        if (!$stmt->fetchColumn()) jsonResponse(404, ['success' => false, 'message' => 'Active service not found.']);
        jsonResponse(200, ['success' => true, 'message' => 'Service deactivated.']);
    } catch (PDOException $e) {
        error_log('Admin service deactivation error: ' . $e->getMessage());
        jsonResponse(500, ['success' => false, 'message' => 'Unable to deactivate service.']);
    }
}

$name = trim((string) ($data['service_name'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));
$priceInput = $data['price'] ?? null;
$newStatus = $data['status'] ?? 'active';
if ($name === '' || strlen($name) > 255 || !is_numeric($priceInput) || (float) $priceInput < 0 || (float) $priceInput > 99999999.99 || strlen($description) > 10000) {
    jsonResponse(422, ['success' => false, 'message' => 'Provide a valid service name, description, and non-negative price.']);
}
if ($method === 'POST' && !in_array($newStatus, ['active', 'inactive'], true)) {
    jsonResponse(422, ['success' => false, 'message' => 'Service status must be active or inactive.']);
}

try {
    if ($method === 'POST') {
        $stmt = $pdo->prepare('INSERT INTO service (service_name, description, price, status) VALUES (:name, :description, :price, :status) RETURNING service_id, service_name, description, price, status');
    } else {
        if (!is_int($serviceId) || $serviceId < 1) jsonResponse(422, ['success' => false, 'message' => 'A valid service ID is required.']);
        $stmt = $pdo->prepare('UPDATE service SET service_name = :name, description = :description, price = :price, updated_at = CURRENT_TIMESTAMP WHERE service_id = :id RETURNING service_id, service_name, description, price, status');
    }
    $params = ['name' => $name, 'description' => $description === '' ? null : $description, 'price' => number_format((float) $priceInput, 2, '.', '')];
    if ($method === 'POST') $params['status'] = $newStatus;
    if ($method === 'PATCH') $params['id'] = $serviceId;
    $stmt->execute($params);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) jsonResponse(404, ['success' => false, 'message' => 'Service not found.']);
    jsonResponse($method === 'POST' ? 201 : 200, ['success' => true, 'message' => $method === 'POST' ? 'Service created.' : 'Service updated.', 'data' => $service]);
} catch (PDOException $e) {
    error_log('Admin service save error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to save service.']);
}
