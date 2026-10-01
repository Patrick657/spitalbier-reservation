-- Run this once against your EXISTING live database (via phpMyAdmin or
-- `mysql -u ... -p dbname < migration-2026-10-01-table-mail.sql`).
-- Remembers the text of the batch "Tischbestätigung" mail (dashboard >
-- Tischbestätigung) between visits. Optional: without this table the page
-- still works, it just starts from the default text every time.
-- Safe to run on the live database — it only adds a new table.

CREATE TABLE IF NOT EXISTS mail_templates (
  name VARCHAR(20) NOT NULL PRIMARY KEY,
  subject VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
