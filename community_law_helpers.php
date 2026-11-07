<?php
/**
 * توابع پایهٔ نظام قوانین جامعه.
 * این فایل فقط منطق خواندن ساختار و ثبت تاریخچه را فراهم می‌کند؛
 * تصویب، رأی‌گیری و اجرای قانون در مراحل بعدی به آن متصل می‌شوند.
 */

if (!function_exists('community_law_tables_ready')) {
    function community_law_tables_ready(mysqli $conn): bool {
        $required = [
            'community_laws',
            'community_law_versions',
            'community_law_scopes',
            'community_law_electorate',
            'community_law_votes',
            'community_law_positions',
            'community_law_objections',
            'community_law_implementations',
            'community_law_history',
            'community_law_voting_rules',
            'community_law_voting_rule_proposals',
            'community_law_voting_rule_electorate',
            'community_law_voting_rule_votes',
            'community_law_direct_electorate',
            'community_law_direct_votes',
            'community_law_direct_results',
            'community_law_position_discussions',
            'community_law_final_results',
            'community_law_review_cases',
            'community_law_review_electorate',
            'community_law_review_assessments',
            'community_law_authority_cases',
            'community_law_authority_references',
            'community_law_authority_assessments',
            'community_law_precedent_assessments',
            'community_law_final_review_cases',
            'community_law_final_review_assessments',
            'community_law_final_review_electorate',
            'community_law_final_review_references',
            'community_law_appeals',
            'community_law_appeal_electorate',
            'community_law_appeal_assessments'
        ];

        $placeholders = implode(',', array_fill(0, count($required), '?'));
        $sql = "SELECT COUNT(*) AS total
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;

        $types = str_repeat('s', count($required));
        $params = array_merge([$types], $required);
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['total'] ?? 0) === count($required);
    }
}

if (!function_exists('community_law_load_parent_map')) {
    function community_law_load_parent_map(mysqli $conn): ?array {
        $result = $conn->query("SELECT id, parent_community_id FROM communities");
        if (!$result) return null;

        $map = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int)$row['id'];
            $map[$id] = $row['parent_community_id'] === null
                ? 0
                : (int)$row['parent_community_id'];
        }
        $result->free();

        return $map;
    }
}

if (!function_exists('community_law_is_descendant_or_same')) {
    function community_law_is_descendant_or_same(array $parent_map, int $community_id, int $ancestor_id): bool {
        if ($community_id <= 0 || $ancestor_id <= 0 || !array_key_exists($community_id, $parent_map)) {
            return false;
        }

        $cursor = $community_id;
        $visited = [];

        while ($cursor > 0) {
            if ($cursor === $ancestor_id) return true;
            if (isset($visited[$cursor])) return false;
            $visited[$cursor] = true;

            $cursor = $parent_map[$cursor] ?? 0;
            if ($cursor > 0 && !array_key_exists($cursor, $parent_map)) {
                return false;
            }
        }

        return false;
    }
}

if (!function_exists('community_law_parent_map_is_valid')) {
    function community_law_parent_map_is_valid(array $parent_map): bool {
        foreach ($parent_map as $community_id => $parent_id) {
            $community_id = (int)$community_id;
            $parent_id = (int)$parent_id;
            if ($community_id <= 0 || $parent_id < 0) return false;
            if ($parent_id > 0 && !array_key_exists($parent_id, $parent_map)) return false;

            $cursor = $community_id;
            $visited = [];
            while ($cursor > 0) {
                if (isset($visited[$cursor]) || !array_key_exists($cursor, $parent_map)) {
                    return false;
                }
                $visited[$cursor] = true;
                $cursor = (int)$parent_map[$cursor];
            }
        }
        return true;
    }
}

if (!function_exists('community_law_scope_matches')) {
    function community_law_scope_matches(mysqli $conn, int $law_version_id, int $target_community_id): bool {
        // تمام مسیرهای تشخیص دامنه باید از اعتبارسنجی fail-closed مشترک استفاده کنند.
        return community_law_scope_match_checked($conn, $law_version_id, $target_community_id) === true;
    }
}

if (!function_exists('community_law_scope_match_checked')) {
    function community_law_scope_match_checked(
        mysqli $conn,
        int $law_version_id,
        int $target_community_id
    ): ?bool {
        if ($law_version_id <= 0 || $target_community_id <= 0) return null;

        $stmt = $conn->prepare(
            "SELECT scope_community_id, include_descendants
             FROM community_law_scopes WHERE law_version_id=?"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $law_version_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $scopes = [];
        while ($row = $result->fetch_assoc()) {
            $scopes[] = [
                'community_id' => (int)$row['scope_community_id'],
                'include_descendants' => (int)$row['include_descendants']
            ];
        }
        $stmt->close();
        if (!$scopes) return null;

        $parent_map = community_law_load_parent_map($conn);
        if ($parent_map === null || !community_law_parent_map_is_valid($parent_map)) return null;
        foreach ($scopes as $scope) {
            if (
                $scope['community_id'] <= 0 ||
                !in_array((int)$scope['include_descendants'], [0, 1], true) ||
                !array_key_exists($scope['community_id'], $parent_map)
            ) return null;
        }

        foreach ($scopes as $scope) {
            if ($target_community_id === $scope['community_id']) return true;
            if (
                $scope['include_descendants'] &&
                community_law_is_descendant_or_same(
                    $parent_map, $target_community_id, $scope['community_id']
                )
            ) return true;
        }
        return false;
    }
}

if (!function_exists('community_law_scope_community_ids')) {
    function community_law_scope_community_ids(mysqli $conn, int $law_version_id): ?array {
        if ($law_version_id <= 0) return null;

        $stmt = $conn->prepare(
            "SELECT scope_community_id, include_descendants
             FROM community_law_scopes WHERE law_version_id=?"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $law_version_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        $scopes = [];
        while ($row = $result->fetch_assoc()) {
            $scopes[] = [
                'id' => (int)$row['scope_community_id'],
                'descendants' => (int)$row['include_descendants']
            ];
        }
        $stmt->close();
        if (!$scopes) return null;

        $parent_map = community_law_load_parent_map($conn);
        if ($parent_map === null || !community_law_parent_map_is_valid($parent_map)) return null;
        foreach ($scopes as $scope) {
            if (
                $scope['id'] <= 0 ||
                !in_array((int)$scope['descendants'], [0, 1], true) ||
                !array_key_exists($scope['id'], $parent_map)
            ) return null;
        }

        $included = [];
        foreach ($parent_map as $candidate_id => $_parent_id) {
            foreach ($scopes as $scope) {
                if (
                    $candidate_id === $scope['id'] ||
                    ($scope['descendants'] && community_law_is_descendant_or_same(
                        $parent_map, (int)$candidate_id, $scope['id']
                    ))
                ) {
                    $included[(int)$candidate_id] = true;
                    break;
                }
            }
        }

        $ids = array_keys($included);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

if (!function_exists('community_law_scope_ids_for_parent_map')) {
    function community_law_scope_ids_for_parent_map(
        mysqli $conn,
        int $law_version_id,
        array $parent_map
    ): ?array {
        if ($law_version_id <= 0 || !community_law_parent_map_is_valid($parent_map)) return null;

        $stmt = $conn->prepare(
            "SELECT scope_community_id, include_descendants
             FROM community_law_scopes WHERE law_version_id=?"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $law_version_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $scopes = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $scopes[] = [
                'id' => (int)$row['scope_community_id'],
                'descendants' => (int)$row['include_descendants']
            ];
        }
        $stmt->close();
        if (!$scopes) return null;
        foreach ($scopes as $scope) {
            if (
                $scope['id'] <= 0 ||
                !in_array((int)$scope['descendants'], [0, 1], true) ||
                !array_key_exists($scope['id'], $parent_map)
            ) return null;
        }

        $included = [];
        foreach ($parent_map as $candidate_id => $_parent_id) {
            foreach ($scopes as $scope) {
                if (
                    (int)$candidate_id === $scope['id'] ||
                    ($scope['descendants'] && community_law_is_descendant_or_same(
                        $parent_map, (int)$candidate_id, $scope['id']
                    ))
                ) {
                    $included[(int)$candidate_id] = true;
                    break;
                }
            }
        }
        $ids = array_keys($included);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}

if (!function_exists('community_law_hierarchy_path')) {
    function community_law_hierarchy_path(array $parent_map, int $community_id): ?array {
        if ($community_id <= 0 || !array_key_exists($community_id, $parent_map)) return null;
        $path = [];
        $cursor = $community_id;
        while ($cursor > 0) {
            if (in_array($cursor, $path, true) || !array_key_exists($cursor, $parent_map)) return null;
            $path[] = $cursor;
            $cursor = (int)$parent_map[$cursor];
        }
        return $path;
    }
}

if (!function_exists('community_law_hierarchy_change_has_pending_review_dependency')) {
    /**
     * اگر تغییر والد مسیر حقوقی یکی از جوامع مشمول یک قانونِ در حال بررسی را عوض کند،
     * snapshot قوانین مرجع آن بررسی منقضی می‌شود؛ در این حالت تغییر سلسله‌مراتب متوقف می‌شود.
     * true = وابستگی باز وجود دارد، false = مانعی نیست، null = خطای ساختار/داده.
     */
    function community_law_hierarchy_change_has_pending_review_dependency(
        mysqli $conn,
        array $old_parent_map,
        array $new_parent_map
    ): ?bool {
        if (
            !community_law_parent_map_is_valid($old_parent_map) ||
            !community_law_parent_map_is_valid($new_parent_map)
        ) return null;

        $pending = $conn->query(
            "SELECT l.current_version_id AS version_id
             FROM community_laws l
             JOIN community_law_versions v ON v.id=l.current_version_id
             WHERE l.status IN (
                    'proposed','voting','pending_final_review',
                    'appeal_period','enactment_pending'
                   )
               AND v.status IN (
                    'proposed','voting','pending_final_review',
                    'appeal_period','enactment_pending'
                   )
             ORDER BY l.id"
        );
        if (!$pending) return null;

        while ($law = $pending->fetch_assoc()) {
            $version_id = (int)$law['version_id'];
            $old_ids = community_law_scope_ids_for_parent_map($conn, $version_id, $old_parent_map);
            $new_ids = community_law_scope_ids_for_parent_map($conn, $version_id, $new_parent_map);
            if ($old_ids === null || $new_ids === null) {
                $pending->free();
                return null;
            }

            $affected_ids = array_unique(array_merge($old_ids, $new_ids));
            foreach ($affected_ids as $affected_id) {
                $old_path = community_law_hierarchy_path($old_parent_map, (int)$affected_id);
                $new_path = community_law_hierarchy_path($new_parent_map, (int)$affected_id);
                if ($old_path === null || $new_path === null) {
                    $pending->free();
                    return null;
                }
                if ($old_path !== $new_path) {
                    $pending->free();
                    return true;
                }
            }
        }

        $pending->free();
        return false;
    }
}

if (!function_exists('community_law_record_hierarchy_scope_changes')) {
    function community_law_record_hierarchy_scope_changes(
        mysqli $conn,
        array $old_parent_map,
        array $new_parent_map,
        int $changed_community_id,
        int $actor_user_id
    ): bool {
        if (
            !community_law_parent_map_is_valid($old_parent_map) ||
            !community_law_parent_map_is_valid($new_parent_map)
        ) return false;

        // اگر چارچوب قوانین هنوز نصب نشده، رویداد شمولی برای ثبت وجود ندارد.
        $law_table = $conn->query(
            "SELECT COUNT(*) AS total FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name='community_laws'"
        );
        if (!$law_table) return false;
        $has_law_table = (int)($law_table->fetch_assoc()['total'] ?? 0) > 0;
        $law_table->close();
        if (!$has_law_table) return true;

        // وجود ناقص جداول قوانین نباید باعث تغییر سلسله‌مراتب بدون ثبت سابقه شود.
        if (!community_law_tables_ready($conn)) return false;

        // snapshot صلاحیت قوانین در حال بررسی نباید با تغییر سلسله‌مراتب کهنه شود.
        $pending_review_dependency = community_law_hierarchy_change_has_pending_review_dependency(
            $conn, $old_parent_map, $new_parent_map
        );
        if ($pending_review_dependency === null) return false;
        if ($pending_review_dependency) {
            throw new RuntimeException('law_review_dependency_pending');
        }

        $active = $conn->query(
            "SELECT l.id AS law_id, l.current_version_id AS version_id
             FROM community_laws l
             JOIN community_law_versions v ON v.id=l.current_version_id
             WHERE l.status='approved' AND v.status='approved'
               AND (l.effective_at IS NULL OR l.effective_at<=NOW())
               AND (l.expires_at IS NULL OR l.expires_at>NOW())
               AND l.repealed_at IS NULL
             ORDER BY l.id"
        );
        if (!$active) return false;

        $ok = true;
        while ($law = $active->fetch_assoc()) {
            $law_id = (int)$law['law_id'];
            $version_id = (int)$law['version_id'];
            $old_ids = community_law_scope_ids_for_parent_map($conn, $version_id, $old_parent_map);
            $new_ids = community_law_scope_ids_for_parent_map($conn, $version_id, $new_parent_map);
            if ($old_ids === null || $new_ids === null) {
                $ok = false;
                break;
            }

            $old_set = array_fill_keys($old_ids, true);
            $new_set = array_fill_keys($new_ids, true);
            $changed_ids = array_unique(array_merge(
                array_diff($new_ids, $old_ids),
                array_diff($old_ids, $new_ids)
            ));
            sort($changed_ids, SORT_NUMERIC);

            foreach ($changed_ids as $affected_id) {
                $entered = isset($new_set[$affected_id]);
                $old_path = community_law_hierarchy_path($old_parent_map, (int)$affected_id);
                $new_path = community_law_hierarchy_path($new_parent_map, (int)$affected_id);
                if ($old_path === null || $new_path === null) {
                    $ok = false;
                    break 2;
                }

                $scope_stmt = $conn->prepare(
                    "SELECT scope_community_id, include_descendants
                     FROM community_law_scopes WHERE law_version_id=?
                     ORDER BY scope_community_id"
                );
                if (!$scope_stmt) {
                    $ok = false;
                    break 2;
                }
                $scope_stmt->bind_param('i', $version_id);
                if (!$scope_stmt->execute()) {
                    $scope_stmt->close();
                    $ok = false;
                    break 2;
                }
                $scope_roots = [];
                $scope_result = $scope_stmt->get_result();
                while ($scope_row = $scope_result->fetch_assoc()) {
                    $scope_roots[] = [
                        'community_id' => (int)$scope_row['scope_community_id'],
                        'include_descendants' => (int)$scope_row['include_descendants']
                    ];
                }
                $scope_stmt->close();

                // وضعیت و گزارش اجرای قبلی را پیش از تغییر دامنه ثبت می‌کنیم؛ هیچ گزارش اجرایی حذف نمی‌شود.
                $implementation_state = null;
                $state_stmt = $conn->prepare(
                    "SELECT responsible_user_id, local_plan, status, started_at,
                            target_date, completed_at, progress_report
                     FROM community_law_implementations
                     WHERE law_version_id=? AND community_id=? LIMIT 1"
                );
                if (!$state_stmt) {
                    $ok = false;
                    break 2;
                }
                $state_stmt->bind_param('ii', $version_id, $affected_id);
                if (!$state_stmt->execute()) {
                    $state_stmt->close();
                    $ok = false;
                    break 2;
                }
                $implementation_state = $state_stmt->get_result()->fetch_assoc() ?: null;
                $state_stmt->close();

                if ($entered) {
                    // بازگشت به دامنه، تعهد را دوباره در انتظار بررسی اجرا قرار می‌دهد؛
                    // مسئول، برنامه و گزارش‌های پیشین دست‌نخورده باقی می‌مانند.
                    $implementation = $conn->prepare(
                        "INSERT INTO community_law_implementations
                            (law_version_id, community_id, status)
                         VALUES (?, ?, 'pending')
                         ON DUPLICATE KEY UPDATE
                            started_at=IF(status='not_applicable',NULL,started_at),
                            target_date=IF(status='not_applicable',NULL,target_date),
                            completed_at=IF(status='not_applicable',NULL,completed_at),
                            status=IF(status='not_applicable','pending',status)"
                    );
                    if (!$implementation) {
                        $ok = false;
                        break 2;
                    }
                    $implementation->bind_param('ii', $version_id, $affected_id);
                    $implementation_ok = $implementation->execute();
                    $implementation->close();
                    if (!$implementation_ok) {
                        $ok = false;
                        break 2;
                    }
                } elseif ($implementation_state !== null) {
                    // خروج از دامنه، تعهد جاری را متوقف می‌کند؛ جزئیات و گزارش‌ها در history حفظ می‌شوند.
                    $implementation = $conn->prepare(
                        "UPDATE community_law_implementations
                         SET status='not_applicable'
                         WHERE law_version_id=? AND community_id=?"
                    );
                    if (!$implementation) {
                        $ok = false;
                        break 2;
                    }
                    $implementation->bind_param('ii', $version_id, $affected_id);
                    $implementation_ok = $implementation->execute();
                    $implementation->close();
                    if (!$implementation_ok) {
                        $ok = false;
                        break 2;
                    }
                }

                $details = json_encode([
                    'transition' => $entered ? 'entered_scope' : 'left_scope',
                    'trigger' => 'community_parent_changed',
                    'changed_community_id' => $changed_community_id,
                    'affected_community_id' => (int)$affected_id,
                    'old_parent_id' => (int)($old_parent_map[$changed_community_id] ?? 0),
                    'new_parent_id' => (int)($new_parent_map[$changed_community_id] ?? 0),
                    'old_hierarchy_path' => $old_path,
                    'new_hierarchy_path' => $new_path,
                    'scope_roots' => $scope_roots,
                    'implementation_state_before_transition' => $implementation_state
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($details) || !community_law_record_history(
                    $conn,
                    $law_id,
                    $version_id,
                    (int)$affected_id,
                    $actor_user_id > 0 ? $actor_user_id : null,
                    $entered ? 'scope_entered' : 'scope_left',
                    $details
                )) {
                    $ok = false;
                    break 2;
                }
            }
        }
        $active->free();
        return $ok;
    }
}

if (!function_exists('community_law_version_is_effective')) {
    function community_law_version_is_effective(mysqli $conn, int $law_version_id): bool {
        if ($law_version_id <= 0) return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM community_law_versions v
             JOIN community_laws l ON l.id = v.law_id
             WHERE v.id = ?
               AND l.current_version_id = v.id
               AND v.status = 'approved'
               AND l.status = 'approved'
               AND (l.effective_at IS NULL OR l.effective_at <= NOW())
               AND (l.expires_at IS NULL OR l.expires_at > NOW())
               AND l.repealed_at IS NULL
             LIMIT 1"
        );
        if (!$stmt) return false;

        $stmt->bind_param('s', $law_version_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('community_law_applies_to_community')) {
    function community_law_applies_to_community(mysqli $conn, int $law_version_id, int $community_id): bool {
        return community_law_version_is_effective($conn, $law_version_id)
            && community_law_scope_matches($conn, $law_version_id, $community_id);
    }
}

if (!function_exists('community_law_record_history')) {
    function community_law_record_history(
        mysqli $conn,
        int $law_id,
        ?int $law_version_id,
        ?int $community_id,
        ?int $actor_user_id,
        string $event_type,
        ?string $details = null
    ): bool {
        if ($law_id <= 0 || $event_type === '' || mb_strlen($event_type) > 60) return false;

        $stmt = $conn->prepare(
            "INSERT INTO community_law_history
                (law_id, law_version_id, community_id, actor_user_id, event_type, details)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return false;

        $stmt->bind_param(
            'iiiiss',
            $law_id,
            $law_version_id,
            $community_id,
            $actor_user_id,
            $event_type,
            $details
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}


if (!function_exists('community_law_charter_tables_ready')) {
    function community_law_charter_tables_ready(mysqli $conn): bool {
        $required = [
            'community_charter_versions',
            'community_charter_articles',
            'community_law_charter_references',
            'community_law_charter_assessments'
        ];
        $placeholders = implode(',', array_fill(0, count($required), '?'));
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name IN ($placeholders)"
        );
        if (!$stmt) return false;
        $types = str_repeat('s', count($required));
        $params = array_merge([$types], $required);
        $refs = [];
        foreach ($params as $key => $value) $refs[$key] = &$params[$key];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['total'] ?? 0) === count($required);
    }
}

if (!function_exists('community_law_capture_charter_snapshot')) {
    /**
     * نسخه و تمام مواد منشور لازم‌الاجرا را برای یک نسخه قانون تثبیت می‌کند.
     * نبود منشور یکتا، ماده ناقص یا خطای DB به معنی توقف امن است.
     */
    function community_law_capture_charter_snapshot(mysqli $conn, int $law_version_id): bool {
        if ($law_version_id <= 0 || !community_law_charter_tables_ready($conn)) return false;

        $active = $conn->query(
            "SELECT id FROM community_charter_versions
             WHERE status='effective' AND effective_at<=NOW()
             ORDER BY version_number DESC"
        );
        if (!$active) return false;
        $active_rows = [];
        while ($row = $active->fetch_assoc()) $active_rows[] = (int)$row['id'];
        $active->free();
        if (count($active_rows) !== 1 || $active_rows[0] <= 0) return false;
        $charter_version_id = $active_rows[0];

        $existing = $conn->prepare(
            "SELECT COUNT(*) AS total FROM community_law_charter_references
             WHERE law_version_id=?"
        );
        if (!$existing) return false;
        $existing->bind_param('i', $law_version_id);
        if (!$existing->execute()) {
            $existing->close();
            return false;
        }
        $already = (int)$existing->get_result()->fetch_assoc()['total'];
        $existing->close();
        if ($already > 0) return false;

        $articles = $conn->prepare(
            "SELECT id, article_code, title, article_text, principle_group, is_core
             FROM community_charter_articles
             WHERE charter_version_id=? ORDER BY display_order, id"
        );
        if (!$articles) return false;
        $articles->bind_param('i', $charter_version_id);
        if (!$articles->execute()) {
            $articles->close();
            return false;
        }
        $rows = [];
        $result = $articles->get_result();
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $articles->close();
        if (!$rows) return false;

        $insert = $conn->prepare(
            "INSERT INTO community_law_charter_references
                (law_version_id, charter_version_id, charter_article_id, article_code, article_fingerprint)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$insert) return false;
        foreach ($rows as $row) {
            $article_id = (int)$row['id'];
            $article_code = (string)$row['article_code'];
            $fingerprint = hash('sha256', json_encode([
                $row['article_code'], $row['title'], $row['article_text'],
                $row['principle_group'], (int)$row['is_core']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $insert->bind_param(
                'iiiss', $law_version_id, $charter_version_id, $article_id,
                $article_code, $fingerprint
            );
            if (!$insert->execute()) {
                $insert->close();
                return false;
            }
        }
        $insert->close();
        return true;
    }
}

if (!function_exists('community_law_charter_snapshot_is_current')) {
    /**
     * true فقط وقتی snapshot دقیقاً با نسخه منشور لازم‌الاجرا و همه مواد آن برابر باشد.
     * هر تغییر، حذف، افزودن یا ابهام باعث توقف ابلاغ می‌شود.
     */
    function community_law_charter_snapshot_is_current(mysqli $conn, int $law_version_id): ?bool {
        if ($law_version_id <= 0 || !community_law_charter_tables_ready($conn)) return null;

        $active = $conn->query(
            "SELECT id FROM community_charter_versions
             WHERE status='effective' AND effective_at<=NOW()"
        );
        if (!$active) return null;
        $active_ids = [];
        while ($row = $active->fetch_assoc()) $active_ids[] = (int)$row['id'];
        $active->free();
        if (count($active_ids) !== 1) return null;
        $active_id = $active_ids[0];

        $stmt = $conn->prepare(
            "SELECT r.charter_version_id, r.charter_article_id, r.article_code,
                    r.article_fingerprint, a.title, a.article_text,
                    a.principle_group, a.is_core
             FROM community_law_charter_references r
             JOIN community_charter_articles a ON a.id=r.charter_article_id
             WHERE r.law_version_id=? ORDER BY r.charter_article_id"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $law_version_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $snapshots = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $snapshots[] = $row;
        $stmt->close();
        if (!$snapshots) return false;

        $active_articles = $conn->prepare(
            "SELECT id FROM community_charter_articles
             WHERE charter_version_id=? ORDER BY display_order, id"
        );
        if (!$active_articles) return null;
        $active_articles->bind_param('i', $active_id);
        if (!$active_articles->execute()) {
            $active_articles->close();
            return null;
        }
        $expected_ids = [];
        $result = $active_articles->get_result();
        while ($row = $result->fetch_assoc()) $expected_ids[] = (int)$row['id'];
        $active_articles->close();

        $captured_ids = [];
        foreach ($snapshots as $row) {
            if ((int)$row['charter_version_id'] !== $active_id) return false;
            $captured_ids[] = (int)$row['charter_article_id'];
            $fingerprint = hash('sha256', json_encode([
                $row['article_code'], $row['title'], $row['article_text'],
                $row['principle_group'], (int)$row['is_core']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!hash_equals((string)$row['article_fingerprint'], $fingerprint)) return false;
        }
        sort($captured_ids, SORT_NUMERIC);
        sort($expected_ids, SORT_NUMERIC);
        return $captured_ids === $expected_ids;
    }
}
