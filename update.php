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

function redirect_edit(int $recordId, string $status, string $message, string $returnTo): void {
  header(
    "Location: edit.php?record_id=" . urlencode((string)$recordId) .
    "&return_to=" . urlencode($returnTo) .
    "&status=" . urlencode($status) .
    "&msg=" . urlencode($message)
  );
  exit;
}

$recordId = (int)($_POST["record_id"] ?? 0);
$name = trim((string)($_POST["name"] ?? ""));
$type = trim((string)($_POST["type"] ?? ""));
$typeSpecify = trim((string)($_POST["type_specify"] ?? ""));
$barangay = trim((string)($_POST["barangay"] ?? ""));
$amountRaw = $_POST["amount"] ?? "";
$recordDate = trim((string)($_POST["record_date"] ?? date("Y-m-d")));
$notes = trim((string)($_POST["notes"] ?? ""));
$csrfToken = $_POST["csrf_token"] ?? "";
$returnTo = normalize_return_to((string)($_POST["return_to"] ?? ""), is_super_admin());
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
  redirect_edit($recordId, "error", "Invalid request token. Please refresh and try again.", $returnTo);
}

if ($name === "" || $type === "" || $barangay === "" || $amountRaw === "" || !is_numeric($amountRaw)) {
  redirect_edit($recordId, "error", "Please fill out all required fields correctly.", $returnTo);
}

if ($type === "Other" && $typeSpecify === "") {
  redirect_edit($recordId, "error", "Please specify the Type of Assistance when you choose Other.", $returnTo);
}

$amount = (float)$amountRaw;
if ($amount < 0) {
  redirect_edit($recordId, "error", "Amount must be 0 or higher.", $returnTo);
}

$ts = strtotime($recordDate);
if ($ts === false) {
  redirect_edit($recordId, "error", "Invalid date.", $returnTo);
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
  redirect_edit($recordId, "error", "Database error: " . $conn->error, $returnTo);
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
     SET name = ?, type = ?, type_specify = ?, barangay = ?, office_scope = ?, municipality = ?, province = ?, amount = ?, record_date = ?, month_year = ?, notes = ?
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
    redirect_edit($recordId, "error", "Database error: " . $conn->error, $returnTo);
  }

  if ($isOfficeScoped && $scopedBarangay !== "") {
    $stmt->bind_param(
      "sssssssdsssiss",
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
      $recordId,
      $scopedOffice,
      $scopedBarangay
    );
  } elseif ($isOfficeScoped) {
    $stmt->bind_param(
      "sssssssdsssis",
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
      $recordId,
      $scopedOffice
    );
  } elseif ($scopedBarangay !== "") {
    $stmt->bind_param(
      "sssssssdsssis",
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
      $recordId,
      $scopedBarangay
    );
  } else {
    $stmt->bind_param(
      "sssssssdsssi",
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
      $recordId
    );
  }
} else {
  $updateSql = "UPDATE records
     SET name = ?, type = ?, barangay = ?, office_scope = ?, municipality = ?, province = ?, amount = ?, record_date = ?, month_year = ?, notes = ?
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
    redirect_edit($recordId, "error", "Database error: " . $conn->error, $returnTo);
  }

  if ($isOfficeScoped && $scopedBarangay !== "") {
    $stmt->bind_param(
      "ssssssdsssiss",
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
      $recordId,
      $scopedOffice,
      $scopedBarangay
    );
  } elseif ($isOfficeScoped) {
    $stmt->bind_param(
      "ssssssdsssis",
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
      $recordId,
      $scopedOffice
    );
  } elseif ($scopedBarangay !== "") {
    $stmt->bind_param(
      "ssssssdsssis",
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
      $recordId,
      $scopedBarangay
    );
  } else {
    $stmt->bind_param(
      "ssssssdsssi",
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
      $recordId
    );
  }
}

if (!$stmt->execute()) {
  $error = $stmt->error;
  $stmt->close();
  redirect_edit($recordId, "error", "Error updating record: " . $error, $returnTo);
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
  header("Location: " . with_status_message($returnTo, "success", "Record updated successfully."));
  exit;
}

header("Location: " . with_status_message($returnTo, "success", "No changes were made."));
exit;
?>
