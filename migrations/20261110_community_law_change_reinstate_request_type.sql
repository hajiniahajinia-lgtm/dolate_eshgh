-- همسان‌سازی نوع درخواست بازگردانی قانون با گردش‌کار اجرایی موجود
-- این تغییر فقط یک مقدار مجاز به ENUM اضافه می‌کند و داده‌ای را حذف یا بازنویسی نمی‌کند.
ALTER TABLE community_law_change_cases
    MODIFY request_type ENUM('suspend','repeal','review_expiry','reinstate') NOT NULL;
