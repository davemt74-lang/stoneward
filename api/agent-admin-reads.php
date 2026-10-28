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
/** Section 91B2C: bounded private Knowledge Base inventory, never document contents. */
function sf_agent_admin_knowledge_filter(string $message):string {
    if(preg_match('/\b(?:private|not public|internal|unshared)\b/i',$message))return 'private';
    if(preg_match('/\b(?:public|shared|agent.enabled|agent access|available to (?:the )?agent)\b/i',$message))return 'agent_enabled';
    return 'all';
}
function sf_agent_admin_knowledge_read(array $index,string $filter,int $limit=10):array {
    if(!in_array($filter,['all','private','agent_enabled'],true))throw new InvalidArgumentException('Unsupported knowledge filter.');
    $limit=max(1,min(20,$limit));$rows=[];$matched=0;
    foreach((array)($index['files']??[]) as $file){
        if(!is_array($file))continue;
        $id=sf_agent_admin_read_label($file['id']??'',100);
        $name=sf_agent_admin_read_label($file['name']??'',180);
        if($id===''||$name==='')continue;
        $enabled=!empty($file['public_agent']);
        if($filter==='private'&&$enabled)continue;
        if($filter==='agent_enabled'&&!$enabled)continue;
        $matched++;
        if(count($rows)>=$limit)continue;
        $rows[]=['id'=>$id,'name'=>$name,'type'=>sf_agent_admin_read_label($file['type']??'',40),
            'agent_enabled'=>$enabled,'folder_id'=>sf_agent_admin_read_label($file['folder_id']??'',40)];
    }
    return ['domain'=>'knowledge','filter'=>$filter,'matched'=>$matched,'shown'=>count($rows),
        'truncated'=>$matched>count($rows),'rows'=>$rows,'read_only'=>true,
        'source'=>'stonefellow.admin.knowledge'];
}
function sf_agent_admin_media_filter(string $message):string {
    if(preg_match('/\b(?:unattached|unused|not attached|without attachments?)\b/i',$message))return 'unattached';
    if(preg_match('/\b(?:attached|in use|linked)\b/i',$message))return 'attached';
    if(preg_match('/\b(?:images?|photos?|artwork|pictures?)\b/i',$message))return 'image';
    if(preg_match('/\b(?:audio|recordings?|sound files?)\b/i',$message))return 'audio';
    if(preg_match('/\b(?:videos?|footage)\b/i',$message))return 'video';
    if(preg_match('/\b(?:documents?|pdfs?)\b/i',$message))return 'document';
    return 'all';
}
/** Projection intentionally excludes stored paths, hashes, variants and technical tags. */
function sf_agent_admin_media_read(array $assets,string $filter,int $limit=10,?int $total=null):array {
    if(!in_array($filter,['all','attached','unattached','image','audio','video','document'],true))throw new InvalidArgumentException('Unsupported media filter.');
    $limit=max(1,min(20,$limit));$rows=[];$matched=0;
    foreach($assets as $asset){
        if(!is_array($asset))continue;
        $id=(int)($asset['id']??0);if($id<1)continue;
        $category=sf_agent_admin_read_label($asset['category']??'',40);
        $links=max(0,(int)($asset['link_count']??0));
        if(in_array($filter,['image','audio','video','document'],true)&&$category!==$filter)continue;
        if($filter==='attached'&&$links===0)continue;
        if($filter==='unattached'&&$links!==0)continue;
        $matched++;if(count($rows)>=$limit)continue;
        $rows[]=['id'=>$id,'title'=>sf_agent_admin_read_label($asset['title']??$asset['original_name']??'',180),
            'category'=>$category,'attachment_count'=>$links];
    }
    $matched=$total===null?$matched:max($matched,$total);
    return ['domain'=>'media','filter'=>$filter,'matched'=>$matched,'shown'=>count($rows),
        'truncated'=>$matched>count($rows),'rows'=>$rows,'read_only'=>true,
        'source'=>'stonefellow.admin.media'];
}
/** Count and select from the same authoritative Media Library, never whole private blobs. */
function sf_agent_admin_media_source(string $filter,int $limit=10):array {
    if(!in_array($filter,['all','attached','unattached','image','audio','video','document'],true))throw new InvalidArgumentException('Unsupported media filter.');
    sf_media_ensure_schema();$limit=max(1,min(20,$limit));$where='';$args=[];
    if(in_array($filter,['image','audio','video','document'],true)){$where=' WHERE a.category=?';$args[]=$filter;}
    elseif($filter==='attached')$where=' WHERE EXISTS (SELECT 1 FROM media_links l WHERE l.asset_id=a.id)';
    elseif($filter==='unattached')$where=' WHERE NOT EXISTS (SELECT 1 FROM media_links l WHERE l.asset_id=a.id)';
    $count=sf_db()->prepare('SELECT COUNT(*) FROM media_assets a'.$where);$count->execute($args);
    $total=(int)$count->fetchColumn();
    $query=sf_db()->prepare('SELECT a.id,a.title,a.original_name,a.category,(SELECT COUNT(*) FROM media_links l WHERE l.asset_id=a.id) AS link_count FROM media_assets a'.$where.' ORDER BY a.id DESC LIMIT '.$limit);
    $query->execute($args);
    return sf_agent_admin_media_read($query->fetchAll(),$filter,$limit,$total);
}
function sf_agent_admin_attachment_filter(string $message):string {
    if(preg_match('/\b(?:songs?|tracks?)\b/i',$message))return 'track';
    if(preg_match('/\b(?:releases?|albums?)\b/i',$message))return 'release';
    if(preg_match('/\b(?:shows?|concerts?)\b/i',$message))return 'show';
    return 'all';
}
/** Real media_links rows joined to their assets; only safe relation metadata escapes. */
function sf_agent_admin_attachment_read(array $links,string $filter,int $limit=10,?int $total=null):array {
    if(!in_array($filter,['all','track','release','show'],true))throw new InvalidArgumentException('Unsupported attachment filter.');
    $limit=max(1,min(20,$limit));$rows=[];$matched=0;
    foreach($links as $link){
        if(!is_array($link))continue;
        $id=(int)($link['id']??0);$type=sf_agent_admin_read_label($link['entity_type']??'',40);
        if($id<1||$type===''||($filter!=='all'&&$type!==$filter))continue;
        $matched++;if(count($rows)>=$limit)continue;
        $rows[]=['id'=>$id,'asset_id'=>(int)($link['asset_id']??0),
            'asset_title'=>sf_agent_admin_read_label($link['title']??'',180),
            'category'=>sf_agent_admin_read_label($link['category']??'',40),
            'entity_type'=>$type,'entity_id'=>sf_agent_admin_read_label($link['entity_id']??'',100),
            'role'=>sf_agent_admin_read_label($link['role']??'',60),
            'public_visible'=>!empty($link['public_visible'])];
    }
    $matched=$total===null?$matched:max($matched,$total);
    return ['domain'=>'attachments','filter'=>$filter,'matched'=>$matched,'shown'=>count($rows),
        'truncated'=>$matched>count($rows),'rows'=>$rows,'read_only'=>true,
        'source'=>'stonefellow.admin.media_links'];
}
function sf_agent_admin_attachment_source(string $filter,int $limit=10):array {
    if(!in_array($filter,['all','track','release','show'],true))throw new InvalidArgumentException('Unsupported attachment filter.');
    sf_media_ensure_schema();$limit=max(1,min(20,$limit));$args=[];
    $where=$filter==='all'?'':' WHERE l.entity_type=?';if($filter!=='all')$args[]=$filter;
    $count=sf_db()->prepare('SELECT COUNT(*) FROM media_links l'.$where);$count->execute($args);
    $total=(int)$count->fetchColumn();
    $query=sf_db()->prepare('SELECT l.id,l.asset_id,l.entity_type,l.entity_id,l.role,l.public_visible,a.title,a.category FROM media_links l JOIN media_assets a ON a.id=l.asset_id'.$where.' ORDER BY l.id DESC LIMIT '.$limit);
    $query->execute($args);
    return sf_agent_admin_attachment_read($query->fetchAll(),$filter,$limit,$total);
}
function sf_agent_admin_read_brief(array $data):string {
    $domain=match($data['domain']){'catalog'=>'catalog songs','releases'=>'releases','shows'=>'shows','knowledge'=>'knowledge documents','media'=>'media assets','attachments'=>'media attachments',default=>'records'};
    $intro=(int)$data['matched'].' '.$domain.' match the '.$data['filter'].' filter';
    if(!$data['rows'])return $intro.'. No matching records were found. No changes made.';
    $items=[];
    foreach($data['rows'] as $row){
        $detail=($row['state']??$row['status']??'unknown').(!empty($row['public_visible'])?' · public':' · not public');
        if($data['domain']==='catalog' && !empty($row['missing_metadata']))$detail.=' · missing '.implode(', ',$row['missing_metadata']);
        if($data['domain']==='releases')$detail.=' · '.(int)$row['track_count'].' tracks'.($row['release_date']!==''?' · '.$row['release_date']:'');
        if($data['domain']==='shows')$detail.=' · '.$row['date'].' · '.$row['venue'].($row['city']!==''?' · '.$row['city']:'');
        if($data['domain']==='knowledge'){$detail=($row['agent_enabled']?'agent enabled':'private').' · '.$row['type'];$items[]=$row['name'].' ('.$row['id'].'): '.$detail;continue;}
        if($data['domain']==='media'){$detail=$row['category'].' · '.$row['attachment_count'].' attachments';}
        if($data['domain']==='attachments'){$detail=$row['entity_type'].' '.$row['entity_id'].' · '.$row['role'].($row['public_visible']?' · public':' · private');$items[]=$row['asset_title'].' (asset '.$row['asset_id'].'): '.$detail;continue;}
        $items[]=$row['title'].' ('.$row['id'].'): '.$detail;
    }
    return $intro.'. '.implode('; ',$items).'.'.($data['truncated']?' Showing the first '.(int)$data['shown'].'.':'').' Read only; no changes made.';
}
