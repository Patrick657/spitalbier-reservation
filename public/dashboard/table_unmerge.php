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
$csrf = (string) ($_POST['csrf'] ?? '');
$back = 'seating.php' . ($primaryId ? '?table=' . $primaryId : '');

if (!$primaryId || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    header('Location: seating.php?msg=badtoken');
    exit;
}

$pdo = sbf_pdo();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, base_seats FROM venue_tables WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $primaryId]);
    $primary = $stmt->fetch();

    if (!$primary) {
        $pdo->rollBack();
        header('Location: seating.php?msg=notfound');
        exit;
    }

    $childStmt = $pdo->prepare('SELECT id, base_seats FROM venue_tables WHERE merged_into = :id FOR UPDATE');
    $childStmt->execute(['id' => $primaryId]);
    $children = $childStmt->fetchAll();

    if (!$children) {
        $pdo->rollBack();
        header('Location: ' . $back . '&msg=invalid');
        exit;
    }

    $occStmt = $pdo->prepare('SELECT COALESCE(SUM(seats), 0) FROM table_assignments WHERE table_id = :id');
    $occStmt->execute(['id' => $primaryId]);
    if ((int) $occStmt->fetchColumn() > 0) {
        $pdo->rollBack();
        header('Location: ' . $back . '&msg=not_empty');
        exit;
    }

    $updatePrimary = $pdo->prepare('UPDATE venue_tables SET seats = :seats WHERE id = :id');
    $updatePrimary->execute(['seats' => (int) $primary['base_seats'], 'id' => $primaryId]);

    $updateChild = $pdo->prepare('UPDATE venue_tables SET seats = :seats, merged_into = NULL WHERE id = :id');
    foreach ($children as $c) {
        $updateChild->execute(['seats' => (int) $c['base_seats'], 'id' => $c['id']]);
    }

    $pdo->commit();
    header('Location: ' . $back . '&msg=unmerged');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[spitalbierfest] table_unmerge error: ' . $e->getMessage());
    header('Location: ' . $back . '&msg=invalid');
}
