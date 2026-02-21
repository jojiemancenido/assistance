<?php
require_once "auth.php";
require_super_admin();
require_once "db.php";

$recordId = (int)($_POST["record_id"] ?? 0);
$csrfToken = $_POST["csrf_token"] ?? "";

if ($recordId <= 0) {
  header("Location: super_admin.php?status=error&msg=" . urlencode("Invalid record selected for deletion.") . "#all-assistance-section");
  exit;
}

if (!verify_csrf_token($csrfToken)) {
  header("Location: edit.php?record_id=" . urlencode((string)$recordId) . "&status=error&msg=" . urlencode("Invalid request token. Please refresh and try again."));
  exit;
}

$nameStmt = $conn->prepare("SELECT name FROM records WHERE record_id = ? LIMIT 1");
if (!$nameStmt) {
  header("Location: edit.php?record_id=" . urlencode((string)$recordId) . "&status=error&msg=" . urlencode("Database error: " . $conn->error));
  exit;
}

$nameStmt->bind_param("i", $recordId);
$nameStmt->execute();
$nameRs = $nameStmt->get_result();
$nameRow = $nameRs ? $nameRs->fetch_assoc() : null;
$nameStmt->close();

if (!$nameRow) {
  header("Location: super_admin.php?status=error&msg=" . urlencode("Record not found.") . "#all-assistance-section");
  exit;
}

$recordName = trim((string)($nameRow["name"] ?? ""));

$deleteStmt = $conn->prepare("DELETE FROM records WHERE record_id = ? LIMIT 1");
if (!$deleteStmt) {
  header("Location: edit.php?record_id=" . urlencode((string)$recordId) . "&status=error&msg=" . urlencode("Database error: " . $conn->error));
  exit;
}

$deleteStmt->bind_param("i", $recordId);
if (!$deleteStmt->execute()) {
  $error = $deleteStmt->error;
  $deleteStmt->close();
  header("Location: edit.php?record_id=" . urlencode((string)$recordId) . "&status=error&msg=" . urlencode("Failed deleting record: " . $error));
  exit;
}

$affected = (int)$deleteStmt->affected_rows;
$deleteStmt->close();

if ($affected < 1) {
  header("Location: super_admin.php?status=error&msg=" . urlencode("Record not found.") . "#all-assistance-section");
  exit;
}

$actor = current_auth_user();
audit_log(
  "record_delete",
  "Super admin deleted record #" . $recordId . " for \"" . $recordName . "\".",
  $actor !== "" ? $actor : null,
  $recordId
);

header("Location: super_admin.php?status=success&msg=" . urlencode("Record deleted successfully.") . "#all-assistance-section");
exit;
?>
