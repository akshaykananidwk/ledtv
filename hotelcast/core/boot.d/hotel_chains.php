<?php
/**
 * Hotel chains (#20): permissions. No tenant tables (chain tables are scoped by chain_id, see
 * core/Chains.php). Role gates only — chain membership is always checked by Chains::current() /
 * Chains::assertHotels() on top of these.
 */
declare(strict_types=1);

// Chain list / create / assign hotels / chain admins (resellers: only their own chains and hotels).
Auth::registerPermission('chains.manage', ['platform_admin', 'reseller']);
// Chain dashboard, library, broadcast, templates (super_admin only with a chain grant: users.chain_id).
Auth::registerPermission('chain.view', ['chain_admin', 'platform_admin', 'reseller', 'super_admin']);
