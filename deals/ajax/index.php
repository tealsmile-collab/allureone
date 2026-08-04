<?php
/**
 * Public AJAX Router — SPA endpoints
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once ROOT_PATH . '/includes/helpers/OrderCartLog.php';
require_once ROOT_PATH . '/includes/models/ProductModel.php';
require_once ROOT_PATH . '/includes/models/CartModel.php';
require_once ROOT_PATH . '/includes/models/CatalogModel.php';
require_once ROOT_PATH . '/includes/services/OrderService.php';

header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $catalog = new CatalogModel();
    $products = new ProductModel();
    $cart = new CartModel();
    $token = cart_token();

    switch ($action) {
        case 'bootstrap':
            Response::success([
                'csrf' => Security::csrfToken(),
                'site' => [
                    'name' => config('site_name'),
                    'currency_symbol' => config('currency_symbol'),
                    'gst_percent' => config('gst_percent'),
                    'support_phone' => config('support_phone'),
                    'support_email' => config('support_email'),
                    'logo' => logo_url(),
                ],
                'categories' => $catalog->categories(),
                'cities' => $catalog->cities(),
                'branches' => $catalog->branches(),
                'sliders' => $catalog->sliders(),
                'policies' => $catalog->policies(),
                'cart' => $cart->summary($token),
                'deals' => $products->list(['today_deal' => 1, 'sort' => 'discount'], 1, 48),
            ]);

        case 'products':
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = max(1, min(48, (int) ($_GET['per_page'] ?? config('app.items_per_page', 12))));
            $filters = [
                'q' => $_GET['q'] ?? '',
                'category_id' => $_GET['category_id'] ?? '',
                'category_slug' => $_GET['category_slug'] ?? '',
                'min_price' => $_GET['min_price'] ?? '',
                'max_price' => $_GET['max_price'] ?? '',
                'min_discount' => $_GET['min_discount'] ?? '',
                'duration' => $_GET['duration'] ?? '',
                'sort' => $_GET['sort'] ?? 'popular',
                'today_deal' => $_GET['today_deal'] ?? '',
                'featured' => $_GET['featured'] ?? '',
                'city_id' => $_GET['city_id'] ?? '',
                'branch_id' => $_GET['branch_id'] ?? '',
            ];
            Response::success($products->list($filters, $page, $perPage));

        case 'product':
            $slug = (string) ($_GET['slug'] ?? '');
            $product = $products->findBySlug($slug);
            if (!$product) {
                Response::error('Product not found', 404);
            }
            $product['related'] = $products->related((int) $product['category_id'], (int) $product['id']);
            Response::success($product);

        case 'branches':
            $cityId = !empty($_GET['city_id']) ? (int) $_GET['city_id'] : null;
            Response::success($catalog->branches($cityId));

        case 'sliders':
            Response::success($catalog->sliders());

        case 'cart_get':
            Response::success($cart->summary($token));

        case 'cart_add':
            Security::requireCsrf();
            $in = json_input();
            $data = $cart->add(
                $token,
                (int) ($in['product_id'] ?? 0),
                (int) ($in['quantity'] ?? 1),
                !empty($in['city_id']) ? (int) $in['city_id'] : null,
                !empty($in['branch_id']) ? (int) $in['branch_id'] : null
            );
            Response::success($data, 'Added to cart');

        case 'cart_update':
            Security::requireCsrf();
            $in = json_input();
            Response::success($cart->updateQty($token, (int) ($in['item_id'] ?? 0), (int) ($in['quantity'] ?? 1)));

        case 'cart_remove':
            Security::requireCsrf();
            $in = json_input();
            Response::success($cart->remove($token, (int) ($in['item_id'] ?? 0)), 'Item removed');

        case 'coupon_apply':
            Security::requireCsrf();
            $in = json_input();
            Response::success($cart->applyCoupon($token, (string) ($in['code'] ?? '')), 'Coupon applied');

        case 'coupon_remove':
            Security::requireCsrf();
            Response::success($cart->removeCoupon($token), 'Coupon removed');

        case 'checkout_create':
            Security::requireCsrf();
            $in = json_input();
            $required = ['name', 'mobile', 'branch_id'];
            foreach ($required as $f) {
                if (empty($in[$f])) {
                    Response::error(ucfirst(str_replace('_', ' ', $f)) . ' is required');
                }
            }

            $name = Security::clean((string) $in['name']);
            $countryCode = preg_replace('/\D+/', '', Security::clean((string) ($in['country_code'] ?? '91'))) ?? '91';
            if ($countryCode === '') {
                $countryCode = '91';
            }
            if (strlen($countryCode) > 4) {
                Response::error('Invalid country code');
            }
            $mobileLocal = preg_replace('/\D+/', '', Security::clean((string) $in['mobile'])) ?? '';
            $email = Security::clean((string) ($in['email'] ?? ''));
            $notes = Security::clean((string) ($in['notes'] ?? ''));

            if (mb_strlen($name) > 80) {
                Response::error('Name must be max 80 characters');
            }
            if ($mobileLocal === '' || !preg_match('/^\d{10}$/', $mobileLocal)) {
                Response::error('Mobile must be exactly 10 digits');
            }
            $mobileFull = format_phone_with_country($mobileLocal, $countryCode);
            if ($mobileFull === '' || strlen($mobileFull) < 11 || strlen($mobileFull) > 15) {
                Response::error('Invalid mobile number for selected country code');
            }
            if ($email !== '' && mb_strlen($email) > 100) {
                Response::error('Email must be max 100 characters');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Enter a valid email address');
            }
            if (mb_strlen($notes) > 150) {
                Response::error('Notes must be max 150 characters');
            }

            $orders = new OrderService();
            $result = $orders->createFromCart([
                'name' => mb_substr($name, 0, 80),
                'mobile' => $mobileFull,
                'mobile_local' => $mobileLocal,
                'country_code' => $countryCode,
                'email' => $email !== '' ? mb_substr($email, 0, 100) : '',
                'gender' => Security::clean((string) ($in['gender'] ?? '')),
                'notes' => mb_substr($notes, 0, 150),
                'city_id' => !empty($in['city_id']) ? (int) $in['city_id'] : null,
                'branch_id' => (int) $in['branch_id'],
            ], $token);
            Response::success($result, 'Order created');

        case 'payment_verify':
            Security::requireCsrf();
            $in = json_input();
            $orders = new OrderService();
            $order = $orders->verifyPayment($in);
            Response::success([
                'order_no' => $order['order_no'],
                'invoice_no' => $order['invoice_no'],
                'payment_status' => $order['payment_status'],
                'grand_total' => $order['grand_total'],
                'invoice_url' => !empty($order['invoice_path']) ? asset_url($order['invoice_path']) : null,
            ], 'Payment successful');

        case 'policy':
            $slug = (string) ($_GET['slug'] ?? '');
            $policy = $catalog->policy($slug);
            if (!$policy) {
                Response::error('Policy not found', 404);
            }
            Response::success($policy);

        default:
            Response::error('Unknown action', 404);
    }
} catch (Throwable $e) {
    $msg = APP_ENV === 'development' ? $e->getMessage() : 'Something went wrong';
    if (APP_ENV !== 'development') {
        // still return useful business errors
        $msg = $e->getMessage() ?: $msg;
    }
    Response::error($msg, 400);
}
