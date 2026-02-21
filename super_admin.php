<?php
require_once "auth.php";
require_super_admin();
require_once "db.php";

ensure_audit_log_table();
ensure_active_session_table();
cleanup_expired_active_sessions();

function has_column(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = @$conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("ss", $table, $column);
  $st->execute();
  $rs = $st->get_result();
  return ($rs && $rs->num_rows > 0);
}

function extract_specify_from_notes(string &$notes): string {
  if (preg_match('/^\[SPECIFY\](.*?)\[\/SPECIFY\]\s*/s', $notes, $m)) {
    $spec = trim((string)$m[1]);
    $notes = (string)preg_replace('/^\[SPECIFY\].*?\[\/SPECIFY\]\s*/s', '', $notes);
    return $spec;
  }
  return "";
}

function build_type_label(string $type, string $spec): string {
  $type = trim($type);
  $spec = trim($spec);
  if ($type === "Other" && $spec !== "") {
    return "Other: " . $spec;
  }
  return $type;
}

function build_sa_query(array $base, array $override = []): string {
  $q = array_merge($base, $override);
  foreach ($q as $k => $v) {
    if ($v === "" || $v === null) unset($q[$k]);
  }
  return http_build_query($q);
}

function ensure_users_admin_schema(mysqli $conn): void {
  $conn->query(
    "CREATE TABLE IF NOT EXISTS users (
      `user` VARCHAR(191) NOT NULL PRIMARY KEY,
      `password` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );

  if (!has_column($conn, "users", "role")) {
    @$conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'admin'");
  }
  if (!has_column($conn, "users", "created_at")) {
    @$conn->query("ALTER TABLE users ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
  }
}

ensure_users_admin_schema($conn);

$status = trim((string)($_GET["status"] ?? ""));
$msg = trim((string)($_GET["msg"] ?? ""));
$authUser = current_auth_user();
$tzLabel = app_timezone_label();

function redirect_super_admin(string $status, string $message, string $extraQuery = ""): void {
  $base = "super_admin.php?status=" . urlencode($status) . "&msg=" . urlencode($message);
  if ($extraQuery !== "") {
    $base .= "&" . ltrim($extraQuery, "&");
  }
  header("Location: " . $base);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (($_POST["action"] ?? "") === "create_account")) {
  $token = $_POST["csrf_token"] ?? "";
  $newUsername = trim((string)($_POST["new_username"] ?? ""));
  $newPassword = (string)($_POST["new_password"] ?? "");
  $newRole = trim((string)($_POST["new_role"] ?? "admin"));

  if (!verify_csrf_token($token)) {
    redirect_super_admin("error", "Invalid request token.");
  }

  if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $newUsername)) {
    redirect_super_admin("error", "Username must be 3-64 chars and use letters, numbers, _, ., - only.");
  }
  if (strlen($newPassword) < 8) {
    redirect_super_admin("error", "Password must be at least 8 characters.");
  }

  $allowedRoles = ["admin", "user", "super_admin"];
  if (!in_array($newRole, $allowedRoles, true)) {
    $newRole = "admin";
  }

  $existsStmt = $conn->prepare("SELECT 1 FROM users WHERE `user` = ? LIMIT 1");
  if (!$existsStmt) {
    redirect_super_admin("error", "Database error: " . $conn->error);
  }
  $existsStmt->bind_param("s", $newUsername);
  $existsStmt->execute();
  $existsRs = $existsStmt->get_result();
  $existsStmt->close();
  if ($existsRs && $existsRs->num_rows > 0) {
    redirect_super_admin("error", "Username already exists.");
  }

  $hash = password_hash($newPassword, PASSWORD_DEFAULT);
  $insertStmt = $conn->prepare("INSERT INTO users (`user`, `password`, `role`, `created_at`) VALUES (?, ?, ?, UTC_TIMESTAMP())");
  if (!$insertStmt) {
    redirect_super_admin("error", "Database error: " . $conn->error);
  }
  $insertStmt->bind_param("sss", $newUsername, $hash, $newRole);
  if (!$insertStmt->execute()) {
    $err = $insertStmt->error;
    $insertStmt->close();
    redirect_super_admin("error", "Failed creating account: " . $err);
  }
  $insertStmt->close();

  audit_log("account_create", "Super admin created account \"" . $newUsername . "\" with role \"" . $newRole . "\".", $authUser !== "" ? $authUser : null, null);
  redirect_super_admin("success", "Account created successfully.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (($_POST["action"] ?? "") === "update_account")) {
  $token = $_POST["csrf_token"] ?? "";
  $originalUsername = trim((string)($_POST["original_username"] ?? ""));
  $editUsername = trim((string)($_POST["edit_username"] ?? ""));
  $editRole = trim((string)($_POST["edit_role"] ?? "admin"));
  $editPassword = (string)($_POST["edit_password"] ?? "");

  $extra = "edit_user=" . urlencode($originalUsername) . "#edit-account-section";

  if (!verify_csrf_token($token)) {
    redirect_super_admin("error", "Invalid request token.", $extra);
  }
  if ($originalUsername === "") {
    redirect_super_admin("error", "Invalid account selected.", $extra);
  }
  if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $editUsername)) {
    redirect_super_admin("error", "Username must be 3-64 chars and use letters, numbers, _, ., - only.", $extra);
  }
  if ($editPassword !== "" && strlen($editPassword) < 8) {
    redirect_super_admin("error", "New password must be at least 8 characters.", $extra);
  }

  $allowedRoles = ["admin", "user", "super_admin"];
  if (!in_array($editRole, $allowedRoles, true)) {
    $editRole = "admin";
  }
  if ($originalUsername === $authUser && $editRole !== "super_admin") {
    redirect_super_admin("error", "You cannot remove your own super admin role.", $extra);
  }

  $existsStmt = $conn->prepare("SELECT `user` FROM users WHERE `user` = ? LIMIT 1");
  if (!$existsStmt) {
    redirect_super_admin("error", "Database error: " . $conn->error, $extra);
  }
  $existsStmt->bind_param("s", $originalUsername);
  $existsStmt->execute();
  $existsRs = $existsStmt->get_result();
  $existsStmt->close();
  if (!$existsRs || $existsRs->num_rows === 0) {
    redirect_super_admin("error", "Account not found.", $extra);
  }

  if ($editUsername !== $originalUsername) {
    $dupeStmt = $conn->prepare("SELECT 1 FROM users WHERE `user` = ? LIMIT 1");
    if (!$dupeStmt) {
      redirect_super_admin("error", "Database error: " . $conn->error, $extra);
    }
    $dupeStmt->bind_param("s", $editUsername);
    $dupeStmt->execute();
    $dupeRs = $dupeStmt->get_result();
    $dupeStmt->close();
    if ($dupeRs && $dupeRs->num_rows > 0) {
      redirect_super_admin("error", "Username already exists.", $extra);
    }
  }

  $conn->begin_transaction();
  try {
    if ($editPassword !== "") {
      $hash = password_hash($editPassword, PASSWORD_DEFAULT);
      $upStmt = $conn->prepare("UPDATE users SET `user` = ?, `password` = ?, `role` = ? WHERE `user` = ? LIMIT 1");
      if (!$upStmt) {
        throw new RuntimeException("Database error: " . $conn->error);
      }
      $upStmt->bind_param("ssss", $editUsername, $hash, $editRole, $originalUsername);
    } else {
      $upStmt = $conn->prepare("UPDATE users SET `user` = ?, `role` = ? WHERE `user` = ? LIMIT 1");
      if (!$upStmt) {
        throw new RuntimeException("Database error: " . $conn->error);
      }
      $upStmt->bind_param("sss", $editUsername, $editRole, $originalUsername);
    }
    if (!$upStmt->execute()) {
      $err = $upStmt->error;
      $upStmt->close();
      throw new RuntimeException("Failed updating account: " . $err);
    }
    $upStmt->close();

    if ($editUsername !== $originalUsername) {
      $sessStmt = $conn->prepare("UPDATE auth_active_sessions SET username = ? WHERE username = ?");
      if ($sessStmt) {
        $sessStmt->bind_param("ss", $editUsername, $originalUsername);
        $sessStmt->execute();
        $sessStmt->close();
      }

      $logStmt = $conn->prepare("UPDATE auth_audit_logs SET username = ? WHERE username = ?");
      if ($logStmt) {
        $logStmt->bind_param("ss", $editUsername, $originalUsername);
        $logStmt->execute();
        $logStmt->close();
      }
    }

    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    redirect_super_admin("error", $e->getMessage(), $extra);
  }

  if ($originalUsername === $authUser) {
    $_SESSION["auth_user"] = $editUsername;
    $_SESSION["auth_role"] = $editRole;
    $authUser = $editUsername;
  }

  audit_log(
    "account_update",
    "Super admin updated account \"" . $originalUsername . "\" to username \"" . $editUsername . "\" with role \"" . $editRole . "\".",
    $authUser !== "" ? $authUser : null,
    null
  );
  redirect_super_admin("success", "Account updated successfully.", "edit_user=" . urlencode($editUsername) . "#edit-account-section");
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (($_POST["action"] ?? "") === "delete_account")) {
  $token = $_POST["csrf_token"] ?? "";
  $deleteUsername = trim((string)($_POST["delete_username"] ?? ""));
  $extra = "#accounts-section";

  if (!verify_csrf_token($token)) {
    redirect_super_admin("error", "Invalid request token.", $extra);
  }
  if ($deleteUsername === "") {
    redirect_super_admin("error", "Invalid account selected.", $extra);
  }
  if ($deleteUsername === $authUser) {
    redirect_super_admin("error", "You cannot delete your own active account.", $extra);
  }

  $targetStmt = $conn->prepare("SELECT role FROM users WHERE `user` = ? LIMIT 1");
  if (!$targetStmt) {
    redirect_super_admin("error", "Database error: " . $conn->error, $extra);
  }
  $targetStmt->bind_param("s", $deleteUsername);
  $targetStmt->execute();
  $targetRs = $targetStmt->get_result();
  $targetRow = $targetRs ? $targetRs->fetch_assoc() : null;
  $targetStmt->close();
  if (!$targetRow) {
    redirect_super_admin("error", "Account not found.", $extra);
  }

  $targetRole = trim((string)($targetRow["role"] ?? ""));
  if ($targetRole === "super_admin") {
    $superCountRs = @$conn->query("SELECT COUNT(*) AS total_super_admin FROM users WHERE role = 'super_admin'");
    $superCount = 0;
    if ($superCountRs && $superCountRow = $superCountRs->fetch_assoc()) {
      $superCount = (int)($superCountRow["total_super_admin"] ?? 0);
    }
    if ($superCount <= 1) {
      redirect_super_admin("error", "Cannot delete the last super admin account.", $extra);
    }
  }

  $conn->begin_transaction();
  try {
    $sessStmt = $conn->prepare("DELETE FROM auth_active_sessions WHERE username = ?");
    if ($sessStmt) {
      $sessStmt->bind_param("s", $deleteUsername);
      $sessStmt->execute();
      $sessStmt->close();
    }

    $deleteStmt = $conn->prepare("DELETE FROM users WHERE `user` = ? LIMIT 1");
    if (!$deleteStmt) {
      throw new RuntimeException("Database error: " . $conn->error);
    }
    $deleteStmt->bind_param("s", $deleteUsername);
    if (!$deleteStmt->execute()) {
      $err = $deleteStmt->error;
      $deleteStmt->close();
      throw new RuntimeException("Failed deleting account: " . $err);
    }
    $affected = (int)$deleteStmt->affected_rows;
    $deleteStmt->close();
    if ($affected < 1) {
      throw new RuntimeException("Account not found.");
    }

    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    redirect_super_admin("error", $e->getMessage(), $extra);
  }

  audit_log(
    "account_delete",
    "Super admin deleted account \"" . $deleteUsername . "\".",
    $authUser !== "" ? $authUser : null,
    null
  );
  redirect_super_admin("success", "Account deleted successfully.", $extra);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (($_POST["action"] ?? "") === "force_logout_account")) {
  $token = $_POST["csrf_token"] ?? "";
  $targetUsername = trim((string)($_POST["target_username"] ?? ""));
  $extra = "#active-sessions-section";

  if (!verify_csrf_token($token)) {
    redirect_super_admin("error", "Invalid request token.", $extra);
  }
  if ($targetUsername === "") {
    redirect_super_admin("error", "Invalid account selected.", $extra);
  }
  if ($targetUsername === $authUser) {
    redirect_super_admin("error", "You cannot force logout your current session here.", $extra);
  }

  $activeStmt = $conn->prepare(
    "SELECT 1 FROM auth_active_sessions WHERE username = ? AND expires_at >= UTC_TIMESTAMP() LIMIT 1"
  );
  if (!$activeStmt) {
    redirect_super_admin("error", "Database error: " . $conn->error, $extra);
  }
  $activeStmt->bind_param("s", $targetUsername);
  $activeStmt->execute();
  $activeRs = $activeStmt->get_result();
  $isActive = ($activeRs && $activeRs->num_rows > 0);
  $activeStmt->close();

  if (!$isActive) {
    redirect_super_admin("error", "Account is not currently active.", $extra);
  }

  $kickStmt = $conn->prepare("DELETE FROM auth_active_sessions WHERE username = ?");
  if (!$kickStmt) {
    redirect_super_admin("error", "Database error: " . $conn->error, $extra);
  }
  $kickStmt->bind_param("s", $targetUsername);
  if (!$kickStmt->execute()) {
    $err = $kickStmt->error;
    $kickStmt->close();
    redirect_super_admin("error", "Failed forcing logout: " . $err, $extra);
  }
  $affected = (int)$kickStmt->affected_rows;
  $kickStmt->close();

  if ($affected < 1) {
    redirect_super_admin("error", "Account is not currently active.", $extra);
  }

  audit_log(
    "force_logout",
    "Super admin forced logout for account \"" . $targetUsername . "\".",
    $authUser !== "" ? $authUser : null,
    null
  );

  redirect_super_admin("success", "Account logged out from active session.", $extra);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (($_POST["action"] ?? "") === "delete_record")) {
  $token = $_POST["csrf_token"] ?? "";
  $deleteRecordId = (int)($_POST["delete_record_id"] ?? 0);

  $returnType = trim((string)($_POST["records_type"] ?? ""));
  $returnBarangay = trim((string)($_POST["records_barangay"] ?? ""));
  $returnSort = trim((string)($_POST["records_sort"] ?? "new"));
  $returnQuery = build_sa_query([
    "records_type" => $returnType,
    "records_barangay" => $returnBarangay,
    "records_sort" => $returnSort,
  ]);
  $extra = ($returnQuery !== "" ? ($returnQuery . "&") : "") . "#all-assistance-section";

  if (!verify_csrf_token($token)) {
    redirect_super_admin("error", "Invalid request token.", $extra);
  }
  if ($deleteRecordId <= 0) {
    redirect_super_admin("error", "Invalid record selected.", $extra);
  }

  $nameStmt = $conn->prepare("SELECT name FROM records WHERE record_id = ? LIMIT 1");
  if (!$nameStmt) {
    redirect_super_admin("error", "Database error: " . $conn->error, $extra);
  }
  $nameStmt->bind_param("i", $deleteRecordId);
  $nameStmt->execute();
  $nameRs = $nameStmt->get_result();
  $nameRow = $nameRs ? $nameRs->fetch_assoc() : null;
  $nameStmt->close();
  if (!$nameRow) {
    redirect_super_admin("error", "Record not found.", $extra);
  }

  $recordName = trim((string)($nameRow["name"] ?? ""));

  $deleteStmt = $conn->prepare("DELETE FROM records WHERE record_id = ? LIMIT 1");
  if (!$deleteStmt) {
    redirect_super_admin("error", "Database error: " . $conn->error, $extra);
  }
  $deleteStmt->bind_param("i", $deleteRecordId);
  if (!$deleteStmt->execute()) {
    $err = $deleteStmt->error;
    $deleteStmt->close();
    redirect_super_admin("error", "Failed deleting record: " . $err, $extra);
  }
  $affected = (int)$deleteStmt->affected_rows;
  $deleteStmt->close();
  if ($affected < 1) {
    redirect_super_admin("error", "Record not found.", $extra);
  }

  audit_log(
    "record_delete",
    "Super admin deleted record #" . $deleteRecordId . " for \"" . $recordName . "\".",
    $authUser !== "" ? $authUser : null,
    $deleteRecordId
  );

  redirect_super_admin("success", "Record deleted successfully.", $extra);
}

$editUser = trim((string)($_GET["edit_user"] ?? ""));
$editAccount = null;
if ($editUser !== "") {
  $editStmt = $conn->prepare("SELECT `user` AS username, `role`, `created_at` FROM users WHERE `user` = ? LIMIT 1");
  if ($editStmt) {
    $editStmt->bind_param("s", $editUser);
    $editStmt->execute();
    $editRs = $editStmt->get_result();
    $editAccount = $editRs ? $editRs->fetch_assoc() : null;
    $editStmt->close();
  }
}

$totalAccounts = 0;
$accountsCountRs = @$conn->query("SELECT COUNT(*) AS total_accounts FROM users");
if ($accountsCountRs && $row = $accountsCountRs->fetch_assoc()) {
  $totalAccounts = (int)($row["total_accounts"] ?? 0);
}

$totalRecords = 0;
$recordsCountRs = @$conn->query("SELECT COUNT(*) AS total_records FROM records");
if ($recordsCountRs && $row = $recordsCountRs->fetch_assoc()) {
  $totalRecords = (int)($row["total_records"] ?? 0);
}

$totalAmount = 0.0;
$amountRs = @$conn->query("SELECT COALESCE(SUM(amount), 0) AS total_amount FROM records");
if ($amountRs && $row = $amountRs->fetch_assoc()) {
  $totalAmount = (float)($row["total_amount"] ?? 0);
}

$totalActiveUsers = 0;
$activeCountRs = @$conn->query("SELECT COUNT(*) AS total_active FROM auth_active_sessions WHERE expires_at >= UTC_TIMESTAMP()");
if ($activeCountRs && $row = $activeCountRs->fetch_assoc()) {
  $totalActiveUsers = (int)($row["total_active"] ?? 0);
}

$totalLogs = 0;
$logsCountRs = @$conn->query("SELECT COUNT(*) AS total_logs FROM auth_audit_logs");
if ($logsCountRs && $row = $logsCountRs->fetch_assoc()) {
  $totalLogs = (int)($row["total_logs"] ?? 0);
}

$accountsSql = "SELECT
                  u.`user` AS username,
                  u.role,
                  u.created_at,
                  CASE
                    WHEN s.username IS NOT NULL AND s.expires_at >= UTC_TIMESTAMP() THEN 'Active'
                    ELSE 'Inactive'
                  END AS current_status,
                  MAX(CASE WHEN l.action = 'login_success' THEN l.created_at ELSE NULL END) AS last_login_at
                FROM users u
                LEFT JOIN auth_active_sessions s ON s.username = u.`user`
                LEFT JOIN auth_audit_logs l ON l.username = u.`user`
                GROUP BY u.`user`, u.role, u.created_at, s.username, s.expires_at
                ORDER BY u.`user` ASC";
$accounts = @$conn->query($accountsSql);

$activeSessionsSql = "SELECT
                        s.username,
                        s.created_at,
                        s.last_seen,
                        s.expires_at,
                        MAX(CASE WHEN l.action = 'login_success' THEN l.created_at ELSE NULL END) AS last_login_at
                      FROM auth_active_sessions s
                      LEFT JOIN auth_audit_logs l ON l.username = s.username
                      WHERE s.expires_at >= UTC_TIMESTAMP()
                      GROUP BY s.username, s.created_at, s.last_seen, s.expires_at
                      ORDER BY s.last_seen DESC";
$activeSessions = @$conn->query($activeSessionsSql);

$hasTypeSpecify = has_column($conn, "records", "type_specify");

$saTypeFilter = trim((string)($_GET["records_type"] ?? ""));
$saBarangayFilter = trim((string)($_GET["records_barangay"] ?? ""));
$saRecordsSort = trim((string)($_GET["records_sort"] ?? "new"));

$typeTotals = [];
$typeOptions = [];
$typesBase = ["Medical", "Burial", "Livelihood", "Other"];
$totalsSql = "SELECT type, notes";
if ($hasTypeSpecify) {
  $totalsSql .= ", type_specify";
}
$totalsSql .= " FROM records";
$totalsRs = @$conn->query($totalsSql);
if ($totalsRs) {
  while ($row = $totalsRs->fetch_assoc()) {
    $type = (string)($row["type"] ?? "");
    if ($type === "") continue;
    $notesVal = (string)($row["notes"] ?? "");
    $spec = "";
    if ($hasTypeSpecify) {
      $spec = trim((string)($row["type_specify"] ?? ""));
    } else {
      $spec = extract_specify_from_notes($notesVal);
    }
    $label = build_type_label($type, $spec);
    if ($label === "") continue;
    if (!isset($typeTotals[$label])) {
      $typeTotals[$label] = 0;
    }
    $typeTotals[$label] += 1;
    if (!isset($typeOptions[$label])) {
      $typeOptions[$label] = ["type" => $type, "spec" => $spec];
    }
  }
}
foreach ($typesBase as $t) {
  if (!isset($typeTotals[$t])) $typeTotals[$t] = 0;
  if (!isset($typeOptions[$t])) $typeOptions[$t] = ["type" => $t, "spec" => ""];
}
$orderedTypeLabels = [];
foreach ($typesBase as $t) {
  if (isset($typeTotals[$t])) $orderedTypeLabels[] = $t;
}
$extraLabels = array_values(array_diff(array_keys($typeTotals), $orderedTypeLabels));
natcasesort($extraLabels);
foreach ($extraLabels as $label) {
  $orderedTypeLabels[] = $label;
}

$saAllowedSorts = [
  "new" => "record_id DESC",
  "old" => "record_id ASC",
  "amount_desc" => "amount DESC, record_id DESC",
  "amount_asc" => "amount ASC, record_id DESC",
  "date_new" => "record_date DESC, record_id DESC",
  "date_old" => "record_date ASC, record_id DESC",
  "month_year_new" => "month_year DESC, record_date DESC, record_id DESC",
  "month_year_old" => "month_year ASC, record_date ASC, record_id ASC",
];
$saOrderBy = $saAllowedSorts[$saRecordsSort] ?? $saAllowedSorts["new"];

$saWhere = [];
$saParamTypes = "";
$saParams = [];
if ($saTypeFilter !== "") {
  if (isset($typeOptions[$saTypeFilter])) {
    $filterInfo = $typeOptions[$saTypeFilter];
    $saWhere[] = "type = ?";
    $saParamTypes .= "s";
    $saParams[] = $filterInfo["type"];
    if ($filterInfo["type"] === "Other" && $filterInfo["spec"] !== "") {
      if ($hasTypeSpecify) {
        $saWhere[] = "type_specify = ?";
        $saParamTypes .= "s";
        $saParams[] = $filterInfo["spec"];
      } else {
        $saWhere[] = "notes LIKE ?";
        $saParamTypes .= "s";
        $saParams[] = "[SPECIFY]" . $filterInfo["spec"] . "[/SPECIFY]%";
      }
    }
  } else {
    $saWhere[] = "type = ?";
    $saParamTypes .= "s";
    $saParams[] = $saTypeFilter;
  }
}
if ($saBarangayFilter !== "") {
  $saWhere[] = "barangay = ?";
  $saParamTypes .= "s";
  $saParams[] = $saBarangayFilter;
}

$recordsCols = "record_id, name, type, barangay, municipality, province, amount, record_date, month_year, notes";
if ($hasTypeSpecify) {
  $recordsCols .= ", type_specify";
}
$recordsSql = "SELECT $recordsCols FROM records";
if (!empty($saWhere)) {
  $recordsSql .= " WHERE " . implode(" AND ", $saWhere);
}
$recordsSql .= " ORDER BY $saOrderBy";
$recordsAll = null;
$recordsStmt = $conn->prepare($recordsSql);
if ($recordsStmt) {
  if (!empty($saParams)) {
    $recordsStmt->bind_param($saParamTypes, ...$saParams);
  }
  $recordsStmt->execute();
  $recordsAll = $recordsStmt->get_result();
}

$saBarangays = [];
$barangayRs = @$conn->query("SELECT DISTINCT barangay FROM records WHERE barangay IS NOT NULL AND barangay <> '' ORDER BY barangay ASC");
if ($barangayRs) {
  while ($bRow = $barangayRs->fetch_assoc()) {
    $bName = trim((string)($bRow["barangay"] ?? ""));
    if ($bName !== "") $saBarangays[] = $bName;
  }
}

$saBaseQuery = [
  "records_type" => $saTypeFilter,
  "records_barangay" => $saBarangayFilter,
  "records_sort" => $saRecordsSort,
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Super Admin Dashboard</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body class="superadmin-page">
  <div class="app">
    <header class="app-header">
      <div class="brand-text">
        <h1>Super Admin Dashboard</h1>
        <p>Global system management and statistics</p>
      </div>
      <div class="header-meta">
        <div class="user-chip">Role <strong>Super Admin</strong></div>
        <a class="btn btn--secondary btn--sm" href="index.php">Dashboard</a>
        <a class="btn btn--secondary btn--sm" href="logs.php">Logs</a>
      </div>
    </header>

    <?php if ($msg !== ""): ?>
      <div class="alert <?php echo ($status === "error") ? "alert--error" : "alert--success"; ?>">
        <?php echo htmlspecialchars($msg); ?>
      </div>
    <?php endif; ?>

    <section class="card">
      <div class="card__header">
        <h2 class="card__title">Global Statistics</h2>
        <p class="card__sub">All accounts, records, and totals across the system.</p>
      </div>
      <div class="card__body">
        <div class="type-grid">
          <div class="type-stat"><div class="type-name">Total Accounts</div><div class="type-amount"><?php echo number_format($totalAccounts); ?></div></div>
          <div class="type-stat"><div class="type-name">Active Accounts</div><div class="type-amount"><?php echo number_format($totalActiveUsers); ?></div></div>
          <div class="type-stat"><div class="type-name">Total Assistance Records</div><div class="type-amount"><?php echo number_format($totalRecords); ?></div></div>
          <div class="type-stat"><div class="type-name">Total Assistance Amount</div><div class="type-amount">PHP <?php echo number_format($totalAmount, 2); ?></div></div>
          <div class="type-stat"><div class="type-name">Total Audit Logs</div><div class="type-amount"><?php echo number_format($totalLogs); ?></div></div>
        </div>
      </div>
    </section>

    <section id="accounts-section" class="card section accounts-hub">
      <div class="card__header card__header--row accounts-hub__header">
        <div>
          <h2 class="card__title">Account Center</h2>
          <p class="card__sub">Create new accounts and manage all record accounts in one place.</p>
        </div>
        <div class="accounts-hub__badges">
          <span class="accounts-hub__badge"><strong><?php echo number_format($totalAccounts); ?></strong> Total</span>
          <span class="accounts-hub__badge accounts-hub__badge--active"><strong><?php echo number_format($totalActiveUsers); ?></strong> Active</span>
        </div>
      </div>
      <div class="card__body accounts-hub__body">
        <details id="create-account-panel" class="accounts-create-disclosure">
          <summary class="accounts-create-disclosure__summary">
            <div class="accounts-create-disclosure__title">
              <h3>Create Account</h3>
              <p>Click to open the form and add a new account.</p>
            </div>
            <span class="accounts-create-disclosure__plus" aria-hidden="true">+</span>
          </summary>
          <div class="accounts-create-panel">
            <form class="accounts-create-form" method="POST" action="super_admin.php" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
              <input type="hidden" name="action" value="create_account" />
              <div class="form-grid accounts-create-grid">
                <div class="field">
                  <label for="new_username">Username</label>
                  <input id="new_username" name="new_username" type="text" required />
                </div>
                <div class="field">
                  <label for="new_password">Password</label>
                  <input id="new_password" name="new_password" type="password" required />
                </div>
                <div class="field accounts-create-role">
                  <label for="new_role">Role</label>
                  <select id="new_role" name="new_role">
                    <option value="admin">admin</option>
                    <option value="user">user</option>
                    <option value="super_admin">super_admin</option>
                  </select>
                </div>
              </div>
              <div class="actions accounts-create-actions">
                <button class="btn" type="submit">Create Account</button>
              </div>
            </form>
          </div>
        </details>

        <div class="accounts-table-panel"> 
          <div class="accounts-table-panel__head">
            <div>
              <h3>All Record Accounts</h3>
              <p>Account status and recent login activity.</p>
            </div>
          </div>
          <div class="table-wrap accounts-table-wrap">
            <div class="table-scroll" style="max-height: 420px; overflow-y:auto;">
              <table>
                <thead>
                  <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created (<?php echo htmlspecialchars($tzLabel); ?>)</th>
                    <th>Last Login (<?php echo htmlspecialchars($tzLabel); ?>)</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($accounts && $accounts->num_rows > 0): ?>
                    <?php while ($u = $accounts->fetch_assoc()): ?>
                      <?php
                        $isActive = ((string)($u["current_status"] ?? "") === "Active");
                        $createdAt = format_utc_datetime_for_app((string)($u["created_at"] ?? ""));
                        $lastLogin = format_utc_datetime_for_app((string)($u["last_login_at"] ?? ""));
                      ?>
                      <tr>
                        <td class="strong"><?php echo htmlspecialchars((string)$u["username"]); ?></td>
                        <td class="mono"><?php echo htmlspecialchars((string)$u["role"]); ?></td>
                        <td><span class="status-pill <?php echo $isActive ? "status-pill--active" : "status-pill--inactive"; ?>"><?php echo $isActive ? "Active" : "Inactive"; ?></span></td>
                        <td class="mono"><?php echo htmlspecialchars($createdAt !== "" ? $createdAt : "-"); ?></td>
                        <td class="mono"><?php echo htmlspecialchars($lastLogin !== "" ? $lastLogin : "-"); ?></td>
                        <td>
                          <div class="account-action-group">
                            <a class="btn btn--secondary btn--sm" href="super_admin.php?edit_user=<?php echo urlencode((string)$u["username"]); ?>#edit-account-section">Edit</a>
                            <form method="POST" action="super_admin.php#accounts-section" class="inline-form" onsubmit="return confirm('Delete account <?php echo htmlspecialchars(addslashes((string)$u["username"])); ?>?');">
                              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
                              <input type="hidden" name="action" value="delete_account" />
                              <input type="hidden" name="delete_username" value="<?php echo htmlspecialchars((string)$u["username"]); ?>" />
                              <button class="btn btn--sm btn--danger" type="submit">Delete</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr><td colspan="6" class="muted">No accounts found.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>

    <?php if ($editAccount): ?>
      <?php
        $editCreatedAt = format_utc_datetime_for_app((string)($editAccount["created_at"] ?? ""));
        $editRoleCurrent = (string)($editAccount["role"] ?? "admin");
        $editUsernameCurrent = (string)($editAccount["username"] ?? "");
      ?>
      <section id="edit-account-section" class="card section">
        <div class="card__header">
          <h2 class="card__title">Edit Account Data</h2>
          <p class="card__sub">Update username, role, and password for this account.</p>
        </div>
        <div class="card__body">
          <p class="muted">Editing: <strong><?php echo htmlspecialchars($editUsernameCurrent); ?></strong> | Created: <?php echo htmlspecialchars($editCreatedAt !== "" ? $editCreatedAt : "-"); ?> <?php echo htmlspecialchars($tzLabel); ?></p>
          <form method="POST" action="super_admin.php#edit-account-section" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
            <input type="hidden" name="action" value="update_account" />
            <input type="hidden" name="original_username" value="<?php echo htmlspecialchars($editUsernameCurrent); ?>" />
            <div class="form-grid">
              <div class="field">
                <label for="edit_username">Username</label>
                <input id="edit_username" name="edit_username" type="text" required value="<?php echo htmlspecialchars($editUsernameCurrent); ?>" />
              </div>
              <div class="field">
                <label for="edit_role">Role</label>
                <select id="edit_role" name="edit_role">
                  <option value="admin" <?php echo $editRoleCurrent === "admin" ? "selected" : ""; ?>>admin</option>
                  <option value="user" <?php echo $editRoleCurrent === "user" ? "selected" : ""; ?>>user</option>
                  <option value="super_admin" <?php echo $editRoleCurrent === "super_admin" ? "selected" : ""; ?>>super_admin</option>
                </select>
              </div>
              <div class="field field--full">
                <label for="edit_password">New Password (optional)</label>
                <input id="edit_password" name="edit_password" type="password" placeholder="Leave blank to keep current password" />
              </div>
            </div>
            <div class="actions">
              <a class="btn btn--secondary" href="super_admin.php#accounts-section">Cancel</a>
              <button class="btn" type="submit">Save Account Changes</button>
            </div>
          </form>
        </div>
      </section>
    <?php endif; ?>

    <section id="active-sessions-section" class="card section">
      <div class="card__header">
        <h2 class="card__title">Who Is Active Now</h2>
        <p class="card__sub">Active users and login/session timestamps.</p>
      </div>
      <div class="card__body">
        <div class="table-wrap">
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Username</th>
                  <th>Login Time-Date (<?php echo htmlspecialchars($tzLabel); ?>)</th>
                  <th>Last Seen (<?php echo htmlspecialchars($tzLabel); ?>)</th>
                  <th>Session Expires (<?php echo htmlspecialchars($tzLabel); ?>)</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($activeSessions && $activeSessions->num_rows > 0): ?>
                  <?php while ($s = $activeSessions->fetch_assoc()): ?>
                    <?php
                      $activeUsername = (string)($s["username"] ?? "");
                      $loginAt = format_utc_datetime_for_app((string)($s["last_login_at"] ?? ""));
                      $lastSeen = format_utc_datetime_for_app((string)($s["last_seen"] ?? ""));
                      $expiresAt = format_utc_datetime_for_app((string)($s["expires_at"] ?? ""));
                      $isOwnSession = ($activeUsername !== "" && $activeUsername === $authUser);
                    ?>
                    <tr>
                      <td class="strong"><?php echo htmlspecialchars($activeUsername); ?></td>
                      <td class="mono"><?php echo htmlspecialchars($loginAt !== "" ? $loginAt : "-"); ?></td>
                      <td class="mono"><?php echo htmlspecialchars($lastSeen !== "" ? $lastSeen : "-"); ?></td>
                      <td class="mono"><?php echo htmlspecialchars($expiresAt !== "" ? $expiresAt : "-"); ?></td>
                      <td>
                        <?php if ($isOwnSession): ?>
                          <span class="muted">Current Session</span>
                        <?php else: ?>
                          <form method="POST" action="super_admin.php#active-sessions-section" class="inline-form" onsubmit="return confirm('Force logout <?php echo htmlspecialchars(addslashes($activeUsername)); ?>?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
                            <input type="hidden" name="action" value="force_logout_account" />
                            <input type="hidden" name="target_username" value="<?php echo htmlspecialchars($activeUsername); ?>" />
                            <button class="btn btn--sm btn--danger" type="submit">Log Out Device</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="5" class="muted">No active users right now.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

    <section class="card section">
      <div class="card__header">
        <h2 class="card__title">Statistics by Assistance Type</h2>
        <p class="card__sub">All assistance categories and counts.</p>
      </div>
      <div class="card__body">
        <div class="type-grid">
          <?php foreach ($orderedTypeLabels as $label): ?>
            <div class="type-stat">
              <div class="type-name"><?php echo htmlspecialchars($label); ?></div>
              <div class="type-count"><?php echo number_format((float)($typeTotals[$label] ?? 0)); ?> records</div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section id="all-assistance-section" class="card section">
      <div class="card__header">
        <h2 class="card__title">All Assistance Records</h2>
        <p class="card__sub">
          Complete record data across the system. Export all records or selected assistance types.
          <?php if ($saTypeFilter !== "" || $saBarangayFilter !== ""): ?>
            <br />Active filter:
            <?php echo htmlspecialchars($saTypeFilter !== "" ? ("Type = " . $saTypeFilter) : "All Types"); ?>,
            <?php echo htmlspecialchars($saBarangayFilter !== "" ? ("Barangay = " . $saBarangayFilter) : "All Barangays"); ?>
          <?php endif; ?>
        </p>
      </div>
      <div class="card__body">
        <form class="super-export-form" method="GET" action="super_admin_export.php">
          <div class="super-export-types">
            <?php foreach ($orderedTypeLabels as $label): ?>
              <label class="super-export-type-item">
                <input type="checkbox" name="types[]" value="<?php echo htmlspecialchars($label); ?>" />
                <span><?php echo htmlspecialchars($label); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="super-export-actions">
            <button class="btn btn--secondary btn--sm" type="submit" name="format" value="excel">Export Excel</button>
            <button class="btn btn--secondary btn--sm" type="submit" name="format" value="pdf">Export PDF</button>
          </div>
          <p class="card__sub">Leave all unchecked to export all assistance types. Select one or more to export specific types.</p>
        </form>

        <div class="table-wrap">
          <div class="table-scroll" style="max-height: 520px; overflow-y:auto;">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>
                    <span class="sa-th-with-menu">
                      <span>Type</span>
                      <details class="sa-th-menu">
                        <summary class="sa-th-menu__summary" aria-label="Type filter"></summary>
                        <div class="sa-th-menu__list">
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_type" => ""]); ?>#all-assistance-section">All Types</a>
                          <?php foreach ($orderedTypeLabels as $label): ?>
                            <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_type" => $label]); ?>#all-assistance-section"><?php echo htmlspecialchars($label); ?></a>
                          <?php endforeach; ?>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th>
                    <span class="sa-th-with-menu">
                      <span>Barangay</span>
                      <details class="sa-th-menu">
                        <summary class="sa-th-menu__summary" aria-label="Barangay filter"></summary>
                        <div class="sa-th-menu__list">
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_barangay" => ""]); ?>#all-assistance-section">All Barangays</a>
                          <?php foreach ($saBarangays as $bName): ?>
                            <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_barangay" => $bName]); ?>#all-assistance-section"><?php echo htmlspecialchars($bName); ?></a>
                          <?php endforeach; ?>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th>Municipality</th>
                  <th>Province</th>
                  <th>
                    <span class="sa-th-with-menu">
                      <span>Amount</span>
                      <details class="sa-th-menu">
                        <summary class="sa-th-menu__summary" aria-label="Amount sort"></summary>
                        <div class="sa-th-menu__list">
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_sort" => "amount_desc"]); ?>#all-assistance-section">High to Low</a>
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_sort" => "amount_asc"]); ?>#all-assistance-section">Low to High</a>
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_sort" => "new"]); ?>#all-assistance-section">Default (Newest)</a>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th>
                    <span class="sa-th-with-menu">
                      <span>Date</span>
                      <details class="sa-th-menu">
                        <summary class="sa-th-menu__summary" aria-label="Date sort"></summary>
                        <div class="sa-th-menu__list">
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_sort" => "date_new"]); ?>#all-assistance-section">New to Old</a>
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_sort" => "date_old"]); ?>#all-assistance-section">Old to New</a>
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_sort" => "new"]); ?>#all-assistance-section">Default (Newest)</a>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th>
                    <span class="sa-th-with-menu">
                      <span>Month-Year</span>
                      <details class="sa-th-menu">
                        <summary class="sa-th-menu__summary" aria-label="Month-Year sort"></summary>
                        <div class="sa-th-menu__list">
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_sort" => "month_year_new"]); ?>#all-assistance-section">New to Old</a>
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_sort" => "month_year_old"]); ?>#all-assistance-section">Old to New</a>
                          <a href="super_admin.php?<?php echo build_sa_query($saBaseQuery, ["records_sort" => "new"]); ?>#all-assistance-section">Default (Newest)</a>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th>Notes</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($recordsAll && $recordsAll->num_rows > 0): ?>
                  <?php while ($r = $recordsAll->fetch_assoc()): ?>
                    <?php
                      $notesVal = (string)($r["notes"] ?? "");
                      $spec = "";
                      if ($hasTypeSpecify) {
                        $spec = trim((string)($r["type_specify"] ?? ""));
                      } else {
                        $spec = extract_specify_from_notes($notesVal);
                      }
                      $typeLabel = build_type_label((string)($r["type"] ?? ""), $spec);
                    ?>
                    <tr>
                      <td class="mono"><?php echo htmlspecialchars((string)$r["record_id"]); ?></td>
                      <td class="strong"><?php echo htmlspecialchars((string)$r["name"]); ?></td>
                      <td><?php echo htmlspecialchars($typeLabel); ?></td>
                      <td><?php echo htmlspecialchars((string)($r["barangay"] ?? "")); ?></td>
                      <td><?php echo htmlspecialchars((string)($r["municipality"] ?? "")); ?></td>
                      <td><?php echo htmlspecialchars((string)($r["province"] ?? "")); ?></td>
                      <td class="mono">PHP <?php echo number_format((float)($r["amount"] ?? 0), 2); ?></td>
                      <td class="mono"><?php echo htmlspecialchars((string)($r["record_date"] ?? "")); ?></td>
                      <td class="mono"><?php echo htmlspecialchars((string)($r["month_year"] ?? "")); ?></td>
                      <td><?php echo htmlspecialchars($notesVal); ?></td>
                      <td>
                        <?php
                          $saReturnQuery = build_sa_query([
                            "records_type" => $saTypeFilter,
                            "records_barangay" => $saBarangayFilter,
                            "records_sort" => $saRecordsSort,
                          ]);
                          $saReturnTo = "super_admin.php" . ($saReturnQuery !== "" ? ("?" . $saReturnQuery) : "") . "#all-assistance-section";
                        ?>
                        <a class="btn btn--secondary btn--sm" href="edit.php?record_id=<?php echo urlencode((string)$r["record_id"]); ?>&return_to=<?php echo urlencode($saReturnTo); ?>">Edit</a>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="11" class="muted">No assistance records found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  </div>
</body>
</html>






