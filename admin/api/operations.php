<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';$me=sf_admin_require_auth($write);sf_ops_ensure_schema();

function sf_ops_http_get_json(string $url,array $headers=[],int $timeout=25): array {
    if(function_exists('curl_init')){
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'],$headers),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'Stonefellow/1.2']);
        $start=microtime(true);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);$lat=(int)round((microtime(true)-$start)*1000);
        if($raw===false)throw new RuntimeException($err?:'Connection failed.');
    } else {
        $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",array_merge(['Accept: application/json','User-Agent: Stonefellow/1.2'],$headers)),'timeout'=>$timeout,'ignore_errors'=>true]]);
        $start=microtime(true);$raw=@file_get_contents($url,false,$ctx);$lat=(int)round((microtime(true)-$start)*1000);if($raw===false)throw new RuntimeException('Connection failed.');$status=200;foreach($http_response_header??[] as $line)if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m))$status=(int)$m[1];
    }
    $j=json_decode((string)$raw,true);if($status<200||$status>=300)throw new RuntimeException((string)($j['error']['message']??$j['message']??('HTTP '.$status)));
    return ['json'=>is_array($j)?$j:[],'latency_ms'=>$lat,'status'=>$status];
}
function sf_ops_test_provider(string $provider,array $admin): array {
    $start=microtime(true);$details=[];
    try{
        if($provider==='openai'){
            $key=sf_ai_provider_secret('openai');if($key==='')throw new RuntimeException('OpenAI API key is not configured.');
            $r=sf_ops_http_get_json('https://api.openai.com/v1/models',['Authorization: Bearer '.$key]);$details=['models'=>count((array)($r['json']['data']??[]))];$lat=$r['latency_ms'];
        }elseif($provider==='anthropic'){
            $key=sf_ai_provider_secret('anthropic');if($key==='')throw new RuntimeException('Anthropic API key is not configured.');
            $row=sf_ai_provider_row('anthropic');$start=microtime(true);$r=sf_anthropic_call(['provider'=>'anthropic','api_key'=>$key,'model'=>(string)($row['model']??'')],'Stonefellow provider health check. Reply with OK only.',[],'Reply OK.',8);$lat=(int)round((microtime(true)-$start)*1000);$details=['model'=>$r['model']??'','output_tokens'=>$r['output_tokens']??0];
        }elseif($provider==='elevenlabs'){
            $key=sf_ai_provider_secret('elevenlabs');if($key==='')throw new RuntimeException('ElevenLabs API key is not configured.');
            $r=sf_ops_http_get_json('https://api.elevenlabs.io/v1/user',['xi-api-key: '.$key]);$details=['tier'=>(string)($r['json']['subscription']['tier']??'')];$lat=$r['latency_ms'];
        }elseif($provider==='stripe'){
            if(sf_stripe_secret()==='')throw new RuntimeException('Stripe secret key is not configured.');
            $start=microtime(true);$r=sf_stripe_request('GET','balance');$lat=(int)round((microtime(true)-$start)*1000);$details=['object'=>(string)($r['object']??'balance')];
        }elseif($provider==='email'){
            $settings=sf_customer_email_settings();$recipient=(string)$admin['email'];$start=microtime(true);$r=sf_transactional_email((int)$admin['id'],$recipient,'Stonefellow email health check',"Stonefellow transactional email health check.\n\nIf you received this message, the configured delivery path is working.",'health_check');$lat=(int)round((microtime(true)-$start)*1000);$details=['delivery_status'=>$r['status']??'queued','mode'=>$settings['delivery_mode']??'log'];
        }elseif($provider==='jev'){
            $d=sf_ai_resolve_decision();if(!$d)throw new RuntimeException('JEV is not enabled/configured.');
            $url=sf_jev_endpoint((string)($d['endpoint_url']??''));if($url==='')throw new RuntimeException('JEV endpoint is missing.');
            $start=microtime(true);$j=sf_http_json($url,['Authorization: Bearer '.$d['api_key']],['requestId'=>sf_agent_uuid(),'model'=>trim((string)$d['model'])?:'typesafe/jev-1.13','state'=>['health_check'=>true],'questions'=>['health'=>['type'=>'choice','instructions'=>'Choose ok.','criteria'=>['ok'=>'Service is reachable.']]]],25);$lat=(int)round((microtime(true)-$start)*1000);$details=['run_id'=>(string)($j['data']['id']??'')];
        }else throw new RuntimeException('Unsupported provider.');
        $row=sf_provider_health_store($provider,'healthy',$lat,'Connection succeeded.',$details,(int)$admin['id']);sf_log_admin_action((int)$admin['id'],'provider_health_test','provider',$provider,$row);return $row+$details;
    }catch(Throwable $e){$lat=(int)round((microtime(true)-$start)*1000);$row=sf_provider_health_store($provider,'error',$lat,$e->getMessage(),$details,(int)$admin['id']);sf_log_site_event('error','provider_health',$provider.': '.$e->getMessage());return $row;}
}
function sf_catalog_validation(): array {
    $rows=[];$summary=['tracks'=>0,'ready'=>0,'warnings'=>0,'errors'=>0];
    foreach(sf_catalog() as $t){$summary['tracks']++;$m=(array)($t['metadata']??[]);$issues=[];$severity='ready';
        if(empty($t['title'])||empty($t['audio'])){$issues[]='Missing title or audio';$severity='error';}
        if(empty($m['isrc'])){$issues[]='Missing ISRC';if($severity!=='error')$severity='warning';}
        if(empty($m['words_by'])&&empty($m['music_by'])){$issues[]='Missing songwriting credits';if($severity!=='error')$severity='warning';}
        if(empty($t['artwork'])&&empty($m['album_cover'])){$issues[]='Missing artwork';if($severity!=='error')$severity='warning';}
        $summary[$severity==='ready'?'ready':($severity==='error'?'errors':'warnings')]++;
        $rows[]=['id'=>$t['id']??'','title'=>$t['title']??'','release'=>$t['release']??'','severity'=>$severity,'issues'=>$issues];
    }return ['summary'=>$summary,'tracks'=>$rows];
}
if(!$write){
    sf_json_response(['ok'=>true,'health'=>sf_provider_health_rows(),'audit'=>sf_ops_recent_admin_audit(100),'events'=>sf_ops_recent_site_events(100),'catalog_validation'=>sf_catalog_validation(),'readiness'=>sf_ops_runtime_readiness(),'site'=>sf_site_settings(),'csrf'=>sf_admin_csrf()]);
}
$b=sf_request_json();$action=(string)($b['action']??'');
if($action==='test_provider')sf_json_response(['ok'=>true,'result'=>sf_ops_test_provider((string)($b['provider']??''),$me),'health'=>sf_provider_health_rows(),'csrf'=>sf_admin_csrf()]);
if($action==='notify_user'){
    $uid=(int)($b['user_id']??0);if($uid<1)sf_json_response(['ok'=>false,'message'=>'Choose a user.'],422);
    $id=sf_notify_user($uid,(string)($b['kind']??'admin'),(string)($b['title']??'Stonefellow update'),(string)($b['body']??''),(string)($b['link_url']??''));sf_log_admin_action((int)$me['id'],'notification_sent','user',(string)$uid,['notification_id'=>$id]);sf_json_response(['ok'=>true,'id'=>$id,'csrf'=>sf_admin_csrf()]);
}
if($action==='clear_site_events'){sf_db()->exec('DELETE FROM site_event_log');sf_log_admin_action((int)$me['id'],'site_events_cleared');sf_json_response(['ok'=>true,'csrf'=>sf_admin_csrf()]);}
sf_json_response(['ok'=>false,'message'=>'Unsupported operations action.'],422);
