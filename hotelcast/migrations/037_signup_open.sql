-- 2.9.6 Open registration (owner's request, 2026-11-13): online sign-up on, 7-day free trial, and the November 2026
-- registration offer — everyone who registers until 30 Nov 2026 gets every module free for 365 days. All values stay
-- editable in Super Admin → Sign-ups & trials → Settings. Runs once (migration table).
INSERT INTO system_settings (hotel_id, setting_key, setting_value) VALUES
  (0, 'signup_enabled', '1'),
  (0, 'trial_days', '7'),
  (0, 'signup_promo_until', '2026-11-30'),
  (0, 'signup_promo_days', '365'),
  (0, 'signup_promo_all_features', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
