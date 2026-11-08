<?php
/**
 * گردش‌کار اصلاح محدود قانون محلی.
 * نسخهٔ مصوب مبنا فقط خوانده می‌شود؛ نسخهٔ پیشنهادی تازه با وضعیت draft ساخته می‌شود.
 */
if (!function_exists('community_law_amendment_tables_ready')) {
    function community_law_amendment_tables_ready(mysqli $conn): bool {
        $tables=['community_law_amendment_cases','community_law_amendment_articles','community_law_amendment_events','community_law_amendment_parent_reviews'];
        $stmt=$conn->prepare("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?)");
        if (!$stmt) return false;
        $table1=$tables[0]; $table2=$tables[1]; $table3=$tables[2];
        $table4=$tables[3];
        $stmt->bind_param('ssss',$table1,$table2,$table3,$table4);
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
                "SELECT c.id,c.community_id,c.local_law_id,c.local_law_version_id,c.parent_community_id,c.status,
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
                 WHERE c.id=? FOR UPDATE"
            );
            if (!$case_stmt) throw new RuntimeException('conformity case prepare failed');
            $case_stmt->bind_param('i',$conformity_case_id);
            if (!$case_stmt->execute()) throw new RuntimeException('conformity case read failed');
            $record=$case_stmt->get_result()->fetch_assoc();
            $case_stmt->close();
            if (!$record || (int)$record['community_id']!==$community_id ||
                (int)$record['originating_community_id']!==$community_id ||
                $record['status']!=='amendment_required' ||
                $record['law_status']!=='approved' || $record['version_status']!=='approved' ||
                (int)$record['current_version_id']!==(int)$record['local_law_version_id'] ||
                $record['parent_law_status']!=='approved' || $record['parent_version_status']!=='approved' ||
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
            foreach ($normalized as $article) {
                if (!isset($confirmed_refs[$article['reference']])) {
                    throw new RuntimeException('amendment may only change articles with a confirmed conflict');
                }
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

            $next_number=(int)$record['version_number']+1;
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
                "SELECT a.id,a.status,a.law_id,a.base_version_id,a.proposed_version_id,a.conformity_case_id,
                        a.community_id,l.status AS law_status,l.current_version_id,l.originating_community_id,
                        base.status AS base_status,base.version_number,
                        proposed.status AS proposed_status,
                        c.status AS conformity_status,c.parent_community_id,
                        pl.status AS parent_law_status,pl.current_version_id AS parent_current_version_id,
                        pl.effective_at AS parent_effective_at,pl.expires_at AS parent_expires_at,
                        pl.repealed_at AS parent_repealed_at,pv.status AS parent_version_status
                 FROM community_law_amendment_cases a
                 JOIN community_laws l ON l.id=a.law_id
                 JOIN community_law_versions base ON base.id=a.base_version_id AND base.law_id=a.law_id
                 JOIN community_law_versions proposed ON proposed.id=a.proposed_version_id AND proposed.law_id=a.law_id
                 JOIN community_law_conformity_cases c ON c.id=a.conformity_case_id
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
                (int)$row['originating_community_id']!==$community_id ||
                $row['status']!=='draft' || $row['law_status']!=='approved' ||
                (int)$row['current_version_id']!==(int)$row['base_version_id'] ||
                $row['base_status']!=='approved' || $row['proposed_status']!=='draft' ||
                $row['conformity_status']!=='amendment_required' ||
                $row['parent_law_status']!=='approved' || $row['parent_version_status']!=='approved' ||
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
                        a.base_version_id,c.parent_community_id,c.community_id,
                        l.status AS local_law_status,l.current_version_id,
                        base.status AS base_status,proposed.status AS proposed_status,
                        pl.status AS parent_law_status,pl.current_version_id AS parent_current_version_id,
                        pv.status AS parent_version_status,pl.effective_at,pl.expires_at,pl.repealed_at
                 FROM community_law_amendment_cases a
                 JOIN community_law_conformity_cases c ON c.id=a.conformity_case_id
                 JOIN community_laws l ON l.id=a.law_id
                 JOIN community_law_versions base ON base.id=a.base_version_id AND base.law_id=a.law_id
                 JOIN community_law_versions proposed ON proposed.id=a.proposed_version_id AND proposed.law_id=a.law_id
                 JOIN community_laws pl ON pl.id=c.parent_law_id
                 JOIN community_law_versions pv ON pv.id=c.parent_law_version_id AND pv.law_id=pl.id
                 WHERE a.id=? FOR UPDATE"
            );
            if (!$lock) throw new RuntimeException('parent review lock prepare failed');
            $lock->bind_param('i',$amendment_id);
            if (!$lock->execute()) throw new RuntimeException('parent review lock failed');
            $row=$lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$row || $row['status']!=='parent_review' ||
                (int)$row['parent_community_id']!==$parent_community_id ||
                (int)$row['proposed_by']===$user_id ||
                $row['local_law_status']!=='approved' ||
                (int)$row['current_version_id']!==(int)$row['base_version_id'] ||
                $row['base_status']!=='approved' || $row['proposed_status']!=='voting' ||
                $row['parent_law_status']!=='approved' || $row['parent_version_status']!=='approved' ||
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
                $case_update=$conn->prepare("UPDATE community_law_amendment_cases SET status=?,decided_at=NOW() WHERE id=? AND status='parent_review'");
                if (!$case_update) throw new RuntimeException('parent review case update prepare failed');
                $case_update->bind_param('si',$next_status,$amendment_id);
                if (!$case_update->execute() || $case_update->affected_rows!==1) throw new RuntimeException('parent review case update failed');
                $case_update->close();
                if ($conformity_next==='amendment_required') {
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
