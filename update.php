<?php
require_once "auth.php";
require_login();
require_once "db.php";

function has_column(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = @$conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param("ss", $table, $column);
  $st->execute();
  $rs = $st->get_result();
  return ($rs && $rs->num_rows > 0);
}

function normalize_return_to(string $raw, bool $isSuperAdmin): string {
  $fallback = $isSuperAdmin ? "super_admin.php#all-assistance-section" : "index.php#records-section";
  $decoded = rawurldecode(trim($raw));

  if ($decoded === "" || str_contains($decoded, "://") || str_starts_with($decoded, "//") || str_starts_with($decoded, "\\")) {
    return $fallback;
  }

  $parts = parse_url($decoded);
  if ($parts === false) {
    return $fallback;
  }

  $path = trim((string)($parts["path"] ?? ""));
  if (!in_array($path, ["index.php", "records.php", "super_admin.php"], true)) {
    return $fallback;
  }
  if ($path === "super_admin.php" && !$isSuperAdmin) {
    return $fallback;
  }

  $query = isset($parts["query"]) && $parts["query"] !== "" ? ("?" . $parts["query"]) : "";
  $fragment = isset($parts["fragment"]) && $parts["fragment"] !== "" ? ("#" . $parts["fragment"]) : "";
  return $path . $query . $fragment;
}

function with_status_message(string $url, string $status, string $message): string {
  $fragment = "";
  $hashPos = strpos($url, "#");
  if ($hashPos !== false) {
    $fragment = substr($url, $hashPos);
    $url = substr($url, 0, $hashPos);
  }

  $joiner = str_contains($url, "?") ? "&" : "?";
  return $url . $joiner . "status=" . urlencode($status) . "&msg=" . urlencode($message) . $fragment;
}

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

function redirect_edit(int $recordId, string $status, string $message, string $returnTo, bool $isPopup = false, bool $closeAfter = false): void {
  $url = "edit.php?record_id=" . urlencode((string)$recordId) .
    "&return_to=" . urlencode($returnTo) .
    "&status=" . urlencode($status) .
    "&msg=" . urlencode($message);
  if ($isPopup) {
    $url .= "&popup=1";
  }
  if ($closeAfter) {
    $url .= "&close=1";
  }
  header("Location: " . $url);
  exit;
}

$recordId = (int)($_POST["record_id"] ?? 0);
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
$type = trim((string)($_POST["type"] ?? ""));
$typeSpecify = trim((string)($_POST["type_specify"] ?? ""));
$barangay = trim((string)($_POST["barangay"] ?? ""));
$amountRaw = $_POST["amount"] ?? "";
$recordDate = trim((string)($_POST["record_date"] ?? date("Y-m-d")));
$notes = trim((string)($_POST["notes"] ?? ""));
$postedMunicipality = trim((string)($_POST["municipality"] ?? ""));
$ageRaw = trim((string)($_POST["age"] ?? ""));
$birthdate = trim((string)($_POST["birthdate"] ?? ""));
$contactNumber = trim((string)($_POST["contact_number"] ?? ""));
$diagnosis = trim((string)($_POST["diagnosis"] ?? ""));
$hospital = trim((string)($_POST["hospital"] ?? ""));
$contactPerson = trim((string)($_POST["contact_person"] ?? ""));
$csrfToken = $_POST["csrf_token"] ?? "";
$returnTo = normalize_return_to((string)($_POST["return_to"] ?? ""), is_super_admin());
$isPopup = (isset($_POST["popup"]) && (string)$_POST["popup"] === "1");
$scopedBarangay = current_scoped_barangay();
if ($scopedBarangay !== "") {
  $barangay = $scopedBarangay;
}
$scopedOffice = current_scoped_office();
$isOfficeScoped = ($scopedOffice !== "");

$municipality = "Daet";
$province = "Camarines Norte";

if ($recordId <= 0) {
  header("Location: " . with_status_message($returnTo, "error", "Invalid record selected for update."));
  exit;
}

if (!verify_csrf_token($csrfToken)) {
  redirect_edit($recordId, "error", "Invalid request token. Please refresh and try again.", $returnTo, $isPopup);
}

if ($lastName === "" || $firstName === "" || $middleName === "") {
  redirect_edit($recordId, "error", "Please provide Last Name, First Name, and Middle Name.", $returnTo, $isPopup);
}

if ($name === "" || $barangay === "" || $amountRaw === "" || !is_numeric($amountRaw)) {
  redirect_edit($recordId, "error", "Please fill out all required fields correctly.", $returnTo, $isPopup);
}

$amount = (float)$amountRaw;
if ($amount < 0) {
  redirect_edit($recordId, "error", "Amount must be 0 or higher.", $returnTo, $isPopup);
}

$ts = strtotime($recordDate);
if ($ts === false) {
  redirect_edit($recordId, "error", "Invalid date.", $returnTo, $isPopup);
}
$monthYear = date("Y-m", $ts);

$checkSql = "SELECT record_id, office_scope FROM records WHERE record_id = ?";
if ($isOfficeScoped) {
  $checkSql .= " AND office_scope = ?";
}
if ($scopedBarangay !== "") {
  $checkSql .= " AND barangay = ?";
}
$checkSql .= " LIMIT 1";

$checkStmt = $conn->prepare($checkSql);
if (!$checkStmt) {
  redirect_edit($recordId, "error", "Database error: " . $conn->error, $returnTo, $isPopup);
}
if ($isOfficeScoped && $scopedBarangay !== "") {
  $checkStmt->bind_param("iss", $recordId, $scopedOffice, $scopedBarangay);
} elseif ($isOfficeScoped) {
  $checkStmt->bind_param("is", $recordId, $scopedOffice);
} elseif ($scopedBarangay !== "") {
  $checkStmt->bind_param("is", $recordId, $scopedBarangay);
} else {
  $checkStmt->bind_param("i", $recordId);
}
$checkStmt->execute();
$exists = $checkStmt->get_result();
$checkRow = ($exists && $exists->num_rows > 0) ? $exists->fetch_assoc() : null;
$checkStmt->close();
if (!$checkRow) {
  header("Location: " . with_status_message($returnTo, "error", "Record not found or access denied."));
  exit;
}
$existingOfficeScope = normalize_office_scope_name((string)($checkRow["office_scope"] ?? ""));
if ($existingOfficeScope === "") {
  $existingOfficeScope = "municipality";
}
$officeScopeToSave = $isOfficeScoped ? $scopedOffice : $existingOfficeScope;
$isMaifRecord = is_maif_office_scope($officeScopeToSave);

if ($isMaifRecord) {
  $type = "Medical";
  $typeSpecify = "";
  $municipality = normalize_maif_municipality($postedMunicipality);
  if ($municipality === "") {
    redirect_edit($recordId, "error", "Please select a municipality for the MAIF entry.", $returnTo, $isPopup);
  }
} else {
  $municipality = "Daet";
}

if ($type === "") {
  redirect_edit($recordId, "error", "Please fill out all required fields correctly.", $returnTo, $isPopup);
}
if (!$isMaifRecord && $type === "Other" && $typeSpecify === "") {
  redirect_edit($recordId, "error", "Please specify the Type of Assistance when you choose Other.", $returnTo, $isPopup);
}
if ($type !== "Other") {
  $typeSpecify = "";
}

$age = null;
if ($ageRaw !== "") {
  if (!preg_match('/^\d{1,3}$/', $ageRaw)) {
    redirect_edit($recordId, "error", "Age must be a whole number.", $returnTo, $isPopup);
  }
  $age = (int)$ageRaw;
  if ($age < 0 || $age > 150) {
    redirect_edit($recordId, "error", "Age must be between 0 and 150.", $returnTo, $isPopup);
  }
}

if (!$isMaifRecord) {
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
    redirect_edit($recordId, "error", "Invalid birthdate.", $returnTo, $isPopup);
  }
  if ($birthTs > strtotime(date("Y-m-d"))) {
    redirect_edit($recordId, "error", "Birthdate cannot be in the future.", $returnTo, $isPopup);
  }
  if ($age === null) {
    $birthDateObj = new DateTimeImmutable(date("Y-m-d", $birthTs));
    $todayObj = new DateTimeImmutable(date("Y-m-d"));
    $age = $birthDateObj->diff($todayObj)->y;
  }
}
$hasTypeSpecify = has_column($conn, "records", "type_specify");
if (!$hasTypeSpecify) {
  if ($type === "Other" && $typeSpecify !== "") {
    $notes = "[SPECIFY]" . $typeSpecify . "[/SPECIFY]\n" . $notes;
  }
  $typeSpecify = "";
} elseif ($type !== "Other") {
  $typeSpecify = "";
}

if ($hasTypeSpecify) {
  $updateSql = "UPDATE records
     SET name = ?, type = ?, type_specify = ?, barangay = ?, office_scope = ?, municipality = ?, province = ?, amount = ?, record_date = ?, month_year = ?, notes = ?, age = ?, birthdate = ?, contact_number = ?, diagnosis = ?, hospital = ?, contact_person = ?
     WHERE record_id = ?";
  if ($isOfficeScoped) {
    $updateSql .= " AND office_scope = ?";
  }
  if ($scopedBarangay !== "") {
    $updateSql .= " AND barangay = ?";
  }
  $updateSql .= " LIMIT 1";

  $stmt = $conn->prepare($updateSql);

  if (!$stmt) {
    redirect_edit($recordId, "error", "Database error: " . $conn->error, $returnTo, $isPopup);
  }

  if ($isOfficeScoped && $scopedBarangay !== "") {
    $stmt->bind_param(
      "sssssssdsssssssssiss",
      $name,
      $type,
      $typeSpecify,
      $barangay,
      $officeScopeToSave,
      $municipality,
      $province,
      $amount,
      $recordDate,
      $monthYear,
      $notes,
      $age,
      $birthdate,
      $contactNumber,
      $diagnosis,
      $hospital,
      $contactPerson,
      $recordId,
      $scopedOffice,
      $scopedBarangay
    );
  } elseif ($isOfficeScoped) {
    $stmt->bind_param(
      "sssssssdssssssssis",
      $name,
      $type,
      $typeSpecify,
      $barangay,
      $officeScopeToSave,
      $municipality,
      $province,
      $amount,
      $recordDate,
      $monthYear,
      $notes,
      $age,
      $birthdate,
      $contactNumber,
      $diagnosis,
      $hospital,
      $contactPerson,
      $recordId,
      $scopedOffice
    );
  } elseif ($scopedBarangay !== "") {
    $stmt->bind_param(
      "sssssssdssssssssis",
      $name,
      $type,
      $typeSpecify,
      $barangay,
      $officeScopeToSave,
      $municipality,
      $province,
      $amount,
      $recordDate,
      $monthYear,
      $notes,
      $age,
      $birthdate,
      $contactNumber,
      $diagnosis,
      $hospital,
      $contactPerson,
      $recordId,
      $scopedBarangay
    );
  } else {
    $stmt->bind_param(
      "sssssssdssssssssi",
      $name,
      $type,
      $typeSpecify,
      $barangay,
      $officeScopeToSave,
      $municipality,
      $province,
      $amount,
      $recordDate,
      $monthYear,
      $notes,
      $age,
      $birthdate,
      $contactNumber,
      $diagnosis,
      $hospital,
      $contactPerson,
      $recordId
    );
  }
} else {
  $updateSql = "UPDATE records
     SET name = ?, type = ?, barangay = ?, office_scope = ?, municipality = ?, province = ?, amount = ?, record_date = ?, month_year = ?, notes = ?, age = ?, birthdate = ?, contact_number = ?, diagnosis = ?, hospital = ?, contact_person = ?
     WHERE record_id = ?";
  if ($isOfficeScoped) {
    $updateSql .= " AND office_scope = ?";
  }
  if ($scopedBarangay !== "") {
    $updateSql .= " AND barangay = ?";
  }
  $updateSql .= " LIMIT 1";

  $stmt = $conn->prepare($updateSql);

  if (!$stmt) {
    redirect_edit($recordId, "error", "Database error: " . $conn->error, $returnTo, $isPopup);
  }

  if ($isOfficeScoped && $scopedBarangay !== "") {
    $stmt->bind_param(
      "ssssssdsssssssssiss",
      $name,
      $type,
      $barangay,
      $officeScopeToSave,
      $municipality,
      $province,
      $amount,
      $recordDate,
      $monthYear,
      $notes,
      $age,
      $birthdate,
      $contactNumber,
      $diagnosis,
      $hospital,
      $contactPerson,
      $recordId,
      $scopedOffice,
      $scopedBarangay
    );
  } elseif ($isOfficeScoped) {
    $stmt->bind_param(
      "ssssssdssssssssis",
      $name,
      $type,
      $barangay,
      $officeScopeToSave,
      $municipality,
      $province,
      $amount,
      $recordDate,
      $monthYear,
      $notes,
      $age,
      $birthdate,
      $contactNumber,
      $diagnosis,
      $hospital,
      $contactPerson,
      $recordId,
      $scopedOffice
    );
  } elseif ($scopedBarangay !== "") {
    $stmt->bind_param(
      "ssssssdssssssssis",
      $name,
      $type,
      $barangay,
      $officeScopeToSave,
      $municipality,
      $province,
      $amount,
      $recordDate,
      $monthYear,
      $notes,
      $age,
      $birthdate,
      $contactNumber,
      $diagnosis,
      $hospital,
      $contactPerson,
      $recordId,
      $scopedBarangay
    );
  } else {
    $stmt->bind_param(
      "ssssssdssssssssi",
      $name,
      $type,
      $barangay,
      $officeScopeToSave,
      $municipality,
      $province,
      $amount,
      $recordDate,
      $monthYear,
      $notes,
      $age,
      $birthdate,
      $contactNumber,
      $diagnosis,
      $hospital,
      $contactPerson,
      $recordId
    );
  }
}

if (!$stmt->execute()) {
  $error = $stmt->error;
  $stmt->close();
  redirect_edit($recordId, "error", "Error updating record: " . $error, $returnTo, $isPopup);
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected > 0) {
  $actor = current_auth_user();
  audit_log(
    "record_update",
    "Updated record #" . $recordId . " for \"" . $name . "\".",
    $actor !== "" ? $actor : null,
    $recordId
  );
  if ($isPopup) {
    redirect_edit($recordId, "success", "Record updated successfully.", $returnTo, true, true);
  }
  header("Location: " . with_status_message($returnTo, "success", "Record updated successfully."));
  exit;
}

if ($isPopup) {
  redirect_edit($recordId, "success", "No changes were made.", $returnTo, true, true);
}
header("Location: " . with_status_message($returnTo, "success", "No changes were made."));
exit;
?>

