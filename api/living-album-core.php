<?php
declare(strict_types=1);
/**
 * 88C1 Living Albums. All material comes from existing published Stonefellow sources.
 * This is a public read-only projection, not a new rights, media or member database.
 */
function sf_living_text(mixed $value,int $max=800):string {
    $s=strip_tags((string)$value);
    $s=preg_replace('/[\x00-\x1F\x7F]/u',' ',$s)??'';
    return trim(function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max));
}
function sf_living_url(mixed $value):string {
    $v=trim((string)$value);
    if(strlen($v)>600||preg_match('/[\s"<>\x00-\x1F]/',$v))return '';
    // Public media links are server-owned paths or HTTPS imagery only.
    return preg_match('#^(?:https://[^/]+/.+|(?:api/)?media\.php\?id=[A-Za-z0-9]+(?:&variant=[a-z]+)?|assets/[A-Za-z0-9/_%.+-]+|uploads/[A-Za-z0-9/_%.+-]+)$#D',$v)?$v:'';
}
function sf_living_visual(array $m):?array {
    if(($m['category']??'')!=='image')return null;
    $url=sf_living_url($m['url']??'');if($url==='')return null;
    return ['url'=>$url,'thumb_url'=>sf_living_url($m['thumb_url']??'')?:$url,
      'title'=>sf_living_text($m['title']??'',120),
      'alt'=>sf_living_text($m['alt_text']??$m['title']??'',160),
      'credit'=>sf_living_text($m['credit']??'',120)];
}
function sf_living_story_card(array $r):?array {
    if(($r['status']??'published')!=='published'||empty($r['readiness']['ready']))return null;
    $id=(int)($r['id']??0);$slug=(string)($r['slug']??'');
    if($id<1||!preg_match('/^[a-z0-9-]{1,160}$/D',$slug))return null;
    $cover=null;foreach((array)($r['media']??[]) as $m){
        if(!is_array($m))continue;$cover=sf_living_visual($m);if($cover)break;
    }
    return ['id'=>$id,'slug'=>$slug,'title'=>sf_living_text($r['title']??'',180),
      'summary'=>sf_living_text($r['summary']??$r['body_text']??'',650),
      'type'=>sf_living_text($r['entry_type']??'story',60),'artwork'=>$cover,
      'view'=>'story','params'=>['slug'=>$slug]];
}
function sf_living_related_to_album(array $links,string $id,array $trackIds):bool {
    foreach($links as $link){
        if(!is_array($link))continue;$type=(string)($link['type']??'');$key=(string)($link['id']??'');
        if(($type==='release'&&$key===$id)||($type==='track'&&isset($trackIds[$key])))return true;
    }
    return false;
}
function sf_living_album_assemble(array $release,array $tracks,array $stories,array $videos,array $galleries,array $media=[]):array {
    $albumId=(string)($release['id']??'');
    if($albumId===''||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$albumId)
      ||($release['state']??'published')!=='published'||($release['public_visible']??true)===false)
        throw new InvalidArgumentException('Release is not published.');
    $trackIds=[];$songs=[];$count=0;
    foreach(array_slice((array)($release['track_ids']??[]),0,120) as $id){
        $id=(string)$id;if($id===''||isset($trackIds[$id]))continue;
        $t=$tracks[$id]??null;
        if(!is_array($t)||($t['public_visible']??true)===false
           ||in_array((string)($t['state']??''),['draft','private','archived'],true))continue;
        $trackIds[$id]=true;
        $metadata=(array)($t['metadata']??[]);
        $songs[]=['id'=>$id,'title'=>sf_living_text($t['title']??'',180),
          'position'=>++$count,'duration'=>max(0,(int)($t['duration']??0)),
          'story'=>sf_living_text($t['story']??'',520),
          'recording_notes'=>sf_living_text($metadata['recording_notes']??$metadata['notes']??'',360),
          'credits'=>array_slice(array_values(array_filter(array_map(fn($x)=>sf_living_text($x,130),(array)($t['credits']??[])))),0,16),
          'view'=>'track','params'=>['id'=>$id]];
    }
    $storyCards=[];$storySeen=[];
    foreach(array_slice($stories,0,250) as $story){
        if(!is_array($story))continue;
        $type=(string)($story['entity_type']??'');$key=(string)($story['entity_id']??'');
        if(!(($type==='release'&&$key===$albumId)||($type==='track'&&isset($trackIds[$key]))))continue;
        $card=sf_living_story_card($story);if(!$card||isset($storySeen[$card['id']]))continue;
        $storySeen[$card['id']]=true;$card['linked_track_id']=$type==='track'?$key:null;
        $storyCards[]=$card;
        if(count($storyCards)>=15)break;
    }
    $videoCards=[];
    foreach(array_slice($videos,0,250) as $v){
        if(!is_array($v)||!sf_living_related_to_album((array)($v['entities']??[]),$albumId,$trackIds))continue;
        $slug=(string)($v['slug']??'');if(!preg_match('/^[a-z0-9-]{1,160}$/D',$slug))continue;
        $poster=is_array($v['poster']??null)?sf_living_visual((array)$v['poster']):null;
        $videoCards[]=['id'=>(int)($v['id']??0),'slug'=>$slug,'title'=>sf_living_text($v['title']??'',160),
          'description'=>sf_living_text($v['description']??'',240),'poster'=>$poster,
          'view'=>'video','params'=>['slug'=>$slug]];
        if(count($videoCards)>=12)break;
    }
    $galleryCards=[];
    foreach(array_slice($galleries,0,250) as $g){
        if(!is_array($g)||!sf_living_related_to_album((array)($g['entities']??[]),$albumId,$trackIds))continue;
        $slug=(string)($g['slug']??'');if(!preg_match('/^[a-z0-9-]{1,160}$/D',$slug))continue;
        $cover=is_array($g['cover']??null)?sf_living_visual((array)$g['cover']):null;
        $galleryCards[]=['id'=>(int)($g['id']??0),'slug'=>$slug,'title'=>sf_living_text($g['title']??'',160),
          'summary'=>sf_living_text($g['summary']??'',250),'cover'=>$cover,
          'view'=>'photo','params'=>['slug'=>$slug]];
        if(count($galleryCards)>=12)break;
    }
    $visuals=[];foreach(array_slice($media,0,80) as $m){
        if(!is_array($m))continue;$pic=sf_living_visual($m);
        if($pic)$visuals[]=$pic;if(count($visuals)>=12)break;
    }
    return ['id'=>$albumId,'title'=>sf_living_text($release['title']??'',180),
      'description'=>sf_living_text($release['description']??'',700),
      'liner_notes'=>sf_living_text($release['liner_notes']??$release['notes']??'',1600),
      'release_date'=>sf_living_text($release['release_date']??'',32),
      'credits'=>array_slice(array_values(array_filter(array_map(fn($x)=>sf_living_text($x,130),(array)($release['credits']??[])))),0,30),
      'songs'=>$songs,'stories'=>$storyCards,'videos'=>$videoCards,'photos'=>$galleryCards,
      'artwork'=>$visuals,
      'counts'=>['songs'=>count($songs),'stories'=>count($storyCards),
        'videos'=>count($videoCards),'photos'=>count($galleryCards)],
      'rights'=>['streaming'=>'Existing playback authorization applies',
        'vinyl'=>'Existing print-license and fulfillment checks apply'],
      'source'=>'published_catalog_and_admin_media',
      'private_media_included'=>false,'actions_executed'=>false];
}
function sf_living_album_public(string $id):?array {
    if(!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id))return null;
    $release=sf_seo_public_release($id);
    if(!$release)return null;
    $tracks=[];foreach(array_slice((array)($release['track_ids']??[]),0,120) as $tid){
        $tid=(string)$tid;if($tid==='')continue;
        $t=sf_seo_public_track($tid);if($t)$tracks[$tid]=$t;
    }
    // Optional editorial sources are unavailable, not a reason to leak drafts or break playback.
    $stories=[];$videos=[];$galleries=[];$media=[];
    foreach(['stories','videos','galleries','media'] as $type){
        try{
            if($type==='stories'){
                $stories=sf_story_for_entity('release',$id,true);
                foreach(array_keys($tracks) as $tid)
                    $stories=array_merge($stories,sf_story_for_entity('track',(string)$tid,true));
            }elseif($type==='videos')$videos=sf_video_public_list();
            elseif($type==='galleries')$galleries=sf_gallery_public_list();
            elseif(function_exists('sf_media_public_links'))$media=sf_media_public_links('release',$id);
        }catch(Throwable $e){error_log('[Living Albums '.$type.'] '.get_class($e));}
    }
    return sf_living_album_assemble($release,$tracks,$stories,$videos,$galleries,$media);
}
