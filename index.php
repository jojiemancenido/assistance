<?php
require_once "auth.php";
require_login();
require_once "db.php";

// Types of assistance
$types = ["Medical", "Burial", "Livelihood", "Other"]; 

// 25 Barangays of Daet
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

function build_query(array $base, array $override = []): string {
  $q = array_merge($base, $override);
  foreach ($q as $k => $v) {
    if ($v === "" || $v === null) unset($q[$k]);
  }
  return http_build_query($q);
}

function extract_specify_from_notes(string &$notes): string {
  // If DB has no type_specify column, we store specify value inside notes using [SPECIFY]...[/SPECIFY]
  if (preg_match('/^\[SPECIFY\](.*?)\[\/SPECIFY\]\s*/s', $notes, $m)) {
    $spec = trim($m[1]);
    $notes = preg_replace('/^\[SPECIFY\].*?\[\/SPECIFY\]\s*/s', '', $notes);
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

$today = date("Y-m-d");
$q = trim($_GET["q"] ?? "");
$typeFilter = trim($_GET["type"] ?? "");
$barangayFilter = trim($_GET["barangay"] ?? "");
$sort = trim($_GET["sort"] ?? "new");
$status = trim($_GET["status"] ?? "");
$msg = trim($_GET["msg"] ?? "");
$authUser = (string)($_SESSION["auth_user"] ?? "");
$authToken = (string)($_SESSION["auth_session_token"] ?? "");
$isSuperAdmin = is_super_admin();

cleanup_expired_active_sessions();
$isCurrentSessionActive = ($authUser !== "" && $authToken !== "" && has_active_session_slot($authUser, $authToken));
$activeUsersCount = 0;
$activeUsers = [];
if ($isSuperAdmin && ensure_active_session_table()) {
  $activeCountRs = @$conn->query("SELECT COUNT(*) AS total_active FROM auth_active_sessions WHERE expires_at >= UTC_TIMESTAMP()");
  if ($activeCountRs && $activeCountRow = $activeCountRs->fetch_assoc()) {
    $activeUsersCount = (int)($activeCountRow["total_active"] ?? 0);
  }

  $activeListRs = @$conn->query(
    "SELECT username, last_seen
     FROM auth_active_sessions
     WHERE expires_at >= UTC_TIMESTAMP()
     ORDER BY username ASC
     LIMIT 50"
  );
  if ($activeListRs) {
    while ($activeUserRow = $activeListRs->fetch_assoc()) {
      $username = (string)($activeUserRow["username"] ?? "");
      $activeUsers[] = [
        "username" => $username,
        "is_me" => ($username !== "" && $username === $authUser),
        "last_seen_display" => format_utc_datetime_for_app((string)($activeUserRow["last_seen"] ?? "")),
      ];
    }
  }
}

$hasTypeSpecify = has_column($conn, "records", "type_specify");

// Next Record ID
$nextId = 1;
$res = @$conn->query(
  "SELECT AUTO_INCREMENT
   FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'records'"
);
if ($res && $row = $res->fetch_assoc()) {
  $nextId = (int)($row["AUTO_INCREMENT"] ?? 1);
}

// Total Records (big number)
$totalRecords = 0;
$r1 = @$conn->query("SELECT COUNT(*) AS total_records FROM records");
if ($r1 && $row = $r1->fetch_assoc()) {
  $totalRecords = (int)($row["total_records"] ?? 0);
}

// Summary totals per assistance category (count only)
$typeTotals = [];
$typeOptions = [];
$selectTotals = "SELECT type, notes";
if ($hasTypeSpecify) {
  $selectTotals .= ", type_specify";
}
$selectTotals .= " FROM records";
$rt = @$conn->query($selectTotals);
if ($rt) {
  while ($row = $rt->fetch_assoc()) {
    $t = (string)($row["type"] ?? "");
    if ($t === "") continue;
    $notesVal = (string)($row["notes"] ?? "");
    $spec = "";
    if ($hasTypeSpecify) {
      $spec = trim((string)($row["type_specify"] ?? ""));
    } else {
      $spec = extract_specify_from_notes($notesVal);
    }
    $label = build_type_label($t, $spec);
    if ($label === "") continue;
    if (!isset($typeTotals[$label])) {
      $typeTotals[$label] = ["count" => 0];
    }
    $typeTotals[$label]["count"] += 1;
    if (!isset($typeOptions[$label])) {
      $typeOptions[$label] = ["type" => $t, "spec" => $spec];
    }
  }
}

foreach ($types as $t) {
  if (!isset($typeTotals[$t])) {
    $typeTotals[$t] = ["count" => 0];
  }
  if (!isset($typeOptions[$t])) {
    $typeOptions[$t] = ["type" => $t, "spec" => ""];
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
$summaryTypePalette = [
  "#2563eb",
  "#0ea5e9",
  "#10b981",
  "#f59e0b",
  "#f97316",
  "#8b5cf6",
  "#ef4444",
  "#14b8a6",
];
$summaryTypeTotal = 0;
foreach ($orderedTypeLabels as $label) {
  $summaryTypeTotal += (int)($typeTotals[$label]["count"] ?? 0);
}
$summaryTypeChartItems = [];
$summaryTypeGradientParts = [];
$summaryTypeCursor = 0.0;
foreach ($orderedTypeLabels as $idx => $label) {
  $countVal = (int)($typeTotals[$label]["count"] ?? 0);
  $pct = ($summaryTypeTotal > 0) ? (($countVal / $summaryTypeTotal) * 100.0) : 0.0;
  $color = $summaryTypePalette[$idx % count($summaryTypePalette)];

  $summaryTypeChartItems[] = [
    "label" => $label,
    "count" => $countVal,
    "percentage" => $pct,
    "color" => $color,
  ];

  if ($pct <= 0) continue;
  $start = $summaryTypeCursor;
  $end = min(100.0, $start + $pct);
  $summaryTypeGradientParts[] = $color . " " . number_format($start, 2, ".", "") . "% " . number_format($end, 2, ".", "") . "%";
  $summaryTypeCursor = $end;
}
if ($summaryTypeCursor < 100.0 && !empty($summaryTypeGradientParts)) {
  $summaryTypeGradientParts[] = "#dbeafe " . number_format($summaryTypeCursor, 2, ".", "") . "% 100%";
}
$summaryTypeDonutGradient = !empty($summaryTypeGradientParts)
  ? ("conic-gradient(" . implode(", ", $summaryTypeGradientParts) . ")")
  : "conic-gradient(#e2e8f0 0% 100%)";

// Records list: search + filter + sort
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

$limit = 0;

$where = [];
$paramTypes = "";
$params = [];

if ($q !== "") {
  $where[] = "name LIKE ?";
  $paramTypes .= "s";
  $params[] = "%" . $q . "%";
}

if ($typeFilter !== "") {
  if (isset($typeOptions[$typeFilter])) {
    $filterInfo = $typeOptions[$typeFilter];
    $where[] = "type = ?";
    $paramTypes .= "s";
    $params[] = $filterInfo["type"];
    if ($filterInfo["type"] === "Other" && $filterInfo["spec"] !== "") {
      if ($hasTypeSpecify) {
        $where[] = "type_specify = ?";
        $paramTypes .= "s";
        $params[] = $filterInfo["spec"];
      } else {
        $where[] = "notes LIKE ?";
        $paramTypes .= "s";
        $params[] = "[SPECIFY]" . $filterInfo["spec"] . "[/SPECIFY]%";
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

$selectCols = "record_id, name, type, barangay, municipality, province, amount, record_date, month_year, notes";
if ($hasTypeSpecify) {
  $selectCols .= ", type_specify";
}

$sql = "SELECT $selectCols FROM records";
if (!empty($where)) {
  $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY $orderBy";
if ($limit > 0) {
  $sql .= " LIMIT $limit";
}

$records = null;
$stmt = $conn->prepare($sql);
if ($stmt) {
  if (!empty($params)) {
    $stmt->bind_param($paramTypes, ...$params);
  }
  $stmt->execute();
  $records = $stmt->get_result();
}

$baseQuery = [
  "q" => $q,
  "type" => $typeFilter,
  "barangay" => $barangayFilter,
  "sort" => $sort,
];
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Beneficiary Records</title>
  <link rel="stylesheet" href="styles.css" />
</head>

<body>
  <div id="startup-splash" class="startup-splash" aria-hidden="true">
    <div class="startup-splash__inner">
      <span class="startup-splash__ring" aria-hidden="true"></span>
      <img class="startup-splash__logo" src="daet%20logo%20lgu.png" alt="Bayan ng Daet Logo" />
      <p class="startup-splash__title">Bayan ng Daet</p>
      <p class="startup-splash__sub">Camarines Norte</p>
    </div>
  </div>
  <script>
    (function () {
      var splash = document.getElementById('startup-splash');
      if (!splash) return;

      var dashboardSwitchKey = 'startupSplashNextDashboard';

      document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) return;
        var link = target.closest('a[data-dashboard-switch="1"]');
        if (!link) return;
        try {
          window.sessionStorage.setItem(dashboardSwitchKey, '1');
        } catch (err) {}
      });

      function getNavigationType() {
        var navEntries = window.performance && window.performance.getEntriesByType
          ? window.performance.getEntriesByType('navigation')
          : [];
        if (navEntries && navEntries.length > 0 && navEntries[0].type) {
          return navEntries[0].type;
        }
        if (window.performance && window.performance.navigation) {
          return window.performance.navigation.type === 1 ? 'reload' : 'navigate';
        }
        return 'navigate';
      }

      var showByIntent = false;
      try {
        showByIntent = window.sessionStorage.getItem(dashboardSwitchKey) === '1';
        if (showByIntent) {
          window.sessionStorage.removeItem(dashboardSwitchKey);
        }
      } catch (err) {}

      var isReload = getNavigationType() === 'reload';
      var shouldShowSplash = isReload || showByIntent;

      if (!shouldShowSplash) {
        if (splash.parentNode) {
          splash.parentNode.removeChild(splash);
        }
        return;
      }

      var minDuration = 900;
      var startAt = Date.now();

      function closeSplash() {
        if (!splash || splash.dataset.done === '1') return;
        splash.dataset.done = '1';
        splash.classList.add('startup-splash--leave');
        window.setTimeout(function () {
          if (splash && splash.parentNode) {
            splash.parentNode.removeChild(splash);
          }
        }, 420);
      }

      function scheduleClose() {
        var elapsed = Date.now() - startAt;
        var wait = Math.max(0, minDuration - elapsed);
        window.setTimeout(closeSplash, wait);
      }

      window.addEventListener('load', scheduleClose, { once: true });
      window.setTimeout(closeSplash, 1800);
    })();
  </script>
  <div class="app">

    <header class="app-header">
      <div class="brand">
        <img class="logo" src="daet logo lgu.png" alt="Logo" />
        <div class="brand-text">
          <h1>Beneficiary Records</h1>
          <p>Daet, Camarines Norte â€¢ Quick entry + search</p>
        </div>
      </div>

      <div class="header-meta">
        <div class="user-chip">
          Signed in as <strong><?php echo htmlspecialchars($authUser); ?></strong>
        </div>
        <div class="user-chip">
          Session
          <span class="status-pill <?php echo $isCurrentSessionActive ? "status-pill--active" : "status-pill--inactive"; ?>">
            <?php echo $isCurrentSessionActive ? "Active" : "Inactive"; ?>
          </span>
        </div>
        <div class="badge">
          Next Record ID
          <strong>#<?php echo htmlspecialchars((string)$nextId); ?></strong>
        </div>
        <?php if ($isSuperAdmin): ?>
          <div class="badge" id="active-users-badge" data-active-users="<?php echo (int)$activeUsersCount; ?>">
            Active Users
            <strong><?php echo htmlspecialchars((string)$activeUsersCount); ?></strong>
          </div>
          <a class="btn btn--secondary btn--sm" href="super_admin.php" data-dashboard-switch="1">Super Admin</a>
        <?php endif; ?>
        <details class="account-menu">
          <summary class="btn btn--secondary btn--sm account-menu__summary">
            <span class="account-menu__summary-label">Account</span>
          </summary>
          <div class="account-menu__panel">
            <div class="account-menu__panel-head">Account Options</div>
            <a class="account-menu__item" href="change_password.php">
              <span class="account-menu__item-title">Change Password</span>
              <span class="account-menu__item-sub">Update your current password</span>
            </a>
            <form method="POST" action="logout.php" class="account-menu__form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
              <button class="account-menu__item account-menu__item--button" type="submit">
                <span class="account-menu__item-title">Log Out</span>
                <span class="account-menu__item-sub">Sign out of this account</span>
              </button>
            </form>
          </div>
        </details>
      </div>
    </header>

    <main class="main-grid">

      <!-- Entry Form -->
      <section class="card">
        <div class="card__header">
          <h2 class="card__title">New Entry</h2>
          <p class="card__sub">Fill out the fields then save the record.</p>
        </div>
        <div class="card__body">

          <?php if ($msg !== ""): ?>
            <div class="alert <?php echo ($status === "error") ? "alert--error" : "alert--success"; ?>">
              <?php echo htmlspecialchars($msg); ?>
            </div>
          <?php endif; ?>

          <form method="POST" action="save.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
            <div class="form-grid">

              <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" required placeholder="e.g., Juan Dela Cruz" />
              </div>

              <div class="field">
                <label for="type">Type of Assistance</label>
                <select id="type" name="type" required>
                  <option value="" disabled selected>Select type</option>
                  <?php foreach ($types as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Only shows when type = Other -->
              <div class="field field--full hidden" id="typeSpecifyWrap">
                <label for="type_specify">Specify Type (if Other)</label>
                <input id="type_specify" type="text" name="type_specify" placeholder="e.g., Educational, Food, Transportation..." />
                <div class="help">Required only when you choose <b>Other</b>.</div>
              </div>

              <div class="field">
                <label for="amount">Amount (PHP)</label>
                <input id="amount" type="number" step="0.01" min="0" name="amount" required placeholder="0.00" />
              </div>

              <div class="field">
                <label for="record_date">Date</label>
                <input id="record_date" type="date" name="record_date" value="<?php echo htmlspecialchars($today); ?>" required />
              </div>

              <div class="field field--full">
                <label for="barangay">Barangay</label>
                <select id="barangay" name="barangay" required>
                  <option value="" disabled selected>Select barangay</option>
                  <?php foreach ($barangays as $b): ?>
                    <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
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
                <textarea id="notes" name="notes" placeholder="Any notes about this assistance/record..."></textarea>
              </div>

            </div>

            <div class="actions">
              <button class="btn" type="submit">Save Record</button>
            </div>
          </form>

          <div class="import-block">
            <h3 class="card__title">Import from Excel/CSV</h3>
            <p class="card__sub">Accepted columns: Name, Barangay, Type of Assitance, Amount, Date.</p>
            <form method="POST" action="import.php" enctype="multipart/form-data" class="import-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>" />
              <input type="file" name="import_file" accept=".csv,.xlsx" required />
              <button class="btn btn--secondary" type="submit">Import File</button>
            </form>
          </div>

        </div>
      </section>

      <!-- Summary -->
      <aside class="stack">
        <?php if ($isSuperAdmin): ?>
          <section class="card">
            <div class="card__header">
              <h2 class="card__title">Active User Accounts</h2>
              <p class="card__sub">Shows which username is currently active.</p>
            </div>
            <div class="card__body">
              <div id="active-users-list" class="active-users-list">
                <?php if (!empty($activeUsers)): ?>
                  <?php foreach ($activeUsers as $activeUser): ?>
                    <div class="active-user-item">
                      <div class="active-user-meta">
                        <div class="active-user-name">
                          <?php echo htmlspecialchars((string)$activeUser["username"]); ?>
                          <?php if (!empty($activeUser["is_me"])): ?><span class="active-you">(You)</span><?php endif; ?>
                        </div>
                        <div class="card__sub">Last seen: <?php echo htmlspecialchars((string)$activeUser["last_seen_display"]); ?> <?php echo htmlspecialchars(app_timezone_label()); ?></div>
                      </div>
                      <span class="status-pill status-pill--active">Active</span>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="muted">No active users right now.</div>
                <?php endif; ?>
              </div>
              <div class="active-users-footer">
                <span id="active-users-count-footer" class="card__sub">Active users: <?php echo htmlspecialchars((string)$activeUsersCount); ?></span>
              </div>
            </div>
          </section>
        <?php endif; ?>

        <section class="card">
          <div class="card__header">
            <h2 class="card__title">Total Records</h2>
            <p class="card__sub">Overall count of saved entries.</p>
          </div>
          <div class="card__body">
            <div
              id="total-records-number"
              class="summary-number summary-number--compact"
              aria-label="Total Records"
              data-total="<?php echo (int)$totalRecords; ?>"
            >
              <?php echo number_format((float)$totalRecords); ?>
            </div>
          </div>
        </section>

        <section class="card">
          <div class="card__header">
            <h2 class="card__title">Summary by Type</h2>
            <p class="card__sub">Count per assistance type.</p>
          </div>
          <div class="card__body">
            <div id="summary-type-grid" class="dashboard-summary-type">
              <?php if ($summaryTypeTotal > 0): ?>
                <div class="dashboard-summary-type__layout js-donut-interactive">
                  <div class="dashboard-donut" style="--dash-donut-fill: <?php echo htmlspecialchars($summaryTypeDonutGradient, ENT_QUOTES); ?>;">
                    <div class="dashboard-donut__center js-donut-center">
                      <strong><?php echo number_format((int)$summaryTypeTotal); ?> record<?php echo ((int)$summaryTypeTotal === 1 ? "" : "s"); ?></strong>
                      <small class="js-donut-mid">100.0%</small>
                      <span class="js-donut-sub">All Types</span>
                    </div>
                  </div>
                  <div class="dashboard-donut__legend">
                    <?php foreach ($summaryTypeChartItems as $item): ?>
                      <div
                        class="dashboard-donut__item"
                        data-donut-top="<?php echo htmlspecialchars(number_format((int)($item["count"] ?? 0)) . " record" . (((int)($item["count"] ?? 0) === 1) ? "" : "s"), ENT_QUOTES); ?>"
                        data-donut-mid="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 1) . "%", ENT_QUOTES); ?>"
                        data-donut-bottom="<?php echo htmlspecialchars((string)($item["label"] ?? ""), ENT_QUOTES); ?>"
                        data-donut-pct="<?php echo htmlspecialchars(number_format((float)($item["percentage"] ?? 0.0), 4, ".", ""), ENT_QUOTES); ?>"
                      >
                        <span class="dashboard-donut__swatch" style="--dash-swatch: <?php echo htmlspecialchars((string)$item["color"], ENT_QUOTES); ?>;"></span>
                        <span class="dashboard-donut__name"><?php echo htmlspecialchars((string)$item["label"]); ?></span>
                        <span class="dashboard-donut__meta"><?php echo number_format((int)$item["count"]); ?> (<?php echo number_format((float)$item["percentage"], 1); ?>%)</span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php else: ?>
                <div class="muted">No summary data.</div>
              <?php endif; ?>
            </div>
          </div>
        </section>
      </aside>

    </main>

    <!-- Records -->
    <section id="records-section" class="card section">
      <div class="card__header card__header--row">
        <div>
          <h2 class="card__title">Records</h2>
          <div class="records-links records-links--dashboard">
            <div class="records-links__primary">
              <a class="btn btn--secondary btn--sm" href="records.php">Open All Records (100vh Page)</a>
              <button
                class="btn btn--secondary btn--sm"
                type="button"
                id="toggle-export-panel"
                aria-expanded="false"
                aria-controls="dashboard-export-panel"
              >Export</button>
            </div>
            <form id="dashboard-export-panel" class="super-export-form super-export-form--dashboard hidden" method="GET" action="export_records.php">
              <div class="super-export-actions">
                <button class="btn btn--secondary btn--sm" type="submit" name="format" value="excel">Export Excel</button>
                <button class="btn btn--secondary btn--sm" type="submit" name="format" value="pdf">Export PDF</button>
              </div>
              <div class="super-export-types">
                <?php foreach ($orderedTypeLabels as $label): ?>
                  <label class="super-export-type-item">
                    <input type="checkbox" name="types[]" value="<?php echo htmlspecialchars($label); ?>" />
                    <span><?php echo htmlspecialchars($label); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="card__sub super-export-hint">Leave all unchecked to export all types. Select one or more to export specific types.</p>
            </form>
          </div>
          <p id="records-subtitle" class="card__sub">
            <?php if ($q !== "" || $typeFilter !== "" || $barangayFilter !== ""): ?>
              Showing all matching results.
            <?php else: ?>
              Showing all saved entries.
            <?php endif; ?>
          </p>
        </div>

        <form class="search" method="GET" action="index.php#records-section">
          <input id="live-search-input" type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search by name..." autocomplete="off" />
          <input type="hidden" name="type" value="<?php echo htmlspecialchars($typeFilter); ?>" />
          <input type="hidden" name="barangay" value="<?php echo htmlspecialchars($barangayFilter); ?>" />
          <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>" />
          <button class="btn btn--sm" type="submit">Search</button>
          <?php if ($q !== "" || $typeFilter !== "" || $barangayFilter !== "" || $sort !== "new"): ?>
            <a class="btn btn--secondary btn--sm" href="index.php#records-section">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="card__body">
        <div class="filters">
          <div class="chips">
            <?php $allActive = ($typeFilter === ""); ?>
            <a class="chip <?php echo $allActive ? "chip--active" : ""; ?>" href="index.php?<?php echo build_query($baseQuery, ["type" => ""]); ?>#records-section">All</a>
            <?php foreach ($orderedTypeLabels as $label): ?>
              <?php $active = ($typeFilter === $label); ?>
              <a class="chip <?php echo $active ? "chip--active" : ""; ?>" href="index.php?<?php echo build_query($baseQuery, ["type" => $label]); ?>#records-section">
                <?php echo htmlspecialchars($label); ?>
                <span class="chip-badge"><?php echo (int)($typeTotals[$label]["count"] ?? 0); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div id="records-table-wrap" class="table-wrap">
          <div class="table-scroll table-scroll--records">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>
                    <span class="th-with-menu">
                      <span>Type</span>
                      <details class="th-menu">
                        <summary class="th-menu__summary" aria-label="Type filter"></summary>
                        <div class="th-menu__list">
                          <a href="index.php?<?php echo build_query($baseQuery, ["type" => ""]); ?>#records-section">All Types</a>
                          <?php foreach ($orderedTypeLabels as $label): ?>
                            <a href="index.php?<?php echo build_query($baseQuery, ["type" => $label]); ?>#records-section"><?php echo htmlspecialchars($label); ?></a>
                          <?php endforeach; ?>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th>
                    <span class="th-with-menu">
                      <span>Barangay</span>
                      <details class="th-menu">
                        <summary class="th-menu__summary" aria-label="Barangay filter"></summary>
                        <div class="th-menu__list">
                          <a href="index.php?<?php echo build_query($baseQuery, ["barangay" => ""]); ?>#records-section">All Barangays</a>
                          <?php foreach ($barangays as $b): ?>
                            <a href="index.php?<?php echo build_query($baseQuery, ["barangay" => $b]); ?>#records-section"><?php echo htmlspecialchars($b); ?></a>
                          <?php endforeach; ?>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th class="col-amount">
                    <span class="amount-heading">
                      <span>Amount</span>
                      <button
                        type="button"
                        id="amount-visibility-indicator"
                        class="amount-indicator"
                        data-hidden="0"
                        aria-label="Amount is visible. Click to hide amount"
                        title="Amount visible"
                      >
                        <svg class="eye-icon eye-icon--open" viewBox="0 0 24 24" aria-hidden="true">
                          <path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z" />
                          <circle cx="12" cy="12" r="3.25" />
                        </svg>
                        <svg class="eye-icon eye-icon--closed" viewBox="0 0 24 24" aria-hidden="true">
                          <path d="M3 3l18 18" />
                          <path d="M9.7 9.8A3.2 3.2 0 0 0 9 12a3.3 3.3 0 0 0 5.2 2.7" />
                          <path d="M5.2 7.4C3.6 8.7 2.5 10.6 2 12c1 2.1 4.3 6.5 10 6.5 2.1 0 3.9-.6 5.4-1.5" />
                          <path d="M10.9 5.6c.4-.1.7-.1 1.1-.1 5.7 0 9 4.4 10 6.5-.4.9-1.1 2.1-2.1 3.2" />
                        </svg>
                      </button>
                      <details class="th-menu">
                        <summary class="th-menu__summary" aria-label="Amount sort"></summary>
                        <div class="th-menu__list">
                          <a href="index.php?<?php echo build_query($baseQuery, ["sort" => "amount_desc"]); ?>#records-section">High to Low</a>
                          <a href="index.php?<?php echo build_query($baseQuery, ["sort" => "amount_asc"]); ?>#records-section">Low to High</a>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th>
                    <span class="th-with-menu">
                      <span>Date</span>
                      <details class="th-menu">
                        <summary class="th-menu__summary" aria-label="Date sort"></summary>
                        <div class="th-menu__list">
                          <a href="index.php?<?php echo build_query($baseQuery, ["sort" => "date_new"]); ?>#records-section">New to Old</a>
                          <a href="index.php?<?php echo build_query($baseQuery, ["sort" => "date_old"]); ?>#records-section">Old to New</a>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th>
                    <span class="th-with-menu">
                      <span>Year-Month</span>
                      <details class="th-menu">
                        <summary class="th-menu__summary" aria-label="Year-Month sort"></summary>
                        <div class="th-menu__list">
                          <a href="index.php?<?php echo build_query($baseQuery, ["sort" => "month_year_new"]); ?>#records-section">New to Old</a>
                          <a href="index.php?<?php echo build_query($baseQuery, ["sort" => "month_year_old"]); ?>#records-section">Old to New</a>
                        </div>
                      </details>
                    </span>
                  </th>
                  <th>Notes</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="records-tbody">
                <?php if ($records && $records->num_rows > 0): ?>
                  <?php while($r = $records->fetch_assoc()): ?>
                    <?php
                      $notesVal = (string)($r["notes"] ?? "");
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
                      $amountDisplay = "PHP " . number_format((float)($r["amount"] ?? 0), 2);
                    ?>
                    <tr>
                      <td class="mono"><?php echo htmlspecialchars($r["record_id"]); ?></td>
                      <td class="strong"><?php echo htmlspecialchars($r["name"]); ?></td>
                      <td><?php echo htmlspecialchars($typeLabel); ?></td>
                      <td><?php echo htmlspecialchars($r["barangay"] ?? ""); ?></td>
                      <td class="mono col-amount"><?php echo htmlspecialchars($amountDisplay); ?></td>
                      <td class="mono"><?php echo htmlspecialchars($r["record_date"]); ?></td>
                      <td class="mono"><?php echo htmlspecialchars($r["month_year"]); ?></td>
                      <td class="note"><?php echo htmlspecialchars($notesVal); ?></td>
                      <td>
                        <div class="record-action-group">
                          <button
                            type="button"
                            class="btn btn--secondary btn--sm record-view-btn"
                            data-record-id="<?php echo htmlspecialchars((string)$r["record_id"], ENT_QUOTES); ?>"
                            data-record-name="<?php echo htmlspecialchars((string)$r["name"], ENT_QUOTES); ?>"
                            data-record-type="<?php echo htmlspecialchars($typeLabel, ENT_QUOTES); ?>"
                            data-record-barangay="<?php echo htmlspecialchars((string)($r["barangay"] ?? ""), ENT_QUOTES); ?>"
                            data-record-amount="<?php echo htmlspecialchars($amountDisplay, ENT_QUOTES); ?>"
                            data-record-date="<?php echo htmlspecialchars((string)($r["record_date"] ?? ""), ENT_QUOTES); ?>"
                            data-record-month-year="<?php echo htmlspecialchars((string)($r["month_year"] ?? ""), ENT_QUOTES); ?>"
                            data-record-notes="<?php echo htmlspecialchars($notesVal, ENT_QUOTES); ?>"
                          >
                            View
                          </button>
                          <a class="btn btn--secondary btn--sm" href="edit.php?record_id=<?php echo urlencode((string)$r["record_id"]); ?>">Edit</a>
                        </div>
                      </td>
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

    <div id="record-view-overlay" class="record-view-overlay hidden" aria-hidden="true">
      <div class="record-view-panel" role="dialog" aria-modal="true" aria-labelledby="record-view-title">
        <div class="record-view-panel__head">
          <div>
            <h3 id="record-view-title">Record Details</h3>
            <p>Viewing full details for selected record.</p>
          </div>
          <button type="button" class="record-view-close" id="record-view-close" aria-label="Close record details">&times;</button>
        </div>
        <div class="record-view-grid">
          <div class="record-view-item"><span>ID</span><strong id="view-record-id">-</strong></div>
          <div class="record-view-item"><span>Name</span><strong id="view-record-name">-</strong></div>
          <div class="record-view-item"><span>Type</span><strong id="view-record-type">-</strong></div>
          <div class="record-view-item"><span>Barangay</span><strong id="view-record-barangay">-</strong></div>
          <div class="record-view-item"><span>Amount</span><strong id="view-record-amount">-</strong></div>
          <div class="record-view-item"><span>Date</span><strong id="view-record-date">-</strong></div>
          <div class="record-view-item"><span>Year-Month</span><strong id="view-record-month-year">-</strong></div>
          <div class="record-view-item record-view-item--full"><span>Notes</span><strong id="view-record-notes">-</strong></div>
        </div>
        <div class="actions">
          <button type="button" class="btn btn--secondary btn--sm" id="record-view-close-footer">Close</button>
        </div>
      </div>
    </div>

    <footer class="footer">
      <span class="muted">Local system â€¢ XAMPP (MySQL) â€¢ PHP</span>
    </footer>

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
  <script>
    (function(){
      const toggleBtn = document.getElementById('toggle-export-panel');
      const panel = document.getElementById('dashboard-export-panel');
      if (!toggleBtn || !panel) return;

      toggleBtn.addEventListener('click', function(){
        const willOpen = panel.classList.contains('hidden');
        panel.classList.toggle('hidden');
        toggleBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        toggleBtn.textContent = willOpen ? 'Close Export' : 'Export';
      });
    })();
  </script>
  <script>
    (function(){
      const section = document.getElementById('records-section');
      const indicatorBtn = document.getElementById('amount-visibility-indicator');
      if (!section || !indicatorBtn) return;

      const storageKey = 'records_amount_hidden';
      function setHiddenState(hidden){
        section.classList.toggle('records-amount-hidden', hidden);
        indicatorBtn.setAttribute('data-hidden', hidden ? '1' : '0');
        indicatorBtn.setAttribute(
          'aria-label',
          hidden
            ? 'Amount is hidden. Click to show amount'
            : 'Amount is visible. Click to hide amount'
        );
        indicatorBtn.setAttribute('title', hidden ? 'Amount hidden' : 'Amount visible');
      }

      let hidden = false;
      try {
        hidden = window.localStorage.getItem(storageKey) === '1';
      } catch (err) {}
      setHiddenState(hidden);

      function toggleAmountVisibility(){
        hidden = !section.classList.contains('records-amount-hidden');
        setHiddenState(hidden);
        try {
          window.localStorage.setItem(storageKey, hidden ? '1' : '0');
        } catch (err) {}
      }

      indicatorBtn.addEventListener('click', toggleAmountVisibility);
    })();
  </script>
  <script>
    (function(){
      const overlay = document.getElementById('record-view-overlay');
      const closeTop = document.getElementById('record-view-close');
      const closeFooter = document.getElementById('record-view-close-footer');
      if (!overlay) return;

      const fields = {
        id: document.getElementById('view-record-id'),
        name: document.getElementById('view-record-name'),
        type: document.getElementById('view-record-type'),
        barangay: document.getElementById('view-record-barangay'),
        amount: document.getElementById('view-record-amount'),
        date: document.getElementById('view-record-date'),
        monthYear: document.getElementById('view-record-month-year'),
        notes: document.getElementById('view-record-notes')
      };

      function setField(el, value){
        if (!el) return;
        const text = String(value ?? '').trim();
        el.textContent = text !== '' ? text : '-';
      }

      function openView(button){
        setField(fields.id, button.getAttribute('data-record-id'));
        setField(fields.name, button.getAttribute('data-record-name'));
        setField(fields.type, button.getAttribute('data-record-type'));
        setField(fields.barangay, button.getAttribute('data-record-barangay'));
        setField(fields.amount, button.getAttribute('data-record-amount'));
        setField(fields.date, button.getAttribute('data-record-date'));
        setField(fields.monthYear, button.getAttribute('data-record-month-year'));
        setField(fields.notes, button.getAttribute('data-record-notes'));

        overlay.classList.remove('hidden');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
      }

      function closeView(){
        overlay.classList.add('hidden');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
      }

      document.addEventListener('click', function(e){
        const button = e.target.closest('.record-view-btn');
        if (button) {
          openView(button);
          return;
        }

        if (e.target === overlay) {
          closeView();
        }
      });

      if (closeTop) closeTop.addEventListener('click', closeView);
      if (closeFooter) closeFooter.addEventListener('click', closeView);

      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && !overlay.classList.contains('hidden')) {
          closeView();
        }
      });
    })();
  </script>
  <script>
    (function(){
      const searchForm = document.querySelector('form.search');
      const searchInput = document.getElementById('live-search-input');
      const tbody = document.getElementById('records-tbody');
      const tableWrap = document.getElementById('records-table-wrap');
      const subtitle = document.getElementById('records-subtitle');
      if (!searchForm || !searchInput || !tbody || !tableWrap) return;
      const initialRowsHtml = tbody.innerHTML;
      const initialSubtitle = subtitle ? subtitle.textContent : '';
      const typeInput = searchForm.querySelector('input[name="type"]');
      const barangayInput = searchForm.querySelector('input[name="barangay"]');
      const sortInput = searchForm.querySelector('input[name="sort"]');
      let debounceTimer = null;
      let controller = null;
      let requestId = 0;
      function escapeHtml(value){
        return String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }
      function renderRows(items, query){
        if (!Array.isArray(items) || items.length === 0) {
          tbody.innerHTML = '<tr><td colspan="9" class="muted">No records found.</td></tr>';
          if (subtitle) subtitle.textContent = 'Live results: 0 for "' + query + '".';
          return;
        }
        const rowsHtml = items.map(function(row){
          const editUrl = 'edit.php?record_id=' + encodeURIComponent(row.record_id ?? '');
          const viewAttrs =
            ' data-record-id="' + escapeHtml(row.record_id) + '"' +
            ' data-record-name="' + escapeHtml(row.name) + '"' +
            ' data-record-type="' + escapeHtml(row.type_label) + '"' +
            ' data-record-barangay="' + escapeHtml(row.barangay) + '"' +
            ' data-record-amount="' + escapeHtml(row.amount_display) + '"' +
            ' data-record-date="' + escapeHtml(row.record_date) + '"' +
            ' data-record-month-year="' + escapeHtml(row.month_year) + '"' +
            ' data-record-notes="' + escapeHtml(row.notes) + '"';

          return '<tr>' +
            '<td class="mono">' + escapeHtml(row.record_id) + '</td>' +
            '<td class="strong">' + escapeHtml(row.name) + '</td>' +
            '<td>' + escapeHtml(row.type_label) + '</td>' +
            '<td>' + escapeHtml(row.barangay) + '</td>' +
            '<td class="mono col-amount">' + escapeHtml(row.amount_display) + '</td>' +
            '<td class="mono">' + escapeHtml(row.record_date) + '</td>' +
            '<td class="mono">' + escapeHtml(row.month_year) + '</td>' +
            '<td class="note">' + escapeHtml(row.notes) + '</td>' +
            '<td><div class="record-action-group">' +
              '<button type="button" class="btn btn--secondary btn--sm record-view-btn"' + viewAttrs + '>View</button>' +
              '<a class="btn btn--secondary btn--sm" href="' + editUrl + '">Edit</a>' +
            '</div></td>' +
          '</tr>';
        }).join('');
        tbody.innerHTML = rowsHtml;
        if (subtitle) subtitle.textContent = 'Live results: ' + items.length + ' for "' + query + '".';
      }
      async function runLiveSearch(){
        const query = searchInput.value.trim();
        if (query === '') {
          if (controller) controller.abort();
          tbody.innerHTML = initialRowsHtml;
          if (subtitle) subtitle.textContent = initialSubtitle;
          tableWrap.classList.remove('is-loading');
          return;
        }
        const currentRequest = ++requestId;
        if (controller) controller.abort();
        controller = new AbortController();
        const params = new URLSearchParams();
        params.set('q', query);
        params.set('limit', '<?php echo (int)$limit; ?>');
        if (typeInput && typeInput.value !== '') params.set('type', typeInput.value);
        if (barangayInput && barangayInput.value !== '') params.set('barangay', barangayInput.value);
        if (sortInput && sortInput.value !== '') params.set('sort', sortInput.value);
        tableWrap.classList.add('is-loading');
        try {
          const response = await fetch('live_search.php?' + params.toString(), {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            signal: controller.signal
          });
          if (!response.ok) {
            throw new Error('Live search failed with status ' + response.status);
          }
          const data = await response.json();
          if (currentRequest !== requestId) return;
          if (!data || data.ok !== true || !Array.isArray(data.items)) {
            throw new Error('Invalid live search response');
          }
          renderRows(data.items, query);
        } catch (err) {
          if (err && err.name === 'AbortError') return;
          console.error(err);
        } finally {
          if (currentRequest === requestId) {
            tableWrap.classList.remove('is-loading');
          }
        }
      }
      searchInput.addEventListener('input', function(){
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(runLiveSearch, 250);
      });
    })();
  </script>
  <script>
    (function(){
      const totalEl = document.getElementById('total-records-number');
      const summaryTypeGridEl = document.getElementById('summary-type-grid');
      const activeUsersBadgeEl = document.getElementById('active-users-badge');
      const activeUsersCountFooterEl = document.getElementById('active-users-count-footer');
      const activeUsersListEl = document.getElementById('active-users-list');
      const canRenderActiveUsers = !!(activeUsersBadgeEl || activeUsersCountFooterEl || activeUsersListEl);
      if (!totalEl) return;

      const numberFmt = new Intl.NumberFormat();
      let lastTotal = Number(totalEl.getAttribute('data-total') || 0);
      let lastActiveUsers = Number(activeUsersBadgeEl ? activeUsersBadgeEl.getAttribute('data-active-users') : 0);
      const tzLabel = '<?php echo htmlspecialchars(app_timezone_label(), ENT_QUOTES); ?>';
      function escapeHtml(value){
        return String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }
      function escapeAttr(value){
        return escapeHtml(value).replace(/`/g, '&#096;');
      }
      const donutBound = new WeakSet();
      function initInteractiveDonuts(scope){
        const root = scope || document;
        const groups = root.querySelectorAll('.js-donut-interactive');
        if (!groups.length) return;

        groups.forEach(function(group){
          if (donutBound.has(group)) return;
          donutBound.add(group);

          const donut = group.querySelector('.dashboard-donut');
          const center = group.querySelector('.js-donut-center');
          if (!donut || !center) return;

          const topEl = center.querySelector('strong');
          const midEl = center.querySelector('.js-donut-mid');
          const bottomEl = center.querySelector('.js-donut-sub');
          const items = Array.prototype.slice.call(group.querySelectorAll('.dashboard-donut__item[data-donut-top][data-donut-mid][data-donut-bottom][data-donut-pct]'));
          if (!topEl || !midEl || !bottomEl || !items.length) return;

          const defaultTop = (topEl.textContent || '').trim();
          const defaultMid = (midEl.textContent || '').trim();
          const defaultBottom = (bottomEl.textContent || '').trim();
          let lockedIndex = -1;
          let currentIndex = -1;

          let cursor = 0;
          const ranges = items.map(function(item, index){
            const pct = parseFloat(item.getAttribute('data-donut-pct') || '0');
            if (!isFinite(pct) || pct <= 0) {
              return { index: index, start: cursor, end: cursor };
            }
            const end = Math.min(100, cursor + pct);
            const segment = { index: index, start: cursor, end: end };
            cursor = end;
            return segment;
          });

          function fitCenterText(){
            let baseTop = parseFloat(center.dataset.baseTop || '');
            let baseMid = parseFloat(center.dataset.baseMid || '');
            let baseBottom = parseFloat(center.dataset.baseBottom || '');

            if (!isFinite(baseTop)) {
              baseTop = parseFloat(window.getComputedStyle(topEl).fontSize) || 24;
              center.dataset.baseTop = String(baseTop);
            }
            if (!isFinite(baseMid)) {
              baseMid = parseFloat(window.getComputedStyle(midEl).fontSize) || 13;
              center.dataset.baseMid = String(baseMid);
            }
            if (!isFinite(baseBottom)) {
              baseBottom = parseFloat(window.getComputedStyle(bottomEl).fontSize) || 11;
              center.dataset.baseBottom = String(baseBottom);
            }

            topEl.style.fontSize = baseTop + 'px';
            midEl.style.fontSize = baseMid + 'px';
            bottomEl.style.fontSize = baseBottom + 'px';

            const maxWidth = Math.max(76, center.clientWidth - 4);
            const maxHeight = Math.max(68, center.clientHeight - 6);
            let guard = 0;

            while (guard < 36 && (
              topEl.scrollWidth > maxWidth ||
              midEl.scrollWidth > maxWidth ||
              bottomEl.scrollWidth > maxWidth ||
              center.scrollHeight > maxHeight
            )) {
              const topSize = parseFloat(topEl.style.fontSize);
              const midSize = parseFloat(midEl.style.fontSize);
              const bottomSize = parseFloat(bottomEl.style.fontSize);

              if (isFinite(topSize) && topSize > 12) topEl.style.fontSize = (topSize - 0.6) + 'px';
              if (isFinite(midSize) && midSize > 9) midEl.style.fontSize = (midSize - 0.35) + 'px';
              if (isFinite(bottomSize) && bottomSize > 8) bottomEl.style.fontSize = (bottomSize - 0.3) + 'px';
              guard += 1;
            }
          }

          function setCenter(top, mid, bottom){
            const nextTop = top || defaultTop;
            const nextMid = mid || defaultMid;
            const nextBottom = bottom || defaultBottom;
            if (topEl.textContent === nextTop && midEl.textContent === nextMid && bottomEl.textContent === nextBottom) return;
            topEl.textContent = nextTop;
            midEl.textContent = nextMid;
            bottomEl.textContent = nextBottom;
            fitCenterText();
          }

          function setActive(index){
            items.forEach(function(item, i){
              item.classList.toggle('is-active', i === index);
            });
          }

          function preview(index){
            const item = items[index];
            if (!item) return;
            currentIndex = index;
            setCenter(
              item.getAttribute('data-donut-top') || defaultTop,
              item.getAttribute('data-donut-mid') || defaultMid,
              item.getAttribute('data-donut-bottom') || defaultBottom
            );
            setActive(index);
          }

          function resetToDefault(){
            currentIndex = -1;
            setCenter(defaultTop, defaultMid, defaultBottom);
            setActive(-1);
          }

          function indexFromPointer(event){
            const rect = donut.getBoundingClientRect();
            const cx = rect.left + (rect.width / 2);
            const cy = rect.top + (rect.height / 2);
            const dx = event.clientX - cx;
            const dy = event.clientY - cy;
            const radius = Math.sqrt((dx * dx) + (dy * dy));
            const outer = rect.width / 2;
            const inner = outer * 0.73;

            if (radius < inner || radius > outer) return -1;

            const deg = (Math.atan2(dy, dx) * 180 / Math.PI + 90 + 360) % 360;
            const pctPos = (deg / 360) * 100;

            for (let i = 0; i < ranges.length; i += 1) {
              const range = ranges[i];
              if (range.end <= range.start) continue;
              if (pctPos >= range.start && pctPos < range.end) return range.index;
            }
            return -1;
          }

          donut.addEventListener('mousemove', function(event){
            if (lockedIndex !== -1) return;
            const idx = indexFromPointer(event);
            if (idx === currentIndex) return;
            if (idx === -1) resetToDefault(); else preview(idx);
          });

          donut.addEventListener('mouseleave', function(){
            if (lockedIndex === -1) resetToDefault(); else preview(lockedIndex);
          });

          donut.addEventListener('click', function(event){
            const idx = indexFromPointer(event);
            if (idx === -1) {
              lockedIndex = -1;
              resetToDefault();
              return;
            }
            if (lockedIndex === idx) {
              lockedIndex = -1;
              resetToDefault();
            } else {
              lockedIndex = idx;
              preview(idx);
            }
          });

          let resizeTimer = null;
          window.addEventListener('resize', function(){
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(function(){
              if (lockedIndex !== -1) preview(lockedIndex);
              else if (currentIndex !== -1) preview(currentIndex);
              else fitCenterText();
            }, 120);
          });

          fitCenterText();
        });
      }
      function renderSummaryTypes(items){
        if (!summaryTypeGridEl) return;
        if (!Array.isArray(items) || items.length === 0) {
          summaryTypeGridEl.innerHTML = '<div class="muted">No summary data.</div>';
          return;
        }

        const palette = ['#2563eb', '#0ea5e9', '#10b981', '#f59e0b', '#f97316', '#8b5cf6', '#ef4444', '#14b8a6'];
        let total = 0;
        items.forEach(function(item){
          total += Number(item.count || 0);
        });

        if (!Number.isFinite(total) || total <= 0) {
          summaryTypeGridEl.innerHTML = '<div class="muted">No summary data.</div>';
          return;
        }

        const mapped = items.map(function(item, idx){
          const count = Number(item.count || 0);
          const percent = (count / total) * 100;
          const labelRaw = String(item.label ?? '');
          return {
            label: escapeHtml(labelRaw),
            labelAttr: escapeAttr(labelRaw),
            count: count,
            countText: numberFmt.format(count),
            percent: percent,
            percentText: percent.toFixed(1),
            color: palette[idx % palette.length]
          };
        });

        let cursor = 0;
        const gradientParts = [];
        mapped.forEach(function(item){
          if (item.percent <= 0) return;
          const start = cursor;
          const end = Math.min(100, start + item.percent);
          gradientParts.push(item.color + ' ' + start.toFixed(2) + '% ' + end.toFixed(2) + '%');
          cursor = end;
        });

        if (cursor < 100 && gradientParts.length > 0) {
          gradientParts.push('#dbeafe ' + cursor.toFixed(2) + '% 100%');
        }

        const donutFill = gradientParts.length > 0
          ? 'conic-gradient(' + gradientParts.join(', ') + ')'
          : 'conic-gradient(#e2e8f0 0% 100%)';

        const legend = mapped.map(function(item){
          return '<div class="dashboard-donut__item" data-donut-top="' + item.countText + ' record' + (item.count === 1 ? '' : 's') + '" data-donut-mid="' + item.percentText + '%" data-donut-bottom="' + item.labelAttr + '" data-donut-pct="' + item.percent.toFixed(4) + '">' +
            '<span class="dashboard-donut__swatch" style="--dash-swatch: ' + item.color + ';"></span>' +
            '<span class="dashboard-donut__name">' + item.label + '</span>' +
            '<span class="dashboard-donut__meta">' + item.countText + ' (' + item.percentText + '%)</span>' +
          '</div>';
        }).join('');

        summaryTypeGridEl.innerHTML =
          '<div class="dashboard-summary-type__layout js-donut-interactive">' +
            '<div class="dashboard-donut" style="--dash-donut-fill: ' + donutFill + ';">' +
              '<div class="dashboard-donut__center js-donut-center">' +
                '<strong>' + numberFmt.format(total) + ' record' + (total === 1 ? '' : 's') + '</strong>' +
                '<small class="js-donut-mid">100.0%</small>' +
                '<span class="js-donut-sub">All Types</span>' +
              '</div>' +
            '</div>' +
            '<div class="dashboard-donut__legend">' + legend + '</div>' +
          '</div>';

        initInteractiveDonuts(summaryTypeGridEl);
      }
      function renderActiveUsers(items){
        if (!activeUsersListEl) return;
        if (!Array.isArray(items) || items.length === 0) {
          activeUsersListEl.innerHTML = '<div class="muted">No active users right now.</div>';
          return;
        }
        const rows = items.map(function(item){
          const username = escapeHtml(item.username);
          const lastSeen = escapeHtml(item.last_seen_display || '');
          const you = item.is_me ? '<span class="active-you">(You)</span>' : '';
          return '<div class="active-user-item">' +
            '<div class="active-user-meta">' +
              '<div class="active-user-name">' + username + you + '</div>' +
              '<div class="card__sub">Last seen: ' + lastSeen + ' ' + tzLabel + '</div>' +
            '</div>' +
            '<span class="status-pill status-pill--active">Active</span>' +
          '</div>';
        }).join('');
        activeUsersListEl.innerHTML = rows;
      }
      function renderActiveUsersCount(value){
        if (!Number.isFinite(value)) return;
        if (activeUsersBadgeEl) {
          activeUsersBadgeEl.setAttribute('data-active-users', String(value));
          const strongEl = activeUsersBadgeEl.querySelector('strong');
          if (strongEl) strongEl.textContent = numberFmt.format(value);
        }
        if (activeUsersCountFooterEl) {
          activeUsersCountFooterEl.textContent = 'Active users: ' + numberFmt.format(value);
        }
      }

      initInteractiveDonuts(document);

      async function refreshDashboardStats(){
        try {
          const response = await fetch('dashboard_stats.php', {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
          });
          if (!response.ok) return;

          const data = await response.json();
          if (!data || data.ok !== true) return;

          const nextTotal = Number(data.total_records);
          if (Number.isFinite(nextTotal) && nextTotal !== lastTotal) {
            lastTotal = nextTotal;
            totalEl.setAttribute('data-total', String(nextTotal));
            totalEl.textContent = numberFmt.format(nextTotal);
          }

          if (canRenderActiveUsers) {
            const nextActiveUsers = Number(data.active_users_count);
            if (Number.isFinite(nextActiveUsers) && nextActiveUsers !== lastActiveUsers) {
              lastActiveUsers = nextActiveUsers;
              renderActiveUsersCount(nextActiveUsers);
            }
            renderActiveUsers(data.active_users);
          }

          renderSummaryTypes(data.summary_type_items);
        } catch (err) {
          console.error(err);
        }
      }

      setInterval(refreshDashboardStats, 4000);
      document.addEventListener('visibilitychange', function(){
        if (!document.hidden) refreshDashboardStats();
      });
    })();
  </script>
</body>
</html>
