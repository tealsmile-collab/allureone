<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var string $activeNav */
$config = require __DIR__ . '/../config.php';
$appName = $config['app']['name'];
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="app">
    <aside class="sidebar" id="appSidebar">
        <a class="sidebar__brand" href="dashboard.php"><?= e($appName) ?></a>
        <nav class="sidebar__nav">
            <a class="sidebar__link<?= ($activeNav === 'dashboard') ? ' is-active' : '' ?>" href="dashboard.php">Dashboard</a>
            <a class="sidebar__link<?= ($activeNav === 'gift_codes') ? ' is-active' : '' ?>" href="gift_codes.php">Gift codes</a>
            <a class="sidebar__link<?= ($activeNav === 'sales_target') ? ' is-active' : '' ?>" href="sales_target.php">Sales target</a>
            <?php if ($user && $user['role_id'] === ROLE_SUPERADMIN): ?>
                <a class="sidebar__link<?= ($activeNav === 'branch') ? ' is-active' : '' ?>" href="branch_master.php">Branch Master</a>
                <a class="sidebar__link<?= ($activeNav === 'user') ? ' is-active' : '' ?>" href="user_master.php">User Master</a>
            <?php endif; ?>
            <a class="sidebar__link sidebar__link--logout" href="logout.php">Logout</a>
        </nav>
    </aside>
    <main class="main">
        <header class="main__header">
            <button class="menu-toggle" type="button" id="menuToggle" aria-expanded="true" aria-controls="appSidebar" aria-label="Toggle menu" title="Toggle menu">
                <span class="menu-toggle__icon" aria-hidden="true"></span>
            </button>
            <h1 class="main__title"><?= e($pageTitle) ?></h1>
            <?php if ($user): ?>
                <p class="main__meta"><?= e($user['full_name']) ?> · <?= e($user['loginname']) ?></p>
            <?php endif; ?>
        </header>
        <div class="main__body">
