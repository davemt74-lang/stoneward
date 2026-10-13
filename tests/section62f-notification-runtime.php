<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf-notice-'.bin2hex(random_bytes(5));mkdir($root.'/storage/orders',0770,true);mkdir($root.'/storage/fulfillment',0770,true);define('SF_ROOT',$root);
foreach([7=>'owner@example.com',8=>'other@example.com'] as $uid=>$email){
  $id='SF-000'.$uid;
  file_put_contents($root.'/storage/orders/'.$id.'.json',json_encode(['id'=>$id,'user_id'=>$uid,'customer'=>['email'=>$email],'created_at'=>'2026-10-13T08:00:00Z','quote'=>['items'=>[['type'=>'custom_media','format'=>'vinyl']]]]));
}
file_put_contents($root.'/storage/fulfillment/vinyl-production.json',json_encode([
 'SF-0007'=>['status'=>'in_production','events'=>[['at'=>'2026-10-13T09:00:00Z','to'=>'artwork_approved','note'=>'PRIVATE'],['at'=>'2026-10-13T10:00:00Z','to'=>'in_production','note'=>'PRIVATE']]],
 'SF-0008'=>['status'=>'quality_check','events'=>[['at'=>'2026-10-13T10:00:00Z','to'=>'quality_check','note'=>'OTHER PRIVATE']]],
]));
class AuditDb {public array $keys=[];public function prepare(string $sql):AuditStmt{return new AuditStmt($this,$sql);}public function exec(string $sql):bool{return true;}}
class AuditStmt {
 private array $args=[];
 public function __construct(private AuditDb $db,private string $sql){}
 public function execute(array $args=[]):bool{$this->args=$args;
  if(str_starts_with($this->sql,'INSERT INTO notification_delivery_keys')){$key=$args[0].'|'.$args[1];if(isset($this->db->keys[$key]))throw new PDOException('duplicate',23000);$this->db->keys[$key]=true;}return true;
 }
 public function fetchColumn():string|false {
  if(str_contains($this->sql,'SELECT email FROM users'))return $this->args[0]===7?'owner@example.com':($this->args[0]===8?'other@example.com':false);
  return false;
 }
}
function sf_db():AuditDb {static $db=null;return $db??=new AuditDb();}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $s,int $max=120):string{return substr((string)$s,0,$max);}
$sent=[];
function sf_notify_user(int $uid,string $kind,string $title,string $body='',string $link=''):int{global $sent;$sent[]=compact('uid','kind','title','body','link');return count($sent);}
require __DIR__.'/../api/notification-core.php';
function assertNotice(bool $ok,string $label):void{if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
assertNotice(sf_notification_generate_vinyl_updates(7)===2,'Two owned production milestones');
assertNotice(sf_notification_generate_vinyl_updates(7)===0,'Repeat invocation does not redeliver');
assertNotice(count($sent)===2,'Exactly two delivery events');
assertNotice(sf_notification_generate_vinyl_updates(8)===1,'Second owner receives own milestone');
assertNotice(count($sent)===3,'No cross-owner deliveries');
assertNotice($sent[0]['kind']==='vinyl_production','Transactional production kind');
assertNotice(str_contains($sent[0]['link'],'?view=order&id=SF-0007'),'Order deep link');
assertNotice(!str_contains(json_encode($sent),'PRIVATE'),'Admin notes excluded');
assertNotice(sf_notification_generate_vinyl_updates(0)===0,'Unknown customer receives no messages');
foreach(glob($root.'/storage/orders/*.json') as $file)unlink($file);unlink($root.'/storage/fulfillment/vinyl-production.json');rmdir($root.'/storage/orders');rmdir($root.'/storage/fulfillment');rmdir($root.'/storage');rmdir($root);
echo "Section 62F notification runtime: 9/9 passed\n";
