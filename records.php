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
$sort = trim((string)($_GET["sort"] ?? "new"));
$scopedBarangay = current_scoped_barangay();
$isBarangayScoped = ($scopedBarangay !== "");
$scopedOffice = current_scoped_office();
$isOfficeScoped = ($scopedOffice !== "");

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
  "type" => $typeSortExpr . " ASC, record_id DESC",
  "amount_desc" => "amount DESC, record_id DESC",
  "amount_asc" => "amount ASC, record_id DESC",
];
$orderBy = $allowedSorts[$sort] ?? $allowedSorts["new"];

$where = [];
$paramTypes = "";
$params = [];

if ($isOfficeScoped) {
  $where[] = "office_scope = ?";
  $paramTypes .= "s";
  $params[] = $scopedOffice;
}

if ($q !== "") {
  $where[] = "name LIKE ?";
  $paramTypes .= "s";
  $params[] = "%" . $q . "%";
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

if ($isBarangayScoped) {
  $where[] = "barangay = ?";
  $paramTypes .= "s";
  $params[] = $scopedBarangay;
}

$selectCols = "record_id, name, type, barangay, amount, record_date, month_year, notes";
if ($hasTypeSpecify) {
  $selectCols .= ", type_specify";
}

$sql = "SELECT $selectCols FROM records";
if (!empty($where)) {
  $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY $orderBy";

$records = null;
$stmt = $conn->prepare($sql);
if ($stmt) {
  if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
  }
  $stmt->execute();
  $records = $stmt->get_result();
}

$typeLabels = [];
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
    $notesVal = (string)($row["notes"] ?? "");
    $spec = $hasTypeSpecify ? trim((string)($row["type_specify"] ?? "")) : extract_specify_from_notes($notesVal);
    $label = build_type_label((string)($row["type"] ?? ""), $spec);
    if ($label !== "") {
      $typeLabels[$label] = true;
    }
  }
}
if ($scanStmt) {
  $scanStmt->close();
}
$typeLabels = array_keys($typeLabels);
natcasesort($typeLabels);

$recordCount = ($records && method_exists($records, 'num_rows')) ? (int)$records->num_rows : 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>All Records</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body class="records-fullscreen">
  <div class="records-shell">
    <header class="app-header">
      <div class="brand-text">
        <h1>All Records</h1>
        <p>Fixed full-height view (100vh)</p>
      </div>
      <div class="header-meta">
        <?php if (is_super_admin()): ?>
          <a class="btn btn--secondary btn--sm" href="logs.php">Logs</a>
        <?php endif; ?>
        <a
          class="btn btn--secondary btn--sm"
          href="index.php#records-section"
          onclick="if (window.history.length > 1) { window.history.back(); return false; }"
        >Back</a>
        <a class="btn btn--secondary btn--sm" href="index.php">Back to Dashboard</a>
      </div>
    </header>

    <section class="card section records-card">
      <div class="card__header card__header--row">
        <div>
          <h2 class="card__title">Records Table</h2>
          <p class="card__sub">Showing <?php echo number_format($recordCount); ?> record(s).</p>
        </div>

        <form class="search" method="GET" action="records.php">
          <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search by name..." autocomplete="off" />

          <select name="type">
            <option value="">All Types</option>
            <?php foreach ($typeLabels as $label): ?>
              <option value="<?php echo htmlspecialchars((string)$label); ?>" <?php echo ($typeFilter === (string)$label) ? "selected" : ""; ?>>
                <?php echo htmlspecialchars((string)$label); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="sort">
            <option value="new" <?php echo $sort === "new" ? "selected" : ""; ?>>Newest</option>
            <option value="old" <?php echo $sort === "old" ? "selected" : ""; ?>>Oldest</option>
            <option value="date_new" <?php echo $sort === "date_new" ? "selected" : ""; ?>>Date (new to old)</option>
            <option value="date_old" <?php echo $sort === "date_old" ? "selected" : ""; ?>>Date (old to new)</option>
            <option value="month_year_new" <?php echo $sort === "month_year_new" ? "selected" : ""; ?>>Month-Year (new to old)</option>
            <option value="month_year_old" <?php echo $sort === "month_year_old" ? "selected" : ""; ?>>Month-Year (old to new)</option>
            <option value="name" <?php echo $sort === "name" ? "selected" : ""; ?>>Name (A to Z)</option>
            <option value="type" <?php echo $sort === "type" ? "selected" : ""; ?>>Type (A to Z)</option>
            <option value="amount_desc" <?php echo $sort === "amount_desc" ? "selected" : ""; ?>>Amount (high to low)</option>
            <option value="amount_asc" <?php echo $sort === "amount_asc" ? "selected" : ""; ?>>Amount (low to high)</option>
          </select>

          <button class="btn btn--sm" type="submit">Apply</button>
          <?php if ($q !== "" || $typeFilter !== "" || $sort !== "new"): ?>
            <a class="btn btn--secondary btn--sm" href="records.php">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="card__body records-card__body">
        <div class="table-wrap records-table-wrap">
          <div class="table-scroll records-table-scroll">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Barangay</th>
                  <th>Amount</th>
                  <th>Date</th>
                  <th>Month-Year</th>
                  <th>Notes</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($records && $records->num_rows > 0): ?>
                  <?php while ($r = $records->fetch_assoc()): ?>
                    <?php
                      $notesVal = (string)($r["notes"] ?? "");
                      $displayName = format_record_name_for_panel((string)($r["name"] ?? ""));
                      $spec = "";
                      if ($hasTypeSpecify) {
                        $spec = trim((string)($r["type_specify"] ?? ""));
                      } else {
                        $spec = extract_specify_from_notes($notesVal);
                      }
                      $typeLabel = (string)($r["type"] ?? "");
                      if ($typeLabel === "Other" && $spec !== "") {
                        $typeLabel .= " (" . $spec . ")";
                      }
                    ?>
                    <tr>
                      <td class="mono"><?php echo htmlspecialchars((string)$r["record_id"]); ?></td>
                      <td class="strong"><?php echo htmlspecialchars($displayName); ?></td>
                      <td><?php echo htmlspecialchars($typeLabel); ?></td>
                      <td><?php echo htmlspecialchars((string)($r["barangay"] ?? "")); ?></td>
                      <td class="mono">PHP <?php echo number_format((float)$r["amount"], 2); ?></td>
                      <td class="mono"><?php echo htmlspecialchars((string)$r["record_date"]); ?></td>
                      <td class="mono"><?php echo htmlspecialchars((string)$r["month_year"]); ?></td>
                      <td class="note"><?php echo htmlspecialchars($notesVal); ?></td>
                      <td><a class="btn btn--secondary btn--sm" href="edit.php?record_id=<?php echo urlencode((string)$r["record_id"]); ?>">Edit</a></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="9" class="muted">No records found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  </div>
</body>
</html>
