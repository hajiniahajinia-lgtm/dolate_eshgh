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
            'community_law_precedent_assessments'
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
        foreach ($parent_map as $community_id => $_parent_id) {
            $cursor = (int)$community_id;
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
        if ($law_version_id <= 0 || $target_community_id <= 0) return false;

        $scope_stmt = $conn->prepare(
            "SELECT scope_community_id, include_descendants
             FROM community_law_scopes
             WHERE law_version_id = ?"
        );
        if (!$scope_stmt) return false;

        $scope_stmt->bind_param('s', $law_version_id);
        if (!$scope_stmt->execute()) {
            $scope_stmt->close();
            return false;
        }

        $scopes = $scope_stmt->get_result();
        $scope_rows = [];
        while ($row = $scopes->fetch_assoc()) {
            $scope_rows[] = [
                'community_id' => (int)$row['scope_community_id'],
                'include_descendants' => (int)$row['include_descendants'] === 1
            ];
        }
        $scope_stmt->close();

        if (!$scope_rows) return false;

        $parent_map = community_law_load_parent_map($conn);
        if ($parent_map === null) return false;

        foreach ($scope_rows as $scope) {
            if ($target_community_id === $scope['community_id']) return true;

            if (
                $scope['include_descendants'] &&
                community_law_is_descendant_or_same(
                    $parent_map,
                    $target_community_id,
                    $scope['community_id']
                )
            ) {
                return true;
            }
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
                'descendants' => (int)$row['include_descendants'] === 1
            ];
        }
        $stmt->close();
        if (!$scopes) return null;

        $parent_map = community_law_load_parent_map($conn);
        if ($parent_map === null) return null;

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
