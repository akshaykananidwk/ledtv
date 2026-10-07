<?php
/**
 * 2.3: content display apps (docs/modules/content_apps.md) — photo album tables and permission.
 * Showcase, event welcome, sheet table and social wall need no table of their own.
 */
declare(strict_types=1);

Tenant::registerTable('albums');
Tenant::registerTable('album_photos');

// Photo album (#19): staff may create albums and upload / approve photos (admin/album_upload.php).
Auth::registerPermission('albums.manage', 'staff');
