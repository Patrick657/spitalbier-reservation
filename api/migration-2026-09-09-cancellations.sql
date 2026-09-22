-- Run this once against your EXISTING live database (via phpMyAdmin or
-- `mysql -u ... -p dbname < migration-2026-09-09-cancellations.sql`).
-- Adds the ability to cancel a reservation from the new dashboard.
-- Safe to run even if reservations already contain data — it only adds a
-- column, it does not touch existing rows.

ALTER TABLE reservations
  ADD COLUMN cancelled_at DATETIME DEFAULT NULL;
