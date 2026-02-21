<?php
require_once 'auth.php';
secure_session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php');
  exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
  header('Location: index.php?status=error&msg=' . urlencode('Invalid logout request token.'));
  exit;
}

$username = current_auth_user();
audit_log('logout', 'User logged out.', $username !== '' ? $username : null, null);
logout_user();
header('Location: login.php?msg=' . urlencode('You have been logged out.'));
exit;
?>
