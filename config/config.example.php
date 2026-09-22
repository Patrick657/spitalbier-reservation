<?php
// Copy this file to config.local.php and fill in your real values.
// config.local.php is git-ignored and must never be committed.
//
// On Plesk you can instead set these as PHP environment variables
// (Websites & Domains > your domain > PHP Settings > additional
// configuration directives, or "Environment variables" if your Plesk
// version exposes it) using the SBF_* names below — env vars win over
// this file when both are present. Either approach works; pick whichever
// is easier to manage on your setup.

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'spitalbierfest',
        'user' => 'db_user',
        'pass' => 'db_password',
        'charset' => 'utf8mb4',
    ],
    'smtp' => [
        'host' => 'smtp.example.de',
        'port' => 587,
        // 'tls' = STARTTLS on a plain connection (typically port 587)
        // 'ssl' = implicit TLS from the start (typically port 465)
        'secure' => 'tls',
        'user' => 'stiftungsamt@straubing.de',
        'pass' => 'smtp-password',
        'from_email' => 'stiftungsamt@straubing.de',
        'from_name' => 'Bürgerspitalstiftung Straubing',
    ],
    // Internal notification for each new reservation. Leave as '' to disable.
    'admin_email' => 'stiftungsamt@straubing.de',

    'capacity' => 220,
    'max_guests_per_reservation' => 10,

    // Reservations are rejected once this moment (Europe/Berlin) has passed.
    'event_deadline' => '2026-10-30 18:30:00',
];
