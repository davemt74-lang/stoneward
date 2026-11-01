<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-runtime.php';
require_once dirname(__DIR__).'/api/agent-song-analyze-core.php';
$checks=0;
$assert=static function(bool $pass,string $name)use(&$checks):void{
    if(!$pass){fwrite(STDERR,"91B12E FAIL: $name\n");exit(1);}
    echo "91B12E PASS: $name\n";$checks++;
};
$request=['track_id'=>'earth-girl','title'=>'Earth Girl','release'=>'Demo',
    'start_ms'=>12000,'end_ms'=>34000,'captured_at'=>'2026-10-31T16:00:00Z',
    'transcript'=>'Earth girl, in the stars','question'=>'Explain its meaning','mode'=>'interpretation'];
$published=[['id'=>'earth-girl','title'=>'Earth Girl',
    'story'=>'A published note about the search for connection.',
    'metadata'=>['words_by'=>'Roger Huston','music_by'=>'Roger Huston']]];
$stories=[['status'=>'published','readiness'=>['ready'=>true],'entity_type'=>'track',
    'entity_id'=>'earth-girl','title'=>'Writing Earth Girl','summary'=>'The writer describes curiosity and longing.'],
    ['status'=>'draft','entity_type'=>'track','entity_id'=>'earth-girl','title'=>'Private draft','summary'=>'Secret feelings.']];
$knowledge=[['name'=>'Earth Girl songwriter notes','text'=>'Earth Girl grew from wonder about the universe.','public_agent'=>true],
    ['name'=>'Earth Girl private notebook','text'=>'Secret personal diary.','public_agent'=>false]];
$songbook=['track_id'=>'earth-girl','sections'=>[['label'=>'Verse 1','lines'=>[
    ['line'=>1,'annotations'=>['This image compares a person to a planet.']]]]]];
$writer=sf_song_analysis_writer_context($request,$published,$stories,$knowledge,$songbook);
$assert(str_contains($writer['text'],'Roger Huston'),'Published writer credits are included');
$assert(str_contains($writer['text'],'curiosity and longing'),'Published connected story is included');
$assert(str_contains($writer['text'],'compares a person to a planet'),'Published line annotations are included');
$assert(str_contains($writer['text'],'wonder about the universe'),'Public-agent-approved knowledge is included');
$assert(!str_contains($writer['text'],'Secret personal diary'),'Private knowledge cannot leak into analysis');
$assert(!str_contains($writer['text'],'Secret feelings'),'Unpublished artist story cannot leak into analysis');
$assert(count($writer['sources'])>=4,'Every approved writer context is source-labeled');
$empty=sf_song_analysis_writer_context($request,[],[],[],[]);
$assert($empty['sources']===[],'No unpublished or missing track is implicitly treated as public');
$system=sf_song_analysis_system($request,'Published catalog','',$writer['text']);
$assert(str_contains($system,'Distinguish direct observations, plausible interpretation, and verified catalog facts'),'System prompt separates fact and interpretation');
$assert(str_contains($system,'Do not invent intentions'),'Unknown songwriter intent must not be hallucinated');
$report=sf_song_analysis_report($request,['text'=>'A possible reading of Earth Girl.','provider'=>'anthropic'],$writer['sources']);
$assert(($report['writer_sources']??[])===$writer['sources'],'Writer source provenance travels with report');
$assert(($report['draft_status']??'')==='unverified_user_supplied','Lyrics remain explicitly unverified');
$route=sf_agent_explicit_song_capture_route('Listen and interpret the lyrics');
$assert($route==='song_capture_live_start','Explicit listening and interpretation starts live lyrics');
$live=sf_agent_policy('song_capture_live_start','Listen and interpret the lyrics',
    ['player_is_playing'=>1,'active_track_id'=>'earth-girl']);
$assert(($live['action']['type']??'')==='song_capture_live_start'&&!empty($live['action']['interpret_on_stop']),
    'Consented interpretation becomes an on-stop step');
$normal=sf_agent_policy('song_capture_live_start','Start live transcription',
    ['player_is_playing'=>1,'active_track_id'=>'earth-girl']);
$assert(empty($normal['action']['interpret_on_stop']),'Live lyrics alone do not authorize analysis');
echo "Section 91B12E: $checks source, permission, and interpretation assertions passed\n";
