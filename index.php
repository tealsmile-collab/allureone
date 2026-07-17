<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (current_user() !== null) {
    $cu = current_user();
    allureone_redirect(allureone_home_path_for_role((int) ($cu['role_id'] ?? 0)));
}
allureone_redirect('login.php');
