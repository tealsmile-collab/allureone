<?php
/**
 * Policy page entry (root) — works with rewrite and ?slug= / ?policy=
 * Pretty URL: /policy/{slug}  Direct: /policy.php?slug=privacy-policy
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/config.php';
}
require_once ROOT_PATH . '/includes/models/CatalogModel.php';

$slug = trim((string) ($_GET['slug'] ?? $_GET['policy'] ?? ''));

// Also accept path like /policy/privacy-policy when routed here without query
if ($slug === '' && !empty($_SERVER['REQUEST_URI'])) {
    $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    if (preg_match('#/policy/([a-z0-9\-]+)/?#i', $path, $m)) {
        $slug = strtolower($m[1]);
    }
}

if ($slug === '' || $slug === 'index') {
    header('Location: ' . base_url(), true, 302);
    exit;
}

$redirectMap = [
    'refund-policy' => 'cancellation-policy',
    'no-refund-policy' => 'cancellation-policy',
    'gift-voucher-policy' => 'digital-product-policy',
    'cancellation-and-refund-policy' => 'cancellation-policy',
];
if (isset($redirectMap[$slug])) {
    header('Location: ' . base_url('policy/' . $redirectMap[$slug] . '/'), true, 301);
    exit;
}

$fallbacks = [
    'privacy-policy' => ['title' => 'Privacy Policy', 'file' => 'privacy_policy.php'],
    'terms-conditions' => ['title' => 'Terms & Conditions', 'file' => 'terms_conditions.php'],
    'cancellation-policy' => ['title' => 'Cancellation Policy and Refund Policy', 'file' => 'cancellation_refund_policy.php'],
    'digital-product-policy' => ['title' => 'Digital Product Policy', 'file' => 'digital_product_policy.php'],
    'payment-policy' => ['title' => 'Payment Policy', 'file' => 'payment_policy.php'],
];

$policy = null;
try {
    $catalog = new CatalogModel();
    $policy = $catalog->policy($slug);
} catch (Throwable $e) {
    $policy = null;
}

if ((!$policy || trim((string) ($policy['content'] ?? '')) === '') && isset($fallbacks[$slug])) {
    $policy = [
        'title' => $fallbacks[$slug]['title'],
        'slug' => $slug,
        'content' => require ROOT_PATH . '/includes/content/' . $fallbacks[$slug]['file'],
    ];
}

if (!$policy) {
    http_response_code(404);
    $title = 'Policy not found';
    $content = '<p>The requested policy could not be found.</p><p><a href="' . e(base_url()) . '">Back to deals</a></p>';
} else {
    $title = (string) $policy['title'];
    $content = (string) $policy['content'];
}

$siteName = (string) config('site_name');
$company = (string) config('company_name');
$logo = logo_url();
$homeUrl = base_url();
$pageTitle = $title . ' | ' . $siteName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($title . ' — ' . $company . ' guest policies for Allure Thai Spa Deals.') ?>">
  <meta name="theme-color" content="#978671">
  <link rel="canonical" href="<?= e(base_url('policy/' . $slug . '/')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="<?= e(app_path('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="policy-page">
  <header class="site-header sticky-top">
    <nav class="navbar navbar-expand-lg">
      <div class="container">
        <a class="navbar-brand brand" href="<?= e($homeUrl) ?>">
          <span class="brand-mark"><img src="<?= e(logo_url()) ?>" alt="<?= e($company) ?>"></span>
          <span class="brand-text">
            <strong>Allure Thai</strong>
            <small>Spa & Wellness Deals</small>
          </span>
        </a>
        <div class="header-actions ms-auto">
          <a class="btn-icon" href="<?= e($homeUrl) ?>#deals"><i class="fa-solid fa-bolt"></i><span class="d-none d-lg-inline">Today's Deals</span></a>
          <a class="btn-icon cart-btn" href="<?= e($homeUrl) ?>" aria-label="Cart">
            <i class="fa-solid fa-cart-shopping"></i>
            <span class="cart-count" id="cartCount">0</span>
          </a>
        </div>
      </div>
    </nav>
  </header>

  <main class="policy-main">
    <div class="container">
      <div class="policy-toolbar">
        <a class="btn btn-sm btn-outline-dark" href="<?= e($homeUrl) ?>"><i class="fa-solid fa-arrow-left me-1"></i> Back to deals</a>
      </div>
      <article class="policy-article">
        <p class="policy-eyebrow"><?= e($company) ?></p>
        <h1><?= e($title) ?></h1>
        <div class="policy-body">
          <?= $content ?>
        </div>
      </article>
    </div>
  </main>

  <footer class="site-footer">
    <div class="container">
      <div class="footer-bottom mb-0 border-0 pt-0 mt-0">
        &copy; <?= date('Y') ?> <?= e($company) ?>. All rights reserved.
        · <a href="<?= e(base_url('policy/privacy-policy/')) ?>">Privacy Policy</a>
        · <a href="<?= e(base_url('policy/terms-conditions/')) ?>">Terms &amp; Conditions</a>
        · <a href="<?= e($homeUrl) ?>">Deals</a>
      </div>
    </div>
  </footer>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script>
    // Keep cart badge in sync with storefront session
    $.getJSON(<?= json_encode(app_path('ajax/index.php')) ?>, { action: 'bootstrap' })
      .done(function (res) {
        if (res && res.success && res.data && res.data.cart) {
          $('#cartCount').text(res.data.cart.item_count || 0);
        }
      });
  </script>
</body>
</html>
