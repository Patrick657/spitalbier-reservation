<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/Reservation.php';

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

try {
    $result = sbf_cancel_reservation(sbf_pdo(), $id, 'admin');
    header('Location: index.php?msg=' . $result);
} catch (Throwable $e) {
    error_log('[spitalbierfest] cancel error: ' . $e->getMessage());
    header('Location: index.php?msg=notfound');
}
