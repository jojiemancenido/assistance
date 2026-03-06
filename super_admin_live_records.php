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

function format_record_name_for_panel(string $fullName): string {
  [$last, $first, $middle, $extension] = split_record_name_parts($fullName);
  if ($last === "" && $first === "") {
    return normalize_name_piece($fullName);
  }
  $display = trim($last);
  if ($first !== "") {
    $display .= ($display !== "" ? ", " : "") . $first;
  }
  if ($middle !== "") {
    $display .= " " . strtoupper(substr(trim($middle), 0, 1)) . ".";
  }
  if ($extension !== "") {
    $display .= " " . $extension;
  }
  return trim($display);
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
  $fullName = (string)($row["name"] ?? "");
  $spec = "";
  if ($hasTypeSpecify) {
    $spec = trim((string)($row["type_specify"] ?? ""));
  } else {
    $spec = extract_specify_from_notes($notesVal);
  }

  $typeLabel = build_type_label((string)($row["type"] ?? ""), $spec);

  $items[] = [
    "record_id" => (string)($row["record_id"] ?? ""),
    "name" => $fullName,
    "name_display" => format_record_name_for_panel($fullName),
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
