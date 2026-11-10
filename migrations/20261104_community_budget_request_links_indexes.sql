-- افزودن ایندکس‌های ارتباط درخواست مالی با موضوع، ایده و طرح اجرایی
-- ستون‌ها از قبل در community_budget_requests وجود دارند.
-- ایندکس‌ها در بررسی هاست موجود نبودند؛ این مهاجرت فقط آن‌ها را اضافه می‌کند.

ALTER TABLE community_budget_requests
  ADD KEY idx_cbr_topic_idea (topic_id, idea_id),
  ADD KEY idx_cbr_execution_plan (execution_plan_id);
