<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; cahrset=utf-8');

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    header('Allow: POST');
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if(stripos($contentType,'application/json') === false){
    respond(415, ['success' => false, 'message' => 'Content-Type must be application/json.']);

}

$rawInput = file_get_contents('php://input');

try{
    $data = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);

}catch(JsonException $e){
    respond(400, ['success' => false, 'message' => 'Invalid JOSN request body.']);
}

if(!is_array($data)){
    respond(400, ['success' => false, 'message' => 'Invalid requsest body']);
}

$login = $data['login'] ?? '';
$password = $data['password'] ?? '';

if(!is_string($login) || !is_string($password)){
    respond(400, ['success' => false, 'message' => 'login and password be text value']);
}

if($login === '' || $password === ''){
    respond(422, ['success' => false, 'message' => "Login and password are required"]);
}

if(strlen($login) > 354 || strlen($password) > 72){
    respond(402, ['success' => false, 'message' => 'Invalid username/email or password']);
}

try{
    $stmt = $pdo -> prepare ('
                               select user_id, username, email, password_hash, fullname, phone_number
                               from users where username = :username or email = :email limit 1');
    $stmt->execute(['username' => $login, 'email' => $login]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$user || !password_verify($password, $user['password_hash'])){
        respond(401, ['success' => false, 'message' => 'Invalid username/email or password']);
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['username'] = $user['username'];

    unset($user['password_hash']);

    respond(200, ['success' => true, 'message' => 'Login successful.', 'data' => $user]);
}catch(PDOException $e){
    error_log('Login API database error: ' .$e->getMessage());

    respond(500, ['success' => false, 'message' => 'Unable to process login']);
}



?>