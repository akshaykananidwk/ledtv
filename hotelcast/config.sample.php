<?php
/**
 * HotelCast configuration (sample). The installer generates config.php automatically —
 * you never need to edit it by hand. Secrets (DB password, APP_KEY) live in .env.
 * Both files are NEVER overwritten by the auto-updater.
 */
return [
    // Public URL of the installation, with trailing slash. Leave '' to auto-detect.
    'base_url' => 'https://hotel.example.com/hotelcast/',
    'timezone' => 'Asia/Kolkata',
    // Show detailed errors (never enable in production).
    'debug' => false,
    // Set true when behind a reverse proxy / CDN that sends X-Forwarded-For.
    'trust_proxy' => false,
    // Use persistent MySQL connections (helps with 100+ polling TVs on some hosts).
    'db_persistent' => false,
    // Extra paths the updater must never overwrite (relative to this folder).
    'update_protected' => [],
];
