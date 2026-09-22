<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: seating.php');
    exit;
}

$tableId = filter_var($_POST['table_id'] ?? null, FILTER_VALIDATE_INT);
$seats = filter_var($_POST['seats'] ?? null, FILTER_VALIDATE_INT);
$csrf = (string) ($_POST['csrf'] ?? '');
$back = 'seating.php' . ($tableId ? '?table=' . $tableId : '');

if (!$tableId || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    header('Location: seating.php?msg=badtoken');
    exit;
}

if ($seats === false || $seats < 1 || $seats > 30) {
    header('Location: ' . $back . '&msg=invalid');
    exit;
}

$pdo = sbf_pdo();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, merged_into FROM venue_tables WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $tableId]);
    $table = $stmt->fetch();

    if (!$table) {
        $pdo->rollBack();
        header('Location: seating.php?msg=notfound');
        exit;
    }

    if ($table['merged_into'] !== null) {
        // A merged-away table's capacity is managed via unmerge, not directly.
        $pdo->rollBack();
        header('Location: ' . $back . '&msg=invalid');
        exit;
    }

    $occStmt = $pdo->prepare('SELECT COALESCE(SUM(seats), 0) FROM table_assignments WHERE table_id = :id');
    $occStmt->execute(['id' => $tableId]);
    $occupied = (int) $occStmt->fetchColumn();

    if ($seats < $occupied) {
        $pdo->rollBack();
        header('Location: ' . $back . '&msg=below_occupied');
        exit;
    }

    $update = $pdo->prepare('UPDATE venue_tables SET seats = :seats WHERE id = :id');
    $update->execute(['seats' => $seats, 'id' => $tableId]);

    $pdo->commit();
    header('Location: ' . $back . '&msg=seats_updated');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[spitalbierfest] table_seats error: ' . $e->getMessage());
    header('Location: ' . $back . '&msg=invalid');
}
