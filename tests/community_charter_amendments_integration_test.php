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

    $db->query(
        "UPDATE community_charter_amendments
         SET status='independent_review', review_opens_at=NOW(),
             review_closes_at=DATE_ADD(NOW(), INTERVAL 1 DAY)
         WHERE id=" . $amendmentId
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
    $db->query(
        "UPDATE community_charter_amendments SET status='public_voting',
         voting_opens_at=NOW(), voting_closes_at=DATE_ADD(NOW(), INTERVAL 1 DAY)
         WHERE id=" . $amendmentId
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
    $db->query(
        "UPDATE community_charter_amendments SET status='appeal_period',
         approved_at=NOW(), appeal_closes_at=DATE_ADD(NOW(), INTERVAL 1 DAY)
         WHERE id=" . $amendmentId
    );

    $newUsers = $db->prepare("INSERT INTO users (id,username) VALUES (?,?)");
    for ($id = 17; $id <= 19; $id++) {
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

    $appealVotes = [
        ['reviewer_user_id'=>17,'verdict'=>'dismiss'],
        ['reviewer_user_id'=>18,'verdict'=>'dismiss'],
        ['reviewer_user_id'=>19,'verdict'=>'uphold']
    ];
    integration_check(
        community_charter_amendment_appeal_result($appealVotes) === 'dismissed',
        'two of three separate appeal reviewers dismiss the appeal'
    );

    $appealAssessment = $db->prepare(
        "INSERT INTO community_charter_amendment_appeal_reviewers
         (appeal_id,reviewer_user_id,verdict,reasoning) VALUES (?,?,?,'استدلال آزمایشی رسیدگی به اعتراض.')"
    );
    foreach ($appealVotes as $appealVote) {
        $reviewerId = $appealVote['reviewer_user_id'];
        $verdict = $appealVote['verdict'];
        $appealAssessment->bind_param('iis', $appealId, $reviewerId, $verdict);
        $appealAssessment->execute();
    }
    $appealAssessment->close();
    $db->query(
        "UPDATE community_charter_amendment_appeals
         SET status='dismissed',resolved_at=NOW() WHERE id=" . $appealId
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

    fwrite(STDOUT, "OK: charter amendment MySQL integration workflow passed.\n");
} finally {
    $db->close();
}
