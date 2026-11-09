<?php
/**
 * گردش‌کار اصلاح محدود قانون محلی.
 * نسخهٔ مصوب مبنا فقط خوانده می‌شود؛ نسخهٔ پیشنهادی تازه با وضعیت draft ساخته می‌شود.
 */
if (!function_exists('community_law_amendment_tables_ready')) {
    function community_law_amendment_tables_ready(mysqli $conn): bool {
        $tables=['community_law_amendment_cases','community_law_amendment_articles','community_law_amendment_events','community_law_amendment_parent_reviews','community_law_version_notices'];
        $stmt=$conn->prepare("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?,?)");
        if (!$stmt) return false;
        $table1=$tables[0]; $table2=$tables[1]; $table3=$tables[2];
        $table4=$tables[3]; $table5=$tables[4];
        $stmt->bind_param('sssss',$table1,$table2,$table3,$table4,$table5);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $n=(int)$stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        if ($n!==count($tables)) return false;

        $column_check=$conn->prepare(
            "SELECT COUNT(*) AS n FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='community_law_amendment_cases'
               AND column_name IN ('voting_rules_source_version_id','rules_adopted_by','rules_adopted_at')"
        );
        if (!$column_check || !$column_check->execute()) {
            if ($column_check) $column_check->close();
            return false;
        }
        $column_count=(int)$column_check->get_result()->fetch_assoc()['n'];
        $column_check->close();
        if ($column_count!==3) return false;

        $status_check=$conn->prepare(
            "SELECT COUNT(*) AS n FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='community_law_amendment_cases'
               AND column_name='status' AND column_type LIKE '%vote_insufficient%'"
        );
        if (!$status_check || !$status_check->execute()) {
            if ($status_check) $status_check->close();
            return false;
        }
        $status_ready=(int)$status_check->get_result()->fetch_assoc()['n']===1;
        $status_check->close();
        if (!$status_ready) return false;
        $review_columns=$conn->prepare(
            "SELECT COUNT(*) AS n FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='community_law_amendment_cases'
               AND column_name IN ('voting_rules_source_version_id','rules_adopted_by','rules_adopted_at')"
        );
        if (!$review_columns || !$review_columns->execute()) { if ($review_columns) $review_columns->close(); return false; }
        $review_columns_count=(int)$review_columns->get_result()->fetch_assoc()['n'];
        $review_columns->close();
        return $review_columns_count===3;
    }
}

if (!function_exists('community_law_create_amendment_draft')) {
    /**
     * @param array<int,array{reference:string,previous_text:string,proposed_text:string,reason:string}> $articles
     * @return int|false
     */
    function community_law_create_amendment_draft(
        mysqli $conn,
        int $conformity_case_id,
        int $community_id,
        int $user_id,
        string $rationale,
        array $articles,
        ?string $evidence=null
    ): int|false {
        $rationale=trim($rationale);
        $evidence=$evidence!==null?trim($evidence):null;
        if ($conformity_case_id<=0 || $community_id<=0 || $user_id<=0 ||
            $rationale==='' || mb_strlen($rationale)>30000 ||
            ($evidence!==null && mb_strlen($evidence)>30000) ||
            count($articles)<1 || count($articles)>20) return false;

        $normalized=[];
        $seen=[];
        foreach ($articles as $article) {
            if (!is_array($article)) return false;
            $reference=trim((string)($article['reference']??''));
            $previous=trim((string)($article['previous_text']??''));
            $proposed=trim((string)($article['proposed_text']??''));
            $reason=trim((string)($article['reason']??''));
            if ($reference==='' || mb_strlen($reference)>120 || isset($seen[$reference]) ||
                $previous==='' || $proposed==='' || $previous===$proposed ||
                mb_strlen($previous)>30000 || mb_strlen($proposed)>30000 ||
                $reason==='' || mb_strlen($reason)>10000) return false;
            $seen[$reference]=true;
            $normalized[]=['reference'=>$reference,'previous_text'=>$previous,'proposed_text'=>$proposed,'reason'=>$reason];
        }

        if (!community_law_amendment_tables_ready($conn)) return false;
        $started=false;
        try {
            if (!$conn->begin_transaction()) return false;
            $started=true;

            $member=$conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
            if (!$member) throw new RuntimeException('membership prepare failed');
            $member->bind_param('ii',$community_id,$user_id);
            if (!$member->execute() || !$member->get_result()->fetch_row()) {
                $member->close();
                throw new RuntimeException('local membership required');
            }
            $member->close();

            $case_stmt=$conn->prepare(
                "SELECT c.id,c.community_id,c.local_law_id,c.local_law_version_id,c.parent_community_id,c.parent_law_version_id,c.status,child.parent_community_id AS current_parent_community_id,
                        l.originating_community_id,l.status AS law_status,l.current_version_id,
                        v.version_number,v.title,v.law_text,v.implementation_plan,v.source_topic_id,v.source_idea_id,
                        v.source_decision_id,v.status AS version_status,
                        pl.status AS parent_law_status,pl.current_version_id AS parent_current_version_id,
                        pl.effective_at AS parent_effective_at,pl.expires_at AS parent_expires_at,
                        pl.repealed_at AS parent_repealed_at,pv.status AS parent_version_status
                 FROM community_law_conformity_cases c
                 JOIN community_laws l ON l.id=c.local_law_id
                 JOIN community_law_versions v ON v.id=c.local_law_version_id
                 JOIN community_laws pl ON pl.id=c.parent_law_id
                 JOIN community_law_versions pv ON pv.id=c.parent_law_version_id AND pv.law_id=pl.id
                 JOIN communities child ON child.id=c.community_id
                 WHERE c.id=? FOR UPDATE"
            );
            if (!$case_stmt) throw new RuntimeException('conformity case prepare failed');
            $case_stmt->bind_param('i',$conformity_case_id);
            if (!$case_stmt->execute()) throw new RuntimeException('conformity case read failed');
            $record=$case_stmt->get_result()->fetch_assoc();
            $case_stmt->close();
            if (!$record || (int)$record['community_id']!==$community_id ||
                (int)$record['current_parent_community_id']!==(int)$record['parent_community_id'] ||
                (int)$record['originating_community_id']!==$community_id ||
                $record['status']!=='amendment_required' ||
                $record['law_status']!=='approved' || $record['version_status']!=='approved' ||
                (int)$record['current_version_id']!==(int)$record['local_law_version_id'] ||
                $record['parent_law_status']!=='approved' || $record['parent_version_status']!=='approved' ||
                community_law_scope_match_checked($conn,(int)$record['parent_law_version_id'],$community_id)!==true ||
                (int)$record['parent_current_version_id']!==(int)$record['parent_law_version_id'] ||
                !empty($record['parent_repealed_at']) ||
                (!empty($record['parent_effective_at']) && strtotime($record['parent_effective_at'])>time()) ||
                (!empty($record['parent_expires_at']) && strtotime($record['parent_expires_at'])<=time())) {
                throw new RuntimeException('amendment is not authorized for this current local law version');
            }

            $open=$conn->prepare(
                "SELECT id FROM community_law_amendment_cases
                 WHERE law_id=? AND status IN ('draft','submitted','local_voting','parent_review')
                 LIMIT 1 FOR UPDATE"
            );
            if (!$open) throw new RuntimeException('open amendment check prepare failed');
            $law_id=(int)$record['local_law_id'];
            $open->bind_param('i',$law_id);
            if (!$open->execute()) throw new RuntimeException('open amendment check failed');
            $has_open=(bool)$open->get_result()->fetch_row();
            $open->close();
            if ($has_open) throw new RuntimeException('an amendment is already in progress for this law');

            $confirmed_refs=[];
            $conflict_refs=$conn->prepare(
                "SELECT DISTINCT local_article_reference
                 FROM community_law_conformity_conflicts
                 WHERE case_id=? AND finding='conflict_confirmed'"
            );
            if (!$conflict_refs) throw new RuntimeException('confirmed conflict article lookup prepare failed');
            $conflict_refs->bind_param('i',$conformity_case_id);
            if (!$conflict_refs->execute()) throw new RuntimeException('confirmed conflict article lookup failed');
            $conflict_rows=$conflict_refs->get_result();
            while ($conflict_row=$conflict_rows->fetch_assoc()) {
                $confirmed_refs[(string)$conflict_row['local_article_reference']]=true;
            }
            $conflict_refs->close();
            if (!$confirmed_refs) throw new RuntimeException('no independently confirmed conflict articles');

            // A conformity case cannot be amended or later resolved while any
            // identified conflict is still unreviewed or disputed.
            $open_conflicts=$conn->prepare(
                "SELECT COUNT(*) AS total FROM community_law_conformity_conflicts
                 WHERE case_id=? AND finding NOT IN ('conflict_confirmed','no_conflict')"
            );
            if (!$open_conflicts) throw new RuntimeException('unresolved conflict check prepare failed');
            $open_conflicts->bind_param('i',$conformity_case_id);
            if (!$open_conflicts->execute()) throw new RuntimeException('unresolved conflict check failed');
            $unresolved_count=(int)$open_conflicts->get_result()->fetch_assoc()['total'];
            $open_conflicts->close();
            if ($unresolved_count>0) throw new RuntimeException('all identified conflicts must receive a conclusive independent review');

            foreach ($normalized as $article) {
                if (!isset($confirmed_refs[$article['reference']])) {
                    throw new RuntimeException('amendment may only change articles with a confirmed conflict');
                }
            }
            if (count($normalized)!==count($confirmed_refs)) {
                throw new RuntimeException('amendment must address every independently confirmed conflict article');
            }

            $base_text=(string)$record['law_text'];
            $replacements=[];
            foreach ($normalized as $article) {
                $occurrences=substr_count($base_text,$article['previous_text']);
                if ($occurrences!==1) throw new RuntimeException('previous article text must occur exactly once in the approved version');
                $position=strpos($base_text,$article['previous_text']);
                if ($position===false) throw new RuntimeException('previous article text not found');
                $replacements[]=['start'=>$position,'length'=>strlen($article['previous_text']),'new'=>$article['proposed_text']];
            }
            usort($replacements,static fn($a,$b)=>$b['start']<=>$a['start']);
            $last_start=strlen($base_text)+1;
            $new_text=$base_text;
            foreach ($replacements as $replacement) {
                if ($replacement['start']+$replacement['length']>$last_start) {
                    throw new RuntimeException('article text ranges overlap');
                }
                $new_text=substr_replace($new_text,$replacement['new'],$replacement['start'],$replacement['length']);
                $last_start=$replacement['start'];
            }
            if ($new_text===$base_text || mb_strlen($new_text)>50000) throw new RuntimeException('invalid resulting law text');

            $next_stmt=$conn->prepare('SELECT COALESCE(MAX(version_number),0)+1 AS next_version_number FROM community_law_versions WHERE law_id=?');
            if (!$next_stmt) throw new RuntimeException('amendment version number prepare failed');
            $next_stmt->bind_param('i',$law_id);
            if (!$next_stmt->execute()) throw new RuntimeException('amendment version number lookup failed');
            $next_number=(int)$next_stmt->get_result()->fetch_assoc()['next_version_number'];
            $next_stmt->close();
            $version_insert=$conn->prepare(
                "INSERT INTO community_law_versions
                    (law_id,version_number,title,law_text,implementation_plan,change_summary,
                     source_topic_id,source_idea_id,source_decision_id,proposed_by,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'draft')"
            );
            if (!$version_insert) throw new RuntimeException('new version prepare failed');
            $title=(string)$record['title'];
            $plan=$record['implementation_plan'];
            $summary='پیشنهاد اصلاح محدود از پروندهٔ انطباق شماره '.$conformity_case_id.'؛ نسخهٔ مبنا '.$record['version_number'];
            $source_topic=$record['source_topic_id']!==null?(int)$record['source_topic_id']:null;
            $source_idea=$record['source_idea_id']!==null?(int)$record['source_idea_id']:null;
            $source_decision=$record['source_decision_id']!==null?(int)$record['source_decision_id']:null;
            $version_insert->bind_param('iissssiiii',$law_id,$next_number,$title,$new_text,$plan,$summary,$source_topic,$source_idea,$source_decision,$user_id);
            if (!$version_insert->execute()) throw new RuntimeException('new version insert failed');
            $new_version_id=(int)$conn->insert_id;
            $version_insert->close();
            $base_version_id=(int)$record['local_law_version_id'];

            // دامنهٔ الزام‌آور نسخهٔ پیشنهادی دقیقاً از نسخهٔ مصوب مبنا snapshot می‌شود.
            $scope_copy=$conn->prepare(
                'INSERT INTO community_law_scopes (law_version_id,scope_community_id,include_descendants)
                 SELECT ?,scope_community_id,include_descendants
                 FROM community_law_scopes WHERE law_version_id=?'
            );
            if (!$scope_copy) throw new RuntimeException('amendment scope copy prepare failed');
            $scope_copy->bind_param('ii',$new_version_id,$base_version_id);
            if (!$scope_copy->execute() || $scope_copy->affected_rows<1) {
                throw new RuntimeException('amendment base scope missing');
            }
            $scope_copy->close();

            $case_insert=$conn->prepare(
                "INSERT INTO community_law_amendment_cases
                    (law_id,base_version_id,proposed_version_id,conformity_case_id,community_id,proposed_by,status,rationale,evidence)
                 VALUES (?,?,?,?,?,?,'draft',?,?)"
            );
            if (!$case_insert) throw new RuntimeException('amendment case insert prepare failed');
            $case_insert->bind_param('iiiiiiss',$law_id,$base_version_id,$new_version_id,$conformity_case_id,$community_id,$user_id,$rationale,$evidence);
            if (!$case_insert->execute()) throw new RuntimeException('amendment case insert failed');
            $amendment_id=(int)$conn->insert_id;
            $case_insert->close();

            $article_insert=$conn->prepare(
                'INSERT INTO community_law_amendment_articles (case_id,article_reference,previous_text,proposed_text,change_reason) VALUES (?,?,?,?,?)'
            );
            if (!$article_insert) throw new RuntimeException('amendment article prepare failed');
            foreach ($normalized as $article) {
                $article_insert->bind_param('issss',$amendment_id,$article['reference'],$article['previous_text'],$article['proposed_text'],$article['reason']);
                if (!$article_insert->execute()) throw new RuntimeException('amendment article insert failed');
            }
            $article_insert->close();

            $details=json_encode([
                'base_version_id'=>$base_version_id,'proposed_version_id'=>$new_version_id,
                'conformity_case_id'=>$conformity_case_id,'article_count'=>count($normalized),
                'status'=>'draft','approved_version_unchanged'=>true
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $event=$conn->prepare('INSERT INTO community_law_amendment_events (case_id,actor_user_id,event_type,details) VALUES (?,?,?,?)');
            if (!$event) throw new RuntimeException('amendment event prepare failed');
            $event_type='amendment_draft_created';
            $event->bind_param('iiss',$amendment_id,$user_id,$event_type,$details);
            if (!$event->execute()) throw new RuntimeException('amendment event insert failed');
            $event->close();

            if (!community_law_record_history($conn,$law_id,$new_version_id,$community_id,$user_id,'amendment_draft_created',$details)) {
                throw new RuntimeException('law history insert failed');
            }
            if (!$conn->commit()) throw new RuntimeException('amendment transaction commit failed');
            $started=false;
            return $amendment_id;
        } catch (Throwable $e) {
            if ($started) $conn->rollback();
            error_log('[Dolate Eshgh law amendment draft] '.$e->getMessage());
            return false;
        }
    }
}


if (!function_exists('community_law_submit_amendment_for_vote')) {
    /**
     * اصلاحیه فقط با پذیرش صریح قواعد رأی‌گیریِ از پیش مصوب نسخهٔ مبنا به رأی محلی فرستاده می‌شود.
     * قواعد به‌صورت snapshot برای نسخهٔ تازه ثبت و منشأ آن در پرونده نگهداری می‌شود.
     */
    function community_law_submit_amendment_for_vote(
        mysqli $conn,
        int $amendment_id,
        int $community_id,
        int $user_id,
        bool $adopt_base_rules
    ): bool {
        if ($amendment_id<=0 || $community_id<=0 || $user_id<=0 || !$adopt_base_rules ||
            !community_law_amendment_tables_ready($conn)) return false;
        $started=false;
        try {
            if (!$conn->begin_transaction()) return false;
            $started=true;

            $member=$conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
            if (!$member) throw new RuntimeException('amendment submit membership prepare failed');
            $member->bind_param('ii',$community_id,$user_id);
            if (!$member->execute() || !$member->get_result()->fetch_row()) {
                $member->close();
                throw new RuntimeException('local membership required');
            }
            $member->close();

            $lock=$conn->prepare(
                "SELECT a.id,a.status,a.proposed_by,a.law_id,a.base_version_id,a.proposed_version_id,a.conformity_case_id,
                        a.community_id,l.status AS law_status,l.current_version_id,l.originating_community_id,
                        base.status AS base_status,base.version_number,
                        proposed.status AS proposed_status,
                        c.status AS conformity_status,c.parent_community_id,c.parent_law_version_id,
                        child.parent_community_id AS current_parent_community_id,
                        pl.status AS parent_law_status,pl.current_version_id AS parent_current_version_id,
                        pl.effective_at AS parent_effective_at,pl.expires_at AS parent_expires_at,
                        pl.repealed_at AS parent_repealed_at,pv.status AS parent_version_status
                 FROM community_law_amendment_cases a
                 JOIN community_laws l ON l.id=a.law_id
                 JOIN community_law_versions base ON base.id=a.base_version_id AND base.law_id=a.law_id
                 JOIN community_law_versions proposed ON proposed.id=a.proposed_version_id AND proposed.law_id=a.law_id
                 JOIN community_law_conformity_cases c ON c.id=a.conformity_case_id
                 JOIN communities child ON child.id=c.community_id
                 JOIN community_laws pl ON pl.id=c.parent_law_id
                 JOIN community_law_versions pv ON pv.id=c.parent_law_version_id AND pv.law_id=pl.id
                 WHERE a.id=? FOR UPDATE"
            );
            if (!$lock) throw new RuntimeException('amendment submit lock prepare failed');
            $lock->bind_param('i',$amendment_id);
            if (!$lock->execute()) throw new RuntimeException('amendment submit lock failed');
            $row=$lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$row || (int)$row['community_id']!==$community_id ||
                (int)$row['proposed_by']!==$user_id ||
                (int)$row['originating_community_id']!==$community_id ||
                (int)$row['current_parent_community_id']!==(int)$row['parent_community_id'] ||
                $row['status']!=='draft' || $row['law_status']!=='approved' ||
                (int)$row['current_version_id']!==(int)$row['base_version_id'] ||
                $row['base_status']!=='approved' || $row['proposed_status']!=='draft' ||
                $row['conformity_status']!=='amendment_required' ||
                $row['parent_law_status']!=='approved' || $row['parent_version_status']!=='approved' ||
                community_law_scope_match_checked($conn,(int)$row['parent_law_version_id'],$community_id)!==true ||
                (int)$row['parent_current_version_id']!==(int)$row['parent_law_version_id'] ||
                !empty($row['parent_repealed_at']) ||
                (!empty($row['parent_effective_at']) && strtotime($row['parent_effective_at'])>time()) ||
                (!empty($row['parent_expires_at']) && strtotime($row['parent_expires_at'])<=time())) {
                throw new RuntimeException('amendment submission state is no longer valid');
            }

            $source_rules=$conn->prepare(
                "SELECT jurisdiction_level,individual_quorum_percent,individual_approval_percent,
                        community_quorum_percent,community_approval_percent,
                        community_aggregate_quorum_percent,community_aggregate_approval_percent,
                        require_community_approval,individual_vote_basis,community_vote_basis
                 FROM community_law_voting_rules
                 WHERE law_version_id=? AND status='approved' LIMIT 1 FOR UPDATE"
            );
            if (!$source_rules) throw new RuntimeException('base voting rules prepare failed');
            $base_version_id=(int)$row['base_version_id'];
            $source_rules->bind_param('i',$base_version_id);
            if (!$source_rules->execute()) throw new RuntimeException('base voting rules lookup failed');
            $rules=$source_rules->get_result()->fetch_assoc();
            $source_rules->close();
            if (!$rules) throw new RuntimeException('approved base voting rules are required');

            $new_version_id=(int)$row['proposed_version_id'];
            $rule_insert=$conn->prepare(
                "INSERT INTO community_law_voting_rules
                    (law_version_id,jurisdiction_level,
                     individual_quorum_percent,individual_approval_percent,
                     community_quorum_percent,community_approval_percent,
                     community_aggregate_quorum_percent,community_aggregate_approval_percent,
                     require_community_approval,individual_vote_basis,community_vote_basis,
                     status,configured_by,configured_at,approved_at)
                 SELECT ?,jurisdiction_level,
                        individual_quorum_percent,individual_approval_percent,
                        community_quorum_percent,community_approval_percent,
                        community_aggregate_quorum_percent,community_aggregate_approval_percent,
                        require_community_approval,individual_vote_basis,community_vote_basis,
                        status,configured_by,configured_at,approved_at
                 FROM community_law_voting_rules
                 WHERE law_version_id=? AND status='approved'"
            );
            if (!$rule_insert) throw new RuntimeException('amendment voting rules snapshot prepare failed');
            $rule_insert->bind_param('ii',$new_version_id,$base_version_id);
            if (!$rule_insert->execute() || $rule_insert->affected_rows!==1) {
                throw new RuntimeException('amendment voting rules snapshot failed');
            }
            $rule_insert->close();

            // اصلاحیه نیز پیش از رأی‌گیری باید غربالگری حقوقی و بررسی صلاحیت مستقل را طی کند.
            $scope_ids=community_law_scope_community_ids($conn,$new_version_id);
            $parent_map=community_law_load_parent_map($conn);
            if (!$scope_ids || $parent_map===null || !community_law_parent_map_is_valid($parent_map) ||
                !isset($parent_map[$community_id])) {
                throw new RuntimeException('amendment legal review scope invalid');
            }
            $has_descendant_scope=false;
            foreach ($scope_ids as $scope_id) {
                if (!community_law_is_descendant_or_same($parent_map,(int)$scope_id,$community_id)) {
                    throw new RuntimeException('amendment scope exceeds local authority');
                }
                if ((int)$scope_id!==$community_id) $has_descendant_scope=true;
            }
            $jurisdiction=$has_descendant_scope?'parent_shared':'local';

            $screening_case=$conn->prepare(
                "INSERT INTO community_law_review_cases
                    (law_version_id,originating_community_id,status,reviewer_quorum,opened_by)
                 VALUES (?,?,'pending',3,?)"
            );
            if (!$screening_case) throw new RuntimeException('amendment screening case prepare failed');
            $screening_case->bind_param('iii',$new_version_id,$community_id,$user_id);
            if (!$screening_case->execute()) throw new RuntimeException('amendment screening case insert failed');
            $screening_case->close();

            $authority_case=$conn->prepare(
                "INSERT INTO community_law_authority_cases
                    (law_version_id,derived_jurisdiction_level,status,reviewer_quorum,opened_by)
                 VALUES (?,?,'pending',3,?)"
            );
            if (!$authority_case) throw new RuntimeException('amendment authority case prepare failed');
            $authority_case->bind_param('isi',$new_version_id,$jurisdiction,$user_id);
            if (!$authority_case->execute()) throw new RuntimeException('amendment authority case insert failed');
            $authority_case->close();

            $reference_insert=$conn->prepare(
                "INSERT IGNORE INTO community_law_authority_references
                    (law_version_id,reference_law_id,reference_law_version_id,reference_origin_community_id,relation_type)
                 VALUES (?,?,?,?,?)"
            );
            if (!$reference_insert) throw new RuntimeException('amendment authority reference prepare failed');
            $active_laws=$conn->query(
                "SELECT l.id AS law_id,l.originating_community_id,l.current_version_id
                 FROM community_laws l
                 JOIN community_law_versions v ON v.id=l.current_version_id
                 WHERE l.status='approved' AND v.status='approved'
                   AND (l.effective_at IS NULL OR l.effective_at<=NOW())
                   AND (l.expires_at IS NULL OR l.expires_at>NOW())
                   AND l.repealed_at IS NULL"
            );
            if (!$active_laws) throw new RuntimeException('amendment active authority laws lookup failed');
            while ($active_law=$active_laws->fetch_assoc()) {
                $reference_law_id=(int)$active_law['law_id'];
                if ($reference_law_id===(int)$row['law_id']) continue;
                $reference_version=(int)$active_law['current_version_id'];
                $reference_origin=(int)$active_law['originating_community_id'];
                $applies=false;
                foreach ($scope_ids as $scope_id) {
                    $match=community_law_scope_match_checked($conn,$reference_version,(int)$scope_id);
                    if ($match===null) { $active_laws->free(); throw new RuntimeException('amendment authority scope check failed'); }
                    if ($match) { $applies=true; break; }
                }
                if (!$applies) continue;
                $higher=false;
                foreach ($scope_ids as $scope_id) {
                    if ((int)$scope_id!==$reference_origin &&
                        community_law_is_descendant_or_same($parent_map,(int)$scope_id,$reference_origin)) {
                        $higher=true; break;
                    }
                }
                if ($higher) $relation='higher';
                elseif ($reference_origin===$community_id) $relation='same_level';
                elseif (community_law_is_descendant_or_same($parent_map,$reference_origin,$community_id)) $relation='lower_level';
                else $relation='peer';
                $reference_insert->bind_param('iiiis',$new_version_id,$reference_law_id,$reference_version,$reference_origin,$relation);
                if (!$reference_insert->execute()) { $active_laws->free(); throw new RuntimeException('amendment authority reference insert failed'); }
            }
            $active_laws->free();
            $reference_insert->close();

            $electorate_insert=$conn->prepare(
                "INSERT IGNORE INTO community_law_review_electorate (law_version_id,user_id)
                 SELECT ?,user_id FROM community_members WHERE community_id=? AND user_id<>?"
            );
            if (!$electorate_insert) throw new RuntimeException('amendment review electorate prepare failed');
            foreach ($scope_ids as $scope_id) {
                $scope_id=(int)$scope_id;
                $electorate_insert->bind_param('iii',$new_version_id,$scope_id,$user_id);
                if (!$electorate_insert->execute()) throw new RuntimeException('amendment review electorate snapshot failed');
            }
            $electorate_insert->close();

            $capacity=$conn->prepare('SELECT COUNT(*) AS total FROM community_law_review_electorate WHERE law_version_id=?');
            if (!$capacity) throw new RuntimeException('amendment reviewer capacity prepare failed');
            $capacity->bind_param('i',$new_version_id);
            if (!$capacity->execute()) throw new RuntimeException('amendment reviewer capacity lookup failed');
            $reviewer_count=(int)$capacity->get_result()->fetch_assoc()['total'];
            $capacity->close();
            if ($reviewer_count<3) throw new RuntimeException('amendment requires at least three independent reviewers');

            $reference_count=$conn->prepare('SELECT COUNT(*) AS total FROM community_law_authority_references WHERE law_version_id=?');
            if (!$reference_count) throw new RuntimeException('amendment authority reference count prepare failed');
            $reference_count->bind_param('i',$new_version_id);
            if (!$reference_count->execute()) throw new RuntimeException('amendment authority reference count failed');
            $authority_reference_count=(int)$reference_count->get_result()->fetch_assoc()['total'];
            $reference_count->close();
            if ($authority_reference_count<1) throw new RuntimeException('amendment has no active higher authority reference');

            if (!community_law_record_history(
                $conn,(int)$row['law_id'],$new_version_id,$community_id,$user_id,
                'amendment_legal_reviews_opened',
                'غربالگری حقوقی و بررسی صلاحیت مستقل برای نسخه اصلاحیه آغاز شد؛ تا تکمیل هر دو مرحله رأی‌گیری محلی آغاز نمی‌شود.'
            )) throw new RuntimeException('amendment legal review history failed');

            $version_update=$conn->prepare(
                "UPDATE community_law_versions SET status='proposed'
                 WHERE id=? AND status='draft'"
            );
            if (!$version_update) throw new RuntimeException('amendment version submission prepare failed');
            $version_update->bind_param('i',$new_version_id);
            if (!$version_update->execute() || $version_update->affected_rows!==1) {
                throw new RuntimeException('amendment version submission failed');
            }
            $version_update->close();

            $case_update=$conn->prepare(
                "UPDATE community_law_amendment_cases
                 SET status='submitted',voting_rules_source_version_id=?,
                     rules_adopted_by=?,rules_adopted_at=NOW(),submitted_at=NOW()
                 WHERE id=? AND status='draft'"
            );
            if (!$case_update) throw new RuntimeException('amendment case submission prepare failed');
            $case_update->bind_param('iii',$base_version_id,$user_id,$amendment_id);
            if (!$case_update->execute() || $case_update->affected_rows!==1) {
                throw new RuntimeException('amendment case submission failed');
            }
            $case_update->close();

            $conformity_update=$conn->prepare(
                "UPDATE community_law_conformity_cases SET status='amendment_in_progress'
                 WHERE id=? AND status='amendment_required'"
            );
            if (!$conformity_update) throw new RuntimeException('conformity amendment state prepare failed');
            $conformity_case_id=(int)$row['conformity_case_id'];
            $conformity_update->bind_param('i',$conformity_case_id);
            if (!$conformity_update->execute() || $conformity_update->affected_rows!==1) {
                throw new RuntimeException('conformity amendment state update failed');
            }
            $conformity_update->close();

            $details=json_encode([
                'base_version_id'=>$base_version_id,'proposed_version_id'=>$new_version_id,
                'voting_rules_source_version_id'=>$base_version_id,
                'rules_explicitly_adopted'=>true,'rules_snapshot_created'=>true,
                'current_approved_law_unchanged'=>true
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $event=$conn->prepare(
                "INSERT INTO community_law_amendment_events (case_id,actor_user_id,event_type,details)
                 VALUES (?,?,'amendment_submitted',?)"
            );
            if (!$event) throw new RuntimeException('amendment submission event prepare failed');
            $event->bind_param('iis',$amendment_id,$user_id,$details);
            if (!$event->execute()) throw new RuntimeException('amendment submission event failed');
            $event->close();

            if (!community_law_record_history(
                $conn,(int)$row['law_id'],$new_version_id,$community_id,$user_id,
                'amendment_submitted',
                'اصلاحیه برای رأی محلی ارسال شد؛ قواعد رأی‌گیریِ مصوب نسخهٔ مبنا با پذیرش صریح ثبت‌کننده snapshot شد و قانون جاری تغییر نکرد.'
            )) throw new RuntimeException('amendment submission history failed');

            if (!$conn->commit()) throw new RuntimeException('amendment submission commit failed');
            $started=false;
            return true;
        } catch (Throwable $e) {
            if ($started) $conn->rollback();
            error_log('[Dolate Eshgh amendment submission] '.$e->getMessage());
            return false;
        }
    }
}


if (!function_exists('community_law_submit_amendment_parent_review')) {
    /**
     * ارزیابی مستقل جامعهٔ مادر؛ فقط نتیجه و ارجاع مواد ثبت می‌شود و متن قانون محلی قابل ویرایش نیست.
     * پس از دو ارزیابی مستقل، یا به بررسی نهایی می‌رود یا برای اصلاح دوباره به جامعهٔ محلی بازمی‌گردد.
     *
     * @param array<int,string> $conflicting_references
     */
    function community_law_submit_amendment_parent_review(
        mysqli $conn,
        int $amendment_id,
        int $parent_community_id,
        int $user_id,
        string $finding,
        array $conflicting_references,
        string $reasoning,
        ?string $evidence=null
    ): bool {
        $reasoning=trim($reasoning);
        $evidence=$evidence!==null?trim($evidence):null;
        if ($amendment_id<=0 || $parent_community_id<=0 || $user_id<=0 ||
            !in_array($finding,['compatible','conflict_remains'],true) ||
            $reasoning==='' || mb_strlen($reasoning)>30000 ||
            ($evidence!==null && mb_strlen($evidence)>30000) ||
            count($conflicting_references)>20 || !community_law_amendment_tables_ready($conn)) return false;

        $refs=[];
        foreach ($conflicting_references as $reference) {
            $reference=trim((string)$reference);
            if ($reference==='' || mb_strlen($reference)>120 || isset($refs[$reference])) return false;
            $refs[$reference]=true;
        }
        if (($finding==='compatible' && count($refs)!==0) ||
            ($finding==='conflict_remains' && count($refs)===0)) return false;

        $started=false;
        try {
            if (!$conn->begin_transaction()) return false;
            $started=true;
            $member=$conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
            if (!$member) throw new RuntimeException('parent review membership prepare failed');
            $member->bind_param('ii',$parent_community_id,$user_id);
            if (!$member->execute() || !$member->get_result()->fetch_row()) {
                $member->close();
                throw new RuntimeException('parent community membership required');
            }
            $member->close();

            $lock=$conn->prepare(
                "SELECT a.id,a.status,a.proposed_by,a.law_id,a.proposed_version_id,a.conformity_case_id,
                        a.base_version_id,c.parent_community_id,c.parent_law_version_id AS parent_version_id,c.community_id,
                        child.parent_community_id AS current_parent_community_id,
                        l.status AS local_law_status,l.current_version_id,
                        base.status AS base_status,proposed.status AS proposed_status,
                        pl.status AS parent_law_status,pl.current_version_id AS parent_current_version_id,
                        pv.status AS parent_version_status,pl.effective_at,pl.expires_at,pl.repealed_at,
                        EXISTS (
                            SELECT 1 FROM community_law_conformity_conflict_reviews prior
                            JOIN community_law_conformity_conflicts conflict ON conflict.id=prior.conflict_id
                            WHERE conflict.case_id=a.conformity_case_id AND prior.reviewer_user_id=?
                        ) AS prior_conformity_reviewer,
                        EXISTS (SELECT 1 FROM community_law_review_assessments r WHERE r.law_version_id=a.proposed_version_id AND r.reviewer_user_id=?) AS prior_legal_reviewer,
                        EXISTS (SELECT 1 FROM community_law_authority_assessments ar WHERE ar.law_version_id=a.proposed_version_id AND ar.reviewer_user_id=?) AS prior_authority_reviewer,
                        EXISTS (SELECT 1 FROM community_law_votes cv WHERE cv.law_version_id=a.proposed_version_id AND cv.voter_user_id=?) AS prior_community_voter,
                        EXISTS (SELECT 1 FROM community_law_direct_votes dv WHERE dv.law_version_id=a.proposed_version_id AND dv.voter_user_id=?) AS prior_direct_voter
                 FROM community_law_amendment_cases a
                 JOIN community_law_conformity_cases c ON c.id=a.conformity_case_id
                 JOIN communities child ON child.id=c.community_id
                 JOIN community_laws l ON l.id=a.law_id
                 JOIN community_law_versions base ON base.id=a.base_version_id AND base.law_id=a.law_id
                 JOIN community_law_versions proposed ON proposed.id=a.proposed_version_id AND proposed.law_id=a.law_id
                 JOIN community_laws pl ON pl.id=c.parent_law_id
                 JOIN community_law_versions pv ON pv.id=c.parent_law_version_id AND pv.law_id=pl.id
                 WHERE a.id=? FOR UPDATE"
            );
            if (!$lock) throw new RuntimeException('parent review lock prepare failed');
            $lock->bind_param('iiiiii',$user_id,$user_id,$user_id,$user_id,$user_id,$amendment_id);
            if (!$lock->execute()) throw new RuntimeException('parent review lock failed');
            $row=$lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$row || $row['status']!=='parent_review' ||
                (int)$row['parent_community_id']!==$parent_community_id ||
                (int)$row['current_parent_community_id']!==(int)$row['parent_community_id'] ||
                (int)$row['proposed_by']===$user_id || (int)$row['prior_conformity_reviewer']===1 ||
                (int)$row['prior_legal_reviewer']===1 || (int)$row['prior_authority_reviewer']===1 ||
                (int)$row['prior_community_voter']===1 || (int)$row['prior_direct_voter']===1 ||
                $row['local_law_status']!=='approved' ||
                (int)$row['current_version_id']!==(int)$row['base_version_id'] ||
                $row['base_status']!=='approved' || $row['proposed_status']!=='voting' ||
                $row['parent_law_status']!=='approved' || $row['parent_version_status']!=='approved' ||
                community_law_scope_match_checked($conn,(int)$row['parent_version_id'],(int)$row['community_id'])!==true ||
                (int)$row['parent_current_version_id']!==(int)$row['parent_version_id'] ||
                !empty($row['repealed_at']) ||
                (!empty($row['effective_at']) && strtotime($row['effective_at'])>time()) ||
                (!empty($row['expires_at']) && strtotime($row['expires_at'])<=time())) {
                throw new RuntimeException('amendment parent review state is invalid');
            }

            $article_stmt=$conn->prepare('SELECT article_reference FROM community_law_amendment_articles WHERE case_id=?');
            if (!$article_stmt) throw new RuntimeException('parent review article lookup prepare failed');
            $article_stmt->bind_param('i',$amendment_id);
            if (!$article_stmt->execute()) throw new RuntimeException('parent review article lookup failed');
            $allowed=[];
            $article_rows=$article_stmt->get_result();
            while ($article=$article_rows->fetch_assoc()) $allowed[(string)$article['article_reference']]=true;
            $article_stmt->close();
            foreach ($refs as $reference=>$_) {
                if (!isset($allowed[$reference])) throw new RuntimeException('parent review reference must identify an amended article');
            }

            $references_json=json_encode(array_keys($refs),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $review=$conn->prepare(
                'INSERT INTO community_law_amendment_parent_reviews
                    (case_id,reviewer_user_id,parent_community_id,finding,conflicting_article_references,reasoning,evidence)
                 VALUES (?,?,?,?,?,?,?)'
            );
            if (!$review) throw new RuntimeException('parent review insert prepare failed');
            $review->bind_param('iiissss',$amendment_id,$user_id,$parent_community_id,$finding,$references_json,$reasoning,$evidence);
            if (!$review->execute()) throw new RuntimeException('parent review insert failed');
            $review->close();

            $count_stmt=$conn->prepare(
                'SELECT COUNT(*) AS total,SUM(CASE WHEN finding=\'conflict_remains\' THEN 1 ELSE 0 END) AS conflicts
                 FROM community_law_amendment_parent_reviews WHERE case_id=? AND parent_community_id=?'
            );
            if (!$count_stmt) throw new RuntimeException('parent review count prepare failed');
            $count_stmt->bind_param('ii',$amendment_id,$parent_community_id);
            if (!$count_stmt->execute()) throw new RuntimeException('parent review count failed');
            $totals=$count_stmt->get_result()->fetch_assoc();
            $count_stmt->close();

            $next_status='parent_review';
            $conformity_next='amendment_in_progress';
            if ((int)$totals['total']>=2) {
                if ((int)$totals['conflicts']>0) {
                    $next_status='revision_required';
                    $conformity_next='amendment_required';
                } else {
                    $next_status='parent_review_approved';
                }
                $decision_note=$next_status==='revision_required'
                    ? 'دو بررسی مستقل جامعهٔ مادر نشان دادند که دست‌کم یک مادهٔ اصلاح‌شده هنوز نیازمند رفع تعارض است؛ جامعهٔ محلی متن اصلاحی تازه را خود تهیه می‌کند.'
                    : 'دو بررسی مستقل جامعهٔ مادر سازگاری مواد اصلاح‌شده را تأیید کردند؛ پرونده برای بررسی نهایی مستقل آماده است.';
                $case_update=$conn->prepare("UPDATE community_law_amendment_cases SET status=?,decided_at=NOW(),decision_note=? WHERE id=? AND status='parent_review'");
                if (!$case_update) throw new RuntimeException('parent review case update prepare failed');
                $case_update->bind_param('ssi',$next_status,$decision_note,$amendment_id);
                if (!$case_update->execute() || $case_update->affected_rows!==1) throw new RuntimeException('parent review case update failed');
                $case_update->close();
                if ($conformity_next==='amendment_required') {
                    $proposed_version_id=(int)$row['proposed_version_id'];
                    $close_version=$conn->prepare("UPDATE community_law_versions SET status='rejected' WHERE id=? AND status='voting'");
                    if (!$close_version) throw new RuntimeException('returned amendment version close prepare failed');
                    $close_version->bind_param('i',$proposed_version_id);
                    if (!$close_version->execute() || $close_version->affected_rows!==1) throw new RuntimeException('returned amendment version close failed');
                    $close_version->close();
                    $conformity_id=(int)$row['conformity_case_id'];
                    $conformity_update=$conn->prepare("UPDATE community_law_conformity_cases SET status='amendment_required' WHERE id=? AND status='amendment_in_progress'");
                    if (!$conformity_update) throw new RuntimeException('parent review conformity update prepare failed');
                    $conformity_update->bind_param('i',$conformity_id);
                    if (!$conformity_update->execute() || $conformity_update->affected_rows!==1) throw new RuntimeException('parent review conformity update failed');
                    $conformity_update->close();
                }
            }

            $details=json_encode([
                'finding'=>$finding,'conflicting_article_references'=>array_keys($refs),
                'review_count'=>(int)$totals['total'],'conflict_reviews'=>(int)$totals['conflicts'],
                'resulting_amendment_status'=>$next_status,
                'parent_can_edit_local_text'=>false
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $event=$conn->prepare(
                "INSERT INTO community_law_amendment_events (case_id,actor_user_id,event_type,details)
                 VALUES (?,?,'parent_compatibility_reviewed',?)"
            );
            if (!$event) throw new RuntimeException('parent review event prepare failed');
            $event->bind_param('iis',$amendment_id,$user_id,$details);
            if (!$event->execute()) throw new RuntimeException('parent review event failed');
            $event->close();

            if (!community_law_record_history(
                $conn,(int)$row['law_id'],(int)$row['proposed_version_id'],$parent_community_id,$user_id,
                'amendment_parent_compatibility_reviewed',
                'بررسی مستقل جامعهٔ مادر ثبت شد؛ هیچ تغییری در متن پیشنهادی قانون محلی ایجاد نشد. نتیجه: '.$next_status
            )) throw new RuntimeException('parent review history failed');

            if (!$conn->commit()) throw new RuntimeException('parent review commit failed');
            $started=false;
            return true;
        } catch (Throwable $e) {
            if ($started) $conn->rollback();
            error_log('[Dolate Eshgh amendment parent review] '.$e->getMessage());
            return false;
        }
    }
}


if (!function_exists('community_law_enact_amendment')) {
    /**
     * ابلاغ اتمی نسخه اصلاحی پس از رأی محلی، بررسی انطباق، بررسی نهایی و پایان اعتراض‌ها.
     * قانون جاری تا لحظهٔ commit به نسخهٔ پایه اشاره می‌کند.
     */
    function community_law_enact_amendment(
        mysqli $conn,
        int $law_id,
        int $version_id,
        int $community_id,
        int $user_id
    ): bool {
        if ($law_id<=0 || $version_id<=0 || $community_id<=0 || $user_id<=0 ||
            !community_law_amendment_tables_ready($conn)) return false;
        $started=false;
        try {
            if (!$conn->begin_transaction()) return false;
            $started=true;
            $hierarchy=$conn->query('SELECT id,parent_community_id FROM communities ORDER BY id FOR UPDATE');
            if (!$hierarchy) throw new RuntimeException('amendment enactment hierarchy lock failed');
            $hierarchy->free();

            $member=$conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
            if (!$member) throw new RuntimeException('amendment enactment membership prepare failed');
            $member->bind_param('ii',$community_id,$user_id);
            if (!$member->execute() || !$member->get_result()->fetch_row()) {
                $member->close();
                throw new RuntimeException('amendment enactment membership required');
            }
            $member->close();

            $lock=$conn->prepare(
                "SELECT l.id AS law_id,l.status AS law_status,l.current_version_id,l.originating_community_id,
                        v.status AS version_status,fc.status AS final_status,fc.appeal_deadline,fc.reviewer_quorum,
                        rc.status AS screening_status,ac.status AS authority_status,fr.final_status AS vote_result,
                        a.id AS amendment_case_id,a.status AS amendment_status,a.base_version_id,a.conformity_case_id,
                        cc.parent_community_id,child.parent_community_id AS current_parent_community_id,
                        cc.parent_law_id,cc.parent_law_version_id,
                        base.status AS base_version_status
                 FROM community_law_versions v
                 JOIN community_laws l ON l.id=v.law_id
                 JOIN community_law_final_review_cases fc ON fc.law_version_id=v.id
                 JOIN community_law_review_cases rc ON rc.law_version_id=v.id
                 JOIN community_law_authority_cases ac ON ac.law_version_id=v.id
                 JOIN community_law_final_results fr ON fr.law_version_id=v.id
                 JOIN community_law_amendment_cases a ON a.proposed_version_id=v.id
                 JOIN community_law_versions base ON base.id=a.base_version_id AND base.law_id=l.id
                 JOIN community_law_conformity_cases cc ON cc.id=a.conformity_case_id
                 JOIN communities child ON child.id=cc.community_id
                 WHERE l.id=? AND v.id=? FOR UPDATE"
            );
            if (!$lock) throw new RuntimeException('amendment enactment lock prepare failed');
            $lock->bind_param('ii',$law_id,$version_id);
            if (!$lock->execute()) throw new RuntimeException('amendment enactment lock failed');
            $row=$lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$row || (int)$row['originating_community_id']!==$community_id ||
                $row['law_status']!=='approved' || $row['version_status']!=='appeal_period' ||
                (int)$row['current_parent_community_id']!==(int)$row['parent_community_id'] ||
                community_law_scope_match_checked($conn,(int)$row['parent_law_version_id'],$community_id)!==true ||
                $row['amendment_status']!=='parent_review_approved' ||
                (int)$row['current_version_id']!==(int)$row['base_version_id'] ||
                $row['base_version_status']!=='approved' ||
                $row['screening_status']!=='screening_clear' ||
                $row['authority_status']!=='confirmed' || $row['vote_result']!=='vote_passed' ||
                !in_array($row['final_status'],['appeal_period','appeal_hold'],true) ||
                empty($row['appeal_deadline']) || strtotime($row['appeal_deadline'])>time()) {
                throw new RuntimeException('amendment enactment state is invalid');
            }

            $assessment_check=$conn->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(a.verdict<>'confirm') AS non_confirmed,
                        SUM(a.charter_and_rights<>'pass' OR a.non_discrimination<>'pass' OR
                            a.power_concentration<>'pass' OR a.human_and_social_impact<>'pass' OR
                            a.environmental_impact<>'pass' OR a.economic_and_resource_impact<>'pass' OR
                            a.future_generations_and_other_life<>'pass' OR a.implementation_and_accountability<>'pass') AS unresolved_dimensions,
                        SUM(NOT EXISTS (
                            SELECT 1 FROM community_law_final_review_electorate e
                            WHERE e.law_version_id=a.law_version_id AND e.user_id=a.reviewer_user_id
                        )) AS ineligible_reviewers,
                        SUM(EXISTS (
                            SELECT 1 FROM community_law_review_assessments r
                            WHERE r.law_version_id=a.law_version_id AND r.reviewer_user_id=a.reviewer_user_id
                        ) OR EXISTS (
                            SELECT 1 FROM community_law_authority_assessments ar
                            WHERE ar.law_version_id=a.law_version_id AND ar.reviewer_user_id=a.reviewer_user_id
                        )) AS prior_stage_reviewers,
                        SUM(a.reviewer_user_id=v.proposed_by) AS proposer_assessments
                 FROM community_law_final_review_assessments a
                 JOIN community_law_versions v ON v.id=a.law_version_id
                 WHERE a.law_version_id=?"
            );
            if (!$assessment_check) throw new RuntimeException('amendment final assessment check prepare failed');
            $assessment_check->bind_param('i',$version_id);
            if (!$assessment_check->execute()) throw new RuntimeException('amendment final assessment check failed');
            $assessment_totals=$assessment_check->get_result()->fetch_assoc();
            $assessment_check->close();
            if ((int)$assessment_totals['total']<(int)$row['reviewer_quorum'] ||
                (int)$assessment_totals['non_confirmed']>0 ||
                (int)$assessment_totals['unresolved_dimensions']>0 ||
                (int)$assessment_totals['ineligible_reviewers']>0 ||
                (int)$assessment_totals['prior_stage_reviewers']>0 ||
                (int)$assessment_totals['proposer_assessments']>0) {
                throw new RuntimeException('amendment final review quorum or findings are incomplete');
            }

            $appeals=$conn->prepare(
                "SELECT SUM(status IN ('submitted','under_review')) AS unresolved,
                        SUM(status='upheld') AS upheld
                 FROM community_law_appeals WHERE law_version_id=?"
            );
            if (!$appeals) throw new RuntimeException('amendment appeal check prepare failed');
            $appeals->bind_param('i',$version_id);
            if (!$appeals->execute()) throw new RuntimeException('amendment appeal check failed');
            $appeal_totals=$appeals->get_result()->fetch_assoc();
            $appeals->close();
            if ((int)$appeal_totals['unresolved']>0 || (int)$appeal_totals['upheld']>0) {
                throw new RuntimeException('amendment appeals are unresolved');
            }

            if (community_law_charter_snapshot_is_current($conn,$version_id)!==true) {
                throw new RuntimeException('amendment charter snapshot changed');
            }

            $scope_ids=community_law_scope_community_ids($conn,$version_id);
            if (!$scope_ids || !in_array($community_id,$scope_ids,true)) {
                throw new RuntimeException('amendment scope is unresolved');
            }
            $reference_stmt=$conn->prepare(
                "SELECT r.reference_law_id,r.reference_law_version_id,r.scope_fingerprint,
                        l.current_version_id,l.status AS law_status,l.effective_at,l.expires_at,l.repealed_at,
                        v.status AS version_status
                 FROM community_law_final_review_references r
                 JOIN community_laws l ON l.id=r.reference_law_id
                 JOIN community_law_versions v ON v.id=r.reference_law_version_id
                 WHERE r.law_version_id=?"
            );
            if (!$reference_stmt) throw new RuntimeException('amendment reference check prepare failed');
            $reference_stmt->bind_param('i',$version_id);
            if (!$reference_stmt->execute()) throw new RuntimeException('amendment reference check failed');
            $reference_rows=$reference_stmt->get_result();
            $captured=[];
            while ($reference=$reference_rows->fetch_assoc()) {
                if ((int)$reference['current_version_id']!==(int)$reference['reference_law_version_id'] ||
                    $reference['law_status']!=='approved' || $reference['version_status']!=='approved' ||
                    !empty($reference['repealed_at']) ||
                    (!empty($reference['effective_at']) && strtotime($reference['effective_at'])>time()) ||
                    (!empty($reference['expires_at']) && strtotime($reference['expires_at'])<=time())) {
                    $reference_stmt->close();
                    throw new RuntimeException('amendment reference law changed');
                }
                $scope_stmt=$conn->prepare('SELECT scope_community_id,include_descendants FROM community_law_scopes WHERE law_version_id=? ORDER BY scope_community_id');
                if (!$scope_stmt) { $reference_stmt->close(); throw new RuntimeException('amendment reference scope prepare failed'); }
                $reference_version=(int)$reference['reference_law_version_id'];
                $scope_stmt->bind_param('i',$reference_version);
                if (!$scope_stmt->execute()) { $scope_stmt->close(); $reference_stmt->close(); throw new RuntimeException('amendment reference scope failed'); }
                $reference_scopes=[];
                $scope_result=$scope_stmt->get_result();
                while ($scope_row=$scope_result->fetch_assoc()) $reference_scopes[]=['scope_community_id'=>(int)$scope_row['scope_community_id'],'include_descendants'=>(int)$scope_row['include_descendants']];
                $scope_stmt->close();
                $actual_scope_fingerprint=hash('sha256',json_encode($reference_scopes));
                if (!$reference_scopes || !hash_equals((string)$reference['scope_fingerprint'],$actual_scope_fingerprint)) {
                    error_log('[Dolate Eshgh amendment enactment scope fingerprint] ref_version='.$reference_version.
                        ' stored='.(string)$reference['scope_fingerprint'].' actual='.$actual_scope_fingerprint.
                        ' scopes='.json_encode($reference_scopes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    $reference_stmt->close();
                    throw new RuntimeException('amendment reference scope changed');
                }
                $captured[(int)$reference['reference_law_id'].':'.(int)$reference['reference_law_version_id']]=true;
            }
            $reference_stmt->close();

            $parent_map=community_law_load_parent_map($conn);
            if ($parent_map===null || !community_law_parent_map_is_valid($parent_map)) {
                throw new RuntimeException('amendment enactment hierarchy invalid');
            }
            $active_laws=$conn->query(
                "SELECT l.id AS law_id,l.current_version_id
                 FROM community_laws l
                 JOIN community_law_versions v ON v.id=l.current_version_id
                 WHERE l.status='approved' AND v.status='approved'
                   AND (l.effective_at IS NULL OR l.effective_at<=NOW())
                   AND (l.expires_at IS NULL OR l.expires_at>NOW())
                   AND l.repealed_at IS NULL"
            );
            if (!$active_laws) throw new RuntimeException('amendment active law rescan failed');
            while ($active=$active_laws->fetch_assoc()) {
                $active_law_id=(int)$active['law_id'];
                if ($active_law_id===$law_id) continue;
                $active_version=(int)$active['current_version_id'];
                $applies=false;
                foreach ($scope_ids as $scope_id) {
                    $matches=community_law_scope_match_checked($conn,$active_version,(int)$scope_id);
                    if ($matches===null) { $active_laws->free(); throw new RuntimeException('amendment active law scope check failed'); }
                    if ($matches) { $applies=true; break; }
                }
                if ($applies && !isset($captured[$active_law_id.':'.$active_version])) {
                    $active_laws->free();
                    throw new RuntimeException('amendment active law reference missing');
                }
            }
            $active_laws->free();

            $base_version_id=(int)$row['base_version_id'];
            $implementation_count=$conn->prepare('SELECT COUNT(*) AS total FROM community_law_implementations WHERE law_version_id=?');
            if (!$implementation_count) throw new RuntimeException('amendment implementation count prepare failed');
            $implementation_count->bind_param('i',$base_version_id);
            if (!$implementation_count->execute()) throw new RuntimeException('amendment implementation count failed');
            $base_implementation_count=(int)$implementation_count->get_result()->fetch_assoc()['total'];
            $implementation_count->close();
            if ($base_implementation_count!==count($scope_ids)) {
                throw new RuntimeException('amendment implementation snapshot missing');
            }

            $copy=$conn->prepare(
                "INSERT INTO community_law_implementations
                    (law_version_id,community_id,responsible_user_id,local_plan,status,
                     started_at,target_date,completed_at,progress_report)
                 SELECT ?,community_id,responsible_user_id,local_plan,status,
                        started_at,target_date,completed_at,progress_report
                 FROM community_law_implementations WHERE law_version_id=?"
            );
            if (!$copy) throw new RuntimeException('amendment implementation copy prepare failed');
            $copy->bind_param('ii',$version_id,$base_version_id);
            if (!$copy->execute() || $copy->affected_rows!==$base_implementation_count) {
                throw new RuntimeException('amendment implementation state copy failed');
            }
            $copy->close();

            $law_update=$conn->prepare(
                "UPDATE community_laws SET current_version_id=?
                 WHERE id=? AND status='approved' AND current_version_id=?"
            );
            if (!$law_update) throw new RuntimeException('amendment law activation prepare failed');
            $law_update->bind_param('iii',$version_id,$law_id,$base_version_id);
            if (!$law_update->execute() || $law_update->affected_rows!==1) throw new RuntimeException('amendment law activation failed');
            $law_update->close();

            $base_update=$conn->prepare("UPDATE community_law_versions SET status='superseded' WHERE id=? AND law_id=? AND status='approved'");
            if (!$base_update) throw new RuntimeException('amendment base supersede prepare failed');
            $base_update->bind_param('ii',$base_version_id,$law_id);
            if (!$base_update->execute() || $base_update->affected_rows!==1) throw new RuntimeException('amendment base supersede failed');
            $base_update->close();

            $version_update=$conn->prepare("UPDATE community_law_versions SET status='approved',approved_at=NOW() WHERE id=? AND status='appeal_period'");
            if (!$version_update) throw new RuntimeException('amendment version activation prepare failed');
            $version_update->bind_param('i',$version_id);
            if (!$version_update->execute() || $version_update->affected_rows!==1) throw new RuntimeException('amendment version activation failed');
            $version_update->close();

            $amendment_update=$conn->prepare("UPDATE community_law_amendment_cases SET status='approved',decided_at=NOW(),decision_note='نسخه اصلاحی پس از طی همه مراحل معتبر، ابلاغ و فعال شد.' WHERE id=? AND status='parent_review_approved'");
            if (!$amendment_update) throw new RuntimeException('amendment case activation prepare failed');
            $amendment_id=(int)$row['amendment_case_id'];
            $amendment_update->bind_param('i',$amendment_id);
            if (!$amendment_update->execute() || $amendment_update->affected_rows!==1) throw new RuntimeException('amendment case activation failed');
            $amendment_update->close();

            $conformity_update=$conn->prepare("UPDATE community_law_conformity_cases SET status='resolved',resolved_at=NOW() WHERE id=? AND status='amendment_in_progress'");
            if (!$conformity_update) throw new RuntimeException('amendment conformity resolution prepare failed');
            $conformity_id=(int)$row['conformity_case_id'];
            $conformity_update->bind_param('i',$conformity_id);
            if (!$conformity_update->execute() || $conformity_update->affected_rows!==1) throw new RuntimeException('amendment conformity resolution failed');
            $conformity_update->close();

            $final_case=$conn->prepare("UPDATE community_law_final_review_cases SET status='enacted',enacted_at=NOW() WHERE law_version_id=? AND status IN ('appeal_period','appeal_hold')");
            if (!$final_case) throw new RuntimeException('amendment final review close prepare failed');
            $final_case->bind_param('i',$version_id);
            if (!$final_case->execute() || $final_case->affected_rows!==1) throw new RuntimeException('amendment final review close failed');
            $final_case->close();

            $details=json_encode([
                'base_version_id'=>$base_version_id,'enacted_version_id'=>$version_id,
                'law_current_version_updated'=>true,'previous_version_preserved_as_superseded'=>true,
                'implementation_progress_preserved'=>true
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $event=$conn->prepare("INSERT INTO community_law_amendment_events (case_id,actor_user_id,event_type,details) VALUES (?,?,'amendment_enacted',?)");
            if (!$event) throw new RuntimeException('amendment enactment event prepare failed');
            $event->bind_param('iis',$amendment_id,$user_id,$details);
            if (!$event->execute()) throw new RuntimeException('amendment enactment event failed');
            $event->close();

            if (!community_law_record_history(
                $conn,$law_id,$version_id,$community_id,$user_id,'law_amendment_enacted',
                'نسخه اصلاحی پس از رأی محلی، بررسی انطباق جامعه مادر، بررسی نهایی مستقل، پایان مهلت اعتراض و رسیدگی به اعتراض‌ها به‌صورت اتمی جایگزین نسخه قبلی شد.'
            )) throw new RuntimeException('amendment enactment history failed');

            // ابلاغ نسخهٔ جدید به تمام جوامع مشمول و جامعهٔ مبدأ در همان تراکنش فعال‌سازی.
            $notice_communities=array_values(array_unique(array_map('intval',array_merge($scope_ids,[$community_id]))));
            $notice_text='نسخهٔ جدید قانون پس از طی فرایند معتبر تصویب و لازم‌الاجرا شد؛ سوابق و گزارش‌های اجرای نسخهٔ پیشین حفظ شده‌اند.';
            $notice_details=json_encode([
                'previous_version_id'=>$base_version_id,
                'current_version_id'=>$version_id,
                'implementation_state_copied'=>true
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (!is_string($notice_details)) throw new RuntimeException('amendment notice details encoding failed');
            $notice=$conn->prepare(
                "INSERT INTO community_law_version_notices
                    (law_id,law_version_id,previous_version_id,community_id,actor_user_id,event_type,notice_text,details)
                 VALUES (?,?,?,?,?,'version_activated',?,?)"
            );
            if (!$notice) throw new RuntimeException('amendment version notice prepare failed');
            foreach ($notice_communities as $notice_community_id) {
                $notice->bind_param('iiiiiss',$law_id,$version_id,$base_version_id,$notice_community_id,$user_id,$notice_text,$notice_details);
                if (!$notice->execute() || $notice->affected_rows!==1) {
                    $notice->close();
                    throw new RuntimeException('amendment version notice insert failed');
                }
            }
            $notice->close();

            if (!$conn->commit()) throw new RuntimeException('amendment enactment commit failed');
            $started=false;
            return true;
        } catch (Throwable $e) {
            if ($started) $conn->rollback();
            error_log('[Dolate Eshgh amendment enactment] '.$e->getMessage());
            return false;
        }
    }
}


if (!function_exists('community_law_open_amendment_final_review')) {
    /**
     * بازکردن بررسی نهایی اصلاحیه با snapshot مستقل ارزیابان، قوانین مرجع و منشور.
     * قانون مادر و نسخه جاری محلی در این مرحله تغییر نمی‌کنند.
     */
    function community_law_open_amendment_final_review(
        mysqli $conn,
        int $law_id,
        int $version_id,
        int $community_id,
        int $user_id
    ): bool {
        if ($law_id<=0 || $version_id<=0 || $community_id<=0 || $user_id<=0 ||
            !community_law_amendment_tables_ready($conn)) return false;
        $started=false;
        try {
            if (!$conn->begin_transaction()) return false;
            $started=true;
            $hierarchy=$conn->query('SELECT id,parent_community_id FROM communities ORDER BY id FOR UPDATE');
            if (!$hierarchy) throw new RuntimeException('amendment final review hierarchy lock failed');
            $hierarchy->free();

            $member=$conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
            if (!$member) throw new RuntimeException('amendment final review membership prepare failed');
            $member->bind_param('ii',$community_id,$user_id);
            if (!$member->execute() || !$member->get_result()->fetch_row()) {
                $member->close();
                throw new RuntimeException('amendment final review membership required');
            }
            $member->close();

            $lock=$conn->prepare(
                "SELECT l.status AS law_status,l.current_version_id,l.originating_community_id,
                        v.status AS version_status,v.proposed_by,fr.final_status AS vote_result,
                        a.id AS amendment_case_id,a.status AS amendment_status,a.base_version_id,
                        a.conformity_case_id,cc.status AS conformity_status,
                        cc.parent_community_id,cc.parent_law_id,cc.parent_law_version_id,
                        child.parent_community_id AS current_parent_community_id,
                        base.status AS base_version_status,pl.status AS parent_law_status,
                        pl.current_version_id AS parent_current_version_id,
                        pv.status AS parent_version_status,pl.effective_at,pl.expires_at,pl.repealed_at,
                        rc.status AS screening_status,ac.status AS authority_status
                 FROM community_law_versions v
                 JOIN community_laws l ON l.id=v.law_id
                 JOIN community_law_final_results fr ON fr.law_version_id=v.id
                 JOIN community_law_amendment_cases a ON a.proposed_version_id=v.id
                 JOIN community_law_conformity_cases cc ON cc.id=a.conformity_case_id
                 JOIN communities child ON child.id=cc.community_id
                 JOIN community_law_versions base ON base.id=a.base_version_id AND base.law_id=l.id
                 JOIN community_laws pl ON pl.id=cc.parent_law_id
                 JOIN community_law_versions pv ON pv.id=cc.parent_law_version_id AND pv.law_id=pl.id
                 JOIN community_law_review_cases rc ON rc.law_version_id=v.id
                 JOIN community_law_authority_cases ac ON ac.law_version_id=v.id
                 WHERE l.id=? AND v.id=? FOR UPDATE"
            );
            if (!$lock) throw new RuntimeException('amendment final review lock prepare failed');
            $lock->bind_param('ii',$law_id,$version_id);
            if (!$lock->execute()) throw new RuntimeException('amendment final review lock failed');
            $row=$lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$row || (int)$row['originating_community_id']!==$community_id ||
                $row['law_status']!=='approved' || $row['version_status']!=='voting' ||
                $row['amendment_status']!=='parent_review_approved' ||
                $row['conformity_status']!=='amendment_in_progress' ||
                (int)$row['current_version_id']!==(int)$row['base_version_id'] ||
                $row['base_version_status']!=='approved' ||
                (int)$row['current_parent_community_id']!==(int)$row['parent_community_id'] ||
                $row['parent_law_status']!=='approved' || $row['parent_version_status']!=='approved' ||
                community_law_scope_match_checked($conn,(int)$row['parent_law_version_id'],$community_id)!==true ||
                (int)$row['parent_current_version_id']!==(int)$row['parent_law_version_id'] ||
                !empty($row['repealed_at']) ||
                (!empty($row['effective_at']) && strtotime($row['effective_at'])>time()) ||
                (!empty($row['expires_at']) && strtotime($row['expires_at'])<=time()) ||
                $row['screening_status']!=='screening_clear' ||
                $row['authority_status']!=='confirmed' || $row['vote_result']!=='vote_passed') {
                throw new RuntimeException('amendment final review state is invalid');
            }

            $existing=$conn->prepare('SELECT 1 FROM community_law_final_review_cases WHERE law_version_id=? LIMIT 1 FOR UPDATE');
            if (!$existing) throw new RuntimeException('amendment final review existence prepare failed');
            $existing->bind_param('i',$version_id);
            if (!$existing->execute()) throw new RuntimeException('amendment final review existence lookup failed');
            $has_case=(bool)$existing->get_result()->fetch_row();
            $existing->close();
            if ($has_case) throw new RuntimeException('amendment final review already opened');

            $scope_ids=community_law_scope_community_ids($conn,$version_id);
            if (!$scope_ids || !in_array($community_id,$scope_ids,true)) {
                throw new RuntimeException('amendment final review scope invalid');
            }
            $electorate=$conn->prepare(
                "INSERT IGNORE INTO community_law_final_review_electorate (law_version_id,user_id)
                 SELECT ?,cm.user_id FROM community_members cm
                 WHERE cm.community_id=? AND cm.user_id<>?
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_review_assessments r
                       WHERE r.law_version_id=? AND r.reviewer_user_id=cm.user_id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_authority_assessments ar
                       WHERE ar.law_version_id=? AND ar.reviewer_user_id=cm.user_id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM community_law_amendment_parent_reviews pr
                       JOIN community_law_amendment_cases a ON a.id=pr.case_id
                       WHERE a.proposed_version_id=? AND pr.reviewer_user_id=cm.user_id
                   )"
            );
            if (!$electorate) throw new RuntimeException('amendment final review electorate prepare failed');
            foreach ($scope_ids as $scope_id) {
                $scope_id=(int)$scope_id;
                $proposer_id=(int)$row['proposed_by'];
                $electorate->bind_param('iiiiii',$version_id,$scope_id,$proposer_id,$version_id,$version_id,$version_id);
                if (!$electorate->execute()) throw new RuntimeException('amendment final review electorate snapshot failed');
            }
            $electorate->close();

            $capacity=$conn->prepare('SELECT COUNT(*) AS total FROM community_law_final_review_electorate WHERE law_version_id=?');
            if (!$capacity) throw new RuntimeException('amendment final review capacity prepare failed');
            $capacity->bind_param('i',$version_id);
            if (!$capacity->execute()) throw new RuntimeException('amendment final review capacity failed');
            $reviewer_count=(int)$capacity->get_result()->fetch_assoc()['total'];
            $capacity->close();
            if ($reviewer_count<3) throw new RuntimeException('amendment requires three independent final reviewers');

            $references=$conn->prepare(
                "SELECT ar.reference_law_id,ar.reference_law_version_id,
                        rl.current_version_id,rl.status AS reference_status,
                        rv.status AS reference_version_status,rl.effective_at,rl.expires_at,rl.repealed_at
                 FROM community_law_authority_references ar
                 JOIN community_laws rl ON rl.id=ar.reference_law_id
                 JOIN community_law_versions rv ON rv.id=ar.reference_law_version_id
                 WHERE ar.law_version_id=?"
            );
            if (!$references) throw new RuntimeException('amendment final review references prepare failed');
            $references->bind_param('i',$version_id);
            if (!$references->execute()) throw new RuntimeException('amendment final review references lookup failed');
            $reference_rows=$references->get_result();
            $reference_insert=$conn->prepare(
                "INSERT INTO community_law_final_review_references
                    (law_version_id,reference_law_id,reference_law_version_id,
                     reference_origin_community_id,relation_type,scope_fingerprint)
                 SELECT ?,ar.reference_law_id,ar.reference_law_version_id,
                        ar.reference_origin_community_id,ar.relation_type,?
                 FROM community_law_authority_references ar
                 WHERE ar.law_version_id=? AND ar.reference_law_version_id=?"
            );
            if (!$reference_insert) { $references->close(); throw new RuntimeException('amendment final reference insert prepare failed'); }
            $reference_count=0;
            while ($reference=$reference_rows->fetch_assoc()) {
                if ((int)$reference['current_version_id']!==(int)$reference['reference_law_version_id'] ||
                    $reference['reference_status']!=='approved' || $reference['reference_version_status']!=='approved' ||
                    !empty($reference['repealed_at']) ||
                    (!empty($reference['effective_at']) && strtotime($reference['effective_at'])>time()) ||
                    (!empty($reference['expires_at']) && strtotime($reference['expires_at'])<=time())) {
                    $references->close();$reference_insert->close();
                    throw new RuntimeException('amendment authority reference changed');
                }
                $scope_stmt=$conn->prepare('SELECT scope_community_id,include_descendants FROM community_law_scopes WHERE law_version_id=? ORDER BY scope_community_id');
                if (!$scope_stmt) { $references->close();$reference_insert->close();throw new RuntimeException('amendment reference scope prepare failed'); }
                $reference_version_id=(int)$reference['reference_law_version_id'];
                $scope_stmt->bind_param('i',$reference_version_id);
                if (!$scope_stmt->execute()) { $scope_stmt->close();$references->close();$reference_insert->close();throw new RuntimeException('amendment reference scope lookup failed'); }
                $scope_rows=[];
                $scope_result=$scope_stmt->get_result();
                while ($scope_row=$scope_result->fetch_assoc()) {
                    $scope_rows[]=['scope_community_id'=>(int)$scope_row['scope_community_id'],'include_descendants'=>(int)$scope_row['include_descendants']];
                }
                $scope_stmt->close();
                if (!$scope_rows) { $references->close();$reference_insert->close();throw new RuntimeException('amendment reference scope missing'); }
                $fingerprint=hash('sha256',json_encode($scope_rows));
                $reference_law_id=(int)$reference['reference_law_id'];
                $reference_insert->bind_param('isii',$version_id,$fingerprint,$version_id,$reference_version_id);
                if (!$reference_insert->execute()) { $references->close();$reference_insert->close();throw new RuntimeException('amendment final reference snapshot failed'); }
                $reference_count++;
            }
            $references->close();
            $reference_insert->close();
            if ($reference_count<1) throw new RuntimeException('amendment has no final review authority references');
            $parent_reference=$conn->prepare(
                "SELECT 1 FROM community_law_final_review_references
                 WHERE law_version_id=? AND reference_law_id=? AND reference_law_version_id=? AND relation_type='higher'
                 LIMIT 1"
            );
            if (!$parent_reference) throw new RuntimeException('amendment parent final reference prepare failed');
            $parent_law_id=(int)$row['parent_law_id'];
            $parent_law_version_id=(int)$row['parent_law_version_id'];
            $parent_reference->bind_param('iii',$version_id,$parent_law_id,$parent_law_version_id);
            if (!$parent_reference->execute() || !$parent_reference->get_result()->fetch_row()) {
                $parent_reference->close();
                throw new RuntimeException('amendment parent law reference missing from final review snapshot');
            }
            $parent_reference->close();

            if (!community_law_capture_charter_snapshot($conn,$version_id)) {
                throw new RuntimeException('amendment charter snapshot unavailable');
            }

            $case_insert=$conn->prepare(
                "INSERT INTO community_law_final_review_cases
                    (law_version_id,status,reviewer_quorum,appeal_period_days,opened_by)
                 VALUES (?,'pending',3,7,?)"
            );
            if (!$case_insert) throw new RuntimeException('amendment final review case prepare failed');
            $case_insert->bind_param('ii',$version_id,$user_id);
            if (!$case_insert->execute()) throw new RuntimeException('amendment final review case insert failed');
            $case_insert->close();

            $version_update=$conn->prepare("UPDATE community_law_versions SET status='pending_final_review' WHERE id=? AND status='voting'");
            if (!$version_update) throw new RuntimeException('amendment final review version prepare failed');
            $version_update->bind_param('i',$version_id);
            if (!$version_update->execute() || $version_update->affected_rows!==1) throw new RuntimeException('amendment final review version update failed');
            $version_update->close();

            $details=json_encode([
                'base_version_id'=>(int)$row['base_version_id'],'proposed_version_id'=>$version_id,
                'final_review_electorate_count'=>$reviewer_count,'law_current_version_unchanged'=>true
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $event=$conn->prepare("INSERT INTO community_law_amendment_events (case_id,actor_user_id,event_type,details) VALUES (?,?,'final_review_opened',?)");
            if (!$event) throw new RuntimeException('amendment final review event prepare failed');
            $amendment_id=(int)$row['amendment_case_id'];
            $event->bind_param('iis',$amendment_id,$user_id,$details);
            if (!$event->execute()) throw new RuntimeException('amendment final review event failed');
            $event->close();

            if (!community_law_record_history(
                $conn,$law_id,$version_id,$community_id,$user_id,'amendment_final_review_opened',
                'بررسی نهایی مستقل برای اصلاحیه آغاز شد؛ قانون جاری تا پایان ارزیابی و مهلت اعتراض بدون تغییر می‌ماند.'
            )) throw new RuntimeException('amendment final review history failed');

            if (!$conn->commit()) throw new RuntimeException('amendment final review commit failed');
            $started=false;
            return true;
        } catch (Throwable $e) {
            if ($started) $conn->rollback();
            error_log('[Dolate Eshgh amendment final review open] '.$e->getMessage());
            return false;
        }
    }
}
