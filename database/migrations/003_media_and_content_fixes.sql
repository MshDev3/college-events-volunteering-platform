-- =====================================================================
-- 003 — Card media and contact-detail fixes.
--   * Volunteer opportunities get an optional cover image (like events).
--   * Events whose cover was a venue photo fall back to their type's default image.
--   * The seeded placeholder address is cleared so the address line is hidden until
--     an admin sets a real one.
--   * `about_image` holds the About-page photo (empty = bundled default).
-- Every UPDATE matches the exact seeded value, so edits made in the admin are never touched.
-- =====================================================================

ALTER TABLE volunteer_opportunities ADD COLUMN image_path VARCHAR(255) NULL AFTER description_en;

UPDATE events SET image_path = NULL WHERE image_path LIKE 'assets/img/facilities/%';

UPDATE settings SET value = '' WHERE `key` = 'address_ar' AND value = 'الكلية التقنية — يُحدَّث العنوان من لوحة الإعدادات';
UPDATE settings SET value = '' WHERE `key` = 'address_en' AND value = 'Technical College — update the address in Admin › Settings';

INSERT IGNORE INTO settings (`key`, value) VALUES ('about_image', '');
