-- زیرساخت اولیهٔ بانک مالی و اموال جامعه
-- این فایل فقط ساختار می‌سازد؛ هیچ دادهٔ موجودی را حذف یا بازنویسی نمی‌کند.
-- اجرای آن در پایگاه دادهٔ هاست باید با تأیید کاربر انجام شود.

CREATE TABLE IF NOT EXISTS community_finance_settings (
  community_id INT NOT NULL PRIMARY KEY,
  currency_code VARCHAR(10) NOT NULL DEFAULT 'IRR',
  created_by INT NULL,
  updated_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_finance_transactions (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  community_id INT NOT NULL,
  transaction_type ENUM('income','expense','adjustment') NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  transaction_date DATE NOT NULL,
  budget_request_id BIGINT NULL,
  receipt_reference VARCHAR(500) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cft_community_date (community_id, transaction_date, id),
  KEY idx_cft_budget_request (budget_request_id),
  KEY idx_cft_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_assets (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  community_id INT NOT NULL,
  asset_name VARCHAR(200) NOT NULL,
  category VARCHAR(100) NULL,
  description TEXT NULL,
  quantity DECIMAL(14,3) NOT NULL DEFAULT 1.000,
  unit VARCHAR(50) NOT NULL DEFAULT 'عدد',
  estimated_value DECIMAL(18,2) NULL,
  location VARCHAR(255) NULL,
  asset_condition ENUM('good','needs_repair','unusable','unknown') NOT NULL DEFAULT 'unknown',
  asset_status ENUM('active','maintenance','transferred','disposed') NOT NULL DEFAULT 'active',
  acquired_on DATE NULL,
  created_by INT NOT NULL,
  updated_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ca_community_status (community_id, asset_status),
  KEY idx_ca_category (community_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_asset_movements (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id BIGINT NOT NULL,
  community_id INT NOT NULL,
  movement_type ENUM('registered','quantity_change','transfer','maintenance','condition_change','disposed') NOT NULL,
  quantity_change DECIMAL(14,3) NULL,
  from_location VARCHAR(255) NULL,
  to_location VARCHAR(255) NULL,
  notes TEXT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cam_asset_date (asset_id, created_at),
  KEY idx_cam_community_date (community_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_budget_requests (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  community_id INT NOT NULL,
  requested_by INT NOT NULL,
  request_type ENUM('budget','expense') NOT NULL DEFAULT 'budget',
  topic_id INT NULL,
  idea_id INT NULL,
  execution_plan_id INT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  requested_amount DECIMAL(18,2) NOT NULL,
  approved_amount DECIMAL(18,2) NULL,
  actual_spent DECIMAL(18,2) NULL,
  status ENUM('draft','voting','approved','rejected','allocated','completed','cancelled') NOT NULL DEFAULT 'draft',
  vote_started_at DATETIME NULL,
  vote_deadline DATETIME NULL,
  decision_at DATETIME NULL,
  decision_note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cbr_community_status (community_id, status, created_at),
  KEY idx_cbr_requester (requested_by),
  KEY idx_cbr_topic_idea (topic_id, idea_id),
  KEY idx_cbr_execution_plan (execution_plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_budget_request_votes (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT NOT NULL,
  community_id INT NOT NULL,
  user_id INT NOT NULL,
  vote_choice ENUM('approve','reject','abstain') NOT NULL,
  comment TEXT NULL,
  voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cbrv_request_user (request_id, user_id),
  KEY idx_cbrv_community_request (community_id, request_id),
  KEY idx_cbrv_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
