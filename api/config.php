<?php
declare(strict_types=1);

/**
 * Merges config.local.php (git-ignored) with SBF_* environment variables,
 * so credentials can live either in a file or in Plesk's PHP env settings.
 * Env vars take precedence when both are set.
 */
function sbf_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $localFile = __DIR__ . '/config.local.php';
    $file = is_file($localFile) ? require $localFile : [];

    $env = static function (string $key, $default = null) {
        $value = getenv($key);
        return $value !== false && $value !== '' ? $value : $default;
    };

    $config = [
        'db' => [
            'host' => $env('SBF_DB_HOST', $file['db']['host'] ?? 'localhost'),
            'name' => $env('SBF_DB_NAME', $file['db']['name'] ?? ''),
            'user' => $env('SBF_DB_USER', $file['db']['user'] ?? ''),
            'pass' => $env('SBF_DB_PASS', $file['db']['pass'] ?? ''),
            'charset' => $file['db']['charset'] ?? 'utf8mb4',
        ],
        'smtp' => [
            'host' => $env('SBF_SMTP_HOST', $file['smtp']['host'] ?? ''),
            'port' => (int) $env('SBF_SMTP_PORT', $file['smtp']['port'] ?? 587),
            'secure' => $env('SBF_SMTP_SECURE', $file['smtp']['secure'] ?? 'tls'),
            'user' => $env('SBF_SMTP_USER', $file['smtp']['user'] ?? ''),
            'pass' => $env('SBF_SMTP_PASS', $file['smtp']['pass'] ?? ''),
            'from_email' => $env('SBF_SMTP_FROM_EMAIL', $file['smtp']['from_email'] ?? ''),
            'from_name' => $env('SBF_SMTP_FROM_NAME', $file['smtp']['from_name'] ?? 'Bürgerspitalstiftung Straubing'),
        ],
        'admin_email' => $env('SBF_ADMIN_EMAIL', $file['admin_email'] ?? ''),
        'capacity' => (int) ($file['capacity'] ?? 255),
        'max_guests_per_reservation' => (int) ($file['max_guests_per_reservation'] ?? 10),
        // Higher ceiling for reservations the admin enters manually in the
        // dashboard (large groups) — the public form still enforces the
        // lower max_guests_per_reservation above.
        'admin_max_guests_per_reservation' => (int) ($file['admin_max_guests_per_reservation'] ?? 60),
        'event_deadline' => $file['event_deadline'] ?? '2026-10-30 18:30:00',
    ];

    return $config;
}
