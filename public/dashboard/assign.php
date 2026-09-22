<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: seating.php');
    exit;
}

$tableId = filter_input(INPUT_POST, 'table_id', FILTER_VALIDATE_INT);
$mode = (string) ($_POST['mode'] ?? '');
$seats = filter_input(INPUT_POST, 'seats', FILTER_VALIDATE_INT);
$csrf = (string) ($_POST['csrf'] ?? '');

$back = 'seating.php' . ($tableId ? '?table=' . $tableId : '');

if (!$tableId || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    header('Location: seating.php?msg=badtoken');
    exit;
}

if (!in_array($mode, ['reservation', 'manual'], true) || !$seats || $seats < 1) {
    header('Location: ' . $back . '&msg=invalid');
    exit;
}

$pdo = sbf_pdo();

try {
    $pdo->beginTransaction();

    $tableStmt = $pdo->prepare('SELECT id, seats FROM venue_tables WHERE id = :id FOR UPDATE');
    $tableStmt->execute(['id' => $tableId]);
    $table = $tableStmt->fetch();

    if (!$table) {
        $pdo->rollBack();
        header('Location: seating.php?msg=notfound');
        exit;
    }

    $occStmt = $pdo->prepare('SELECT COALESCE(SUM(seats), 0) FROM table_assignments WHERE table_id = :id');
    $occStmt->execute(['id' => $tableId]);
    $occupied = (int) $occStmt->fetchColumn();
    $remainingTable = (int) $table['seats'] - $occupied;

    if ($seats > $remainingTable) {
        $pdo->rollBack();
        header('Location: ' . $back . '&msg=full');
        exit;
    }

    if ($mode === 'reservation') {
        $reservationId = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);
        if (!$reservationId) {
            $pdo->rollBack();
            header('Location: ' . $back . '&msg=invalid');
            exit;
        }

        $resStmt = $pdo->prepare('SELECT id, guests, cancelled_at FROM reservations WHERE id = :id FOR UPDATE');
        $resStmt->execute(['id' => $reservationId]);
        $reservation = $resStmt->fetch();

        if (!$reservation || $reservation['cancelled_at'] !== null) {
            $pdo->rollBack();
            header('Location: ' . $back . '&msg=notfound');
            exit;
        }

        $assignedStmt = $pdo->prepare('SELECT COALESCE(SUM(seats), 0) FROM table_assignments WHERE reservation_id = :id');
        $assignedStmt->execute(['id' => $reservationId]);
        $assignedSoFar = (int) $assignedStmt->fetchColumn();
        $remainingReservation = (int) $reservation['guests'] - $assignedSoFar;

        if ($seats > $remainingReservation) {
            $pdo->rollBack();
            header('Location: ' . $back . '&msg=invalid');
            exit;
        }

        $insert = $pdo->prepare(
            'INSERT INTO table_assignments (table_id, reservation_id, seats, note, created_at)
             VALUES (:table_id, :reservation_id, :seats, NULL, NOW())'
        );
        $insert->execute(['table_id' => $tableId, 'reservation_id' => $reservationId, 'seats' => $seats]);
    } else {
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '' || mb_strlen($note) > 190) {
            $pdo->rollBack();
            header('Location: ' . $back . '&msg=invalid');
            exit;
        }

        $insert = $pdo->prepare(
            'INSERT INTO table_assignments (table_id, reservation_id, seats, note, created_at)
             VALUES (:table_id, NULL, :seats, :note, NOW())'
        );
        $insert->execute(['table_id' => $tableId, 'seats' => $seats, 'note' => $note]);
    }

    $pdo->commit();
    header('Location: ' . $back . '&msg=assigned');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[spitalbierfest] assign error: ' . $e->getMessage());
    header('Location: ' . $back . '&msg=invalid');
}
