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

    $childStmt = $pdo->prepare('SELECT id, base_seats FROM venue_tables WHERE merged_into = :id ORDER BY position FOR UPDATE');
    $childStmt->execute(['id' => $primaryId]);
    $children = $childStmt->fetchAll();

    if (!$children) {
        $pdo->rollBack();
        header('Location: ' . $back . '&msg=invalid');
        exit;
    }

    // Seated guests are spread back over the separate tables: each
    // assignment goes whole to the first table with enough room, and is only
    // split when it fits nowhere. Whatever is left over lands on the last
    // table, which then keeps the extra chairs it needs.
    $targets = [];
    foreach (array_merge([$primary], $children) as $t) {
        $targets[] = ['id' => (int) $t['id'], 'base' => (int) $t['base_seats'], 'used' => 0];
    }
    $last = count($targets) - 1;

    $assignStmt = $pdo->prepare(
        'SELECT id, reservation_id, seats, note, created_at FROM table_assignments
         WHERE table_id = :id ORDER BY created_at ASC, id ASC FOR UPDATE'
    );
    $assignStmt->execute(['id' => $primaryId]);

    $moveStmt = $pdo->prepare('UPDATE table_assignments SET table_id = :table, seats = :seats WHERE id = :id');
    $splitStmt = $pdo->prepare(
        'INSERT INTO table_assignments (table_id, reservation_id, seats, note, created_at)
         VALUES (:table, :res, :seats, :note, :created)'
    );

    foreach ($assignStmt->fetchAll() as $a) {
        $need = (int) $a['seats'];
        $parts = [];
        foreach ($targets as $i => $t) {
            if ($t['base'] - $t['used'] >= $need) {
                $parts[$i] = $need;
                $need = 0;
                break;
            }
        }
        foreach ($targets as $i => $t) {
            if ($need === 0) {
                break;
            }
            $take = $i === $last ? $need : min($need, $t['base'] - $t['used']);
            if ($take > 0) {
                $parts[$i] = $take;
                $need -= $take;
            }
        }

        $first = true;
        foreach ($parts as $i => $seats) {
            $targets[$i]['used'] += $seats;
            if ($first) {
                $moveStmt->execute(['table' => $targets[$i]['id'], 'seats' => $seats, 'id' => $a['id']]);
                $first = false;
            } else {
                $splitStmt->execute([
                    'table' => $targets[$i]['id'],
                    'res' => $a['reservation_id'],
                    'seats' => $seats,
                    'note' => $a['note'],
                    'created' => $a['created_at'],
                ]);
            }
        }
    }

    $updateTable = $pdo->prepare('UPDATE venue_tables SET seats = :seats, merged_into = NULL WHERE id = :id');
    foreach ($targets as $t) {
        $updateTable->execute(['seats' => max($t['base'], $t['used']), 'id' => $t['id']]);
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
