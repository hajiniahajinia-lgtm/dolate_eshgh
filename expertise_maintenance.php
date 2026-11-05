<?php
/**
 * Finalize expired expertise applications and domain proposals.
 * This routine is invoked by the CLI scheduler after database configuration is verified.
 * This must be called only by the trusted CLI maintenance runner, never by a page GET.
 */
function expertise_finalize_expired(mysqli $conn): array {
    $summary = ['applications' => 0, 'domains' => 0];
    $requiredApplicationTables = [
        'expertise_applications',
        'expertise_application_reviews',
        'expertise_application_votes',
        'expertise_competencies',
        'expertise_application_history'
    ];
    $requiredDomainTables = ['expertise_domains', 'expertise_domain_votes'];

    $exists = static function (string $table) use ($conn): bool {
        $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $found;
    };

    $applicationsReady = true;
    foreach ($requiredApplicationTables as $table) {
        if (!$exists($table)) { $applicationsReady = false; break; }
    }
    if ($applicationsReady) {
        $due = $conn->query("SELECT id FROM expertise_applications
            WHERE status='pending' AND decision_at IS NULL
              AND expires_at IS NOT NULL AND expires_at<=NOW()
              AND (SELECT COUNT(*) FROM expertise_application_reviews r
                   WHERE r.application_id=expertise_applications.id
                     AND r.status='submitted' AND r.conflict_of_interest='none')>=3");
        while ($item = $due->fetch_assoc()) {
            $id = (int)$item['id'];
            $conn->begin_transaction();
            try {
                $lock = $conn->prepare("SELECT a.*,
                    (SELECT COUNT(*) FROM expertise_application_reviews r
                     WHERE r.application_id=a.id AND r.status='submitted'
                       AND r.conflict_of_interest='none') AS independent_review_count
                    FROM expertise_applications a WHERE a.id=? FOR UPDATE");
                $lock->bind_param('i', $id);
                $lock->execute();
                $application = $lock->get_result()->fetch_assoc();
                $lock->close();

                if ($application && $application['status']==='pending'
                    && $application['decision_at']===null
                    && !empty($application['expires_at'])
                    && strtotime($application['expires_at'])<=time()
                    && (int)$application['independent_review_count']>=3) {
                    $counts = $conn->prepare("SELECT SUM(vote='yes') yes_count,SUM(vote='no') no_count
                        FROM expertise_application_votes WHERE application_id=?");
                    $counts->bind_param('i', $id);
                    $counts->execute();
                    $votes = $counts->get_result()->fetch_assoc() ?: [];
                    $counts->close();

                    $yes = (int)($votes['yes_count'] ?? 0);
                    $no = (int)($votes['no_count'] ?? 0);
                    $total = $yes + $no;
                    $reviewCount = (int)$application['independent_review_count'];
                    $status = 'not_approved';
                    if ($total===0) {
                        $reason = 'عدم تصویب: تا پایان مهلت، هیچ رأی معتبری ثبت نشده بود.';
                    } else {
                        $reason = 'عدم تصویب: هیچ‌یک از گزینه‌های موافق یا مخالف به حدنصاب دوسوم نرسید.';
                    }

                    if ($reviewCount>=3 && $total>0 && $yes*3 >= $total*2) {
                        $scoreQuery = $conn->prepare("SELECT AVG(theoretical_score*0.20+practical_score*0.30+achievements_score*0.30+professional_score*0.20) weighted_score
                            FROM expertise_application_reviews
                            WHERE application_id=? AND status='submitted' AND conflict_of_interest='none'");
                        $scoreQuery->bind_param('i', $id);
                        $scoreQuery->execute();
                        $scoreRow = $scoreQuery->get_result()->fetch_assoc();
                        $scoreQuery->close();
                        $score = (float)($scoreRow['weighted_score'] ?? 0);
                        if ($score<60) {
                            $status='rejected';
                            $reason='رد درخواست: امتیاز ارزیابی مستقل کمتر از ۶۰ است.';
                        } else {
                            if ($score<70) { $level=1; $weight=1.25; }
                            elseif ($score<80) { $level=2; $weight=1.50; }
                            elseif ($score<90) { $level=3; $weight=2.00; }
                            else { $level=4; $weight=3.00; }
                            $up = $conn->prepare("INSERT INTO expertise_competencies
                                (user_id,domain_id,level,weight,approved_in_community_id,source_application_id,valid_from,valid_until)
                                VALUES (?,?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 3 YEAR))
                                ON DUPLICATE KEY UPDATE level=VALUES(level),weight=VALUES(weight),
                                approved_in_community_id=VALUES(approved_in_community_id),
                                source_application_id=VALUES(source_application_id),
                                valid_from=NOW(),valid_until=DATE_ADD(NOW(),INTERVAL 3 YEAR)");
                            $up->bind_param('iiidii', $application['target_user_id'], $application['domain_id'],
                                $level, $weight, $application['community_id'], $id);
                            $up->execute();
                            $up->close();
                            $status='approved';
                            $reason='تصویب: رأی موافق به حداقل دوسوم رسیده و امتیاز شایستگی کافی است.';
                        }
                    } elseif ($reviewCount>=3 && $total>0 && $no*3 >= $total*2) {
                        $status='rejected';
                        $reason='رد درخواست: رأی مخالف به حداقل دوسوم رسیده است.';
                    } elseif ($reviewCount>=3) {
                        $status='not_approved';
                        $reason='عدم تصویب: هیچ‌یک از دو گزینهٔ موافق یا مخالف به دوسوم آرا نرسید.';
                    }

                    $finish = $conn->prepare('UPDATE expertise_applications SET status=?,decision_at=NOW() WHERE id=? AND decision_at IS NULL');
                    $finish->bind_param('si', $status, $id);
                    $finish->execute();
                    $changed = $finish->affected_rows;
                    $finish->close();
                    if ($changed > 0) {
                        $detail = $reason.' ارزیابی مستقل: '.$reviewCount.'؛ موافق: '.$yes.'؛ مخالف: '.$no.'؛ شرط تصمیم: دوسوم.';
                        $history = $conn->prepare("INSERT INTO expertise_application_history
                            (application_id,actor_user_id,event_type,details) VALUES (?,NULL,'voting_closed',?)");
                        $history->bind_param('is', $id, $detail);
                        $history->execute();
                        $history->close();
                        $summary['applications']++;
                    }
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Expertise application maintenance: '.$e->getMessage());
            }
        }
        $due->close();
    }

    $domainsReady = true;
    foreach ($requiredDomainTables as $table) {
        if (!$exists($table)) { $domainsReady = false; break; }
    }
    if ($domainsReady) {
        $dueDomains = $conn->query("SELECT id FROM expertise_domains
            WHERE status='pending' AND decided_at IS NULL
              AND created_at<=DATE_SUB(NOW(),INTERVAL 7 DAY)");
        while ($row = $dueDomains->fetch_assoc()) {
            $domainId = (int)$row['id'];
            $conn->begin_transaction();
            try {
                $lock = $conn->prepare('SELECT id,status,decided_at FROM expertise_domains WHERE id=? FOR UPDATE');
                $lock->bind_param('i', $domainId);
                $lock->execute();
                $domain = $lock->get_result()->fetch_assoc();
                $lock->close();
                if ($domain && $domain['status']==='pending' && $domain['decided_at']===null) {
                    $counts = $conn->prepare("SELECT SUM(vote='yes') yes_count,SUM(vote='no') no_count
                        FROM expertise_domain_votes WHERE domain_id=?");
                    $counts->bind_param('i', $domainId);
                    $counts->execute();
                    $votes = $counts->get_result()->fetch_assoc() ?: [];
                    $counts->close();
                    $yes = (int)($votes['yes_count'] ?? 0);
                    $no = (int)($votes['no_count'] ?? 0);
                    if ($yes>$no) {
                        $update = $conn->prepare("UPDATE expertise_domains SET status='approved',decided_at=NOW() WHERE id=? AND status='pending' AND decided_at IS NULL");
                    } elseif ($no>$yes) {
                        $update = $conn->prepare("UPDATE expertise_domains SET status='rejected',decided_at=NOW() WHERE id=? AND status='pending' AND decided_at IS NULL");
                    } else {
                        $update = $conn->prepare("UPDATE expertise_domains SET decided_at=NOW() WHERE id=? AND status='pending' AND decided_at IS NULL");
                    }
                    $update->bind_param('i', $domainId);
                    $update->execute();
                    if ($update->affected_rows>0) $summary['domains']++;
                    $update->close();
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Expertise domain maintenance: '.$e->getMessage());
            }
        }
        $dueDomains->close();
    }
    return $summary;
}
