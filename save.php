<?php
require_once "auth.php";
require_login();
require_once "db.php";

function normalize_name_piece(string $value): string {
  $value = trim($value);
  if ($value === "") return "";
  $value = preg_replace('/\s+/u', ' ', $value);
  return trim((string)$value);
}

function normalize_name_extension(string $value): string {
  $clean = normalize_name_piece($value);
  if ($clean === "") return "";
  $key = strtoupper(str_replace(".", "", $clean));
  $map = [
    "JR" => "Jr.",
    "SR" => "Sr.",
    "II" => "II",
    "III" => "III",
    "IV" => "IV",
    "V" => "V",
    "VI" => "VI",
  ];
  return $map[$key] ?? $clean;
}

function split_extension_from_tokens(array $tokens): array {
  if (empty($tokens)) return [$tokens, ""];
  $lastToken = (string)($tokens[count($tokens) - 1] ?? "");
  $normalized = normalize_name_extension($lastToken);
  if ($normalized === "") return [$tokens, ""];
  $key = strtoupper(str_replace(".", "", $lastToken));
  $known = ["JR", "SR", "II", "III", "IV", "V", "VI"];
  if (!in_array($key, $known, true)) {
    return [$tokens, ""];
  }
  array_pop($tokens);
  return [$tokens, $normalized];
}

function split_record_name_parts(string $fullName): array {
  $fullName = trim((string)$fullName);
  if ($fullName === "") return ["", "", "", ""];

  if (str_contains($fullName, ",")) {
    $chunks = explode(",", $fullName, 2);
    $last = normalize_name_piece((string)($chunks[0] ?? ""));
    $rest = normalize_name_piece((string)($chunks[1] ?? ""));
    $tokens = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY);
    [$tokens, $extension] = split_extension_from_tokens(is_array($tokens) ? $tokens : []);
    $first = normalize_name_piece((string)($tokens[0] ?? ""));
    $middle = "";
    if (count($tokens) > 1) {
      $middle = normalize_name_piece(implode(" ", array_slice($tokens, 1)));
    }
    return [$last, $first, $middle, $extension];
  }

  $tokens = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY);
  $tokens = is_array($tokens) ? $tokens : [];
  [$tokens, $extension] = split_extension_from_tokens($tokens);
  if (count($tokens) === 0) return ["", "", "", $extension];
  if (count($tokens) === 1) return ["", normalize_name_piece((string)$tokens[0]), "", $extension];
  if (count($tokens) === 2) return [normalize_name_piece((string)$tokens[1]), normalize_name_piece((string)$tokens[0]), "", $extension];

  $first = normalize_name_piece((string)$tokens[0]);
  $middle = normalize_name_piece((string)$tokens[1]);
  $last = normalize_name_piece(implode(" ", array_slice($tokens, 2)));
  return [$last, $first, $middle, $extension];
}

function build_record_name_for_storage(string $last, string $first, string $middle, string $extension): string {
  $last = normalize_name_piece($last);
  $first = normalize_name_piece($first);
  $middle = normalize_name_piece($middle);
  $extension = normalize_name_extension($extension);
  $full = $last . ", " . $first;
  if ($middle !== "") {
    $full .= " " . $middle;
  }
  if ($extension !== "") {
    $full .= " " . $extension;
  }
  return trim($full);
}

function format_name_middle_initial(string $fullName): string {
  [$last, $first, $middle, $extension] = split_record_name_parts($fullName);
  if ($last === "" && $first === "") {
    return normalize_name_piece($fullName);
  }
  $out = trim($last);
  if ($first !== "") {
    $out .= ($out !== "" ? ", " : "") . $first;
  }
  if ($middle !== "") {
    $middleInitial = strtoupper(substr(trim($middle), 0, 1));
    if ($middleInitial !== "") {
      $out .= " " . $middleInitial . ".";
    }
  }
  if ($extension !== "") {
    $out .= " " . $extension;
  }
  return trim($out);
}

$lastName = normalize_name_piece((string)($_POST["last_name"] ?? ""));
$firstName = normalize_name_piece((string)($_POST["first_name"] ?? ""));
$middleName = normalize_name_piece((string)($_POST["middle_name"] ?? ""));
$nameExtension = normalize_name_extension((string)($_POST["name_extension"] ?? ""));
$legacyNameRaw = normalize_name_piece((string)($_POST["name"] ?? ""));
if (($lastName === "" || $firstName === "" || $middleName === "") && $legacyNameRaw !== "") {
  [$legacyLast, $legacyFirst, $legacyMiddle, $legacyExtension] = split_record_name_parts($legacyNameRaw);
  if ($lastName === "") $lastName = $legacyLast;
  if ($firstName === "") $firstName = $legacyFirst;
  if ($middleName === "") $middleName = $legacyMiddle;
  if ($nameExtension === "") $nameExtension = $legacyExtension;
}
$name = build_record_name_for_storage($lastName, $firstName, $middleName, $nameExtension);
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
$isBorabodOffice = normalize_office_scope_name($officeScope) === "borabod";
$postedMunicipality = trim((string)($_POST["municipality"] ?? ""));

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

if (!$isMaifOffice && strcasecmp($type, "MAIF") === 0 && !$isBorabodOffice) {
  redirect_with_msg("error", "MAIF assistance type is only available in the Borabod office.");
  exit;
}

$isMaifStyleEntry = $isMaifOffice || ($isBorabodOffice && strcasecmp($type, "MAIF") === 0);
$municipality = $isMaifStyleEntry
  ? normalize_maif_municipality($postedMunicipality)
  : "Daet";

if ($lastName === "" || $firstName === "" || $middleName === "") {
  redirect_with_msg("error", "Please provide Last Name, First Name, and Middle Name.");
  exit;
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
if ($isMaifStyleEntry && $municipality === "") {
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

if (!$isMaifStyleEntry) {
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
    "name" => format_name_middle_initial($name),
    "barangay" => $barangay,
    "type" => $typeLabel,
    "year" => (string)$yearKey,
  ];
  $_SESSION["duplicate_form_draft"] = [
    "name" => $name,
    "last_name" => $lastName,
    "first_name" => $firstName,
    "middle_name" => $middleName,
    "name_extension" => $nameExtension,
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
