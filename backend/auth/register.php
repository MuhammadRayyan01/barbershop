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
    respond(405, ['success' => false, 'message' => 'Method not allowed. ']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if(stripos($contentType, 'application/json') === false){
    respond(415, ['success' => false, 'message' => 'Content-Type must be application/json.']);
}

$rawInput = file_get_contents('php://input');

try{
    $data = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
}catch (JsonException $e){
    respond(400, ['success' => false, 'message' => 'invalid request body']);
}

if(!is_array($data)){
    respond(400, ['success' => false, 'message' => 'Invalid request body']);
}

$uername = trim($data['username'] ?? '');
$email = trim($data['email'] ?? '');
$fullName = trim($data['full_name'] ?? '');
$phoneNumber = trim($data['phone_number'] ?? '');
$password = $data['password'] ?? '';

if(!is_string($username) ||
   !is_string($email) ||
   !is_string($fullName) ||
   !is_string($phoneNumber) ||
   !is_string($password)
){
    respond(400, ['success' => false, 'message' => 'All fields mustcontains text valuse']);
}

if (
    $username === '' ||
    $email === '' ||
    $fullName === '' ||
    $phoneNumber === '' ||
    $password === ''
){
    respond(422, ['sussess' => false, 'message' => "all fields must require to fill"]);
}

if (
    strlen($username) > 50 ||
    strlen($email) > 254 ||
    strlen($fullName) > 150 ||
    strlen($phoneNumber) > 15
){
    respond(422, ['success' => false, 'message' => 'one or more fields exceeds the allwed limit']);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL)){
    respond(422, ['success' => false, 'message' => 'Enter a valid email address.']);
}

if (strlen($password) < 12 || strlen($password) > 72){
    respond(422, ['success' => false, 'message' => 'Pasword must be between 12 and 72 cahrs']);
}

try{
    $check = $pdo->prepare('select 1 from users where username = :username or email = :email limit 1');
    $check->execute(['username' => $username, 'email' => $email]);

    if($check->fetchColumn()){
        respond(409, ['success' => false, 'message' => 'Username or email is already registerd']);

    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $pdo->prepare(
        'insert into users (username,email, password_hash, full_name, phone_number)
        values
        (:username, :email, :password_hash, :full_name, :phone_number)
        returning user_id, username, email, fullname, phone_number, created_at'
    );
    $insert->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'full_name' => $fullName,
        'phone_number' => $phoneNumber
    ]);

    $user = $insert->fetch(PDO::FETCH_ASSOC);

    session_regenerate_id(true);

    $_SESSION['user_id'] =(int) $user['user_id'];
    $_SESSION['username'] = $user['username'];

    respond(201, ['seccess' => true, 'message' => 'Registration seccessful.', 'data' => $user]);
}catch(PDOException $e){
    error_log('Registration API database error : ' . $e->getMessage());
    
    if($e->getCode() === '23505'){
        respond(409, ['success' => false, 'message' => 'Username or email is already registerd.']);
    }
    respond(500, [ 'success' => false, 'message' => 'Unable to complete regestration']);
}
?>