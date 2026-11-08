-- وضعیت مستقل برای رأی‌گیری با حدنصاب ناکافی؛ امکان اشتباه گرفتن آن با رد ماهوی وجود ندارد.
ALTER TABLE community_law_amendment_cases
    MODIFY status ENUM('draft','submitted','local_voting','vote_insufficient','parent_review','approved','rejected','cancelled')
    NOT NULL DEFAULT 'draft';
