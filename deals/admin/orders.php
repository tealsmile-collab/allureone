<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$filters = [
    'q' => $_GET['q'] ?? '',
    'branch_id' => $_GET['branch_id'] ?? '',
    'city_id' => $_GET['city_id'] ?? '',
    'payment_status' => $_GET['payment_status'] ?? '',
    'coupon' => $_GET['coupon'] ?? '',
    'status' => $_GET['status'] ?? '',
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$data = (new OrderService())->list($filters, $page, 20);
$catalog = new CatalogModel();
$cities = $catalog->cities();
$branches = $catalog->branches();

if (!empty($_GET['invoice'])) {
    $order = (new OrderService())->getOrder((int) $_GET['invoice']);
    if (!empty($order['invoice_path']) && is_file(ROOT_PATH . '/' . $order['invoice_path'])) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(ROOT_PATH . '/' . $order['invoice_path']);
        exit;
    }
    $path = (new OrderService())->generateInvoice((int) $_GET['invoice']);
    redirect(base_url($path));
}

admin_header('Orders', 'orders');
?>
<div class="panel mb-3">
  <form class="row g-2 align-items-end" method="get">
    <div class="col-md-3"><input class="form-control" name="q" placeholder="Search customer / order / mobile" value="<?= e($filters['q']) ?>"></div>
    <div class="col-md-2">
      <select class="form-select" name="city_id"><option value="">City</option>
        <?php foreach ($cities as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $filters['city_id'] == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select class="form-select" name="branch_id"><option value="">Branch</option>
        <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $filters['branch_id'] == $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select class="form-select" name="payment_status">
        <option value="">Payment</option>
        <?php foreach (['pending','paid','failed','refunded'] as $s): ?>
          <option value="<?= $s ?>" <?= $filters['payment_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1"><input class="form-control" name="coupon" placeholder="Coupon" maxlength="20" value="<?= e($filters['coupon']) ?>"></div>
    <div class="col-md-2"><button class="btn btn-brand w-100">Filter</button></div>
  </form>
</div>
<div class="panel">
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Order</th><th>Customer</th><th>Branch</th><th>Amount</th><th>Payment</th><th>Coupon</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($data['items'] as $o): ?>
        <tr>
          <td><strong><?= e($o['order_no']) ?></strong><br><small><?= e($o['created_at']) ?></small></td>
          <td><?= e($o['customer_name']) ?><br><small><?= e($o['customer_mobile']) ?></small></td>
          <td><?= e($o['branch_name'] ?? '') ?><br><small><?= e($o['city_name'] ?? '') ?></small></td>
          <td><?= e(money((float) $o['grand_total'])) ?></td>
          <td><?= e($o['payment_status']) ?></td>
          <td><?= e((string) $o['coupon_code']) ?></td>
          <td><?= e($o['status_code']) ?></td>
          <td><a class="btn btn-sm btn-outline-dark" href="?invoice=<?= (int) $o['id'] ?>" target="_blank">Invoice</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="d-flex gap-2">
    <?php for ($i = 1; $i <= max(1, $data['pages']); $i++): ?>
      <a class="btn btn-sm <?= $i === $data['page'] ? 'btn-brand' : 'btn-outline-dark' ?>" href="?<?= e(http_build_query(array_merge($filters, ['page' => $i]))) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
</div>
<?php admin_footer(); ?>
