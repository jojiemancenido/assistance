<?php
require_once "auth.php";
require_login();
require_once "db.php";

$types = ["Medical", "Burial", "Livelihood", "Other"];
$barangays = [
  "Barangay 1",
  "Barangay 2 (Pasig)",
  "Barangay 3 (Bagumbayan)",
  "Barangay 4 (Mantagbac)",
  "Barangay 5 (Pandan)",
  "Barangay 6 (Centro Occidental)",
  "Barangay 7 (Centro Oriental)",
  "Barangay 8 (Salcedo)",
  "Alawihao",
  "Awitan",
  "Bagasbas",
  "Bibirao",
  "Borabod",
  "Calasgasan",
  "Camambugan",
  "Cobangbang",
  "Dogongan",
  "Gahonon",
  "Gubat",
  "Lag-on",
  "Magang",
  "Mambalite",
  "Mancruz",
  "Pamorangon",
  "San Isidro",
];

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

$isSuperAdmin = is_super_admin();
$recordId = (int)($_GET["record_id"] ?? 0);
$returnTo = normalize_return_to((string)($_GET["return_to"] ?? ""), $isSuperAdmin);
$status = trim((string)($_GET["status"] ?? ""));
$msg = trim((string)($_GET["msg"] ?? ""));

if ($recordId <= 0) {
  header("Location: " . with_status_message($returnTo, "error", "Invalid record selected for editing."));
  exit;
}

$hasTypeSpecify = has_column($conn, "records", "type_specify");
$selectCols = "record_id, name, type, barangay, amount, record_date, notes";
if ($hasTypeSpecify) {
  $selectCols .= ", type_specify";
}

$stmt = $conn->prepare("SELECT $selectCols FROM records WHERE record_id = ? LIMIT 1");
if (!$stmt) {
  header("Location: " . with_status_message($returnTo, "error", "Database error: " . $conn->error));
  exit;
}
$stmt->bind_param("i", $recordId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
  header("Location: " . with_status_message($returnTo, "error", "Record not found."));
  exit;
}

$name = trim((string)($row["name"] ?? ""));
$type = trim((string)($row["type"] ?? ""));
$barangay = trim((string)($row["barangay"] ?? ""));
$amount = (string)($row["amount"] ?? "");
$recordDate = trim((string)($row["record_date"] ?? date("Y-m-d")));
$notes = (string)($row["notes"] ?? "");
$typeSpecify = "";

if ($hasTypeSpecify) {
  $typeSpecify = trim((string)($row["type_specify"] ?? ""));
} else {
  $typeSpecify = extract_specify_from_notes($notes);
}

$canDeleteRecord = is_super_admin();
$backDashboardHref = $returnTo;

if ($barangay !== "" && !in_array($barangay, $barangays, true)) {
  $barangays[] = $barangay;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Record #<?php echo (int)$recordId; ?></title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="app">
    <header class="app-header">
      <div class="brand-text">
        <h1>Edit Record #<?php echo (int)$recordId; ?></h1>
        <p>Update beneficiary information</p>
      </div>
      <div class="header-meta">
        <?php if (is_super_admin()): ?>
          <a class="btn btn--secondary btn--sm" href="logs.php">Logs</a>
        <?php endif; ?>
        <a class="btn btn--secondary btn--sm" href="<?php echo htmlspecialchars($backDashboardHref); ?>">Back to Dashboard</a>
        <a class="btn btn--secondary btn--sm" href="records.php">All Records</a>
      </div>
    </header>

    <section class="card">
      <div class="card__header">
        <h2 class="card__title">Edit Information</h2>
        <p class="card__sub">Change the fields below then save.</p>
      </div>
      <div class="card__body">
        <?php if ($msg !== ""): ?>
          <div class="alert <?php echo ($status === "error") ? "alert--error" : "alert--success"; ?>">
            <?php echo htmlspecialchars($msg); ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="update.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
          <input type="hidden" name="record_id" value="<?php echo (int)$recordId; ?>" />
          <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo); ?>" />

          <div class="form-grid">
            <div class="field">
              <label for="name">Name</label>
              <input id="name" type="text" name="name" required value="<?php echo htmlspecialchars($name); ?>" />
            </div>

            <div class="field">
              <label for="type">Type of Assistance</label>
              <select id="type" name="type" required>
                <option value="" disabled <?php echo $type === "" ? "selected" : ""; ?>>Select type</option>
                <?php foreach ($types as $t): ?>
                  <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $type === $t ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars($t); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field field--full <?php echo $type === "Other" ? "" : "hidden"; ?>" id="typeSpecifyWrap">
              <label for="type_specify">Specify Type (if Other)</label>
              <input id="type_specify" type="text" name="type_specify" value="<?php echo htmlspecialchars($typeSpecify); ?>" />
              <div class="help">Required only when you choose <b>Other</b>.</div>
            </div>

            <div class="field">
              <label for="amount">Amount (PHP)</label>
              <input id="amount" type="number" step="0.01" min="0" name="amount" required value="<?php echo htmlspecialchars($amount); ?>" />
            </div>

            <div class="field">
              <label for="record_date">Date</label>
              <input id="record_date" type="date" name="record_date" required value="<?php echo htmlspecialchars($recordDate); ?>" />
            </div>

            <div class="field field--full">
              <label for="barangay">Barangay</label>
              <select id="barangay" name="barangay" required>
                <option value="" disabled <?php echo $barangay === "" ? "selected" : ""; ?>>Select barangay</option>
                <?php foreach ($barangays as $b): ?>
                  <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $barangay === $b ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars($b); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Municipality</label>
              <input class="readonly" type="text" value="Daet" readonly />
            </div>

            <div class="field">
              <label>Province</label>
              <input class="readonly" type="text" value="Camarines Norte" readonly />
            </div>

            <div class="field field--full">
              <label for="notes">Notes (optional)</label>
              <textarea id="notes" name="notes"><?php echo htmlspecialchars($notes); ?></textarea>
            </div>
          </div>

          <div class="actions">
            <a class="btn btn--secondary" href="<?php echo htmlspecialchars($returnTo); ?>">Cancel</a>
            <?php if ($canDeleteRecord): ?>
              <button class="btn btn--danger" type="submit" formaction="delete_record.php" formmethod="POST" formnovalidate onclick="return confirm('Delete this record permanently?');">Delete Record</button>
            <?php endif; ?>
            <button class="btn" type="submit">Update Record</button>
          </div>
        </form>
      </div>
    </section>
  </div>

  <script>
    (function(){
      const typeSel = document.getElementById('type');
      const wrap = document.getElementById('typeSpecifyWrap');
      const input = document.getElementById('type_specify');
      if (!typeSel || !wrap || !input) return;

      function sync(){
        const isOther = typeSel.value === 'Other';
        wrap.classList.toggle('hidden', !isOther);
        input.required = isOther;
        if (!isOther) input.value = '';
      }

      typeSel.addEventListener('change', sync);
      sync();
    })();
  </script>
</body>
</html>

