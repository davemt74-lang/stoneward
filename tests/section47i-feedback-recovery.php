<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE agent_messages (id INTEGER PRIMARY KEY, user_id INTEGER, role TEXT, content TEXT)');
$db->exec('CREATE TABLE admin_agent_messages (id INTEGER PRIMARY KEY, admin_user_id INTEGER, role TEXT, content TEXT)');
$db->exec("INSERT INTO agent_messages VALUES (11,1,'assistant','Private customer answer'),(12,1,'user','Private prompt'),(21,2,'assistant','Second customer answer')");
$db->exec("INSERT INTO admin_agent_messages VALUES (11,9,'assistant','Admin insight'),(12,9,'user','Admin prompt'),(22,10,'assistant','Second admin insight')");
function sf_db():PDO {global $db;return $db;}
require $root.'/api/agent-feedback-core.php';
$checks=[];
$customer=sf_agent_feedback_save('customer',1,11,'helpful');
$checks['Customer feedback stored by owner and response ID']=$customer['saved'] && $customer['response_message_id']===11;
$checks['Customer never stores full response text']=!str_contains(json_encode(sf_agent_feedback_recent('customer',1)),'Private customer');
$checks['Owner sees exactly their own feedback']=count(sf_agent_feedback_recent('customer',1))===1 && count(sf_agent_feedback_recent('customer',2))===0;
$checks['No default Admin visibility into Customer ratings']=sf_agent_feedback_recent('admin',9)===[];
$checks['Customer can update feedback idempotently']=sf_agent_feedback_save('customer',1,11,'not_helpful')['rating']==='not_helpful';
$checks['A retried rating creates no duplicates']=(int)$db->query("SELECT COUNT(*) FROM agent_response_feedback WHERE scope='customer'")->fetchColumn()===1;
$checks['Feedback summary reflects exact negative rating']=sf_agent_feedback_summary('customer',1)['not_helpful']===1;
$checks['Negative feedback adds quality-only guidance']=str_contains(sf_agent_feedback_summary('customer',1)['guidance'],'Ask for clarification');
$checks['Other Customer not affected by ratings']=sf_agent_feedback_summary('customer',2)['not_helpful']===0;
$checks['Admin may rate its own independent reply']=sf_agent_feedback_save('admin',9,11,'helpful')['rating']==='helpful';
$checks['Customer and Admin IDs remain isolated']=count(sf_agent_feedback_recent('admin',9))===1 && count(sf_agent_feedback_recent('customer',1))===1;
$checks['Admin cannot see another Admin ratings']=sf_agent_feedback_recent('admin',10)===[];
$denied=function(string $scope,int $owner,int $id,string $rating):bool{
    try{sf_agent_feedback_save($scope,$owner,$id,$rating);return false;}
    catch(InvalidArgumentException|RuntimeException $e){return true;}
};
$checks['Customer cannot rate other Customer response']=$denied('customer',1,21,'helpful');
$checks['Customer cannot rate Admin response']=$denied('customer',9,11,'helpful');
$checks['Admin cannot rate other Admin response']=$denied('admin',9,22,'helpful');
$checks['Admin cannot rate Customer response']=$denied('admin',1,11,'helpful');
$checks['Cannot rate a user-written message']=$denied('customer',1,12,'helpful')&&$denied('admin',9,12,'helpful');
$checks['Invalid rating rejected']=$denied('customer',1,11,'send_email');
$checks['Unknown or unauthenticated scope rejected']=$denied('server',1,11,'helpful')&&$denied('customer',0,11,'helpful');
$checks['Invalid message ID rejected']=$denied('customer',1,-1,'helpful');
$checks['Feedback survives equivalent repeated reads']=sf_agent_feedback_recent('customer',1)===sf_agent_feedback_recent('customer',1);

function sf_agent_mission_plan_recent(string $scope,int $ownerId,int $limit=10):array{
    return $ownerId===1&&$scope==='customer'?[['steps'=>[['status'=>'read_available'],['status'=>'approval_required']]]]:
      ($ownerId===9&&$scope==='admin'?[['steps'=>[['status'=>'read_completed'],['status'=>'approval_required']]]]:[]);
}
function sf_agent_action_recent(int $ownerId,int $limit=25):array{
    return $ownerId===9?[['status'=>'executing'],['status'=>'review_required'],['status'=>'completed']]:[];
}
$fanRecovery=sf_agent_mission_recovery_summary('customer',1);
$adminRecovery=sf_agent_mission_recovery_summary('admin',9);
$checks['Customer recovery reports pending reads and approvals']=$fanRecovery['pending_reads']===1&&$fanRecovery['pending_approval']===1;
$checks['Admin uncertain actions explicitly require review']=$adminRecovery['review_required']===2&&$adminRecovery['status']==='review_required';
$checks['No automated retry permitted in recovery report']=$fanRecovery['automatic_retry']===false&&$adminRecovery['automatic_retry']===false;
$checks['No cross-owner recovery report']=sf_agent_mission_recovery_summary('admin',10)['review_required']===0;
$checks['Unauthenticated recovery report has no owner data']=sf_agent_mission_recovery_summary('customer',0)['status']==='unavailable';

$db->beginTransaction();$deleted=sf_agent_feedback_clear('customer',1);$db->commit();
$checks['Privacy clear works inside transaction']=$deleted===1&&!sf_agent_feedback_recent('customer',1);
$checks['Customer privacy clear cannot affect Admin feedback']=count(sf_agent_feedback_recent('admin',9))===1;
$checks['Other Customer clear does nothing']=sf_agent_feedback_clear('customer',2)===0;
// The export must not truncate accounts with over 100 rated replies.
$insertMessage=$db->prepare("INSERT INTO agent_messages(id,user_id,role,content) VALUES(?,?,?,?)");
for($i=1000;$i<1121;$i++){
    $insertMessage->execute([$i,1,'assistant','Private content must not be copied into feedback']);
    sf_agent_feedback_save('customer',1,$i,'helpful');
}
$fullExport=sf_agent_feedback_export('customer',1);
$checks['Privacy export includes all 121 feedback entries']=count($fullExport)===121;
$checks['Recent display remains bounded to 100']=count(sf_agent_feedback_recent('customer',1,100))===100;
$checks['Feedback totals cover complete history, not only recent 100']=sf_agent_feedback_summary('customer',1)['total']===121;
$checks['Complete export excludes message text']=!str_contains(json_encode($fullExport),'Private content');
$checks['More than 100 ratings do not expose Admin records']=count(sf_agent_feedback_export('admin',9))===1;
$checks['Bulk feedback deletion clears all exported entries']=sf_agent_feedback_clear('customer',1)===121
    && sf_agent_feedback_export('customer',1)===[];
$previous=$db;$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$checks['Privacy deletion tolerates not-yet-created table']=sf_agent_feedback_clear('customer',1)===0;
$db=$previous;
$files=[
 'api/agent-feedback.php'=>'sf_require_user(false,$write)',
 'admin/api/agent-feedback.php'=>'sf_admin_require_auth($write)',
 'api/privacy-core.php'=>"sf_agent_feedback_clear('customer',",
 'api/agent-brain.php'=>"sf_agent_feedback_summary('customer',",
 'api/agent-admin-brain.php'=>"sf_agent_feedback_summary('admin',",
 'assets/js/agent-chat-canvas.js'=>"post('agent-feedback.php'",
 'admin/assets/agent-canvas.js'=>"c.api('agent-feedback.php'",
];
foreach($files as $file=>$needle)$checks['Integration: '.$file]=str_contains(file_get_contents($root.'/'.$file),$needle);
$checks['Customer feedback export available']=str_contains(file_get_contents($root.'/api/privacy-core.php'),"'agent_response_feedback'=>sf_agent_feedback_export(");
$checks['Customer Chat uses only numeric message identifiers']=str_contains(file_get_contents($root.'/api/agent-brain.php'),'SELECT id FROM agent_messages WHERE conversation_id=? AND user_id=?');
$checks['Admin Chat uses owner-scoped assistant IDs']=str_contains(file_get_contents($root.'/api/agent-admin-brain.php'),'SELECT id,role,content,action_json,created_at FROM admin_agent_messages');
$checks['No raw text column in new feedback table']=!str_contains(file_get_contents($root.'/api/agent-feedback-core.php'),'message_text LONGTEXT');
$checks['No Agent feedback send/publish tool']=!str_contains(file_get_contents($root.'/api/agent-feedback-core.php'),'sf_campaign_send_email_node(');
$failed=0;
foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$failed++;}
echo '47I: '.(count($checks)-$failed).'/'.count($checks).' checks passed'.PHP_EOL;
exit($failed?1:0);
