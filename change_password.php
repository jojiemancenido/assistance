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
            <input id="current_password" name="current_password" type="password" required />
          </div>

          <div class="field">
            <label for="new_password">New Password</label>
            <input id="new_password" name="new_password" type="password" required />
            <div class="help">Minimum 8 characters.</div>
          </div>

          <div class="field">
            <label for="confirm_password">Confirm New Password</label>
            <input id="confirm_password" name="confirm_password" type="password" required />
          </div>

          <div class="actions">
            <button class="btn" type="submit">Save New Password</button>
          </div>
        </form>
      </div>
    </section>
  </div>
</body>
</html>
