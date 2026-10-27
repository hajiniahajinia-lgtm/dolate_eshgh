-- دولت عشق: مراحل مستقل برنامه تفصیلی و زمان‌بندی اجرا
-- فقط جدول جدید می‌سازد و به داده‌های موجود دست نمی‌زند.
CREATE TABLE IF NOT EXISTS topic_execution_stages (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  execution_plan_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  expected_result TEXT NULL,
  start_date DATE NULL,
  due_date DATE NULL,
  status ENUM('planned','in_progress','completed','delayed') NOT NULL DEFAULT 'planned',
  progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  completion_note TEXT NULL,
  created_by INT NOT NULL,
  updated_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_execution_stages_plan (execution_plan_id, id),
  KEY idx_execution_stages_due (due_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
