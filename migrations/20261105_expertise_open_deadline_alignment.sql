-- همگام‌سازی درخواست‌های باز با آغاز مهلت رأی‌گیری پس از سه ارزیابی مستقل
-- فقط درخواست‌های باز و نهایی‌نشده تغییر می‌کنند؛ سوابق بسته‌شده دست‌نخورده می‌مانند.

-- درخواست‌هایی که هنوز سه ارزیابی مستقل معتبر ندارند، نباید مهلت رأی‌گیری داشته باشند.
UPDATE expertise_applications a
SET a.expires_at = NULL
WHERE a.status = 'pending'
  AND a.decision_at IS NULL
  AND (
      SELECT COUNT(*)
      FROM expertise_application_reviews r
      WHERE r.application_id = a.id
        AND r.status = 'submitted'
        AND r.conflict_of_interest = 'none'
  ) < 3;

-- درخواست‌هایی که سه ارزیابی مستقل را قبلاً تکمیل کرده‌اند،
-- از زمان اجرای این Migration هفت روز کامل برای رأی‌گیری دریافت می‌کنند.
UPDATE expertise_applications a
SET a.expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
WHERE a.status = 'pending'
  AND a.decision_at IS NULL
  AND (
      SELECT COUNT(*)
      FROM expertise_application_reviews r
      WHERE r.application_id = a.id
        AND r.status = 'submitted'
        AND r.conflict_of_interest = 'none'
  ) >= 3;
