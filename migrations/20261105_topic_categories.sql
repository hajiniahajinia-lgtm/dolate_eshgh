-- دسته‌بندی پایهٔ موضوعات جامعه
-- داده‌های قبلی حفظ می‌شوند و همهٔ موضوعات موجود در دستهٔ «سایر موضوعات» قرار می‌گیرند.

CREATE TABLE IF NOT EXISTS topic_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(60) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_topic_categories_slug (slug),
    KEY idx_topic_categories_active_order (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO topic_categories (slug, name, description, sort_order, is_active) VALUES
('economy', 'اقتصاد و معیشت', 'درآمد، هزینه‌ها، بازار و رفاه اقتصادی', 10, 1),
('education', 'آموزش و پرورش', 'مدرسه، دانشگاه، مهارت‌آموزی و یادگیری', 20, 1),
('health', 'سلامت و بهداشت', 'سلامت عمومی، درمان و بهداشت محیط', 30, 1),
('environment', 'محیط زیست', 'آب، هوا، خاک، منابع طبیعی و پایداری', 40, 1),
('infrastructure', 'زیرساخت و خدمات عمومی', 'راه، حمل‌ونقل، انرژی، آب و خدمات شهری', 50, 1),
('governance', 'مدیریت و اداره جامعه', 'سازمان‌دهی، تصمیم‌گیری و کیفیت خدمات عمومی', 60, 1),
('culture', 'فرهنگ و روابط اجتماعی', 'فرهنگ، هنر، رسانه و همبستگی اجتماعی', 70, 1),
('justice', 'عدالت و حقوق', 'حقوق شهروندی، برابری و دسترسی منصفانه', 80, 1),
('science', 'علم و فناوری', 'پژوهش، نوآوری و فناوری‌های نو', 90, 1),
('employment', 'اشتغال و تولید', 'کارآفرینی، کسب‌وکار و تولید', 100, 1),
('other', 'سایر موضوعات', 'موضوعاتی که در دسته‌های دیگر جای نمی‌گیرند', 110, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    sort_order = VALUES(sort_order),
    is_active = 1;

-- اجرای امن حتی در صورت باقی‌ماندن بخشی از تغییرات ساختاری پس از خطا
SET @topic_category_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'topics'
      AND column_name = 'category_id'
);
SET @topic_category_column_sql = IF(
    @topic_category_column_exists = 0,
    'ALTER TABLE topics ADD COLUMN category_id INT UNSIGNED NULL AFTER description',
    'SELECT 1'
);
PREPARE topic_category_column_stmt FROM @topic_category_column_sql;
EXECUTE topic_category_column_stmt;
DEALLOCATE PREPARE topic_category_column_stmt;

SET @topic_category_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'topics'
      AND index_name = 'idx_topics_community_category_open'
);
SET @topic_category_index_sql = IF(
    @topic_category_index_exists = 0,
    'ALTER TABLE topics ADD KEY idx_topics_community_category_open (community_id, category_id, closed)',
    'SELECT 1'
);
PREPARE topic_category_index_stmt FROM @topic_category_index_sql;
EXECUTE topic_category_index_stmt;
DEALLOCATE PREPARE topic_category_index_stmt;

UPDATE topics
SET category_id = (SELECT id FROM topic_categories WHERE slug = 'other' LIMIT 1)
WHERE category_id IS NULL;
