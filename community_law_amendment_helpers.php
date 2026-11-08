<?php
/**
 * گردش‌کار اصلاح محدود قانون محلی.
 * نسخهٔ مصوب مبنا فقط خوانده می‌شود؛ نسخهٔ پیشنهادی تازه با وضعیت draft ساخته می‌شود.
 */
if (!function_exists('community_law_amendment_tables_ready')) {
    function community_law_amendment_tables_ready(mysqli $conn): bool {
        $tables=['community_law_amendment_cases','community_law_amendment_articles','community_law_amendment_events'];
        $stmt=$conn->prepare("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?)");
        if (!$stmt) return false;
        $table1=$tables[0]; $table2=$tables[1]; $table3=$tables[2];
        $stmt->bind_param('sss',$table1,$table2,$table3);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $n=(int)$stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        return $n===count($tables);
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
                        v.source_decision_id,v.status AS version_status
                 FROM community_law_conformity_cases c
                 JOIN community_laws l ON l.id=c.local_law_id
                 JOIN community_law_versions v ON v.id=c.local_law_version_id
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
                (int)$record['current_version_id']!==(int)$record['local_law_version_id']) {
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

            $case_insert=$conn->prepare(
                "INSERT INTO community_law_amendment_cases
                    (law_id,base_version_id,proposed_version_id,conformity_case_id,community_id,proposed_by,status,rationale,evidence)
                 VALUES (?,?,?,?,?,?,'draft',?,?)"
            );
            if (!$case_insert) throw new RuntimeException('amendment case insert prepare failed');
            $base_version_id=(int)$record['local_law_version_id'];
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
