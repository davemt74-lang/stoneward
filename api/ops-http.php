<?php
declare(strict_types=1);

function sf_ops_http_get_json(string $url,array $headers=[],int $timeout=25): array {
    if(function_exists('curl_init')){
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'],$headers),CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>min(8,$timeout),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'Stonefellow/1.2']);
        $start=microtime(true);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);$lat=(int)round((microtime(true)-$start)*1000);
        if($raw===false)throw new RuntimeException($err?:'Connection failed.');
    }else{
        $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",array_merge(['Accept: application/json','User-Agent: Stonefellow/1.2'],$headers)),
            'timeout'=>$timeout,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0]]);
        $start=microtime(true);$raw=@file_get_contents($url,false,$ctx);$lat=(int)round((microtime(true)-$start)*1000);if($raw===false)throw new RuntimeException('Connection failed.');$status=200;foreach($http_response_header??[] as $line)if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m))$status=(int)$m[1];
    }
    $j=json_decode((string)$raw,true);if($status<200||$status>=300)throw new RuntimeException((string)($j['error']['message']??$j['message']??('HTTP '.$status)));
    return ['json'=>is_array($j)?$j:[],'latency_ms'=>$lat,'status'=>$status];
}
