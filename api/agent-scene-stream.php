<?php
declare(strict_types=1);

/** Large lyric results use the owner-authorized scene GET for dataset delivery. */
function sf_agent_scene_result_event(array $data,int $status): array {
    $event=['type'=>$status>=400?'error':'result','status'=>$status,'data'=>$data];
    // Match the browser's per-event NDJSON limit. Measure bytes as an upper bound on characters.
    if($status<300&&isset($data['scene']['id'],$data['scene']['datasets']['published_lyrics'])&&
        strlen(json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR))>1048576){
        unset($event['data']['scene']['datasets'],$event['data']['scene']['dataset_track_id']);
        $event['data']['datasets_deferred']=true;
    }
    return $event;
}

final class SfAgentSceneStreamInterrupted extends RuntimeException {
    public function __construct(public readonly string $reason) {
        parent::__construct('Listening room provider stream did not complete.');
    }
}

final class SfAgentSceneBuildCanceled extends LogicException {
    public function __construct() {
        parent::__construct('The canvas build was stopped.');
    }
}

final class SfAgentSceneSourceEvents {
    private const PREFIX_LIMIT=1200;

    private string $prefix='';
    private ?array $pending=[];

    public function __construct(private Closure $emit) {}

    public function push(string $text): void {
        if($this->pending===null){
            ($this->emit)(['type'=>'delta','text'=>$text]);
            return;
        }
        $this->pending[]=$text;
        $this->prefix.=substr($text,0,self::PREFIX_LIMIT-strlen($this->prefix));
        if($this->awaitConcept()&&strlen($this->prefix)<self::PREFIX_LIMIT)return;
        $this->flush();
    }

    public function flush(): void {
        foreach($this->pending??[] as $text)($this->emit)(['type'=>'delta','text'=>$text]);
        $this->pending=null;
        $this->prefix='';
    }

    public function retry(string $reason): void {
        $this->pending=[];
        $this->prefix='';
        ($this->emit)(['type'=>'retry','reason'=>$reason]);
    }

    private function awaitConcept(): bool {
        $start=ltrim($this->prefix," \t\r\n");
        if(str_starts_with('```json',strtolower($start)))return true;
        $start=preg_replace('/\A```(?:json)?[ \t\r\n]*/i','',$start)??$start;
        if(!preg_match('/\A\{[ \t\r\n]*"concept"[ \t\r\n]*:[ \t\r\n]*/',$start,$key)){
            // Release code immediately when the first key belongs to an older response format.
            return str_starts_with('{"concept":',str_replace([" ","\t","\r","\n"],'',$start));
        }
        $value=substr($start,strlen($key[0]));
        if($value==='')return true;
        if($value[0]!=='"')return false;
        if(!preg_match('~\A"(?:[^"\\\\]++|\\\\.)*"~s',$value,$match))return true;
        $concept=json_decode($match[0]);
        if(is_string($concept)&&preg_match('/\A[^\p{C}\p{Zl}\p{Zp}<>]{1,80}\z/u',$concept)===1&&trim($concept)!=='')
            ($this->emit)(['type'=>'concept','text'=>trim($concept)]);
        return false;
    }
}

final class SfAgentSceneStreamParser {
    private const MAX_STREAM_BYTES=2_000_000;
    private const MAX_EVENT_BYTES=260_000;
    private const MAX_TEXT_BYTES=96_000;

    private string $buffer='';
    private string $event='';
    private string $data='';
    private string $text='';
    private int $bytes=0;
    private bool $firstLine=true;
    private bool $complete=false;
    private ?int $inputTokens=null;
    private ?int $outputTokens=null;
    private ?string $responseId=null;
    private ?int $lastTextAt=null;
    private ?Closure $onDelta;

    public function __construct(private string $provider,?callable $onDelta=null) {
        $this->onDelta=$onDelta===null?null:Closure::fromCallable($onDelta);
    }

    public function push(string $chunk): void {
        if($this->complete)return;
        $this->bytes+=strlen($chunk);
        if($this->bytes>self::MAX_STREAM_BYTES)throw new RuntimeException('Listening room provider stream exceeded its limit.');
        $buffer=$this->buffer.$chunk;
        $length=strlen($buffer);
        $offset=0;
        while($offset<$length){
            $end=$offset+strcspn($buffer,"\r\n",$offset);
            if($end===$length||($buffer[$end]==="\r"&&$end+1===$length))break;
            $this->line(substr($buffer,$offset,$end-$offset));
            if($this->complete){$this->buffer='';return;}
            $offset=$end+($buffer[$end]==="\r"&&($buffer[$end+1]??null)==="\n"?2:1);
        }
        $this->buffer=substr($buffer,$offset);
        if(strlen($this->buffer)+strlen($this->data)>self::MAX_EVENT_BYTES)
            throw new RuntimeException('Listening room provider event exceeded its limit.');
    }

    public function result(string $model): array {
        // A final bare carriage return can terminate the last blank line.
        if($this->buffer==="\r")$this->line('');
        if(!$this->complete||$this->inputTokens===null||$this->outputTokens===null)
            throw new SfAgentSceneStreamInterrupted('stream_incomplete');
        return ['provider'=>$this->provider,'model'=>$model,'text'=>trim($this->text),
            'input_tokens'=>$this->inputTokens,'output_tokens'=>$this->outputTokens,'raw_id'=>$this->responseId];
    }

    public function ended(): bool {
        return $this->complete;
    }

    public function lastTextAt(): ?int {
        return $this->lastTextAt;
    }

    private function line(string $line): void {
        if(strlen($line)>self::MAX_EVENT_BYTES)throw new RuntimeException('Listening room provider event exceeded its limit.');
        if($this->firstLine){
            $this->firstLine=false;
            if(str_starts_with($line,"\xef\xbb\xbf"))$line=substr($line,3);
        }
        if($line===''){
            if($this->data!==''&&!$this->complete)$this->dispatch();
            $this->event='';$this->data='';
            return;
        }
        [$field,$value]=array_pad(explode(':',$line,2),2,'');
        if(str_starts_with($value,' '))$value=substr($value,1);
        if($field==='event')$this->event=$value;
        if($field==='data'){
            if(strlen($this->data)+strlen($value)+1>self::MAX_EVENT_BYTES)
                throw new RuntimeException('Listening room provider event exceeded its limit.');
            $this->data.=$value."\n";
        }
    }

    private function dispatch(): void {
        if(trim($this->data)==='[DONE]')return;
        $event=json_decode($this->data,true,32);
        $type=$event['type']??null;
        if(!is_string($type)||($this->event!==''&&$type!==$this->event))
            throw new RuntimeException('Listening room provider event was invalid.');
        if($type==='error')$this->providerError($event['code']??$event['error']['type']??null);
        if($this->provider==='openai')$this->openai($type,$event);
        else $this->anthropic($type,$event);
    }

    private function openai(string $type,array $event): void {
        if($type==='response.incomplete'){
            $reason=$event['response']['incomplete_details']['reason']??null;
            throw new SfAgentSceneStreamInterrupted(match($reason){
                'max_output_tokens'=>'output_limit',
                'content_filter'=>'provider_refused',
                default=>'stream_incomplete',
            });
        }
        if(in_array($type,['response.refusal.delta','response.refusal.done'],true))
            throw new SfAgentSceneStreamInterrupted('provider_refused');
        if($type==='response.failed')$this->providerError($event['response']['error']['code']??null);
        if($type==='response.cancelled')throw new SfAgentSceneStreamInterrupted('stream_incomplete');
        if($type==='response.output_text.delta')$this->append($event['delta']??null);
        if($type!=='response.completed')return;
        $response=$event['response']??[];
        if(!is_array($response)||($response['status']??'completed')!=='completed')
            throw new RuntimeException('Listening room provider could not finish generating.');
        $this->recordUsage($response['usage']??null);
        $this->responseId=is_string($response['id']??null)?$response['id']:null;
        $this->complete=true;
    }

    private function providerError(mixed $code): never {
        throw new SfAgentSceneStreamInterrupted(match($code){
            'content_policy_violation','content_filter'=>'provider_refused',
            'server_error','api_error','overloaded_error'=>'provider_busy',
            default=>'provider_unavailable',
        });
    }

    private function anthropic(string $type,array $event): void {
        switch($type){
            case 'message_start':
                $message=$event['message']??[];
                $this->responseId=is_string($message['id']??null)?$message['id']:null;
                $this->recordUsage(['input_tokens'=>$message['usage']['input_tokens']??null]);
                break;
            case 'content_block_start':
                if(($event['content_block']['type']??null)==='text')$this->append($event['content_block']['text']??null);
                break;
            case 'content_block_delta':
                if(($event['delta']['type']??null)==='text_delta')$this->append($event['delta']['text']??null);
                break;
            case 'message_delta':
                $reason=$event['delta']['stop_reason']??null;
                if($reason===null)break;
                if($reason==='max_tokens')throw new SfAgentSceneStreamInterrupted('output_limit');
                if($reason==='refusal')throw new SfAgentSceneStreamInterrupted('provider_refused');
                if(!in_array($reason,['end_turn','stop_sequence'],true))
                    throw new RuntimeException('Listening room provider could not finish generating.');
                // Anthropic's terminal usage is cumulative. Keep any input count omitted by the delta.
                $this->recordUsage($event['usage']??null);
                break;
            case 'message_stop':
                $this->complete=true;
                break;
        }
    }

    private function recordUsage(mixed $usage): void {
        if(!is_array($usage))return;
        $input=$usage['input_tokens']??null;$output=$usage['output_tokens']??null;
        if(is_int($input)&&$input>=0)$this->inputTokens=$input;
        if(is_int($output)&&$output>=0)$this->outputTokens=$output;
    }

    private function append(mixed $text): void {
        if(!is_string($text))throw new RuntimeException('Listening room provider text event was invalid.');
        if($text==='')return;
        if(strlen($this->text)+strlen($text)>self::MAX_TEXT_BYTES)
            throw new RuntimeException('Listening room provider text exceeded its limit.');
        $this->text.=$text;
        $this->lastTextAt=hrtime(true);
        if($this->onDelta!==null)($this->onDelta)($text);
    }
}

function sf_agent_scene_stream(array $provider,string $system,string $message,int $maxOutput,?int $timeout,?callable $onDelta=null,bool $preferLowReasoning=false,?int $idleTimeout=null,?callable $checkCanceled=null): array {
    $name=$provider['provider']??'';
    $key=$provider['api_key']??'';
    if(!is_string($key)||$key===''||preg_match('/[\r\n]/',$key))throw new RuntimeException('Listening room provider is unavailable.');
    $model=trim((string)($provider['model']??''));
    $headers=['Content-Type: application/json','Accept: text/event-stream','User-Agent: Stonefellow/0.8'];
    if($name==='openai'){
        $url='https://api.openai.com/v1/responses';
        $model=$model?:'gpt-6-luna';
        $headers[]='Authorization: Bearer '.$key;
        $payload=['model'=>$model,'instructions'=>$system,'input'=>[['role'=>'user','content'=>[['type'=>'input_text','text'=>$message]]]],
            'max_output_tokens'=>$maxOutput,'stream'=>true,'store'=>false];
        if($preferLowReasoning&&$model==='gpt-6-luna')$payload['reasoning']=['effort'=>'low'];
    }elseif($name==='anthropic'){
        $url='https://api.anthropic.com/v1/messages';
        $model=$model?:SF_CLAUDE_FAST_MODEL;
        $headers[]='x-api-key: '.$key;
        $headers[]='anthropic-version: 2023-06-01';
        $payload=['model'=>$model,'system'=>$system,'messages'=>[['role'=>'user','content'=>$message]],'max_tokens'=>$maxOutput,'stream'=>true];
    }else throw new RuntimeException('Listening room provider is unavailable.');
    $body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($body===false)throw new RuntimeException('Could not encode the listening room request.');
    $parser=new SfAgentSceneStreamParser($name,$onDelta);
    if($timeout!==null)$timeout=max(1,$timeout);
    if(function_exists('curl_init')&&function_exists('curl_exec'))sf_agent_scene_stream_curl($url,$headers,$body,$timeout,$parser,$idleTimeout,$checkCanceled);
    else sf_agent_scene_stream_read($url,$headers,$body,$timeout,$parser,$idleTimeout,$checkCanceled);
    return $parser->result($model);
}

function sf_agent_scene_stream_curl(string $url,array $headers,string $body,?int $timeout,SfAgentSceneStreamParser $parser,?int $idleTimeout=null,?callable $checkCanceled=null): void {
    if($timeout===null&&$idleTimeout===null&&$checkCanceled===null)throw new InvalidArgumentException('A provider stream needs a time limit or a cancellation check.');
    if($checkCanceled!==null&&$checkCanceled())throw new SfAgentSceneBuildCanceled();
    $connectTimeout=min(10,$timeout??$idleTimeout??10);
    $handle=curl_init($url);
    if($handle===false)throw new RuntimeException('Listening room provider connection is unavailable.');
    $status=0;$isSse=false;
    $idleExpired=false;
    $canceled=false;
    $stopCheck=null;
    if($idleTimeout!==null||$checkCanceled!==null){
        $started=hrtime(true);
        $window=$idleTimeout===null?null:min($timeout??PHP_INT_MAX,max(1,$idleTimeout))*1_000_000_000;
        // Provider keepalives and reasoning events are not visible code progress.
        $stopCheck=static function()use($parser,$started,$window,$checkCanceled,&$idleExpired,&$canceled):int {
            $canceled=$canceled||($checkCanceled!==null&&$checkCanceled());
            $idleExpired=$window!==null&&hrtime(true)-($parser->lastTextAt()??$started)>=$window;
            return $canceled||$idleExpired?1:0;
        };
    }
    try{
        $options=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_TIMEOUT=>$timeout??0,CURLOPT_CONNECTTIMEOUT=>max(1,$connectTimeout),CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$status,&$isSse):int {
                sf_agent_scene_stream_header($line,$status,$isSse);
                return strlen($line);
            },CURLOPT_WRITEFUNCTION=>static function($curl,string $bytes)use($parser,&$status,&$isSse,$stopCheck):int {
                if($status<200||$status>=300||!$isSse||($stopCheck!==null&&$stopCheck()))return 0;
                $parser->push($bytes);
                // A terminal provider event can precede a socket that stays open.
                return $parser->ended()?0:strlen($bytes);
            }];
        if($stopCheck!==null){
            $options[CURLOPT_NOPROGRESS]=false;
            $progressOption=defined('CURLOPT_XFERINFOFUNCTION')?CURLOPT_XFERINFOFUNCTION:CURLOPT_PROGRESSFUNCTION;
            $options[$progressOption]=$stopCheck;
        }
        curl_setopt_array($handle,$options);
        $ok=curl_exec($handle);
        $timedOut=curl_errno($handle)===CURLE_OPERATION_TIMEDOUT;
    }finally{curl_close($handle);}
    if($canceled)throw new SfAgentSceneBuildCanceled();
    if($status>=400&&$status<=599)throw new SfAgentSceneStreamInterrupted('http_'.$status);
    if($parser->ended())return;
    if($timedOut||$idleExpired)throw new SfAgentSceneStreamInterrupted('timeout');
    if($ok===false&&$status>=200&&$status<300&&$isSse)throw new SfAgentSceneStreamInterrupted('stream_incomplete');
    if($ok===false||$status<200||$status>=300||!$isSse)throw new RuntimeException('Listening room provider connection did not complete.');
}

function sf_agent_scene_stream_read(string $url,array $headers,string $body,?int $timeout,SfAgentSceneStreamParser $parser,?int $idleTimeout=null,?callable $checkCanceled=null): void {
    if($timeout===null&&$idleTimeout===null&&$checkCanceled===null)throw new InvalidArgumentException('A provider stream needs a time limit or a cancellation check.');
    if($checkCanceled!==null&&$checkCanceled())throw new SfAgentSceneBuildCanceled();
    $openTimeout=$idleTimeout===null?($timeout??-1):min($timeout??PHP_INT_MAX,max(1,$idleTimeout));
    $started=hrtime(true);
    $deadline=$timeout===null?PHP_INT_MAX:$started+$timeout*1_000_000_000;
    $idleWindow=$idleTimeout===null?null:$openTimeout*1_000_000_000;
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,
        'timeout'=>$openTimeout,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $stream=@fopen($url,'rb',false,$context);
    if($stream===false){
        if($checkCanceled!==null&&$checkCanceled())throw new SfAgentSceneBuildCanceled();
        if($idleWindow!==null&&hrtime(true)-$started>=$idleWindow)throw new SfAgentSceneStreamInterrupted('timeout');
        throw new RuntimeException('Listening room provider connection is unavailable.');
    }
    try{
        if($checkCanceled!==null&&$checkCanceled())throw new SfAgentSceneBuildCanceled();
        $headers=stream_get_meta_data($stream)['wrapper_data']??null;
        if(is_array($headers)){
            $status=0;$isSse=false;
            foreach($headers as $line)sf_agent_scene_stream_header($line,$status,$isSse);
            if($status>=400&&$status<=599)throw new SfAgentSceneStreamInterrupted('http_'.$status);
            if($status<200||$status>=300||!$isSse)throw new RuntimeException('Listening room provider response was invalid.');
        }
        // Blocking reads can hold short chunked SSE events until the stream ends.
        $nonblocking=@stream_set_blocking($stream,false);
        $pollBlockingRead=!$nonblocking&&$checkCanceled!==null;
        while(!$parser->ended()&&!feof($stream)){
            if($checkCanceled!==null&&$checkCanceled())throw new SfAgentSceneBuildCanceled();
            $readDeadline=$idleWindow===null?$deadline:min($deadline,($parser->lastTextAt()??$started)+$idleWindow);
            $remaining=(int)(($readDeadline-hrtime(true))/1000);
            if($remaining<1)throw new SfAgentSceneStreamInterrupted('timeout');
            if(!$nonblocking){
                $readTimeout=$pollBlockingRead?min($remaining,1_000_000):$remaining;
                @stream_set_timeout($stream,intdiv($readTimeout,1_000_000),$readTimeout%1_000_000);
            }
            $bytes=@fread($stream,8192);
            if($checkCanceled!==null&&$checkCanceled())throw new SfAgentSceneBuildCanceled();
            if($idleWindow!==null&&hrtime(true)>=$readDeadline)throw new SfAgentSceneStreamInterrupted('timeout');
            if($pollBlockingRead&&($bytes===false||$bytes==='')&&!empty(stream_get_meta_data($stream)['timed_out']))continue;
            if($bytes===false)throw new SfAgentSceneStreamInterrupted(!empty(stream_get_meta_data($stream)['timed_out'])?'timeout':'stream_incomplete');
            if($bytes===''){
                if(!feof($stream))usleep(min(20_000,$remaining));
                continue;
            }
            $parser->push($bytes);
        }
    }finally{fclose($stream);}
}

function sf_agent_scene_stream_header(string $line,int &$status,bool &$isSse): void {
    if(preg_match('~\AHTTP/\S+\s+(\d{3})(?:\s|$)~i',$line,$match)){
        $status=(int)$match[1];$isSse=false;
    }elseif(stripos($line,'Content-Type:')===0){
        $isSse=strtolower(trim(explode(';',substr($line,13),2)[0]))==='text/event-stream';
    }
}
