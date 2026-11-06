<?php
declare(strict_types=1);

require_once __DIR__.'/agent-tool-registry.php';
require_once __DIR__.'/canvas-capabilities.php';
require_once __DIR__.'/canvas-access-core.php';
require_once __DIR__.'/agent-public-artist-context.php';
require_once __DIR__.'/agent-canvas-catalog.php';
require_once __DIR__.'/agent-canvas-public-commerce.php';
require_once __DIR__.'/agent-canvas-private-commerce.php';
require_once __DIR__.'/canvas-resource-core.php';

/** Provider-safe tool descriptions without dataset values. */
function sf_agent_canvas_data_tools(): array {
    $tools=[];
    $policy=sf_canvas_permission_policy();
    foreach(sf_agent_tool_registry() as $id=>$tool){
        if(!isset($tool['canvas_data'])||!sf_canvas_permission_enabled($id,$policy))continue;
        $tools[]=[
            'id'=>$id,
            'permission_id'=>'canvas.data.'.$id,
            'policy_revision'=>$policy['revision'],
            'label'=>$tool['label'],
            'scope'=>$tool['scope'],
            'endpoint'=>'/'.$tool['owner'],
            'description'=>$tool['canvas_data']['description'],
            'fields'=>$tool['canvas_data']['fields'],
            'filters'=>$tool['canvas_data']['filters']??[],
            'max_rows'=>$tool['canvas_data']['max_rows'],
            'actions'=>$tool['canvas_data']['actions']??[],
        ];
    }
    return $tools;
}

/** Resolve declared datasets for delivery to the current account's canvas. */
function sf_agent_canvas_data_for_scene(array $user,string $html,string $currentTrackId='',?array $musicInputs=null,?array $artistInputs=null,?array $accessContext=null): array {
    if(!str_contains($html,'sf-dataset-'))return [];
    preg_match_all('/<[a-z][a-z0-9]*\s+class="([A-Za-z0-9_ -]{1,160})"(?:\s*\/)?\s*>/',$html,$classAttributes);
    $classes=[];$currentClasses=[];$bindings=[];
    foreach($classAttributes[1] as $attribute){
        $tokens=array_fill_keys(explode(' ',$attribute),true);
        $classes+=$tokens;
        foreach(array_keys($tokens) as $token){
            if(preg_match('/\Asf-dataset-([a-z][a-z0-9_-]{0,63})\z/',$token,$match)===1)$bindings[$match[1]][]=$tokens;
        }
        if(isset($tokens['sf-current-track']))$currentClasses+=$tokens;
    }
    $userId=(int)($user['id']??0);
    $role=$userId>0?'fan':'guest';
    $access=null;
    if($accessContext!==null){
        $resolved=sf_canvas_access_resolve($user,$accessContext);
        if(!hash_equals($resolved['scene']['html'],$html))throw new RuntimeException('Canvas source does not match its access context.');
        $access=sf_canvas_access_summary($user,$accessContext);
    }
    $permissions=$access===null?[]:array_column($access['permissions'],null,'id');
    $datasets=[];
    $catalog=null;
    $musicCatalog=null;
    foreach(sf_agent_canvas_data_tools() as $tool){
        $id=$tool['id'];
        $marker='sf-dataset-'.$id;
        if(!isset($classes[$marker])||!sf_agent_tool_allowed($id,$role))continue;
        if($tool['scope']==='signed_in_fan'&&(sf_db()->inTransaction()||empty($permissions['canvas.data.'.$id]['granted']))){
            $datasets[$id]=['fields'=>$tool['fields'],'filters'=>$tool['filters'],'actions'=>$tool['actions'],'rows'=>[],'state'=>sf_db()->inTransaction()?'unavailable':'consent_required'];
            continue;
        }
        $usesCurrentTrack=isset($currentClasses[$marker])&&in_array('track_id',$tool['filters'],true);
        $dataset=['fields'=>$tool['fields'],'filters'=>$tool['filters'],'actions'=>$tool['actions'],'rows'=>[]];
        if($usesCurrentTrack)$dataset['current_rows']=[];
        try{
            $source=match($id){
                'published_catalog'=>$catalog??=array_column(sf_public_catalog(),null,'id'),
                'published_release_tracks'=>sf_agent_canvas_release_track_rows(),
                'personal_listening_stats'=>sf_agent_canvas_listening_rows($userId,$catalog??=array_column(sf_public_catalog(),null,'id')),
                'published_merch'=>sf_canvas_resource_artist_rows($id,$artistInputs),
                'published_ticket_offers'=>sf_canvas_resource_artist_rows($id,$artistInputs),
                'my_purchases'=>sf_agent_canvas_purchase_rows($user),
                'my_tickets'=>sf_agent_canvas_owned_ticket_rows($userId),
            };
            $rows=sf_agent_canvas_project_rows($source,$id,$tool['fields']);
            $scopedMusic=$musicInputs!==null&&in_array($id,['published_catalog','published_release_tracks'],true);
            $scopedArtist=$artistInputs!==null&&(
                ($id==='published_merch'&&array_key_exists('merch_ids',$artistInputs))||
                ($id==='published_ticket_offers'&&array_key_exists('ticket_ids',$artistInputs)));
            if($scopedMusic){
                require_once __DIR__.'/agent-canvas-music-core.php';
                $musicCatalog??=sf_agent_canvas_music_catalog($musicInputs);
                $rows=sf_agent_canvas_music_rows($rows,$musicCatalog);
            }
            $filteredRows=sf_agent_canvas_filter_rows($rows,$bindings[$id]??[],$tool['filters']);
            $dataset['rows']=$scopedMusic||$scopedArtist?$filteredRows:array_slice($filteredRows,0,$tool['max_rows']);
            if($usesCurrentTrack&&$currentTrackId!==''){
                $catalog??=array_column(sf_public_catalog(),null,'id');
                $track=$catalog[$currentTrackId]??null;
                if($track!==null){
                    $currentSource=match($id){
                        'published_catalog'=>[$track],
                        'personal_listening_stats'=>sf_agent_canvas_listening_rows($userId,$catalog,$currentTrackId),
                        default=>array_filter($source,static fn(array $row):bool=>($row['track_id']??null)===$currentTrackId),
                    };
                    $currentRows=sf_agent_canvas_project_rows($currentSource,$id,$tool['fields']);
                    if($scopedMusic)$currentRows=sf_agent_canvas_music_rows($currentRows,$musicCatalog);
                    $filteredCurrent=sf_agent_canvas_filter_rows($currentRows,$bindings[$id]??[],$tool['filters']);
                    $dataset['current_rows']=$scopedMusic?$filteredCurrent:array_slice($filteredCurrent,0,$tool['max_rows']);
                }
            }
        }catch(Throwable $error){
            error_log('[Stonefellow canvas data] '.$id.': '.get_class($error));
            $datasets[$id]=$dataset+['state'=>'unavailable'];
            continue;
        }
        $datasets[$id]=$dataset;
    }
    // Recheck after potentially slow domain reads before delivering private rows.
    $policy=sf_canvas_permission_policy();
    $fresh=$accessContext===null?[]:array_column(sf_canvas_access_summary($user,$accessContext)['permissions'],null,'id');
    foreach(array_keys($datasets) as $id){
        if(!sf_canvas_permission_enabled($id,$policy))unset($datasets[$id]);
        elseif((sf_canvas_capability_cards()[$id]['requires_sign_in']??false)&&empty($fresh['canvas.data.'.$id]['granted'])){
            $datasets[$id]['rows']=[];unset($datasets[$id]['current_rows']);$datasets[$id]['state']='consent_required';
        }
    }
    return $datasets;
}

/** Apply declared exact scopes before the delivery limit, including late catalog rows. */
function sf_agent_canvas_filter_rows(array $rows,array $bindings,array $allowedFields): array {
    $selected=[];$unfiltered=false;
    foreach($bindings as $classes){
        $filters=[];$valid=true;
        foreach(array_keys($classes) as $class){
            if(!str_starts_with($class,'sf-filter-'))continue;
            if(preg_match('/\Asf-filter-([a-z][a-z0-9_-]{0,63}?)--([A-Za-z0-9_-]{1,80})\z/',$class,$match)!==1||
                !in_array($match[1],$allowedFields,true)){$valid=false;break;}
            $filters[]=['field'=>$match[1],'value'=>$match[2]];
        }
        if(!$valid)continue;
        if(!$filters){$unfiltered=true;continue;}
        foreach($rows as $index=>$row){
            foreach($filters as $filter){
                if(!isset($row[$filter['field']])||(string)$row[$filter['field']]!==$filter['value'])continue 2;
            }
            $selected[$index]=$row;
        }
    }
    // Explicitly requested rows get room before an additional unfiltered view.
    if($unfiltered)foreach($rows as $index=>$row)$selected[$index]??=$row;
    return array_values($selected);
}

/** Published release membership is authoritative, with canonical legacy relationships as a fallback. */
function sf_agent_canvas_release_track_rows(): array {
    $rows=[];$resolved=[];$catalog=sf_public_catalog();
    foreach(sf_agent_canvas_catalog_release_rows() as $release){
        $releaseId=(string)$release['id'];
        $releaseTitle=sf_agent_public_artist_text($release['title']??'',180);
        if($releaseTitle==='')continue;
        $ids=(array)($release['track_ids']??[]);
        if(!$ids){
            foreach($catalog as $track){
                $trackRelease=(string)($track['release_id']??'');
                if($trackRelease!==''?$trackRelease===$releaseId:(string)($track['release']??'')===(string)($release['title']??''))
                    $ids[]=$track['id']??null;
            }
        }
        $seen=[];$position=0;
        foreach($ids as $rawId){
            $trackId=sf_agent_canvas_catalog_id($rawId);
            if($trackId===null||isset($seen[$trackId]))continue;
            $seen[$trackId]=true;
            if(!array_key_exists($trackId,$resolved))$resolved[$trackId]=sf_seo_public_track($trackId);
            $track=$resolved[$trackId];
            if($track===null)continue;
            $title=sf_agent_public_artist_text($track['title']??'',180);
            if($title==='')continue;
            $rows[]=['release_id'=>$releaseId,'release_title'=>$releaseTitle,'track_id'=>$trackId,'title'=>$title,
                'duration_seconds'=>is_numeric($track['duration']??null)?max(0,(int)$track['duration']):0,'position'=>++$position];
        }
    }
    return $rows;
}

/** Small public release inventory for choosing a real dataset filter, without sample song rows. */
function sf_agent_canvas_release_sources(string $brief,string $playingTrack=''): array {
    $counts=[];$members=[];
    foreach(sf_agent_canvas_release_track_rows() as $row){
        $counts[$row['release_id']]=($counts[$row['release_id']]??0)+1;
        if($row['track_id']===$playingTrack)$members[$row['release_id']]=true;
    }
    $ranked=[];$query=strtolower(sf_agent_public_artist_text($brief,1800));
    foreach(sf_agent_canvas_catalog_release_rows($brief,$playingTrack) as $index=>$release){
        $id=(string)$release['id'];$title=sf_agent_public_artist_text($release['title']??'',180);
        if($title==='')continue;
        $mentioned=preg_match('/(?<![A-Za-z0-9_-])'.preg_quote(strtolower($id),'/').'(?![A-Za-z0-9_-])/',$query)===1||
            (strlen($title)>2&&str_contains($query,strtolower($title)));
        $ranked[]=['release_id'=>$id,'title'=>$title,'track_count'=>$counts[$id]??0,
            'rank'=>($mentioned?8:0)+(isset($members[$id])?4:0),'order'=>$index];
    }
    usort($ranked,static fn(array $a,array $b):int=>$b['rank']<=>$a['rank']?:$a['order']<=>$b['order']);
    return array_map(static fn(array $row):array=>['release_id'=>$row['release_id'],'title'=>$row['title'],'track_count'=>$row['track_count']],
        array_slice($ranked,0,6));
}

function sf_agent_canvas_project_rows(array $source,string $id,array $fields): array {
    $rows=[];
    foreach($source as $sourceRow){
        if($id==='published_catalog'){
            $sourceRow['track_id']=$sourceRow['id']??'';
            $sourceRow['duration_seconds']=$sourceRow['duration']??0;
        }
        $row=[];
        foreach($fields as $field=>$type){
            $value=$sourceRow[$field]??null;
            $row[$field]=$type==='number'
                ?(is_numeric($value)?max(0,(int)$value):null)
                :sf_agent_public_artist_text($value);
        }
        $rows[]=$row;
    }
    return $rows;
}

/** Use the same account aggregate as listening.php, with published track metadata only. */
function sf_agent_canvas_listening_rows(int $userId,array $publishedTracks,?string $trackId=null): array {
    if($userId<=0)return [];
    $source=sf_listening_track_rows(gmdate('c',time()-30*86400),$userId,$trackId);
    if($trackId!==null&&!$source)$source=[['track_id'=>$trackId,'listens'=>0,'completes'=>0]];
    $rows=[];
    foreach($source as $row){
        $track=$publishedTracks[$row['track_id']]??null;
        if($track===null)continue;
        $row['title']=$track['title']??'';
        $row['release']=$track['release']??'';
        $rows[]=$row;
    }
    return $rows;
}
