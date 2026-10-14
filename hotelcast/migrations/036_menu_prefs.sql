-- 2.9 Menu settings (docs/modules/menu_settings.md): personal sidebar menu per user ("Profile → My menu").
-- users.menu_prefs: JSON {"<panel>": {"order": [nav keys], "hidden": [nav keys], "more": 0|1}} — NULL / missing
-- panel = use the business default (Settings key nav_menu) or the built-in order. Only changes the sidebar,
-- never permissions. Idempotent: duplicate column errors are ignored by the Migrator.
ALTER TABLE users ADD COLUMN menu_prefs TEXT NULL AFTER language;
