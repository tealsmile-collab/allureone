<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $stmt = $db->prepare(
        'SELECT * FROM alluredeal_customer WHERE is_deleted=0 AND (name LIKE ? OR mobile LIKE ? OR email LIKE ?) ORDER BY id DESC LIMIT 200'
    );
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $rows = $stmt->fetchAll();
} else {
    $rows = $db->query('SELECT * FROM alluredeal_customer WHERE is_deleted=0 ORDER BY id DESC LIMIT 200')->fetchAll();
}
admin_header('Customers', 'customers');
?>
<div class="panel mb-3">
  <form method="get" class="row g-2"><div class="col-md-6"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name / mobile / email"></div>
  <div class="col-md-2"><button class="btn btn-brand">Search</button></div></form>
</div>
<div class="panel">
  <table class="table"><thead><tr><th>Name</th><th>Mobile</th><th>Email</th><th>Orders</th><th>Spent</th><th>Joined</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td>
      <td><?= e($r['mobile']) ?></td>
      <td><?= e((string) $r['email']) ?></td>
      <td><?= (int) $r['total_orders'] ?></td>
      <td><?= e(money((float) $r['total_spent'])) ?></td>
      <td><?= e($r['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php admin_footer(); ?>
