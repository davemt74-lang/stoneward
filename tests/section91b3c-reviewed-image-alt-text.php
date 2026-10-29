<?php
declare(strict_types=1);
/** Section 91B3C: real SQLite image + shared ledger; no production data. */
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
$db->exec("INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,title,caption,alt_text,technical_json,variants_json,created_at,updated_at)
 VALUES('CALTIMAGE','image','image/jpeg','jpg','Tour.jpg','storage/media/original.jpg','sha-original','Tour Portrait','Caption stays here','Old alt','{}','{}','2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,title,technical_json,variants_json,created_at,updated_at)
 VALUES('CALTSONG','audio','audio/mpeg','mp3','Song.mp3','storage/media/song.mp3','sha-audio','Song','{}','{}','2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,download_allowed,created_at,updated_at)
 VALUES(1,'photo_gallery','gallery-1','cover',1,0,'2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,download_allowed,created_at,updated_at)
 VALUES(1,'track','track-1','artwork',0,0,'2026-10-01','2026-10-01')");
$passes=0;$fails=0;
$check=static function(bool $ok,string $label)use(&$passes,&$fails):void{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if($ok)$passes++;else $fails++;
};
$reject=static function(callable $call):bool{try{$call();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}};
$prompt='Set alt text for media asset #1 to A singer under blue stage lighting.';
$parsed=sf_agent_media_parse_alt_text($prompt);
$check(($parsed['asset_id']??null)===1&&($parsed['value']??'')==='A singer under blue stage lighting.','Alt-text command resolves exact numeric ID');
$check((sf_agent_media_parse_alt_text('Change alternative text on media asset 1 to A new photo')??[])['asset_id']===1,'Alternative text phrasing supported');
$check(sf_agent_media_parse_alt_text('Show media assets')===null,'Read query is not a write');
$check(sf_agent_media_parse_alt_text('Delete media asset 1')===null,'Delete request is not alt-text write');
$check(sf_agent_media_parse_alt_text('Publish media asset 1')===null,'Publish request is not alt-text write');
$check(sf_agent_media_parse_alt_text('Set caption for media asset 1 to caption')===null,'Caption edits remain separate');
$draft=sf_agent_media_alt_draft_from_chat($prompt);
$check($draft['asset_id']===1&&$draft['field']==='alt_text'&&$draft['previous']==='Old alt','Real existing image field read');
$check($draft['attachment_count']===2&&$draft['public_link_count']===1,'Public impact and all attachments exposed');
$check($reject(fn()=>sf_agent_media_alt_draft_from_chat('Set alt text for media asset 2 to Sound waves')),'Non-image media rejected');
$check($reject(fn()=>sf_agent_media_alt_draft_from_chat('Set alt text for media asset 999 to Missing')),'Missing image rejected');
$asset=sf_media_asset(1);$links=sf_agent_media_links(1);
$norm=static fn(array $p)=>sf_agent_media_normalize_alt_text($p,$asset,$links);
$check($reject(fn()=>$norm($draft+['public_visible'=>true])),'Payload field injection rejected');
$check($reject(fn()=>$norm(array_replace($draft,['field'=>'caption']))),'Caption field substitution rejected');
$check($reject(fn()=>$norm(array_replace($draft,['asset_id'=>2]))),'Asset substitution rejected');
$check($reject(fn()=>$norm(array_replace($draft,['value'=>str_repeat('x',501)]))),'Oversized alt text rejected');
$check($reject(fn()=>$norm(array_replace($draft,['value'=>'']))),'Empty alt text rejected');
$check($reject(fn()=>$norm(array_replace($draft,['value'=>'Old alt']))),'Unchanged alt text rejected');
$check($reject(fn()=>$norm(array_replace($draft,['value'=>['array']]))),'Non-text payload rejected');
$check($reject(fn()=>$norm(array_replace($draft,['expected_revision'=>str_repeat('0',64)]))),'Forged image revision rejected');
$check($reject(fn()=>$norm(array_replace($draft,['expected_links_hash'=>str_repeat('0',64)]))),'Forged attachment revision rejected');
$check(sf_agent_tool_executable('media_alt_text_update','admin',true)
   &&!sf_agent_tool_executable('media_alt_text_update','admin',false)
   &&!sf_agent_tool_executable('media_alt_text_update','fan',true)
   &&!sf_agent_tool_executable('media_alt_text_update','guest',true),'Only separately approved Admin can execute');
$contract=sf_agent_orchestration_contract('admin','edit_media_alt_text');
$check($contract['tool_id']==='media_alt_text_update'&&$contract['may_prepare_reviewed_proposal']
   &&$contract['requires_exact_approval']&&!$contract['may_execute_directly'],'Admin skill is exact review only');
$check(!sf_agent_orchestration_contract('customer','edit_media_alt_text')['registered']
    &&!sf_agent_orchestration_contract('public','edit_media_alt_text')['registered'],'Public/customer cannot discover writer');
$proposal=sf_agent_action_prepare(1,'media-alt-text-91b3c-request-001','media_alt_text_update',$draft);
$check($proposal['status']==='proposed'&&sf_media_asset(1)['alt_text']==='Old alt','Preparation has no write');
$check($reject(fn()=>sf_agent_action_approve(1,$proposal['id'],$proposal['payload_hash'],'YES')),'Approval must be exact typed APPROVE');
$check(sf_agent_action_get(2,$proposal['id'])===null,'Cross-Admin proposal inaccessible');
sf_agent_action_approve(1,$proposal['id'],$proposal['payload_hash'],'APPROVE');
$check(sf_media_asset(1)['alt_text']==='Old alt','Approval does not perform a save');
$done=sf_agent_action_execute(1,$proposal['id']);
$check($done['status']==='completed'&&$done['result']['field']==='alt_text'
    &&sf_media_asset(1)['alt_text']==='A singer under blue stage lighting.','Separate save actually persists alt text');
$check(sf_media_asset(1)['caption']==='Caption stays here','Caption is unchanged');
$check((int)$db->query('SELECT COUNT(*) FROM media_links')->fetchColumn()===2
    &&(int)$db->query('SELECT SUM(public_visible) FROM media_links')->fetchColumn()===1,'Attachments and public visibility unchanged');
$check(sf_media_asset(1)['stored_path']==='storage/media/original.jpg'
    &&sf_media_asset(1)['sha256']==='sha-original','File location and bytes fingerprint unchanged');
$check(sf_agent_action_execute(1,$proposal['id'])['result']===$done['result'],'Completed replay returns original receipt');
$fresh=sf_agent_media_alt_draft_from_chat('Set alt text for media asset 1 to Another description.');
$stale=sf_agent_action_prepare(1,'media-alt-text-91b3c-request-002','media_alt_text_update',$fresh);
sf_agent_action_approve(1,$stale['id'],$stale['payload_hash'],'APPROVE');
$db->exec("UPDATE media_assets SET alt_text='Manual editor text',updated_at='2026-10-29' WHERE id=1");
$check($reject(fn()=>sf_agent_action_execute(1,$stale['id'])),'Concurrent metadata edit blocks stale save');
$check(sf_agent_action_get(1,$stale['id'])['status']==='review_required'
    &&sf_media_asset(1)['alt_text']==='Manual editor text','Stale edit is held for review, not overwritten');
$check($reject(fn()=>sf_agent_action_execute(1,$stale['id'])),'Uncertain execution cannot retry');
$linkDraft=sf_agent_media_alt_draft_from_chat('Set alt text for media asset 1 to New public image label.');
$linkProposal=sf_agent_action_prepare(1,'media-alt-text-91b3c-request-003','media_alt_text_update',$linkDraft);
sf_agent_action_approve(1,$linkProposal['id'],$linkProposal['payload_hash'],'APPROVE');
$db->exec('UPDATE media_links SET public_visible=0 WHERE id=1');
$check($reject(fn()=>sf_agent_action_normalize_media_alt_text($linkDraft)),'Visibility changes invalidate review hash');
$check($reject(fn()=>sf_agent_action_execute(1,$linkProposal['id'])),'Changed attachment state blocks save');
$check(sf_agent_action_get(1,$linkProposal['id'])['status']==='review_required'
    &&sf_media_asset(1)['alt_text']==='Manual editor text','Attachment drift retains old image text');
echo 'Section 91B3C alt text integration: '.$passes.' passed, '.$fails.' failed'.PHP_EOL;
exit($fails?1:0);
