<?php
require_once "auth.php";
require_login();
require_once "db.php";

$name = trim($_POST["name"] ?? "");
$type = trim($_POST["type"] ?? "");
$type_specify = trim($_POST["type_specify"] ?? "");
$barangay = trim($_POST["barangay"] ?? "");
$amountRaw = $_POST["amount"] ?? "";
$record_date = trim($_POST["record_date"] ?? date("Y-m-d"));
$notes = trim($_POST["notes"] ?? "");
$csrfToken = $_POST["csrf_token"] ?? "";

// Auto-set (don’t trust client)
$municipality = "Daet";
$province = "Camarines Norte";

function has_column(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = @$conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("ss", $table, $column);
  $st->execute();
  $rs = $st->get_result();
  return ($rs && $rs->num_rows > 0);
}
function redirect_with_msg(string $status, string $msg): void {
  $url = "index.php?status=" . urlencode($status) . "&msg=" . urlencode($msg) . "#entry-section";
  header("Location: " . $url);
  exit;
}

// Basic validation
if (!verify_csrf_token($csrfToken)) {
  redirect_with_msg("error", "Invalid request token. Please refresh and try again.");
  exit;
}

if ($name === "" || $type === "" || $barangay === "" || $amountRaw === "" || !is_numeric($amountRaw)) {
  redirect_with_msg("error", "Please fill out all required fields correctly.");
  exit;
}

if ($type === "Other" && $type_specify === "") {
  redirect_with_msg("error", "Please specify the Type of Assistance when you choose Other.");
  exit;
}

$amount = (float)$amountRaw;
if ($amount < 0) {
  redirect_with_msg("error", "Amount must be 0 or higher.");
  exit;
}

$ts = strtotime($record_date);
if ($ts === false) {
  redirect_with_msg("error", "Invalid date.");
  exit;
}

$month_year = date("Y-m", $ts);

$hasTypeSpecify = has_column($conn, "records", "type_specify");

// If the column doesn't exist yet, store the specify value inside notes (hidden tag)
if (!$hasTypeSpecify) {
  if ($type === "Other" && $type_specify !== "") {
    $notes = "[SPECIFY]" . $type_specify . "[/SPECIFY]\n" . $notes;
  }
  $type_specify = null;
}

if ($hasTypeSpecify) {
  $stmt = $conn->prepare(
    "INSERT INTO records (name, type, type_specify, barangay, municipality, province, amount, record_date, month_year, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );

  if (!$stmt) {
    redirect_with_msg("error", "Database error: " . $conn->error);
    exit;
  }

  $stmt->bind_param(
    "ssssssdsss",
    $name,
    $type,
    $type_specify,
    $barangay,
    $municipality,
    $province,
    $amount,
    $record_date,
    $month_year,
    $notes
  );
} else {
  $stmt = $conn->prepare(
    "INSERT INTO records (name, type, barangay, municipality, province, amount, record_date, month_year, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );

  if (!$stmt) {
    redirect_with_msg("error", "Database error: " . $conn->error);
    exit;
  }

  $stmt->bind_param(
    "sssssdsss",
    $name,
    $type,
    $barangay,
    $municipality,
    $province,
    $amount,
    $record_date,
    $month_year,
    $notes
  );
}

if ($stmt->execute()) {
  $newRecordId = (int)$conn->insert_id;
  $actor = current_auth_user();
  audit_log(
    "record_create",
    "Created a new record for \"" . $name . "\".",
    $actor !== "" ? $actor : null,
    $newRecordId > 0 ? $newRecordId : null
  );
  redirect_with_msg("success", "Saved successfully.");
  exit;
}

redirect_with_msg("error", "Error saving record: " . $stmt->error);
exit;
