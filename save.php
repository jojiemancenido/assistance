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
$ageRaw = trim((string)($_POST["age"] ?? ""));
$birthdate = trim((string)($_POST["birthdate"] ?? ""));
$contactNumber = trim((string)($_POST["contact_number"] ?? ""));
$diagnosis = trim((string)($_POST["diagnosis"] ?? ""));
$hospital = trim((string)($_POST["hospital"] ?? ""));
$contactPerson = trim((string)($_POST["contact_person"] ?? ""));
$csrfToken = $_POST["csrf_token"] ?? "";
$scopedBarangay = current_scoped_barangay();
if ($scopedBarangay !== "") {
  $barangay = $scopedBarangay;
}
$scopedOffice = current_scoped_office();
$officeScope = ($scopedOffice !== "") ? $scopedOffice : "municipality";
$isMaifOffice = is_maif_office_scope($officeScope);

// Auto-set (don’t trust client)
$municipality = $isMaifOffice
  ? normalize_maif_municipality((string)($_POST["municipality"] ?? ""))
  : "Daet";
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
function redirect_with_msg(string $status, string $msg, array $extra = []): void {
  $url = "index.php?status=" . urlencode($status) . "&msg=" . urlencode($msg);
  if (!empty($extra)) {
    $url .= "&" . http_build_query($extra);
  }
  $url .= "#entry-section";
  header("Location: " . $url);
  exit;
}

function redirect_to_entry(): void {
  header("Location: index.php#entry-section");
  exit;
}

// Basic validation
if (!verify_csrf_token($csrfToken)) {
  redirect_with_msg("error", "Invalid request token. Please refresh and try again.");
  exit;
}

if ($isMaifOffice) {
  $type = "Medical";
  $type_specify = "";
}

if ($name === "" || $type === "" || $barangay === "" || $amountRaw === "" || !is_numeric($amountRaw)) {
  redirect_with_msg("error", "Please fill out all required fields correctly.");
  exit;
}

if (!$isMaifOffice && $type === "Other" && $type_specify === "") {
  redirect_with_msg("error", "Please specify the Type of Assistance when you choose Other.");
  exit;
}
if ($type !== "Other") {
  $type_specify = "";
}
if ($isMaifOffice && $municipality === "") {
  redirect_with_msg("error", "Please select a municipality for the MAIF entry.");
  exit;
}

$amount = (float)$amountRaw;
if ($amount < 0) {
  redirect_with_msg("error", "Amount must be 0 or higher.");
  exit;
}

$age = null;
if ($ageRaw !== "") {
  if (!preg_match('/^\d{1,3}$/', $ageRaw)) {
    redirect_with_msg("error", "Age must be a whole number.");
    exit;
  }
  $age = (int)$ageRaw;
  if ($age < 0 || $age > 150) {
    redirect_with_msg("error", "Age must be between 0 and 150.");
    exit;
  }
}

$ts = strtotime($record_date);
if ($ts === false) {
  redirect_with_msg("error", "Invalid date.");
  exit;
}

if (!$isMaifOffice) {
  $age = null;
  $birthdate = "";
  $contactNumber = "";
  $diagnosis = "";
  $hospital = "";
  $contactPerson = "";
}

if ($birthdate !== "") {
  $birthTs = strtotime($birthdate);
  if ($birthTs === false) {
    redirect_with_msg("error", "Invalid birthdate.");
    exit;
  }
  if ($birthTs > strtotime(date("Y-m-d"))) {
    redirect_with_msg("error", "Birthdate cannot be in the future.");
    exit;
  }
  if ($age === null) {
    $birthDateObj = new DateTimeImmutable(date("Y-m-d", $birthTs));
    $todayObj = new DateTimeImmutable(date("Y-m-d"));
    $age = $birthDateObj->diff($todayObj)->y;
  }
}

$month_year = date("Y-m", $ts);
$yearKey = (int)date("Y", $ts);
$confirmDuplicate = trim((string)($_POST["confirm_duplicate"] ?? "")) === "1";
$hasTypeSpecify = has_column($conn, "records", "type_specify");
$normalizedName = strtolower(trim($name));
$normalizedBarangay = strtolower(trim($barangay));
$normalizedTypeSpecify = strtolower(trim($type_specify));
$duplicateCount = 0;
$duplicateSql = (
  "SELECT COUNT(*) AS total_duplicates
   FROM records
   WHERE LOWER(TRIM(name)) = ?
     AND LOWER(TRIM(barangay)) = ?
     AND type = ?
     AND YEAR(record_date) = ?
     AND office_scope = ?"
);
if ($hasTypeSpecify) {
  $duplicateSql .= " AND LOWER(TRIM(COALESCE(type_specify, ''))) = ?";
}
$dupStmt = $conn->prepare($duplicateSql);
$typeLabel = $type;
if ($type === "Other" && $type_specify !== "") {
  $typeLabel = "Other: " . $type_specify;
}
if ($dupStmt) {
  if ($hasTypeSpecify) {
    $dupStmt->bind_param("sssiss", $normalizedName, $normalizedBarangay, $type, $yearKey, $officeScope, $normalizedTypeSpecify);
  } else {
    $dupStmt->bind_param("sssis", $normalizedName, $normalizedBarangay, $type, $yearKey, $officeScope);
  }
  if ($dupStmt->execute()) {
    $dupRes = $dupStmt->get_result();
    if ($dupRes && $dupRow = $dupRes->fetch_assoc()) {
      $duplicateCount = (int)($dupRow["total_duplicates"] ?? 0);
    }
  }
  $dupStmt->close();
}

if (!$confirmDuplicate && $duplicateCount > 0) {
  $_SESSION["duplicate_warning"] = [
    "count" => $duplicateCount,
    "name" => $name,
    "barangay" => $barangay,
    "type" => $typeLabel,
    "year" => (string)$yearKey,
  ];
  $_SESSION["duplicate_form_draft"] = [
    "name" => $name,
    "type" => $type,
    "type_specify" => $type_specify,
    "barangay" => $barangay,
    "municipality" => $municipality,
    "amount" => $amountRaw,
    "record_date" => $record_date,
    "notes" => $notes,
    "age" => ($age === null) ? "" : (string)$age,
    "birthdate" => $birthdate,
    "contact_number" => $contactNumber,
    "diagnosis" => $diagnosis,
    "hospital" => $hospital,
    "contact_person" => $contactPerson,
  ];
  redirect_to_entry();
}

// If the column doesn't exist yet, store the specify value inside notes (hidden tag)
if (!$hasTypeSpecify) {
  if ($type === "Other" && $type_specify !== "") {
    $notes = "[SPECIFY]" . $type_specify . "[/SPECIFY]\n" . $notes;
  }
  $type_specify = null;
}

if ($hasTypeSpecify) {
  $stmt = $conn->prepare(
    "INSERT INTO records (name, type, type_specify, barangay, office_scope, municipality, province, amount, record_date, month_year, notes, age, birthdate, contact_number, diagnosis, hospital, contact_person)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );

  if (!$stmt) {
    redirect_with_msg("error", "Database error: " . $conn->error);
    exit;
  }

  $stmt->bind_param(
    "sssssssdsssssssss",
    $name,
    $type,
    $type_specify,
    $barangay,
    $officeScope,
    $municipality,
    $province,
    $amount,
    $record_date,
    $month_year,
    $notes,
    $age,
    $birthdate,
    $contactNumber,
    $diagnosis,
    $hospital,
    $contactPerson
  );
} else {
  $stmt = $conn->prepare(
    "INSERT INTO records (name, type, barangay, office_scope, municipality, province, amount, record_date, month_year, notes, age, birthdate, contact_number, diagnosis, hospital, contact_person)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );

  if (!$stmt) {
    redirect_with_msg("error", "Database error: " . $conn->error);
    exit;
  }

  $stmt->bind_param(
    "ssssssdsssssssss",
    $name,
    $type,
    $barangay,
    $officeScope,
    $municipality,
    $province,
    $amount,
    $record_date,
    $month_year,
    $notes,
    $age,
    $birthdate,
    $contactNumber,
    $diagnosis,
    $hospital,
    $contactPerson
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
