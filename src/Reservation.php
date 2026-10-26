<?php
declare(strict_types=1);

/**
 * Shared reservation actions: the guest's personal link (confirm / cancel,
 * see public/reservierung.php) and cancelling, which the dashboard and the
 * guest page both use.
 */

/** Stand-in token of the test mail: the page shows a demo, nothing changes. */
const SBF_TEST_TOKEN = 'TEST';

/**
 * Gives every listed reservation that has none yet a link token. Done right
 * before a mail is rendered, so preview and mail show the same link.
 *
 * @param int[] $ids
 */
function sbf_ensure_manage_tokens(PDO $pdo, array $ids): void
{
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM reservations WHERE manage_token IS NULL AND id IN ({$placeholders})");
    $stmt->execute(array_values($ids));
    $update = $pdo->prepare('UPDATE reservations SET manage_token = :t WHERE id = :id AND manage_token IS NULL');
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $update->execute(['t' => bin2hex(random_bytes(16)), 'id' => (int) $id]);
    }
}

/** 'bestaetigen' or 'stornieren' — preselects the button on the page. */
function sbf_manage_url(string $token, string $action): string
{
    return sbf_config()['base_url'] . '/reservierung.php?t=' . rawurlencode($token) . '&a=' . $action;
}

/** A token as it may come from a link: 32 hex chars (or the test token). */
function sbf_valid_token_format(string $token): bool
{
    return $token === SBF_TEST_TOKEN || preg_match('/^[0-9a-f]{32}$/', $token) === 1;
}

/**
 * Cancels a reservation, gives its seats back to the capacity counter and
 * frees its tables.
 *
 * @param string $by 'admin' or 'guest'
 * @return string 'cancelled', 'already' or 'notfound'
 */
function sbf_cancel_reservation(PDO $pdo, int $id, string $by): string
{
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
            return 'notfound';
        }
        if ($reservation['cancelled_at'] !== null) {
            $pdo->rollBack();
            return 'already';
        }

        $update = $pdo->prepare('UPDATE reservations SET cancelled_at = NOW(), cancelled_by = :by WHERE id = :id AND cancelled_at IS NULL');
        $update->execute(['id' => $id, 'by' => $by]);

        if ($update->rowCount() === 1) {
            $counter = $pdo->prepare('UPDATE capacity_counter SET seats_taken = GREATEST(0, seats_taken - :g) WHERE id = 1');
            $counter->execute(['g' => (int) $reservation['guests']]);

            // Free any table seats this reservation held — a cancelled booking
            // shouldn't keep blocking a physical seat in the seating plan.
            $freeSeats = $pdo->prepare('DELETE FROM table_assignments WHERE reservation_id = :id');
            $freeSeats->execute(['id' => $id]);
        }

        $pdo->commit();
        return 'cancelled';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
