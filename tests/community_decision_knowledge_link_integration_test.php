<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host=getenv('DB_HOST')?:'127.0.0.1'; $name=getenv('DB_NAME')?:'dolate_charter_ci';
$user=getenv('DB_USER')?:'root'; $pass=getenv('DB_PASSWORD')?:''; $port=(int)(getenv('DB_PORT')?:3306);
$db=new mysqli($host,$user,$pass,$name,$port);
$tableExisted = (int)$db->query(
 "SELECT COUNT(*) AS total FROM information_schema.tables
  WHERE table_schema=DATABASE() AND table_name='community_decision_records'"
)->fetch_assoc()['total'] > 0;
try {
 $db->query("CREATE TABLE IF NOT EXISTS community_decision_records (
   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
   source_topic_id INT NOT NULL, source_idea_id INT NULL, community_id INT NOT NULL,
   record_status VARCHAR(20) NOT NULL DEFAULT 'recorded',
   acceptance_status VARCHAR(20) NOT NULL DEFAULT 'accepted',
   enforceability_status VARCHAR(20) NOT NULL DEFAULT 'undecided',
   decided_at DATETIME NOT NULL, UNIQUE KEY uq_topic(source_topic_id)
 ) ENGINE=InnoDB");
 $sql=file_get_contents(__DIR__.'/../migrations/20261108_community_decision_knowledge_link.sql');
 if(!is_string($sql)||trim($sql)==='') throw new RuntimeException('Missing migration');
 for($i=0;$i<2;$i++){ $db->multi_query($sql); do{$res=$db->store_result();if($res instanceof mysqli_result)$res->free();}while($db->more_results()&&$db->next_result()); }
 $cols=$db->query("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='community_decision_records' AND column_name='knowledge_entry_id'")->fetch_assoc();
 $idx=$db->query("SELECT COUNT(*) n FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='community_decision_records' AND index_name='idx_decision_knowledge_entry'")->fetch_assoc();
 if((int)$cols['n']!==1||(int)$idx['n']!==1) throw new RuntimeException('Link column/index missing or duplicated');
 require_once __DIR__.'/../community_decision_ledger.php';
 $db->query("INSERT INTO community_decision_records (source_topic_id,community_id,decided_at) VALUES (987650,88,NOW())");
 if(!community_decision_attach_knowledge_entry($db,987650,12345)) throw new RuntimeException('Could not attach knowledge entry');
 $link=community_decision_get_knowledge_link($db,987650);
 if(!$link||(int)$link['knowledge_entry_id']!==12345||(int)$link['decision_id']<=0) throw new RuntimeException('Decision/knowledge link lookup failed');
 if(!community_decision_attach_knowledge_entry($db,987650,12345)) throw new RuntimeException('Idempotent retry of the same knowledge link failed');
 if(community_decision_attach_knowledge_entry($db,987650,54321)) throw new RuntimeException('Existing knowledge link was overwritten');
 echo "OK: decision-to-knowledge reference is idempotent and protected from overwrite.\n";
} finally {
 if (!$tableExisted) $db->query("DROP TABLE IF EXISTS community_decision_records");
 else $db->query("DELETE FROM community_decision_records WHERE source_topic_id=987650");
 $db->close();
}
?>
