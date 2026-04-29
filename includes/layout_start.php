<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var string $activeNav */
require_once __DIR__ . '/app_client.php';
$config = require __DIR__ . '/../config.php';
$appName = $config['app']['name'];
$user = current_user();
$isAccountsRole = is_accounts_role($user);
$isFranchiseOfficerRole = is_franchise_officer_role($user);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="app" data-dingg-ls-key="<?= e(ALLUREONE_LS_DINGG_BEARER) ?>">
    <?php
    if (!empty($_SESSION['dingg_bearer_bootstrap'])) {
        $bootTok = (string) $_SESSION['dingg_bearer_bootstrap'];
        unset($_SESSION['dingg_bearer_bootstrap']);
        ?>
        <script>
        (function () {
            try {
                localStorage.setItem(<?= json_encode(ALLUREONE_LS_DINGG_BEARER) ?>, <?= json_encode($bootTok, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
            } catch (e) {}
        })();
        </script>
        <?php
    }
    ?>
    <aside class="sidebar" id="appSidebar">
        <button type="button" class="sidebar__close-btn" id="mobileMenuClose" aria-label="Close menu">×</button>
        <a class="sidebar__brand" href="<?= $isAccountsRole ? 'gift_codes.php' : ($isFranchiseOfficerRole ? 'Franchise-leads.php' : 'dashboard.php') ?>"><?= e($appName) ?></a>
        <nav class="sidebar__nav">
            <?php if (!$isAccountsRole && !$isFranchiseOfficerRole): ?>
                <a class="sidebar__link<?= ($activeNav === 'dashboard') ? ' is-active' : '' ?>" href="dashboard.php">Dashboard</a>
                <a class="sidebar__link<?= ($activeNav === 'invoice_cancellation') ? ' is-active' : '' ?>" href="invoice_cancellation.php">Invoice Cancellation</a>
            <?php endif; ?>
            <?php if ($user && !$isAccountsRole && !$isFranchiseOfficerRole && ((int) ($user['role_id'] ?? 0) === ROLE_SUPERADMIN || (int) ($user['role_id'] ?? 0) === ROLE_ADMIN)): ?>
                <a class="sidebar__link<?= ($activeNav === 'franchise_leads') ? ' is-active' : '' ?>" href="Franchise-leads.php">Franchise Leads</a>
            <?php endif; ?>
            <?php if ($isFranchiseOfficerRole): ?>
                <a class="sidebar__link<?= ($activeNav === 'franchise_leads') ? ' is-active' : '' ?>" href="Franchise-leads.php">Franchise Leads</a>
            <?php endif; ?>
            <?php if ($user && !$isAccountsRole && !$isFranchiseOfficerRole && ((int) ($user['role_id'] ?? 0) === ROLE_SUPERADMIN || (int) ($user['role_id'] ?? 0) === ROLE_ADMIN || (int) ($user['role_id'] ?? 0) === 3)): ?>
                <a class="sidebar__link<?= ($activeNav === 'google_ads_view') ? ' is-active' : '' ?>" href="google-ads-view.php">Google Ads View</a>
            <?php endif; ?>
            <?php if (!$isFranchiseOfficerRole): ?>
                <a class="sidebar__link<?= ($activeNav === 'gift_codes') ? ' is-active' : '' ?>" href="gift_codes.php">Gift Card Sale</a>
            <?php endif; ?>
            <?php if (!$isAccountsRole && !$isFranchiseOfficerRole): ?>
                <a class="sidebar__link<?= ($activeNav === 'sales_target') ? ' is-active' : '' ?>" href="sales_target.php">Sales target</a>
            <?php endif; ?>
            <?php if ($user && !$isAccountsRole && !$isFranchiseOfficerRole && $user['role_id'] === ROLE_SUPERADMIN): ?>
                <a class="sidebar__link<?= ($activeNav === 'branch') ? ' is-active' : '' ?>" href="branch_master.php">Branch Master</a>
                <a class="sidebar__link<?= ($activeNav === 'user') ? ' is-active' : '' ?>" href="user_master.php">User Master</a>
            <?php endif; ?>
            <a class="sidebar__link" href="logout.php">Logout</a>
            <?php if ($user && trim((string) ($user['full_name'] ?? '')) !== ''): ?>
                <p class="sidebar__user-line"><?= e((string) $user['full_name']) ?> · <?= e((string) ($user['loginname'] ?? '')) ?></p>
            <?php endif; ?>
        </nav>
    </aside>
    <main class="main">
        <header class="main__header">
            <button class="menu-toggle" type="button" id="menuToggle" aria-expanded="true" aria-controls="appSidebar" aria-label="Toggle menu" title="Toggle menu">
                <span class="menu-toggle__icon" aria-hidden="true"></span>
            </button>
            <img src="assets/images/allure-logo.png" alt="<?= e($appName) ?>" class="main__header-logo" loading="eager" decoding="async">
        </header>
        <div class="main__body">
        <?php
        if (!empty($_SESSION['dingg_auth_expired_notice'])) {
            $dinggAuthBanner = (string) $_SESSION['dingg_auth_expired_notice'];
            unset($_SESSION['dingg_auth_expired_notice']);
            ?>
            <div class="alert alert--error" role="alert" style="margin:0 0 1rem">
                <?= e($dinggAuthBanner) ?>
                <a href="logout.php" class="link--underlined" style="margin-left:0.35rem">Log out</a>
            </div>
            <?php
        }
        ?>
