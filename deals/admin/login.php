<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';

if (Auth::check()) {
    redirect(base_url('admin/index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session token. Please retry.';
    } else {
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (Auth::attempt($login, $password)) {
            redirect(base_url('admin/index.php'));
        }
        $error = 'Invalid credentials or insufficient role (admin/superadmin required).';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login | <?= e(config('site_name')) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body{min-height:100vh;display:grid;place-items:center;font-family:Outfit,sans-serif;background:linear-gradient(145deg,#978671,#B2BDA3 50%,#F8D3BB);margin:0}
    .card{width:min(420px,92vw);border:0;border-radius:18px;box-shadow:0 20px 50px rgba(0,0,0,.18);background:#F4E5D9}
    h1{font-family:"Cormorant Garamond",serif;font-size:2rem;color:#978671}
    .btn-brand{background:#978671;border:0}
    .btn-brand:hover{background:#7d6e5c}
  </style>
</head>
<body>
  <div class="card p-4 p-md-5">
    <h1>Allure Deals Admin</h1>
    <p class="text-muted">Sign in with your Allure Pro account</p>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= Security::csrfField() ?>
      <div class="mb-3">
        <label class="form-label">Username / Email</label>
        <input type="text" name="login" class="form-control" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button class="btn btn-brand text-white w-100 py-2" type="submit">Login</button>
    </form>
    <div class="text-center mt-3"><a href="<?= e(base_url()) ?>">← Back to store</a></div>
  </div>
</body>
</html>
