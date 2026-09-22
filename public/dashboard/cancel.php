<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$csrf = (string) ($_POST['csrf'] ?? '');

if (!$id || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    header('Location: index.php?msg=badtoken');
    exit;
}

$pdo = sbf_pdo();

try {
    $pdo->beginTransaction();

    // Lock capacity_counter first, always — reserve.php locks it in the same
    // order, so two concurrent requests can never deadlock against each other.
    $pdo->query('SELECT seats_taken FROM capacity_counter WHERE id = 1 FOR UPDATE');

    $stmt = $pdo->prepare('SELECT guests, cancelled_at FROM reservations WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $reservation = $stmt->fetch();

    if (!$reservation) {
        $pdo->rollBack();
        header('Location: index.php?msg=notfound');
        exit;
    }

    if ($reservation['cancelled_at'] !== null) {
        $pdo->rollBack();
        header('Location: index.php?msg=already');
        exit;
    }

    $update = $pdo->prepare('UPDATE reservations SET cancelled_at = NOW() WHERE id = :id AND cancelled_at IS NULL');
    $update->execute(['id' => $id]);

    if ($update->rowCount() === 1) {
        $counter = $pdo->prepare('UPDATE capacity_counter SET seats_taken = GREATEST(0, seats_taken - :g) WHERE id = 1');
        $counter->execute(['g' => (int) $reservation['guests']]);

        // Free any table seats this reservation held — a cancelled booking
        // shouldn't keep blocking a physical seat in the seating plan.
        $freeSeats = $pdo->prepare('DELETE FROM table_assignments WHERE reservation_id = :id');
        $freeSeats->execute(['id' => $id]);
    }

    $pdo->commit();
    header('Location: index.php?msg=cancelled');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[spitalbierfest] cancel error: ' . $e->getMessage());
    header('Location: index.php?msg=notfound');
}
