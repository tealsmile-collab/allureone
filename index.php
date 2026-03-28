<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (current_user() !== null) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
