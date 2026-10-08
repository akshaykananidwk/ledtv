-- 2.4.1 emergency alarm (docs/modules/emergency_alarm.md): the sound TVs play while an emergency is shown.
--   alarm_sound   sound reference (core/Sounds.php): "b:emergency_beep" (built-in) or "u:12" (hotel upload);
--                 NULL = no alarm (every emergency created before 2.4.1)
--   alarm_loop    1 = repeat until the emergency is stopped, 0 = play alarm_repeat times
--   alarm_repeat  number of plays when alarm_loop = 0 (1–10)
--   alarm_volume  0–100: the TV raises its volume to at least this while the alarm plays
--   alarm_muted   1 = "Silence alarm on all TVs" was pressed: the message stays, the sound stops
-- Idempotent: "duplicate column" (1060) is ignored by the Migrator.
ALTER TABLE broadcast_commands ADD COLUMN alarm_sound VARCHAR(40) NULL;
ALTER TABLE broadcast_commands ADD COLUMN alarm_loop TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE broadcast_commands ADD COLUMN alarm_repeat TINYINT UNSIGNED NOT NULL DEFAULT 3;
ALTER TABLE broadcast_commands ADD COLUMN alarm_volume TINYINT UNSIGNED NOT NULL DEFAULT 80;
ALTER TABLE broadcast_commands ADD COLUMN alarm_muted TINYINT(1) NOT NULL DEFAULT 0;
