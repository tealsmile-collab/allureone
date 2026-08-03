<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$stats = (new CatalogModel())->dashboardStats();
admin_header('Dashboard', 'dashboard');
?>
<div class="stat-grid">
  <div class="stat-card"><span>Revenue</span><strong><?= e(money($stats['revenue'])) ?></strong></div>
  <div class="stat-card"><span>Orders</span><strong><?= (int) $stats['orders'] ?></strong></div>
  <div class="stat-card"><span>Coupon Usage</span><strong><?= (int) $stats['couponUsage'] ?></strong></div>
  <div class="stat-card"><span>Customers</span><strong><?= (int) $stats['customers'] ?></strong></div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="panel">
      <h2>Sales (14 days)</h2>
      <canvas id="salesChart" height="120"></canvas>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="panel">
      <h2>Top Products</h2>
      <ul class="list-unstyled mb-0">
        <?php foreach ($stats['topProducts'] as $p): ?>
          <li class="d-flex justify-content-between py-2 border-bottom">
            <span><?= e($p['product_name']) ?></span>
            <strong><?= (int) $p['qty'] ?></strong>
          </li>
        <?php endforeach; ?>
        <?php if (!$stats['topProducts']): ?><li class="text-muted">No sales yet</li><?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="col-md-6">
    <div class="panel">
      <h2>Cities</h2>
      <ul class="list-unstyled mb-0">
        <?php foreach ($stats['byCity'] as $r): ?>
          <li class="d-flex justify-content-between py-2 border-bottom">
            <span><?= e($r['name']) ?></span>
            <span><?= e(money((float) $r['revenue'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="col-md-6">
    <div class="panel">
      <h2>Branches</h2>
      <ul class="list-unstyled mb-0">
        <?php foreach ($stats['byBranch'] as $r): ?>
          <li class="d-flex justify-content-between py-2 border-bottom">
            <span><?= e($r['name']) ?></span>
            <span><?= e(money((float) $r['revenue'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<script>
window.DASHBOARD_SALES = <?= json_encode($stats['salesGraph'], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php admin_footer(); ?>
