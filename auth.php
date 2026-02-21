<?php
require_once __DIR__ . '/db.php';
date_default_timezone_set('Asia/Manila');

function app_timezone_name(): string {
  return 'Asia/Manila';
}

function app_timezone_label(): string {
  return 'PHT';
}

function format_utc_datetime_for_app(?string $value): string {
  $raw = trim((string)$value);
  if ($raw === '') {
    return '';
  }

  try {
    $utc = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
  } catch (Throwable $e) {
    $ts = strtotime($raw);
    if ($ts === false) {
      return $raw;
    }
    $utc = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
  }

  $local = $utc->setTimezone(new DateTimeZone(app_timezone_name()));
  return $local->format('Y-m-d h:i:s A');
}

function secure_session_start(): void {
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  $sessionLifetime = session_timeout_seconds();
  if ($sessionLifetime < 300) {
    $sessionLifetime = 300;
  }

  session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  ini_set('session.use_only_cookies', '1');
  ini_set('session.use_strict_mode', '1');
  ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
  session_start();
}

function auth_cookie_name(): string {
  return 'assist_auth';
}

function auth_cookie_options(int $expiresAt): array {
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  return [
    'expires' => $expiresAt,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ];
}

function set_persistent_auth_cookie(string $username, string $token): void {
  $payload = base64_encode($username . '|' . $token);
  $expiresAt = time() + session_timeout_seconds();
  setcookie(auth_cookie_name(), $payload, auth_cookie_options($expiresAt));
  $_COOKIE[auth_cookie_name()] = $payload;
}

function clear_persistent_auth_cookie(): void {
  setcookie(auth_cookie_name(), '', auth_cookie_options(time() - 3600));
  unset($_COOKIE[auth_cookie_name()]);
}

function read_persistent_auth_cookie(): array {
  $raw = trim((string)($_COOKIE[auth_cookie_name()] ?? ''));
  if ($raw === '') {
    return ['', ''];
  }

  $decoded = base64_decode($raw, true);
  if (!is_string($decoded) || $decoded === '') {
    clear_persistent_auth_cookie();
    return ['', ''];
  }

  $parts = explode('|', $decoded, 2);
  if (count($parts) !== 2) {
    clear_persistent_auth_cookie();
    return ['', ''];
  }

  $username = trim((string)$parts[0]);
  $token = trim((string)$parts[1]);
  if ($username === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    clear_persistent_auth_cookie();
    return ['', ''];
  }

  return [$username, $token];
}

function restore_login_from_cookie(): bool {
  secure_session_start();

  if (!empty($_SESSION['auth_user'])) {
    return true;
  }

  [$username, $token] = read_persistent_auth_cookie();
  if ($username === '' || $token === '') {
    return false;
  }

  if (!has_active_session_slot($username, $token)) {
    clear_persistent_auth_cookie();
    return false;
  }

  $_SESSION['auth_user'] = $username;
  $_SESSION['auth_role'] = fetch_user_role($username);
  $_SESSION['auth_session_token'] = $token;
  $_SESSION['last_activity'] = time();
  touch_active_session_slot($username, $token);
  return true;
}

function auth_credentials(): array {
  $defaultUser = 'admin';
  $defaultPass = 'admin1234';

  $user = trim((string)(getenv('BURIAL_APP_USER') ?: $defaultUser));
  $pass = (string)(getenv('BURIAL_APP_PASS') ?: $defaultPass);

  return [$user, $pass];
}

function super_admin_credentials(): array {
  $defaultUser = 'superadmin';
  $defaultPass = 'superadmin1234';

  $user = trim((string)(getenv('BURIAL_SUPERADMIN_USER') ?: $defaultUser));
  $pass = (string)(getenv('BURIAL_SUPERADMIN_PASS') ?: $defaultPass);

  return [$user, $pass];
}

function default_role_for_username(string $username): string {
  [$superUser] = super_admin_credentials();
  if ($username !== '' && hash_equals($superUser, $username)) {
    return 'super_admin';
  }
  return 'admin';
}

function users_table_has_column(string $column): bool {
  global $conn;
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return false;
  }
  $stmt->bind_param('s', $column);
  $stmt->execute();
  $result = $stmt->get_result();
  $exists = $result && $result->num_rows > 0;
  $stmt->close();
  return $exists;
}

function session_timeout_seconds(): int {
  $defaultSeconds = 604800; // 7 days
  $raw = getenv('BURIAL_SESSION_TIMEOUT_SEC');
  if ($raw === false || $raw === '') {
    return $defaultSeconds;
  }

  $value = (int)$raw;
  if ($value < 300) {
    return 300;
  }
  return $value;
}

function ensure_active_session_table(): bool {
  global $conn;
  static $ready = false;

  if ($ready) {
    return true;
  }

  if (!isset($conn) || !($conn instanceof mysqli)) {
    return false;
  }

  $sql = "CREATE TABLE IF NOT EXISTS auth_active_sessions (
    username VARCHAR(191) NOT NULL PRIMARY KEY,
    session_token VARCHAR(128) NOT NULL,
    created_at DATETIME NOT NULL,
    last_seen DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_auth_active_sessions_expires_at (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  if (!$conn->query($sql)) {
    return false;
  }

  $ready = true;
  return true;
}

function cleanup_expired_active_sessions(): void {
  global $conn;

  if (!ensure_active_session_table()) {
    return;
  }

  @$conn->query("DELETE FROM auth_active_sessions WHERE expires_at < UTC_TIMESTAMP()");
}

function acquire_active_session_slot(string $username, string $token): bool {
  global $conn;

  if (!ensure_active_session_table()) {
    // Fail-open when lock table cannot be managed, so login itself still works.
    return true;
  }

  cleanup_expired_active_sessions();

  $timeout = session_timeout_seconds();
  $sql = "INSERT INTO auth_active_sessions (username, session_token, created_at, last_seen, expires_at)
          VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
          ON DUPLICATE KEY UPDATE username = username";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return true;
  }

  $stmt->bind_param('ssi', $username, $token, $timeout);
  $ok = $stmt->execute();
  $affected = $stmt->affected_rows;
  $stmt->close();

  if (!$ok) {
    return true;
  }

  // 1 = inserted (slot acquired), 0 = username already has active session.
  return $affected > 0;
}

function replace_active_session_slot(string $username, string $token): bool {
  global $conn;

  if (!ensure_active_session_table()) {
    return true;
  }

  cleanup_expired_active_sessions();

  $timeout = session_timeout_seconds();
  $sql = "INSERT INTO auth_active_sessions (username, session_token, created_at, last_seen, expires_at)
          VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
          ON DUPLICATE KEY UPDATE
            session_token = VALUES(session_token),
            created_at = UTC_TIMESTAMP(),
            last_seen = UTC_TIMESTAMP(),
            expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return true;
  }

  $stmt->bind_param('ssii', $username, $token, $timeout, $timeout);
  $ok = $stmt->execute();
  $stmt->close();

  return (bool)$ok;
}

function has_active_session_slot(string $username, string $token): bool {
  global $conn;

  if (!ensure_active_session_table()) {
    return true;
  }

  $sql = "SELECT 1
          FROM auth_active_sessions
          WHERE username = ? AND session_token = ? AND expires_at >= UTC_TIMESTAMP()
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return true;
  }

  $stmt->bind_param('ss', $username, $token);
  $stmt->execute();
  $result = $stmt->get_result();
  $exists = $result && $result->num_rows > 0;
  $stmt->close();

  return $exists;
}

function touch_active_session_slot(string $username, string $token): void {
  global $conn;

  if (!ensure_active_session_table()) {
    return;
  }

  $timeout = session_timeout_seconds();
  $sql = "UPDATE auth_active_sessions
          SET last_seen = UTC_TIMESTAMP(),
              expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)
          WHERE username = ? AND session_token = ? AND expires_at >= UTC_TIMESTAMP()
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return;
  }

  $stmt->bind_param('iss', $timeout, $username, $token);
  $stmt->execute();
  $stmt->close();
}

function release_active_session_slot(string $username, string $token): void {
  global $conn;

  if (!ensure_active_session_table()) {
    return;
  }

  $stmt = $conn->prepare(
    "DELETE FROM auth_active_sessions WHERE username = ? AND session_token = ? LIMIT 1"
  );
  if (!$stmt) {
    return;
  }

  $stmt->bind_param('ss', $username, $token);
  $stmt->execute();
  $stmt->close();
}

function ensure_audit_log_table(): bool {
  global $conn;
  static $ready = false;

  if ($ready) {
    return true;
  }

  if (!isset($conn) || !($conn instanceof mysqli)) {
    return false;
  }

  $sql = "CREATE TABLE IF NOT EXISTS auth_audit_logs (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(191) NOT NULL,
    action VARCHAR(64) NOT NULL,
    record_id INT NULL,
    details TEXT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_auth_audit_logs_created_at (created_at),
    INDEX idx_auth_audit_logs_username (username),
    INDEX idx_auth_audit_logs_action (action),
    INDEX idx_auth_audit_logs_record_id (record_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  if (!$conn->query($sql)) {
    return false;
  }

  $ready = true;
  return true;
}

function current_auth_user(): string {
  secure_session_start();
  return trim((string)($_SESSION['auth_user'] ?? ''));
}

function current_auth_role(): string {
  secure_session_start();
  $role = trim((string)($_SESSION['auth_role'] ?? ''));
  if ($role !== '') {
    return $role;
  }
  $username = current_auth_user();
  if ($username === '') {
    return 'admin';
  }
  $resolved = fetch_user_role($username);
  $_SESSION['auth_role'] = $resolved;
  return $resolved;
}

function is_super_admin(): bool {
  return current_auth_role() === 'super_admin';
}

function client_ip_address(): string {
  $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  return $ip !== '' ? $ip : 'unknown';
}

function audit_log(string $action, string $details = '', ?string $username = null, ?int $recordId = null): void {
  global $conn;

  if (!ensure_audit_log_table()) {
    return;
  }

  $user = trim((string)($username ?? current_auth_user()));
  if ($user === '') {
    $user = 'unknown';
  }

  $act = trim($action);
  if ($act === '') {
    $act = 'event';
  }
  if (strlen($act) > 64) {
    $act = substr($act, 0, 64);
  }

  $detailText = trim($details);
  if (strlen($detailText) > 5000) {
    $detailText = substr($detailText, 0, 5000);
  }

  $ip = client_ip_address();

  if ($recordId === null) {
    $stmt = $conn->prepare(
      "INSERT INTO auth_audit_logs (username, action, record_id, details, ip_address, created_at)
       VALUES (?, ?, NULL, ?, ?, UTC_TIMESTAMP())"
    );
    if (!$stmt) {
      return;
    }

    $stmt->bind_param('ssss', $user, $act, $detailText, $ip);
    $stmt->execute();
    $stmt->close();
    return;
  }

  $stmt = $conn->prepare(
    "INSERT INTO auth_audit_logs (username, action, record_id, details, ip_address, created_at)
     VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
  );
  if (!$stmt) {
    return;
  }

  $stmt->bind_param('ssiss', $user, $act, $recordId, $detailText, $ip);
  $stmt->execute();
  $stmt->close();
}

function fetch_user_password(string $username): ?string {
  global $conn;

  if (!isset($conn) || !($conn instanceof mysqli)) {
    return null;
  }

  $sql = "SELECT `password` FROM `users` WHERE `user` = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return null;
  }

  $stmt->bind_param('s', $username);
  $stmt->execute();
  $result = $stmt->get_result();
  $row = $result ? $result->fetch_assoc() : null;
  $stmt->close();

  if (!$row || !array_key_exists('password', $row)) {
    return null;
  }

  return (string)$row['password'];
}

function fetch_user_role(string $username): string {
  global $conn;

  if ($username === '') {
    return 'admin';
  }

  if (!isset($conn) || !($conn instanceof mysqli)) {
    return default_role_for_username($username);
  }

  if (!users_table_has_column('role')) {
    return default_role_for_username($username);
  }

  $sql = "SELECT `role` FROM `users` WHERE `user` = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return default_role_for_username($username);
  }

  $stmt->bind_param('s', $username);
  $stmt->execute();
  $result = $stmt->get_result();
  $row = $result ? $result->fetch_assoc() : null;
  $stmt->close();

  $role = trim((string)($row['role'] ?? ''));
  if ($role === '') {
    return default_role_for_username($username);
  }
  return $role;
}

function is_logged_in(): bool {
  if (empty($_SESSION['auth_user']) && !restore_login_from_cookie()) {
    return false;
  }

  $last = (int)($_SESSION['last_activity'] ?? 0);
  if ($last > 0 && (time() - $last) > session_timeout_seconds()) {
    $timedOutUser = (string)($_SESSION['auth_user'] ?? '');
    audit_log('session_timeout', 'Session expired due to inactivity.', $timedOutUser !== '' ? $timedOutUser : null, null);
    logout_user();
    return false;
  }

  $username = (string)($_SESSION['auth_user'] ?? '');
  if ($username === '') {
    return false;
  }

  if (empty($_SESSION['auth_role'])) {
    $_SESSION['auth_role'] = fetch_user_role($username);
  }

  $token = (string)($_SESSION['auth_session_token'] ?? '');
  if ($token === '') {
    $token = bin2hex(random_bytes(32));
    if (!acquire_active_session_slot($username, $token)) {
      logout_user();
      return false;
    }
    $_SESSION['auth_session_token'] = $token;
  }

  if (!has_active_session_slot($username, $token)) {
    audit_log('session_invalidated', 'Session ended because the active session slot is missing or expired.', $username, null);
    logout_user();
    return false;
  }

  touch_active_session_slot($username, $token);

  $_SESSION['last_activity'] = time();
  return true;
}

function login_user(string $username): bool {
  session_regenerate_id(true);

  $token = bin2hex(random_bytes(32));
  if (!acquire_active_session_slot($username, $token)) {
    return false;
  }

  $_SESSION['auth_user'] = $username;
  $_SESSION['auth_role'] = fetch_user_role($username);
  $_SESSION['auth_session_token'] = $token;
  $_SESSION['last_activity'] = time();
  set_persistent_auth_cookie($username, $token);
  return true;
}

function login_user_with_takeover(string $username): bool {
  session_regenerate_id(true);

  $token = bin2hex(random_bytes(32));
  if (!replace_active_session_slot($username, $token)) {
    return false;
  }

  $_SESSION['auth_user'] = $username;
  $_SESSION['auth_role'] = fetch_user_role($username);
  $_SESSION['auth_session_token'] = $token;
  $_SESSION['last_activity'] = time();
  set_persistent_auth_cookie($username, $token);
  return true;
}

function attempt_login(string $username, string $password): bool {
  $storedPassword = fetch_user_password($username);
  if ($storedPassword !== null) {
    if (password_verify($password, $storedPassword)) {
      return true;
    }
    return hash_equals($storedPassword, $password);
  }

  [$superUser, $superPass] = super_admin_credentials();
  if (hash_equals($superUser, $username) && hash_equals($superPass, $password)) {
    return true;
  }

  [$validUser, $validPass] = auth_credentials();
  return hash_equals($validUser, $username) && hash_equals($validPass, $password);
}

function require_login(): void {
  secure_session_start();

  if (is_logged_in()) {
    return;
  }

  $target = rawurldecode((string)($_SERVER['REQUEST_URI'] ?? 'index.php'));
  header('Location: login.php?redirect=' . rawurlencode($target));
  exit;
}

function require_super_admin(): void {
  require_login();
  if (is_super_admin()) {
    return;
  }
  header('Location: index.php?status=error&msg=' . urlencode('Super admin access required.'));
  exit;
}

function logout_user(): void {
  $username = (string)($_SESSION['auth_user'] ?? '');
  $token = (string)($_SESSION['auth_session_token'] ?? '');
  if ($username !== '' && $token !== '') {
    release_active_session_slot($username, $token);
  }
  clear_persistent_auth_cookie();

  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
  }
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
  }
}

function csrf_token(): string {
  secure_session_start();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
  secure_session_start();
  $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
  return $sessionToken !== '' && is_string($token) && hash_equals($sessionToken, $token);
}
?>
