<?php
require_once "auth.php";
require_super_admin();
require_once "db.php";

function normalize_return_to(string $raw): string {
  $fallback = "super_admin.php#all-assistance-section";
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

function edit_error_redirect(int $recordId, string $returnTo, string $message, bool $isPopup): string {
  $url = "edit.php?record_id=" . urlencode((string)$recordId) .
    "&return_to=" . urlencode($returnTo) .
    "&status=error&msg=" . urlencode($message);
  if ($isPopup) {
    $url .= "&popup=1";
  }
  return $url;
}

$recordId = (int)($_POST["record_id"] ?? 0);
$csrfToken = $_POST["csrf_token"] ?? "";
$returnTo = normalize_return_to((string)($_POST["return_to"] ?? ""));
$isPopup = (isset($_POST["popup"]) && (string)$_POST["popup"] === "1");

if ($recordId <= 0) {
  header("Location: " . with_status_message($returnTo, "error", "Invalid record selected for deletion."));
  exit;
}

if (!verify_csrf_token($csrfToken)) {
  header("Location: " . edit_error_redirect($recordId, $returnTo, "Invalid request token. Please refresh and try again.", $isPopup));
  exit;
}

$nameStmt = $conn->prepare("SELECT name FROM records WHERE record_id = ? LIMIT 1");
if (!$nameStmt) {
  header("Location: " . edit_error_redirect($recordId, $returnTo, "Database error: " . $conn->error, $isPopup));
  exit;
}

$nameStmt->bind_param("i", $recordId);
$nameStmt->execute();
$nameRs = $nameStmt->get_result();
$nameRow = $nameRs ? $nameRs->fetch_assoc() : null;
$nameStmt->close();

if (!$nameRow) {
  header("Location: " . with_status_message($returnTo, "error", "Record not found."));
  exit;
}

$recordName = trim((string)($nameRow["name"] ?? ""));

$deleteStmt = $conn->prepare("DELETE FROM records WHERE record_id = ? LIMIT 1");
if (!$deleteStmt) {
  header("Location: " . edit_error_redirect($recordId, $returnTo, "Database error: " . $conn->error, $isPopup));
  exit;
}

$deleteStmt->bind_param("i", $recordId);
if (!$deleteStmt->execute()) {
  $error = $deleteStmt->error;
  $deleteStmt->close();
  header("Location: " . edit_error_redirect($recordId, $returnTo, "Failed deleting record: " . $error, $isPopup));
  exit;
}

$affected = (int)$deleteStmt->affected_rows;
$deleteStmt->close();

if ($affected < 1) {
  header("Location: " . with_status_message($returnTo, "error", "Record not found."));
  exit;
}

$actor = current_auth_user();
audit_log(
  "record_delete",
  "Super admin deleted record #" . $recordId . " for \"" . $recordName . "\".",
  $actor !== "" ? $actor : null,
  $recordId
);

header("Location: " . with_status_message($returnTo, "success", "Record deleted successfully."));
exit;
?>
