-- 2.6.1: activity_logs.actor_platform = 1 when the action was done by a Super Admin / reseller
-- (platform user), e.g. while working inside a customer's workspace. Customers never see those rows in
-- their own logs; the Super Admin console's Audit logs shows everything (docs/modules/panels.md).
-- Idempotent: duplicate column / key errors are ignored by the Migrator.
ALTER TABLE activity_logs ADD COLUMN actor_platform TINYINT(1) NOT NULL DEFAULT 0 AFTER username;
ALTER TABLE activity_logs ADD KEY idx_al_hotel_actor (hotel_id, actor_platform, id);
UPDATE activity_logs a JOIN users u ON u.id = a.user_id SET a.actor_platform = 1 WHERE u.role IN ('platform_admin', 'reseller') AND a.actor_platform = 0;
-- Existing rows keep their hotel_id (data preserved); actor_platform alone hides them from the customer.
