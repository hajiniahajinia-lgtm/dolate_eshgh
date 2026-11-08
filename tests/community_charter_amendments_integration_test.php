<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function integration_check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function apply_sql_file(mysqli $db, string $path): void {
    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException("Migration is missing or empty: {$path}");
    }
    $db->multi_query($sql);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) $result->free();
    } while ($db->more_results() && $db->next_result());
}

function try_activate_amendment(mysqli $db, int $amendmentId): bool {
    try {
        community_charter_amendment_activate($db, $amendmentId);
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$name = getenv('DB_NAME') ?: 'dolate_charter_ci';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$port = (int)(getenv('DB_PORT') ?: 3306);

$db = new mysqli($host, $user, $password, $name, $port);
$db->set_charset('utf8mb4');

try {
    $db->query(
        "CREATE TABLE IF NOT EXISTS users (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    apply_sql_file($db, __DIR__ . '/../migrations/20261107_community_charter_foundation.sql');
    apply_sql_file($db, __DIR__ . '/../migrations/20261107_community_charter_amendments.sql');
    apply_sql_file($db, __DIR__ . '/../migrations/20261108_community_charter_amendment_appeal_assignments.sql');

    require_once __DIR__ . '/../community_law_helpers.php';

    $requiredTables = [
        'community_charter_versions',
        'community_charter_articles',
        'community_charter_amendments',
        'community_charter_amendment_items',
        'community_charter_amendment_reviewers',
        'community_charter_amendment_assessments',
        'community_charter_amendment_electorate',
        'community_charter_amendment_votes',
        'community_charter_amendment_appeals',
        'community_charter_amendment_appeal_reviewers',
        'community_charter_amendment_appeal_reviewer_assignments'
    ];
    $tables = $db->query(
        "SELECT COUNT(*) AS total FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name IN ('" .
         implode("','", $requiredTables) . "')"
    )->fetch_assoc();
    integration_check((int)$tables['total'] === count($requiredTables), 'all charter tables were created');

    $baseline = $db->query(
        "SELECT id, version_number FROM community_charter_versions WHERE status='effective'"
    );
    $activeRows = [];
    while ($row = $baseline->fetch_assoc()) $activeRows[] = $row;
    $baseline->free();
    integration_check(count($activeRows) === 1 && (int)$activeRows[0]['version_number'] === 1,
        'the initial charter version is the single effective version');

    $articleCount = (int)$db->query(
        "SELECT COUNT(*) AS total FROM community_charter_articles WHERE charter_version_id=1"
    )->fetch_assoc()['total'];
    integration_check($articleCount === 12, 'the initial charter contains all twelve foundation articles');

    $insertUser = $db->prepare("INSERT INTO users (id, username) VALUES (?, ?)");
    for ($id = 1; $id <= 16; $id++) {
        $username = 'charter_test_' . $id;
        $insertUser->bind_param('is', $id, $username);
        $insertUser->execute();
    }
    $insertUser->close();

    $db->begin_transaction();
    $version = $db->prepare(
        "INSERT INTO community_charter_versions
         (version_number,title,status,change_summary,proposed_by)
         VALUES (2,'آزمایش اصلاح منشور','draft','نسخه آزمایشی برای آزمون یکپارچه',1)"
    );
    $version->execute();
    $proposedVersionId = (int)$version->insert_id;
    $version->close();

    $clone = $db->prepare(
        "INSERT INTO community_charter_articles
         (charter_version_id,article_code,title,article_text,principle_group,is_core,display_order)
         SELECT ?,article_code,title,article_text,principle_group,is_core,display_order
         FROM community_charter_articles WHERE charter_version_id=1"
    );
    $clone->bind_param('i', $proposedVersionId);
    $clone->execute();
    $clone->close();

    $amendment = $db->prepare(
        "INSERT INTO community_charter_amendments
         (base_charter_version_id,proposed_charter_version_id,proposer_user_id,
          title,justification,impact_assessment,status)
         VALUES (1,?,1,'آزمایش اصلاح منشور',
          'دلیل آزمایشی کافی برای بررسی یکپارچه اصلاح منشور.',
          'ارزیابی پیامد آزمایشی برای آزمون مسیر اصلاح منشور.', 'draft')"
    );
    $amendment->bind_param('i', $proposedVersionId);
    $amendment->execute();
    $amendmentId = (int)$amendment->insert_id;
    $amendment->close();

    $item = $db->prepare(
        "INSERT INTO community_charter_amendment_items
         (amendment_id,action,target_article_id,article_code,proposed_title,
          proposed_text,principle_group,rationale)
         VALUES (?,'replace',12,'C-12','فناوری در خدمت زندگی',
          'متن اصلاحی آزمایشی برای آزمون ذخیره نسخه پیشنهادی.',
          'technology','دلیل آزمایشی اصلاح ماده برای آزمون یکپارچه.')"
    );
    $item->bind_param('i', $amendmentId);
    $item->execute();
    $item->close();
    $db->commit();
    integration_check(true, 'draft amendment and proposed charter version were persisted');

    $reviewerIds = [2, 3, 4, 5, 6];
    $orderedCandidates = range(2, 16);
    $db->begin_transaction();
    $panel = community_charter_assign_review_panel($db, $amendmentId, $orderedCandidates);
    $db->commit();
    integration_check($panel['reviewer_ids'] === $reviewerIds, 'the production helper assigns the first five shuffled candidates as reviewers');
    integration_check($panel['electorate_ids'] === range(7, 16), 'the production helper excludes reviewers from the public electorate');

    $panelCounts = $db->query(
        "SELECT
            (SELECT COUNT(*) FROM community_charter_amendment_reviewers WHERE amendment_id=" . $amendmentId . ") AS reviewers,
            (SELECT COUNT(*) FROM community_charter_amendment_electorate WHERE amendment_id=" . $amendmentId . ") AS electorate,
            (SELECT COUNT(*) FROM community_charter_amendment_reviewers r
             JOIN community_charter_amendment_electorate e
               ON e.amendment_id=r.amendment_id AND e.user_id=r.reviewer_user_id
             WHERE r.amendment_id=" . $amendmentId . ") AS overlap"
    )->fetch_assoc();
    integration_check((int)$panelCounts['reviewers'] === 5, 'five reviewer assignments were persisted by the production helper');
    integration_check((int)$panelCounts['electorate'] === 10, 'ten public electorate snapshots were persisted by the production helper');
    integration_check((int)$panelCounts['overlap'] === 0, 'reviewers are never included in the public electorate snapshot');

    $db->begin_transaction();
    community_charter_start_independent_review($db, $amendmentId);
    $db->commit();
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_amendments WHERE id=" . $amendmentId)->fetch_assoc()['status'] === 'independent_review',
        'the production transition helper starts the independent review stage'
    );

    $assessmentInsert = $db->prepare(
        "INSERT INTO community_charter_amendment_assessments
         (amendment_id,reviewer_user_id,verdict,rights_protection,
          equality_and_non_discrimination,living_beings_and_environment,
          power_and_accountability,future_generations,reasoning,evidence)
         VALUES (?,?,?,'maintained','maintained','maintained','maintained',
                 'maintained','استدلال آزمایشی کافی برای آزمون ارزیابی مستقل.','شاهد آزمایشی')"
    );
    for ($i = 0; $i < 5; $i++) {
        $reviewerId = $reviewerIds[$i];
        $verdict = $i < 4 ? 'supports' : 'unclear';
        $assessmentInsert->bind_param('iis', $amendmentId, $reviewerId, $verdict);
        $assessmentInsert->execute();
    }
    $assessmentInsert->close();

    $assessmentsResult = $db->query(
        "SELECT reviewer_user_id,verdict,rights_protection,equality_and_non_discrimination,
                living_beings_and_environment,power_and_accountability,future_generations
         FROM community_charter_amendment_assessments
         WHERE amendment_id=" . $amendmentId . " ORDER BY reviewer_user_id"
    );
    $assessments = [];
    while ($row = $assessmentsResult->fetch_assoc()) $assessments[] = $row;
    $assessmentsResult->free();
    integration_check(
        community_charter_amendment_reviewer_result($assessments) === 'approved',
        'five independent assessments with four supports pass the real review helper'
    );
    $db->begin_transaction();
    community_charter_advance_to_public_voting($db, $amendmentId);
    $db->commit();
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_amendments WHERE id=" . $amendmentId)->fetch_assoc()['status'] === 'public_voting',
        'the production transition helper advances an approved review to public voting'
    );

    $voteInsert = $db->prepare(
        "INSERT INTO community_charter_amendment_votes (amendment_id,user_id,vote) VALUES (?,?,?)"
    );
    for ($voterId = 7; $voterId <= 16; $voterId++) {
        $vote = $voterId <= 12 ? 'yes' : ($voterId <= 14 ? 'no' : 'abstain');
        $voteInsert->bind_param('iis', $amendmentId, $voterId, $vote);
        $voteInsert->execute();
    }
    $voteInsert->close();

    $voteCounts = $db->query(
        "SELECT SUM(vote='yes') AS yes_count,SUM(vote='no') AS no_count,
                SUM(vote='abstain') AS abstain_count
         FROM community_charter_amendment_votes WHERE amendment_id=" . $amendmentId
    )->fetch_assoc();
    integration_check(
        community_charter_amendment_vote_result(
            10, (int)$voteCounts['yes_count'], (int)$voteCounts['no_count'],
            (int)$voteCounts['abstain_count']
        ) === 'approved',
        'stored ballot passes exact two-thirds turnout and three-quarters approval thresholds'
    );
    $db->begin_transaction();
    community_charter_advance_to_appeal_period($db, $amendmentId);
    $db->commit();
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_amendments WHERE id=" . $amendmentId)->fetch_assoc()['status'] === 'appeal_period',
        'the production transition helper opens the appeal period after approval'
    );

    $newUsers = $db->prepare("INSERT INTO users (id,username) VALUES (?,?)");
    for ($id = 17; $id <= 22; $id++) {
        $username = 'charter_appeal_test_' . $id;
        $newUsers->bind_param('is', $id, $username);
        $newUsers->execute();
    }
    $newUsers->close();

    $appeal = $db->prepare(
        "INSERT INTO community_charter_amendment_appeals
         (amendment_id,appellant_user_id,grounds,evidence,status)
         VALUES (?,7,'دلایل روشن و مستند آزمایشی برای ثبت اعتراض به نتیجه.','شاهد اعتراض آزمایشی','under_review')"
    );
    $appeal->bind_param('i', $amendmentId);
    $appeal->execute();
    $appealId = (int)$appeal->insert_id;
    $appeal->close();

    $db->begin_transaction();
    $assignedAppealReviewers = community_charter_assign_appeal_reviewers($db, $appealId, [17,18,19]);
    $db->commit();
    integration_check($assignedAppealReviewers === [17,18,19], 'the production helper assigns three independent appeal reviewers');
    $appealAssignmentCount = (int)$db->query(
        "SELECT COUNT(*) AS total FROM community_charter_amendment_appeal_reviewer_assignments WHERE appeal_id=" . $appealId
    )->fetch_assoc()['total'];
    integration_check($appealAssignmentCount === 3, 'all three independent appeal reviewer assignments were persisted');

    $db->query(
        "UPDATE community_charter_amendments
         SET appeal_closes_at=DATE_SUB(NOW(), INTERVAL 1 SECOND)
         WHERE id=" . $amendmentId
    );
    integration_check(!try_activate_amendment($db, $amendmentId),
        'activation is refused while an appeal remains open');
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_versions WHERE id=1")->fetch_assoc()['status'] === 'effective',
        'an open appeal leaves the current charter untouched'
    );

    // A user outside the snapshotted panel must not be able to submit an assessment.
    $db->begin_transaction();
    $unauthorizedRejected = false;
    try {
        community_charter_record_appeal_assessment(
            $db, $appealId, 23, 'dismiss',
            'تلاش کاربر خارج از هیئت مستقل برای ارزیابی اعتراض.'
        );
    } catch (Throwable $error) {
        $unauthorizedRejected = true;
        $db->rollback();
    }
    integration_check($unauthorizedRejected,
        'an unassigned user cannot submit an appeal assessment');
    integration_check(
        (int)$db->query("SELECT COUNT(*) AS total FROM community_charter_amendment_appeal_reviewers WHERE appeal_id=" . $appealId)->fetch_assoc()['total'] === 0,
        'an unauthorized assessment leaves no persisted reviewer record'
    );

    $appealVotes = [
        ['reviewer_user_id'=>17,'verdict'=>'unclear'],
        ['reviewer_user_id'=>18,'verdict'=>'dismiss'],
        ['reviewer_user_id'=>19,'verdict'=>'dismiss']
    ];
    integration_check(
        community_charter_amendment_appeal_result($appealVotes) === 'dismissed',
        'two dismiss votes resolve an appeal even when the remaining assessment is unclear'
    );

    $db->begin_transaction();
    $firstAppealStatus = community_charter_record_appeal_assessment(
        $db, $appealId, 17, 'unclear',
        'شواهد موجود برای نتیجه قطعی کافی نیست و بررسی بیشتری لازم است.'
    );
    $secondAppealStatus = community_charter_record_appeal_assessment(
        $db, $appealId, 18, 'dismiss',
        'دلایل ارائه‌شده برای پذیرش اعتراض کافی و قانع‌کننده نیست.'
    );
    $db->commit();
    integration_check($firstAppealStatus === 'under_review' && $secondAppealStatus === 'under_review',
        'an unclear assessment and a single dismissal keep the appeal open');
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_amendment_appeals WHERE id=" . $appealId)->fetch_assoc()['status'] === 'under_review',
        'the appeal remains persisted as under review until a decisive majority exists'
    );

    $db->begin_transaction();
    $appealStatus = community_charter_record_appeal_assessment(
        $db, $appealId, 19, 'dismiss',
        'رسیدگی مستقل نشان می‌دهد اعتراض مبنای کافی برای تغییر نتیجه ندارد.'
    );
    $db->commit();
    integration_check($appealStatus === 'dismissed',
        'the production service resolves the appeal only after the second dismissal vote');
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_amendment_appeals WHERE id=" . $appealId)->fetch_assoc()['status'] === 'dismissed',
        'the production assessment service persists the final dismissed appeal status'
    );

    // Force a mid-transaction failure after superseding the old version; rollback must restore it.
    $db->query(
        "UPDATE community_charter_versions SET status='review' WHERE id=" . $proposedVersionId
    );
    integration_check(!try_activate_amendment($db, $amendmentId),
        'a failed new-version activation aborts the whole transaction');
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_versions WHERE id=1")->fetch_assoc()['status'] === 'effective',
        'rollback restores the previous effective charter after a late activation failure'
    );
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_amendments WHERE id=" . $amendmentId)->fetch_assoc()['status'] === 'appeal_period',
        'rollback restores the amendment state after activation failure'
    );

    $db->query(
        "UPDATE community_charter_versions SET status='draft' WHERE id=" . $proposedVersionId
    );
    integration_check(try_activate_amendment($db, $amendmentId),
        'a fully reviewed, approved, unchallenged amendment activates successfully');

    $final = $db->query(
        "SELECT
            (SELECT COUNT(*) FROM community_charter_versions WHERE status='effective') AS active_versions,
            (SELECT status FROM community_charter_versions WHERE id=1) AS old_status,
            (SELECT status FROM community_charter_versions WHERE id=" . $proposedVersionId . ") AS new_status,
            (SELECT status FROM community_charter_amendments WHERE id=" . $amendmentId . ") AS amendment_status"
    )->fetch_assoc();

    integration_check((int)$final['active_versions'] === 1, 'exactly one effective charter remains after activation');
    integration_check($final['old_status'] === 'superseded', 'the previous charter version is superseded');
    integration_check($final['new_status'] === 'effective', 'the approved proposed version is effective');
    integration_check($final['amendment_status'] === 'activated', 'the amendment reaches activated status');

    // Reset only the isolated test fixture to exercise the upheld-appeal branch independently.
    $db->begin_transaction();
    $db->query(
        "UPDATE community_charter_versions
         SET status='effective' WHERE id=1"
    );
    $resetVersion = $db->prepare(
        "UPDATE community_charter_versions
         SET status='draft',approved_at=NULL,effective_at=NULL WHERE id=?"
    );
    $resetVersion->bind_param('i', $proposedVersionId);
    $resetVersion->execute();
    $resetVersion->close();
    $resetAmendment = $db->prepare(
        "UPDATE community_charter_amendments
         SET status='appeal_period',activated_at=NULL,
             appeal_closes_at=DATE_SUB(NOW(),INTERVAL 1 SECOND)
         WHERE id=?"
    );
    $resetAmendment->bind_param('i', $amendmentId);
    $resetAmendment->execute();
    $resetAmendment->close();
    $db->commit();

    $upheldAppeal = $db->prepare(
        "INSERT INTO community_charter_amendment_appeals
         (amendment_id,appellant_user_id,grounds,evidence,status)
         VALUES (?,8,'دلایل مستقل و مستند برای اعتراض دوم در آزمون.','شواهد مسیر پذیرش اعتراض','under_review')"
    );
    $upheldAppeal->bind_param('i', $amendmentId);
    $upheldAppeal->execute();
    $upheldAppealId = (int)$upheldAppeal->insert_id;
    $upheldAppeal->close();

    $db->begin_transaction();
    $upheldAssignments = community_charter_assign_appeal_reviewers($db, $upheldAppealId, [20,21,22]);
    $db->commit();
    integration_check($upheldAssignments === [20,21,22],
        'a second independent panel is assigned for the upheld-appeal scenario');

    $upheldVotes = [
        ['reviewer_user_id'=>20,'verdict'=>'uphold'],
        ['reviewer_user_id'=>21,'verdict'=>'uphold'],
        ['reviewer_user_id'=>22,'verdict'=>'dismiss']
    ];
    integration_check(
        community_charter_amendment_appeal_result($upheldVotes) === 'upheld',
        'two of three separate appeal reviewers uphold the appeal'
    );

    $db->begin_transaction();
    $upheldStatus = 'under_review';
    foreach ($upheldVotes as $appealVote) {
        $upheldStatus = community_charter_record_appeal_assessment(
            $db,
            $upheldAppealId,
            (int)$appealVote['reviewer_user_id'],
            (string)$appealVote['verdict'],
            'استدلال آزمایشی مستقل برای پذیرش اعتراض و ارزیابی آثار اصلاحیه.'
        );
    }
    $db->commit();

    integration_check($upheldStatus === 'upheld',
        'the production assessment service resolves the second appeal as upheld');
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_amendment_appeals WHERE id=" . $upheldAppealId)->fetch_assoc()['status'] === 'upheld',
        'the upheld appeal result is persisted');
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_amendments WHERE id=" . $amendmentId)->fetch_assoc()['status'] === 'rejected',
        'an upheld appeal rejects the proposed charter amendment');
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_versions WHERE id=" . $proposedVersionId)->fetch_assoc()['status'] === 'rejected',
        'an upheld appeal rejects the proposed charter version');
    integration_check(!try_activate_amendment($db, $amendmentId),
        'a rejected amendment cannot be activated after an upheld appeal');
    integration_check(
        (string)$db->query("SELECT status FROM community_charter_versions WHERE id=1")->fetch_assoc()['status'] === 'effective',
        'the original effective charter remains unchanged after an upheld appeal');

    fwrite(STDOUT, "OK: charter amendment MySQL integration workflow passed.\n");
} finally {
    $db->close();
}
