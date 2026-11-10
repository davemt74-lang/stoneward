<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/agent-scene-stream.php';

function verify(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException($message);
    echo "PASS $message\n";
}

function rejected(callable $run,string $message,?string $reason=null): void {
    try{$run();}catch(RuntimeException $error){
        verify($reason===null||($error instanceof SfAgentSceneStreamInterrupted&&$error->reason===$reason),$message);
        return;
    }
    throw new RuntimeException($message);
}

function event(string $type,array $fields=[],string $lineEnding="\n"): string {
    $data=json_encode(['type'=>$type]+$fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    return 'event: '.$type.$lineEnding.'data: '.$data.$lineEnding.$lineEnding;
}

$events=[];
$emit=static function(array $event)use(&$events):void {$events[]=$event;};
$source=new SfAgentSceneSourceEvents($emit);
$conceptStart="```js";
$conceptTail="on\n{ \"concept\" : \"a pulsing ";
$conceptEnd='EQ with \\"stars\\" and caf\\u00e9"';
$source->push($conceptStart);
$source->push($conceptTail);
verify($events===[],'A split leading concept waits for its complete string');
$source->push($conceptEnd);
verify($events===[['type'=>'concept','text'=>'a pulsing EQ with "stars" and café'],
    ['type'=>'delta','text'=>$conceptStart],['type'=>'delta','text'=>$conceptTail],['type'=>'delta','text'=>$conceptEnd]],
    'A decoded concept is emitted before unchanged source chunks');
$source->push(',"html":"<article></article>"}');
$source->flush();
verify(count($events)===5&&$events[4]['type']==='delta','Code continues after the concept without duplicate events');

$events=[];
$source=new SfAgentSceneSourceEvents($emit);
$large='{"concept":"an aurora", "html":"'.str_repeat('x',1800);
$source->push($large);
verify($events===[['type'=>'concept','text'=>'an aurora'],['type'=>'delta','text'=>$large]],
    'A large first chunk still announces a short concept before code');

foreach(['{"html":"<article>','{"concept":"line\\nfeed"','{"concept":"<b>HTML</b>"',
    '{"concept":"'.str_repeat('x',81).'"','{"concept":"'.str_repeat('x',1800)] as $invalid){
    $events=[];
    $source=new SfAgentSceneSourceEvents($emit);
    $source->push($invalid);
    verify($events===[['type'=>'delta','text'=>$invalid]],'Legacy, invalid and overlong concepts let raw code continue');
}
$events=[];
$source=new SfAgentSceneSourceEvents($emit);
$source->push('{"concept":"incomplete');
$source->flush();
verify($events===[['type'=>'delta','text'=>'{"concept":"incomplete']],'Incomplete concept preserves raw source before the terminal event');

$events=[];
$source=new SfAgentSceneSourceEvents($emit);
$source->push('{"concept":"unfinished');
$source->retry('invalid_markup');
$fresh='{"concept":"a pulsing star cluster", "html":"<article></article>"}';
$source->push($fresh);
$source->flush();
verify($events===[['type'=>'retry','reason'=>'invalid_markup'],['type'=>'concept','text'=>'a pulsing star cluster'],['type'=>'delta','text'=>$fresh]],
    'Retry reports its validation category, discards the buffered prefix and announces the next concept');

$deltas=[];
$openai=new SfAgentSceneStreamParser('openai',static function(string $delta)use(&$deltas):void {$deltas[]=$delta;});
$wire="\xef\xbb\xbf: heartbeat\r\n\r\n".event('response.reasoning_summary_text.delta',['delta'=>'private reasoning'],"\r\n")
    .event('response.output_text.delta',['delta'=>'{"html":"café'],"\r\n");
foreach(str_split($wire,1) as $byte)$openai->push($byte);
verify($deltas===['{"html":"café'],'OpenAI emits only visible text before the response completes');
$openai->push("event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\ndata: \"delta\":\"\\\"}\"}\n\n");
verify(!$openai->ended(),'Visible code alone does not mark a provider response complete');
$openai->push(event('response.completed',['response'=>['id'=>'resp_fixture','status'=>'completed','usage'=>['input_tokens'=>38,'output_tokens'=>12]]]));
$openai->push("data: [DONE]\n\n");
verify($openai->result('fixture-openai')===['provider'=>'openai','model'=>'fixture-openai','text'=>'{"html":"café"}',
    'input_tokens'=>38,'output_tokens'=>12,'raw_id'=>'resp_fixture'],'OpenAI reassembles split UTF-8 and multi-line events with final usage');

$deltas=[];
$anthropic=new SfAgentSceneStreamParser('anthropic',static function(string $delta)use(&$deltas):void {$deltas[]=$delta;});
$wire=event('message_start',['message'=>['id'=>'msg_fixture','usage'=>['input_tokens'=>40,'output_tokens'=>1]]],"\r")
    .event('content_block_start',['index'=>0,'content_block'=>['type'=>'thinking','thinking'=>'hidden']],"\r")
    .event('content_block_delta',['index'=>0,'delta'=>['type'=>'thinking_delta','thinking'=>'hidden delta']],"\r")
    .event('content_block_stop',['index'=>0],"\r")
    .event('ping',[],"\r")
    .event('content_block_start',['index'=>1,'content_block'=>['type'=>'text','text'=>'Hello']],"\r")
    .event('content_block_delta',['index'=>1,'delta'=>['type'=>'text_delta','text'=>' world']],"\r")
    .event('content_block_stop',['index'=>1],"\r")
    .event('message_delta',['delta'=>['stop_reason'=>'end_turn'],'usage'=>['output_tokens'=>18]],"\r")
    .event('message_stop',[],"\r");
foreach(str_split($wire,7) as $chunk)$anthropic->push($chunk);
verify($anthropic->result('fixture-claude')===['provider'=>'anthropic','model'=>'fixture-claude','text'=>'Hello world',
    'input_tokens'=>40,'output_tokens'=>18,'raw_id'=>'msg_fixture']&&$deltas===['Hello',' world'],
    'Anthropic uses cumulative output usage and emits only text blocks');

final class OpenCanvasSocketFixture {
    public mixed $context;
    public static string $wire='';
    public static int $reads=0;
    public static bool $closed=false;

    public function stream_open(string $path,string $mode,int $options,?string &$openedPath): bool {return true;}
    public function stream_set_option(int $option,int $arg1,?int $arg2): bool {return true;}
    public function stream_eof(): bool {return false;}
    public function stream_close(): void {self::$closed=true;}
    public function stream_read(int $count): string {
        if(self::$reads++>0)throw new RuntimeException('The socket waited after its terminal event.');
        return self::$wire;
    }
}
$wire=event('response.output_text.delta',['delta'=>'complete code'])
    .event('response.completed',['response'=>['usage'=>['input_tokens'=>10,'output_tokens'=>3]]]);
OpenCanvasSocketFixture::$wire=$wire.':'.str_repeat(' ',8189-strlen($wire))."\n\n";
stream_wrapper_register('sfcanvassocket',OpenCanvasSocketFixture::class);
try{
    $parser=new SfAgentSceneStreamParser('openai');
    sf_agent_scene_stream_read('sfcanvassocket://fixture',[],'',1,$parser);
    verify(OpenCanvasSocketFixture::$reads===1&&OpenCanvasSocketFixture::$closed&&$parser->result('fixture')['text']==='complete code',
        'The stream transport returns at provider completion while the socket remains open');
}finally{stream_wrapper_unregister('sfcanvassocket');}

final class CanvasWatchdogFixture {
    public mixed $context;
    public static array $chunks=[];
    public static int $openTimeout=0;
    public static bool $closed=false;

    public function stream_open(string $path,string $mode,int $options,?string &$openedPath): bool {
        self::$openTimeout=stream_context_get_options($this->context)['http']['timeout'];
        self::$closed=false;
        return true;
    }
    public function stream_set_option(int $option,int $arg1,?int $arg2): bool {return true;}
    public function stream_eof(): bool {return false;}
    public function stream_close(): void {self::$closed=true;}
    public function stream_read(int $count): string {
        [$delay,$wire]=array_shift(self::$chunks)??[20_000,event('ping')];
        if($delay)usleep($delay);
        // A full read prevents the userland wrapper combining multiple scheduled events.
        return $wire.':'.str_repeat(' ',$count-strlen($wire)-3)."\n\n";
    }
}
stream_wrapper_register('sfcanvaswatchdog',CanvasWatchdogFixture::class);
try{
    CanvasWatchdogFixture::$chunks=[[0,event('response.output_text.delta',['delta'=>'unfinished code'])]];
    $parser=new SfAgentSceneStreamParser('openai');
    rejected(static fn()=>sf_agent_scene_stream_read('sfcanvaswatchdog://fixture',[],'',null,$parser,1),
        'Keepalives cannot postpone a stalled code stream without a fixed deadline','timeout');
    verify(CanvasWatchdogFixture::$openTimeout===1&&CanvasWatchdogFixture::$closed&&!$parser->ended(),
        'Opening the connection uses the idle limit and a stalled stream is closed without completion');

    CanvasWatchdogFixture::$chunks=[
        [600_000,event('response.output_text.delta',['delta'=>'complete '])],
        [600_000,event('response.output_text.delta',['delta'=>'code'])
            .event('response.completed',['response'=>['usage'=>['input_tokens'=>10,'output_tokens'=>3]]])],
    ];
    $parser=new SfAgentSceneStreamParser('openai');
    sf_agent_scene_stream_read('sfcanvaswatchdog://fixture',[],'',null,$parser,1);
    verify(CanvasWatchdogFixture::$closed&&$parser->result('fixture')['text']==='complete code',
        'Visible text lets an uncapped stream continue beyond its initial idle limit and complete');

    CanvasWatchdogFixture::$chunks=[[600_000,event('response.output_text.delta',['delta'=>'first part'])]];
    $parser=new SfAgentSceneStreamParser('openai');
    rejected(static fn()=>sf_agent_scene_stream_read('sfcanvaswatchdog://fixture',[],'',1,$parser,3),
        'Code progress cannot extend the hard stream timeout','timeout');
    verify(CanvasWatchdogFixture::$openTimeout===1&&CanvasWatchdogFixture::$closed,
        'Opening the connection never exceeds the hard timeout');

    CanvasWatchdogFixture::$chunks=[[0,$wire]];
    $parser=new SfAgentSceneStreamParser('openai');
    sf_agent_scene_stream_read('sfcanvaswatchdog://fixture',[],'',null,$parser,null,static fn():bool=>false);
    verify(CanvasWatchdogFixture::$openTimeout===-1&&CanvasWatchdogFixture::$closed&&$parser->result('fixture')['text']==='complete code',
        'A cancellable stream can complete without a fixed or idle timeout');

    CanvasWatchdogFixture::$chunks=[[0,event('ping')]];
    $parser=new SfAgentSceneStreamParser('openai');
    $checks=0;$stopped=false;
    try{
        sf_agent_scene_stream_read('sfcanvaswatchdog://fixture',[],'',null,$parser,null,
            static function()use(&$checks):bool {return ++$checks>=6;});
    }catch(SfAgentSceneBuildCanceled $error){$stopped=true;}
    verify($stopped&&CanvasWatchdogFixture::$closed&&!$parser->ended()&&$parser->lastTextAt()===null,
        'Stopping a build closes its provider stream while it is sending only keepalives');
}finally{stream_wrapper_unregister('sfcanvaswatchdog');}

rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('openai');
    $parser->push(event('response.output_text.delta',['delta'=>'partial']));
    $parser->result('fixture');
},'A disconnected stream cannot produce a chargeable response','stream_incomplete');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('anthropic');
    $parser->push(event('message_start',['message'=>['usage'=>['input_tokens'=>40,'output_tokens'=>1]]]).event('message_stop'));
    $parser->result('fixture');
},'Provisional Anthropic usage cannot settle a truncated stream','stream_incomplete');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('openai');
    $parser->push(event('response.incomplete',['response'=>['incomplete_details'=>['reason'=>'max_output_tokens']]]));
},'OpenAI incomplete generation reports its output limit instead of accepting partial code','output_limit');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('anthropic');
    $parser->push(event('message_delta',['delta'=>['stop_reason'=>'max_tokens'],'usage'=>['output_tokens'=>1600]]));
},'Anthropic max-token stop reports its output limit instead of accepting partial code','output_limit');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('openai');
    $parser->push(event('response.incomplete',['response'=>['incomplete_details'=>['reason'=>'content_filter']]]));
},'OpenAI content filter is classified as a refusal rather than an interrupted connection','provider_refused');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('openai');
    $parser->push(event('response.refusal.delta',['delta'=>'A refusal from the provider']));
},'OpenAI explicit refusal is distinguished from source output','provider_refused');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('openai');
    $parser->push(event('error',['code'=>'content_policy_violation']));
},'An explicit policy error is a refusal and cannot be retried through another provider','provider_refused');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('anthropic');
    $parser->push(event('message_delta',['delta'=>['stop_reason'=>'refusal'],'usage'=>['output_tokens'=>7]]));
},'Anthropic terminal refusal is classified separately from output limits','provider_refused');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('anthropic');
    $parser->push(event('content_block_delta',['delta'=>['type'=>'text_delta','text'=>'partial code']])
        .event('error',['error'=>['type'=>'overloaded_error','message'=>'Provider details remain private']]));
},'A provider overload after code begins is distinguished from an invalid result','provider_busy');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('openai');
    $parser->push(event('response.output_text.delta',['delta'=>str_repeat('x',96_001)]));
},'Text output cap applies before any oversized delta is sent');
rejected(static function():void {
    $parser=new SfAgentSceneStreamParser('openai');
    $parser->push('data: '.str_repeat('x',260_000));
},'An unterminated provider event cannot grow without bound');


$scene=['id'=>42,'canvas_kind'=>'custom','dataset_track_id'=>'song','datasets'=>[
    'published_lyrics'=>['rows'=>[['text'=>'Published lyric']]],
    'published_stories'=>['rows'=>[['body_text'=>'Published story']]],
]];
$receipt=['ok'=>true,'scene'=>$scene,'workflow'=>['state'=>'ready']];
verify(sf_agent_scene_result_event($receipt,200)['data']===$receipt,'Ordinary lyric results stay inline in the existing completion event');
$receipt['scene']['datasets']['published_stories']['rows'][0]['body_text']=str_repeat('é',530000);
$event=sf_agent_scene_result_event($receipt,200);
verify($event['type']==='result'&&$event['data']['datasets_deferred']===true&&
    $event['data']['workflow']===['state'=>'ready']&&$event['data']['scene']===['id'=>42,'canvas_kind'=>'custom'],
    'Large mixed lyric results keep the same completion receipt and defer authorized dataset delivery');
