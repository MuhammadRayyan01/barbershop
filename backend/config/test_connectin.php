<?php

use LDAP\Result;

require_once __DIR__ . '/db.php';

try{
    $stmt = $pdo->query('select current_database() as database_name');
    $result = $stmt->fetch();

    echo 'Database connecton successful!<br>';
    echo 'connected database : '. htmlspecialchars($result['database_name'], ENT_QUOTES, 'UTF-8');

}catch (PDOException $e){
    error_log("Database test failed : ".$e->getMessage());
    http_response_code(500);
    echo 'Database query failed.';
}
?>