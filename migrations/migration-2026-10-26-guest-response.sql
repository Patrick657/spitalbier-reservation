-- Run this once against your EXISTING live database (via phpMyAdmin or
-- `mysql -u ... -p dbname < migration-2026-10-26-guest-response.sql`)
-- BEFORE uploading the new code — the dashboard reads these columns.
-- Lets guests confirm or cancel their table from the link in the
-- Tischbestätigung (public/reservierung.php):
--   manage_token  secret part of the guest's personal link
--   confirmed_at  when the guest confirmed
--   cancelled_by  'guest' or 'admin' (NULL for cancellations made before this)
-- Safe to run on the live database — it only adds empty columns.

ALTER TABLE reservations
  ADD COLUMN manage_token VARCHAR(32) DEFAULT NULL AFTER source,
  ADD COLUMN confirmed_at DATETIME DEFAULT NULL AFTER manage_token,
  ADD COLUMN cancelled_by VARCHAR(10) DEFAULT NULL AFTER confirmed_at,
  ADD UNIQUE KEY uniq_manage_token (manage_token);
