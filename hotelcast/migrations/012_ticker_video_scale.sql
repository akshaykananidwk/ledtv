-- 2.2.2: how video / images fill the smaller area next to a ticker that reserves space.
-- fill (default) = whole width and height, no black side bars; fit = aspect kept with bars; zoom = cropped.
-- Idempotent: "duplicate column" (1060) is ignored by the Migrator.
ALTER TABLE tickers ADD COLUMN video_scale ENUM('fill','fit','zoom') NOT NULL DEFAULT 'fill' AFTER reserve_space;
