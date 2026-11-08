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
            'community_law_appeal_assessments',
            'community_charter_versions',
            'community_charter_articles',
            'community_law_charter_references',
            'community_law_charter_assessments'
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



if (!function_exists('community_charter_assign_review_panel')) {
    /**
     * پنج ارزیاب مستقل و بقیه واجدان شرایط را در دو snapshot جدا ثبت می‌کند.
     * ورودی باید از قبل به‌صورت تصادفی مرتب شده باشد؛ فراخواننده تراکنش را مدیریت می‌کند.
     */
    function community_charter_assign_review_panel(
        mysqli $conn,
        int $amendment_id,
        array $ordered_candidate_ids,
        int $reviewer_count = 5,
        int $minimum_electorate = 3
    ): array {
        if ($amendment_id <= 0 || $reviewer_count < 1 || $minimum_electorate < 1) {
            throw new RuntimeException('پارامترهای تخصیص بررسی منشور معتبر نیست.');
        }
        $candidate_ids = [];
        $seen = [];
        foreach ($ordered_candidate_ids as $candidate_id) {
            $candidate_id = (int)$candidate_id;
            if ($candidate_id <= 0 || isset($seen[$candidate_id])) {
                throw new RuntimeException('فهرست نامزدهای بررسی منشور تکراری یا نامعتبر است.');
            }
            $seen[$candidate_id] = true;
            $candidate_ids[] = $candidate_id;
        }
        if (count($candidate_ids) < $reviewer_count + $minimum_electorate) {
            throw new RuntimeException('تعداد نامزدها برای تشکیل هیئت بررسی و فهرست رأی‌دهندگان کافی نیست.');
        }

        $reviewer_ids = array_slice($candidate_ids, 0, $reviewer_count);
        $electorate_ids = array_slice($candidate_ids, $reviewer_count);
        $reviewer_insert = $conn->prepare(
            "INSERT INTO community_charter_amendment_reviewers (amendment_id,reviewer_user_id) VALUES (?,?)"
        );
        if (!$reviewer_insert) throw new RuntimeException('ثبت ارزیابان مستقل ممکن نیست.');
        foreach ($reviewer_ids as $reviewer_id) {
            $reviewer_insert->bind_param('ii', $amendment_id, $reviewer_id);
            if (!$reviewer_insert->execute()) {
                $reviewer_insert->close();
                throw new RuntimeException('ثبت ارزیاب مستقل انجام نشد.');
            }
        }
        $reviewer_insert->close();

        $electorate_insert = $conn->prepare(
            "INSERT INTO community_charter_amendment_electorate (amendment_id,user_id) VALUES (?,?)"
        );
        if (!$electorate_insert) throw new RuntimeException('ثبت فهرست رأی‌دهندگان ممکن نیست.');
        foreach ($electorate_ids as $voter_id) {
            $electorate_insert->bind_param('ii', $amendment_id, $voter_id);
            if (!$electorate_insert->execute()) {
                $electorate_insert->close();
                throw new RuntimeException('ثبت رأی‌دهندگان انجام نشد.');
            }
        }
        $electorate_insert->close();

        return ['reviewer_ids' => $reviewer_ids, 'electorate_ids' => $electorate_ids];
    }
}

if (!function_exists('community_charter_assign_appeal_reviewers')) {
    /**
     * سه ارزیاب اعتراض را از فهرست نامزدهای از قبل پالایش و تصادفی‌مرتب‌شده ثبت می‌کند.
     * فراخواننده تراکنش را مدیریت می‌کند.
     */
    function community_charter_assign_appeal_reviewers(
        mysqli $conn,
        int $appeal_id,
        array $ordered_candidate_ids,
        int $reviewer_count = 3
    ): array {
        if ($appeal_id <= 0 || $reviewer_count < 3) {
            throw new RuntimeException('پارامترهای تخصیص ارزیاب اعتراض معتبر نیست.');
        }
        $candidate_ids = [];
        $seen = [];
        foreach ($ordered_candidate_ids as $candidate_id) {
            $candidate_id = (int)$candidate_id;
            if ($candidate_id <= 0 || isset($seen[$candidate_id])) {
                throw new RuntimeException('فهرست ارزیابان اعتراض تکراری یا نامعتبر است.');
            }
            $seen[$candidate_id] = true;
            $candidate_ids[] = $candidate_id;
        }
        if (count($candidate_ids) < $reviewer_count) {
            throw new RuntimeException('برای رسیدگی مستقل به اعتراض ارزیاب کافی وجود ندارد.');
        }
        $reviewer_ids = array_slice($candidate_ids, 0, $reviewer_count);
        $assignment = $conn->prepare(
            "INSERT INTO community_charter_amendment_appeal_reviewer_assignments (appeal_id,reviewer_user_id) VALUES (?,?)"
        );
        if (!$assignment) throw new RuntimeException('تخصیص ارزیابان اعتراض ممکن نیست.');
        foreach ($reviewer_ids as $reviewer_id) {
            $assignment->bind_param('ii', $appeal_id, $reviewer_id);
            if (!$assignment->execute()) {
                $assignment->close();
                throw new RuntimeException('تخصیص ارزیاب اعتراض انجام نشد.');
            }
        }
        $assignment->close();
        return $reviewer_ids;
    }
}

if (!function_exists('community_charter_amendment_reviewer_result')) {
    /**
     * پنج ارزیاب مستقل لازم است؛ دست‌کم چهار ارزیاب باید حمایت کنند.
     * تعارض یا تضعیف هر یک از محورهای حفاظت، اصلاح را مردود می‌کند؛
     * ابهام به معنی عدم آمادگی برای رأی‌گیری است.
     */
    function community_charter_amendment_reviewer_result(
        array $assessments,
        int $quorum = 5,
        int $approval_numerator = 4,
        int $approval_denominator = 5
    ): string {
        if ($quorum < 5 || $approval_denominator <= 0 ||
            $approval_numerator <= 0 || $approval_numerator > $approval_denominator ||
            count($assessments) < $quorum) {
            return 'pending';
        }

        $seen = [];
        $support = 0;
        $dimensions = [
            'rights_protection',
            'equality_and_non_discrimination',
            'living_beings_and_environment',
            'power_and_accountability',
            'future_generations'
        ];

        foreach ($assessments as $assessment) {
            if (!is_array($assessment) || !isset($assessment['reviewer_user_id']) ||
                (int)$assessment['reviewer_user_id'] <= 0) return 'pending';
            $reviewer_id = (int)$assessment['reviewer_user_id'];
            if (isset($seen[$reviewer_id])) return 'pending';
            $seen[$reviewer_id] = true;

            $verdict = $assessment['verdict'] ?? '';
            if ($verdict === 'conflict') return 'rejected';
            if ($verdict !== 'supports' && $verdict !== 'unclear') return 'pending';

            foreach ($dimensions as $dimension) {
                $value = $assessment[$dimension] ?? '';
                if ($value === 'weakened') return 'rejected';
                if ($value !== 'maintained' && $value !== 'improved') return 'pending';
            }
            if ($verdict === 'supports') $support++;
        }

        if (count($seen) < $quorum) return 'pending';
        return ($support * $approval_denominator >= count($seen) * $approval_numerator)
            ? 'approved'
            : 'rejected';
    }
}

if (!function_exists('community_charter_amendment_vote_result')) {
    /**
     * مشارکت حداقل دوسوم واجدان شرایط و موافقت حداقل سه‌چهارم آرای معتبر لازم است.
     * رأی ممتنع در مشارکت محاسبه می‌شود، اما در نسبت موافقت/مخالفت وارد نمی‌شود.
     */
    function community_charter_amendment_vote_result(
        int $electorate,
        int $yes,
        int $no,
        int $abstain,
        int $turnout_numerator = 2,
        int $turnout_denominator = 3,
        int $approval_numerator = 3,
        int $approval_denominator = 4
    ): string {
        if ($electorate <= 0 || min($yes, $no, $abstain) < 0 ||
            $turnout_denominator <= 0 || $approval_denominator <= 0 ||
            $turnout_numerator <= 0 || $approval_numerator <= 0 ||
            $turnout_numerator > $turnout_denominator ||
            $approval_numerator > $approval_denominator) return 'invalid';

        $participation = $yes + $no + $abstain;
        if ($participation > $electorate) return 'invalid';
        if ($participation * $turnout_denominator < $electorate * $turnout_numerator) {
            return 'no_quorum';
        }
        $valid_votes = $yes + $no;
        if ($valid_votes === 0) return 'no_quorum';
        return ($yes * $approval_denominator >= $valid_votes * $approval_numerator)
            ? 'approved'
            : 'rejected';
    }
}

if (!function_exists('community_charter_amendment_items_are_valid')) {
    /**
     * حذف ماده بنیادین (is_core=1) ممنوع است.
     * اصلاح متن ماده بنیادین ممکن است، اما فقط از مسیر بررسی سخت‌گیرانه منشور.
     */
    function community_charter_amendment_items_are_valid(array $items): bool {
        if (!$items) return false;
        $seen_targets = [];
        $seen_codes = [];
        foreach ($items as $item) {
            if (!is_array($item)) return false;
            $action = $item['action'] ?? '';
            $code = trim((string)($item['article_code'] ?? ''));
            if (!in_array($action, ['add', 'replace', 'remove'], true) || $code === '') return false;
            if (isset($seen_codes[$code])) return false;
            $seen_codes[$code] = true;

            if ($action === 'add') {
                if (!empty($item['target_article_id']) || empty($item['proposed_title']) ||
                    empty($item['proposed_text']) || empty($item['principle_group'])) return false;
                continue;
            }

            $target = (int)($item['target_article_id'] ?? 0);
            if ($target <= 0 || isset($seen_targets[$target])) return false;
            $seen_targets[$target] = true;
            if ($action === 'remove' && !empty($item['is_core'])) return false;
            if ($action === 'replace' &&
                (empty($item['proposed_title']) || empty($item['proposed_text']) ||
                 empty($item['principle_group']))) return false;
        }
        return true;
    }
}


if (!function_exists('community_charter_amendment_appeal_result')) {
    /**
     * اعتراض با رأی دست‌کم دو نفر از سه ارزیاب مستقل پذیرفته یا رد می‌شود.
     * رأی نامشخص یا اختلاف بدون اکثریت، پرونده را باز نگه می‌دارد.
     */
    function community_charter_amendment_appeal_result(
        array $assessments,
        int $quorum = 3,
        int $majority = 2
    ): string {
        if ($quorum < 3 || $majority < 2 || $majority > $quorum) return 'invalid';
        $seen = [];
        $uphold = 0;
        $dismiss = 0;
        foreach ($assessments as $assessment) {
            if (!is_array($assessment) || (int)($assessment['reviewer_user_id'] ?? 0) <= 0) {
                return 'invalid';
            }
            $reviewer_id = (int)$assessment['reviewer_user_id'];
            if (isset($seen[$reviewer_id])) return 'invalid';
            $seen[$reviewer_id] = true;
            $verdict = $assessment['verdict'] ?? '';
            if (!in_array($verdict, ['uphold','dismiss','unclear'], true)) return 'invalid';
            if ($verdict === 'uphold') $uphold++;
            if ($verdict === 'dismiss') $dismiss++;
        }
        if (count($seen) < $quorum) return 'pending';
        if ($uphold >= $majority) return 'upheld';
        if ($dismiss >= $majority) return 'dismissed';
        return 'pending';
    }
}


if (!function_exists('community_charter_start_independent_review')) {
    /**
     * پرونده draft را پس از تخصیص موفق ارزیابان وارد بررسی مستقل می‌کند.
     * فراخواننده باید تراکنش و قفل پرونده را مدیریت کند.
     */
    function community_charter_start_independent_review(mysqli $conn, int $amendment_id): void {
        if ($amendment_id <= 0) throw new RuntimeException('شناسه اصلاح منشور معتبر نیست.');
        $stmt = $conn->prepare(
            "UPDATE community_charter_amendments
             SET status='independent_review',review_opens_at=NOW(),
                 review_closes_at=DATE_ADD(NOW(),INTERVAL 14 DAY)
             WHERE id=? AND status='draft'"
        );
        if (!$stmt) throw new RuntimeException('آغاز بررسی مستقل ممکن نیست.');
        $stmt->bind_param('i', $amendment_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('پرونده برای آغاز بررسی مستقل در وضعیت draft نیست.');
        }
        $stmt->close();
    }
}

if (!function_exists('community_charter_advance_to_public_voting')) {
    /**
     * نتیجه تأیید بررسی مستقل را به رأی‌گیری عمومی منتقل می‌کند.
     * فراخواننده باید تراکنش و قفل پرونده را مدیریت کند.
     */
    function community_charter_advance_to_public_voting(mysqli $conn, int $amendment_id): void {
        if ($amendment_id <= 0) throw new RuntimeException('شناسه اصلاح منشور معتبر نیست.');
        $stmt = $conn->prepare(
            "UPDATE community_charter_amendments
             SET status='public_voting',voting_opens_at=NOW(),
                 voting_closes_at=DATE_ADD(NOW(),INTERVAL 14 DAY)
             WHERE id=? AND status='independent_review'"
        );
        if (!$stmt) throw new RuntimeException('آغاز رأی‌گیری عمومی ممکن نیست.');
        $stmt->bind_param('i', $amendment_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('پرونده در مرحله بررسی مستقل نیست.');
        }
        $stmt->close();
    }
}

if (!function_exists('community_charter_advance_to_appeal_period')) {
    /**
     * نتیجه تأیید رأی‌گیری را وارد مهلت اعتراض می‌کند.
     * فراخواننده باید تراکنش و قفل پرونده را مدیریت کند.
     */
    function community_charter_advance_to_appeal_period(mysqli $conn, int $amendment_id): void {
        if ($amendment_id <= 0) throw new RuntimeException('شناسه اصلاح منشور معتبر نیست.');
        $stmt = $conn->prepare(
            "UPDATE community_charter_amendments
             SET status='appeal_period',approved_at=NOW(),
                 appeal_closes_at=DATE_ADD(NOW(),INTERVAL 7 DAY)
             WHERE id=? AND status='public_voting'"
        );
        if (!$stmt) throw new RuntimeException('ثبت نتیجه رأی‌گیری ممکن نیست.');
        $stmt->bind_param('i', $amendment_id);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('پرونده در مرحله رأی‌گیری عمومی نیست.');
        }
        $stmt->close();
    }
}

if (!function_exists('community_charter_amendment_activate')) {
    /**
     * اجرای تراکنشی فعال‌سازی منشور؛ این تابع هم در endpoint و هم در آزمون یکپارچه استفاده می‌شود.
     * در صورت هر خطا rollback می‌کند و خطا را به فراخواننده می‌سپارد.
     */
    function community_charter_amendment_activate(mysqli $conn, int $amendment_id): void {
        if ($amendment_id <= 0) throw new RuntimeException('شناسه اصلاحیه معتبر نیست.');
        if (!$conn->begin_transaction()) throw new RuntimeException('شروع تراکنش فعال‌سازی انجام نشد.');

        try {
            $active = $conn->query(
                "SELECT id FROM community_charter_versions
                 WHERE status='effective' AND effective_at<=NOW() FOR UPDATE"
            );
            if (!$active) throw new RuntimeException('قفل منشور جاری ممکن نیست.');
            $active_ids = [];
            while ($row = $active->fetch_assoc()) $active_ids[] = (int)$row['id'];
            $active->free();
            if (count($active_ids) !== 1) throw new RuntimeException('نسخه جاری منشور یکتا نیست.');

            $lock = $conn->prepare(
                "SELECT base_charter_version_id,proposed_charter_version_id,status,appeal_closes_at,
                        (appeal_closes_at IS NOT NULL AND appeal_closes_at<=NOW()) AS appeal_closed
                 FROM community_charter_amendments WHERE id=? FOR UPDATE"
            );
            if (!$lock) throw new RuntimeException('قفل اصلاحیه ممکن نیست.');
            $lock->bind_param('i', $amendment_id);
            if (!$lock->execute()) throw new RuntimeException('خواندن اصلاحیه انجام نشد.');
            $amendment = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$amendment || $amendment['status'] !== 'appeal_period' ||
                (int)$amendment['base_charter_version_id'] !== $active_ids[0] ||
                empty($amendment['appeal_closes_at']) || (int)$amendment['appeal_closed'] !== 1) {
                throw new RuntimeException('فعال‌سازی فقط پس از پایان مهلت اعتراض و در صورت جاری بودن منشور پایه ممکن است.');
            }

            $open_appeals = $conn->prepare(
                "SELECT COUNT(*) AS total FROM community_charter_amendment_appeals
                 WHERE amendment_id=? AND status IN ('submitted','under_review')"
            );
            if (!$open_appeals) throw new RuntimeException('بررسی اعتراض‌های باز ممکن نیست.');
            $open_appeals->bind_param('i', $amendment_id);
            if (!$open_appeals->execute()) throw new RuntimeException('بررسی اعتراض‌های باز انجام نشد.');
            $open_count = (int)$open_appeals->get_result()->fetch_assoc()['total'];
            $open_appeals->close();
            if ($open_count > 0) throw new RuntimeException('تا رسیدگی نهایی به همه اعتراض‌های ثبت‌شده، منشور فعال نمی‌شود.');

            $pending = $conn->prepare(
                "UPDATE community_charter_amendments SET status='approved_pending_activation'
                 WHERE id=? AND status='appeal_period'"
            );
            if (!$pending) throw new RuntimeException('آماده‌سازی فعال‌سازی ممکن نیست.');
            $pending->bind_param('i', $amendment_id);
            if (!$pending->execute() || $pending->affected_rows !== 1) throw new RuntimeException('آماده‌سازی فعال‌سازی انجام نشد.');
            $pending->close();

            $supersede = $conn->prepare(
                "UPDATE community_charter_versions SET status='superseded'
                 WHERE id=? AND status='effective'"
            );
            if (!$supersede) throw new RuntimeException('بستن نسخه قبلی منشور ممکن نیست.');
            $supersede->bind_param('i', $active_ids[0]);
            if (!$supersede->execute() || $supersede->affected_rows !== 1) {
                throw new RuntimeException('بستن نسخه قبلی منشور انجام نشد.');
            }
            $supersede->close();

            $new_version_id = (int)$amendment['proposed_charter_version_id'];
            $activate_version = $conn->prepare(
                "UPDATE community_charter_versions
                 SET status='effective',approved_at=NOW(),effective_at=NOW()
                 WHERE id=? AND status='draft'"
            );
            if (!$activate_version) throw new RuntimeException('فعال‌سازی نسخه تازه ممکن نیست.');
            $activate_version->bind_param('i', $new_version_id);
            if (!$activate_version->execute() || $activate_version->affected_rows !== 1) {
                throw new RuntimeException('فعال‌سازی نسخه تازه انجام نشد.');
            }
            $activate_version->close();

            $activated = $conn->prepare(
                "UPDATE community_charter_amendments SET status='activated',activated_at=NOW()
                 WHERE id=? AND status='approved_pending_activation'"
            );
            if (!$activated) throw new RuntimeException('ثبت وضعیت نهایی اصلاحیه ممکن نیست.');
            $activated->bind_param('i', $amendment_id);
            if (!$activated->execute() || $activated->affected_rows !== 1) {
                throw new RuntimeException('ثبت وضعیت نهایی اصلاحیه انجام نشد.');
            }
            $activated->close();

            if (!$conn->commit()) throw new RuntimeException('فعال‌سازی منشور نهایی نشد.');
        } catch (Throwable $error) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            throw $error;
        }
    }
}
