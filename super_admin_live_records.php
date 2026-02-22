<?php
require_once "auth.php";
require_super_admin();
require_once "db.php";
secure_session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

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
  if (preg_match('/^\\[SPECIFY\\](.*?)\\[\\/SPECIFY\\]\\s*/s', $notes, $m)) {
    $spec = trim((string)$m[1]);
    $notes = (string)preg_replace('/^\\[SPECIFY\\].*?\\[\\/SPECIFY\\]\\s*/s', '', $notes);
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
  if (!isset($typeOptions[$t])) $typeOptions[$t] = ["type" => $t, "spec" => ""];
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

$sql = "SELECT $recordsCols FROM records";
if (!empty($saWhere)) {
  $sql .= " WHERE " . implode(" AND ", $saWhere);
}
$sql .= " ORDER BY $saOrderBy";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
  exit;
}

if (!empty($saParams)) {
  $stmt->bind_param($saParamTypes, ...$saParams);
}

$stmt->execute();
$rs = $stmt->get_result();

$items = [];
while ($row = $rs->fetch_assoc()) {
  $notesVal = (string)($row["notes"] ?? "");
  $spec = "";
  if ($hasTypeSpecify) {
    $spec = trim((string)($row["type_specify"] ?? ""));
  } else {
    $spec = extract_specify_from_notes($notesVal);
  }

  $typeLabel = build_type_label((string)($row["type"] ?? ""), $spec);

  $items[] = [
    "record_id" => (string)($row["record_id"] ?? ""),
    "name" => (string)($row["name"] ?? ""),
    "type_label" => $typeLabel,
    "barangay" => (string)($row["barangay"] ?? ""),
    "municipality" => (string)($row["municipality"] ?? ""),
    "province" => (string)($row["province"] ?? ""),
    "amount_display" => "PHP " . number_format((float)($row["amount"] ?? 0), 2),
    "record_date" => (string)($row["record_date"] ?? ""),
    "month_year" => (string)($row["month_year"] ?? ""),
    "notes" => $notesVal,
  ];
}

$stmt->close();

echo json_encode([
  "ok" => true,
  "items" => $items,
], JSON_UNESCAPED_UNICODE);
exit;
?>
