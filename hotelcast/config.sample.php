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

    // ---- HotelCast 2.0: installation type & license -------------------------------------
    // 'saas'       = platform server: hosts one or many hotels, manages plans / invoices /
    //                resellers and answers license checks. No license needed (default; also
    //                what every installation upgraded from 1.x runs as).
    // 'standalone' = self-hosted single hotel, licensed by a HotelCast platform.
    'mode' => 'saas',
    // Standalone only: key from your provider + the provider's HotelCast address. Checked once a
    // day (14 days offline grace). Without a key: demo mode, max 2 TVs.
    // 'license_key' => 'HC-XXXXX-XXXXX-XXXXX-XXXXX',
    // 'license_server' => 'https://tv.provider.com/hotelcast/',
    // Optional product name shown by the installer (white-label); the admin panel uses
    // Platform settings → Branding.
    // 'brand_name' => 'HotelCast',
];
