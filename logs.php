<?php
require_once "auth.php";
require_login();
require_once "db.php";

ensure_audit_log_table();
ensure_active_session_table();
cleanup_expired_active_sessions();

$q = trim((string)($_GET["q"] ?? ""));
$actionFilter = trim((string)($_GET["action"] ?? ""));
$limit = (int)($_GET["limit"] ?? 200);
if ($limit < 50) $limit = 50;
if ($limit > 1000) $limit = 1000;

$actions = [];
$actionRs = @$conn->query("SELECT DISTINCT action FROM auth_audit_logs ORDER BY action ASC LIMIT 250");
if ($actionRs) {
  while ($row = $actionRs->fetch_assoc()) {
    $a = trim((string)($row["action"] ?? ""));
    if ($a !== "") $actions[] = $a;
  }
}

$totalLogs = 0;
$countRs = @$conn->query("SELECT COUNT(*) AS total_logs FROM auth_audit_logs");
if ($countRs && $row = $countRs->fetch_assoc()) {
  $totalLogs = (int)($row["total_logs"] ?? 0);
}

$where = [];
$types = "";
$params = [];

if ($q !== "") {
  $where[] = "(l.username LIKE ? OR l.details LIKE ? OR l.action LIKE ?)";
  $types .= "sss";
  $like = "%" . $q . "%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

if ($actionFilter !== "") {
  $where[] = "l.action = ?";
  $types .= "s";
  $params[] = $actionFilter;
}

$sql = "SELECT
          l.log_id,
          l.username,
          l.action,
          l.record_id,
          l.details,
          l.ip_address,
          l.created_at,
          CASE
            WHEN s.username IS NOT NULL AND s.expires_at >= UTC_TIMESTAMP() THEN 'Active'
            ELSE 'Inactive'
          END AS current_status
        FROM auth_audit_logs l
        LEFT JOIN auth_active_sessions s ON s.username = l.username";

if (!empty($where)) {
  $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY l.log_id DESC LIMIT ?";
$types .= "i";
$params[] = $limit;

$logs = null;
$stmt = $conn->prepare($sql);
if ($stmt) {
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $logs = $stmt->get_result();
}

$userStatusSql = "SELECT
                    u.username,
                    CASE
                      WHEN s.username IS NOT NULL AND s.expires_at >= UTC_TIMESTAMP() THEN 'Active'
                      ELSE 'Inactive'
                    END AS current_status,
                    s.last_seen,
                    s.expires_at,
                    MAX(CASE WHEN l.action = 'login_success' THEN l.created_at ELSE NULL END) AS last_login_at
                  FROM (SELECT DISTINCT username FROM auth_audit_logs WHERE username <> '') u
                  LEFT JOIN auth_active_sessions s ON s.username = u.username
                  LEFT JOIN auth_audit_logs l ON l.username = u.username
                  GROUP BY u.username, s.username, s.last_seen, s.expires_at
                  ORDER BY u.username ASC";
$userStatuses = @$conn->query($userStatusSql);
$tzLabel = app_timezone_label();
$isLogsSuperAdmin = is_super_admin();
$logsBackUrl = $isLogsSuperAdmin ? "super_admin.php" : "index.php";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Activity Logs</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="app">
    <header class="app-header">
      <div class="brand-text">
        <h1>Activity Logs</h1>
        <p>Who logged in, who entered data, and who updated records</p>
      </div>
      <div class="header-meta">
        <a class="btn btn--secondary btn--sm" href="<?php echo htmlspecialchars($logsBackUrl); ?>">Back</a>
        <a class="btn btn--secondary btn--sm" href="records.php">All Records</a>
      </div>
    </header>

    <section class="card">
      <div class="card__header card__header--row">
        <div>
          <h2 class="card__title">Log Entries</h2>
          <p class="card__sub">Showing up to <?php echo (int)$limit; ?> rows. Total logs: <?php echo number_format($totalLogs); ?>.</p>
        </div>

        <form class="search" method="GET" action="logs.php">
          <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search username, action, details..." />
          <select name="action" style="width:auto; min-width: 200px;">
            <option value="">All actions</option>
            <?php foreach ($actions as $a): ?>
              <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $actionFilter === $a ? "selected" : ""; ?>>
                <?php echo htmlspecialchars($a); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn--sm" type="submit">Filter</button>
          <?php if ($q !== "" || $actionFilter !== ""): ?>
            <a class="btn btn--secondary btn--sm" href="logs.php">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="card__body">
        <div class="table-wrap">
          <div class="table-scroll" style="max-height: 560px; overflow-y:auto;">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>User</th>
                  <th>Status</th>
                  <th>Action</th>
                  <th>Record ID</th>
                  <th>Details</th>
                  <th>IP</th>
                  <th>Date-Time (<?php echo htmlspecialchars($tzLabel); ?>)</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($logs && $logs->num_rows > 0): ?>
                  <?php while ($log = $logs->fetch_assoc()): ?>
                    <?php
                      $isActive = ((string)($log["current_status"] ?? "") === "Active");
                      $createdAtDisplay = format_utc_datetime_for_app((string)($log["created_at"] ?? ""));
                    ?>
                    <tr>
                      <td class="mono"><?php echo htmlspecialchars((string)$log["log_id"]); ?></td>
                      <td class="strong"><?php echo htmlspecialchars((string)$log["username"]); ?></td>
                      <td>
                        <span class="status-pill <?php echo $isActive ? "status-pill--active" : "status-pill--inactive"; ?>">
                          <?php echo $isActive ? "Active" : "Inactive"; ?>
                        </span>
                      </td>
                      <td><?php echo htmlspecialchars((string)$log["action"]); ?></td>
                      <td class="mono"><?php echo htmlspecialchars((string)($log["record_id"] ?? "")); ?></td>
                      <td><?php echo htmlspecialchars((string)$log["details"]); ?></td>
                      <td class="mono"><?php echo htmlspecialchars((string)$log["ip_address"]); ?></td>
                      <td class="mono"><?php echo htmlspecialchars($createdAtDisplay !== "" ? $createdAtDisplay : "-"); ?></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="8" class="muted">No logs found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

    <section class="card section">
      <div class="card__header">
        <h2 class="card__title">User Status</h2>
        <p class="card__sub">Current activity status per username.</p>
      </div>
      <div class="card__body">
        <div class="table-wrap">
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Username</th>
                  <th>Status</th>
                  <th>Last Login (<?php echo htmlspecialchars($tzLabel); ?>)</th>
                  <th>Last Seen (<?php echo htmlspecialchars($tzLabel); ?>)</th>
                  <th>Session Expires (<?php echo htmlspecialchars($tzLabel); ?>)</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($userStatuses && $userStatuses->num_rows > 0): ?>
                  <?php while ($u = $userStatuses->fetch_assoc()): ?>
                    <?php
                      $isActive = ((string)($u["current_status"] ?? "") === "Active");
                      $lastLoginDisplay = format_utc_datetime_for_app((string)($u["last_login_at"] ?? ""));
                      $lastSeenDisplay = format_utc_datetime_for_app((string)($u["last_seen"] ?? ""));
                      $expiresAtDisplay = format_utc_datetime_for_app((string)($u["expires_at"] ?? ""));
                    ?>
                    <tr>
                      <td class="strong"><?php echo htmlspecialchars((string)$u["username"]); ?></td>
                      <td>
                        <span class="status-pill <?php echo $isActive ? "status-pill--active" : "status-pill--inactive"; ?>">
                          <?php echo $isActive ? "Active" : "Inactive"; ?>
                        </span>
                      </td>
                      <td class="mono"><?php echo htmlspecialchars($lastLoginDisplay !== "" ? $lastLoginDisplay : "-"); ?></td>
                      <td class="mono"><?php echo htmlspecialchars($lastSeenDisplay !== "" ? $lastSeenDisplay : "-"); ?></td>
                      <td class="mono"><?php echo htmlspecialchars($expiresAtDisplay !== "" ? $expiresAtDisplay : "-"); ?></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="5" class="muted">No user activity found yet.</td>
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
