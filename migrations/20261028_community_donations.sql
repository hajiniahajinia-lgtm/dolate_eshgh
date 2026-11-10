-- دفتر کمک‌های نقدی و غیرمنقول جامعه
-- ساختار افزوده می‌شود؛ هیچ دادهٔ موجودی حذف یا بازنویسی نمی‌شود.
CREATE TABLE IF NOT EXISTS community_donations (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  community_id INT NOT NULL,
  donor_name VARCHAR(200) NOT NULL,
  donation_type ENUM('cash','immovable') NOT NULL,
  amount DECIMAL(18,2) NULL,
  currency_code VARCHAR(10) NOT NULL DEFAULT 'IRR',
  asset_description TEXT NULL,
  estimated_value DECIMAL(18,2) NULL,
  location VARCHAR(255) NULL,
  donation_date DATE NOT NULL,
  status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  created_by INT NOT NULL,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cd_community_date (community_id, donation_date, id),
  KEY idx_cd_community_status (community_id, status),
  KEY idx_cd_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
