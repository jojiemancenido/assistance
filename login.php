<?php
require_once 'auth.php';
secure_session_start();

if (is_logged_in()) {
  if (is_super_admin()) {
    header('Location: super_admin.php');
  } else {
    header('Location: index.php');
  }
  exit;
}

$redirectRaw = trim((string)($_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php'));
$redirect = rawurldecode($redirectRaw);
if ($redirect === '' || str_contains($redirect, '://') || str_starts_with($redirect, '//')) {
  $redirect = 'index.php';
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string)($_POST['username'] ?? $_POST['user'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $token = $_POST['csrf_token'] ?? '';

  if (!verify_csrf_token($token)) {
    $error = 'Invalid request token. Please try again.';
  } elseif ($username === '' || $password === '') {
    $error = 'Username and password are required.';
  } elseif (!attempt_login($username, $password)) {
    audit_log('login_failed', 'Invalid username or password.', $username, null);
    $error = 'Invalid username or password.';
  } else {
    $loggedIn = login_user($username);

    if (!$loggedIn) {
      audit_log('login_blocked', 'Login blocked because this account is already active on another device.', $username, null);
      $error = 'This account is already logged in on another browser or device.';
    } else {
      audit_log('login_success', 'User logged in.', $username, null);
      $target = $redirect;
      if (($target === '' || $target === 'index.php' || $target === './index.php') && is_super_admin()) {
        $target = 'super_admin.php';
      }
      header('Location: ' . $target);
      exit;
    }
  }
}

$msg = trim((string)($_GET['msg'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login - Beneficiary Records</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div id="startup-splash" class="startup-splash" aria-hidden="true">
    <div class="startup-splash__inner">
      <span class="startup-splash__ring" aria-hidden="true"></span>
      <img class="startup-splash__logo" src="daet%20logo%20lgu.png" alt="Bayan ng Daet Logo" />
      <p class="startup-splash__title">Bayan ng Daet</p>
      <p class="startup-splash__sub">Camarines Norte</p>
    </div>
  </div>
  <script>
    (function () {
      var splash = document.getElementById('startup-splash');
      if (!splash) return;

      var minDuration = 900;
      var startAt = Date.now();

      function closeSplash() {
        if (!splash || splash.dataset.done === '1') return;
        splash.dataset.done = '1';
        splash.classList.add('startup-splash--leave');
        window.setTimeout(function () {
          if (splash && splash.parentNode) {
            splash.parentNode.removeChild(splash);
          }
        }, 420);
      }

      function scheduleClose() {
        var elapsed = Date.now() - startAt;
        var wait = Math.max(0, minDuration - elapsed);
        window.setTimeout(closeSplash, wait);
      }

      window.addEventListener('load', scheduleClose, { once: true });
      window.setTimeout(closeSplash, 1800);
    })();
  </script>
  <div class="auth-shell">
    <section class="card auth-card">
      <div class="card__header">
        <h1 class="card__title">Sign In</h1>
        <p class="card__sub">Enter your account to access beneficiary records.</p>
      </div>
      <div class="card__body">
        <?php if ($msg !== ''): ?>
          <div class="alert alert--success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="alert alert--error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
          <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>" />

          <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" required />
          </div>

          <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required />
          </div>

          <div class="actions">
            <button class="btn" type="submit">Log In</button>
          </div>
        </form>
      </div>
    </section>
  </div>
</body>
</html>
