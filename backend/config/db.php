<?php
$host = 'localhost';
$port = '5432';
$dbname = 'barber_shop';
$username = 'ashfaq';
$password = 'ashfaq123';

try{
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

    $pdo = new PDO($dsn, $username, $password);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO:: ATTR_EMULATE_PREPARES, false);

}catch(PDOException $e){
    error_log("Database connection".$e->getMessage());
    http_response_code(500);
    exit('Database connection failed');
}
?>