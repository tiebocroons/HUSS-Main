<?php
require_once __DIR__ . '/auth.php';

$error = null;
$success = null;
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  $discord = isset($_POST['discord']) ? $_POST['discord'] : '';
  $password = isset($_POST['password']) ? $_POST['password'] : '';
  $role = isset($_POST['role']) ? $_POST['role'] : 'Member';
  $csrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
  if (!verify_csrf_token($csrf)) {
    $error = 'Invalid form submission (CSRF). Please reload and try again.';
  } else {
    if(register_user($discord, $password, $role, $error)){
      $success = 'Registration successful — you can now log in.';
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Register</title>
  <link rel="stylesheet" href="css/calculator.css">
  <link rel="stylesheet" href="css/index.css">
  <link rel="stylesheet" href="css/auth.css">
</head>
<body>
<main class="site-main">
<div class="container auth-wrap">
  <div class="card">
    <div class="header"><div class="title">Register</div><div class="sub">Create an account</div></div>
    <?php if($error): ?><div class="panel error"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <?php if($success): ?><div class="panel success"><?=htmlspecialchars($success)?></div><?php endif; ?>
    <form method="post" action="register.php">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(generate_csrf_token())?>" />
      <div class="form-row">
        <label>Discord name</label>
        <input type="text" name="discord" required />
      </div>
      <div class="form-row">
        <label>Password</label>
        <input type="password" name="password" required />
      </div>
      <div class="form-row">
        <label>Role</label>
        <div class="muted">Member (default)</div>
        <input type="hidden" name="role" value="Member" />
      </div>
      <div class="actions">
        <button class="btn primary" type="submit">Register</button>
        <a class="nav-link" href="login.php">Login</a>
      </div>
    </form>
  </div>
  </div>
  </main>
    <footer class="site-footer">
      Created by <strong>Hypha</strong> — <a href="https://cv.tiebocroons.be" target="_blank" rel="noopener noreferrer">cv.tiebocroons.be</a>
    </footer>
  </body>
  </html>
