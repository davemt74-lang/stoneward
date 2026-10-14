<?php
declare(strict_types=1);
/** Section 82: marketing consent survives account merges and late opt-outs. */
$root=dirname(__DIR__);
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT)');
foreach([7,8,9,10] as $id)$db->prepare('INSERT INTO users(id,email) VALUES(?,?)')->execute([$id,'user'.$id.'@example.test']);
function sf_db():PDO {global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
require_once $root.'/api/crm-core.php';
require_once $root.'/api/campaign-core.php';
$pass=0;$fail=0;
function c82(bool $yes,string $name):void {global $pass,$fail;$yes?$pass++:$fail++;echo ($yes?'PASS ':'FAIL ').$name.PHP_EOL;}
function add82(string $email,?int $uid,bool $opt=false,?string $unsub=null):array {
    $row=sf_crm_upsert_contact($email,'Fan','test',$uid,$opt,'fan');
    sf_db()->prepare('UPDATE fan_contacts SET marketing_opt_in=?,unsubscribed_at=?,marketing_opt_in_at=? WHERE id=?')
      ->execute([$opt?1:0,$unsub,$opt?'2026-01-01T00:00:00+00:00':null,$row['id']]);
    return sf_crm_contact_by_id((int)$row['id']);
}
sf_crm_ensure_schema();
$opt=['marketing_opt_in'=>1,'marketing_opt_in_at'=>'2026-01-01T00:00:00+00:00','unsubscribed_at'=>null];
$unsub=['marketing_opt_in'=>0,'unsubscribed_at'=>'2026-10-01T00:00:00+00:00'];
c82(sf_crm_merge_consent($opt,$unsub)['opt_in']===0,'An unsubscribed secondary contact blocks implicit marketing opt-in');
c82(sf_crm_merge_consent($unsub,$opt)['opt_in']===0,'An unsubscribed primary contact blocks implicit marketing opt-in');
c82(sf_crm_merge_consent($opt,$opt)['opt_in']===1,'Two opted-in identities preserve consent');
c82(sf_crm_merge_consent([],[])['opt_in']===0,'Missing consent never defaults to opt-in');
c82(sf_crm_merge_consent($unsub,$opt,true)['opt_in']===1,'Explicit new newsletter signup may resubscribe');
c82(sf_crm_merge_consent($opt,$opt,false)['opt_in']===0,'Explicit unsubscribe remains authoritative');
$old=add82('account7@example.test',7,true);
$lead=add82('claimed7@example.test',null,false,'2026-10-01T00:00:00+00:00');
sf_db()->prepare('UPDATE fan_contacts SET unsubscribe_token_hash=? WHERE id=?')->execute([str_repeat('a',64),$old['id']]);
$merged=sf_crm_upsert_contact('claimed7@example.test','Merged','account',7,null,'fan');
c82((int)$merged['id']===(int)$old['id'],'Account identity remains stable during contact merge');
c82((int)$merged['marketing_opt_in']===0&&!empty($merged['unsubscribed_at']),'Lead unsubscribe survives merging into opted-in account');
c82(empty($merged['unsubscribe_token_hash']),'Merged unsubscribe invalidates previous marketing token');
c82(sf_crm_contact_by_id((int)$lead['id'])===null,'Duplicate contact deleted after safe merge');
add82('account8@example.test',8,false,'2026-10-02T00:00:00+00:00');
add82('claimed8@example.test',null,true);
$merged2=sf_crm_upsert_contact('claimed8@example.test','Merged','account',8,null,'fan');
c82((int)$merged2['marketing_opt_in']===0&&!empty($merged2['unsubscribed_at']),'Account unsubscribe wins against subscribed lead');
add82('account9@example.test',9,false,'2026-10-02T00:00:00+00:00');
add82('claimed9@example.test',null,true);
$merged3=sf_crm_upsert_contact('claimed9@example.test','Newsletter','newsletter',9,true,'fan');
c82((int)$merged3['marketing_opt_in']===1&&empty($merged3['unsubscribed_at']),'Explicit consent after merge re-enables newsletter');
$before=['id'=>10,'email'=>'fan10@example.test','marketing_opt_in'=>1,'unsubscribed_at'=>null];
$current=$before;
c82(sf_campaign_send_recipient_allowed($before,$current),'Fresh unchanged opted-in recipient is eligible');
$current['unsubscribed_at']='2026-10-14T10:00:00+00:00';
c82(!sf_campaign_send_recipient_allowed($before,$current),'Opt-out after campaign audience snapshot blocks dispatch');
$current=$before;$current['marketing_opt_in']=0;
c82(!sf_campaign_send_recipient_allowed($before,$current),'Revoked opt-in blocks dispatch');
$current=$before;$current['email']='new-address@example.test';
c82(!sf_campaign_send_recipient_allowed($before,$current),'Changed email requires fresh campaign approval');
$current=$before;$current['id']=11;
c82(!sf_campaign_send_recipient_allowed($before,$current),'Changed CRM identity blocks dispatch');
c82(!sf_campaign_send_recipient_allowed($before,null),'Deleted contact cannot receive outreach');
$current=$before;$current['email']='bad';
c82(!sf_campaign_send_recipient_allowed($before,$current),'Invalid current address blocks dispatch');
$source=file_get_contents($root.'/api/campaign-core.php');
c82(str_contains($source,'sf_crm_contact_by_id((int)($c[\'id\']??0))')
  &&str_contains($source,'sf_campaign_send_recipient_allowed($c,$current)'),
  'Campaign delivery re-reads live consent for every recipient');
c82(str_contains($source,'skipped_opt_out_or_changed')&&str_contains($source,'$skipped++'),
  'Excluded contacts are counted separately from failed provider delivery');
$automation=file_get_contents($root.'/api/automation-core.php');
c82(str_contains($automation,"sf_crm_contact_by_id($contactId)")&&str_contains($automation,"empty($contact['unsubscribed_at'])"),
  'Existing lifecycle email automation continues to read authoritative live consent');
c82(str_contains($root.'/api/newsletter.php','newsletter.php')&&str_contains(file_get_contents($root.'/api/newsletter.php'),"action==='unsubscribe'"),
  'Public unsubscribe flow remains reachable');
echo 'Section 82 consent and outreach gates: '.$pass.'/'.($pass+$fail).' passed'.PHP_EOL;
exit($fail?1:0);
