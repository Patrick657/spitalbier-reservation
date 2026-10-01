<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: seating.php');
    exit;
}

$primaryId = filter_var($_POST['table_id'] ?? null, FILTER_VALIDATE_INT);
$neighborId = filter_var($_POST['neighbor_id'] ?? null, FILTER_VALIDATE_INT);
$csrf = (string) ($_POST['csrf'] ?? '');
$back = 'seating.php' . ($primaryId ? '?table=' . $primaryId : '');

if (!$primaryId || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    header('Location: seating.php?msg=badtoken');
    exit;
}

if (!$neighborId || $neighborId === $primaryId) {
    header('Location: ' . $back . '&msg=invalid');
    exit;
}

$pdo = sbf_pdo();

try {
    $pdo->beginTransaction();

    // Lock both rows in a fixed (ascending id) order so two concurrent
    // merge attempts can never deadlock against each other.
    $ids = [$primaryId, $neighborId];
    sort($ids);
    $stmt = $pdo->prepare(
        'SELECT id, row_label, position, seats, base_seats, merged_into
         FROM venue_tables WHERE id IN (:a, :b) FOR UPDATE'
    );
    $stmt->execute(['a' => $ids[0], 'b' => $ids[1]]);
    $rows = $stmt->fetchAll();

    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }
    $primary = $byId[$primaryId] ?? null;
    $neighbor = $byId[$neighborId] ?? null;

    if (!$primary || !$neighbor) {
        $pdo->rollBack();
        header('Location: seating.php?msg=notfound');
        exit;
    }

    $adjacent = $primary['row_label'] === $neighbor['row_label']
        && abs((int) $primary['position'] - (int) $neighbor['position']) === 1;

    if (!$adjacent || $primary['merged_into'] !== null || $neighbor['merged_into'] !== null) {
        $pdo->rollBack();
        header('Location: ' . $back . '&msg=invalid');
        exit;
    }

    // Neither table may already be the primary of another merge (keep merges
    // to simple pairs).
    $childCountStmt = $pdo->prepare('SELECT COUNT(*) FROM venue_tables WHERE merged_into IN (:a, :b)');
    $childCountStmt->execute(['a' => $primaryId, 'b' => $neighborId]);
    if ((int) $childCountStmt->fetchColumn() > 0) {
        $pdo->rollBack();
        header('Location: ' . $back . '&msg=invalid');
        exit;
    }

    // Keep any manually added chairs: the merged table offers the current
    // capacity of both, so existing assignments always still fit.
    $newSeats = (int) $primary['seats'] + (int) $neighbor['seats'];

    $updatePrimary = $pdo->prepare('UPDATE venue_tables SET seats = :seats WHERE id = :id');
    $updatePrimary->execute(['seats' => $newSeats, 'id' => $primaryId]);

    $updateNeighbor = $pdo->prepare('UPDATE venue_tables SET seats = 0, merged_into = :primary WHERE id = :id');
    $updateNeighbor->execute(['primary' => $primaryId, 'id' => $neighborId]);

    // Guests already seated at the neighbor move over to the merged table.
    $moveStmt = $pdo->prepare('UPDATE table_assignments SET table_id = :primary WHERE table_id = :neighbor');
    $moveStmt->execute(['primary' => $primaryId, 'neighbor' => $neighborId]);

    // A reservation that was split across both tables becomes one entry.
    $dupStmt = $pdo->prepare(
        'SELECT reservation_id, MIN(id) AS keep_id, SUM(seats) AS total
         FROM table_assignments
         WHERE table_id = :id AND reservation_id IS NOT NULL
         GROUP BY reservation_id HAVING COUNT(*) > 1'
    );
    $dupStmt->execute(['id' => $primaryId]);
    $keepStmt = $pdo->prepare('UPDATE table_assignments SET seats = :seats WHERE id = :id');
    $dropStmt = $pdo->prepare('DELETE FROM table_assignments WHERE table_id = :table AND reservation_id = :res AND id <> :keep');
    foreach ($dupStmt->fetchAll() as $dup) {
        $keepStmt->execute(['seats' => (int) $dup['total'], 'id' => $dup['keep_id']]);
        $dropStmt->execute(['table' => $primaryId, 'res' => $dup['reservation_id'], 'keep' => $dup['keep_id']]);
    }

    $pdo->commit();
    header('Location: ' . $back . '&msg=merged');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[spitalbierfest] table_merge error: ' . $e->getMessage());
    header('Location: ' . $back . '&msg=invalid');
}
