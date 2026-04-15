<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/app_client.php';

logout_user();
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signing out</title>
    <script>
    (function () {
        try {
            localStorage.removeItem(<?= json_encode(ALLUREONE_LS_DINGG_BEARER) ?>);
        } catch (e) {}
        location.replace('login.php');
    })();
    </script>
</head>
<body>
<p>Signing out…</p>
</body>
</html>
