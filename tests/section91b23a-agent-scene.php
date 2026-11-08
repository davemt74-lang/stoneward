<?php
declare(strict_types=1);

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON; CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT NOT NULL); INSERT INTO users VALUES(1,'active'),(2,'active'),(3,'active'),(4,'active')");
$providerCalls=0;$prompt=null;$mode='success';$checks=0;

function sf_db():PDO {return $GLOBALS['db'];}
function sf_db_config():array {return ['driver'=>'sqlite'];}
function sf_ai_meta_get(string $key,string $default=''):string {return $default;}
function sf_clean_text(mixed $v,int $max=120):string {return substr(trim((string)$v),0,$max);}
function sf_public_catalog():array {return [
    ['id'=>'song-one','title'=>'Song & <Title>','release'=>'First Release','duration'=>120,'metadata'=>['words_by'=>'Artist','private'=>'secret note']],
    ['id'=>'song-two','title'=>'Second Song','duration'=>110]];}
function sf_seo_release_rows():array {return [];}
function sf_media_public_links(string $type,string $id):array {return $type==='track'&&$id==='song-one'?[['category'=>'image','role'=>'primary_artwork','uuid'=>'MABCDEFG1234567']]:[];}
function sf_media_managed_url(array $asset,string $variant):string {return 'api/media.php?id='.$asset['uuid'].'&variant='.$variant;}
function sf_story_get(int $id,bool $public):?array {return $id===50&&$public?['id'=>50,'slug'=>'song-story','title'=>'Artist story','summary'=>'Published story summary','entity_type'=>'track','entity_id'=>'song-one']:null;}
function sf_ai_llm_candidates(string $workload):array {return [['provider'=>'openai','model'=>'fixture']];}
function sf_openai_call(array $provider,string $system,array $history,string $message,int $maxOutput,int $timeout):array {
    global $db,$providerCalls,$prompt,$mode,$providerTimeout,$providerSystem,$playerCodeAttempts,$repairAttempts;
    ++$providerCalls;$prompt=json_decode($message,true,8,JSON_THROW_ON_ERROR);
    $providerTimeout=$timeout;$providerSystem=$system;
    if($mode==='network_error')throw new RuntimeException('Fixture provider unavailable.');
    if($mode==='clear_history'){
        sf_agent_scene_history_clear(1);
        $db->exec("INSERT INTO user_privacy_events(user_id,event_type,created_at) VALUES(1,'agent_history_cleared','now')");
    }
    if($mode==='repair_markup')$repairAttempts[]=['system'=>$system,'prompt'=>$prompt,'limit'=>$maxOutput];
    $invalidMarkup=$mode==='invalid_markup'||($mode==='repair_markup'&&count($repairAttempts)===1);
    $html=$invalidMarkup?'<script>alert(1)</script>':'<article class="room"><h1>{{title}}</h1><p>{{story_summary}}</p></article>';
    $css='.room { color: #eee; }';
    if(str_starts_with($mode,'player_')){
        $playerCodeAttempts[]=['system'=>$system,'prompt'=>$prompt,'limit'=>$maxOutput];
        $html='<article class="deck"><div class="speaker"></div></article>';
        $css='.deck{display:grid;max-width:36vw}.speaker{border-radius:50%;transform:scale(calc(1 + var(--sf-audio-bass, 0) * .1))}'
            .'@media(max-width:620px){.deck{max-width:80vw}}';
    }
    $design=['html'=>$html,'css'=>$css];
    if($mode==='player_js')$design['js']='const button = document.createElement("button"); button.textContent = "Play"; button.addEventListener("click", () => StonefellowAudio.play()); document.querySelector(".deck").append(button);';
    if($mode==='library_repair'){
        $three=array_column($prompt['canvas_libraries'],null,'id')['three']['lock'];
        $lock=str_contains($system,'previous JavaScript declared')?$three:str_replace('0.187.0','0.188.0',$three);
        $design=['html'=>'<section class="art"></section>','css'=>'','js'=>"// sf-libraries: ".$lock."\nconst stage = new THREE.Scene();"];
    }
    if($mode==='d3'){
        $pin=array_column($prompt['canvas_libraries'],null,'id')['d3']['lock'];
        $design=['html'=>'<section class="chart"></section>','css'=>'','js'=>"// sf-libraries: ".$pin."\n"
            .'d3.select(".chart").text(d3.sum([1,2,3]));'];
    }
    if($mode==='matter'){
        $pin=array_column($prompt['canvas_libraries'],null,'id')['matter']['lock'];
        $design=['html'=>'<section class="game"></section>','css'=>'','js'=>"// sf-libraries: ".$pin."\n"
            .'const engine = Matter.Engine.create();'];
    }
    if($mode==='anime'){
        $pin=array_column($prompt['canvas_libraries'],null,'id')['anime']['lock'];
        $design=['html'=>'<section class="motion"></section>','css'=>'','js'=>"// sf-libraries: ".$pin."\n"
            .'const timeline = anime.createTimeline({autoplay:false});'];
    }
    if($mode==='pixi'){
        $pin=array_column($prompt['canvas_libraries'],null,'id')['pixi']['lock'];
        $design=['html'=>'<section class="game"></section>','css'=>'','js'=>"// sf-libraries: ".$pin."\n"
            .'const app = new PIXI.Application();'];
    }
    if($mode==='meyda'){
        $pin=array_column($prompt['canvas_libraries'],null,'id')['meyda']['lock'];
        $design=['html'=>'<section class="visualizer"></section>','css'=>'','js'=>"// sf-libraries: ".$pin."\n"
            .'document.querySelector(".visualizer").textContent = Meyda.extract("rms", new Float32Array(512));'];
    }
    if($mode==='generate_image')$design=['html'=>'<figure class="sf-image-new"></figure>',
        'css'=>'figure{background-image:var(--sf-image-new,none)}','image_request'=>[
            'prompt'=>'Rain on a glass greenhouse at dusk','title'=>'Glass Greenhouse','size'=>'1536x1024']];
    if($mode==='generate_batch'){
        $batchPlan=['prompt'=>'Rain on a glass greenhouse at dusk','title'=>'Glass Greenhouse','size'=>'1536x1024'];
        $design=['html'=>'<figure class="sf-image-new-1"></figure><figure class="sf-image-new-2"></figure><figure class="sf-image-new-3"></figure>',
            'css'=>'','image_requests'=>array_fill(0,str_contains($system,'Match requested_new_count and provide')?3:1,$batchPlan)];
    }
    if($mode==='reuse_image')$design=['html'=>'<figure class="sf-image-'.str_repeat('9',32).'"></figure>','css'=>''];
    return ['text'=>json_encode($design,JSON_THROW_ON_ERROR),
        'provider'=>'openai','model'=>'fixture','input_tokens'=>200,'output_tokens'=>80];
}

require dirname(__DIR__).'/api/lifecycle.php';
require dirname(__DIR__).'/api/entitlements.php';
require dirname(__DIR__).'/api/billing.php';
require dirname(__DIR__).'/api/ai-token-reservations.php';
require dirname(__DIR__).'/api/song-moments-core.php';
require dirname(__DIR__).'/api/agent-scene-history.php';
require dirname(__DIR__).'/api/privacy-core.php';
require dirname(__DIR__).'/api/agent-scene-core.php';

function verify(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    ++$GLOBALS['checks'];echo 'PASS '.$label."\n";
}
function rejects(callable $call,string $type,string $label):void {
    try{$call();}catch(Throwable $e){
        verify($e instanceof $type,$label.($e instanceof $type?'':': '.get_class($e).' '.$e->getMessage()));return;
    }
    throw new RuntimeException('FAIL expected rejection: '.$label);
}

sf_billing_ensure_schema();
sf_song_moments_schema();
$insert=$db->prepare('INSERT INTO song_timeline_moments(track_id,at_ms,kind,title,body,status,created_at,updated_at,story_id,source_annotation_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach([
    ['song-one',1000,'note','Published artist note','This is public','published','now','now',0,0],
    ['song-one',2000,'note','Private artist note','Do not share','draft','now','now',0,0],
    ['song-one',2500,'note','Private annotation','Do not send','published','now','now',0,12],
    ['song-one',2600,'comment','Fan comment','Do not send comments','published','now','now',0,0],
    ['song-one',7000,'story','Story cue','Public moment','published','now','now',50,0],
] as $row)$insert->execute($row);
$insert=$db->prepare("INSERT INTO user_subscriptions(user_id,status,current_period_end,created_at,updated_at) VALUES(?,'active',?,?,?)");
foreach([1,2,3] as $uid)$insert->execute([$uid,gmdate('c',time()+86400),gmdate('c'),gmdate('c')]);
foreach([1,2] as $uid)sf_token_adjust($uid,10000,'admin_grant','fixture');
$account=['id'=>1,'role'=>'customer'];
$request=['track_id'=>'song-one','brief'=>'Make the room warmer.'];

foreach([
    ['<script>alert(1)</script>',''],['<div onclick="alert(1)">Hi</div>',''],['<a href="https://example.com">Hi</a>',''],
    ['<div><p>Unclosed</div>',''],['<p>Style</p>','p{background:url(https://example.com)}'],
    ['<p>Style</p>','@import "https://example.com";'],['<p>Style</p>','</style>'],
    ['<details ontoggle="alert(1)"><summary>Open</summary><p>Unsafe</p></details>',''],
    ['<p>Animation</p>','@keyframes pulse{from{background:url(https://example.com)}to{opacity:.8}}'],
    ['<p>Style</p>','p{background:u\\72l(https://example.com)}'],['<p>'.str_repeat('a',12000).'</p>',''],
] as [$html,$css])verify(sf_agent_scene_code(['html'=>$html,'css'=>$css])===null,'Reject unsafe or oversized scene code');
verify(sf_agent_scene_code(['html'=>'<article class="room"><h1>Title</h1><hr /><p>Text &amp; art.</p></article>',
    'css'=>'@media (max-width: 500px){ .room > h1 { font-size: 1rem; } }'])!==null,'Allow scoped semantic markup and responsive CSS');
verify(sf_agent_scene_code(['html'=>'<details class="notes"><summary>Open notes</summary><p>Details</p></details>',
    'css'=>'@keyframes pulse{from{opacity:.8}to{opacity:1}}@-webkit-keyframes pulse{from{opacity:.8}to{opacity:1}}.notes{animation:pulse 1s}'])!==null,
    'Allow native disclosure and CSS animation without executable code');

$boxBrief='Build a boom box player with audio controls and pulsing speakers for Stonefellow.';
$boxCode=['html'=>'<article><small>a boom box player with audio controls and pulsing speakers</small>'
    .'<h1><span>A boom box player</span> with audio controls<br> and <strong>pulsing speakers</strong></h1>'
    .'<p>Two speakers breathe with the low end.</p><div class="speaker"></div><footer>Playback controls are provided by the host</footer></article>',
    'css'=>'.speaker::before{content:"";background:#eee}.speaker::after{content:"Boom box; player" !important;color:red}'];
$clean=sf_agent_scene_scrub_copy($boxCode,$boxBrief);
verify(!preg_match('/boom box|speakers|host/i',$clean['html'])&&str_contains($clean['html'],'<div class="speaker"></div>')&&
    $clean['css']==='.speaker::before{content:"";background:#eee}.speaker::after{content:"" !important;color:red}',
    'Custom visual artwork removes copied or unsolicited text across HTML and CSS without removing shapes');
$legacy=sf_agent_scene_scrub_copy($boxCode+['title'=>'a boom box player with audio controls and pulsing speakers']);
verify(!preg_match('/boom box|pulsing speakers|host/i',$legacy['html'])&&str_contains($legacy['html'],'Two speakers breathe')&&
    $legacy['title']==='Custom Canvas','Legacy artwork strips duplicated briefs and implementation notes while preserving independent copy');
$explicit=sf_agent_scene_scrub_copy(['html'=>'<article><h1><em>STAR</em> CITY</h1><p>{{title}} · {{release}}</p>'
    .'<details><summary>Open options</summary><p>Implementation note: host supplies playback controls</p></details></article>','css'=>''],
    'Build a star field with the exact headline STAR CITY.');
verify(str_contains($explicit['html'],'<h1><em>STAR</em> CITY</h1>')&&str_contains($explicit['html'],'{{title}} · {{release}}')&&
    str_contains($explicit['html'],'<summary>Open options</summary>')&&!str_contains($explicit['html'],'Implementation note'),
    'Requested headlines, canonical facts and short native disclosure labels survive the copy review');

$optionalContext=['canvas_kind'=>'custom','design_brief'=>'A player tinted to the image','canvas_title'=>'Custom Canvas',
    'title'=>'','release'=>'','story'=>null,'track_id'=>''];
$optionalCode=json_encode(['html'=>'<section>'.SF_AGENT_SCENE_PLAYER_SLOT.'</section>','css'=>'']);
$optionalPlayer=sf_agent_scene_render($optionalCode,$optionalContext);
verify($optionalPlayer['html']==='<section></section>'&&!isset($optionalPlayer['player_controls']),
    'Legacy empty host transport slots disappear without adding controls');
$removedPlayer=sf_agent_scene_render(json_encode(['html'=>'<section>'.SF_AGENT_SCENE_PLAYER_SLOT.SF_AGENT_SCENE_PLAYER_SLOT.'</section>','css'=>'']),$optionalContext);
verify($removedPlayer['html']==='<section></section>',
    'Repeated legacy host slots are removed without model repair');

rejects(fn()=>sf_agent_scene_create($account,['track_id'=>'hidden','brief'=>'Design it']),InvalidArgumentException::class,'Unpublished songs fail before provider usage');
rejects(fn()=>sf_agent_scene_create(['id'=>3,'role'=>'customer'],$request),RuntimeException::class,'Insufficient tokens fail before provider usage');
verify($providerCalls===0,'Rejected requests make no provider calls');
$created=sf_agent_scene_create($account,$request);
verify($created['active_tokens_used']===280&&$created['active_tokens_remaining']===9720&&sf_token_balance(1)===9720,'Charge exactly provider-reported tokens after saving a scene');
verify($providerTimeout===20&&$created['design_source']==='model','Listening-room model call uses a bounded interactive timeout');
verify(str_contains($created['scene']['html'],'Song &amp; &lt;Title&gt;')&&$created['scene']['story']['at_ms']===7000&&$created['scene']['artwork']==='api/media.php?id=MABCDEFG1234567&variant=medium','Bind canonical facts, art and story metadata');
verify(count($prompt['published_song']['notes'])===1&&$prompt['published_song']['credits']===['words_by'=>'Artist']&&!str_contains(json_encode($prompt),'Do not send'),'Prompt only includes bounded published fields and omits comments');
$first=$created['scene']['id'];
rejects(fn()=>sf_agent_scene_create(['id'=>2,'role'=>'customer'],$request+['previous_id'=>$first]),InvalidArgumentException::class,'Cannot revise another account’s scene');
rejects(fn()=>sf_agent_scene_create($account,['track_id'=>'song-two','brief'=>'Revise','previous_id'=>$first]),InvalidArgumentException::class,'Cannot revise a room for another song');
verify($providerCalls===1&&sf_agent_scene_history_get(2,$first)===null,'Cross-account history never reaches the provider');
$revised=sf_agent_scene_create($account,$request+['previous_id'=>$first]);
verify($revised['scene']['previous_id']===$first&&$prompt['previous']['html']===$created['scene']['html'],'Revision reads owned code and preserves prior scene');

foreach(['network_error','invalid_markup'] as $fail){
    $mode=$fail;$balance=sf_token_balance(1);$count=count(sf_agent_scene_history_list(1));
    $standard=sf_agent_scene_create($account,$request);
    verify($standard['design_source']==='standard'&&$standard['active_tokens_used']===0&&$standard['active_tokens_remaining']===$balance
        &&sf_token_balance(1)===$balance&&count(sf_agent_scene_history_list(1))===$count+1,'First room falls back without a charge on '.$fail);
    verify(str_contains($standard['scene']['html'],'Song &amp; &lt;Title&gt;')&&sf_agent_scene_code($standard['scene'])!==null
        &&sf_agent_scene_request_design_source(1,$standard['scene']['id'])==='standard','Standard room preserves published facts, safe code and source attribution');
    rejects(fn()=>sf_agent_scene_create($account,$request+['previous_id'=>$first]),UnexpectedValueException::class,'Failed revision returns a structured refusal on '.$fail);
    verify(sf_token_balance(1)===$balance&&count(sf_agent_scene_history_list(1))===$count+1,'Failed revision refunds the hold and preserves the original room');
}
$mode='success';$balance=sf_token_balance(1);$count=count(sf_agent_scene_history_list(1));
$stale=sf_ai_reserve_tokens($account,100,SF_AGENT_SCENE_TOKEN_SOURCE);
$db->prepare('UPDATE ai_token_reservations SET created_at=? WHERE request_key=?')->execute([gmdate('c',time()-301),$stale]);
$db->exec("CREATE TRIGGER reject_scene BEFORE INSERT ON agent_scene_history BEGIN SELECT RAISE(ABORT, 'Fixture history write failure'); END");
rejects(fn()=>sf_agent_scene_create($account,$request),PDOException::class,'A failed history write rolls back successful token settlement');
verify(sf_token_balance(1)===$balance&&(int)$db->query("SELECT COUNT(*) FROM ai_token_reservations WHERE status='held'")->fetchColumn()===0
    &&count(sf_agent_scene_history_list(1))===$count,'A new attempt releases stale scene escrow and a failed save leaves no charge');
$db->exec('DROP TRIGGER reject_scene');
$progressId=str_repeat('e',32);
$progressHold=sf_ai_reserve_tokens($account,100,SF_AGENT_SCENE_TOKEN_SOURCE);
$now=time();
sf_agent_scene_request_claim(1,$progressId,str_repeat('f',64));
$db->prepare('UPDATE agent_scene_requests SET created_at=? WHERE request_id=?')->execute([$now-280,$progressId]);
$db->prepare('UPDATE ai_token_reservations SET created_at=? WHERE request_key=?')->execute([gmdate('c',$now-280),$progressHold]);
sf_agent_scene_touch_progress(1,$progressId,$progressHold,$now);
$q=$db->prepare('SELECT created_at FROM agent_scene_requests WHERE request_id=?');$q->execute([$progressId]);
$renewedRequest=(int)$q->fetchColumn();
$q=$db->prepare('SELECT created_at FROM ai_token_reservations WHERE request_key=?');$q->execute([$progressHold]);
verify($renewedRequest===$now&&$q->fetchColumn()===gmdate('c',$now),'Streaming progress renews the pending request and its token hold together');
sf_agent_scene_request_fail(1,$progressId);
rejects(fn()=>sf_agent_scene_touch_progress(1,$progressId,$progressHold,$now+60),InvalidArgumentException::class,
    'Progress cannot revive a closed request');
$q->execute([$progressHold]);
verify($q->fetchColumn()===gmdate('c',$now),'Rejected progress leaves the token hold timestamp intact');
sf_ai_finish_reservation($account,$progressHold,0,[],SF_AGENT_SCENE_TOKEN_SOURCE,false);
$admin=sf_agent_scene_create(['id'=>4,'role'=>'admin'],$request);
verify($admin['active_tokens_used']===0&&$admin['active_tokens_remaining']===null&&count(sf_agent_scene_history_list(4))===1,'Administrator history and metering use existing unlimited semantics');
$mode='clear_history';$balance=sf_token_balance(1);
rejects(fn()=>sf_agent_scene_create($account,$request),InvalidArgumentException::class,'A reset during generation discards the result');
verify(sf_token_balance(1)===$balance&&sf_agent_scene_history_list(1)===[],'A reset during generation prevents a charge and does not resurrect scene history');
$mode='success';
sf_token_adjust(1,10000,'admin_grant','fixture custom tool context budget');
$standalone=['canvas_kind'=>'custom','brief'=>'An amber observatory','context_track_id'=>'song-one','request_id'=>str_repeat('a',32)];
$created=sf_agent_scene_create($account,$standalone);
$scene=$created['scene'];
verify($prompt['published_song']===null&&$prompt['optional_playing_song']['track_id']==='song-one'&&
    $prompt['optional_playing_song']['title']==='Song & <Title>',
    'Standalone canvas supplies the current song ID and facts as optional context');
verify(!str_contains($providerSystem,'1,800 characters')&&str_contains($providerSystem,'recognizable silhouettes')&&
    str_contains($providerSystem,'phone and desktop')&&str_contains($providerSystem,'CSS-generated content')&&
    !isset($prompt['player_controls_requested'])&&empty($scene['player_controls'])&&str_contains($providerSystem,'window.StonefellowAudio')&&
    !str_contains($providerSystem,SF_AGENT_SCENE_PLAYER_SLOT),
    'Custom output budget supports a detailed, responsive design with no unrequested controls or copy');
verify($scene['track_id']===''&&$scene['song_title']===null&&$scene['artwork']===null&&$scene['story']===null&&$scene['lyric_cue']===null&&
    !array_key_exists('context_track_id',sf_agent_scene_history_get(1,$scene['id'])),'Optional song inspiration never binds playback, assets, lyrics or history');
$before=$providerCalls;
rejects(fn()=>sf_agent_scene_create($account,array_replace($standalone,['context_track_id'=>'song-two'])),InvalidArgumentException::class,
    'Optional song context changes the private request fingerprint');
rejects(fn()=>sf_agent_scene_create($account,array_replace($standalone,['context_track_id'=>'hidden','request_id'=>str_repeat('b',32)])),
    InvalidArgumentException::class,'Standalone canvas only accepts published song context');
verify($providerCalls===$before,'Invalid or conflicting optional context makes no provider call');
sf_token_adjust(1,5000,'admin_grant','fixture custom repair budget');
$transport=array_replace($standalone,['brief'=>'A Stonefellow cassette deck with audio controls and speakers that pulse with music',
    'request_id'=>str_repeat('c',32)]);
$mode='player_js';$playerCodeAttempts=[];$balance=sf_token_balance(1);
$controlled=sf_agent_scene_create($account,$transport);
verify(count($playerCodeAttempts)===1&&!isset($playerCodeAttempts[0]['prompt']['player_controls_requested'])&&
    !str_contains($playerCodeAttempts[0]['system'],SF_AGENT_SCENE_PLAYER_SLOT)&&
    str_contains($controlled['scene']['js'],'StonefellowAudio.play()'),
    'The coding model authors interactions through the general audio SDK without a forced slot or repair');
verify($controlled['active_tokens_used']===280&&sf_token_balance(1)===$balance-280&&
    sf_agent_scene_history_get(1,$controlled['scene']['id'])['js']===$controlled['scene']['js'],
    'Authored JavaScript is saved with its canvas and the completed model result is charged once');
$mode='player_no_script';$playerCodeAttempts=[];$balance=sf_token_balance(1);
$plain=sf_agent_scene_create($account,array_replace($transport,['request_id'=>str_repeat('d',32)]));
verify(count($playerCodeAttempts)===1&&!isset($plain['scene']['js'])&&!isset($plain['scene']['player_controls'])&&
    sf_token_balance(1)===$balance-280,
    'An audio-related phrase imposes neither JavaScript nor a standard host control layout');
$revision=['canvas_kind'=>'custom','previous_id'=>$scene['id'],'track_id'=>'','brief'=>'Add more detail to the Stonefellow design.',
    'request_id'=>str_repeat('1',32)];
$mode='repair_markup';$repairAttempts=[];$before=$providerCalls;$balance=sf_token_balance(1);
$revised=sf_agent_scene_create($account,$revision);
verify($providerCalls===$before+2&&$repairAttempts[0]['prompt']===$repairAttempts[1]['prompt']&&
    $repairAttempts[0]['limit']===$repairAttempts[1]['limit']&&str_contains($repairAttempts[1]['system'],'failed markup validation')&&
    $revised['active_tokens_used']===280&&sf_token_balance(1)===$balance-280,
    'An invalid custom revision gets one repair using the original brief and charges only the saved result');
$revision['request_id']=str_repeat('2',32);
$mode='invalid_markup';$before=$providerCalls;$balance=sf_token_balance(1);$count=count(sf_agent_scene_history_list(1));
try{
    sf_agent_scene_create($account,$revision);
    throw new RuntimeException('FAIL expected invalid custom revision');
}catch(SfAgentSceneInvalidOutput $e){
    verify($e->reason==='invalid_markup','A repeated invalid custom revision reports a safe validation reason');
}
verify($providerCalls===$before+2&&sf_token_balance(1)===$balance&&count(sf_agent_scene_history_list(1))===$count&&
    sf_agent_scene_history_get(1,$scene['id'])!==null&&sf_agent_scene_request_get(1,$revision['request_id'])['state']==='failed',
    'A failed custom repair leaves the saved design available and charges no tokens');
$imageId=str_repeat('9',32);
$otherImage=str_repeat('8',32);
$insert=$db->prepare('INSERT INTO agent_canvas_images(user_id,id,storage_key,title,mime,size_bytes,width,height,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
$insert->execute([1,$imageId,str_repeat('a',48),'Glass Greenhouse','image/webp',240,1536,1024,'2026-10-01']);
$insert->execute([2,$otherImage,str_repeat('b',48),'Another Account Image','image/webp',240,1024,1024,'2026-10-02']);
$mode='reuse_image';$balance=sf_token_balance(1);
$reused=sf_agent_scene_create($account,['canvas_kind'=>'custom','brief'=>'Use {image-'.$imageId.'} in the foreground.']);
verify(array_column($prompt['canvas_image_tool']['saved_images'],'id')===[$imageId]&&
    array_column(sf_agent_canvas_image_assets(1,$reused['scene']),'id')===[$imageId]&&sf_agent_canvas_image_assets(2,$reused['scene'])===[]&&
    !str_contains(json_encode($prompt),'agent-canvas-image.php')&&sf_token_balance(1)===$balance-280,
    'A saved image can be recalled without paying for new pixels or exposing another account inventory');
rejects(fn()=>sf_agent_scene_create($account,['canvas_kind'=>'custom','brief'=>'Use {image-'.$otherImage.'}.']),InvalidArgumentException::class,
    'An explicit reference cannot retrieve another account’s image');
$mode='generate_image';$imageStages=[];$balance=sf_token_balance(1);
rejects(function()use($account,&$imageStages):void{
    sf_agent_scene_create($account,['canvas_kind'=>'custom','brief'=>'Create an image for this figure.'],null,null,
        function(string $state)use(&$imageStages):void{$imageStages[]=$state;});
},SfAgentCanvasImageUnavailable::class,
    'A requested raster reports when the image provider is disabled');
verify(sf_token_balance(1)===$balance&&$imageStages===['generating'],'Image generation failure releases the coding hold without reporting a save');
$plan=sf_agent_canvas_image_plan(['prompt'=>'Glass greenhouse','title'=>'Garden']);
verify($plan['size']==='1024x1024'&&sf_agent_canvas_image_apply(['html'=>'<figure class="sf-image-new"></figure>',
    'css'=>'figure{background-image:var(--sf-image-new,none)}'],$imageId)===['html'=>'<figure class="sf-image-'.$imageId.'"></figure>',
        'css'=>'figure{background-image:var(--sf-image-'.$imageId.',none)}'],
    'The image action can place one generated image in CSS and in a foreground figure');
rejects(fn()=>sf_agent_canvas_image_plans(['html'=>'<div></div>','css'=>''],$plan,[]),SfAgentSceneInvalidOutput::class,
    'An image plan without a placement cannot trigger a paid image call');
verify(sf_agent_canvas_requested_image_count('3 images of a garden')===3&&
    sf_agent_canvas_requested_image_count('Build a gallery, then generate three distinct images')===3&&
    sf_agent_canvas_requested_image_count('Create a set of four new photos')===4&&
    sf_agent_canvas_requested_image_count('Build a gallery for the 3 Images theme')===null&&
    sf_agent_canvas_requested_image_count('3 images using {image-'.$imageId.'}')===null&&
    sf_agent_canvas_requested_image_count('Create 3 images using {image-'.$imageId.'} as inspiration')===3,
    'Explicit image counts support chat shorthand and words without mistaking saved layouts for new generation');
rejects(fn()=>sf_agent_canvas_requested_image_count('Create five images'),InvalidArgumentException::class,
    'Requests above four are rejected before paid generation');
$batchCode=['html'=>'<figure class="sf-image-new-1"></figure><figure class="sf-image-new-2"></figure>',
    'css'=>'figure{background-image:var(--sf-image-new-2,none)}'];
verify(array_keys(sf_agent_canvas_image_plans($batchCode,[$plan,$plan],[],2))===['new-1','new-2'],
    'Numbered images bind in plan order even when the CSS references a later slot first');
rejects(fn()=>sf_agent_canvas_image_plans($batchCode,[$plan,$plan],[]),SfAgentSceneInvalidOutput::class,
    'A model cannot add multiple new images without a requested count');
$fifth=['html'=>$batchCode['html'].'<figure class="sf-image-new-5"></figure>','css'=>''];
rejects(fn()=>sf_agent_canvas_image_plans($fifth,[$plan,$plan],[],2),SfAgentSceneInvalidOutput::class,
    'An unsupported fifth placement is rejected');
$mode='generate_batch';$imageStages=[];$before=$providerCalls;
rejects(function()use($account,&$imageStages):void{
    sf_agent_scene_create($account,['canvas_kind'=>'custom','brief'=>'Create 3 images for a responsive gallery.'],null,null,
        function(string $state,?array $image,int $index,int $total)use(&$imageStages):void{$imageStages[]=[$state,$index,$total];});
},SfAgentCanvasImageUnavailable::class,
    'An explicit three-image build reaches the generator after model repair');
verify($providerCalls===$before+2&&$prompt['canvas_image_tool']['requested_new_count']===3&&
    $imageStages===[['generating',1,3]],'The model is retried once for an undercount and progress declares the exact requested batch size');
$legacyRoom=sf_agent_scene_history_save(1,['track_id'=>'song-one','title'=>'Room','html'=>'<section>Room</section>','css'=>'']);
$roomImageEdit=['canvas_kind'=>'custom','track_id'=>'song-one','previous_id'=>$legacyRoom['id'],'brief'=>'Add {image-'.$imageId.'} to the room.'];
$mode='reuse_image';
$converted=sf_agent_scene_create($account,$roomImageEdit);
verify($converted['scene']['canvas_kind']==='custom'&&$converted['scene']['track_id']==='song-one'&&
    $converted['scene']['previous_id']===$legacyRoom['id']&&sf_agent_scene_saved(1,$legacyRoom['id'])['canvas_kind']==='listening_room'&&
    array_column(sf_agent_canvas_image_assets(1,$converted['scene']),'id')===[$imageId],
    'An image edit creates a custom revision of an owned listening room and keeps the original track and room');
rejects(fn()=>sf_agent_scene_create($account,array_replace($roomImageEdit,['track_id'=>'song-two'])),InvalidArgumentException::class,
    'A listening room image edit cannot change its original track');

$adminAccount=['id'=>4,'role'=>'admin'];
$mode='library_repair';$before=$providerCalls;
$generated=sf_agent_scene_create($adminAccount,['canvas_kind'=>'custom','brief'=>'Build an explorable 3D sculpture.']);
$offered=array_column($prompt['canvas_libraries'],null,'id');
verify(array_keys($offered)===['anime','color-thief','d3','matter','meyda','pixi','three']&&$offered['three']['global']==='THREE'&&
    $offered['color-thief']['global']==='ColorThief'&&$offered['d3']['global']==='d3'&&
    $offered['matter']['global']==='Matter'&&$offered['anime']['global']==='anime'&&
    $offered['meyda']['global']==='Meyda'&&$offered['pixi']['global']==='PIXI'&&str_contains($providerSystem,'use native HTML, CSS, Canvas 2D'),
    'The normal coding prompt offers verified optional libraries and criteria for choosing native APIs first');
verify($providerCalls===$before+2&&str_starts_with($generated['scene']['js'],'// sf-libraries: '.$offered['three']['lock']."\n")&&
    sf_agent_scene_history_get(4,$generated['scene']['id'])['js']===$generated['scene']['js'],
    'The build repairs an unreviewed dependency and persists the corrected pinned source');
$mode='d3';
$chart=sf_agent_scene_create($adminAccount,['canvas_kind'=>'custom','brief'=>'Use D3 to render a data-driven chart.']);
verify(str_starts_with($chart['scene']['js'],'// sf-libraries: '.$offered['d3']['lock']."\n")&&
    sf_agent_scene_history_get(4,$chart['scene']['id'])['js']===$chart['scene']['js'],
    'An explicit D3 build receives the exact pin and persists the authored source');
$mode='matter';
$game=sf_agent_scene_create($adminAccount,['canvas_kind'=>'custom','brief'=>'Use Matter.js to simulate a throw and detect a hit.']);
verify(str_starts_with($game['scene']['js'],'// sf-libraries: '.$offered['matter']['lock']."\n")&&
    sf_agent_scene_history_get(4,$game['scene']['id'])['js']===$game['scene']['js'],
    'An explicit Matter.js build receives the exact pin and persists the authored source');
$mode='anime';
$motion=sf_agent_scene_create($adminAccount,['canvas_kind'=>'custom','brief'=>'Use Anime.js to animate SVG and DOM elements.']);
verify(str_starts_with($motion['scene']['js'],'// sf-libraries: '.$offered['anime']['lock']."\n")&&
    sf_agent_scene_history_get(4,$motion['scene']['id'])['js']===$motion['scene']['js'],
    'An explicit Anime.js build receives the exact pin and persists the authored source');
$mode='pixi';
$sprites=sf_agent_scene_create($adminAccount,['canvas_kind'=>'custom','brief'=>'Use PixiJS to draw interactive sprites.']);
verify(str_starts_with($sprites['scene']['js'],'// sf-libraries: '.$offered['pixi']['lock']."\n")&&
    sf_agent_scene_history_get(4,$sprites['scene']['id'])['js']===$sprites['scene']['js'],
    'An explicit PixiJS build receives the exact pin and persists the authored source');
$mode='meyda';
$visualizer=sf_agent_scene_create($adminAccount,['canvas_kind'=>'custom','brief'=>'Use Meyda to display an audio feature.']);
verify(str_starts_with($visualizer['scene']['js'],'// sf-libraries: '.$offered['meyda']['lock']."\n")&&
    sf_agent_scene_history_get(4,$visualizer['scene']['id'])['js']===$visualizer['scene']['js'],
    'An explicit Meyda build receives the exact pin and persists the authored source');
sf_canvas_permission_save($adminAccount,1,['canvas.library.three'=>false,'canvas.library.d3'=>false,
    'canvas.library.matter'=>false,'canvas.library.anime'=>false,'canvas.library.meyda'=>false,'canvas.library.pixi'=>false]);
$mode='success';
$native=sf_agent_scene_create($adminAccount,['canvas_kind'=>'custom','brief'=>'Create a quiet reading backdrop.']);
verify(array_column($prompt['canvas_libraries'],'id')===['color-thief']&&!isset($native['scene']['js'])&&
    sf_agent_scene_history_get(4,$generated['scene']['id'])['js']===$generated['scene']['js'],
    'Disabling libraries removes their pins from new build inventory while preserving earlier source and native builds');
echo "$checks listening-room checks passed\n";
