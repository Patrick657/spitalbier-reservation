<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: seating.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$tableId = filter_input(INPUT_POST, 'table_id', FILTER_VALIDATE_INT);
$csrf = (string) ($_POST['csrf'] ?? '');
$back = 'seating.php' . ($tableId ? '?table=' . $tableId : '');

if (!$id || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    header('Location: seating.php?msg=badtoken');
    exit;
}

$pdo = sbf_pdo();

try {
    $stmt = $pdo->prepare('DELETE FROM table_assignments WHERE id = :id');
    $stmt->execute(['id' => $id]);
    header('Location: ' . $back . '&msg=unassigned');
} catch (Throwable $e) {
    error_log('[spitalbierfest] unassign error: ' . $e->getMessage());
    header('Location: ' . $back . '&msg=invalid');
}
