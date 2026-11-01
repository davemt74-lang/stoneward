<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-runtime.php';
require_once dirname(__DIR__).'/api/agent-song-analyze-core.php';
$checks=0;
$assert=static function(bool $pass,string $name)use(&$checks):void{
    if(!$pass){fwrite(STDERR,"91B13E FAIL: $name\n");exit(1);}
    echo "91B13E PASS: $name\n";$checks++;
};
$request=[
    'track_id'=>'earth-girl','title'=>'Earth Girl','release'=>'Demo',
    'start_ms'=>58384,'end_ms'=>90132,'captured_at'=>'2026-11-01T16:00:00Z',
    'transcript'=>'The reality of our imperfect mistakes keeps love alive',
    'question'=>'Interpret imagery and emotion in these words.',
    'mode'=>'interpretation','listener_edited'=>true];
$system=sf_song_analysis_system($request,'Published credits and context','Preferences','Published song notes');
$user=sf_song_analysis_user_prompt($request);
$assert(str_contains($user,'The reality of our imperfect mistakes keeps love alive'),
    'Immediate LLM user message contains actual corrected lyric words');
$assert(str_contains($system,'Do not restate the track title, credits, time offsets'),
    'Song identity and provenance are context, not narration');
$assert(str_contains($system,'Lead with what the listener-provided words could mean'),
    'System requires lyric-first interpretation');
$assert(str_contains($system,'credits alone are not evidence of intent'),
    'Published credits cannot masquerade as songwriter intentions');
$report=sf_song_analysis_report($request,[
    'text'=>'Preamble: catalog credits and time offsets.'."\n".
        '<analysis>The friction between mistakes and love suggests affection enduring imperfection.</analysis>'."\n".
        'Sources: private metadata','provider'=>'anthropic','model'=>'claude-test'],['Published song notes']);
$assert($report['report']==='The friction between mistakes and love suggests affection enduring imperfection.',
    'Speech report contains only analysis between explicit response tags');
$assert(!str_contains($report['report'],'Sources:')&&!str_contains($report['report'],'Preamble:'),
    'Provider provenance outside analysis tags is excluded from Agent reply');
$assert(($report['listener_edited']??false)===true,
    'Listener correction is retained as separate structured metadata');
$assert(($report['track_id']??'')==='earth-girl'&&($report['start_ms']??0)===58384,
    'Track identity and timing stay as structured metadata');
$assert(($report['writer_sources']??[])===['Published song notes']&&($report['provider']??'')==='anthropic',
    'Source and model metadata survive for expandable sidecar detail');
echo "Section 91B13E: $checks lyric-grounding, source-separation and privacy checks passed\n";