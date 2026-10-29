<?php
declare(strict_types=1);
/** Real isolated SQLite and source JSON; never touches a production record. */
$fixture=sys_get_temp_dir().'/sf-91b3d-'.bin2hex(random_bytes(6));
if(!mkdir($fixture.'/data',0700,true))throw new RuntimeException('Could not create fixture.');
define('SF_ROOT',$fixture);
$save=static function(string $name,array $rows)use($fixture):void{
    file_put_contents($fixture.'/data/'.$name.'.json',json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
};
$save('catalog',[['id'=>'track-1','title'=>'Live Song','status'=>'draft','public_visible'=>false]]);
$save('releases',[['id'=>'release-1','title'=>'Tour Album','state'=>'published','public_visible'=>true]]);
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
$db->exec("INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,title,technical_json,variants_json,created_at,updated_at)
 VALUES('LINKIMAGE1','image','image/jpeg','jpg','New.jpg','storage/media/new.jpg','new-sha','New Image','{}','{}','2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,title,technical_json,variants_json,created_at,updated_at)
 VALUES('LINKIMAGE2','image','image/jpeg','jpg','Old.jpg','storage/media/old.jpg','old-sha','Published Artwork','{}','{}','2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,title,technical_json,variants_json,created_at,updated_at)
 VALUES('LINKAUDIO','audio','audio/mpeg','mp3','Song.mp3','storage/media/song.mp3','audio-sha','Master Audio','{}','{}','2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,download_allowed,featured,created_at,updated_at)
 VALUES(1,'photo_gallery','gallery-1','cover',1,0,1,'2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,download_allowed,featured,created_at,updated_at)
 VALUES(2,'track','track-1','primary_artwork',1,0,1,'2026-10-01','2026-10-01')");
$db->exec("INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,download_allowed,featured,created_at,updated_at)
 VALUES(2,'release','release-1','cover',1,0,1,'2026-10-01','2026-10-01')");
$passes=0;$fails=0;
$check=static function(bool $ok,string $label)use(&$passes,&$fails):void{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if($ok)$passes++;else $fails++;
};
$reject=static function(callable $call):bool{try{$call();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}};
$prompt='Attach media asset #1 as artwork to song track-1';
$parsed=sf_agent_media_link_parse($prompt);
$check($parsed===['asset_id'=>1,'entity_type'=>'track','entity_id'=>'track-1','role'=>'artwork'],'Numeric asset + exact song ID parsed');
$check((sf_agent_media_link_parse('Attach media asset 1 as cover to release release-1')??[])['role']==='cover','Release cover grammar recognized');
$check(sf_agent_media_link_parse('Delete media asset 1')===null,'Delete is not attach');
$check(sf_agent_media_link_parse('Publish media asset 1')===null,'Publish is not attach');
$check(sf_agent_media_link_parse('Attach arbitrary URL as artwork to song track-1')===null,'External URL cannot become source');
$draft=sf_agent_media_link_draft_from_chat($prompt);
$check($draft['asset_id']===1&&$draft['entity_id']==='track-1'&&$draft['entity_title']==='Live Song','Existing track and asset resolved');
$check($draft['asset_link_count']===1&&$draft['asset_public_link_count']===1&&$draft['target_link_count']===1,'Preexisting public and target usage disclosed');
$check($draft['public_visible']===false&&$draft['download_allowed']===false&&$draft['featured']===false,'Exactly three private flags enforced');
$check($reject(fn()=>sf_agent_media_link_draft_from_chat('Attach media asset 3 as artwork to song track-1')),'Audio source prohibited');
$check($reject(fn()=>sf_agent_media_link_draft_from_chat('Attach media asset 1 as artwork to song fake-song')),'Unknown song prohibited');
$check($reject(fn()=>sf_agent_media_link_draft_from_chat('Attach media asset 1 as cover to song track-1')),'Wrong role for song prohibited');
$check($reject(fn()=>sf_agent_media_link_draft_from_chat('Attach media asset 1 as artwork to release release-1')),'Wrong role for release prohibited');
$check($reject(fn()=>sf_agent_action_normalize_media_private_link($draft+['visible'=>true])),'Unexpected payload fields denied');
$check($reject(fn()=>sf_agent_action_normalize_media_private_link(array_replace($draft,['public_visible'=>true]))),'Exposure escalation denied');
$check($reject(fn()=>sf_agent_action_normalize_media_private_link(array_replace($draft,['featured'=>1]))),'Featured escalation denied');
$check($reject(fn()=>sf_agent_action_normalize_media_private_link(array_replace($draft,['download_allowed'=>1]))),'Download escalation denied');
$check($reject(fn()=>sf_agent_action_normalize_media_private_link(array_replace($draft,['entity_id'=>'release-1']))),'Target swap denied');
$check($reject(fn()=>sf_agent_action_normalize_media_private_link(array_replace($draft,['expected_asset_links_hash'=>str_repeat('0',64)]))),'Image usage digest tampering denied');
$check($reject(fn()=>sf_agent_action_normalize_media_private_link(array_replace($draft,['expected_target_source_hash'=>str_repeat('0',64)]))),'Source digest tampering denied');
$check($reject(fn()=>sf_agent_action_normalize_media_private_link(array_replace($draft,['expected_target_links_hash'=>str_repeat('0',64)]))),'Target attachments digest tampering denied');
$check(sf_agent_tool_executable('media_private_link_add','admin',true)
    &&!sf_agent_tool_executable('media_private_link_add','admin',false)
    &&!sf_agent_tool_executable('media_private_link_add','fan',true)
    &&!sf_agent_tool_executable('media_private_link_add','guest',true),'Only approved Admin can execute');
$contract=sf_agent_orchestration_contract('admin','attach_private_media');
$check($contract['tool_id']==='media_private_link_add'&&$contract['may_prepare_reviewed_proposal']
    &&$contract['requires_exact_approval']&&!$contract['may_execute_directly'],'Owner-reviewed contract, never direct execution');
$check(!sf_agent_orchestration_contract('customer','attach_private_media')['registered']
    &&!sf_agent_orchestration_contract('public','attach_private_media')['registered'],'Public and customer cannot discover writer');
$proposal=sf_agent_action_prepare(1,'media-private-91b3d-track-request-001','media_private_link_add',$draft);
$check($proposal['status']==='proposed'&&count(sf_agent_media_link_target_links('track','track-1'))===1,'Prepare makes no DB attachment');
$check($reject(fn()=>sf_agent_action_approve(1,$proposal['id'],$proposal['payload_hash'],'yes')),'Exact APPROVE text required');
$check(sf_agent_action_get(2,$proposal['id'])===null,'Cross-admin proposal access denied');
sf_agent_action_approve(1,$proposal['id'],$proposal['payload_hash'],'APPROVE');
$check(count(sf_agent_media_link_target_links('track','track-1'))===1,'Approval alone never links artwork');
$done=sf_agent_action_execute(1,$proposal['id']);
$check($done['status']==='completed'&&$done['result']['role']==='artwork'
    &&$done['result']['public_visible']===false,'Save produces private-link receipt');
$row=$db->query("SELECT * FROM media_links WHERE asset_id=1 AND entity_type='track' AND entity_id='track-1'")->fetch(PDO::FETCH_ASSOC);
$check((int)$row['public_visible']===0&&(int)$row['featured']===0&&(int)$row['download_allowed']===0,'Saved attachment is strictly private');
$check(count(sf_agent_media_link_target_links('track','track-1'))===2
    &&(int)$db->query("SELECT COUNT(*) FROM media_links WHERE entity_type='track' AND entity_id='track-1' AND role='primary_artwork' AND public_visible=1")->fetchColumn()===1,'Existing public primary artwork is unchanged');
$check(sf_media_asset(1)['sha256']==='new-sha'&&sf_media_asset(1)['stored_path']==='storage/media/new.jpg','Image content and location unchanged');
$check(sf_agent_action_execute(1,$proposal['id'])['result']===$done['result']
    &&(int)$db->query("SELECT COUNT(*) FROM media_links WHERE asset_id=1 AND entity_type='track'")->fetchColumn()===1,'Completed action replays receipt only');
$check($reject(fn()=>sf_agent_media_link_draft_from_chat($prompt)),'Duplicate same-target artwork prohibited');
$releaseDraft=sf_agent_media_link_draft_from_chat('Attach media asset 1 as cover to release release-1');
$releaseProposal=sf_agent_action_prepare(1,'media-private-91b3d-release-request-001','media_private_link_add',$releaseDraft);
sf_agent_action_approve(1,$releaseProposal['id'],$releaseProposal['payload_hash'],'APPROVE');
$releaseDone=sf_agent_action_execute(1,$releaseProposal['id']);
$check($releaseDone['status']==='completed'&&$releaseDone['result']['entity_type']==='release'
    &&$releaseDone['result']['public_visible']===false,'Private release cover linked and verified');
$check((int)$db->query("SELECT COUNT(*) FROM media_links WHERE entity_type='release' AND role='cover' AND public_visible=1")->fetchColumn()===1
    &&str_contains((string)file_get_contents($fixture.'/data/releases.json'),'Tour Album'),'Published release cover and source unchanged');
$db->exec("INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,title,technical_json,variants_json,created_at,updated_at)
 VALUES('LINKIMAGE4','image','image/jpeg','jpg','Candidate.jpg','storage/media/candidate.jpg','candidate-sha','Candidate','{}','{}','2026-10-01','2026-10-01')");
$catalogRows=[['id'=>'track-1','title'=>'Live Song'],['id'=>'track-2','title'=>'Second Song'],
    ['id'=>'track-3','title'=>'Third Song'],['id'=>'track-4','title'=>'Fourth Song'],
    ['id'=>'track-5','title'=>'Fifth Song']];
$save('catalog',$catalogRows);
$staleDraft=sf_agent_media_link_draft_from_chat('Attach media asset 4 as artwork to song track-2');
$stale=sf_agent_action_prepare(1,'media-private-91b3d-stale-asset-002','media_private_link_add',$staleDraft);
sf_agent_action_approve(1,$stale['id'],$stale['payload_hash'],'APPROVE');
$db->exec("UPDATE media_assets SET updated_at='2026-10-29' WHERE id=4");
$check($reject(fn()=>sf_agent_action_execute(1,$stale['id'])),'Concurrent image change refuses approved attachment');
$check(sf_agent_action_get(1,$stale['id'])['status']==='review_required'
    &&count(sf_agent_media_link_target_links('track','track-2'))===0,'Image drift is review-required, no link');
$check($reject(fn()=>sf_agent_action_execute(1,$stale['id'])),'Uncertain execution is never automatically retried');
$targetDraft=sf_agent_media_link_draft_from_chat('Attach media asset 4 as artwork to song track-3');
$targetProposal=sf_agent_action_prepare(1,'media-private-91b3d-stale-target-003','media_private_link_add',$targetDraft);
sf_agent_action_approve(1,$targetProposal['id'],$targetProposal['payload_hash'],'APPROVE');
$catalogRows[2]['title']='Third Song Updated';$save('catalog',$catalogRows);
$check($reject(fn()=>sf_agent_action_execute(1,$targetProposal['id'])),'Edited song source invalidates target review');
$check(sf_agent_action_get(1,$targetProposal['id'])['status']==='review_required'
    &&count(sf_agent_media_link_target_links('track','track-3'))===0,'Target drift cannot leave a link');
$linksDraft=sf_agent_media_link_draft_from_chat('Attach media asset 4 as artwork to song track-4');
$linksProposal=sf_agent_action_prepare(1,'media-private-91b3d-stale-links-004','media_private_link_add',$linksDraft);
sf_agent_action_approve(1,$linksProposal['id'],$linksProposal['payload_hash'],'APPROVE');
$db->exec("INSERT INTO media_links(asset_id,entity_type,entity_id,role,created_at,updated_at)
 VALUES(4,'photo_gallery','other-gallery','photo','2026-10-29','2026-10-29')");
$check($reject(fn()=>sf_agent_action_execute(1,$linksProposal['id'])),'Image usage change invalidates review');
$check(sf_agent_action_get(1,$linksProposal['id'])['status']==='review_required'
    &&count(sf_agent_media_link_target_links('track','track-4'))===0,'Image link drift never creates target link');
$destDraft=sf_agent_media_link_draft_from_chat('Attach media asset 4 as artwork to song track-5');
$destProposal=sf_agent_action_prepare(1,'media-private-91b3d-stale-dest-005','media_private_link_add',$destDraft);
sf_agent_action_approve(1,$destProposal['id'],$destProposal['payload_hash'],'APPROVE');
$db->exec("INSERT INTO media_links(asset_id,entity_type,entity_id,role,created_at,updated_at)
 VALUES(2,'track','track-5','artwork','2026-10-29','2026-10-29')");
$check($reject(fn()=>sf_agent_action_execute(1,$destProposal['id'])),'Target attachment changes invalidate review');
$check(sf_agent_action_get(1,$destProposal['id'])['status']==='review_required'
    &&(int)$db->query("SELECT COUNT(*) FROM media_links WHERE asset_id=4 AND entity_type='track' AND entity_id='track-5'")->fetchColumn()===0,'Destination drift cannot attach reviewed asset');
@unlink($fixture.'/data/catalog.json');@unlink($fixture.'/data/releases.json');
@rmdir($fixture.'/data');@rmdir($fixture);
echo 'Section 91B3D private media integration: '.$passes.' passed, '.$fails.' failed'.PHP_EOL;
exit($fails?1:0);
