-- منشور بنیادین دولت عشق: مرجع حقوقی برتر و نسخه‌بندی‌شده
-- این migration فقط ساختار و نسخه آغازین منشور را در شاخه توسعه تعریف می‌کند.
-- اجرای آن روی پایگاه داده یا هاست انجام نمی‌شود.

CREATE TABLE IF NOT EXISTS community_charter_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_number INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    status ENUM('draft','review','voting','effective','superseded','rejected') NOT NULL DEFAULT 'draft',
    change_summary MEDIUMTEXT NULL,
    proposed_by INT NULL,
    approved_at DATETIME NULL,
    effective_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_charter_version_number (version_number),
    KEY idx_charter_version_status (status, effective_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_charter_articles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    charter_version_id BIGINT UNSIGNED NOT NULL,
    article_code VARCHAR(30) NOT NULL,
    title VARCHAR(200) NOT NULL,
    article_text MEDIUMTEXT NOT NULL,
    principle_group ENUM(
        'human_dignity','equality','freedom','justice','peace',
        'living_beings','environment','shared_resources',
        'self_governance','accountability','future_generations',
        'technology'
    ) NOT NULL,
    is_core TINYINT(1) NOT NULL DEFAULT 1,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_charter_article_code (charter_version_id, article_code),
    KEY idx_charter_article_group (charter_version_id, principle_group, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_charter_references (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    charter_version_id BIGINT UNSIGNED NOT NULL,
    charter_article_id BIGINT UNSIGNED NOT NULL,
    article_code VARCHAR(30) NOT NULL,
    article_fingerprint CHAR(64) NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_charter_article (law_version_id, charter_article_id),
    KEY idx_law_charter_reference_version (law_version_id, charter_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_law_charter_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    verdict ENUM('compliant','conflict','unclear') NOT NULL,
    human_rights ENUM('compliant','conflict','unclear') NOT NULL,
    living_beings_and_environment ENUM('compliant','conflict','unclear') NOT NULL,
    equality_and_non_discrimination ENUM('compliant','conflict','unclear') NOT NULL,
    power_and_accountability ENUM('compliant','conflict','unclear') NOT NULL,
    reasoning MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_law_charter_assessor (law_version_id, reviewer_user_id),
    KEY idx_law_charter_assessment_version (law_version_id, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- نسخه آغازین منشور؛ تنها یک نسخه می‌تواند در هر لحظه مرجع لازم‌الاجرا باشد.
INSERT IGNORE INTO community_charter_versions
    (id, version_number, title, status, change_summary, approved_at, effective_at)
VALUES
    (1, 1, 'منشور بنیادین دولت عشق - نسخه آغازین', 'effective',
     'نسخه پایه منشور بر اساس اصول بنیادین مصوب در طراحی سامانه',
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

INSERT IGNORE INTO community_charter_articles
    (charter_version_id, article_code, title, article_text, principle_group, is_core, display_order)
VALUES
    (1, 'C-01', 'کرامت و حق زندگی',
     'کرامت ذاتی انسان، حق زندگی، امنیت و مصونیت از خشونت و رفتار غیرانسانی بنیاد همه تصمیم‌هاست. هیچ رأی یا قانونی مجاز به سلب خودسرانه این حقوق نیست.',
     'human_dignity', 1, 1),
    (1, 'C-02', 'برابری و منع تبعیض',
     'همه انسان‌ها از حیث حقوق بنیادین برابرند. تبعیض ناروا بر پایه ویژگی‌های فردی، خاستگاه، زبان، فرهنگ، باور یا جایگاه اجتماعی ممنوع است.',
     'equality', 1, 2),
    (1, 'C-03', 'آزادی و مشارکت آگاهانه',
     'آزادی اندیشه، بیان، وجدان، مشارکت و دسترسی به اطلاعات با رعایت حقوق دیگران و منع خشونت تضمین می‌شود. مشارکت نباید با اجبار یا فریب جایگزین شود.',
     'freedom', 1, 3),
    (1, 'C-04', 'عدالت و دادرسی منصفانه',
     'هر فرد حق برخورداری از رسیدگی بی‌طرفانه، امکان دفاع، آگاهی از دلایل تصمیم و اعتراض مؤثر را دارد. مسئولیت و محدودیت حقوق باید قانونی، ضروری و متناسب باشد.',
     'justice', 1, 4),
    (1, 'C-05', 'صلح و منع سلطه',
     'جنگ‌افروزی، تجاوز، برده‌سازی، سلطه‌جویی و استفاده از قدرت برای پایمال کردن حقوق دیگران با اصول منشور ناسازگار است. حل اختلاف باید تا حد امکان مسالمت‌آمیز باشد.',
     'peace', 1, 5),
    (1, 'C-06', 'حقوق سایر موجودات زنده',
     'موجودات زنده و زیست‌بوم‌ها ارزش و ملاحظات اخلاقی مستقل دارند. تصمیم‌ها باید از رنج غیرضروری، نابودی بی‌رویه زیستگاه‌ها و آسیب قابل پیشگیری به حیات جلوگیری کنند.',
     'living_beings', 1, 6),
    (1, 'C-07', 'پاسداری از محیط زیست',
     'حفاظت از آب، خاک، هوا، تنوع زیستی و تعادل بوم‌شناختی از الزامات تصمیم‌گیری است. سود کوتاه‌مدت مجوز تخریب جبران‌ناپذیر طبیعت نیست.',
     'environment', 1, 7),
    (1, 'C-08', 'منابع مشترک و بهره‌مندی عادلانه',
     'منابع و نعمت‌های زمین باید با عدالت، پایداری و رعایت حقوق جوامع محلی و نسل‌های آینده مدیریت شوند. انحصار و بهره‌کشی مخرب نباید دسترسی منصفانه را از دیگران سلب کند.',
     'shared_resources', 1, 8),
    (1, 'C-09', 'خودگردانی و تنوع جوامع',
     'جوامع حق خودگردانی و پاسداشت زبان، فرهنگ و شیوه زندگی مسالمت‌آمیز خود را دارند. وحدت و همکاری به معنی یکسان‌سازی یا ایجاد قدرت مرکزی سلطه‌گر نیست.',
     'self_governance', 1, 9),
    (1, 'C-10', 'شفافیت و پاسخ‌گویی',
     'هر اختیار عمومی باید محدود، شفاف، قابل نظارت و پاسخ‌گو باشد. تمرکز غیرقابل مهار قدرت، تعارض منافع و محروم کردن افراد از حق اعتراض ممنوع است.',
     'accountability', 1, 10),
    (1, 'C-11', 'حقوق نسل‌های آینده',
     'تصمیم‌ها باید پیامدهای بلندمدت خود را بر منابع، اقلیم، سلامت زیست‌بوم و امکان زندگی شایسته نسل‌های آینده ارزیابی کنند.',
     'future_generations', 1, 11),
    (1, 'C-12', 'فناوری در خدمت انسان و زندگی',
     'هوش مصنوعی و دیگر فناوری‌ها ابزار پشتیبان‌اند و جایگزین اراده، مسئولیت و نظارت انسانی و جمعی نمی‌شوند. تصمیم نهایی و پاسخ‌گویی باید قابل ردیابی باشد.',
     'technology', 1, 12);
