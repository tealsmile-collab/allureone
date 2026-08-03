<?php
/**
 * Front Controller — SPA shell
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Pretty policy URLs may land here as ?policy=slug (legacy rewrite) — show policy page
$policySlug = trim((string) ($_GET['policy'] ?? ''));
if ($policySlug !== '') {
    $_GET['slug'] = $policySlug;
    require __DIR__ . '/policy.php';
    exit;
}

$siteName = config('site_name');
$company = config('company_name');
$logo = logo_url();
$csrf = Security::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($siteName) ?> | Premium Spa Offers</title>
  <meta name="description" content="Exclusive Allure Thai Spa deals — Thai massage, facial, hot stone & more across Mumbai, Thane, Navi Mumbai & beyond.">
  <meta name="theme-color" content="#978671">
  <link rel="canonical" href="<?= e(base_url()) ?>">
  <meta property="og:title" content="<?= e($siteName) ?>">
  <meta property="og:description" content="Luxury spa deals with Amazon-style shopping experience.">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= e(base_url()) ?>">
  <meta property="og:image" content="<?= e($logo) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="<?= e(asset_url('assets/css/app.css')) ?>" rel="stylesheet">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Store",
    "name": "<?= e($company) ?>",
    "url": "<?= e(base_url()) ?>",
    "telephone": "<?= e((string) config('support_phone')) ?>",
    "email": "<?= e((string) config('support_email')) ?>",
    "currenciesAccepted": "INR",
    "paymentAccepted": "Razorpay"
  }
  </script>
</head>
<body>
  <header class="site-header sticky-top">
    <nav class="navbar navbar-expand-lg">
      <div class="container">
        <a class="navbar-brand brand" href="<?= e(base_url()) ?>" data-nav="home">
          <span class="brand-mark"><img src="<?= e($logo) ?>" alt="<?= e($company) ?>"></span>
          <span class="brand-text">
            <strong>Allure Thai</strong>
            <small>Spa & Wellness Deals</small>
          </span>
        </a>
        <div class="header-actions ms-auto">
          <a class="btn-icon" href="#deals" data-nav="deals"><i class="fa-solid fa-bolt"></i><span class="d-none d-lg-inline">Today's Deals</span></a>
          <button class="btn-icon cart-btn" id="btnCart" type="button">
            <i class="fa-solid fa-cart-shopping"></i>
            <span class="cart-count" id="cartCount">0</span>
          </button>
        </div>
      </div>
    </nav>
  </header>

  <main id="app">
    <div class="home-stage">
      <section class="hero-section" id="heroSection">
        <div class="swiper hero-swiper" id="heroSwiper">
          <div class="swiper-wrapper" id="heroSlides">
            <div class="swiper-slide hero-skeleton"></div>
          </div>
          <div class="swiper-pagination"></div>
          <div class="swiper-button-prev"></div>
          <div class="swiper-button-next"></div>
        </div>
      </section>

      <section class="section deals-section" id="deals">
        <div class="container">
          <div class="deals-panel">
            <div class="section-head deals-head">
              <div>
                <p class="eyebrow">Limited time</p>
                <h2>Today's Deals</h2>
                <p class="price-tax-note mb-0">All prices include GST</p>
              </div>
              <div class="deals-filters" id="dealsFilters">
                <div class="deals-filter-group">
                  <label for="dealsMinPrice">Price</label>
                  <div class="d-flex gap-1">
                    <input type="number" id="dealsMinPrice" class="form-control form-control-sm" placeholder="Min" min="0" inputmode="numeric">
                    <input type="number" id="dealsMaxPrice" class="form-control form-control-sm" placeholder="Max" min="0" inputmode="numeric">
                  </div>
                </div>
                <div class="deals-filter-group">
                  <label for="dealsLocation">Location</label>
                  <select id="dealsLocation" class="form-select form-select-sm">
                    <option value="">All locations</option>
                  </select>
                </div>
                <div class="deals-filter-actions">
                  <button type="button" class="btn btn-sm btn-outline-dark" id="applyDealsFilters">Apply</button>
                  <button type="button" class="btn btn-sm btn-link" id="resetDealsFilters">Reset</button>
                </div>
              </div>
            </div>
            <div class="product-grid deals-grid" id="dealsGrid">
              <div class="skeleton-card"></div><div class="skeleton-card"></div><div class="skeleton-card"></div><div class="skeleton-card"></div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </main>

  <footer class="site-footer">
    <div class="container">
      <div class="row g-4">
        <div class="col-md-4">
          <div class="brand brand-light">
            <span class="brand-mark"><img src="<?= e($logo) ?>" alt="<?= e($company) ?>"></span>
            <span class="brand-text"><strong>Allure</strong><small>Thai Spa Deals</small></span>
          </div>
          <p class="mt-3">Luxury wellness deals with a seamless shopping experience. Book your favourite therapy at your nearest Allure centre.</p>
        </div>
        <div class="col-md-4">
          <h4>Policies</h4>
          <ul class="footer-links" id="footerPolicies">
            <li><a href="<?= e(base_url('policy/privacy-policy/')) ?>">Privacy Policy</a></li>
            <li><a href="<?= e(base_url('policy/terms-conditions/')) ?>">Terms &amp; Conditions</a></li>
            <li><a href="<?= e(base_url('policy/cancellation-policy/')) ?>">Cancellation Policy and Refund Policy</a></li>
            <li><a href="<?= e(base_url('policy/digital-product-policy/')) ?>">Digital Product Policy</a></li>
            <li><a href="<?= e(base_url('policy/payment-policy/')) ?>">Payment Policy</a></li>
          </ul>
        </div>
        <div class="col-md-4">
          <h4>Contact</h4>
          <p><i class="fa-solid fa-phone"></i> <?= e((string) config('support_phone')) ?></p>
          <p><i class="fa-solid fa-envelope"></i> <?= e((string) config('support_email')) ?></p>
        </div>
      </div>
      <div class="footer-bottom">&copy; <?= date('Y') ?> <?= e($company) ?>. All rights reserved.</div>
    </div>
  </footer>

  <div class="mobile-cart-bar" id="mobileCartBar">
    <div>
      <strong id="mobileCartTotal">₹0.00</strong>
      <small id="mobileCartItems">0 items</small>
    </div>
    <div class="mobile-cart-bar-actions">
      <button type="button" id="mobileCartBtn">View Cart</button>
      <button type="button" class="mobile-cart-close" id="mobileCartClose" title="Close" aria-label="Close cart bar">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>

  <!-- Sticky FABs: back-to-top + reserved phone slot -->
  <div class="sticky-fabs" id="stickyFabs">
    <button type="button" class="sticky-fab sticky-fab-top" id="btnBackToTop" title="Back to top" aria-label="Back to top" hidden>
      <i class="fa-solid fa-chevron-up"></i>
    </button>
    <div class="sticky-fab-phone-slot" id="stickyPhoneSlot" aria-hidden="true" title="Phone (coming soon)"></div>
  </div>

  <!-- Mobile Filters popup -->
  <div class="offcanvas offcanvas-bottom mobile-filters-sheet" tabindex="-1" id="mobileFiltersSheet">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title">Filters</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
      <div class="filter-group mb-3" id="mobileFilterCategoryGroup" hidden>
        <label class="form-label" for="mobileCategory">Category</label>
        <select id="mobileCategory" class="form-select">
          <option value="">All</option>
        </select>
      </div>
      <div class="filter-group mb-3">
        <label class="form-label">Price range (₹)</label>
        <div class="d-flex gap-2">
          <input type="number" id="mobileMinPrice" class="form-control" placeholder="Min" min="0" inputmode="numeric">
          <input type="number" id="mobileMaxPrice" class="form-control" placeholder="Max" min="0" inputmode="numeric">
        </div>
      </div>
      <div class="filter-group mb-3">
        <label class="form-label" for="mobileDuration">Duration</label>
        <select id="mobileDuration" class="form-select">
          <option value="">Any</option>
          <option value="45">45 min</option>
          <option value="60">60 min</option>
          <option value="75">75 min</option>
          <option value="90">90 min</option>
        </select>
      </div>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary flex-fill" id="mobileResetFilters">Clear</button>
        <button type="button" class="btn btn-primary flex-fill" id="mobileApplyFilters">Apply</button>
      </div>
    </div>
  </div>

  <!-- Cart Offcanvas -->
  <div class="offcanvas offcanvas-end cart-drawer" tabindex="-1" id="cartDrawer">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title">Your Cart</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body" id="cartBody"></div>
  </div>

  <!-- Product Quick View -->
  <div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content" id="productModalBody"></div>
    </div>
  </div>

  <!-- Checkout Modal -->
  <div class="modal fade" id="checkoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Checkout</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="checkoutForm" class="row g-3" novalidate>
            <div class="col-md-6">
              <label class="form-label">Name *</label>
              <input name="name" class="form-control" required maxlength="80" minlength="2" autocomplete="name">
              <small class="text-muted">Max 80 characters</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Mobile *</label>
              <input name="mobile" id="checkoutMobile" class="form-control" type="tel" required maxlength="15" minlength="10" pattern="[0-9]{10,15}" inputmode="numeric" autocomplete="tel" placeholder="10-digit mobile number">
              <small class="text-muted">Numbers only · Max 15 digits</small>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" maxlength="100" autocomplete="email">
              <small class="text-muted">Max 100 characters</small>
            </div>
            <div class="col-md-6"><label class="form-label">Gender</label>
              <select name="gender" class="form-select">
                <option value="">Select</option>
                <option value="female">Female</option>
                <option value="male">Male</option>
                <option value="other">Other</option>
                <option value="prefer_not">Prefer not to say</option>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">City</label><select name="city_id" id="checkoutCity" class="form-select"></select></div>
            <div class="col-md-6"><label class="form-label">Branch *</label><select name="branch_id" id="checkoutBranch" class="form-select" required></select></div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2" maxlength="200"></textarea>
              <small class="text-muted">Max 200 characters</small>
            </div>
            <div class="col-12" id="checkoutSummary"></div>
            <div class="col-12"><button class="btn btn-primary w-100" type="submit">Pay Securely with Razorpay</button></div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Policy Modal -->
  <div class="modal fade" id="policyModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content" id="policyBody"></div></div></div>

  <script>
    window.ALLURE = {
      baseUrl: <?= json_encode(base_url()) ?>,
      ajaxUrl: <?= json_encode(app_path('ajax/index.php')) ?>,
      csrf: <?= json_encode($csrf) ?>
    };
  </script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
  <script src="<?= e(asset_url('assets/js/app.js')) ?>"></script>
</body>
</html>
