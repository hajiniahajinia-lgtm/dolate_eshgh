-- ساختار سلسله‌مراتبی جوامع در «دولت عشق»
-- این فایل را فقط یک‌بار و به‌صورت دستی در پایگاه دادهٔ درست اجرا کنید.
-- این تغییر داده‌های موجود را حذف یا بازنویسی نمی‌کند.
ALTER TABLE communities
    ADD COLUMN parent_community_id INT NULL DEFAULT NULL,
    ADD INDEX idx_communities_parent_community_id (parent_community_id);
