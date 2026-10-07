-- 2.3: two new content types.
--   app    = a display app (menu board, token display, notice board, offers, widgets …) rendered by the
--            server at /display/ and shown by the TV as a web page (settings JSON: {"app": "<key>", …}).
--   layout = split screen: zones, each with its own items (settings JSON, see docs/modules/layouts.md).
-- Idempotent: MODIFY to the same definition is a no-op on re-run.
ALTER TABLE content_items MODIFY type ENUM('image','video','stream','timetable','announcement','html','url','youtube','clock','app','layout') NOT NULL;
ALTER TABLE chain_content_items MODIFY type ENUM('image','video','stream','timetable','announcement','html','url','youtube','clock','app','layout') NOT NULL;
