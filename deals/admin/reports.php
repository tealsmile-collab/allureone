<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="allure-deals-report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order No', 'Date', 'Customer', 'Mobile', 'Branch', 'City', 'Subtotal', 'Discount', 'GST', 'Grand Total', 'Coupon', 'Payment']);
    $rows = $db->query(
        "SELECT o.order_no, o.created_at, o.customer_name, o.customer_mobile, b.name branch_name, c.name city_name,
                o.subtotal, o.coupon_discount, o.gst_amount, o.grand_total, o.coupon_code, o.payment_status
         FROM alluredeal_orders o
         LEFT JOIN alluredeal_branch b ON b.id = o.branch_id
         LEFT JOIN alluredeal_city c ON c.id = o.city_id
         WHERE o.is_deleted = 0
         ORDER BY o.id DESC"
    )->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

$stats = (new CatalogModel())->dashboardStats();
$gst = $db->query(
    "SELECT COALESCE(SUM(gst_amount),0) gst, COALESCE(SUM(grand_total),0) total
     FROM alluredeal_orders WHERE payment_status='paid' AND is_deleted=0"
)->fetch();
$coupons = $db->query(
    "SELECT coupon_code, COUNT(*) cnt, SUM(coupon_discount) discount
     FROM alluredeal_orders WHERE coupon_code IS NOT NULL AND coupon_code <> '' AND payment_status='paid'
     GROUP BY coupon_code ORDER BY cnt DESC"
)->fetchAll();

admin_header('Reports', 'reports');
?>
<div class="d-flex justify-content-between mb-3">
  <div></div>
  <a class="btn btn-brand" href="?export=csv">CSV Export</a>
</div>
<div class="stat-grid mb-3">
  <div class="stat-card"><span>Paid Revenue</span><strong><?= e(money((float) $gst['total'])) ?></strong></div>
  <div class="stat-card"><span>GST Collected</span><strong><?= e(money((float) $gst['gst'])) ?></strong></div>
  <div class="stat-card"><span>Orders</span><strong><?= (int) $stats['orders'] ?></strong></div>
  <div class="stat-card"><span>Coupon Uses</span><strong><?= (int) $stats['couponUsage'] ?></strong></div>
</div>
<div class="row g-3">
  <div class="col-md-6"><div class="panel"><h2>Products</h2>
    <table class="table"><thead><tr><th>Product</th><th>Qty</th><th>Revenue</th></tr></thead><tbody>
    <?php foreach ($stats['topProducts'] as $p): ?>
      <tr><td><?= e($p['product_name']) ?></td><td><?= (int) $p['qty'] ?></td><td><?= e(money((float) $p['revenue'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div></div>
  <div class="col-md-6"><div class="panel"><h2>Coupons</h2>
    <table class="table"><thead><tr><th>Code</th><th>Uses</th><th>Discount</th></tr></thead><tbody>
    <?php foreach ($coupons as $c): ?>
      <tr><td><?= e((string) $c['coupon_code']) ?></td><td><?= (int) $c['cnt'] ?></td><td><?= e(money((float) $c['discount'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div></div>
  <div class="col-md-6"><div class="panel"><h2>Cities</h2>
    <?php foreach ($stats['byCity'] as $r): ?>
      <div class="d-flex justify-content-between border-bottom py-2"><span><?= e($r['name']) ?></span><strong><?= e(money((float) $r['revenue'])) ?></strong></div>
    <?php endforeach; ?>
  </div></div>
  <div class="col-md-6"><div class="panel"><h2>Branches</h2>
    <?php foreach ($stats['byBranch'] as $r): ?>
      <div class="d-flex justify-content-between border-bottom py-2"><span><?= e($r['name']) ?></span><strong><?= e(money((float) $r['revenue'])) ?></strong></div>
    <?php endforeach; ?>
  </div></div>
</div>
<?php admin_footer(); ?>
