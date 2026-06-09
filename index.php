<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (current_user() !== null) {
    allureone_redirect('dashboard.php');
}
allureone_redirect('login.php');
