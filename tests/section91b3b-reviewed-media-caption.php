<?php
declare(strict_types=1);
/** Real isolated SQLite asset + production Agent proposal ledger. */
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users(id) VALUES(1),(2)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
require dirname(__DIR__).'/api/media-core.php';
require dirname(__DIR__).'/api/agent-actions-core.php';
require dirname(__DIR__).'/api/agent-orchestration.php';
sf_media_ensure_schema();
$db->exec("INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,title,caption,technical_json,variants_json,created_at,updated_at)
 VALUES('MTESTONE','image','image/jpeg','jpg','Artist Portrait.jpg','storage/media/private.jpg','abc','Artist Portrait','Original caption','{}','{}','2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,download_allowed,created_at,updated_at)
 VALUES(1,'photo_gallery','gallery-1','cover',1,0,'2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,download_allowed,created_at,updated_at)
 VALUES(1,'track','song-1','artwork',0,0,'2026-10-01','2026-10-01')");
$passes=0;$fails=0;
$check=static function(bool $ok,string $label)use(&$passes,&$fails):void{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if($ok)$passes++;else $fails++;
};
$reject=static function(callable $call):bool{try{$call();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}};
$prompt='Set caption for media asset #1 to A reviewed portrait caption.';
$parsed=sf_agent_media_parse_caption($prompt);
$check(($parsed['asset_id']??null)===1&&($parsed['value']??null)==='A reviewed portrait caption.','Exact media asset ID and caption parsed');
$check(sf_agent_media_parse_caption('Show Media Library assets')===null,'Read intent not classified as write');
$check(sf_agent_media_parse_caption('Delete media asset 1')===null,'Delete intent not classified as caption');
$check(sf_agent_media_parse_caption('Publish media asset 1')===null,'Publish intent not classified as caption');
$draft=sf_agent_media_draft_from_chat($prompt);
$check(($draft['asset_id']??null)===1&&$draft['previous']==='Original caption'&&$draft['value']==='A reviewed portrait caption.','Real existing Media Library record resolved');
$check($draft['attachment_count']===2&&$draft['public_link_count']===1,'Shared attachment and public visibility counts explicit');
$check($reject(fn()=>sf_agent_media_normalize_caption($draft+['public_visible'=>true],sf_media_asset(1),sf_agent_media_links(1))),'Unknown writable field rejected');
$check($reject(fn()=>sf_agent_media_normalize_caption(array_replace($draft,['field'=>'public_visible']),sf_media_asset(1),sf_agent_media_links(1))),'Visibility modification rejected');
$check($reject(fn()=>sf_agent_media_normalize_caption(array_replace($draft,['asset_id'=>2]),sf_media_asset(1),sf_agent_media_links(1))),'Different asset rejected');
$check($reject(fn()=>sf_agent_media_normalize_caption(array_replace($draft,['value'=>str_repeat('X',501)]),sf_media_asset(1),sf_agent_media_links(1))),'Oversized caption rejected');
$check($reject(fn()=>sf_agent_media_normalize_caption(array_replace($draft,['expected_revision'=>str_repeat('0',64)]),sf_media_asset(1),sf_agent_media_links(1))),'Forged asset revision rejected');
$check($reject(fn()=>sf_agent_media_normalize_caption(array_replace($draft,['expected_links_hash'=>str_repeat('0',64)]),sf_media_asset(1),sf_agent_media_links(1))),'Changed attachment digest rejected');
$check($reject(fn()=>sf_agent_media_draft_from_chat('Set caption for media asset 999 to Missing asset.')),'Missing asset rejected');
$check(sf_agent_tool_executable('media_caption_update','admin',true)&&!sf_agent_tool_executable('media_caption_update','admin',false)
    &&!sf_agent_tool_executable('media_caption_update','fan',true)&&!sf_agent_tool_executable('media_caption_update','guest',true),'Only approved Admin allowed to execute');
$contract=sf_agent_orchestration_contract('admin','edit_media_caption');
$check($contract['tool_id']==='media_caption_update'&&$contract['may_prepare_reviewed_proposal']
    &&$contract['requires_exact_approval']&&!$contract['may_execute_directly'],'Registered as owner-reviewed proposal only');
$check(!sf_agent_orchestration_contract('customer','edit_media_caption')['registered']
    &&!sf_agent_orchestration_contract('public','edit_media_caption')['registered'],'Customer/public cannot discover media caption executor');
$proposal=sf_agent_action_prepare(1,'media-reviewed-caption-request-001','media_caption_update',$draft);
$check($proposal['status']==='proposed'&&sf_media_asset(1)['caption']==='Original caption','Preparation makes no change');
$check($reject(fn()=>sf_agent_action_approve(1,$proposal['id'],$proposal['payload_hash'],'YES')),'Exact typed APPROVE required');
$check(sf_agent_action_get(2,$proposal['id'])===null,'Cross-admin proposal access denied');
sf_agent_action_approve(1,$proposal['id'],$proposal['payload_hash'],'APPROVE');
$check(sf_media_asset(1)['caption']==='Original caption','Approval alone never writes');
$done=sf_agent_action_execute(1,$proposal['id']);
$check($done['status']==='completed'&&$done['result']['asset_id']===1&&sf_media_asset(1)['caption']==='A reviewed portrait caption.','Separate execute saves and verifies caption');
$check((int)$db->query('SELECT COUNT(*) FROM media_links')->fetchColumn()===2
    &&(int)$db->query('SELECT SUM(public_visible) FROM media_links')->fetchColumn()===1,'Attachments, visibility and public link count unchanged');
$check(sf_media_asset(1)['stored_path']==='storage/media/private.jpg'&&sf_media_asset(1)['sha256']==='abc','Stored file location and content hash unchanged');
$same=sf_agent_action_execute(1,$proposal['id']);
$check($same['result']===$done['result'],'Completed replay returns original receipt without another save');
$fresh=sf_agent_media_draft_from_chat('Set caption for media asset 1 to A future caption.');
$stale=sf_agent_action_prepare(1,'media-reviewed-caption-request-002','media_caption_update',$fresh);
sf_agent_action_approve(1,$stale['id'],$stale['payload_hash'],'APPROVE');
$db->exec("UPDATE media_assets SET caption='Manual editor changed caption',updated_at='2026-10-28' WHERE id=1");
$check($reject(fn()=>sf_agent_action_execute(1,$stale['id'])),'Concurrent Admin caption edit blocks stale approval');
$check(sf_agent_action_get(1,$stale['id'])['status']==='review_required'&&sf_media_asset(1)['caption']==='Manual editor changed caption','Uncertain ledger state held for manual review, no overwrite');
$check($reject(fn()=>sf_agent_action_execute(1,$stale['id'])),'No automatic replay after interrupted execution');
$linksDraft=sf_agent_media_draft_from_chat('Set caption for media asset 1 to Caption after review.');
$linkProposal=sf_agent_action_prepare(1,'media-reviewed-caption-request-003','media_caption_update',$linksDraft);
sf_agent_action_approve(1,$linkProposal['id'],$linkProposal['payload_hash'],'APPROVE');
$db->exec("UPDATE media_links SET public_visible=0 WHERE id=1");
$check($reject(fn()=>sf_agent_action_normalize_media_caption($linksDraft)),'Attachment visibility changed after review blocks normalization');
$check($reject(fn()=>sf_agent_action_execute(1,$linkProposal['id'])),'Attachment change after approval blocks actual save');
$check(sf_agent_action_get(1,$linkProposal['id'])['status']==='review_required'&&sf_media_asset(1)['caption']==='Manual editor changed caption','Attachment drift leaves original caption and no-retry receipt');
echo 'Section 91B3B media integration: '.$passes.' passed, '.$fails.' failed'.PHP_EOL;
exit($fails?1:0);
