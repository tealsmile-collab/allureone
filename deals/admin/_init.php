<?php
/**
 * Admin layout bootstrap
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once ROOT_PATH . '/includes/models/ProductModel.php';
require_once ROOT_PATH . '/includes/models/CatalogModel.php';
require_once ROOT_PATH . '/includes/models/CartModel.php';
require_once ROOT_PATH . '/includes/services/OrderService.php';

Auth::requireAdmin();

function admin_header(string $title, string $active = 'dashboard'): void
{
    $user = Auth::user();
    $nav = [
        'dashboard' => ['Dashboard', 'index.php'],
        'products' => ['Products', 'products.php'],
        'categories' => ['Categories', 'categories.php'],
        'deals' => ["Today's Deals", 'deals.php'],
        'slider' => ['Hero Slider', 'slider.php'],
        'coupons' => ['Coupons', 'coupons.php'],
        'orders' => ['Orders', 'orders.php'],
        'customers' => ['Customers', 'customers.php'],
        'reports' => ['Reports', 'reports.php'],
        'settings' => ['Settings', 'settings.php'],
        'policies' => ['Policies', 'policies.php'],
    ];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' | Admin</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">';
    echo '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">';
    echo '<link href="' . e(asset_url('assets/css/admin.css')) . '" rel="stylesheet">';
    echo '<script>window.ADMIN={csrf:' . json_encode(Security::csrfToken()) . ',ajax:' . json_encode(base_url('admin/ajax.php')) . '};</script>';
    echo '</head><body><div class="admin-shell">';
    echo '<aside class="admin-sidebar"><div class="brand-mini">Allure Deals</div><nav>';
    foreach ($nav as $key => [$label, $file]) {
        $cls = $active === $key ? 'active' : '';
        echo '<a class="' . $cls . '" href="' . e(base_url('admin/' . $file)) . '">' . e($label) . '</a>';
    }
    echo '<a href="' . e(base_url('admin/logout.php')) . '">Logout</a>';
    echo '</nav></aside><main class="admin-main">';
    echo '<header class="admin-top"><h1>' . e($title) . '</h1>';
    echo '<div class="user-chip">' . e($user['full_name'] ?? $user['loginname']) . ' · ' . e($user['role']) . '</div></header><div class="admin-content">';
}

function admin_footer(): void
{
    echo '</div></main></div>';
    echo '<link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">';
    echo '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
    echo '<script src="' . e(asset_url('assets/js/admin.js')) . '"></script>';
    echo '</body></html>';
}
