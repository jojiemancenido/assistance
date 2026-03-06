<?php
require_once "auth.php";
require_once "db.php";
secure_session_start();

header("Content-Type: application/json; charset=utf-8");

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

$q = trim((string)($_GET["q"] ?? ""));
$typeFilter = trim((string)($_GET["type"] ?? ""));
$barangayFilter = effective_barangay_filter(trim((string)($_GET["barangay"] ?? "")));
$municipalityFilter = trim((string)($_GET["municipality"] ?? ""));
$sort = trim((string)($_GET["sort"] ?? "new"));
$limit = (int)($_GET["limit"] ?? 0);
$scopedOffice = current_scoped_office();
$isOfficeScoped = ($scopedOffice !== "");
$isMaifDashboard = is_maif_office_scope($scopedOffice);
$useLimit = ($limit > 0);
if ($limit > 1000) $limit = 1000;

$hasTypeSpecify = has_column($conn, "records", "type_specify");
$typeSortExpr = "type";
if ($hasTypeSpecify) {
  $typeSortExpr = "CASE WHEN type = 'Other' AND type_specify IS NOT NULL AND type_specify <> '' THEN CONCAT('Other: ', type_specify) ELSE type END";
}

$allowedSorts = [
  "new" => "record_id DESC",
  "old" => "record_id ASC",
  "date_new" => "record_date DESC, record_id DESC",
  "date_old" => "record_date ASC, record_id DESC",
  "month_year_new" => "month_year DESC, record_date DESC, record_id DESC",
  "month_year_old" => "month_year ASC, record_date ASC, record_id ASC",
  "name" => "name ASC, record_id DESC",
  "municipality_az" => "municipality ASC, record_id DESC",
  "municipality_za" => "municipality DESC, record_id DESC",
  "type" => $typeSortExpr . " ASC, record_id DESC",
  "amount_desc" => "amount DESC, record_id DESC",
  "amount_asc" => "amount ASC, record_id DESC",
];
$orderBy = $allowedSorts[$sort] ?? $allowedSorts["new"];

$where = [];
$paramTypes = "";
$params = [];
$isCrossOfficeSearch = ($q !== "");

if ($isOfficeScoped && !$isCrossOfficeSearch) {
  $where[] = "office_scope = ?";
  $paramTypes .= "s";
  $params[] = $scopedOffice;
}

if ($q !== "") {
  $searchTerm = "%" . $q . "%";
  $where[] = "(name LIKE ? OR barangay LIKE ? OR municipality LIKE ? OR office_scope LIKE ? OR notes LIKE ? OR contact_number LIKE ? OR diagnosis LIKE ? OR hospital LIKE ? OR contact_person LIKE ?)";
  $paramTypes .= "sssssssss";
  array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

if ($typeFilter !== "") {
  if (substr($typeFilter, 0, 7) === "Other: ") {
    $spec = trim(substr($typeFilter, 7));
    $where[] = "type = 'Other'";
    if ($spec !== "") {
      if ($hasTypeSpecify) {
        $where[] = "type_specify = ?";
        $paramTypes .= "s";
        $params[] = $spec;
      } else {
        $where[] = "notes LIKE ?";
        $paramTypes .= "s";
        $params[] = "[SPECIFY]" . $spec . "[/SPECIFY]%";
      }
    }
  } else {
    $where[] = "type = ?";
    $paramTypes .= "s";
    $params[] = $typeFilter;
  }
}

if ($barangayFilter !== "") {
  $where[] = "barangay = ?";
  $paramTypes .= "s";
  $params[] = $barangayFilter;
}

if ($municipalityFilter !== "") {
  $where[] = "municipality = ?";
  $paramTypes .= "s";
  $params[] = $municipalityFilter;
}

$selectCols = "record_id, name, type, barangay, office_scope, municipality, province, amount, record_date, month_year, notes";
if ($isMaifDashboard) {
  $selectCols .= ", age, birthdate, contact_number, diagnosis, hospital, contact_person";
}
if ($hasTypeSpecify) {
  $selectCols .= ", type_specify";
}

$sql = "SELECT $selectCols FROM records";
if (!empty($where)) {
  $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY $orderBy";
if ($useLimit) {
  $sql .= " LIMIT $limit";
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database error"]);
  exit;
}

if (!empty($params)) {
  $stmt->bind_param($paramTypes, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
  $notesVal = (string)($row["notes"] ?? "");
  $fullName = (string)($row["name"] ?? "");
  $spec = "";
  if ($hasTypeSpecify) {
    $spec = trim((string)($row["type_specify"] ?? ""));
  } else {
    $spec = extract_specify_from_notes($notesVal);
  }

  $typeLabel = (string)($row["type"] ?? "");
  if ($typeLabel === "Other" && $spec !== "") {
    $typeLabel = "Other (" . $spec . ")";
  }

  $items[] = [
    "record_id" => (string)($row["record_id"] ?? ""),
    "name" => $fullName,
    "name_full" => $fullName,
    "name_display" => format_record_name_for_panel($fullName),
    "type_label" => $typeLabel,
    "barangay" => (string)($row["barangay"] ?? ""),
    "office_display" => ((string)($row["office_scope"] ?? "")) === "maif" ? "MAIF" : ucfirst((string)($row["office_scope"] ?? "municipality")),
    "municipality" => (string)($row["municipality"] ?? ""),
    "province" => (string)($row["province"] ?? ""),
    "age" => (string)($row["age"] ?? ""),
    "birthdate" => (string)($row["birthdate"] ?? ""),
    "contact_number" => (string)($row["contact_number"] ?? ""),
    "diagnosis" => $isMaifDashboard ? (string)($row["diagnosis"] ?? "") : "",
    "hospital" => (string)($row["hospital"] ?? ""),
    "contact_person" => (string)($row["contact_person"] ?? ""),
    "amount_display" => "PHP " . number_format((float)($row["amount"] ?? 0), 2),
    "record_date" => (string)($row["record_date"] ?? ""),
    "month_year" => (string)($row["month_year"] ?? ""),
    "notes" => $notesVal,
  ];
}

$stmt->close();
echo json_encode(["ok" => true, "items" => $items], JSON_UNESCAPED_UNICODE);
exit;
?>
