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

function format_office_scope_name(string $officeScope): string {
  $officeScope = normalize_office_scope_name(trim($officeScope));
  if ($officeScope === "maif") {
    return "MAIF";
  }
  if ($officeScope === "borabod") {
    return "Borabod";
  }
  return ($officeScope === "" ? "Municipality" : ucfirst($officeScope));
}

function build_line_chart_geometry(array $values, int $width = 640, int $height = 220, int $padX = 18, int $padY = 20, ?float $maxOverride = null): array {
  $count = count($values);
  if ($count === 0) {
    return [
      "polyline" => "",
      "points" => [],
      "max" => 0,
      "width" => $width,
      "height" => $height,
    ];
  }

  $maxValue = ($maxOverride !== null) ? (float)$maxOverride : 0.0;
  if ($maxOverride === null) {
    foreach ($values as $value) {
      $maxValue = max($maxValue, (float)$value);
    }
  }
  if ($maxValue <= 0) {
    $maxValue = 1.0;
  }

  $usableWidth = max(1, $width - ($padX * 2));
  $usableHeight = max(1, $height - ($padY * 2));
  $points = [];

  foreach ($values as $idx => $value) {
    $ratioX = ($count === 1) ? 0.5 : ($idx / ($count - 1));
    $ratioY = min(1.0, max(0.0, ((float)$value / $maxValue)));
    $x = $padX + ($usableWidth * $ratioX);
    $y = $height - $padY - ($usableHeight * $ratioY);
    $points[] = [
      "x" => round($x, 2),
      "y" => round($y, 2),
      "value" => (float)$value,
    ];
  }

  $polyline = implode(" ", array_map(function ($point) {
    return number_format((float)$point["x"], 2, ".", "") . "," . number_format((float)$point["y"], 2, ".", "");
  }, $points));

  return [
    "polyline" => $polyline,
    "points" => $points,
    "max" => $maxValue,
    "width" => $width,
    "height" => $height,
  ];
}

function daet_barangays(): array {
  return [
    "Barangay 1",
    "Barangay 2 (Pasig)",
    "Barangay 3 (Bagumbayan)",
    "Barangay 4 (Mantagbac)",
    "Barangay 5 (Pandan)",
    "Barangay 6 (Centro)",
    "Barangay 7 (Centro Oriental)",
    "Barangay 8 (Salcedo)",
    "Alawihao",
    "Awitan",
    "Bagasbas",
    "Bibirao",
    "Borabod",
    "Calasgasan",
    "Camambugan",
    "Cobangbang",
    "Dogongan",
    "Gahonon",
    "Gubat",
    "Lag-on",
    "Magang",
    "Mambalite",
    "Mancruz",
    "Pamorangon",
    "San Isidro",
  ];
}

function normalize_barangay_choice(string $raw, array $choices): string {
  $value = trim($raw);
  if ($value === "") {
    return "";
  }
  foreach ($choices as $choice) {
    if (strcasecmp($value, (string)$choice) === 0) {
      return (string)$choice;
    }
  }
  return "";
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
  if (!has_column($conn, "users", "barangay_scope")) {
    @$conn->query("ALTER TABLE users ADD COLUMN barangay_scope VARCHAR(191) NULL DEFAULT NULL");
  }
  if (!has_column($conn, "users", "office_scope")) {
    @$conn->query("ALTER TABLE users ADD COLUMN office_scope VARCHAR(32) NULL DEFAULT NULL");
  }

  // Backward compatibility for older custom roles.
  @$conn->query("UPDATE users SET role = 'admin', office_scope = 'borabod' WHERE LOWER(TRIM(role)) = 'borabod'");
  @$conn->query("UPDATE users SET role = 'admin', office_scope = 'municipality' WHERE LOWER(TRIM(role)) = 'municipality'");
  @$conn->query("UPDATE users SET office_scope = 'maif' WHERE LOWER(TRIM(role)) = 'maif' AND (office_scope IS NULL OR TRIM(office_scope) = '')");
  @$conn->query("UPDATE users SET office_scope = 'municipality' WHERE role <> 'super_admin' AND (office_scope IS NULL OR TRIM(office_scope) = '')");
}

ensure_users_admin_schema($conn);
$barangayChoices = daet_barangays();

$status = trim((string)($_GET["status"] ?? ""));
$msg = trim((string)($_GET["msg"] ?? ""));
$authUser = current_auth_user();
$tzLabel = app_timezone_label();
$reportPeriod = trim((string)($_GET["report_period"] ?? "all"));
$reportOfficeFilter = normalize_office_scope_name(trim((string)($_GET["report_office"] ?? "")));
$reportBarangayFilter = normalize_barangay_choice((string)($_GET["report_barangay"] ?? ""), $barangayChoices);
$reportYear = (int)($_GET["report_year"] ?? 0);
$allowedReportPeriods = ["all", "daily", "monthly", "yearly"];
if (!in_array($reportPeriod, $allowedReportPeriods, true)) {
  $reportPeriod = "all";
}
$allowedReportOffices = ["", "municipality", "maif", "borabod"];
if (!in_array($reportOfficeFilter, $allowedReportOffices, true)) {
  $reportOfficeFilter = "";
}

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
  $newBarangayScope = normalize_barangay_choice((string)($_POST["new_barangay_scope"] ?? ""), $barangayChoices);
  $newOfficeScope = normalize_office_scope_name((string)($_POST["new_office_scope"] ?? ""));

  if (!verify_csrf_token($token)) {
    redirect_super_admin("error", "Invalid request token.");
  }

  if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $newUsername)) {
    redirect_super_admin("error", "Username must be 3-64 chars and use letters, numbers, _, ., - only.");
  }
  if (strlen($newPassword) < 8) {
    redirect_super_admin("error", "Password must be at least 8 characters.");
  }

  $allowedRoles = ["admin", "user", "barangay", "maif", "super_admin"];
  if (!in_array($newRole, $allowedRoles, true)) {
    $newRole = "admin";
  }
  if ($newRole === "barangay" && $newBarangayScope === "") {
    redirect_super_admin("error", "Please select a barangay scope for barangay role.");
  }
  if ($newRole !== "barangay") {
    $newBarangayScope = "";
  }
  if ($newRole === "maif") {
    $newOfficeScope = "maif";
  } elseif ($newRole === "super_admin") {
    $newOfficeScope = "";
  } elseif ($newOfficeScope === "") {
    redirect_super_admin("error", "Please select an office for this account.");
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
  $insertStmt = $conn->prepare("INSERT INTO users (`user`, `password`, `role`, `barangay_scope`, `office_scope`, `created_at`) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())");
  if (!$insertStmt) {
    redirect_super_admin("error", "Database error: " . $conn->error);
  }
  $insertStmt->bind_param("sssss", $newUsername, $hash, $newRole, $newBarangayScope, $newOfficeScope);
  if (!$insertStmt->execute()) {
    $err = $insertStmt->error;
    $insertStmt->close();
    redirect_super_admin("error", "Failed creating account: " . $err);
  }
  $insertStmt->close();

  $scopeText = ($newRole === "barangay" && $newBarangayScope !== "") ? (" barangay: " . $newBarangayScope . ",") : "";
  $officeText = ($newOfficeScope !== "") ? (" office: " . ($newOfficeScope === "maif" ? "MAIF" : ucfirst($newOfficeScope))) : " office: all";
  audit_log("account_create", "Super admin created account \"" . $newUsername . "\" with role \"" . $newRole . "\" (" . trim($scopeText . $officeText) . ").", $authUser !== "" ? $authUser : null, null);
  redirect_super_admin("success", "Account created successfully.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && (($_POST["action"] ?? "") === "update_account")) {
  $token = $_POST["csrf_token"] ?? "";
  $originalUsername = trim((string)($_POST["original_username"] ?? ""));
  $editUsername = trim((string)($_POST["edit_username"] ?? ""));
  $editRole = trim((string)($_POST["edit_role"] ?? "admin"));
  $editBarangayScope = normalize_barangay_choice((string)($_POST["edit_barangay_scope"] ?? ""), $barangayChoices);
  $editOfficeScope = normalize_office_scope_name((string)($_POST["edit_office_scope"] ?? ""));
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

  $allowedRoles = ["admin", "user", "barangay", "maif", "super_admin"];
  if (!in_array($editRole, $allowedRoles, true)) {
    $editRole = "admin";
  }
  if ($editRole === "barangay" && $editBarangayScope === "") {
    redirect_super_admin("error", "Please select a barangay scope for barangay role.", $extra);
  }
  if ($editRole !== "barangay") {
    $editBarangayScope = "";
  }
  if ($editRole === "maif") {
    $editOfficeScope = "maif";
  } elseif ($editRole === "super_admin") {
    $editOfficeScope = "";
  } elseif ($editOfficeScope === "") {
    redirect_super_admin("error", "Please select an office for this account.", $extra);
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
      $upStmt = $conn->prepare("UPDATE users SET `user` = ?, `password` = ?, `role` = ?, `barangay_scope` = ?, `office_scope` = ? WHERE `user` = ? LIMIT 1");
      if (!$upStmt) {
        throw new RuntimeException("Database error: " . $conn->error);
      }
      $upStmt->bind_param("ssssss", $editUsername, $hash, $editRole, $editBarangayScope, $editOfficeScope, $originalUsername);
    } else {
      $upStmt = $conn->prepare("UPDATE users SET `user` = ?, `role` = ?, `barangay_scope` = ?, `office_scope` = ? WHERE `user` = ? LIMIT 1");
      if (!$upStmt) {
        throw new RuntimeException("Database error: " . $conn->error);
      }
      $upStmt->bind_param("sssss", $editUsername, $editRole, $editBarangayScope, $editOfficeScope, $originalUsername);
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
    $_SESSION["auth_office_scope"] = $editOfficeScope;
    $_SESSION["auth_office_scope_user"] = $editUsername;
    $_SESSION["auth_barangay_scope"] = $editBarangayScope;
    $_SESSION["auth_barangay_scope_user"] = $editUsername;
    $authUser = $editUsername;
  }

  $scopeText = ($editRole === "barangay" && $editBarangayScope !== "") ? (" barangay: " . $editBarangayScope . ",") : "";
  $officeText = ($editOfficeScope !== "") ? (" office: " . ($editOfficeScope === "maif" ? "MAIF" : ucfirst($editOfficeScope))) : " office: all";
  audit_log(
    "account_update",
    "Super admin updated account \"" . $originalUsername . "\" to username \"" . $editUsername . "\" with role \"" . $editRole . "\" (" . trim($scopeText . $officeText) . ").",
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
  $editStmt = $conn->prepare("SELECT `user` AS username, `role`, `barangay_scope`, `office_scope`, `created_at` FROM users WHERE `user` = ? LIMIT 1");
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

$maifTotalAmount = 0.0;
$maifAmountRs = @$conn->query(
  "SELECT COALESCE(SUM(amount), 0) AS total_amount
   FROM records
   WHERE COALESCE(NULLIF(LOWER(TRIM(office_scope)), ''), 'municipality') = 'maif'"
);
if ($maifAmountRs && $row = $maifAmountRs->fetch_assoc()) {
  $maifTotalAmount = (float)($row["total_amount"] ?? 0);
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

$reportMinYear = (int)date("Y");
$reportMaxYear = (int)date("Y");
$yearRangeRs = @$conn->query(
  "SELECT MIN(YEAR(record_date)) AS min_year, MAX(YEAR(record_date)) AS max_year
   FROM records
   WHERE record_date IS NOT NULL AND record_date <> ''"
);
if ($yearRangeRs && $yearRangeRow = $yearRangeRs->fetch_assoc()) {
  $minYearDb = (int)($yearRangeRow["min_year"] ?? 0);
  $maxYearDb = (int)($yearRangeRow["max_year"] ?? 0);
  if ($minYearDb > 0) $reportMinYear = $minYearDb;
  if ($maxYearDb > 0) $reportMaxYear = max($reportMinYear, $maxYearDb);
}
$reportYearChoices = [];
for ($y = $reportMinYear; $y <= $reportMaxYear; $y += 1) {
  $reportYearChoices[] = $y;
}
if ($reportYear <= 0) {
  $reportYear = $reportMaxYear;
}
if ($reportYear < $reportMinYear || $reportYear > $reportMaxYear) {
  $reportYear = $reportMaxYear;
}

$todayDate = date("Y-m-d");
$monthStartDate = date("Y-m-01");
$monthEndDate = date("Y-m-t");
$yearStartDate = date("Y-01-01");
$yearEndDate = date("Y-12-31");
$reportPeriodMeta = "Tracking statistics for all saved records.";
$reportGeneratedAt = format_utc_datetime_for_app(gmdate("Y-m-d H:i:s")) . " " . $tzLabel;
$reportStatsConditions = [];
$reportStatsParamTypes = "";
$reportStatsParams = [];
if ($reportPeriod === "daily") {
  $reportPeriodMeta = "Tracking statistics for " . date("F j, Y") . ".";
  $reportStatsConditions[] = "record_date = ?";
  $reportStatsParamTypes .= "s";
  $reportStatsParams[] = $todayDate;
} elseif ($reportPeriod === "monthly") {
  $reportPeriodMeta = "Tracking statistics for " . date("F Y") . ".";
  $reportStatsConditions[] = "record_date BETWEEN ? AND ?";
  $reportStatsParamTypes .= "ss";
  $reportStatsParams[] = $monthStartDate;
  $reportStatsParams[] = $monthEndDate;
} elseif ($reportPeriod === "yearly") {
  $reportPeriodMeta = "Tracking statistics for " . $reportYear . ".";
  $reportStatsConditions[] = "record_date BETWEEN ? AND ?";
  $reportStatsParamTypes .= "ss";
  $reportStatsParams[] = $reportYear . "-01-01";
  $reportStatsParams[] = $reportYear . "-12-31";
}
if ($reportOfficeFilter !== "") {
  $reportStatsConditions[] = "COALESCE(NULLIF(LOWER(TRIM(office_scope)), ''), 'municipality') = ?";
  $reportStatsParamTypes .= "s";
  $reportStatsParams[] = $reportOfficeFilter;
}
if ($reportBarangayFilter !== "") {
  $reportStatsConditions[] = "barangay = ?";
  $reportStatsParamTypes .= "s";
  $reportStatsParams[] = $reportBarangayFilter;
}
$reportMetaParts = [];
if ($reportOfficeFilter !== "") {
  $reportMetaParts[] = "Office: " . format_office_scope_name($reportOfficeFilter);
}
if ($reportBarangayFilter !== "") {
  $reportMetaParts[] = "Barangay: " . $reportBarangayFilter;
}
if (!empty($reportMetaParts)) {
  $reportPeriodMeta = rtrim($reportPeriodMeta, ".") . " Filtered by " . implode(" | ", $reportMetaParts) . ".";
}
$reportStatsWhereSql = !empty($reportStatsConditions)
  ? (" WHERE " . implode(" AND ", $reportStatsConditions))
  : "";

$reportTrackedRecords = 0;
$reportTrackedAmount = 0.0;
$reportAverageAmount = 0.0;
$reportSummarySql = "SELECT COUNT(*) AS total_records, COALESCE(SUM(amount), 0) AS total_amount FROM records" . $reportStatsWhereSql;
$reportSummaryStmt = $conn->prepare($reportSummarySql);
if ($reportSummaryStmt) {
  if (!empty($reportStatsParams)) {
    $reportSummaryStmt->bind_param($reportStatsParamTypes, ...$reportStatsParams);
  }
  $reportSummaryStmt->execute();
  $reportSummaryRs = $reportSummaryStmt->get_result();
  if ($reportSummaryRs && $row = $reportSummaryRs->fetch_assoc()) {
    $reportTrackedRecords = (int)($row["total_records"] ?? 0);
    $reportTrackedAmount = (float)($row["total_amount"] ?? 0.0);
    $reportAverageAmount = ($reportTrackedRecords > 0) ? ($reportTrackedAmount / $reportTrackedRecords) : 0.0;
  }
  $reportSummaryStmt->close();
}

$reportLeadingOffice = "-";
$reportLeadingOfficeCount = 0;
$reportLeadingOfficeSql =
  "SELECT COALESCE(NULLIF(LOWER(TRIM(office_scope)), ''), 'municipality') AS office_name, COUNT(*) AS total_records
   FROM records" .
  $reportStatsWhereSql .
  " GROUP BY COALESCE(NULLIF(LOWER(TRIM(office_scope)), ''), 'municipality')
    ORDER BY total_records DESC, office_name ASC
    LIMIT 1";
$reportLeadingOfficeStmt = $conn->prepare($reportLeadingOfficeSql);
if ($reportLeadingOfficeStmt) {
  if (!empty($reportStatsParams)) {
    $reportLeadingOfficeStmt->bind_param($reportStatsParamTypes, ...$reportStatsParams);
  }
  $reportLeadingOfficeStmt->execute();
  $reportLeadingOfficeRs = $reportLeadingOfficeStmt->get_result();
  if ($reportLeadingOfficeRs && $row = $reportLeadingOfficeRs->fetch_assoc()) {
    $reportLeadingOffice = format_office_scope_name((string)($row["office_name"] ?? ""));
    $reportLeadingOfficeCount = (int)($row["total_records"] ?? 0);
  }
  $reportLeadingOfficeStmt->close();
}

$reportTrendCaption = "No report data available yet.";
$reportTrendLabels = [];
$reportTrendBuckets = [];
$reportTrendSeries = [];
$reportTrendChartBase = build_line_chart_geometry([]);
$reportTrendPointLabels = [];
$reportTrendInteractiveData = ["labels" => [], "series" => []];
$reportTrendOfficeScopes = ($reportOfficeFilter !== "")
  ? [$reportOfficeFilter]
  : ["municipality", "maif", "borabod"];
$reportTrendPalette = [
  "municipality" => "#2563eb",
  "maif" => "#10b981",
  "borabod" => "#f97316",
];
$reportTrendSeriesMap = [];

$trendStart = null;
$trendEnd = null;
$trendBucketExpr = "";
if ($reportPeriod === "daily") {
  $trendStart = new DateTimeImmutable("-6 days");
  $trendEnd = new DateTimeImmutable("today");
  $trendBucketExpr = "record_date";
  $reportTrendCaption = "Last 7 days of encoded records.";
} elseif ($reportPeriod === "monthly") {
  $trendStart = new DateTimeImmutable(date("Y-m-01"));
  $trendEnd = new DateTimeImmutable(date("Y-m-t"));
  $trendBucketExpr = "record_date";
  $reportTrendCaption = "Daily record trend for " . date("F Y") . ".";
} elseif ($reportPeriod === "yearly") {
  $trendStart = new DateTimeImmutable($reportYear . "-01-01");
  $trendEnd = new DateTimeImmutable($reportYear . "-12-31");
  $trendBucketExpr = "DATE_FORMAT(record_date, '%Y-%m')";
  $reportTrendCaption = "Monthly record trend for " . $reportYear . ".";
} else {
  $trendStart = (new DateTimeImmutable("first day of this month"))->sub(new DateInterval("P11M"));
  $trendEnd = new DateTimeImmutable("last day of this month");
  $trendBucketExpr = "DATE_FORMAT(record_date, '%Y-%m')";
  $reportTrendCaption = "12-month record trend.";
}

$trendConditions = ["record_date BETWEEN ? AND ?"];
$trendParamTypes = "ss";
$trendParams = [$trendStart->format("Y-m-d"), $trendEnd->format("Y-m-d")];
if ($reportOfficeFilter !== "") {
  $trendConditions[] = "COALESCE(NULLIF(LOWER(TRIM(office_scope)), ''), 'municipality') = ?";
  $trendParamTypes .= "s";
  $trendParams[] = $reportOfficeFilter;
}
if ($reportBarangayFilter !== "") {
  $trendConditions[] = "barangay = ?";
  $trendParamTypes .= "s";
  $trendParams[] = $reportBarangayFilter;
}
$trendWhereSql = " WHERE " . implode(" AND ", $trendConditions);
$reportTrendSql =
  "SELECT " . $trendBucketExpr . " AS bucket,
          COALESCE(NULLIF(LOWER(TRIM(office_scope)), ''), 'municipality') AS office_name,
          COUNT(*) AS total_records
   FROM records" .
  $trendWhereSql .
  " GROUP BY bucket, COALESCE(NULLIF(LOWER(TRIM(office_scope)), ''), 'municipality')
    ORDER BY bucket ASC";
$reportTrendStmt = $conn->prepare($reportTrendSql);
if ($reportTrendStmt) {
  $reportTrendStmt->bind_param($trendParamTypes, ...$trendParams);
  $reportTrendStmt->execute();
  $reportTrendRs = $reportTrendStmt->get_result();
  if ($reportTrendRs) {
    while ($trendRow = $reportTrendRs->fetch_assoc()) {
      $bucket = (string)($trendRow["bucket"] ?? "");
      if ($bucket === "") continue;
      $officeScope = normalize_office_scope_name((string)($trendRow["office_name"] ?? ""));
      if ($officeScope === "") {
        $officeScope = "municipality";
      }
      if (!isset($reportTrendSeriesMap[$officeScope])) {
        $reportTrendSeriesMap[$officeScope] = [];
      }
      $reportTrendSeriesMap[$officeScope][$bucket] = (int)($trendRow["total_records"] ?? 0);
    }
  }
  $reportTrendStmt->close();
}

if ($reportPeriod === "daily" || $reportPeriod === "monthly") {
  $loopDate = $trendStart;
  while ($loopDate <= $trendEnd) {
    $bucket = $loopDate->format("Y-m-d");
    $reportTrendBuckets[] = $bucket;
    $reportTrendLabels[] = ($reportPeriod === "daily") ? $loopDate->format("M j, Y") : $loopDate->format("M j, Y");
    $loopDate = $loopDate->add(new DateInterval("P1D"));
  }
} else {
  $loopMonth = new DateTimeImmutable($trendStart->format("Y-m-01"));
  $endMonth = new DateTimeImmutable($trendEnd->format("Y-m-01"));
  while ($loopMonth <= $endMonth) {
    $bucket = $loopMonth->format("Y-m");
    $reportTrendBuckets[] = $bucket;
    $reportTrendLabels[] = ($reportPeriod === "yearly") ? $loopMonth->format("M Y") : $loopMonth->format("M Y");
    $loopMonth = $loopMonth->add(new DateInterval("P1M"));
  }
}

$reportTrendMaxValue = 0.0;
foreach ($reportTrendOfficeScopes as $officeScope) {
  $seriesValues = [];
  foreach ($reportTrendBuckets as $bucketKey) {
    $value = (int)($reportTrendSeriesMap[$officeScope][$bucketKey] ?? 0);
    $seriesValues[] = $value;
    $reportTrendMaxValue = max($reportTrendMaxValue, (float)$value);
  }
  $reportTrendSeries[] = [
    "scope" => $officeScope,
    "label" => format_office_scope_name($officeScope),
    "color" => (string)($reportTrendPalette[$officeScope] ?? "#2563eb"),
    "values" => $seriesValues,
  ];
}
if ($reportTrendMaxValue <= 0) {
  $reportTrendMaxValue = 1.0;
}
$reportTrendChartBase = build_line_chart_geometry(array_fill(0, max(1, count($reportTrendBuckets)), 0), 640, 220, 18, 20, $reportTrendMaxValue);
foreach ($reportTrendSeries as $idx => $series) {
  $reportTrendSeries[$idx]["chart"] = build_line_chart_geometry((array)$series["values"], 640, 220, 18, 20, $reportTrendMaxValue);
}
$trendLabelCount = count($reportTrendLabels);
if ($trendLabelCount > 0) {
  $reportTrendPointLabels[0] = $reportTrendLabels[0];
  $reportTrendPointLabels[$trendLabelCount - 1] = $reportTrendLabels[$trendLabelCount - 1];
  if ($trendLabelCount > 2) {
    $midIndex = (int)floor(($trendLabelCount - 1) / 2);
    $reportTrendPointLabels[$midIndex] = $reportTrendLabels[$midIndex];
  }
}
$reportTrendInteractiveData = [
  "labels" => $reportTrendLabels,
  "series" => array_map(function (array $series): array {
    return [
      "label" => (string)($series["label"] ?? ""),
      "color" => (string)($series["color"] ?? "#2563eb"),
      "values" => array_map("intval", (array)($series["values"] ?? [])),
    ];
  }, $reportTrendSeries),
];

$barangayStats = [];
$barangayRecordTotal = 0;
$barangayTotalsRs = @$conn->query(
  "SELECT TRIM(barangay) AS barangay_name, COUNT(*) AS total_records
   FROM records
   WHERE barangay IS NOT NULL AND TRIM(barangay) <> '' AND LOWER(TRIM(barangay)) NOT IN ('other','others')
   GROUP BY TRIM(barangay)
   ORDER BY total_records DESC, barangay_name ASC"
);
if ($barangayTotalsRs) {
  while ($row = $barangayTotalsRs->fetch_assoc()) {
    $barangayName = trim((string)($row["barangay_name"] ?? ""));
    $barangayCount = (int)($row["total_records"] ?? 0);
    if ($barangayName === "" || $barangayCount <= 0) continue;
    $barangayStats[] = [
      "name" => $barangayName,
      "count" => $barangayCount,
    ];
    $barangayRecordTotal += $barangayCount;
  }
}
if ($barangayRecordTotal > 0) {
  foreach ($barangayStats as $idx => $entry) {
    $barangayStats[$idx]["percentage"] = ((float)$entry["count"] / (float)$barangayRecordTotal) * 100.0;
  }
}
$topBarangay = (!empty($barangayStats)) ? $barangayStats[0] : null;

$chartPalette = [
  "#2563eb",
  "#0ea5e9",
  "#10b981",
  "#f59e0b",
  "#f97316",
  "#8b5cf6",
  "#ef4444",
  "#14b8a6",
  "#84cc16",
  "#ec4899",
];

$officeStats = [
  [
    "name" => "Municipality",
    "scope" => "municipality",
    "count" => 0,
  ],
  [
    "name" => "Borabod",
    "scope" => "borabod",
    "count" => 0,
  ],
  [
    "name" => "MAIF",
    "scope" => "maif",
    "count" => 0,
  ],
];
$officeCountMap = [];
$officeTotalsRs = @$conn->query(
  "SELECT COALESCE(NULLIF(TRIM(office_scope), ''), 'municipality') AS office_name, COUNT(*) AS total_records
   FROM records
   GROUP BY COALESCE(NULLIF(TRIM(office_scope), ''), 'municipality')
   ORDER BY office_name ASC"
);
if ($officeTotalsRs) {
  while ($row = $officeTotalsRs->fetch_assoc()) {
    $officeKey = normalize_office_scope_name((string)($row["office_name"] ?? ""));
    if ($officeKey === "") {
      $officeKey = "municipality";
    }
    $officeCountMap[$officeKey] = (int)($row["total_records"] ?? 0);
  }
}
$officeRecordTotal = 0;
foreach ($officeStats as $idx => $officeEntry) {
  $scope = (string)$officeEntry["scope"];
  $count = (int)($officeCountMap[$scope] ?? 0);
  $officeStats[$idx]["count"] = $count;
  $officeRecordTotal += $count;
}
if ($officeRecordTotal > 0) {
  foreach ($officeStats as $idx => $officeEntry) {
    $officeStats[$idx]["percentage"] = ((float)$officeEntry["count"] / (float)$officeRecordTotal) * 100.0;
  }
} else {
  foreach ($officeStats as $idx => $officeEntry) {
    $officeStats[$idx]["percentage"] = 0.0;
  }
}
$officeChartItems = $officeStats;
$officeGradientParts = [];
$officeCursor = 0.0;
foreach ($officeChartItems as $idx => $item) {
  $pct = (float)($item["percentage"] ?? 0.0);
  $color = $chartPalette[$idx % count($chartPalette)];
  $officeChartItems[$idx]["color"] = $color;
  if ($pct <= 0) {
    continue;
  }

  $start = $officeCursor;
  $end = min(100.0, $start + $pct);
  $officeGradientParts[] = $color . " " . number_format($start, 2, ".", "") . "% " . number_format($end, 2, ".", "") . "%";
  $officeCursor = $end;
}
if ($officeCursor < 100.0 && !empty($officeGradientParts)) {
  $officeGradientParts[] = "#dbeafe " . number_format($officeCursor, 2, ".", "") . "% 100%";
}
$officeDonutGradient = !empty($officeGradientParts)
  ? ("conic-gradient(" . implode(", ", $officeGradientParts) . ")")
  : "conic-gradient(#e2e8f0 0% 100%)";
$maifRecordCount = (int)($officeCountMap["maif"] ?? 0);
$maifAmountPercentage = ($totalAmount > 0)
  ? (($maifTotalAmount / $totalAmount) * 100.0)
  : 0.0;
$nonMaifTotalAmount = max(0.0, $totalAmount - $maifTotalAmount);
$maifAmountChartItems = [];
if ($totalAmount > 0) {
  $maifAmountChartItems[] = [
    "name" => "MAIF",
    "amount" => $maifTotalAmount,
    "percentage" => $maifAmountPercentage,
    "color" => "#10b981",
  ];
  $maifAmountChartItems[] = [
    "name" => "Other Offices",
    "amount" => $nonMaifTotalAmount,
    "percentage" => max(0.0, 100.0 - $maifAmountPercentage),
    "color" => "#2563eb",
  ];
}
$maifAmountGradientParts = [];
$maifAmountCursor = 0.0;
foreach ($maifAmountChartItems as $item) {
  $pct = (float)($item["percentage"] ?? 0.0);
  if ($pct <= 0) continue;

  $start = $maifAmountCursor;
  $end = min(100.0, $start + $pct);
  $maifAmountGradientParts[] = (string)$item["color"] . " " . number_format($start, 2, ".", "") . "% " . number_format($end, 2, ".", "") . "%";
  $maifAmountCursor = $end;
}
if ($maifAmountCursor < 100.0 && !empty($maifAmountGradientParts)) {
  $maifAmountGradientParts[] = "#dbeafe " . number_format($maifAmountCursor, 2, ".", "") . "% 100%";
}
$maifAmountDonutGradient = !empty($maifAmountGradientParts)
  ? ("conic-gradient(" . implode(", ", $maifAmountGradientParts) . ")")
  : "conic-gradient(#e2e8f0 0% 100%)";

$barangayAmountStats = [];
$amountTotalForPercentage = 0.0;
$barangayAmountRs = @$conn->query(
  "SELECT TRIM(barangay) AS barangay_name, COALESCE(SUM(amount), 0) AS total_amount
   FROM records
   WHERE barangay IS NOT NULL AND TRIM(barangay) <> '' AND LOWER(TRIM(barangay)) NOT IN ('other','others')
   GROUP BY TRIM(barangay)
   ORDER BY total_amount DESC, barangay_name ASC"
);
if ($barangayAmountRs) {
  while ($row = $barangayAmountRs->fetch_assoc()) {
    $barangayName = trim((string)($row["barangay_name"] ?? ""));
    $barangayAmount = (float)($row["total_amount"] ?? 0);
    if ($barangayName === "" || $barangayAmount <= 0) continue;
    $barangayAmountStats[] = [
      "name" => $barangayName,
      "amount" => $barangayAmount,
    ];
    $amountTotalForPercentage += $barangayAmount;
  }
}
if ($amountTotalForPercentage > 0) {
  foreach ($barangayAmountStats as $idx => $entry) {
    $barangayAmountStats[$idx]["percentage"] = ((float)$entry["amount"] / (float)$amountTotalForPercentage) * 100.0;
  }
}
$topAmountBarangay = (!empty($barangayAmountStats)) ? $barangayAmountStats[0] : null;

$amountBarangayChartItems = $barangayAmountStats;

$amountBarangayGradientParts = [];
$amountBarangayCursor = 0.0;
foreach ($amountBarangayChartItems as $idx => $item) {
  $pct = (float)($item["percentage"] ?? 0.0);
  $color = $chartPalette[$idx % count($chartPalette)];
  $amountBarangayChartItems[$idx]["color"] = $color;
  if ($pct <= 0) continue;

  $start = $amountBarangayCursor;
  $end = min(100.0, $start + $pct);
  $amountBarangayGradientParts[] = $color . " " . number_format($start, 2, ".", "") . "% " . number_format($end, 2, ".", "") . "%";
  $amountBarangayCursor = $end;
}
if ($amountBarangayCursor < 100.0 && !empty($amountBarangayGradientParts)) {
  $amountBarangayGradientParts[] = "#dbeafe " . number_format($amountBarangayCursor, 2, ".", "") . "% 100%";
}
$amountBarangayDonutGradient = !empty($amountBarangayGradientParts)
  ? ("conic-gradient(" . implode(", ", $amountBarangayGradientParts) . ")")
  : "conic-gradient(#e2e8f0 0% 100%)";
$barangayChartItems = $barangayStats;

$barangayGradientParts = [];
$barangayCursor = 0.0;
foreach ($barangayChartItems as $idx => $item) {
  $pct = (float)($item["percentage"] ?? 0.0);
  $color = $chartPalette[$idx % count($chartPalette)];
  $barangayChartItems[$idx]["color"] = $color;
  if ($pct <= 0) continue;

  $start = $barangayCursor;
  $end = min(100.0, $start + $pct);
  $barangayGradientParts[] = $color . " " . number_format($start, 2, ".", "") . "% " . number_format($end, 2, ".", "") . "%";
  $barangayCursor = $end;
}
if ($barangayCursor < 100.0 && !empty($barangayGradientParts)) {
  $barangayGradientParts[] = "#dbeafe " . number_format($barangayCursor, 2, ".", "") . "% 100%";
}
$barangayDonutGradient = !empty($barangayGradientParts)
  ? ("conic-gradient(" . implode(", ", $barangayGradientParts) . ")")
  : "conic-gradient(#e2e8f0 0% 100%)";



$accountsSql = "SELECT
                  u.`user` AS username,
                  u.role,
                  u.barangay_scope,
                  u.office_scope,
                  u.created_at,
                  CASE
                    WHEN s.username IS NOT NULL AND s.expires_at >= UTC_TIMESTAMP() THEN 'Active'
                    ELSE 'Inactive'
                  END AS current_status,
                  MAX(CASE WHEN l.action = 'login_success' THEN l.created_at ELSE NULL END) AS last_login_at
                FROM users u
                LEFT JOIN auth_active_sessions s ON s.username = u.`user`
                LEFT JOIN auth_audit_logs l ON l.username = u.`user`
                GROUP BY u.`user`, u.role, u.barangay_scope, u.office_scope, u.created_at, s.username, s.expires_at
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
$typeTotalForPercentage = 0;
foreach ($typeTotals as $countVal) {
  $typeTotalForPercentage += (int)$countVal;
}

$typePercentages = [];
$typeChartItems = [];
foreach ($orderedTypeLabels as $idx => $label) {
  $countVal = (int)($typeTotals[$label] ?? 0);
  $pct = ($typeTotalForPercentage > 0) ? (($countVal / $typeTotalForPercentage) * 100.0) : 0.0;
  $color = $chartPalette[$idx % count($chartPalette)];

  $typePercentages[$label] = $pct;
  $typeChartItems[] = [
    "name" => $label,
    "count" => $countVal,
    "percentage" => $pct,
    "color" => $color,
  ];
}
$topTypeItem = null;
foreach ($typeChartItems as $typeItem) {
  if ($topTypeItem === null || (float)($typeItem["percentage"] ?? 0.0) > (float)($topTypeItem["percentage"] ?? 0.0)) {
    $topTypeItem = $typeItem;
  }
}

$typeGradientParts = [];
$typeCursor = 0.0;
foreach ($typeChartItems as $item) {
  $pct = (float)($item["percentage"] ?? 0.0);
  if ($pct <= 0) continue;

  $start = $typeCursor;
  $end = min(100.0, $start + $pct);
  $typeGradientParts[] = (string)$item["color"] . " " . number_format($start, 2, ".", "") . "% " . number_format($end, 2, ".", "") . "%";
  $typeCursor = $end;
}
if ($typeCursor < 100.0 && !empty($typeGradientParts)) {
  $typeGradientParts[] = "#dbeafe " . number_format($typeCursor, 2, ".", "") . "% 100%";
}
$typeDonutGradient = !empty($typeGradientParts)
  ? ("conic-gradient(" . implode(", ", $typeGradientParts) . ")")
  : "conic-gradient(#e2e8f0 0% 100%)";


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
  "report_period" => $reportPeriod,
  "report_year" => $reportYear,
  "report_office" => $reportOfficeFilter,
  "report_barangay" => $reportBarangayFilter,
];

if (isset($_GET["report_tracking_live"]) && $_GET["report_tracking_live"] === "1") {
  $reportPointLabelIndexes = array_map("intval", array_keys($reportTrendPointLabels));
  sort($reportPointLabelIndexes);
  $reportSeriesPayload = array_map(function (array $series): array {
    $points = [];
    foreach ((array)($series["chart"]["points"] ?? []) as $point) {
      $points[] = [
        "x" => (float)($point["x"] ?? 0.0),
        "y" => (float)($point["y"] ?? 0.0),
      ];
    }
    return [
      "label" => (string)($series["label"] ?? ""),
      "color" => (string)($series["color"] ?? "#2563eb"),
      "polyline" => (string)($series["chart"]["polyline"] ?? ""),
      "points" => $points,
      "values" => array_map("intval", (array)($series["values"] ?? [])),
    ];
  }, $reportTrendSeries);

  header("Content-Type: application/json; charset=utf-8");
  echo json_encode([
    "ok" => true,
    "period_meta" => $reportPeriodMeta,
    "generated_at" => "Updated: " . $reportGeneratedAt,
    "tracked_records" => $reportTrackedRecords,
    "tracked_records_display" => number_format($reportTrackedRecords),
    "tracked_amount" => $reportTrackedAmount,
    "tracked_amount_display" => "PHP " . number_format($reportTrackedAmount, 2),
    "trend" => [
      "caption" => $reportTrendCaption,
      "chart" => [
        "width" => (int)($reportTrendChartBase["width"] ?? 640),
        "height" => (int)($reportTrendChartBase["height"] ?? 220),
      ],
      "labels" => $reportTrendLabels,
      "point_label_indexes" => $reportPointLabelIndexes,
      "series" => $reportSeriesPayload,
      "interactive" => $reportTrendInteractiveData,
    ],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
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

      var dashboardSwitchKey = 'startupSplashNextDashboard';

      document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) return;
        var link = target.closest('a[data-dashboard-switch="1"]');
        if (!link) return;
        try {
          window.sessionStorage.setItem(dashboardSwitchKey, '1');
        } catch (err) {}
      });

      function getNavigationType() {
        var navEntries = window.performance && window.performance.getEntriesByType
          ? window.performance.getEntriesByType('navigation')
          : [];
        if (navEntries && navEntries.length > 0 && navEntries[0].type) {
          return navEntries[0].type;
        }
        if (window.performance && window.performance.navigation) {
          return window.performance.navigation.type === 1 ? 'reload' : 'navigate';
        }
        return 'navigate';
      }

      var showByIntent = false;
      try {
        showByIntent = window.sessionStorage.getItem(dashboardSwitchKey) === '1';
        if (showByIntent) {
          window.sessionStorage.removeItem(dashboardSwitchKey);
        }
      } catch (err) {}

      var isReload = getNavigationType() === 'reload';
      var shouldShowSplash = isReload || showByIntent;

      if (!shouldShowSplash) {
        if (splash.parentNode) {
          splash.parentNode.removeChild(splash);
        }
        return;
      }

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
  <div class="app">
    <header class="app-header">
      <div class="brand-text">
        <h1>Super Admin Dashboard</h1>
        <p>Global system management and statistics</p>
      </div>
      <div class="header-meta">
        <div class="user-chip">Role <strong>Super Admin</strong></div>
        <a class="btn btn--secondary btn--sm" href="index.php" data-dashboard-switch="1">Dashboard</a>
        <a class="btn btn--secondary btn--sm" href="logs.php">Logs</a>
      </div>
    </header>

    <?php if ($msg !== ""): ?>
      <div class="alert <?php echo ($status === "error") ? "alert--error" : "alert--success"; ?>">
        <?php echo htmlspecialchars($msg); ?>
      </div>
    <?php endif; ?>

    <section class="card sa-global-stats">
      <div class="card__header">
        <h2 class="card__title">Global Statistics</h2>
        <p class="card__sub">Compact percentage view for barangay, office, and assistance type distribution.</p>
      </div>
      <div class="card__body sa-global-stats__body">
        <div class="sa-stats-layout">
          <div class="sa-stats-grid">
            <div class="sa-distribution-card">
              <div class="sa-distribution-card__head">
                <h3>Barangay Record Percentage</h3>
                <p><?php echo number_format($barangayRecordTotal); ?> record<?php echo ($barangayRecordTotal === 1 ? "" : "s"); ?> with barangay details.</p>
              </div>
              <?php if (!empty($barangayChartItems)): ?>
                <div class="sa-donut-layout js-donut-interactive">
                  <div class="sa-donut" style="--donut-fill: <?php echo htmlspecialchars($barangayDonutGradient, ENT_QUOTES); ?>;">
                    <div class="sa-donut__center js-donut-center">
                      <strong><?php echo number_format((int)$barangayRecordTotal); ?> record<?php echo ((int)$barangayRecordTotal === 1 ? "" : "s"); ?></strong>
                      <small class="js-donut-mid">100.0%</small>
                      <span class="js-donut-sub">All Barangays</span>
                    </div>
                  </div>
                  <ul class="sa-legend-list">
                    <?php foreach ($barangayChartItems as $item): ?>
                      <li class="sa-legend-item" data-donut-top="<?php echo htmlspecialchars(number_format((int)($item["count"] ?? 0)) . " record" . (((int)($item["count"] ?? 0) === 1) ? "" : "s"), ENT_QUOTES); ?>" data-donut-mid="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 1) . "%", ENT_QUOTES); ?>" data-donut-bottom="<?php echo htmlspecialchars((string)($item["name"] ?? ""), ENT_QUOTES); ?>" data-donut-pct="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 4, ".", ""), ENT_QUOTES); ?>">
                        <span class="sa-legend-swatch" style="--legend-color: <?php echo htmlspecialchars((string)($item["color"] ?? "#94a3b8"), ENT_QUOTES); ?>;"></span>
                        <span class="sa-legend-name"><?php echo htmlspecialchars((string)($item["name"] ?? "")); ?></span>
                        <span class="sa-legend-meta"><?php echo number_format((int)($item["count"] ?? 0)); ?> (<?php echo number_format((float)($item["percentage"] ?? 0.0), 1); ?>%)</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <p class="muted">No barangay statistics available yet.</p>
              <?php endif; ?>
            </div>

            <div class="sa-distribution-card">
              <div class="sa-distribution-card__head">
                <h3>Office Record Percentage</h3>
                <p><?php echo number_format($officeRecordTotal); ?> total office-scoped record<?php echo ($officeRecordTotal === 1 ? "" : "s"); ?>.</p>
              </div>
              <?php if ($officeRecordTotal > 0): ?>
                <div class="sa-donut-layout js-donut-interactive">
                  <div class="sa-donut" style="--donut-fill: <?php echo htmlspecialchars($officeDonutGradient, ENT_QUOTES); ?>;">
                    <div class="sa-donut__center js-donut-center">
                      <strong><?php echo number_format((int)$officeRecordTotal); ?> record<?php echo ((int)$officeRecordTotal === 1 ? "" : "s"); ?></strong>
                      <small class="js-donut-mid">100.0%</small>
                      <span class="js-donut-sub">All Offices</span>
                    </div>
                  </div>
                  <ul class="sa-legend-list">
                    <?php foreach ($officeChartItems as $item): ?>
                      <li class="sa-legend-item" data-donut-top="<?php echo htmlspecialchars(number_format((int)($item["count"] ?? 0)) . " record" . (((int)($item["count"] ?? 0) === 1) ? "" : "s"), ENT_QUOTES); ?>" data-donut-mid="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 1) . "%", ENT_QUOTES); ?>" data-donut-bottom="<?php echo htmlspecialchars((string)($item["name"] ?? ""), ENT_QUOTES); ?>" data-donut-pct="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 4, ".", ""), ENT_QUOTES); ?>">
                        <span class="sa-legend-swatch" style="--legend-color: <?php echo htmlspecialchars((string)($item["color"] ?? "#94a3b8"), ENT_QUOTES); ?>;"></span>
                        <span class="sa-legend-name"><?php echo htmlspecialchars((string)($item["name"] ?? "")); ?></span>
                        <span class="sa-legend-meta"><?php echo number_format((int)($item["count"] ?? 0)); ?> (<?php echo number_format((float)($item["percentage"] ?? 0.0), 1); ?>%)</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <p class="muted">No office statistics available yet.</p>
              <?php endif; ?>
            </div>

            <div class="sa-distribution-card">
              <div class="sa-distribution-card__head">
                <h3>Assistance Type Percentage</h3>
                <p><?php echo number_format($typeTotalForPercentage); ?> total type-tagged record<?php echo ($typeTotalForPercentage === 1 ? "" : "s"); ?>.</p>
              </div>
              <?php if (!empty($typeChartItems)): ?>
                <div class="sa-donut-layout js-donut-interactive">
                  <div class="sa-donut" style="--donut-fill: <?php echo htmlspecialchars($typeDonutGradient, ENT_QUOTES); ?>;">
                    <div class="sa-donut__center js-donut-center">
                      <strong><?php echo number_format((int)$typeTotalForPercentage); ?> record<?php echo ((int)$typeTotalForPercentage === 1 ? "" : "s"); ?></strong>
                      <small class="js-donut-mid">100.0%</small>
                      <span class="js-donut-sub">All Types</span>
                    </div>
                  </div>
                  <ul class="sa-legend-list">
                    <?php foreach ($typeChartItems as $item): ?>
                      <li class="sa-legend-item" data-donut-top="<?php echo htmlspecialchars(number_format((int)($item["count"] ?? 0)) . " record" . (((int)($item["count"] ?? 0) === 1) ? "" : "s"), ENT_QUOTES); ?>" data-donut-mid="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 1) . "%", ENT_QUOTES); ?>" data-donut-bottom="<?php echo htmlspecialchars((string)($item["name"] ?? ""), ENT_QUOTES); ?>" data-donut-pct="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 4, ".", ""), ENT_QUOTES); ?>">
                        <span class="sa-legend-swatch" style="--legend-color: <?php echo htmlspecialchars((string)($item["color"] ?? "#94a3b8"), ENT_QUOTES); ?>;"></span>
                        <span class="sa-legend-name"><?php echo htmlspecialchars((string)($item["name"] ?? "")); ?></span>
                        <span class="sa-legend-meta"><?php echo number_format((int)($item["count"] ?? 0)); ?> (<?php echo number_format((float)($item["percentage"] ?? 0.0), 1); ?>%)</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <p class="muted">No assistance type statistics available yet.</p>
              <?php endif; ?>
            </div>

            <section class="sa-summary-panel sa-summary-panel--accent">
              <div class="sa-summary-panel__label">Top Barangay</div>
              <?php if ($topBarangay): ?>
                <div class="sa-summary-panel__value"><?php echo htmlspecialchars((string)$topBarangay["name"]); ?></div>
                <div class="sa-summary-panel__meta">
                  <?php echo number_format((int)$topBarangay["count"]); ?> records
                  (<?php echo number_format((float)($topBarangay["percentage"] ?? 0.0), 1); ?>%)
                </div>
              <?php else: ?>
                <div class="sa-summary-panel__value">-</div>
                <div class="sa-summary-panel__meta">No barangay data yet</div>
              <?php endif; ?>
              <div class="sa-summary-panel__divider" aria-hidden="true"></div>
              <div class="sa-summary-panel__subhead">Leading Office</div>
              <div class="sa-summary-panel__subvalue"><?php echo htmlspecialchars($reportLeadingOffice); ?></div>
              <div class="sa-summary-panel__meta">
                <?php echo number_format($reportLeadingOfficeCount); ?> record<?php echo ($reportLeadingOfficeCount === 1 ? "" : "s"); ?>, average PHP <?php echo number_format($reportAverageAmount, 2); ?>
              </div>
            </section>

            <section class="sa-summary-panel sa-summary-panel--amount">
              <div class="sa-summary-panel__head">
                <h3>Total Amount</h3>
                <p>Barangay share by assistance amount.</p>
              </div>
              <div class="sa-summary-panel__value">PHP <?php echo number_format($totalAmount, 2); ?></div>
              <?php if (!empty($amountBarangayChartItems)): ?>
                <div class="sa-donut-layout sa-donut-layout--compact js-donut-interactive">
                  <div class="sa-donut" style="--donut-fill: <?php echo htmlspecialchars($amountBarangayDonutGradient, ENT_QUOTES); ?>;">
                    <div class="sa-donut__center js-donut-center">
                      <strong>PHP <?php echo number_format((float)$totalAmount, 2); ?></strong>
                      <small class="js-donut-mid">100.0%</small>
                      <span class="js-donut-sub sa-donut-sub">All Barangays</span>
                    </div>
                  </div>
                  <ul class="sa-legend-list sa-legend-list--compact">
                    <?php foreach ($amountBarangayChartItems as $item): ?>
                      <li class="sa-legend-item" data-donut-top="<?php echo htmlspecialchars("PHP " . number_format((float)($item["amount"] ?? 0.0), 2), ENT_QUOTES); ?>" data-donut-mid="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 1) . "%", ENT_QUOTES); ?>" data-donut-bottom="<?php echo htmlspecialchars((string)($item["name"] ?? ""), ENT_QUOTES); ?>" data-donut-pct="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 4, ".", ""), ENT_QUOTES); ?>">
                        <span class="sa-legend-swatch" style="--legend-color: <?php echo htmlspecialchars((string)($item["color"] ?? "#94a3b8"), ENT_QUOTES); ?>;"></span>
                        <span class="sa-legend-name"><?php echo htmlspecialchars((string)($item["name"] ?? "")); ?></span>
                        <span class="sa-legend-meta">PHP <?php echo number_format((float)($item["amount"] ?? 0.0), 2); ?> (<?php echo number_format((float)($item["percentage"] ?? 0.0), 1); ?>%)</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <p class="muted">No barangay amount statistics available yet.</p>
              <?php endif; ?>
            </section>

            <section class="sa-distribution-card">
              <div class="sa-distribution-card__head">
                <h3>MAIF Total Amount</h3>
                <p><?php echo number_format($maifRecordCount); ?> MAIF record<?php echo ($maifRecordCount === 1 ? "" : "s"); ?> contributing <?php echo number_format($maifAmountPercentage, 1); ?>% of all assistance amount.</p>
              </div>
              <?php if ($totalAmount > 0): ?>
                <div class="sa-donut-layout js-donut-interactive">
                  <div class="sa-donut" style="--donut-fill: <?php echo htmlspecialchars($maifAmountDonutGradient, ENT_QUOTES); ?>;">
                    <div class="sa-donut__center js-donut-center">
                      <strong>PHP <?php echo number_format((float)$maifTotalAmount, 2); ?></strong>
                      <small class="js-donut-mid"><?php echo number_format((float)$maifAmountPercentage, 1); ?>%</small>
                      <span class="js-donut-sub sa-donut-sub">MAIF Share</span>
                    </div>
                  </div>
                  <ul class="sa-legend-list">
                    <?php foreach ($maifAmountChartItems as $item): ?>
                      <li class="sa-legend-item" data-donut-top="<?php echo htmlspecialchars("PHP " . number_format((float)($item["amount"] ?? 0.0), 2), ENT_QUOTES); ?>" data-donut-mid="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 1) . "%", ENT_QUOTES); ?>" data-donut-bottom="<?php echo htmlspecialchars((string)($item["name"] ?? ""), ENT_QUOTES); ?>" data-donut-pct="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 4, ".", ""), ENT_QUOTES); ?>">
                        <span class="sa-legend-swatch" style="--legend-color: <?php echo htmlspecialchars((string)($item["color"] ?? "#94a3b8"), ENT_QUOTES); ?>;"></span>
                        <span class="sa-legend-name"><?php echo htmlspecialchars((string)($item["name"] ?? "")); ?></span>
                        <span class="sa-legend-meta">PHP <?php echo number_format((float)($item["amount"] ?? 0.0), 2); ?> (<?php echo number_format((float)($item["percentage"] ?? 0.0), 1); ?>%)</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <p class="muted">No MAIF amount statistics available yet.</p>
              <?php endif; ?>
            </section>

          </div>
          <div class="sa-stats-right">
            <section class="sa-summary-panel sa-summary-panel--tracking">
              <div class="sa-summary-panel__head">
                <h3>Report Tracking</h3>
                <p id="sa-report-period-meta"><?php echo htmlspecialchars($reportPeriodMeta); ?></p>
              </div>
              <form id="sa-report-filter-form" class="sa-report-filter sa-report-filter--panel" method="GET" action="super_admin.php">
                <input type="hidden" name="records_type" value="<?php echo htmlspecialchars($saTypeFilter); ?>" />
                <input type="hidden" name="records_barangay" value="<?php echo htmlspecialchars($saBarangayFilter); ?>" />
                <input type="hidden" name="records_sort" value="<?php echo htmlspecialchars($saRecordsSort); ?>" />
                <?php if ($editUser !== ""): ?>
                  <input type="hidden" name="edit_user" value="<?php echo htmlspecialchars($editUser); ?>" />
                <?php endif; ?>
                <div class="sa-report-filter__field">
                  <label for="report_period" class="sa-report-filter__label">Period</label>
                  <select id="report_period" name="report_period">
                  <option value="all" <?php echo $reportPeriod === "all" ? "selected" : ""; ?>>All Time</option>
                  <option value="daily" <?php echo $reportPeriod === "daily" ? "selected" : ""; ?>>Daily</option>
                  <option value="monthly" <?php echo $reportPeriod === "monthly" ? "selected" : ""; ?>>Monthly</option>
                  <option value="yearly" <?php echo $reportPeriod === "yearly" ? "selected" : ""; ?>>Yearly</option>
                </select>
                </div>
                <div class="sa-report-filter__field">
                  <label for="report_year" class="sa-report-filter__label">Year</label>
                  <select id="report_year" name="report_year">
                    <?php foreach ($reportYearChoices as $yearChoice): ?>
                      <option value="<?php echo (int)$yearChoice; ?>" <?php echo ((int)$reportYear === (int)$yearChoice) ? "selected" : ""; ?>><?php echo htmlspecialchars((string)$yearChoice); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="sa-report-filter__field">
                  <label for="report_office" class="sa-report-filter__label">Office</label>
                  <select id="report_office" name="report_office">
                  <option value="" <?php echo $reportOfficeFilter === "" ? "selected" : ""; ?>>All Offices</option>
                  <option value="municipality" <?php echo $reportOfficeFilter === "municipality" ? "selected" : ""; ?>>Municipality</option>
                  <option value="maif" <?php echo $reportOfficeFilter === "maif" ? "selected" : ""; ?>>MAIF</option>
                  <option value="borabod" <?php echo $reportOfficeFilter === "borabod" ? "selected" : ""; ?>>Borabod</option>
                </select>
                </div>
                <div class="sa-report-filter__field">
                  <label for="report_barangay" class="sa-report-filter__label">Barangay</label>
                  <select id="report_barangay" name="report_barangay">
                  <option value="" <?php echo $reportBarangayFilter === "" ? "selected" : ""; ?>>All Barangays</option>
                  <?php foreach ($barangayChoices as $barangayChoice): ?>
                    <option value="<?php echo htmlspecialchars($barangayChoice); ?>" <?php echo $reportBarangayFilter === $barangayChoice ? "selected" : ""; ?>><?php echo htmlspecialchars($barangayChoice); ?></option>
                  <?php endforeach; ?>
                </select>
                </div>
              </form>
              <div class="sa-report-stat-grid">
                <div class="sa-report-stat">
                  <span>Records</span>
                  <strong id="sa-report-tracked-records"><?php echo number_format($reportTrackedRecords); ?></strong>
                </div>
                <div class="sa-report-stat">
                  <span>Amount</span>
                  <strong id="sa-report-tracked-amount">PHP <?php echo number_format($reportTrackedAmount, 2); ?></strong>
                </div>
              </div>
              <div class="sa-report-line-card">
                <div class="sa-report-line-card__head">
                  <span>Trend Line</span>
                  <small id="sa-report-generated-at">Updated: <?php echo htmlspecialchars($reportGeneratedAt); ?></small>
                </div>
                <div class="sa-report-line-chart" id="sa-report-line-chart" tabindex="0" role="group" aria-label="<?php echo htmlspecialchars($reportTrendCaption . ". Use left and right arrow keys to inspect each point."); ?>">
                  <svg viewBox="0 0 <?php echo (int)$reportTrendChartBase["width"]; ?> <?php echo (int)$reportTrendChartBase["height"]; ?>" role="img" aria-label="<?php echo htmlspecialchars($reportTrendCaption); ?>">
                    <line x1="18" y1="<?php echo (int)$reportTrendChartBase["height"] - 20; ?>" x2="<?php echo (int)$reportTrendChartBase["width"] - 18; ?>" y2="<?php echo (int)$reportTrendChartBase["height"] - 20; ?>" class="sa-report-line-chart__axis"></line>
                    <?php foreach ($reportTrendSeries as $seriesIndex => $series): ?>
                      <polyline points="<?php echo htmlspecialchars((string)($series["chart"]["polyline"] ?? ""), ENT_QUOTES); ?>" class="sa-report-line-chart__line" style="--line-color: <?php echo htmlspecialchars((string)($series["color"] ?? "#2563eb"), ENT_QUOTES); ?>;"></polyline>
                      <?php foreach (($series["chart"]["points"] ?? []) as $pointIndex => $point): ?>
                        <circle
                          cx="<?php echo htmlspecialchars(number_format((float)$point["x"], 2, ".", ""), ENT_QUOTES); ?>"
                          cy="<?php echo htmlspecialchars(number_format((float)$point["y"], 2, ".", ""), ENT_QUOTES); ?>"
                          r="4"
                          class="sa-report-line-chart__dot"
                          data-series-index="<?php echo (int)$seriesIndex; ?>"
                          data-point-index="<?php echo (int)$pointIndex; ?>"
                          style="--dot-color: <?php echo htmlspecialchars((string)($series["color"] ?? "#2563eb"), ENT_QUOTES); ?>;"
                        ></circle>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </svg>
                </div>
                <div class="sa-report-line-labels" id="sa-report-line-labels" style="--label-count: <?php echo max(1, (int)count($reportTrendLabels)); ?>;">
                  <?php foreach ($reportTrendLabels as $idx => $trendLabel): ?>
                    <span data-point-index="<?php echo (int)$idx; ?>" class="<?php echo isset($reportTrendPointLabels[$idx]) ? "is-visible" : ""; ?>">
                      <?php echo isset($reportTrendPointLabels[$idx]) ? htmlspecialchars($trendLabel) : "&nbsp;"; ?>
                    </span>
                  <?php endforeach; ?>
                </div>
                <div class="sa-report-line-inspector" aria-live="polite">
                  <div class="sa-report-line-inspector__head">
                    <strong id="sa-report-line-inspector-label">-</strong>
                  </div>
                  <div class="sa-report-line-inspector__rows" id="sa-report-line-inspector-rows"></div>
                  <div class="sa-report-line-inspector__hint">Tip: hover a dot or use the left/right arrow keys.</div>
                </div>
                <div class="sa-report-line-legend" id="sa-report-line-legend">
                  <span class="sa-report-line-legend__title">Office Lines</span>
                  <?php foreach ($reportTrendSeries as $series): ?>
                    <div class="sa-report-line-legend__item">
                      <span class="sa-report-line-legend__swatch" style="--legend-line-color: <?php echo htmlspecialchars((string)($series["color"] ?? "#2563eb"), ENT_QUOTES); ?>;"></span>
                      <span class="sa-report-line-legend__name"><?php echo htmlspecialchars((string)($series["label"] ?? "")); ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
                <script type="application/json" id="sa-report-line-data"><?php echo json_encode($reportTrendInteractiveData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
              </div>
            </section>

            <section class="sa-summary-panel sa-summary-panel--accent">
              <div class="sa-summary-panel__label">Top Barangay</div>
              <?php if ($topBarangay): ?>
                <div class="sa-summary-panel__value"><?php echo htmlspecialchars((string)$topBarangay["name"]); ?></div>
                <div class="sa-summary-panel__meta">
                  <?php echo number_format((int)$topBarangay["count"]); ?> records
                  (<?php echo number_format((float)($topBarangay["percentage"] ?? 0.0), 1); ?>%)
                </div>
              <?php else: ?>
                <div class="sa-summary-panel__value">-</div>
                <div class="sa-summary-panel__meta">No barangay data yet</div>
              <?php endif; ?>
              <div class="sa-summary-panel__divider" aria-hidden="true"></div>
              <div class="sa-summary-panel__subhead">Leading Office</div>
              <div class="sa-summary-panel__subvalue"><?php echo htmlspecialchars($reportLeadingOffice); ?></div>
              <div class="sa-summary-panel__meta">
                <?php echo number_format($reportLeadingOfficeCount); ?> record<?php echo ($reportLeadingOfficeCount === 1 ? "" : "s"); ?>, average PHP <?php echo number_format($reportAverageAmount, 2); ?>
              </div>
            </section>

            <section class="sa-summary-panel sa-summary-panel--amount">
              <div class="sa-summary-panel__head">
                <h3>Total Amount</h3>
                <p>Barangay share by assistance amount.</p>
              </div>
              <div class="sa-summary-panel__value">PHP <?php echo number_format($totalAmount, 2); ?></div>
              <?php if (!empty($amountBarangayChartItems)): ?>
                <div class="sa-donut-layout sa-donut-layout--compact js-donut-interactive">
                  <div class="sa-donut" style="--donut-fill: <?php echo htmlspecialchars($amountBarangayDonutGradient, ENT_QUOTES); ?>;">
                    <div class="sa-donut__center js-donut-center">
                      <strong>PHP <?php echo number_format((float)$totalAmount, 2); ?></strong>
                      <small class="js-donut-mid">100.0%</small>
                      <span class="js-donut-sub">All Barangays</span>
                    </div>
                  </div>
                  <ul class="sa-legend-list sa-legend-list--compact">
                    <?php foreach ($amountBarangayChartItems as $item): ?>
                      <li class="sa-legend-item" data-donut-top="<?php echo htmlspecialchars("PHP " . number_format((float)($item["amount"] ?? 0.0), 2), ENT_QUOTES); ?>" data-donut-mid="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 1) . "%", ENT_QUOTES); ?>" data-donut-bottom="<?php echo htmlspecialchars((string)($item["name"] ?? ""), ENT_QUOTES); ?>" data-donut-pct="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 4, ".", ""), ENT_QUOTES); ?>">
                        <span class="sa-legend-swatch" style="--legend-color: <?php echo htmlspecialchars((string)($item["color"] ?? "#94a3b8"), ENT_QUOTES); ?>;"></span>
                        <span class="sa-legend-name"><?php echo htmlspecialchars((string)($item["name"] ?? "")); ?></span>
                        <span class="sa-legend-meta">PHP <?php echo number_format((float)($item["amount"] ?? 0.0), 2); ?> (<?php echo number_format((float)($item["percentage"] ?? 0.0), 1); ?>%)</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <p class="muted">No barangay amount statistics available yet.</p>
              <?php endif; ?>
            </section>

            <section class="sa-distribution-card">
              <div class="sa-distribution-card__head">
                <h3>MAIF Total Amount</h3>
                <p><?php echo number_format($maifRecordCount); ?> MAIF record<?php echo ($maifRecordCount === 1 ? "" : "s"); ?> contributing <?php echo number_format($maifAmountPercentage, 1); ?>% of all assistance amount.</p>
              </div>
              <?php if ($totalAmount > 0): ?>
                <div class="sa-donut-layout js-donut-interactive">
                  <div class="sa-donut" style="--donut-fill: <?php echo htmlspecialchars($maifAmountDonutGradient, ENT_QUOTES); ?>;">
                    <div class="sa-donut__center js-donut-center">
                      <strong>PHP <?php echo number_format((float)$maifTotalAmount, 2); ?></strong>
                      <small class="js-donut-mid"><?php echo number_format((float)$maifAmountPercentage, 1); ?>%</small>
                      <span class="js-donut-sub">MAIF Share</span>
                    </div>
                  </div>
                  <ul class="sa-legend-list">
                    <?php foreach ($maifAmountChartItems as $item): ?>
                      <li class="sa-legend-item" data-donut-top="<?php echo htmlspecialchars("PHP " . number_format((float)($item["amount"] ?? 0.0), 2), ENT_QUOTES); ?>" data-donut-mid="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 1) . "%", ENT_QUOTES); ?>" data-donut-bottom="<?php echo htmlspecialchars((string)($item["name"] ?? ""), ENT_QUOTES); ?>" data-donut-pct="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 4, ".", ""), ENT_QUOTES); ?>">
                        <span class="sa-legend-swatch" style="--legend-color: <?php echo htmlspecialchars((string)($item["color"] ?? "#94a3b8"), ENT_QUOTES); ?>;"></span>
                        <span class="sa-legend-name"><?php echo htmlspecialchars((string)($item["name"] ?? "")); ?></span>
                        <span class="sa-legend-meta">PHP <?php echo number_format((float)($item["amount"] ?? 0.0), 2); ?> (<?php echo number_format((float)($item["percentage"] ?? 0.0), 1); ?>%)</span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <p class="muted">No MAIF amount statistics available yet.</p>
              <?php endif; ?>
            </section>

            <section class="sa-summary-panel sa-summary-panel--accounts">
              <div class="sa-summary-panel__head">
                <h3>Accounts</h3>
                <p>Account records and currently active users.</p>
              </div>
              <div class="sa-account-metrics">
                <div class="sa-account-metric">
                  <span class="sa-account-metric__label">Account Records</span>
                  <strong class="sa-account-metric__value"><?php echo number_format($totalAccounts); ?></strong>
                </div>
                <div class="sa-account-metric">
                  <span class="sa-account-metric__label">Active Accounts</span>
                  <strong class="sa-account-metric__value"><?php echo number_format($totalActiveUsers); ?></strong>
                </div>
              </div>
            </section>

            <section class="sa-summary-panel">
              <div class="sa-summary-panel__label">Audit Logs</div>
              <div class="sa-summary-panel__value"><?php echo number_format($totalLogs); ?></div>
            </section>
          </div>
</div>
      </div>
    </section>

    <section id="accounts-section" class="card section accounts-hub">
      <div class="card__header card__header--row accounts-hub__header">
        <div>
          <h2 class="card__title">Account Center</h2>
          <p class="card__sub">Create new accounts and manage all record accounts in one place.</p>
        </div>
        <div class="accounts-hub__tools">
          <div class="accounts-hub__badges">
            <span class="accounts-hub__badge"><strong><?php echo number_format($totalAccounts); ?></strong> Total</span>
            <span class="accounts-hub__badge accounts-hub__badge--active"><strong><?php echo number_format($totalActiveUsers); ?></strong> Active</span>
          </div>
          <button type="button" id="open-create-account-modal" class="btn btn--sm">Create Account</button>
        </div>
      </div>
      <div class="card__body accounts-hub__body">
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
                        $roleText = trim((string)($u["role"] ?? ""));
                        $roleScope = trim((string)($u["barangay_scope"] ?? ""));
                        $officeScope = normalize_office_scope_name((string)($u["office_scope"] ?? ""));
                        $officeText = ($officeScope !== "") ? ($officeScope === "maif" ? "MAIF" : ucfirst($officeScope)) : "All";
                        if ($roleText === "maif") {
                          $roleText = "MAIF";
                        }
                        if ($roleText === "barangay" && $roleScope !== "") {
                          $roleText .= " (" . $roleScope . ")";
                        }
                        $roleText .= " @ " . $officeText;
                      ?>
                      <tr>
                        <td class="strong"><?php echo htmlspecialchars((string)$u["username"]); ?></td>
                        <td class="mono"><?php echo htmlspecialchars($roleText); ?></td>
                        <td><span class="status-pill <?php echo $isActive ? "status-pill--active" : "status-pill--inactive"; ?>"><?php echo $isActive ? "Active" : "Inactive"; ?></span></td>
                        <td class="mono"><?php echo htmlspecialchars($createdAt !== "" ? $createdAt : "-"); ?></td>
                        <td class="mono"><?php echo htmlspecialchars($lastLogin !== "" ? $lastLogin : "-"); ?></td>
                        <td>
                          <div class="account-action-group">
                            <a class="btn btn--secondary btn--sm" href="super_admin.php?edit_user=<?php echo urlencode((string)$u["username"]); ?>#accounts-section">Edit</a>
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

    <div id="accounts-create-modal" class="accounts-create-modal hidden" aria-hidden="true">
      <div class="accounts-create-modal__panel" role="dialog" aria-modal="true" aria-labelledby="accounts-create-title">
        <button type="button" class="accounts-create-modal__close" id="accounts-create-close" aria-label="Close create account panel">&times;</button>
        <div class="accounts-create-modal__head">
          <h3 id="accounts-create-title">Create Account</h3>
          <p>Quickly add a new account with role, office, and optional barangay scope.</p>
        </div>
        <form class="accounts-create-form" method="POST" action="super_admin.php#accounts-section" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
          <input type="hidden" name="action" value="create_account" />
          <div class="form-grid accounts-create-grid">
            <div class="field">
              <label for="new_username">Username</label>
              <input id="new_username" name="new_username" type="text" required placeholder="Enter username" />
            </div>
            <div class="field">
              <label for="new_password">Password</label>
              <input id="new_password" name="new_password" type="password" required placeholder="Enter secure password" />
            </div>
            <div class="field accounts-create-role">
              <label for="new_role">Role</label>
              <select id="new_role" name="new_role">
                <option value="admin">admin</option>
                <option value="user">user</option>
                <option value="barangay">barangay</option>
                <option value="maif">MAIF</option>
                <option value="super_admin">super_admin</option>
              </select>
            </div>
            <div class="field" id="newOfficeScopeWrap">
              <label for="new_office_scope">Office</label>
              <select id="new_office_scope" name="new_office_scope">
                <option value="municipality" selected>Municipality</option>
                <option value="borabod">Borabod</option>
                <option value="maif">MAIF</option>
              </select>
            </div>
            <div class="field field--full hidden" id="newBarangayScopeWrap">
              <label for="new_barangay_scope">Barangay Scope</label>
              <select id="new_barangay_scope" name="new_barangay_scope">
                <option value="" selected>Select barangay</option>
                <?php foreach ($barangayChoices as $bChoice): ?>
                  <option value="<?php echo htmlspecialchars($bChoice); ?>"><?php echo htmlspecialchars($bChoice); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="actions accounts-create-actions">
            <span class="accounts-create-actions__note">Use strong passwords for higher-privilege roles.</span>
            <button class="btn" type="submit">Create Account</button>
          </div>
        </form>
      </div>
    </div>

    <?php if ($editAccount): ?>
      <?php
        $editCreatedAt = format_utc_datetime_for_app((string)($editAccount["created_at"] ?? ""));
        $editRoleCurrent = (string)($editAccount["role"] ?? "admin");
        $editUsernameCurrent = (string)($editAccount["username"] ?? "");
        $editBarangayScopeCurrent = normalize_barangay_choice((string)($editAccount["barangay_scope"] ?? ""), $barangayChoices);
        $editOfficeScopeCurrent = normalize_office_scope_name((string)($editAccount["office_scope"] ?? ""));
        if (strtolower(trim($editRoleCurrent)) === "borabod") {
          $editRoleCurrent = "admin";
          if ($editOfficeScopeCurrent === "") $editOfficeScopeCurrent = "borabod";
        } elseif (strtolower(trim($editRoleCurrent)) === "municipality") {
          $editRoleCurrent = "admin";
          if ($editOfficeScopeCurrent === "") $editOfficeScopeCurrent = "municipality";
        } elseif (strtolower(trim($editRoleCurrent)) === "maif") {
          if ($editOfficeScopeCurrent === "") $editOfficeScopeCurrent = "maif";
        }
        if ($editOfficeScopeCurrent === "" && $editRoleCurrent !== "super_admin") {
          $editOfficeScopeCurrent = "municipality";
        }
      ?>
      <div id="accounts-edit-modal" class="accounts-edit-modal" aria-hidden="false">
        <div class="accounts-edit-modal__panel" role="dialog" aria-modal="true" aria-labelledby="accounts-edit-title">
          <a class="accounts-edit-modal__close" href="super_admin.php#accounts-section" aria-label="Close edit account panel">&times;</a>
          <div class="accounts-edit-modal__head">
            <h3 id="accounts-edit-title">Edit Account</h3>
            <p>Update role access, office scope, and password for <strong><?php echo htmlspecialchars($editUsernameCurrent); ?></strong>.</p>
          </div>
          <form method="POST" action="super_admin.php#accounts-section" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
            <input type="hidden" name="action" value="update_account" />
            <input type="hidden" name="original_username" value="<?php echo htmlspecialchars($editUsernameCurrent); ?>" />
            <div class="form-grid accounts-edit-grid">
              <div class="field">
                <label for="edit_username">Username</label>
                <input id="edit_username" name="edit_username" type="text" required value="<?php echo htmlspecialchars($editUsernameCurrent); ?>" />
              </div>
              <div class="field">
                <label for="edit_role">Role</label>
                <select id="edit_role" name="edit_role">
                  <option value="admin" <?php echo $editRoleCurrent === "admin" ? "selected" : ""; ?>>admin</option>
                  <option value="user" <?php echo $editRoleCurrent === "user" ? "selected" : ""; ?>>user</option>
                  <option value="barangay" <?php echo $editRoleCurrent === "barangay" ? "selected" : ""; ?>>barangay</option>
                  <option value="maif" <?php echo $editRoleCurrent === "maif" ? "selected" : ""; ?>>MAIF</option>
                  <option value="super_admin" <?php echo $editRoleCurrent === "super_admin" ? "selected" : ""; ?>>super_admin</option>
                </select>
              </div>
              <div class="field" id="editOfficeScopeWrap">
                <label for="edit_office_scope">Office</label>
                <select id="edit_office_scope" name="edit_office_scope">
                  <option value="municipality" <?php echo $editOfficeScopeCurrent === "municipality" ? "selected" : ""; ?>>Municipality</option>
                  <option value="borabod" <?php echo $editOfficeScopeCurrent === "borabod" ? "selected" : ""; ?>>Borabod</option>
                  <option value="maif" <?php echo $editOfficeScopeCurrent === "maif" ? "selected" : ""; ?>>MAIF</option>
                </select>
              </div>
              <div class="field field--full <?php echo $editRoleCurrent === "barangay" ? "" : "hidden"; ?>" id="editBarangayScopeWrap">
                <label for="edit_barangay_scope">Barangay Scope</label>
                <select id="edit_barangay_scope" name="edit_barangay_scope">
                  <option value="" <?php echo $editBarangayScopeCurrent === "" ? "selected" : ""; ?>>Select barangay</option>
                  <?php foreach ($barangayChoices as $bChoice): ?>
                    <option value="<?php echo htmlspecialchars($bChoice); ?>" <?php echo $editBarangayScopeCurrent === $bChoice ? "selected" : ""; ?>>
                      <?php echo htmlspecialchars($bChoice); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field field--full">
                <label for="edit_password">New Password (optional)</label>
                <input id="edit_password" name="edit_password" type="password" placeholder="Leave blank to keep current password" />
              </div>
              <div class="field field--full">
                <label>Created</label>
                <input class="readonly" type="text" value="<?php echo htmlspecialchars($editCreatedAt !== "" ? $editCreatedAt : "-"); ?> <?php echo htmlspecialchars($tzLabel); ?>" readonly />
              </div>
            </div>
            <div class="actions accounts-edit-actions">
              <a class="btn btn--secondary" href="super_admin.php#accounts-section">Cancel</a>
              <button class="btn" type="submit">Save Account Changes</button>
            </div>
          </form>
        </div>
      </div>
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

    <section id="assistance-type-section" class="card section">
      <div class="card__header">
        <h2 class="card__title">Statistics by Assistance Type</h2>
        <p class="card__sub">All assistance categories and counts.</p>
      </div>
      <div class="card__body">
        <div class="type-grid">
          <?php foreach ($orderedTypeLabels as $label): ?>
            <div class="type-stat">
              <div class="type-name"><?php echo htmlspecialchars($label); ?></div>
              <div class="type-count"><?php echo number_format((float)($typeTotals[$label] ?? 0)); ?> records (<?php echo number_format((float)($typePercentages[$label] ?? 0.0), 1); ?>%)</div>
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

        <div id="sa-records-table-wrap" class="table-wrap">
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
              <tbody id="sa-records-tbody">
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
      <script>
    (function(){
      var tbody = document.getElementById('sa-records-tbody');
      var tableWrap = document.getElementById('sa-records-table-wrap');
      if (!tbody || !tableWrap) return;

      var controller = null;
      var requestId = 0;
      var refreshTimer = null;
      var lastRenderKey = '';

      function escapeHtml(value){
        return String(value == null ? '' : value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function getRecordsQuery(){
        var urlQuery = new URLSearchParams(window.location.search || '');
        var query = new URLSearchParams();
        var type = urlQuery.get('records_type') || '';
        var barangay = urlQuery.get('records_barangay') || '';
        var sort = urlQuery.get('records_sort') || 'new';

        if (type !== '') query.set('records_type', type);
        if (barangay !== '') query.set('records_barangay', barangay);
        query.set('records_sort', sort);
        return query;
      }

      function buildReturnTo(){
        var query = getRecordsQuery().toString();
        return 'super_admin.php' + (query ? ('?' + query) : '') + '#all-assistance-section';
      }

      function renderRows(items){
        if (!Array.isArray(items) || items.length === 0) {
          tbody.innerHTML = '<tr><td colspan="11" class="muted">No assistance records found.</td></tr>';
          return;
        }

        var returnTo = buildReturnTo();
        var rowsHtml = items.map(function(row){
          var editUrl = 'edit.php?record_id=' + encodeURIComponent(row.record_id || '') +
            '&return_to=' + encodeURIComponent(returnTo);

          return '<tr>' +
            '<td class="mono">' + escapeHtml(row.record_id) + '</td>' +
            '<td class="strong">' + escapeHtml(row.name) + '</td>' +
            '<td>' + escapeHtml(row.type_label) + '</td>' +
            '<td>' + escapeHtml(row.barangay) + '</td>' +
            '<td>' + escapeHtml(row.municipality) + '</td>' +
            '<td>' + escapeHtml(row.province) + '</td>' +
            '<td class="mono">' + escapeHtml(row.amount_display) + '</td>' +
            '<td class="mono">' + escapeHtml(row.record_date) + '</td>' +
            '<td class="mono">' + escapeHtml(row.month_year) + '</td>' +
            '<td>' + escapeHtml(row.notes) + '</td>' +
            '<td><a class="btn btn--secondary btn--sm" href="' + editUrl + '">Edit</a></td>' +
          '</tr>';
        }).join('');

        tbody.innerHTML = rowsHtml;
      }

      async function refreshRecords(showLoading){
        var currentRequest = ++requestId;
        if (controller) controller.abort();
        controller = new AbortController();

        if (showLoading) tableWrap.classList.add('is-loading');

        try {
          var params = getRecordsQuery();
          params.set('_ts', String(Date.now()));

          var response = await fetch('super_admin_live_records.php?' + params.toString(), {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
            signal: controller.signal
          });

          if (!response.ok) {
            throw new Error('Live records request failed with status ' + response.status);
          }

          var data = await response.json();
          if (currentRequest !== requestId) return;
          if (!data || data.ok !== true || !Array.isArray(data.items)) {
            throw new Error('Invalid live records response');
          }

          var renderKey = JSON.stringify(data.items);
          if (renderKey !== lastRenderKey) {
            lastRenderKey = renderKey;
            renderRows(data.items);
          }
        } catch (err) {
          if (err && err.name === 'AbortError') return;
          console.error(err);
        } finally {
          if (currentRequest === requestId && showLoading) {
            tableWrap.classList.remove('is-loading');
          }
        }
      }

      function startAutoRefresh(){
        if (refreshTimer) clearInterval(refreshTimer);
        refreshTimer = setInterval(function(){
          if (document.hidden) return;
          refreshRecords(false);
        }, 4000);
      }

      document.addEventListener('visibilitychange', function(){
        if (!document.hidden) refreshRecords(false);
      });

      startAutoRefresh();
      refreshRecords(false);
    })();
  </script>
  <script>
    (function(){
      var openCreateBtn = document.getElementById('open-create-account-modal');
      var createModal = document.getElementById('accounts-create-modal');
      var createCloseBtn = document.getElementById('accounts-create-close');
      if (!openCreateBtn || !createModal || !createCloseBtn) return;

      function openCreateModal() {
        createModal.classList.remove('hidden');
        createModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
      }

      function closeCreateModal() {
        createModal.classList.add('hidden');
        createModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
      }

      openCreateBtn.addEventListener('click', openCreateModal);
      createCloseBtn.addEventListener('click', closeCreateModal);

      createModal.addEventListener('click', function(e){
        if (e.target === createModal) {
          closeCreateModal();
        }
      });

      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
          if (!createModal.classList.contains('hidden')) {
            closeCreateModal();
          }
        }
      });
    })();
  </script>
  <script>
    (function(){
      var editModal = document.getElementById('accounts-edit-modal');
      if (!editModal) return;

      document.body.classList.add('modal-open');

      function closeEditModal() {
        window.location.href = 'super_admin.php#accounts-section';
      }

      editModal.addEventListener('click', function(e){
        if (e.target === editModal) {
          closeEditModal();
        }
      });

      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
          closeEditModal();
        }
      });
    })();
  </script>
  <script>
    (function(){
      function bindBarangayScope(roleSelectId, wrapId, scopeSelectId) {
        var roleSelect = document.getElementById(roleSelectId);
        var wrap = document.getElementById(wrapId);
        var scopeSelect = document.getElementById(scopeSelectId);
        if (!roleSelect || !wrap || !scopeSelect) return;

        function sync() {
          var isBarangayRole = roleSelect.value === 'barangay';
          wrap.classList.toggle('hidden', !isBarangayRole);
          scopeSelect.required = isBarangayRole;
        }

        roleSelect.addEventListener('change', sync);
        sync();
      }

      function bindOfficeScope(roleSelectId, wrapId, scopeSelectId) {
        var roleSelect = document.getElementById(roleSelectId);
        var wrap = document.getElementById(wrapId);
        var scopeSelect = document.getElementById(scopeSelectId);
        if (!roleSelect || !wrap || !scopeSelect) return;

        function sync() {
          var needsOffice = roleSelect.value !== 'super_admin';
          var forceMaif = roleSelect.value === 'maif';
          wrap.classList.toggle('hidden', !needsOffice);
          scopeSelect.required = needsOffice;
          if (forceMaif) {
            scopeSelect.value = 'maif';
          }
        }

        roleSelect.addEventListener('change', sync);
        sync();
      }

      bindBarangayScope('new_role', 'newBarangayScopeWrap', 'new_barangay_scope');
      bindBarangayScope('edit_role', 'editBarangayScopeWrap', 'edit_barangay_scope');
      bindOfficeScope('new_role', 'newOfficeScopeWrap', 'new_office_scope');
      bindOfficeScope('edit_role', 'editOfficeScopeWrap', 'edit_office_scope');
    })();
  </script>
  <script>
    (function () {
      var groups = document.querySelectorAll('.js-donut-interactive');
      if (!groups.length) return;

      groups.forEach(function (group) {
        var donut = group.querySelector('.sa-donut');
        var center = group.querySelector('.js-donut-center');
        if (!donut || !center) return;

        var topEl = center.querySelector('strong');
        var midEl = center.querySelector('.js-donut-mid');
        var bottomEl = center.querySelector('.js-donut-sub') || center.querySelector('.sa-donut-sub');
        var items = Array.prototype.slice.call(group.querySelectorAll('.sa-legend-item[data-donut-top][data-donut-mid][data-donut-bottom][data-donut-pct]'));
        if (!topEl || !midEl || !bottomEl || !items.length) return;

        var defaultTop = (topEl.textContent || '').trim();
        var defaultMid = (midEl.textContent || '').trim();
        var defaultBottom = (bottomEl.textContent || '').trim();
        var lockedIndex = -1;
        var currentIndex = -1;

        var ranges = [];
        var cursor = 0;
        items.forEach(function (item, index) {
          var pct = parseFloat(item.getAttribute('data-donut-pct') || '0');
          if (!isFinite(pct) || pct <= 0) {
            ranges.push({ index: index, start: cursor, end: cursor });
            return;
          }
          var end = Math.min(100, cursor + pct);
          ranges.push({ index: index, start: cursor, end: end });
          cursor = end;
        });

        function fitCenterText() {
          var baseTop = parseFloat(center.dataset.baseTop || '');
          var baseMid = parseFloat(center.dataset.baseMid || '');
          var baseBottom = parseFloat(center.dataset.baseBottom || '');

          if (!isFinite(baseTop)) {
            baseTop = parseFloat(window.getComputedStyle(topEl).fontSize) || 22;
            center.dataset.baseTop = String(baseTop);
          }
          if (!isFinite(baseMid)) {
            baseMid = parseFloat(window.getComputedStyle(midEl).fontSize) || 12;
            center.dataset.baseMid = String(baseMid);
          }
          if (!isFinite(baseBottom)) {
            baseBottom = parseFloat(window.getComputedStyle(bottomEl).fontSize) || 10;
            center.dataset.baseBottom = String(baseBottom);
          }

          topEl.style.fontSize = baseTop + 'px';
          midEl.style.fontSize = baseMid + 'px';
          bottomEl.style.fontSize = baseBottom + 'px';

          var maxWidth = Math.max(70, center.clientWidth - 6);
          var maxHeight = Math.max(62, center.clientHeight - 6);
          var guard = 0;

          while (guard < 32 && (
            topEl.scrollWidth > maxWidth ||
            midEl.scrollWidth > maxWidth ||
            bottomEl.scrollWidth > maxWidth ||
            center.scrollHeight > maxHeight
          )) {
            var topSize = parseFloat(topEl.style.fontSize);
            var midSize = parseFloat(midEl.style.fontSize);
            var bottomSize = parseFloat(bottomEl.style.fontSize);

            if (isFinite(topSize) && topSize > 12) topEl.style.fontSize = (topSize - 0.6) + 'px';
            if (isFinite(midSize) && midSize > 9) midEl.style.fontSize = (midSize - 0.35) + 'px';
            if (isFinite(bottomSize) && bottomSize > 8) bottomEl.style.fontSize = (bottomSize - 0.3) + 'px';

            guard += 1;
          }
        }

        function setCenter(top, mid, bottom) {
          var nextTop = top || defaultTop;
          var nextMid = mid || defaultMid;
          var nextBottom = bottom || defaultBottom;
          if (topEl.textContent === nextTop && midEl.textContent === nextMid && bottomEl.textContent === nextBottom) return;
          topEl.textContent = nextTop;
          midEl.textContent = nextMid;
          bottomEl.textContent = nextBottom;
          fitCenterText();
        }

        function setActive(index) {
          items.forEach(function (item, i) {
            item.classList.toggle('is-active', i === index);
          });
        }

        function preview(index) {
          var item = items[index];
          if (!item) return;
          currentIndex = index;
          setCenter(
            item.getAttribute('data-donut-top') || defaultTop,
            item.getAttribute('data-donut-mid') || defaultMid,
            item.getAttribute('data-donut-bottom') || defaultBottom
          );
          setActive(index);
        }

        function resetToDefault() {
          currentIndex = -1;
          setCenter(defaultTop, defaultMid, defaultBottom);
          setActive(-1);
        }

        function setLockedIndex(index) {
          if (lockedIndex === index) {
            lockedIndex = -1;
            resetToDefault();
            return;
          }
          lockedIndex = index;
          preview(index);
        }

        function indexFromPointer(event) {
          var rect = donut.getBoundingClientRect();
          var cx = rect.left + (rect.width / 2);
          var cy = rect.top + (rect.height / 2);
          var dx = event.clientX - cx;
          var dy = event.clientY - cy;
          var radius = Math.sqrt((dx * dx) + (dy * dy));
          var outer = rect.width / 2;
          var inner = outer * 0.74;

          if (radius < inner || radius > outer) return -1;

          var deg = (Math.atan2(dy, dx) * 180 / Math.PI + 90 + 360) % 360;
          var pctPos = (deg / 360) * 100;

          for (var i = 0; i < ranges.length; i++) {
            var range = ranges[i];
            if (range.end <= range.start) continue;
            if (pctPos >= range.start && pctPos < range.end) return range.index;
          }

          return -1;
        }

        donut.addEventListener('mousemove', function (event) {
          if (lockedIndex !== -1) return;
          var idx = indexFromPointer(event);
          if (idx === currentIndex) return;
          if (idx === -1) resetToDefault(); else preview(idx);
        });

        donut.addEventListener('mouseleave', function () {
          if (lockedIndex === -1) resetToDefault(); else preview(lockedIndex);
        });

        donut.addEventListener('click', function (event) {
          var idx = indexFromPointer(event);
          if (idx === -1) {
            lockedIndex = -1;
            resetToDefault();
            return;
          }
          setLockedIndex(idx);
        });

        items.forEach(function (item, index) {
          if (!item.hasAttribute('tabindex')) {
            item.setAttribute('tabindex', '0');
          }

          item.addEventListener('mouseenter', function () {
            if (lockedIndex !== -1) return;
            preview(index);
          });

          item.addEventListener('mouseleave', function () {
            if (lockedIndex === -1) resetToDefault();
            else preview(lockedIndex);
          });

          item.addEventListener('focus', function () {
            preview(index);
          });

          item.addEventListener('blur', function () {
            if (lockedIndex === -1) resetToDefault();
            else preview(lockedIndex);
          });

          item.addEventListener('click', function () {
            setLockedIndex(index);
          });

          item.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              setLockedIndex(index);
            }
          });
        });

        var resizeTimer = null;
        window.addEventListener('resize', function () {
          window.clearTimeout(resizeTimer);
          resizeTimer = window.setTimeout(function () {
            if (lockedIndex !== -1) preview(lockedIndex);
            else if (currentIndex !== -1) preview(currentIndex);
            else fitCenterText();
          }, 120);
        });

        fitCenterText();
      });
    })();
  </script>
  <script>
    (function () {
      var filterForm = document.getElementById('sa-report-filter-form');
      if (filterForm) {
        var autoFilters = filterForm.querySelectorAll('select');
        autoFilters.forEach(function (field) {
          field.addEventListener('change', function () {
            if (typeof filterForm.requestSubmit === 'function') {
              filterForm.requestSubmit();
            } else {
              filterForm.submit();
            }
          });
        });
      }

      var periodMetaEl = document.getElementById('sa-report-period-meta');
      var recordsEl = document.getElementById('sa-report-tracked-records');
      var amountEl = document.getElementById('sa-report-tracked-amount');
      var updatedEl = document.getElementById('sa-report-generated-at');
      var chart = document.getElementById('sa-report-line-chart');
      var labelsWrap = document.getElementById('sa-report-line-labels');
      var legendWrap = document.getElementById('sa-report-line-legend');
      var dataEl = document.getElementById('sa-report-line-data');
      var inspectorLabel = document.getElementById('sa-report-line-inspector-label');
      var inspectorRows = document.getElementById('sa-report-line-inspector-rows');
      if (!chart || !labelsWrap || !legendWrap || !dataEl || !inspectorLabel || !inspectorRows) return;

      var labels = [];
      var series = [];
      var dots = [];
      var labelEls = [];
      var activeIndex = 0;
      var liveRequestInFlight = false;
      var lastLiveSignature = '';

      function escapeHtml(value) {
        return String(value == null ? '' : value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function clampIndex(index) {
        if (!labels.length) return 0;
        if (index < 0) return 0;
        if (index >= labels.length) return labels.length - 1;
        return index;
      }

      function getLiveFilterValue(name, fallback) {
        if (filterForm) {
          var formField = filterForm.querySelector('[name="' + name + '"]');
          if (formField) {
            return String(formField.value || '');
          }
        }
        var urlParams = new URLSearchParams(window.location.search || '');
        var fromUrl = urlParams.get(name);
        if (fromUrl !== null) return String(fromUrl);
        return String(fallback || '');
      }

      function bindDotEvents() {
        dots.forEach(function (dot) {
          if (dot.dataset.bound === '1') return;
          dot.dataset.bound = '1';

          dot.addEventListener('mouseenter', function () {
            var pointIndex = Number(dot.getAttribute('data-point-index') || -1);
            if (pointIndex >= 0) {
              render(pointIndex);
            }
          });

          dot.addEventListener('click', function () {
            var pointIndex = Number(dot.getAttribute('data-point-index') || -1);
            if (pointIndex >= 0) {
              render(pointIndex);
            }
            chart.focus();
          });
        });
      }

      function syncInteractiveData(nextData, preferredIndex) {
        labels = Array.isArray(nextData && nextData.labels) ? nextData.labels : [];
        series = Array.isArray(nextData && nextData.series) ? nextData.series : [];

        if (!labels.length || !series.length) {
          inspectorLabel.textContent = '-';
          inspectorRows.innerHTML = '';
          return false;
        }

        if (typeof preferredIndex === 'number' && isFinite(preferredIndex)) {
          activeIndex = clampIndex(preferredIndex);
        } else {
          activeIndex = clampIndex(labels.length - 1);
        }

        dots = Array.prototype.slice.call(chart.querySelectorAll('.sa-report-line-chart__dot[data-point-index]'));
        labelEls = Array.prototype.slice.call(labelsWrap.querySelectorAll('span[data-point-index]'));
        bindDotEvents();
        render(activeIndex);
        return true;
      }

      function render(index) {
        if (!labels.length || !series.length) return;
        index = clampIndex(index);
        activeIndex = index;

        var rowsHtml = '';
        series.forEach(function (entry) {
          var values = Array.isArray(entry.values) ? entry.values : [];
          var value = Number(values[index] || 0);
          rowsHtml += '<div class="sa-report-line-inspector__row">' +
            '<span class="sa-report-line-inspector__office">' +
              '<span class="sa-report-line-inspector__swatch" style="--inspector-color:' + escapeHtml(entry.color || '#2563eb') + ';"></span>' +
              escapeHtml(entry.label || '') +
            '</span>' +
            '<strong>' + escapeHtml(String(value)) + ' record' + (value === 1 ? '' : 's') + '</strong>' +
          '</div>';
        });

        inspectorLabel.textContent = labels[index] || '-';
        inspectorRows.innerHTML = rowsHtml;

        dots.forEach(function (dot) {
          var pointIndex = Number(dot.getAttribute('data-point-index') || -1);
          dot.classList.toggle('is-active', pointIndex === activeIndex);
        });

        labelEls.forEach(function (labelEl) {
          var pointIndex = Number(labelEl.getAttribute('data-point-index') || -1);
          labelEl.classList.toggle('is-selected', pointIndex === activeIndex);
        });
      }

      function renderChartFromPayload(trend) {
        var chartInfo = trend && trend.chart ? trend.chart : {};
        var chartWidth = Number(chartInfo.width || 640);
        var chartHeight = Number(chartInfo.height || 220);
        if (!isFinite(chartWidth) || chartWidth <= 0) chartWidth = 640;
        if (!isFinite(chartHeight) || chartHeight <= 0) chartHeight = 220;

        var caption = trend && typeof trend.caption === 'string' ? trend.caption : 'Trend line';
        chart.setAttribute('aria-label', caption + '. Use left and right arrow keys to inspect each point.');

        var svgHtml = '<svg viewBox="0 0 ' + chartWidth + ' ' + chartHeight + '" role="img" aria-label="' + escapeHtml(caption) + '">' +
          '<line x1="18" y1="' + (chartHeight - 20) + '" x2="' + (chartWidth - 18) + '" y2="' + (chartHeight - 20) + '" class="sa-report-line-chart__axis"></line>';

        var payloadSeries = Array.isArray(trend && trend.series) ? trend.series : [];
        payloadSeries.forEach(function (entry, seriesIndex) {
          var color = String(entry && entry.color ? entry.color : '#2563eb');
          var polyline = String(entry && entry.polyline ? entry.polyline : '');
          svgHtml += '<polyline points="' + escapeHtml(polyline) + '" class="sa-report-line-chart__line" style="--line-color: ' + escapeHtml(color) + ';"></polyline>';

          var points = Array.isArray(entry && entry.points) ? entry.points : [];
          points.forEach(function (point, pointIndex) {
            var x = Number(point && point.x);
            var y = Number(point && point.y);
            svgHtml += '<circle cx="' + escapeHtml(isFinite(x) ? x.toFixed(2) : '0.00') + '" cy="' + escapeHtml(isFinite(y) ? y.toFixed(2) : '0.00') + '" r="4" class="sa-report-line-chart__dot" data-series-index="' + seriesIndex + '" data-point-index="' + pointIndex + '" style="--dot-color: ' + escapeHtml(color) + ';"></circle>';
          });
        });

        svgHtml += '</svg>';
        chart.innerHTML = svgHtml;
      }

      function renderTrendLabelsFromPayload(trend) {
        var trendLabels = Array.isArray(trend && trend.labels) ? trend.labels : [];
        var visibleIndexMap = {};
        var visibleIndexes = Array.isArray(trend && trend.point_label_indexes) ? trend.point_label_indexes : [];
        visibleIndexes.forEach(function (idx) {
          var intIdx = Number(idx);
          if (isFinite(intIdx) && intIdx >= 0) {
            visibleIndexMap[intIdx] = true;
          }
        });

        labelsWrap.style.setProperty('--label-count', String(Math.max(1, trendLabels.length)));
        labelsWrap.innerHTML = trendLabels.map(function (label, idx) {
          var visible = !!visibleIndexMap[idx];
          return '<span data-point-index="' + idx + '" class="' + (visible ? 'is-visible' : '') + '">' +
            (visible ? escapeHtml(label) : '&nbsp;') +
          '</span>';
        }).join('');
      }

      function renderTrendLegendFromPayload(trend) {
        var payloadSeries = Array.isArray(trend && trend.series) ? trend.series : [];
        var legendHtml = '<span class="sa-report-line-legend__title">Office Lines</span>';
        payloadSeries.forEach(function (entry) {
          legendHtml += '<div class="sa-report-line-legend__item">' +
            '<span class="sa-report-line-legend__swatch" style="--legend-line-color: ' + escapeHtml(entry && entry.color ? entry.color : '#2563eb') + ';"></span>' +
            '<span class="sa-report-line-legend__name">' + escapeHtml(entry && entry.label ? entry.label : '') + '</span>' +
          '</div>';
        });
        legendWrap.innerHTML = legendHtml;
      }

      function applyLivePayload(payload) {
        if (!payload || payload.ok !== true) return;

        if (periodMetaEl && typeof payload.period_meta === 'string') {
          periodMetaEl.textContent = payload.period_meta;
        }
        if (updatedEl && typeof payload.generated_at === 'string') {
          updatedEl.textContent = payload.generated_at;
        }
        if (recordsEl) {
          recordsEl.textContent = payload.tracked_records_display || String(payload.tracked_records || 0);
        }
        if (amountEl) {
          amountEl.textContent = payload.tracked_amount_display || 'PHP 0.00';
        }

        var trend = payload.trend || {};
        renderChartFromPayload(trend);
        renderTrendLabelsFromPayload(trend);
        renderTrendLegendFromPayload(trend);

        var interactive = trend.interactive || {
          labels: Array.isArray(trend.labels) ? trend.labels : [],
          series: Array.isArray(trend.series) ? trend.series.map(function (entry) {
            return {
              label: String(entry && entry.label ? entry.label : ''),
              color: String(entry && entry.color ? entry.color : '#2563eb'),
              values: Array.isArray(entry && entry.values) ? entry.values : [],
            };
          }) : [],
        };
        dataEl.textContent = JSON.stringify(interactive);
        syncInteractiveData(interactive, activeIndex);
      }

      function buildLiveSignature(payload) {
        return JSON.stringify({
          records: Number(payload && payload.tracked_records ? payload.tracked_records : 0),
          amount: Number(payload && payload.tracked_amount ? payload.tracked_amount : 0),
          trend: payload && payload.trend && payload.trend.interactive ? payload.trend.interactive : {},
        });
      }

      async function refreshReportTracking() {
        if (liveRequestInFlight || document.hidden) return;
        liveRequestInFlight = true;

        try {
          var params = new URLSearchParams();
          params.set('report_tracking_live', '1');
          params.set('report_period', getLiveFilterValue('report_period', 'all'));
          params.set('report_year', getLiveFilterValue('report_year', ''));
          params.set('report_office', getLiveFilterValue('report_office', ''));
          params.set('report_barangay', getLiveFilterValue('report_barangay', ''));
          params.set('_ts', String(Date.now()));

          var response = await fetch('super_admin.php?' + params.toString(), {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
            credentials: 'same-origin',
          });
          if (!response.ok) {
            throw new Error('Report tracking refresh failed with status ' + response.status);
          }

          var payload = await response.json();
          if (!payload || payload.ok !== true) return;

          var nextSignature = buildLiveSignature(payload);
          if (nextSignature === lastLiveSignature) return;

          lastLiveSignature = nextSignature;
          applyLivePayload(payload);
        } catch (err) {
          console.error(err);
        } finally {
          liveRequestInFlight = false;
        }
      }

      if (chart.dataset.keyboardBound !== '1') {
        chart.dataset.keyboardBound = '1';
        chart.addEventListener('keydown', function (event) {
          if (event.key === 'ArrowLeft') {
            event.preventDefault();
            render(activeIndex - 1);
          } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            render(activeIndex + 1);
          } else if (event.key === 'Home') {
            event.preventDefault();
            render(0);
          } else if (event.key === 'End') {
            event.preventDefault();
            render(labels.length - 1);
          }
        });
        chart.addEventListener('click', function () {
          chart.focus();
        });
      }

      var initialData = null;
      try {
        initialData = JSON.parse(dataEl.textContent || '{}');
      } catch (err) {
        initialData = null;
      }
      if (!syncInteractiveData(initialData, null)) {
        return;
      }

      lastLiveSignature = JSON.stringify({
        records: Number((recordsEl && recordsEl.textContent ? recordsEl.textContent.replace(/,/g, '') : '0') || 0),
        amount: Number((amountEl && amountEl.textContent ? amountEl.textContent.replace(/[^\d.-]/g, '') : '0') || 0),
        trend: initialData,
      });

      setInterval(function () {
        refreshReportTracking();
      }, 4000);

      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          refreshReportTracking();
        }
      });
    })();
  </script>
</body>
</html>
