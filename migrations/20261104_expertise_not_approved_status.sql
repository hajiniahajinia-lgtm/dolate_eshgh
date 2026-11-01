-- افزودن وضعیت مستقل برای درخواست‌هایی که در پایان مهلت به حد نصاب تصویب یا رد نمی‌رسند.
-- داده‌ها و وضعیت‌های قبلی حفظ می‌شوند.
ALTER TABLE expertise_applications
    MODIFY COLUMN status ENUM(
        'pending',
        'approved',
        'rejected',
        'not_approved',
        'needs_renewal',
        'under_review'
    ) NOT NULL DEFAULT 'pending';
