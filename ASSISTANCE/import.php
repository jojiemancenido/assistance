<?php
require_once "auth.php";
require_login();
require_once "db.php";

function redirect_with_status(string $status, string $message): void {
  header("Location: index.php?status=" . urlencode($status) . "&msg=" . urlencode($message));
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

function normalize_header(string $value): string {
  $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
  $value = strtolower(trim($value));
  $value = preg_replace('/[^a-z0-9]+/', '', $value);
  return $value ?? "";
}

function map_headers(array $headerRow): array {
  $aliases = [
    "name" => ["name", "fullname", "beneficiaryname"],
    "barangay" => ["barangay"],
    "type" => ["typeofassistance", "typeofassitance", "assistance", "assitance", "type"],
    "amount" => ["amount"],
    "date" => ["date", "recorddate"],
  ];

  $normalized = [];
  foreach ($headerRow as $i => $cell) {
    $normalized[(int)$i] = normalize_header((string)$cell);
  }

  $map = [];
  foreach ($aliases as $key => $values) {
    $map[$key] = -1;
    foreach ($normalized as $idx => $name) {
      if (in_array($name, $values, true)) {
        $map[$key] = $idx;
        break;
      }
    }
  }

  return $map;
}

function get_cell(array $row, int $index): string {
  return trim((string)($row[$index] ?? ""));
}

function detect_csv_delimiter(string $sample): string {
  $delimiters = [",", ";", "\t", "|"];
  $best = ",";
  $bestCount = -1;

  foreach ($delimiters as $delimiter) {
    $count = substr_count($sample, $delimiter);
    if ($count > $bestCount) {
      $bestCount = $count;
      $best = $delimiter;
    }
  }

  return $best;
}

function read_csv_rows(string $path): array {
  $handle = fopen($path, "rb");
  if (!$handle) {
    throw new RuntimeException("Cannot open CSV file.");
  }

  $sample = (string)fgets($handle);
  $delimiter = detect_csv_delimiter($sample);
  rewind($handle);

  $rows = [];
  while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    $rows[] = $row;
  }
  fclose($handle);

  return $rows;
}

function column_ref_to_index(string $cellRef): int {
  if (!preg_match('/^[A-Z]+/', strtoupper($cellRef), $m)) {
    return -1;
  }

  $letters = $m[0];
  $index = 0;
  for ($i = 0; $i < strlen($letters); $i++) {
    $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
  }
  return $index - 1;
}

function read_shared_strings(ZipArchive $zip): array {
  $xml = $zip->getFromName("xl/sharedStrings.xml");
  if ($xml === false) return [];

  $doc = @simplexml_load_string($xml);
  if (!$doc) return [];

  $doc->registerXPathNamespace("x", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
  $nodes = $doc->xpath("//*[local-name()='si']");
  if (!$nodes) return [];

  $strings = [];
  foreach ($nodes as $si) {
    $parts = $si->xpath(".//*[local-name()='t']");
    $text = "";
    if ($parts) {
      foreach ($parts as $t) {
        $text .= (string)$t;
      }
    }
    $strings[] = $text;
  }
  return $strings;
}

function first_sheet_path(ZipArchive $zip): string {
  $workbookXml = $zip->getFromName("xl/workbook.xml");
  $relsXml = $zip->getFromName("xl/_rels/workbook.xml.rels");
  if ($workbookXml === false || $relsXml === false) {
    return "xl/worksheets/sheet1.xml";
  }

  $workbook = @simplexml_load_string($workbookXml);
  $rels = @simplexml_load_string($relsXml);
  if (!$workbook || !$rels) {
    return "xl/worksheets/sheet1.xml";
  }

  $workbook->registerXPathNamespace("x", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
  $sheets = $workbook->xpath("//*[local-name()='sheets']/*[local-name()='sheet']");
  if (!$sheets || !isset($sheets[0])) {
    return "xl/worksheets/sheet1.xml";
  }

  $ridAttrs = $sheets[0]->attributes("http://schemas.openxmlformats.org/officeDocument/2006/relationships");
  $rid = (string)($ridAttrs["id"] ?? "");
  if ($rid === "") {
    return "xl/worksheets/sheet1.xml";
  }

  $rels->registerXPathNamespace("r", "http://schemas.openxmlformats.org/package/2006/relationships");
  $matches = $rels->xpath("//*[local-name()='Relationship' and @Id='" . $rid . "']");
  if (!$matches || !isset($matches[0])) {
    return "xl/worksheets/sheet1.xml";
  }

  $target = (string)($matches[0]["Target"] ?? "");
  if ($target === "") {
    return "xl/worksheets/sheet1.xml";
  }

  if (substr($target, 0, 1) === "/") {
    return ltrim($target, "/");
  }

  return "xl/" . ltrim($target, "/");
}

function read_xlsx_rows(string $path): array {
  if (!class_exists("ZipArchive")) {
    throw new RuntimeException("XLSX import needs PHP Zip extension.");
  }

  $zip = new ZipArchive();
  if ($zip->open($path) !== true) {
    throw new RuntimeException("Cannot open XLSX file.");
  }

  $sheetPath = first_sheet_path($zip);
  $sheetXml = $zip->getFromName($sheetPath);
  if ($sheetXml === false) {
    $zip->close();
    throw new RuntimeException("Cannot read first worksheet from XLSX.");
  }

  $sharedStrings = read_shared_strings($zip);
  $zip->close();

  $sheet = @simplexml_load_string($sheetXml);
  if (!$sheet) {
    throw new RuntimeException("Invalid worksheet XML.");
  }

  $sheet->registerXPathNamespace("x", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
  $rows = $sheet->xpath("//*[local-name()='sheetData']/*[local-name()='row']");
  if (!$rows) return [];

  $output = [];
  foreach ($rows as $rowNode) {
    $cells = $rowNode->xpath("./*[local-name()='c']");
    if (!$cells) {
      $output[] = [];
      continue;
    }

    $row = [];
    $nextIndex = 0;
    foreach ($cells as $cell) {
      $cellRef = (string)($cell["r"] ?? "");
      $idx = $cellRef !== "" ? column_ref_to_index($cellRef) : $nextIndex;
      if ($idx < 0) $idx = $nextIndex;

      $type = (string)($cell["t"] ?? "");
      $value = "";

      if ($type === "inlineStr") {
        $parts = $cell->xpath(".//*[local-name()='t']");
        if ($parts) {
          foreach ($parts as $part) {
            $value .= (string)$part;
          }
        }
      } else {
        $raw = (string)($cell->v ?? "");
        if ($type === "s") {
          $ssIndex = (int)$raw;
          $value = (string)($sharedStrings[$ssIndex] ?? "");
        } else {
          $value = $raw;
        }
      }

      $row[$idx] = $value;
      $nextIndex = $idx + 1;
    }

    if (!empty($row)) {
      ksort($row);
      $max = max(array_keys($row));
      $dense = [];
      for ($i = 0; $i <= $max; $i++) {
        $dense[$i] = (string)($row[$i] ?? "");
      }
      $output[] = $dense;
    } else {
      $output[] = [];
    }
  }

  return $output;
}

function normalize_type(string $raw): array {
  $value = trim(preg_replace('/\s+/', ' ', $raw) ?? "");
  if ($value === "") {
    return ["", ""];
  }

  if (preg_match('/^other\s*[:\-]\s*(.+)$/i', $value, $m)) {
    return ["Other", trim((string)$m[1])];
  }

  $key = strtolower($value);
  $known = [
    "medical" => "Medical",
    "burial" => "Burial",
    "livelihood" => "Livelihood",
    "other" => "Other",
  ];

  if (isset($known[$key])) {
    return [$known[$key], ""];
  }

  // Unknown values become Other + specify value
  return ["Other", $value];
}

function normalize_date(string $raw): ?string {
  $value = trim($raw);
  if ($value === "") {
    return null;
  }

  // Excel serial date (works for CSV and XLSX numeric dates)
  if (is_numeric($value)) {
    $serial = (float)$value;
    if ($serial > 0) {
      $days = (int)floor($serial);
      $base = new DateTimeImmutable("1899-12-30", new DateTimeZone("UTC"));
      $dt = $base->modify("+" . $days . " days");
      if ($dt instanceof DateTimeImmutable) {
        return $dt->format("Y-m-d");
      }
    }
  }

  $formats = [
    "Y-m-d",
    "m/d/Y",
    "d/m/Y",
    "n/j/Y",
    "j/n/Y",
    "m-d-Y",
    "d-m-Y",
    "Y/m/d",
    "M j, Y",
    "F j, Y",
  ];
  foreach ($formats as $fmt) {
    $dt = DateTimeImmutable::createFromFormat($fmt, $value);
    if ($dt instanceof DateTimeImmutable) {
      return $dt->format("Y-m-d");
    }
  }

  $ts = strtotime($value);
  if ($ts === false) return null;
  return date("Y-m-d", $ts);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  redirect_with_status("error", "Invalid import request.");
}

$csrfToken = $_POST["csrf_token"] ?? "";
if (!verify_csrf_token($csrfToken)) {
  redirect_with_status("error", "Invalid request token. Please refresh and try again.");
}

if (!isset($_FILES["import_file"])) {
  redirect_with_status("error", "Please choose a file to import.");
}

$file = $_FILES["import_file"];
$err = (int)($file["error"] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
  redirect_with_status("error", "File upload failed. Error code: " . $err);
}

$name = (string)($file["name"] ?? "");
$tmp = (string)($file["tmp_name"] ?? "");
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ["csv", "xlsx"], true)) {
  redirect_with_status("error", "Only .csv and .xlsx files are supported.");
}

try {
  $rows = ($ext === "csv") ? read_csv_rows($tmp) : read_xlsx_rows($tmp);
} catch (Throwable $e) {
  redirect_with_status("error", "Import failed: " . $e->getMessage());
}

if (count($rows) < 2) {
  redirect_with_status("error", "Import file has no data rows.");
}

$headerMap = map_headers($rows[0]);
$missing = [];
foreach (["name", "barangay", "type", "amount", "date"] as $requiredKey) {
  if (($headerMap[$requiredKey] ?? -1) < 0) {
    $missing[] = strtoupper($requiredKey);
  }
}
if (!empty($missing)) {
  redirect_with_status("error", "Missing required header(s): " . implode(", ", $missing));
}

$hasTypeSpecify = has_column($conn, "records", "type_specify");
$municipality = "Daet";
$province = "Camarines Norte";

if ($hasTypeSpecify) {
  $stmt = $conn->prepare(
    "INSERT INTO records (name, type, type_specify, barangay, municipality, province, amount, record_date, month_year, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );
} else {
  $stmt = $conn->prepare(
    "INSERT INTO records (name, type, barangay, municipality, province, amount, record_date, month_year, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );
}

if (!$stmt) {
  redirect_with_status("error", "Database error: " . $conn->error);
}

$imported = 0;
$failed = 0;
$skipped = 0;
$errorRows = [];

for ($i = 1; $i < count($rows); $i++) {
  $row = $rows[$i];
  $rowNumber = $i + 1;

  $nameVal = get_cell($row, $headerMap["name"]);
  $barangayVal = get_cell($row, $headerMap["barangay"]);
  $typeRaw = get_cell($row, $headerMap["type"]);
  $amountRaw = get_cell($row, $headerMap["amount"]);
  $dateRaw = get_cell($row, $headerMap["date"]);

  if ($nameVal === "" && $barangayVal === "" && $typeRaw === "" && $amountRaw === "" && $dateRaw === "") {
    $skipped++;
    continue;
  }

  [$typeVal, $typeSpecifyVal] = normalize_type($typeRaw);
  $dateVal = normalize_date($dateRaw);

  $amountClean = str_replace(",", "", $amountRaw);
  $amountClean = preg_replace('/[^0-9.\-]/', '', $amountClean) ?? "";

  if ($nameVal === "" || $barangayVal === "" || $typeVal === "" || $amountClean === "" || !is_numeric($amountClean) || $dateVal === null) {
    $failed++;
    if (count($errorRows) < 5) {
      $errorRows[] = "row " . $rowNumber;
    }
    continue;
  }

  $amountVal = (float)$amountClean;
  if ($amountVal < 0) {
    $failed++;
    if (count($errorRows) < 5) {
      $errorRows[] = "row " . $rowNumber;
    }
    continue;
  }

  $monthYear = date("Y-m", strtotime($dateVal));
  $notes = "Imported via file upload";

  if ($hasTypeSpecify) {
    $typeSpecifySave = ($typeVal === "Other") ? $typeSpecifyVal : "";
    $stmt->bind_param(
      "ssssssdsss",
      $nameVal,
      $typeVal,
      $typeSpecifySave,
      $barangayVal,
      $municipality,
      $province,
      $amountVal,
      $dateVal,
      $monthYear,
      $notes
    );
  } else {
    if ($typeVal === "Other" && $typeSpecifyVal !== "") {
      $notes = "[SPECIFY]" . $typeSpecifyVal . "[/SPECIFY]\n" . $notes;
    }
    $stmt->bind_param(
      "sssssdsss",
      $nameVal,
      $typeVal,
      $barangayVal,
      $municipality,
      $province,
      $amountVal,
      $dateVal,
      $monthYear,
      $notes
    );
  }

  if ($stmt->execute()) {
    $imported++;
  } else {
    $failed++;
    if (count($errorRows) < 5) {
      $errorRows[] = "row " . $rowNumber;
    }
  }
}

$stmt->close();

if ($imported === 0) {
  $suffix = empty($errorRows) ? "" : " Invalid data in " . implode(", ", $errorRows) . ".";
  $actor = current_auth_user();
  audit_log(
    "records_import_failed",
    "Import failed. No rows imported from file \"" . $name . "\".",
    $actor !== "" ? $actor : null,
    null
  );
  redirect_with_status("error", "No rows were imported." . $suffix);
}

$message = "Import complete: " . $imported . " row(s) added.";
if ($skipped > 0) {
  $message .= " Skipped empty rows: " . $skipped . ".";
}
if ($failed > 0) {
  $message .= " Failed rows: " . $failed . ".";
  if (!empty($errorRows)) {
    $message .= " Check " . implode(", ", $errorRows) . ".";
  }
}

$actor = current_auth_user();
audit_log(
  "records_import",
  "Imported " . $imported . " row(s) from file \"" . $name . "\". Failed: " . $failed . ", Skipped: " . $skipped . ".",
  $actor !== "" ? $actor : null,
  null
);

redirect_with_status("success", $message);
?>
