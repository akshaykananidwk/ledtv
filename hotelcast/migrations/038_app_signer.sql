-- 2.10.1 TV app signing key per TV (TV app 2.6.3+ sends app_signer = SHA-256 of its signing certificate). The server
-- offers an app release only when it is signed with the TV's key: an APK signed with another key cannot update an
-- installed app. TVs that never sent it (older apps) carry the original release key (AppReleases::LEGACY_SIGNER).
-- Idempotent: duplicate column errors are ignored by the Migrator.
ALTER TABLE devices ADD COLUMN app_signer CHAR(64) NULL AFTER app_version_code;
