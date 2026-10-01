-- Run this once against your EXISTING live database (via phpMyAdmin or
-- `mysql -u ... -p dbname < migration-2026-10-01-mail-log.sql`).
-- Adds the e-mail log shown in the dashboard under "E-Mail-Log".
-- Safe to run on the live database — it only adds a new table.

CREATE TABLE IF NOT EXISTS mail_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recipient_email VARCHAR(190) NOT NULL,
  recipient_name VARCHAR(190) DEFAULT NULL,
  subject VARCHAR(190) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  reservation_code VARCHAR(16) DEFAULT NULL,
  status VARCHAR(10) NOT NULL,
  error VARCHAR(255) DEFAULT NULL,
  KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
