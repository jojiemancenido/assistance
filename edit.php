<?php
require_once "auth.php";
require_login();
require_once "db.php";

$baseTypes = ["Medical", "Burial", "Livelihood", "Other"];
$barangays = [
  "Barangay 1",
  "Barangay 2 (Pasig)",
  "Barangay 3 (Bagumbayan)",
  "Barangay 4 (Mantagbac)",
  "Barangay 5 (Pandan)",
  "Barangay 6 (Centro)",
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

$isSuperAdmin = is_super_admin();
$scopedBarangay = current_scoped_barangay();
$isBarangayScoped = ($scopedBarangay !== "");
$scopedOffice = current_scoped_office();
$isOfficeScoped = ($scopedOffice !== "");
$recordId = (int)($_GET["record_id"] ?? 0);
$returnTo = normalize_return_to((string)($_GET["return_to"] ?? ""), $isSuperAdmin);
$status = trim((string)($_GET["status"] ?? ""));
$msg = trim((string)($_GET["msg"] ?? ""));
$isPopup = (isset($_GET["popup"]) && (string)$_GET["popup"] === "1");
$popupClose = $isPopup && (isset($_GET["close"]) && (string)$_GET["close"] === "1");
$popupRedirectTo = "";
if ($popupClose) {
  $popupStatus = ($status !== "") ? $status : "success";
  $popupMessage = ($msg !== "") ? $msg : "Record updated successfully.";
  $popupRedirectTo = with_status_message($returnTo, $popupStatus, $popupMessage);
}

if ($recordId <= 0) {
  header("Location: " . with_status_message($returnTo, "error", "Invalid record selected for editing."));
  exit;
}

$hasTypeSpecify = has_column($conn, "records", "type_specify");
$selectCols = "record_id, name, type, barangay, amount, record_date, notes, office_scope, municipality, province, age, birthdate, contact_number, diagnosis, hospital, contact_person";
if ($hasTypeSpecify) {
  $selectCols .= ", type_specify";
}

$recordSql = "SELECT $selectCols FROM records WHERE record_id = ?";
if ($isOfficeScoped) {
  $recordSql .= " AND office_scope = ?";
}
if ($isBarangayScoped) {
  $recordSql .= " AND barangay = ?";
}
$recordSql .= " LIMIT 1";

$stmt = $conn->prepare($recordSql);
if (!$stmt) {
  header("Location: " . with_status_message($returnTo, "error", "Database error: " . $conn->error));
  exit;
}
if ($isOfficeScoped && $isBarangayScoped) {
  $stmt->bind_param("iss", $recordId, $scopedOffice, $scopedBarangay);
} elseif ($isOfficeScoped) {
  $stmt->bind_param("is", $recordId, $scopedOffice);
} elseif ($isBarangayScoped) {
  $stmt->bind_param("is", $recordId, $scopedBarangay);
} else {
  $stmt->bind_param("i", $recordId);
}
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
  header("Location: " . with_status_message($returnTo, "error", "Record not found or access denied."));
  exit;
}

$name = trim((string)($row["name"] ?? ""));
[$lastName, $firstName, $middleName, $nameExtension] = split_record_name_parts($name);
$type = trim((string)($row["type"] ?? ""));
$barangay = trim((string)($row["barangay"] ?? ""));
$amount = (string)($row["amount"] ?? "");
$recordDate = trim((string)($row["record_date"] ?? date("Y-m-d")));
$notes = (string)($row["notes"] ?? "");
$recordOfficeScope = normalize_office_scope_name((string)($row["office_scope"] ?? ""));
if ($recordOfficeScope === "") {
  $recordOfficeScope = "municipality";
}
$isMaifRecord = is_maif_office_scope($recordOfficeScope);
$types = $isMaifRecord ? ["Medical"] : $baseTypes;
$maifMunicipalities = maif_municipality_choices();
$defaultMaifMunicipality = !empty($maifMunicipalities) ? (string)$maifMunicipalities[0] : "Daet";
$municipality = $isMaifRecord
  ? normalize_maif_municipality((string)($row["municipality"] ?? ""))
  : trim((string)($row["municipality"] ?? "Daet"));
if ($isMaifRecord && $municipality === "") {
  $municipality = $defaultMaifMunicipality;
}
$province = trim((string)($row["province"] ?? "Camarines Norte"));
$age = trim((string)($row["age"] ?? ""));
$birthdate = trim((string)($row["birthdate"] ?? ""));
$contactNumber = trim((string)($row["contact_number"] ?? ""));
$diagnosis = trim((string)($row["diagnosis"] ?? ""));
$hospital = trim((string)($row["hospital"] ?? ""));
$contactPerson = trim((string)($row["contact_person"] ?? ""));
$maifBarangaySuggestions = $isMaifRecord ? maif_designated_barangay_suggestions($municipality) : [];
$typeSpecify = "";

if ($hasTypeSpecify) {
  $typeSpecify = trim((string)($row["type_specify"] ?? ""));
} else {
  $typeSpecify = extract_specify_from_notes($notes);
}
if ($isMaifRecord) {
  $type = "Medical";
  $typeSpecify = "";
}

$canDeleteRecord = is_super_admin();
$backDashboardHref = $returnTo;
$visibleBarangays = $isBarangayScoped ? [$scopedBarangay] : $barangays;

if ($barangay !== "" && !in_array($barangay, $barangays, true)) {
  $visibleBarangays[] = $barangay;
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
        <?php if (!$isPopup && is_super_admin()): ?>
          <a class="btn btn--secondary btn--sm" href="logs.php">Logs</a>
        <?php endif; ?>
        <?php if (!$isPopup): ?>
          <a class="btn btn--secondary btn--sm" href="<?php echo htmlspecialchars($backDashboardHref); ?>">Back to Dashboard</a>
          <a class="btn btn--secondary btn--sm" href="records.php">All Records</a>
        <?php endif; ?>
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
          <input type="hidden" name="popup" value="<?php echo $isPopup ? "1" : "0"; ?>" />

          <div class="form-grid">
            <div class="field">
              <label for="last_name">Last Name</label>
              <input id="last_name" type="text" name="last_name" required value="<?php echo htmlspecialchars($lastName); ?>" />
            </div>

            <div class="field">
              <label for="first_name">First Name</label>
              <input id="first_name" type="text" name="first_name" required value="<?php echo htmlspecialchars($firstName); ?>" />
            </div>

            <div class="field">
              <label for="middle_name">Middle Name</label>
              <input id="middle_name" type="text" name="middle_name" required value="<?php echo htmlspecialchars($middleName); ?>" />
            </div>

            <div class="field">
              <label for="name_extension">Extension (optional)</label>
              <input id="name_extension" type="text" name="name_extension" value="<?php echo htmlspecialchars($nameExtension); ?>" />
            </div>

            <div class="field">
              <label for="type">Type of Assistance</label>
              <?php if ($isMaifRecord): ?>
                <input class="readonly" type="text" value="Medical" readonly />
                <input id="type" type="hidden" name="type" value="Medical" />
              <?php else: ?>
                <select id="type" name="type" required>
                  <option value="" disabled <?php echo $type === "" ? "selected" : ""; ?>>Select type</option>
                  <?php foreach ($types as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $type === $t ? "selected" : ""; ?>>
                      <?php echo htmlspecialchars($t); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>

            <?php if (!$isMaifRecord): ?>
              <div class="field field--full <?php echo $type === "Other" ? "" : "hidden"; ?>" id="typeSpecifyWrap">
                <label for="type_specify">Specify Type (if Other)</label>
                <input id="type_specify" type="text" name="type_specify" value="<?php echo htmlspecialchars($typeSpecify); ?>" />
                <div class="help">Required only when you choose <b>Other</b>.</div>
              </div>
            <?php endif; ?>

            <div class="field">
              <label for="amount">Amount (PHP)</label>
              <input id="amount" type="number" step="0.01" min="0" name="amount" required value="<?php echo htmlspecialchars($amount); ?>" />
            </div>

            <div class="field">
              <label for="record_date">Date</label>
              <input id="record_date" type="date" name="record_date" required value="<?php echo htmlspecialchars($recordDate); ?>" />
            </div>

            <div class="field field--full">
              <label for="barangay"><?php echo $isMaifRecord ? "Designated Barangay" : "Barangay"; ?></label>
              <?php if ($isMaifRecord): ?>
                <select id="barangay" name="barangay" required>
                  <option value="" disabled <?php echo ($barangay === "") ? "selected" : ""; ?>>Select designated barangay</option>
                  <?php foreach ($maifBarangaySuggestions as $maifBarangay): ?>
                    <option value="<?php echo htmlspecialchars($maifBarangay); ?>" <?php echo ($barangay === $maifBarangay) ? "selected" : ""; ?>>
                      <?php echo htmlspecialchars($maifBarangay); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($isBarangayScoped): ?>
                <input id="barangay" class="readonly" type="text" value="<?php echo htmlspecialchars($scopedBarangay); ?>" readonly />
                <input type="hidden" name="barangay" value="<?php echo htmlspecialchars($scopedBarangay); ?>" />
              <?php else: ?>
                <select id="barangay" name="barangay" required>
                  <option value="" disabled <?php echo $barangay === "" ? "selected" : ""; ?>>Select barangay</option>
                  <?php foreach ($visibleBarangays as $b): ?>
                    <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $barangay === $b ? "selected" : ""; ?>>
                      <?php echo htmlspecialchars($b); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>

            <?php if ($isMaifRecord): ?>
              <div class="field">
                <label for="municipality">Municipality</label>
                <select id="municipality" name="municipality" required>
                  <?php foreach ($maifMunicipalities as $maifMunicipality): ?>
                    <option value="<?php echo htmlspecialchars($maifMunicipality); ?>" <?php echo $municipality === $maifMunicipality ? "selected" : ""; ?>>
                      <?php echo htmlspecialchars($maifMunicipality); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label for="age">Age</label>
                <input id="age" type="number" min="0" max="150" name="age" value="<?php echo htmlspecialchars($age); ?>" />
              </div>

              <div class="field">
                <label for="birthdate">Birthdate</label>
                <input id="birthdate" type="date" name="birthdate" value="<?php echo htmlspecialchars($birthdate); ?>" />
              </div>

              <div class="field field--full">
                <label for="contact_number">Contact Number</label>
                <input id="contact_number" type="text" name="contact_number" value="<?php echo htmlspecialchars($contactNumber); ?>" />
              </div>

              <div class="field field--full">
                <label for="diagnosis">Diagnosis</label>
                <textarea id="diagnosis" name="diagnosis"><?php echo htmlspecialchars($diagnosis); ?></textarea>
              </div>

              <div class="field">
                <label for="hospital">Hospital</label>
                <input id="hospital" type="text" name="hospital" value="<?php echo htmlspecialchars($hospital); ?>" />
              </div>

              <div class="field">
                <label for="contact_person">Contact Person (if applicable)</label>
                <input id="contact_person" type="text" name="contact_person" value="<?php echo htmlspecialchars($contactPerson); ?>" />
              </div>
            <?php else: ?>
              <div class="field">
                <label>Municipality</label>
                <input class="readonly" type="text" value="<?php echo htmlspecialchars($municipality !== "" ? $municipality : "Daet"); ?>" readonly />
              </div>

              <div class="field">
                <label>Province</label>
                <input class="readonly" type="text" value="<?php echo htmlspecialchars($province !== "" ? $province : "Camarines Norte"); ?>" readonly />
              </div>
            <?php endif; ?>

            <div class="field field--full">
              <label for="notes">Notes (optional)</label>
              <textarea id="notes" name="notes"><?php echo htmlspecialchars($notes); ?></textarea>
            </div>
          </div>

          <div class="actions">
            <a class="btn btn--secondary" id="edit-cancel-btn" href="<?php echo htmlspecialchars($returnTo); ?>">Cancel</a>
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
      var isPopup = <?php echo $isPopup ? "true" : "false"; ?>;
      var popupClose = <?php echo $popupClose ? "true" : "false"; ?>;
      var popupRedirectTo = <?php echo json_encode($popupRedirectTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      if (!isPopup || window.parent === window) return;

      function notifyParentClose(redirectUrl){
        try {
          window.parent.postMessage({
            type: 'close-record-edit-popup',
            redirectUrl: redirectUrl || ''
          }, '*');
        } catch (err) {}
      }

      var cancelBtn = document.getElementById('edit-cancel-btn');
      if (cancelBtn) {
        cancelBtn.addEventListener('click', function(e){
          e.preventDefault();
          notifyParentClose('');
        });
      }

      if (popupClose) {
        notifyParentClose(popupRedirectTo);
      }
    })();
  </script>
  <script>
    (function(){
      const municipality = document.getElementById('municipality');
      const barangaySelect = document.getElementById('barangay');
      if (!municipality || !barangaySelect) return;

      const optionsByMunicipality = <?php echo json_encode(maif_barangay_options_by_municipality(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

      function syncBarangayOptions(){
        const current = municipality.value;
        const options = Array.isArray(optionsByMunicipality[current]) ? optionsByMunicipality[current] : [];
        const previousValue = barangaySelect.value;
        let html = '<option value="" disabled>Select designated barangay</option>';

        if (options.length === 0) {
          html += '<option value="" disabled>No barangay list configured yet</option>';
        } else {
          html += options.map(function(option){
            const escaped = String(option)
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;');
            const selected = previousValue === option ? ' selected' : '';
            return '<option value="' + escaped + '"' + selected + '>' + escaped + '</option>';
          }).join('');
        }

        barangaySelect.innerHTML = html;
        if (!options.includes(previousValue)) {
          barangaySelect.selectedIndex = 0;
        }
      }

      municipality.addEventListener('change', syncBarangayOptions);
      syncBarangayOptions();
    })();
  </script>
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
