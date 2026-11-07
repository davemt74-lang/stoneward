<?php
declare(strict_types=1);

require_once __DIR__.'/agent-tool-registry.php';

/** Only registered, read-only personal canvas datasets are selectable. */
function sf_canvas_account_data_tools(): array {
    return array_filter(sf_agent_tool_registry(),static fn(array $tool):bool=>
        $tool['scope']==='signed_in_fan'&&$tool['kind']==='read'&&isset($tool['canvas_data']));
}

/** Validate attachment identities independently of the runtime permission policy. */
function sf_canvas_account_data_ids(mixed $raw): array {
    if(!is_array($raw)||!array_is_list($raw))throw new InvalidArgumentException('Choose account data sources from the picker.');
    $tools=sf_canvas_account_data_tools();
    foreach($raw as $id){
        if(!is_string($id)||!isset($tools[$id]))throw new InvalidArgumentException('Choose registered account data sources from the picker.');
    }
    return array_values(array_unique($raw,SORT_STRING));
}

/** Metadata only. Neither discovery nor attachment reads the viewer’s records. */
function sf_canvas_account_data_inventory(): array {
    require_once __DIR__.'/canvas-capabilities.php';
    $policy=sf_canvas_permission_policy();
    $items=[];
    foreach(sf_canvas_account_data_tools() as $id=>$tool){
        if(!sf_canvas_permission_enabled($id,$policy))continue;
        $data=$tool['canvas_data'];
        $items[]=['id'=>$id,'title'=>$tool['label'],'permission_id'=>'canvas.data.'.$id,
            'description'=>$data['description'],'fields'=>$data['fields'],'field_labels'=>$data['field_labels']??[],
            'time_window'=>$data['time_window']??'','max_rows'=>$data['max_rows']];
    }
    return $items;
}

/** The same track and release visibility boundary applies to listening rows. */
function sf_canvas_account_public_tracks(): array {
    require_once __DIR__.'/canvas-audio-core.php';
    sf_catalog(true);
    $public=[];
    foreach(sf_public_catalog() as $track){
        $id=(string)($track['id']??'');
        if($id==='')continue;
        $resolved=sf_canvas_audio_public_track($id);
        if($resolved!==null)$public[$id]=$resolved;
    }
    return $public;
}

/** Aggregate the canonical event ledger without exposing any session identifier. */
function sf_canvas_account_listening_summary(int $uid,array $publicTracks): array {
    if($uid<1||!sf_privacy_listening_history_enabled($uid))return [];
    $summary=['window_days'=>30,'starts'=>0,'completes'=>0,'sessions'=>0,'tracks'=>0,
        'listened_seconds'=>0,'measured_events'=>0,'legacy_events'=>0];
    if(!$publicTracks)return [$summary];
    sf_ops_ensure_schema();
    $measurement=sf_listening_measurement_sql();
    $db=sf_db();
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $track=$mysql?'BINARY track_id':'track_id';
    $session=$mysql?'BINARY session_key':'session_key';
    $ids=array_keys($publicTracks);
    $slots=implode(',',array_fill(0,count($ids),'?'));
    $now=time();
    $q=$db->prepare("SELECT SUM(CASE WHEN event_type='start' THEN 1 ELSE 0 END) AS starts,
        SUM(CASE WHEN event_type='complete' THEN 1 ELSE 0 END) AS completes,
        COUNT(DISTINCT $session) AS sessions,COUNT(DISTINCT $track) AS tracks,
        {$measurement['seconds']} AS listened_seconds,{$measurement['measured']} AS measured_events,{$measurement['legacy']} AS legacy_events
        FROM listening_events WHERE user_id=? AND created_at>=? AND created_at<=? AND $track IN ($slots)");
    $q->execute([$uid,gmdate('c',$now-30*86400),gmdate('c',$now),...$ids]);
    $values=$q->fetch(PDO::FETCH_ASSOC);
    $q->closeCursor();
    foreach($values?:[] as $key=>$value)$summary[$key]=max(0,(int)$value);
    return [$summary];
}

/** Read only the newest eligible public-song activity, without transport identifiers. */
function sf_canvas_account_listening_history(int $uid,array $publicTracks): array {
    if($uid<1||!$publicTracks||!sf_privacy_listening_history_enabled($uid))return [];
    sf_ops_ensure_schema();
    $db=sf_db();
    $track=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY track_id':'track_id';
    $ids=array_keys($publicTracks);
    $slots=implode(',',array_fill(0,count($ids),'?'));
    $limit=sf_agent_tool_registry()['my_listening_history']['canvas_data']['max_rows'];
    $q=$db->prepare("SELECT track_id,event_type,position_seconds,duration_seconds,created_at AS occurred_at
        FROM listening_events WHERE user_id=? AND event_type IN ('start','resume','pause','complete','skip')
        AND created_at<=? AND $track IN ($slots) ORDER BY created_at DESC,id DESC LIMIT $limit");
    $q->execute([$uid,gmdate('c'),...$ids]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $q->closeCursor();
    foreach($rows as &$row){
        $metadata=$publicTracks[$row['track_id']];
        $row['title']=$metadata['title']??'';
        $row['release']=$metadata['release']??'';
        $row['position_seconds']=(int)$row['position_seconds'];
        $row['duration_seconds']=(int)$row['duration_seconds'];
    }
    unset($row);
    return $rows;
}

/** Only the declared profile preferences cross the canvas boundary. */
function sf_canvas_account_preferences(int $uid): array {
    if($uid<1)return [];
    $profile=sf_privacy_profile($uid);
    return [['locale'=>$profile['locale'],'timezone'=>$profile['timezone'],'country_code'=>$profile['country_code'],
        'listening_history_enabled'=>(int)$profile['listening_history_enabled'],
        'personalization_enabled'=>(int)$profile['personalization_enabled']]];
}
