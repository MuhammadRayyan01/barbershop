<?php
declare(strict_types=1);

require_once __DIR__ .'/../config/session.php';

if(!isset($_SESSION['user_id']) || !is_int($_SESSION['user_id']) || $_SESSION['user_id'] < 1){
    header('Location: /auth/login.php');
}
?>