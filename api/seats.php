<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$cfg = sbf_config();
$cap = $cfg['capacity'];

try {
    $pdo = sbf_pdo();
    $stmt = $pdo->query('SELECT seats_taken FROM capacity_counter WHERE id = 1');
    $seatsTaken = (int) ($stmt !== false ? $stmt->fetchColumn() : 0);
} catch (Throwable $e) {
    error_log('[spitalbierfest] seats.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Konnte Belegung nicht laden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$seatsLeft = max(0, $cap - $seatsTaken);
$seatPct = $cap > 0 ? (int) round(($seatsTaken / $cap) * 100) : 0;

echo json_encode([
    'ok' => true,
    'seatsLeft' => $seatsLeft,
    'seatPct' => $seatPct,
    'cap' => $cap,
], JSON_UNESCAPED_UNICODE);
