-- Run this once against your EXISTING live database (via phpMyAdmin or
-- `mysql -u ... -p dbname < migration-2026-09-10-seating.sql`).
-- Adds the seating plan: 44 physical tables (4 rows x 11, 6 seats each)
-- and the ability to assign reservations (or manual blocks) to them.
-- Safe to run on the live database — it only adds new tables, and the
-- INSERT IGNORE below is safe to re-run (the unique code just skips
-- rows that already exist).

CREATE TABLE IF NOT EXISTS venue_tables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(8) NOT NULL,
  row_label CHAR(1) NOT NULL,
  position TINYINT UNSIGNED NOT NULL,
  seats TINYINT UNSIGNED NOT NULL DEFAULT 6,
  UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS table_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_id INT UNSIGNED NOT NULL,
  reservation_id INT UNSIGNED DEFAULT NULL,
  seats TINYINT UNSIGNED NOT NULL,
  note VARCHAR(190) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ta_table FOREIGN KEY (table_id) REFERENCES venue_tables(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4 Reihen (A-D) x 11 Tische, je 6 Plätze.
INSERT IGNORE INTO venue_tables (code, row_label, position, seats) VALUES
  ('A-01', 'A', 1, 6), ('A-02', 'A', 2, 6), ('A-03', 'A', 3, 6), ('A-04', 'A', 4, 6), ('A-05', 'A', 5, 6),
  ('A-06', 'A', 6, 6), ('A-07', 'A', 7, 6), ('A-08', 'A', 8, 6), ('A-09', 'A', 9, 6), ('A-10', 'A', 10, 6),
  ('A-11', 'A', 11, 6),
  ('B-01', 'B', 1, 6), ('B-02', 'B', 2, 6), ('B-03', 'B', 3, 6), ('B-04', 'B', 4, 6), ('B-05', 'B', 5, 6),
  ('B-06', 'B', 6, 6), ('B-07', 'B', 7, 6), ('B-08', 'B', 8, 6), ('B-09', 'B', 9, 6), ('B-10', 'B', 10, 6),
  ('B-11', 'B', 11, 6),
  ('C-01', 'C', 1, 6), ('C-02', 'C', 2, 6), ('C-03', 'C', 3, 6), ('C-04', 'C', 4, 6), ('C-05', 'C', 5, 6),
  ('C-06', 'C', 6, 6), ('C-07', 'C', 7, 6), ('C-08', 'C', 8, 6), ('C-09', 'C', 9, 6), ('C-10', 'C', 10, 6),
  ('C-11', 'C', 11, 6),
  ('D-01', 'D', 1, 6), ('D-02', 'D', 2, 6), ('D-03', 'D', 3, 6), ('D-04', 'D', 4, 6), ('D-05', 'D', 5, 6),
  ('D-06', 'D', 6, 6), ('D-07', 'D', 7, 6), ('D-08', 'D', 8, 6), ('D-09', 'D', 9, 6), ('D-10', 'D', 10, 6),
  ('D-11', 'D', 11, 6);
