<?php
/**
 * Online sign-up / free trial (#17) and demo mode (#21): permissions and the demo write guard.
 * signups is a platform table (not tenant-scoped); demo hotels are ordinary hotels with
 * hotels.demo_kind set.
 */
declare(strict_types=1);

// Platform → Sign-ups (list, approve, settings) and the public demo settings.
Auth::registerPermission('signup.manage', ['platform_admin']);
// "Demo for client": private demo copies for prospects (platform admins and resellers).
Auth::registerPermission('demo.client', ['platform_admin', 'reseller']);

// Demo guard: users of a read-only demo hotel cannot change anything (every admin POST except
// login / logout / language is refused). Runs before any admin page code.
if (PHP_SAPI !== 'cli' && hc_installed()) {
    try {
        Demo::guard();
    } catch (PDOException $e) {
        // Schema not migrated yet (updater run in progress): no demo hotels exist.
        Logger::error('Demo guard skipped: ' . $e->getMessage());
    }
}
