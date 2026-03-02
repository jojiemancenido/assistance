<?php
require_once "auth.php";
require_once "db.php";
secure_session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!is_logged_in()) {
  http_response_code(401);
  echo json_encode(["ok" => false, "message" => "Unauthorized"]);
  exit;
}

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

$scopedBarangay = current_scoped_barangay();
$isBarangayScoped = ($scopedBarangay !== "");
$scopedOffice = current_scoped_office();
$isOfficeScoped = ($scopedOffice !== "");
$isMaifDashboard = is_maif_office_scope($scopedOffice);

$totalRecords = 0;
$countSql = "SELECT COUNT(*) AS total_records FROM records";
$countWhere = [];
$countTypes = "";
$countParams = [];
if ($isOfficeScoped) {
  $countWhere[] = "office_scope = ?";
  $countTypes .= "s";
  $countParams[] = $scopedOffice;
}
if ($isBarangayScoped) {
  $countWhere[] = "barangay = ?";
  $countTypes .= "s";
  $countParams[] = $scopedBarangay;
}
if (!empty($countWhere)) {
  $countSql .= " WHERE " . implode(" AND ", $countWhere);
}
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
  if (!empty($countParams)) {
    $countStmt->bind_param($countTypes, ...$countParams);
  }
  $countStmt->execute();
  $res = $countStmt->get_result();
  if ($res && $row = $res->fetch_assoc()) {
    $totalRecords = (int)($row["total_records"] ?? 0);
  }
  $countStmt->close();
}

cleanup_expired_active_sessions();

$activeUsers = [];
$activeUsersCount = 0;
if (ensure_active_session_table()) {
  $countRs = @$conn->query("SELECT COUNT(*) AS total_active FROM auth_active_sessions WHERE expires_at >= UTC_TIMESTAMP()");
  if ($countRs && $countRow = $countRs->fetch_assoc()) {
    $activeUsersCount = (int)($countRow["total_active"] ?? 0);
  }

  $listRs = @$conn->query(
    "SELECT username, last_seen
     FROM auth_active_sessions
     WHERE expires_at >= UTC_TIMESTAMP()
     ORDER BY username ASC
     LIMIT 50"
  );
  if ($listRs) {
    $currentUser = current_auth_user();
    while ($listRow = $listRs->fetch_assoc()) {
      $username = (string)($listRow["username"] ?? "");
      $activeUsers[] = [
        "username" => $username,
        "is_me" => ($username !== "" && $username === $currentUser),
        "last_seen_display" => format_utc_datetime_for_app((string)($listRow["last_seen"] ?? "")),
      ];
    }
  }
}

$types = $isMaifDashboard ? ["Medical"] : ["Medical", "Burial", "Livelihood", "Other"];
$typeTotals = [];
$hasTypeSpecify = has_column($conn, "records", "type_specify");
$scanSql = "SELECT type, notes";
if ($hasTypeSpecify) {
  $scanSql .= ", type_specify";
}
$scanSql .= " FROM records";
$scanTypes = "";
$scanParams = [];
if ($isOfficeScoped) {
  $scanSql .= " WHERE office_scope = ?";
  $scanTypes .= "s";
  $scanParams[] = $scopedOffice;
}
if ($isBarangayScoped) {
  $scanSql .= ($isOfficeScoped ? " AND " : " WHERE ") . "barangay = ?";
  $scanTypes .= "s";
  $scanParams[] = $scopedBarangay;
}
$scanStmt = $conn->prepare($scanSql);
$scanRs = null;
if ($scanStmt) {
  if (!empty($scanParams)) {
    $scanStmt->bind_param($scanTypes, ...$scanParams);
  }
  $scanStmt->execute();
  $scanRs = $scanStmt->get_result();
}
if ($scanRs) {
  while ($row = $scanRs->fetch_assoc()) {
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
  }
}
if ($scanStmt) {
  $scanStmt->close();
}

foreach ($types as $t) {
  if (!isset($typeTotals[$t])) {
    $typeTotals[$t] = 0;
  }
}

$orderedTypeLabels = [];
foreach ($types as $t) {
  if (isset($typeTotals[$t])) {
    $orderedTypeLabels[] = $t;
  }
}
$extraLabels = array_values(array_diff(array_keys($typeTotals), $orderedTypeLabels));
natcasesort($extraLabels);
foreach ($extraLabels as $label) {
  $orderedTypeLabels[] = $label;
}

$summaryTypeItems = [];
foreach ($orderedTypeLabels as $label) {
  $summaryTypeItems[] = [
    "label" => $label,
    "count" => (int)($typeTotals[$label] ?? 0),
  ];
}

echo json_encode([
  "ok" => true,
  "total_records" => $totalRecords,
  "active_users_count" => $activeUsersCount,
  "active_users" => $activeUsers,
  "summary_type_items" => $summaryTypeItems,
  "timezone_label" => app_timezone_label()
], JSON_UNESCAPED_UNICODE);
exit;
?>
