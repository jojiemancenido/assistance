<?php
require_once "auth.php";
require_login();
require_once "db.php";

function ensure_users_password_schema(mysqli $conn): void {
  $conn->query(
    "CREATE TABLE IF NOT EXISTS users (
      `user` VARCHAR(191) NOT NULL PRIMARY KEY,
      `password` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );

  if (!users_table_has_column("role")) {
    @$conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'admin'");
  }
  if (!users_table_has_column("created_at")) {
    @$conn->query("ALTER TABLE users ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
  }
}

ensure_users_password_schema($conn);

$status = trim((string)($_GET["status"] ?? ""));
$msg = trim((string)($_GET["msg"] ?? ""));
$error = "";
$username = current_auth_user();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $token = $_POST["csrf_token"] ?? "";
  $currentPassword = (string)($_POST["current_password"] ?? "");
  $newPassword = (string)($_POST["new_password"] ?? "");
  $confirmPassword = (string)($_POST["confirm_password"] ?? "");

  if (!verify_csrf_token($token)) {
    $error = "Invalid request token.";
  } elseif ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
    $error = "All password fields are required.";
  } elseif (!attempt_login($username, $currentPassword)) {
    audit_log("password_change_failed", "Password change failed: invalid current password.", $username, null);
    $error = "Current password is incorrect.";
  } elseif (strlen($newPassword) < 8) {
    $error = "New password must be at least 8 characters.";
  } elseif (!hash_equals($newPassword, $confirmPassword)) {
    $error = "New password and confirmation do not match.";
  } elseif (hash_equals($currentPassword, $newPassword)) {
    $error = "New password must be different from your current password.";
  } else {
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $existsStmt = $conn->prepare("SELECT 1 FROM users WHERE `user` = ? LIMIT 1");
    if (!$existsStmt) {
      $error = "Database error: " . $conn->error;
    } else {
      $existsStmt->bind_param("s", $username);
      $existsStmt->execute();
      $existsRs = $existsStmt->get_result();
      $exists = ($existsRs && $existsRs->num_rows > 0);
      $existsStmt->close();

      if ($exists) {
        $upStmt = $conn->prepare("UPDATE users SET `password` = ? WHERE `user` = ? LIMIT 1");
        if (!$upStmt) {
          $error = "Database error: " . $conn->error;
        } else {
          $upStmt->bind_param("ss", $passwordHash, $username);
          if (!$upStmt->execute()) {
            $error = "Failed updating password: " . $upStmt->error;
          }
          $upStmt->close();
        }
      } else {
        $role = fetch_user_role($username);
        $insStmt = $conn->prepare("INSERT INTO users (`user`, `password`, `role`, `created_at`) VALUES (?, ?, ?, UTC_TIMESTAMP())");
        if (!$insStmt) {
          $error = "Database error: " . $conn->error;
        } else {
          $insStmt->bind_param("sss", $username, $passwordHash, $role);
          if (!$insStmt->execute()) {
            $error = "Failed saving password: " . $insStmt->error;
          }
          $insStmt->close();
        }
      }
    }

    if ($error === "") {
      audit_log("password_change", "User changed account password.", $username, null);
      header("Location: change_password.php?status=success&msg=" . urlencode("Password updated successfully."));
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Change Password</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="app">
    <header class="app-header">
      <div class="brand-text">
        <h1>Change Password</h1>
        <p>Update your account password securely.</p>
      </div>
      <div class="header-meta">
        <a class="btn btn--secondary btn--sm" href="index.php">Back to Dashboard</a>
      </div>
    </header>

    <section class="card" style="max-width: 680px; margin: 0 auto;">
      <div class="card__header">
        <h2 class="card__title">Password Settings</h2>
        <p class="card__sub">Signed in as <strong><?php echo htmlspecialchars($username); ?></strong></p>
      </div>
      <div class="card__body">
        <?php if ($status === "success" && $msg !== ""): ?>
          <div class="alert alert--success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
          <div class="alert alert--error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="change_password.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />

          <div class="field">
            <label for="current_password">Current Password</label>
            <div class="password-field">
              <input id="current_password" name="current_password" type="password" required />
              <button
                type="button"
                class="password-toggle"
                data-target="current_password"
                data-visible="0"
                aria-label="Show current password"
                title="Show password"
              >
                <svg class="eye-icon eye-icon--open" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z" />
                  <circle cx="12" cy="12" r="3.25" />
                </svg>
                <svg class="eye-icon eye-icon--closed" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M3 3l18 18" />
                  <path d="M9.7 9.8A3.2 3.2 0 0 0 9 12a3.3 3.3 0 0 0 5.2 2.7" />
                  <path d="M5.2 7.4C3.6 8.7 2.5 10.6 2 12c1 2.1 4.3 6.5 10 6.5 2.1 0 3.9-.6 5.4-1.5" />
                  <path d="M10.9 5.6c.4-.1.7-.1 1.1-.1 5.7 0 9 4.4 10 6.5-.4.9-1.1 2.1-2.1 3.2" />
                </svg>
                <span class="password-toggle__text">Show</span>
              </button>
            </div>
          </div>

          <div class="field">
            <label for="new_password">New Password</label>
            <div class="password-field">
              <input id="new_password" name="new_password" type="password" required />
              <button
                type="button"
                class="password-toggle"
                data-target="new_password"
                data-visible="0"
                aria-label="Show new password"
                title="Show password"
              >
                <svg class="eye-icon eye-icon--open" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z" />
                  <circle cx="12" cy="12" r="3.25" />
                </svg>
                <svg class="eye-icon eye-icon--closed" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M3 3l18 18" />
                  <path d="M9.7 9.8A3.2 3.2 0 0 0 9 12a3.3 3.3 0 0 0 5.2 2.7" />
                  <path d="M5.2 7.4C3.6 8.7 2.5 10.6 2 12c1 2.1 4.3 6.5 10 6.5 2.1 0 3.9-.6 5.4-1.5" />
                  <path d="M10.9 5.6c.4-.1.7-.1 1.1-.1 5.7 0 9 4.4 10 6.5-.4.9-1.1 2.1-2.1 3.2" />
                </svg>
                <span class="password-toggle__text">Show</span>
              </button>
            </div>
            <div class="help">Minimum 8 characters.</div>
          </div>

          <div class="field">
            <label for="confirm_password">Confirm New Password</label>
            <div class="password-field">
              <input id="confirm_password" name="confirm_password" type="password" required />
              <button
                type="button"
                class="password-toggle"
                data-target="confirm_password"
                data-visible="0"
                aria-label="Show confirm password"
                title="Show password"
              >
                <svg class="eye-icon eye-icon--open" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z" />
                  <circle cx="12" cy="12" r="3.25" />
                </svg>
                <svg class="eye-icon eye-icon--closed" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M3 3l18 18" />
                  <path d="M9.7 9.8A3.2 3.2 0 0 0 9 12a3.3 3.3 0 0 0 5.2 2.7" />
                  <path d="M5.2 7.4C3.6 8.7 2.5 10.6 2 12c1 2.1 4.3 6.5 10 6.5 2.1 0 3.9-.6 5.4-1.5" />
                  <path d="M10.9 5.6c.4-.1.7-.1 1.1-.1 5.7 0 9 4.4 10 6.5-.4.9-1.1 2.1-2.1 3.2" />
                </svg>
                <span class="password-toggle__text">Show</span>
              </button>
            </div>
          </div>

          <div class="actions">
            <button class="btn" type="submit">Save New Password</button>
          </div>
        </form>
      </div>
    </section>
  </div>
  <script>
    (function () {
      const toggleButtons = document.querySelectorAll('.password-toggle[data-target]');
      if (!toggleButtons.length) return;

      function updateButtonState(button, input, isVisible) {
        button.dataset.visible = isVisible ? '1' : '0';
        button.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
        button.setAttribute('title', isVisible ? 'Hide password' : 'Show password');
        const text = button.querySelector('.password-toggle__text');
        if (text) {
          text.textContent = isVisible ? 'Hide' : 'Show';
        }
      }

      toggleButtons.forEach(function (button) {
        const targetId = button.dataset.target || '';
        const input = document.getElementById(targetId);
        if (!input) return;

        button.addEventListener('click', function () {
          const willShow = input.type === 'password';
          input.type = willShow ? 'text' : 'password';
          updateButtonState(button, input, willShow);
        });

        updateButtonState(button, input, input.type !== 'password');
      });
    })();
  </script>
</body>
</html>
