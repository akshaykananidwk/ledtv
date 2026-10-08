-- 2.4 web player (#45): which kind of player a TV is, and the browser's user agent.
--   platform   'android' = the Android TV app (default, every TV registered before 2.4),
--              'web'     = the browser web player (hotelcast/player/, docs/modules/web_player.md)
--   user_agent the browser's user agent (web players only; shown on the TV details page)
-- Idempotent: "duplicate column" (1060) is ignored by the Migrator.
ALTER TABLE devices ADD COLUMN platform ENUM('android','web') NOT NULL DEFAULT 'android' AFTER device_uid;
ALTER TABLE devices ADD COLUMN user_agent VARCHAR(255) NULL AFTER model;
