-- پیش‌ساخت/ترمیم جدول اعتراض‌های قانون پیش از مهاجرت چارچوب قوانین.
-- فایل چارچوب قوانین قدیمی‌تر حفظ می‌شود؛ این مهاجرت مستقل مشکل کلید اصلی را حل می‌کند.
CREATE TABLE IF NOT EXISTS community_law_objections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    law_version_id BIGINT UNSIGNED NOT NULL,
    community_id INT NOT NULL,
    submitted_by INT NOT NULL,
    objection_type ENUM('charter_conflict','jurisdiction_conflict','rights_impact','implementation_barrier','other') NOT NULL DEFAULT 'other',
    description MEDIUMTEXT NOT NULL,
    evidence MEDIUMTEXT NULL,
    status ENUM('pending','under_review','upheld','dismissed','withdrawn') NOT NULL DEFAULT 'pending',
    reviewer_user_id INT NULL,
    decision_note MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_law_objections_version_status (law_version_id, status, created_at),
    KEY idx_law_objections_community (community_id, status),
    KEY idx_law_objections_submitter (submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @community_law_objections_has_primary_key = (
    SELECT COUNT(*) > 0
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'community_law_objections'
      AND constraint_type = 'PRIMARY KEY'
);

SET @community_law_objections_repair_sql = IF(
    @community_law_objections_has_primary_key,
    'SELECT 1',
    'ALTER TABLE community_law_objections ADD PRIMARY KEY (id)'
);

PREPARE community_law_objections_repair_stmt FROM @community_law_objections_repair_sql;
EXECUTE community_law_objections_repair_stmt;
DEALLOCATE PREPARE community_law_objections_repair_stmt;
