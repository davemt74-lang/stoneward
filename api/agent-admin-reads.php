<?php
declare(strict_types=1);
/** Section 91B2A: bounded, deterministic Admin catalog/release reads. */
function sf_agent_admin_read_label(mixed $value,int $limit=140):string {
    if(!is_scalar($value) && $value!==null)return '';
    $value=strip_tags((string)$value);
    $value=preg_replace('/[\x00-\x1f\x7f]+/u',' ',$value)??'';
    return substr(trim($value),0,$limit);
}
function sf_agent_admin_catalog_filter(string $message):string {
    if(preg_match('/\b(?:missing|incomplete|without|need)\b.{0,45}\b(?:isrc|credits?|metadata|writers?|composers?)\b/i',$message))return 'missing_metadata';
    if(preg_match('/\b(?:draft|unpublished|private|not published|haven.t been published)\b/i',$message))return 'unpublished';
    return 'all';
}
/** @param array<int,mixed> $catalog */
function sf_agent_admin_catalog_read(array $catalog,string $filter,int $limit=10):array {
    if(!in_array($filter,['all','unpublished','missing_metadata'],true))throw new InvalidArgumentException('Unsupported catalog filter.');
    $limit=max(1,min(20,$limit));$matched=0;$rows=[];
    foreach($catalog as $track){
        if(!is_array($track))continue;
        $id=sf_agent_admin_read_label($track['id']??'',100);
        if($id==='')continue;
        $state=strtolower(sf_agent_admin_read_label($track['state']??'published',25));
        $isPublic=($track['public_visible']??true)!==false
            && !in_array($state,['draft','private','archived'],true);
        $meta=is_array($track['metadata']??null)?$track['metadata']:[];
        $missing=[];
        if(trim((string)($meta['isrc']??''))==='')$missing[]='ISRC';
        if(trim((string)($meta['words_by']??''))==='' && trim((string)($meta['music_by']??''))==='')$missing[]='writer/composer';
        if($filter==='unpublished' && $isPublic)continue;
        if($filter==='missing_metadata' && !$missing)continue;
        $matched++;
        if(count($rows)>=$limit)continue;
        $rows[]=['id'=>$id,'title'=>sf_agent_admin_read_label($track['title']??$id),
            'state'=>$state,'public_visible'=>$isPublic,
            'missing_metadata'=>$missing];
    }
    return ['domain'=>'catalog','filter'=>$filter,'matched'=>$matched,
        'shown'=>count($rows),'truncated'=>$matched>count($rows),'rows'=>$rows,
        'read_only'=>true,'source'=>'stonefellow.admin.catalog'];
}
function sf_agent_admin_release_filter(string $message):string {
    if(preg_match('/\b(?:scheduled|upcoming release)\b/i',$message))return 'scheduled';
    if(preg_match('/\b(?:draft|pending|in review|awaiting review|unpublished|not published)\b/i',$message))return 'pending';
    return 'all';
}
/** @param array<int,mixed> $releases */
function sf_agent_admin_release_read(array $releases,string $filter,int $limit=10):array {
    if(!in_array($filter,['all','pending','scheduled'],true))throw new InvalidArgumentException('Unsupported release filter.');
    $limit=max(1,min(20,$limit));$matched=0;$rows=[];
    foreach($releases as $release){
        if(!is_array($release))continue;
        $id=sf_agent_admin_read_label($release['id']??'',100);
        if($id==='')continue;
        $state=strtolower(sf_agent_admin_read_label($release['state']??'draft',25));
        $public=($release['public_visible']??($state==='published'))===true && $state==='published';
        if($filter==='pending' && ($public || $state==='archived'))continue;
        if($filter==='scheduled' && $state!=='scheduled')continue;
        $matched++;
        if(count($rows)>=$limit)continue;
        $rows[]=['id'=>$id,'title'=>sf_agent_admin_read_label($release['title']??$id),
            'state'=>$state,'public_visible'=>$public,
            'release_date'=>sf_agent_admin_read_label($release['release_date']??'',24),
            'track_count'=>count(is_array($release['track_ids']??null)?$release['track_ids']:[])];
    }
    return ['domain'=>'releases','filter'=>$filter,'matched'=>$matched,
        'shown'=>count($rows),'truncated'=>$matched>count($rows),'rows'=>$rows,
        'read_only'=>true,'source'=>'stonefellow.admin.releases'];
}
function sf_agent_admin_show_filter(string $message):string {
    if(preg_match('/\b(?:cancelled|canceled)\b/i',$message))return 'cancelled';
    if(preg_match('/\b(?:postponed|rescheduled)\b/i',$message))return 'postponed';
    if(preg_match('/\b(?:upcoming|future|next|scheduled)\b/i',$message))return 'upcoming';
    return 'all';
}
/** @param array<int,mixed> $shows */
function sf_agent_admin_shows_read(array $shows,string $filter,string $today,int $limit=10):array {
    if(!in_array($filter,['all','upcoming','postponed','cancelled'],true))throw new InvalidArgumentException('Unsupported show filter.');
    if(!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$today))throw new InvalidArgumentException('Expected an ISO date.');
    $limit=max(1,min(20,$limit));$matches=[];
    foreach($shows as $show){
        if(!is_array($show))continue;
        $id=sf_agent_admin_read_label($show['id']??'',100);
        $date=sf_agent_admin_read_label($show['date']??'',25);
        if($id==='' || !preg_match('/\A\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2})?\z/D',$date))continue;
        $status=strtolower(sf_agent_admin_read_label($show['status']??'scheduled',25));
        if($filter==='upcoming' && ($status!=='scheduled' || substr($date,0,10)<$today))continue;
        if($filter==='postponed' && $status!=='postponed')continue;
        if($filter==='cancelled' && $status!=='cancelled')continue;
        $matches[]=['id'=>$id,'title'=>sf_agent_admin_read_label($show['title']??''),
            'date'=>$date,'venue'=>sf_agent_admin_read_label($show['venue']??''),
            'city'=>sf_agent_admin_read_label($show['city']??''),
            'status'=>$status,'public_visible'=>($show['public_visible']??true)!==false];
    }
    usort($matches,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['id'],$b['id']));
    $rows=array_slice($matches,0,$limit);
    return ['domain'=>'shows','filter'=>$filter,'matched'=>count($matches),
        'shown'=>count($rows),'truncated'=>count($matches)>count($rows),'rows'=>$rows,
        'read_only'=>true,'source'=>'stonefellow.admin.shows','as_of_utc_date'=>$today];
}
function sf_agent_admin_read_brief(array $data):string {
    $domain=match($data['domain']){'catalog'=>'catalog songs','releases'=>'releases','shows'=>'shows',default=>'records'};
    $intro=(int)$data['matched'].' '.$domain.' match the '.$data['filter'].' filter';
    if(!$data['rows'])return $intro.'. No matching records were found. No changes made.';
    $items=[];
    foreach($data['rows'] as $row){
        $detail=($row['state']??$row['status']??'unknown').(!empty($row['public_visible'])?' · public':' · not public');
        if($data['domain']==='catalog' && !empty($row['missing_metadata']))$detail.=' · missing '.implode(', ',$row['missing_metadata']);
        if($data['domain']==='releases')$detail.=' · '.(int)$row['track_count'].' tracks'.($row['release_date']!==''?' · '.$row['release_date']:'');
        if($data['domain']==='shows')$detail.=' · '.$row['date'].' · '.$row['venue'].($row['city']!==''?' · '.$row['city']:'');
        $items[]=$row['title'].' ('.$row['id'].'): '.$detail;
    }
    return $intro.'. '.implode('; ',$items).'.'.($data['truncated']?' Showing the first '.(int)$data['shown'].'.':'').' Read only; no changes made.';
}
