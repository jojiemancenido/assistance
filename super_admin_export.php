<?php
require_once "auth.php";
require_super_admin();
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

function safe_excel_cell(string $value): string {
  $trimmed = ltrim($value);
  if ($trimmed !== "" && in_array($trimmed[0], ["=", "+", "-", "@"], true)) {
    return "'" . $value;
  }
  return $value;
}

function normalize_pdf_text(string $value): string {
  $text = str_replace(["\r\n", "\r", "\n", "\t"], " ", $value);
  $text = trim((string)preg_replace('/\s+/', ' ', $text));
  if (function_exists("iconv")) {
    $conv = @iconv("UTF-8", "windows-1252//TRANSLIT//IGNORE", $text);
    if ($conv !== false) $text = $conv;
  }
  return (string)preg_replace('/[^\x20-\x7E\x80-\xFF]/', '?', $text);
}

function pdf_escape(string $value): string {
  $value = normalize_pdf_text($value);
  return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $value);
}

function pdf_wrap_text(string $text, int $maxChars = 95): array {
  $text = normalize_pdf_text($text);
  if ($text === "") return [""];
  $words = preg_split('/\s+/', $text) ?: [];
  $lines = [];
  $line = "";
  foreach ($words as $word) {
    if ($line === "") {
      $line = $word;
      continue;
    }
    $candidate = $line . " " . $word;
    if (strlen($candidate) <= $maxChars) {
      $line = $candidate;
    } else {
      $lines[] = $line;
      $line = $word;
    }
  }
  if ($line !== "") $lines[] = $line;
  return !empty($lines) ? $lines : [""];
}

function pdf_build_document(array $pages): string {
  if (empty($pages)) {
    $pages = [["No data."]];
  }

  $objects = [];
  $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
  $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

  $kids = [];
  $nextObj = 4;
  foreach ($pages as $pageLines) {
    $content = "BT\n/F1 9 Tf\n12 TL\n36 806 Td\n";
    foreach ($pageLines as $line) {
      $content .= "(" . pdf_escape((string)$line) . ") Tj\nT*\n";
    }
    $content .= "ET";

    $contentObj = $nextObj++;
    $objects[$contentObj] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

    $pageObj = $nextObj++;
    $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents " . $contentObj . " 0 R >>";
    $kids[] = $pageObj . " 0 R";
  }

  $objects[2] = "<< /Type /Pages /Kids [ " . implode(" ", $kids) . " ] /Count " . count($kids) . " >>";
  ksort($objects);

  $pdf = "%PDF-1.4\n";
  $offsets = [0];
  $maxObj = max(array_keys($objects));
  for ($i = 1; $i <= $maxObj; $i++) {
    $offsets[$i] = strlen($pdf);
    $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
  }

  $xrefPos = strlen($pdf);
  $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
  $pdf .= "0000000000 65535 f \n";
  for ($i = 1; $i <= $maxObj; $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
  }
  $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\n";
  $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";
  return $pdf;
}

$format = strtolower(trim((string)($_GET["format"] ?? "")));
if (!in_array($format, ["excel", "pdf"], true)) {
  http_response_code(400);
  echo "Invalid export format.";
  exit;
}

$hasTypeSpecify = has_column($conn, "records", "type_specify");
$sql = "SELECT record_id, name, type, barangay, municipality, province, amount, record_date, month_year, notes";
if ($hasTypeSpecify) {
  $sql .= ", type_specify";
}
$sql .= " FROM records ORDER BY record_id ASC";
$rs = @$conn->query($sql);

$rows = [];
if ($rs) {
  while ($r = $rs->fetch_assoc()) {
    $notesVal = (string)($r["notes"] ?? "");
    $spec = "";
    if ($hasTypeSpecify) {
      $spec = trim((string)($r["type_specify"] ?? ""));
    } else {
      $spec = extract_specify_from_notes($notesVal);
    }

    $rows[] = [
      "record_id" => (string)($r["record_id"] ?? ""),
      "name" => (string)($r["name"] ?? ""),
      "type_label" => build_type_label((string)($r["type"] ?? ""), $spec),
      "barangay" => (string)($r["barangay"] ?? ""),
      "municipality" => (string)($r["municipality"] ?? ""),
      "province" => (string)($r["province"] ?? ""),
      "amount" => (float)($r["amount"] ?? 0),
      "record_date" => (string)($r["record_date"] ?? ""),
      "month_year" => (string)($r["month_year"] ?? ""),
      "notes" => $notesVal,
    ];
  }
}

$selectedTypesRaw = $_GET["types"] ?? [];
$selectedTypes = [];
if (is_array($selectedTypesRaw)) {
  foreach ($selectedTypesRaw as $typeLabel) {
    $label = trim((string)$typeLabel);
    if ($label === "") continue;
    $selectedTypes[$label] = true;
  }
} elseif (is_string($selectedTypesRaw) && trim($selectedTypesRaw) !== "") {
  $label = trim($selectedTypesRaw);
  $selectedTypes[$label] = true;
}

if (!empty($selectedTypes)) {
  $rows = array_values(array_filter($rows, function(array $row) use ($selectedTypes): bool {
    $label = (string)($row["type_label"] ?? "");
    return isset($selectedTypes[$label]);
  }));
}

$selectedTypeLabels = array_keys($selectedTypes);
$selectedTypesLabel = empty($selectedTypeLabels) ? "All types" : implode(", ", $selectedTypeLabels);
$stamp = date("Ymd_His");

if ($format === "excel") {
  header("Content-Type: application/vnd.ms-excel; charset=utf-8");
  header("Content-Disposition: attachment; filename=\"superadmin_records_" . $stamp . ".xls\"");
  header("Cache-Control: max-age=0");
  header("Pragma: public");
  echo "\xEF\xBB\xBF";
  ?>
  <html>
  <head>
    <meta charset="utf-8" />
    <style>
      table { border-collapse: collapse; width: 100%; }
      th, td { border: 1px solid #cbd5e1; padding: 6px 8px; font-size: 12px; }
      th { background: #eef2ff; text-align: left; }
      .meta { margin-bottom: 10px; font-size: 12px; color: #334155; }
    </style>
  </head>
  <body>
    <div class="meta">
      <strong>Super Admin Assistance Records Export</strong><br />
      Generated: <?php echo htmlspecialchars(date("Y-m-d h:i:s A") . " " . app_timezone_label()); ?><br />
      Types: <?php echo htmlspecialchars($selectedTypesLabel); ?><br />
      Total rows: <?php echo number_format((float)count($rows)); ?>
    </div>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>Type</th>
          <th>Barangay</th>
          <th>Municipality</th>
          <th>Province</th>
          <th>Amount</th>
          <th>Date</th>
          <th>Month-Year</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($rows)): ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars(safe_excel_cell((string)$r["record_id"])); ?></td>
              <td><?php echo htmlspecialchars(safe_excel_cell((string)$r["name"])); ?></td>
              <td><?php echo htmlspecialchars(safe_excel_cell((string)$r["type_label"])); ?></td>
              <td><?php echo htmlspecialchars(safe_excel_cell((string)$r["barangay"])); ?></td>
              <td><?php echo htmlspecialchars(safe_excel_cell((string)$r["municipality"])); ?></td>
              <td><?php echo htmlspecialchars(safe_excel_cell((string)$r["province"])); ?></td>
              <td><?php echo htmlspecialchars("PHP " . number_format((float)$r["amount"], 2)); ?></td>
              <td><?php echo htmlspecialchars(safe_excel_cell((string)$r["record_date"])); ?></td>
              <td><?php echo htmlspecialchars(safe_excel_cell((string)$r["month_year"])); ?></td>
              <td><?php echo htmlspecialchars(safe_excel_cell((string)$r["notes"])); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="10">No records found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </body>
  </html>
  <?php
  exit;
}

$lines = [];
$lines[] = "Super Admin Assistance Records Export";
$lines[] = "Generated: " . date("Y-m-d h:i:s A") . " " . app_timezone_label();
$lines[] = "Types: " . $selectedTypesLabel;
$lines[] = "Total rows: " . number_format((float)count($rows));
$lines[] = str_repeat("=", 96);

if (empty($rows)) {
  $lines[] = "No records found.";
} else {
  foreach ($rows as $r) {
    $lines[] = "ID #" . $r["record_id"] . " - " . normalize_pdf_text((string)$r["name"]);
    $lines[] = "Type: " . normalize_pdf_text((string)$r["type_label"]) .
               " | Amount: PHP " . number_format((float)$r["amount"], 2) .
               " | Date: " . normalize_pdf_text((string)$r["record_date"]);
    $lines[] = "Location: " . normalize_pdf_text((string)$r["barangay"]) . ", " .
               normalize_pdf_text((string)$r["municipality"]) . ", " .
               normalize_pdf_text((string)$r["province"]);
    $lines[] = "Month-Year: " . normalize_pdf_text((string)$r["month_year"]);
    $notes = trim((string)$r["notes"]);
    $wrappedNotes = pdf_wrap_text("Notes: " . ($notes !== "" ? $notes : "-"), 95);
    foreach ($wrappedNotes as $noteLine) {
      $lines[] = $noteLine;
    }
    $lines[] = str_repeat("-", 96);
  }
}

$maxLinesPerPage = 52;
$pages = [];
$current = [];
foreach ($lines as $line) {
  if (count($current) >= $maxLinesPerPage) {
    $pages[] = $current;
    $current = [];
  }
  $current[] = $line;
}
if (!empty($current)) {
  $pages[] = $current;
}

$pdf = pdf_build_document($pages);
header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=\"superadmin_records_" . $stamp . ".pdf\"");
header("Cache-Control: private, max-age=0, must-revalidate");
header("Pragma: public");
echo $pdf;
exit;
?>
