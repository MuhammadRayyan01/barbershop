<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: applicaton/json; charset=utf-8');

function respond(int $status, array $data):never {
    http_response_code($status);
    echo json_encode($data);
    exit;

}

if ($_SERVER['REQUEST_METHOD'] !== 'GET'){
    header('Allow: GET');

    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$userId = $_SESSION['user_id'] ?? null;

if(!is_int($userId) || $userId < 1){
    respond(401, ['success' => false, 'message' => 'Authentication require']);
}

try{
    $stmt = $pdo->prepare('
                            select user_id, username, email, full_name, phone_number, created_at
                            from users whrer user_id = :user_id');

    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$user){
        $_SESSION = [];
        session_destroy();

        respond(401, ['success' => false, 'message' => 'Authentication required']);
    }

    $user['user_id'] = (int) $user['user_id'];
    respond(200, ['success' => true, 'data' => $user]);
      
    }catch(PDOException $e){
        error_log('Customer profile API error : ' .$e->getMessage());
        
        respond(500, ['success' => false, 'message' => 'Unable to retrive customer profile']);
    }
?>