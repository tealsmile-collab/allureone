<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
Auth::logout();
redirect(base_url('admin/login.php'));
