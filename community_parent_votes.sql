-- رأی کاربران دربارهٔ جایگاه هر جامعه در ساختار دولت عشق
-- این فایل را فقط یک‌بار در پایگاه دادهٔ هاست اجرا کنید.
-- جدول جدید می‌سازد و هیچ داده یا جدولی را حذف یا بازنویسی نمی‌کند.
CREATE TABLE IF NOT EXISTS community_parent_votes (
    id INT NOT NULL AUTO_INCREMENT,
    community_id INT NOT NULL,
    user_id INT NOT NULL,
    parent_community_id INT NULL DEFAULT NULL,
    voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_community_parent_vote_user (community_id, user_id),
    KEY idx_community_parent_vote_choice (community_id, parent_community_id),
    KEY idx_community_parent_vote_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
