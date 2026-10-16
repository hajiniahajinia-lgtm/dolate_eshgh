<?php
session_start();
include "db.php";

if(!isset($_SESSION['user_id'])){
    echo json_encode(['success'=>false,'message'=>"ابتدا وارد شوید."]);
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $topic_id = (int)$_POST['topic_id'];
    $day_vote = (int)$_POST['day_vote'];
    $user_id = (int)$_SESSION['user_id'];

    // محدودیت 10 بار رای برای هر کاربر
    $vote_count_q = $conn->query("SELECT COUNT(*) as c FROM topic_urgency_votes WHERE topic_id=$topic_id AND user_id=$user_id");
    $vote_count = $vote_count_q->fetch_assoc()['c'];

    if($vote_count < 10){
        // درج یا بروزرسانی رای
        $conn->query("INSERT INTO topic_urgency_votes (topic_id,user_id,day_vote) 
                      VALUES ($topic_id,$user_id,$day_vote)
                      ON DUPLICATE KEY UPDATE day_vote=$day_vote");

        // محاسبه فوریت نهایی: قله داده ها → میانگین
        $days_q = $conn->query("SELECT day_vote, COUNT(*) as cnt FROM topic_urgency_votes WHERE topic_id=$topic_id GROUP BY day_vote ORDER BY cnt DESC");
        $days = [];
        while($row = $days_q->fetch_assoc()){
            $days[] = $row;
        }

        if(count($days)){
            $max_count = $days[0]['cnt'];
            $peak_days = [];
            foreach($days as $d){
                if($d['cnt'] == $max_count){
                    $peak_days[] = $d['day_vote'];
                } else break;
            }
            $final_day = round(array_sum($peak_days)/count($peak_days));
            $conn->query("UPDATE topics SET urgency=$final_day WHERE id=$topic_id");
            echo json_encode(['success'=>true,'new_value'=>$final_day]);
            exit;
        }
    }
    echo json_encode(['success'=>false,'message'=>"محدودیت رای شما پر شده است"]);