<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/agent-runtime.php';
$pass=0;
$check=static function(bool $result,string $name)use(&$pass):void{
    if(!$result){fwrite(STDERR,"91B6 FAIL $name\n");exit(1);}
    echo "91B6 PASS $name\n";$pass++;
};
$catalog=[
    ['id'=>'subtle-surrender','title'=>'Subtle Surrender','release'=>'Happier Than Hay'],
    ['id'=>'genius-unhumankind','title'=>'Genius of Unhumankind','release'=>'Happier Than Hay'],
    ['id'=>'two-hands-up','title'=>'Two Hands Up','release'=>'Happier Than Hay'],
];
$message='play genius of on human kind';
$check(sf_agent_orchestrator_speech_key('Genius of Unhumankind')===sf_agent_orchestrator_speech_key('genius of on human kind'),'ASR phrase normalizes to real published title');
$check(sf_agent_orchestrator_title_score('genius of on human kind','Genius of Unhumankind')===1.,'Spoken title scores as exact phonetic identity');
$candidate=sf_agent_orchestrator_catalog_candidates($message,$catalog);
$check(($candidate[0]['id']??'')==='genius-unhumankind','Published candidate ranked first');
$good=sf_agent_orchestrator_validate('{"route":"player_play_named","track_id":"genius-unhumankind"}',$catalog,false,$message,'subtle-surrender');
$check(($good['verified_track']['title']??'')==='Genius of Unhumankind','LLM-selected published ID is verified');
$check(($good['resolution']??'')==='llm_catalog','Verified receipt is marked for browser cross-check');
$bad=sf_agent_orchestrator_validate('{"route":"player_play_named","track_id":"subtle-surrender"}',$catalog,false,$message,'subtle-surrender');
$check(($bad['track_id']??'')===''&&($bad['verified_track']??null)===null,'Active track cannot substitute for requested song');
$missing=sf_agent_orchestrator_validate('{"route":"player_play_named","track_id":"private-master"}',$catalog,false,$message,'subtle-surrender');
$check(($missing['resolution']??'')==='none','Private or nonexistent catalog ID is refused');
$ambiguous=array_merge($catalog,[['id'=>'genius-alt','title'=>'Genius of Unhuman Kind','release'=>'Alternate']]);
$ambiguousResult=sf_agent_orchestrator_validate('{"route":"player_play_named","track_id":"genius-unhumankind"}',$ambiguous,false,'play genius of on human kind');
$check(($ambiguousResult['resolution']??'')==='none','Near-duplicate published song versions require clarification');
$check(sf_agent_orchestrator_validate('{"route":"admin_campaign_overview"}',$catalog,false,'show private CRM')===null,'Public agent cannot access Admin route');
$check(sf_agent_orchestrator_validate('{"route":"admin_campaign_overview"}',$catalog,true,'show campaign dashboard')['route']==='admin_campaign_overview','Admin can access Admin route through policy');
$check(sf_agent_orchestrator_validate('{"route":"campaign_send_email"}',$catalog,true,'send blast')===null,'LLM cannot invent a consequential executor route');
$check(sf_agent_orchestrator_validate('{"route":"ticketing_info"}',$catalog,false,'what tickets are available')['route']==='ticketing_info','Ticket routes share the LLM interpreter');
$check(sf_agent_orchestrator_validate('{"route":"store_browse"}',$catalog,false,'show vinyl records and merch')['route']==='store_browse','Merchandise and vinyl routes share interpreter');
$check(sf_agent_orchestrator_validate('{"route":"purchase_request"}',$catalog,false,'buy this record')['route']==='purchase_request','Purchase intent remains governed by server policy');
$check(sf_agent_orchestrator_validate('not JSON',$catalog,false,$message)===null,'Malformed provider output cannot authorize anything');
$check(sf_agent_orchestrator_validate('{"route":"open_any_url","url":"https://example.org"}',$catalog,false,$message)===null,'Model cannot invent arbitrary browser tools');
$deictic=sf_agent_orchestrator_validate('{"route":"player_play_named","track_id":"genius-unhumankind"}',$catalog,false,'play this song','subtle-surrender');
$check(($deictic['resolution']??'')==='none','Deictic playback cannot switch to unrelated track');
$deicticOk=sf_agent_orchestrator_validate('{"route":"player_play_named","track_id":"subtle-surrender"}',$catalog,false,'play this song','subtle-surrender');
$check(($deicticOk['resolution']??'')==='llm_catalog','Explicit current-song reference resolves only actual current song');
echo "Section 91B6: $pass LLM-first interpretation and authorization assertions passed\n";
