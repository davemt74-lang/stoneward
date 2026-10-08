<?php
declare(strict_types=1);

function sf_http_json(string $url,array $headers,array $payload,int $timeout=45): array {
    $body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($body===false)throw new RuntimeException('Could not encode provider request.');
    if(function_exists('curl_init')){
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','Accept: application/json'],$headers),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_USERAGENT=>'Stonefellow/0.8']);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
        if($raw===false)throw new RuntimeException('Provider connection failed: '.$err);
    } else {
        $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",array_merge(['Content-Type: application/json','Accept: application/json','User-Agent: Stonefellow/0.8'],$headers)),'content'=>$body,'timeout'=>$timeout,'ignore_errors'=>true]]);
        $raw=@file_get_contents($url,false,$ctx);if($raw===false)throw new RuntimeException('Provider connection failed.');
        $status=200;foreach($http_response_header??[] as $line)if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m))$status=(int)$m[1];
    }
    $json=json_decode((string)$raw,true);
    if(!is_array($json))throw new RuntimeException('Provider returned an invalid response.');
    if($status<200||$status>=300){$message=(string)($json['error']['message']??$json['message']??('HTTP '.$status));throw new RuntimeException($message);}
    return $json;
}
function sf_http_binary(string $url,array $headers,array $payload,int $timeout=45): string {
    $body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($body===false)throw new RuntimeException('Could not encode voice request.');
    if(function_exists('curl_init')){
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','Accept: audio/mpeg'],$headers),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_USERAGENT=>'Stonefellow/0.8']);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
        if($raw===false)throw new RuntimeException('Voice provider connection failed: '.$err);
        if($status<200||$status>=300){$j=json_decode((string)$raw,true);throw new RuntimeException((string)($j['detail']['message']??$j['message']??('Voice HTTP '.$status)));}
        return (string)$raw;
    }
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",array_merge(['Content-Type: application/json','Accept: audio/mpeg','User-Agent: Stonefellow/0.8'],$headers)),'content'=>$body,'timeout'=>$timeout,'ignore_errors'=>true]]);
    $raw=@file_get_contents($url,false,$ctx);if($raw===false)throw new RuntimeException('Voice provider connection failed.');
    $status=200;foreach($http_response_header??[] as $line)if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m))$status=(int)$m[1];
    if($status<200||$status>=300){$j=json_decode((string)$raw,true);throw new RuntimeException((string)($j['detail']['message']??$j['message']??('Voice HTTP '.$status)));}
    return (string)$raw;
}
function sf_agent_uuid(): string {$b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
function sf_agent_excerpt(string $text,int $max=500): string {$text=preg_replace('/\s+/u',' ',trim($text))??trim($text);return function_exists('mb_substr')?mb_substr($text,0,$max):substr($text,0,$max);}

function sf_agent_brain_ensure_schema(): void { sf_entitlements_ensure_schema(); }
function sf_agent_brain_log(int $userId,string $conversationId,string $phase,array $data): void {
    sf_agent_brain_ensure_schema();$q=sf_db()->prepare('INSERT INTO agent_brain_decisions(user_id,conversation_id,phase,route,action,context_profile,needs_llm,requires_confirmation,request_excerpt,response_excerpt,provider_run_id,provider_status,input_tokens,output_tokens,details_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $details=$data;$request=(string)($details['request']??'');$response=(string)($details['response']??'');unset($details['request'],$details['response']);
    $q->execute([$userId,$conversationId,$phase,(string)($data['route']??''),(string)($data['action']??''),(string)($data['context_profile']??''),!empty($data['needs_llm'])?1:0,!empty($data['requires_confirmation'])?1:0,sf_agent_excerpt($request),sf_agent_excerpt($response),(string)($data['run_id']??''),(string)($data['status']??''),(int)($data['input_tokens']??0),(int)($data['output_tokens']??0),json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}',gmdate('c')]);
}

function sf_agent_context(string $query,string $profile='catalog'): string {
    if($profile==='none')return '';
    $terms=array_values(array_filter(preg_split('/[^a-z0-9]+/i',strtolower($query))?:[],fn($x)=>strlen($x)>2));
    $score=function(string $text)use($terms){$t=strtolower($text);$n=0;foreach($terms as $w)if(str_contains($t,$w))$n++;return $n;};
    $sections=[];
    if(in_array($profile,['catalog','knowledge','recommendation','commerce'],true)){
        $tracks=sf_catalog();usort($tracks,fn($a,$b)=>$score(json_encode($b)?:'')<=>$score(json_encode($a)?:''));$tracks=array_slice($tracks,0,$profile==='knowledge'?6:10);
        $lines=[];foreach($tracks as $t){$m=(array)($t['metadata']??[]);$lines[]='- id='.($t['id']??'').' | '.($t['title']??'').' | release '.($t['release']??'').' | moods '.implode(', ',(array)($t['mood']??[])).' | story '.substr((string)($t['story']??''),0,360).' | credits '.implode('; ',(array)($t['credits']??[])).' | writer '.($m['words_by']??'').' | music '.($m['music_by']??'').' | producer '.($m['producer']??'').' | ISRC '.($m['isrc']??'');}
        $sections[]="CATALOG\n".implode("\n",$lines);
        $releaseLines=[];$rp=SF_ROOT.'/data/releases.json';$rels=is_file($rp)?json_decode((string)file_get_contents($rp),true):[];
        foreach((array)$rels as $r){if(($r['state']??'published')!=='published'||isset($r['agent_discoverable'])&&!$r['agent_discoverable'])continue;$releaseLines[]='- '.($r['title']??'').' ('.($r['type']??'release').') '.substr((string)($r['description']??''),0,280);if(count($releaseLines)>=6)break;}
        if($releaseLines)$sections[]="RELEASES\n".implode("\n",$releaseLines);
    }
    if($profile==='knowledge'){
        $kb=[];$kp=SF_ROOT.'/storage/knowledge/index.json';$kd=is_file($kp)?json_decode((string)file_get_contents($kp),true):[];
        foreach((array)($kd['files']??[]) as $f){if(empty($f['public_agent']))continue;$hay=(string)($f['name']??'').' '.(string)($f['notes']??'').' '.(string)($f['text']??'');if($score($hay)>0||!$terms){$kb[]='- '.($f['name']??'knowledge').': '.substr((string)($f['text']??$f['notes']??''),0,1100);}if(count($kb)>=6)break;}
        $sections[]="APPROVED KNOWLEDGE\n".implode("\n",$kb);
    }
    if($profile==='commerce')$sections[]="COMMERCE POLICY\nThe agent may explain products and open the cart or builder, but it may not claim a purchase is complete or charge money. Checkout remains a user-confirmed UI action.";
    if($profile==='account')$sections[]="ACCOUNT POLICY\nThe agent may explain plans, Active Tokens and account navigation, but must not expose other users or administrator-only data.";
    return implode("\n\n",$sections);
}

function sf_agent_history(int $userId,string $conversationId,int $limit=10): array {
    $q=sf_db()->prepare('SELECT role,content FROM agent_messages WHERE conversation_id=? AND user_id=? ORDER BY id DESC LIMIT '.max(1,min(30,$limit)));$q->execute([$conversationId,$userId]);return array_reverse($q->fetchAll());
}
function sf_agent_save_message(int $userId,string $conversationId,string $role,string $content,array $usage=[]): void {
    $pdo=sf_db();$now=gmdate('c');$q=$pdo->prepare('SELECT id FROM agent_conversations WHERE id=? AND user_id=?');$q->execute([$conversationId,$userId]);
    if(!$q->fetchColumn()){$i=$pdo->prepare('INSERT INTO agent_conversations(id,user_id,title,created_at,updated_at) VALUES(?,?,?,?,?)');$i->execute([$conversationId,$userId,substr($content,0,120),$now,$now]);}
    else{$u=$pdo->prepare('UPDATE agent_conversations SET updated_at=? WHERE id=?');$u->execute([$now,$conversationId]);}
    $i=$pdo->prepare('INSERT INTO agent_messages(conversation_id,user_id,role,content,provider,model,input_tokens,output_tokens,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $i->execute([$conversationId,$userId,$role,$content,(string)($usage['provider']??''),(string)($usage['model']??''),(int)($usage['input_tokens']??0),(int)($usage['output_tokens']??0),$now]);
}

function sf_agent_find_track(string $message,?string $activeId=null): ?array {
    $n=strtolower(preg_replace('/[^a-z0-9 ]+/i',' ', $message)??$message);$best=null;$bestLen=0;
    foreach(sf_catalog() as $t){$title=strtolower(trim((string)($t['title']??'')));if($title!==''&&str_contains($n,$title)&&strlen($title)>$bestLen){$best=$t;$bestLen=strlen($title);}}
    if($best)return $best;
    if($activeId)foreach(sf_catalog() as $t)if((string)($t['id']??'')===$activeId)return $t;
    return null;
}
function sf_agent_track_score(array $t,string $message): int {
    $q=strtolower($message);$score=0;$hay=strtolower(implode(' ',array_merge([(string)($t['title']??''),(string)($t['release']??'')],(array)($t['mood']??[]),(array)($t['themes']??[]))));
    foreach(array_filter(preg_split('/[^a-z0-9]+/',$q)?:[],fn($w)=>strlen($w)>2) as $w)if(str_contains($hay,$w))$score+=2;
    $energy=(int)($t['energy']??3);if(preg_match('/quiet|mellow|soft|calm|reflective/',$q)&&$energy<=2)$score+=8;if(preg_match('/dark|heavy|loud|electric|intense/',$q)&&$energy>=4)$score+=8;if(preg_match('/drive|road|driving/',$q)&&str_contains($hay,'driv'))$score+=8;return $score;
}
function sf_agent_recommend_track(string $message,?string $excludeId=null): ?array {
    $rows=[];foreach(sf_catalog() as $t){if($excludeId&&($t['id']??'')===$excludeId)continue;$rows[]=['t'=>$t,'s'=>sf_agent_track_score($t,$message)];}
    usort($rows,fn($a,$b)=>$b['s']<=>$a['s']);return $rows[0]['t']??null;
}
function sf_agent_playlist_name(string $message,string $fallback='Stonefellow Mix'): string {
    if(preg_match('/\b(?:called|named)\s+[“"\']?([^”"\']{2,80})[”"\']?/iu',$message,$m))return sf_clean_text(trim((string)$m[1]),80);
    if(preg_match('/\bplaylist\s+(?:for|about)\s+(.{2,60})$/iu',$message,$m))return sf_clean_text('Stonefellow — '.trim((string)$m[1]),80);
    return $fallback;
}
function sf_agent_local_route(string $message,array $client=[]): string {
    $q=strtolower(trim($message));
    if(preg_match('/\b(pause|stop music|stop song)\b/',$q))return 'player_pause';
    if(preg_match('/\b(next song|next track|skip)\b/',$q))return 'player_next';
    if(preg_match('/\b(previous song|previous track|go back a song)\b/',$q))return 'player_previous';
    if(preg_match('/\b(play|listen to)\b/',$q)&&sf_agent_find_track($message,(string)($client['active_track_id']??'')))return 'player_play_named';
    if(preg_match('/recommend|something (dark|quiet|mellow|heavy|similar)|what should i (hear|listen)|play me something/',$q))return 'player_recommend';
    if(preg_match('/catalog|show (me )?(songs|music)|look around/',$q))return 'catalog_browse';
    if(preg_match('/album|release| ep |single/',$q))return 'release_browse';
    if(preg_match('/save (?:this |the )?(?:agent )?(?:listening )?session|save (?:these|those) songs/',$q)&&str_contains($q,'playlist'))return 'playlist_save_session';
    if(preg_match('/(?:make|create|build|curate).*playlist|playlist.*(?:make|create|build|curate)/',$q))return 'playlist_create';
    if(preg_match('/my playlists|show (?:me )?(?:my )?playlists|open playlists/',$q))return 'playlist_open';
    if(preg_match('/make|create|build/',$q)&&preg_match('/record|vinyl|cassette|mixtape/',$q))return 'builder_open';
    if(preg_match('/add .* (record|vinyl|cassette|side [ab])|put .* on/',$q))return 'builder_add';
    if(preg_match('/cart|checkout/',$q))return 'cart_open';
    if(preg_match('/\b(buy|purchase|order)\b/',$q))return 'purchase_request';
    if(preg_match('/my account|my library|my purchases|profile/',$q))return 'account_open';
    if(preg_match('/plan|package|subscription|free trial|active tokens?/',$q))return 'plans_open';
    if(preg_match('/lyrics?|credits?|who wrote|who produced|isrc|bmi|ascap|story|about this song/',$q))return 'track_info';
    if(preg_match('/knowledge|notes?|document|history|why did|what does|meaning/',$q))return 'knowledge_question';
    return 'general_conversation';
}
function sf_agent_policy(string $route,string $message,array $client=[]): array {
    $active=(string)($client['active_track_id']??'');$track=sf_agent_find_track($message,$active);$action=['type'=>'none'];$text='';$profile='none';$needs=false;$confirm=false;
    switch($route){
        case 'player_play_named':
            if($track){$action=['type'=>'play_track','track_id'=>(string)$track['id']];$text='Playing “'.($track['title']??'').'.”';}
            else {$route='player_recommend';$track=sf_agent_recommend_track($message,$active);if($track){$action=['type'=>'play_track','track_id'=>(string)$track['id']];$text='I’d start with “'.($track['title']??'').'.”';}}
            break;
        case 'player_recommend':
            $track=sf_agent_recommend_track($message,$active);if($track){$action=['type'=>'play_track','track_id'=>(string)$track['id']];$text='I’d start with “'.($track['title']??'').'.”';}$profile='recommendation';break;
        case 'player_pause':$action=['type'=>'pause_player'];$text='Paused.';break;
        case 'player_next':$action=['type'=>'next_track'];$text='Next track.';break;
        case 'player_previous':$action=['type'=>'previous_track'];$text='Going back one track.';break;
        case 'catalog_browse':$action=['type'=>'open_view','view'=>'music'];$text='Here’s the Stonefellow catalog.';break;
        case 'release_browse':$action=['type'=>'open_view','view'=>'releases'];$text='Here are the releases.';$profile='catalog';break;
        case 'playlist_open':$action=['type'=>'open_view','view'=>'account'];$text='Your playlists are in My Stonefellow.';$profile='account';break;
        case 'playlist_create':
            $ids=sf_agent_playlist_track_ids($message,$active?:null,7);$name=sf_agent_playlist_name($message);$action=['type'=>'create_playlist','name'=>$name,'track_ids'=>$ids,'visibility'=>'private','source_type'=>'agent'];$text='I curated “'.$name.'” and saved it to your playlists.';$profile='recommendation';break;
        case 'playlist_save_session':
            $name=sf_agent_playlist_name($message,'Agent Listening Session');$action=['type'=>'save_agent_session','name'=>$name];$text='I’ll save the recent songs I played for you as “'.$name.'”.';$profile='account';break;
        case 'builder_open':$action=['type'=>'open_view','view'=>'builder'];$text='Let’s build your record.';break;
        case 'builder_add':
            if($track){$action=['type'=>'builder_add_track','track_id'=>(string)$track['id']];$text='I’ll add “'.($track['title']??'').'” to the current build.';}
            else {$action=['type'=>'open_view','view'=>'builder'];$text='Open the builder and tell me which track you want to add.';}
            break;
        case 'cart_open':$action=['type'=>'open_view','view'=>'cart'];$text='Here’s your cart.';$profile='commerce';break;
        case 'purchase_request':$action=['type'=>'open_view','view'=>'cart'];$text='I can take you to the cart. You’ll confirm the purchase yourself at checkout.';$profile='commerce';$confirm=true;break;
        case 'account_open':$action=['type'=>'open_view','view'=>'account'];$text='Here’s your account.';$profile='account';break;
        case 'plans_open':$action=['type'=>'open_view','view'=>'plans'];$text='Here are the Stonefellow plans and Active Token options.';$profile='account';break;
        case 'track_info':
            $profile='catalog';$needs=true;if($track)$action=['type'=>'show_track','track_id'=>(string)$track['id']];break;
        case 'knowledge_question':$profile='knowledge';$needs=true;break;
        case 'general_conversation':default:$profile='catalog';$needs=true;break;
    }
    return ['route'=>$route,'action'=>$action,'action_name'=>(string)($action['type']??'none'),'context_profile'=>$profile,'needs_llm'=>$needs,'requires_confirmation'=>$confirm,'text'=>$text,'track'=>$track];
}
function sf_jev_endpoint(string $url): string {
    $url=rtrim(trim($url),'/');if($url==='')return '';
    if(preg_match('#/api/jev/run$#',$url))return $url;
    $parts=parse_url($url);$path=(string)($parts['path']??'');
    if($path===''||$path==='/')return $url.'/api/jev/run';
    return $url;
}
function sf_agent_jev_route(string $message,array $client=[]): ?array {
    $d=sf_ai_resolve_decision();if(!$d)return null;$url=sf_jev_endpoint((string)($d['endpoint_url']??''));if($url==='')return null;
    $model=trim((string)($d['model']??''))?:'typesafe/jev-1.13';
    $state=['message'=>$message,'current_view'=>(string)($client['view']??'home'),'active_track_id'=>(string)($client['active_track_id']??''),'builder_format'=>(string)($client['builder_format']??''),'builder_side_a_count'=>(int)($client['builder_side_a_count']??0),'builder_side_b_count'=>(int)($client['builder_side_b_count']??0)];
    $criteria=[
        'player_play_named'=>'Explicitly asks to play a named Stonefellow song or the current song.',
        'player_recommend'=>'Asks for a recommendation, a mood, something similar, or something to hear.',
        'player_pause'=>'Asks to pause or stop current music playback.',
        'player_next'=>'Asks for the next song or to skip.',
        'player_previous'=>'Asks to go back to the previous song.',
        'catalog_browse'=>'Asks to browse or show the Stonefellow song catalog.',
        'release_browse'=>'Asks to browse albums, EPs, singles, releases, or release pages.',
        'playlist_open'=>'Asks to see or open their playlists.',
        'playlist_create'=>'Asks the agent to create, curate, or build a playlist.',
        'playlist_save_session'=>'Asks to save the recent agent-curated listening session as a playlist.',
        'track_info'=>'Asks factual questions about a track, lyrics, credits, writers, producers, ISRC, or song story.',
        'knowledge_question'=>'Asks a broader factual/history/meaning question that may require approved knowledge documents.',
        'builder_open'=>'Asks to create, build, or continue a custom record, vinyl, cassette, or mixtape.',
        'builder_add'=>'Asks to put a song onto the current custom-media build.',
        'cart_open'=>'Asks to view cart or checkout.',
        'purchase_request'=>'Asks to buy, purchase, or order something.',
        'account_open'=>'Asks for their account, library, purchases, or profile.',
        'plans_open'=>'Asks about plans, packages, subscriptions, free trial, or Active Tokens.',
        'general_conversation'=>'General conversation that should be answered by the language model.'
    ];
    $j=sf_http_json($url,['Authorization: Bearer '.$d['api_key']],['requestId'=>sf_agent_uuid(),'model'=>$model,'state'=>$state,'questions'=>['route'=>['type'=>'choice','instructions'=>'Choose exactly one Stonefellow route. Prefer a deterministic site/tool route whenever the request clearly maps to one; use general_conversation only when no tool or factual route fits.','criteria'=>$criteria]]],40);
    $data=(array)($j['data']??[]);$usage=(array)($data['result']['usage']??$j['usage']??[]);$route=(string)($data['result']['answers']['route']['choice']??$j['answers']['route']['choice']??'');
    if(!isset($criteria[$route]))$route='general_conversation';
    return ['route'=>$route,'provider'=>'jev','model'=>$model,'run_id'=>(string)($data['id']??''),'status'=>(string)($data['status']??''),'input_tokens'=>(int)($data['inputTokens']??$usage['input_tokens']??0),'output_tokens'=>(int)($data['outputTokens']??$usage['output_tokens']??0)];
}
function sf_agent_jev_evaluate(string $message,string $context,string $response,string $route): ?array {
    if(sf_ai_meta_get('ai.jev_response_eval','0')!=='1')return null;$d=sf_ai_resolve_decision();if(!$d)return null;$url=sf_jev_endpoint((string)($d['endpoint_url']??''));if($url==='')return null;
    $model=trim((string)($d['model']??''))?:'typesafe/jev-1.13';
    $state=['user_message'=>$message,'route'=>$route,'grounded_context'=>substr($context,0,11000),'proposed_response'=>substr($response,0,3500)];
    $j=sf_http_json($url,['Authorization: Bearer '.$d['api_key']],['requestId'=>sf_agent_uuid(),'model'=>$model,'state'=>$state,'questions'=>['verdict'=>['type'=>'choice','instructions'=>'Evaluate whether the proposed response is relevant and supported by the supplied Stonefellow context. Choose withhold if it makes unsupported factual claims or claims an action/purchase happened when it did not.','criteria'=>['approve'=>'Relevant and grounded enough to show to the user.','withhold'=>'Contains unsupported Stonefellow facts, unsafe action claims, or should not be shown as written.']]]],40);
    $data=(array)($j['data']??[]);$usage=(array)($data['result']['usage']??$j['usage']??[]);return ['verdict'=>(string)($data['result']['answers']['verdict']['choice']??'approve'),'provider'=>'jev','model'=>$model,'run_id'=>(string)($data['id']??''),'status'=>(string)($data['status']??''),'input_tokens'=>(int)($data['inputTokens']??$usage['input_tokens']??0),'output_tokens'=>(int)($data['outputTokens']??$usage['output_tokens']??0)];
}
function sf_openai_call(array $provider,string $system,array $history,string $message,int $maxOutput): array {
    $model=trim((string)$provider['model'])?:'gpt-6-luna';$input=[];
    foreach($history as $h){$assistant=($h['role']??'')==='assistant';$input[]=['role'=>$assistant?'assistant':'user','content'=>[['type'=>$assistant?'output_text':'input_text','text'=>(string)$h['content']]]];}
    $input[]=['role'=>'user','content'=>[['type'=>'input_text','text'=>$message]]];
    $j=sf_http_json('https://api.openai.com/v1/responses',['Authorization: Bearer '.$provider['api_key']],['model'=>$model,'instructions'=>$system,'input'=>$input,'max_output_tokens'=>$maxOutput],50);
    $text='';foreach((array)($j['output']??[]) as $item)foreach((array)($item['content']??[]) as $c)if(($c['type']??'')==='output_text')$text.=(string)($c['text']??'');if($text==='')$text=(string)($j['output_text']??'');
    $u=(array)($j['usage']??[]);return ['text'=>trim($text),'provider'=>'openai','model'=>$model,'input_tokens'=>(int)($u['input_tokens']??0),'output_tokens'=>(int)($u['output_tokens']??0),'raw_id'=>$j['id']??null];
}
function sf_anthropic_call(array $provider,string $system,array $history,string $message,int $maxOutput): array {
    $model=trim((string)$provider['model'])?:'claude-sonnet-4-6';$msgs=[];foreach($history as $h)$msgs[]=['role'=>$h['role']==='assistant'?'assistant':'user','content'=>(string)$h['content']];$msgs[]=['role'=>'user','content'=>$message];
    $j=sf_http_json('https://api.anthropic.com/v1/messages',['x-api-key: '.$provider['api_key'],'anthropic-version: 2023-06-01'],['model'=>$model,'max_tokens'=>$maxOutput,'system'=>$system,'messages'=>$msgs],50);
    $text='';foreach((array)($j['content']??[]) as $c)if(($c['type']??'')==='text')$text.=(string)($c['text']??'');$u=(array)($j['usage']??[]);
    return ['text'=>trim($text),'provider'=>'anthropic','model'=>$model,'input_tokens'=>(int)($u['input_tokens']??0),'output_tokens'=>(int)($u['output_tokens']??0),'raw_id'=>$j['id']??null];
}
function sf_agent_reply(array $user,string $conversationId,string $message,array $client=[]): array {
    sf_entitlements_ensure_schema();$userId=(int)$user['id'];$isAdmin=(($user['role']??'')==='admin');$history=sf_agent_history($userId,$conversationId,10);sf_agent_save_message($userId,$conversationId,'user',$message);
    $decisionUsage=0;$routeSource='local';$jev=null;
    if($isAdmin||sf_can_use_ai($user,128)['ok']){
        try{$jev=sf_agent_jev_route($message,$client);if($jev){$routeSource='jev';$decisionUsage=max(0,(int)$jev['input_tokens']+(int)$jev['output_tokens']);if($decisionUsage>0)sf_consume_ai($user,$decisionUsage,$jev,'agent-jev-route');}}catch(Throwable $e){$jev=['error'=>$e->getMessage()];}
    }
    $route=(string)($jev['route']??sf_agent_local_route($message,$client));$plan=sf_agent_policy($route,$message,$client);
    sf_agent_brain_log($userId,$conversationId,'route',['request'=>$message,'route'=>$plan['route'],'action'=>$plan['action_name'],'context_profile'=>$plan['context_profile'],'needs_llm'=>$plan['needs_llm'],'requires_confirmation'=>$plan['requires_confirmation'],'run_id'=>$jev['run_id']??'','status'=>$jev['status']??($routeSource==='local'?'local_fallback':'error'),'input_tokens'=>$jev['input_tokens']??0,'output_tokens'=>$jev['output_tokens']??0,'source'=>$routeSource,'provider_error'=>$jev['error']??'','action_payload'=>$plan['action']]);
    if(!$plan['needs_llm']){
        $text=$plan['text']!==''?$plan['text']:'Ready.';
        sf_agent_save_message($userId,$conversationId,'assistant',$text,['provider'=>$routeSource==='jev'?'jev+policy':'stonefellow-policy','model'=>$jev['model']??'policy']);
        sf_log_user_activity($userId,'agent_interaction','Agent: '.$plan['route'],'agent',$conversationId,['action'=>$plan['action_name'],'needs_llm'=>false]);
        return ['text'=>$text,'provider'=>$routeSource==='jev'?'jev+policy':'stonefellow-policy','model'=>$jev['model']??'policy','input_tokens'=>(int)($jev['input_tokens']??0),'output_tokens'=>(int)($jev['output_tokens']??0),'active_tokens_used'=>$isAdmin?0:$decisionUsage,'active_tokens_remaining'=>$isAdmin?null:sf_token_balance($userId),'conversation_id'=>$conversationId,'decision'=>$plan['route'],'action'=>$plan['action'],'requires_confirmation'=>$plan['requires_confirmation'],'brain'=>['route'=>$plan['route'],'source'=>$routeSource,'needs_llm'=>false]];
    }
    $context=sf_agent_context($message,$plan['context_profile']);$estimate=(int)ceil((strlen($context)+strlen($message)+array_sum(array_map(fn($x)=>strlen((string)$x['content']),$history)))/4)+256;$allow=sf_can_use_ai($user,$estimate);if(!$allow['ok'])throw new RuntimeException('ACTIVE_TOKENS_REQUIRED');
    $system="You are the Stonefellow music-site agent. Be concise, warm, knowledgeable, and grounded. Use only the supplied Stonefellow context for factual claims about songs, releases, credits, purchases, or artist history. If the context lacks the answer, say so. Never claim a purchase, playback change, account change, or builder edit happened unless the Stonefellow action layer reports it. The structured route is advisory context, not permission to invent actions.\n\nROUTE: ".$plan['route']."\nCONTEXT PROFILE: ".$plan['context_profile']."\n\n".$context;
    $maxOutput=$isAdmin?700:max(96,min(700,$allow['balance']-$estimate+128));$errors=[];
    foreach(sf_ai_llm_candidates() as $provider){
        try{
            $r=$provider['provider']==='openai'?sf_openai_call($provider,$system,$history,$message,$maxOutput):sf_anthropic_call($provider,$system,$history,$message,$maxOutput);
            if($r['text']==='')throw new RuntimeException('Provider returned no text.');
            $used=max(1,(int)$r['input_tokens']+(int)$r['output_tokens']);$balance=sf_consume_ai($user,$used,$r,'agent-chat');$evalUsage=0;$evaluation=null;
            try{$evaluation=sf_agent_jev_evaluate($message,$context,$r['text'],$plan['route']);if($evaluation){$evalUsage=max(0,(int)$evaluation['input_tokens']+(int)$evaluation['output_tokens']);if($evalUsage>0)$balance=sf_consume_ai($user,$evalUsage,$evaluation,'agent-jev-eval');sf_agent_brain_log($userId,$conversationId,'response_eval',['request'=>$message,'response'=>$r['text'],'route'=>$plan['route'],'action'=>$plan['action_name'],'context_profile'=>$plan['context_profile'],'needs_llm'=>true,'requires_confirmation'=>$plan['requires_confirmation'],'run_id'=>$evaluation['run_id']??'','status'=>$evaluation['status']??'','input_tokens'=>$evaluation['input_tokens']??0,'output_tokens'=>$evaluation['output_tokens']??0,'verdict'=>$evaluation['verdict']??'approve']);if(($evaluation['verdict']??'approve')==='withhold')$r['text']="I don't have enough verified Stonefellow context to answer that confidently yet.";}}catch(Throwable $e){sf_agent_brain_log($userId,$conversationId,'response_eval',['request'=>$message,'response'=>$r['text'],'route'=>$plan['route'],'action'=>$plan['action_name'],'context_profile'=>$plan['context_profile'],'needs_llm'=>true,'requires_confirmation'=>$plan['requires_confirmation'],'status'=>'evaluation_error','error'=>$e->getMessage()]);}
            sf_agent_brain_log($userId,$conversationId,'response',['request'=>$message,'response'=>$r['text'],'route'=>$plan['route'],'action'=>$plan['action_name'],'context_profile'=>$plan['context_profile'],'needs_llm'=>true,'requires_confirmation'=>$plan['requires_confirmation'],'status'=>'completed','input_tokens'=>$r['input_tokens'],'output_tokens'=>$r['output_tokens'],'llm_provider'=>$r['provider'],'llm_model'=>$r['model']]);
            sf_agent_save_message($userId,$conversationId,'assistant',$r['text'],$r);sf_log_user_activity($userId,'agent_interaction','Agent: '.$plan['route'],'agent',$conversationId,['action'=>$plan['action_name'],'needs_llm'=>true,'provider'=>$r['provider'],'model'=>$r['model']]);$r['active_tokens_used']=$isAdmin?0:$used+$decisionUsage+$evalUsage;$r['active_tokens_remaining']=$isAdmin?null:$balance;$r['conversation_id']=$conversationId;$r['decision']=$plan['route'];$r['action']=$plan['action'];$r['requires_confirmation']=$plan['requires_confirmation'];$r['brain']=['route'=>$plan['route'],'source'=>$routeSource,'needs_llm'=>true,'evaluation'=>$evaluation['verdict']??null];return $r;
        }catch(Throwable $e){$errors[]=$provider['provider'].': '.$e->getMessage();}
    }
    throw new RuntimeException($errors?'No configured language provider completed the request. '.implode(' | ',$errors):'No language provider is enabled.');
}
