<?php
$pageTitle = htmlspecialchars($adminPageTitle ?? 'Admin Portal', ENT_QUOTES, 'UTF-8');
$adminName = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?></title>
  <link rel="stylesheet" href="../assets/css/tailwind.css">
  <link rel="stylesheet" href="../assets/css/admin.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
</head>
<body class="bg-[#f8f8fd] text-slate-900">
  <div id="admin-sidebar" data-admin-name="<?= $adminName ?>"></div>
  <div id="admin-topbar"></div>
