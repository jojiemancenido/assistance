<?php
// db.php (XAMPP defaults)
$host = "https://server400.web-hosting.com/";
$user = "daetirbb";
$pass = "panel_NameCh3ap2026";
$dbname = "daetirbb_assistance_record";

$conn = new mysqli($host, $user, $pass);

if ($conn->connect_error) {
  die("DB Connection failed: " . $conn->connect_error);
}

$escapedDbName = str_replace("`", "``", $dbname);
if (!$conn->query("CREATE DATABASE IF NOT EXISTS `{$escapedDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
  die("DB Initialization failed: " . $conn->error);
}

if (!$conn->select_db($dbname)) {
  die("DB Selection failed: " . $conn->error);
}

$conn->set_charset("utf8mb4");

$recordsTableSql = "CREATE TABLE IF NOT EXISTS records (
  record_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL,
  type VARCHAR(100) NOT NULL,
  type_specify VARCHAR(191) NULL DEFAULT NULL,
  barangay VARCHAR(191) NOT NULL,
  office_scope VARCHAR(32) NOT NULL DEFAULT 'municipality',
  municipality VARCHAR(191) NOT NULL DEFAULT 'Daet',
  province VARCHAR(191) NOT NULL DEFAULT 'Camarines Norte',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  record_date DATE NOT NULL,
  month_year CHAR(7) NOT NULL,
  notes TEXT NULL,
  age INT NULL DEFAULT NULL,
  birthdate DATE NULL DEFAULT NULL,
  contact_number VARCHAR(64) NULL DEFAULT NULL,
  diagnosis TEXT NULL,
  hospital VARCHAR(191) NULL DEFAULT NULL,
  contact_person VARCHAR(191) NULL DEFAULT NULL,
  INDEX idx_records_record_date (record_date),
  INDEX idx_records_month_year (month_year),
  INDEX idx_records_barangay (barangay),
  INDEX idx_records_office_scope (office_scope),
  INDEX idx_records_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$conn->query($recordsTableSql)) {
  die("DB Schema failed: " . $conn->error);
}
