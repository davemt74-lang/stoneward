<?php
declare(strict_types=1);

function sf_archive_text(mixed $value,int $max=500): string {
    $s=trim((string)$value);
    $s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$s)??'';
    return function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max);
}
function sf_archive_media(mixed $value): array {
    if(!is_array($value))return [];
    $out=[];
    foreach($value as $row){
        if(!is_array($row))continue;$url=sf_archive_text($row['url']??'',500);if($url==='')continue;
        $out[]=['type'=>sf_archive_text($row['type']??'link',40),'title'=>sf_archive_text($row['title']??'Archive item',180),'url'=>$url,'caption'=>sf_archive_text($row['caption']??'',500),'date'=>sf_archive_text($row['date']??'',20)];
        if(count($out)>=80)break;
    }
    return $out;
}
function sf_archive_track_meta(array $track): array {
    $a=is_array($track['archive']??null)?$track['archive']:[];
    $out=[];
    foreach(['era','canonical_work_id','version_type','version_label','version_of','recorded_date','session','source_notes'] as $k)$out[$k]=sf_archive_text($a[$k]??'',$k==='source_notes'?4000:180);
    $out['alternate_track_ids']=array_values(array_unique(array_filter(array_map(fn($v)=>sf_archive_text($v,100),(array)($a['alternate_track_ids']??[])))));
    $out['personnel']=[];
    foreach((array)($a['personnel']??[]) as $p){if(!is_array($p))continue;$name=sf_archive_text($p['name']??'',140);if($name==='')continue;$out['personnel'][]=['name'=>$name,'instrument'=>sf_archive_text($p['instrument']??'',100),'role'=>sf_archive_text($p['role']??'',100)];if(count($out['personnel'])>=80)break;}
    $out['media']=sf_archive_media($a['media']??[]);
    return $out;
}
function sf_archive_release_meta(array $release): array {
    $a=is_array($release['archive']??null)?$release['archive']:[];
    return ['era'=>sf_archive_text($a['era']??'',180),'edition'=>sf_archive_text($a['edition']??'',180),'original_release_date'=>sf_archive_text($a['original_release_date']??'',20),'reissue_of'=>sf_archive_text($a['reissue_of']??'',100),'media'=>sf_archive_media($a['media']??[])];
}
function sf_archive_version_groups(array $catalog): array {
    $groups=[];
    foreach($catalog as $track){if(!is_array($track)||empty($track['id']))continue;$a=sf_archive_track_meta($track);$key=$a['canonical_work_id']!==''?$a['canonical_work_id']:$a['version_of'];if($key==='')continue;$groups[$key][]=['id'=>(string)$track['id'],'title'=>(string)($track['title']??$track['id']),'version_type'=>$a['version_type'],'version_label'=>$a['version_label'],'recorded_date'=>$a['recorded_date'],'era'=>$a['era']];}
    foreach($groups as $key=>$rows)if(count($rows)<2)unset($groups[$key]);
    ksort($groups,SORT_NATURAL|SORT_FLAG_CASE);return $groups;
}
function sf_archive_payload(array $catalog,array $releases): array {
    $timeline=[];$eras=[];
    foreach($catalog as $track){if(!is_array($track)||empty($track['id']))continue;$a=sf_archive_track_meta($track);$m=is_array($track['metadata']??null)?$track['metadata']:[];$date=$a['recorded_date']!==''?$a['recorded_date']:(string)($m['release_date']??($track['year']??''));if($a['era']!=='')$eras[$a['era']]=true;if($date!==''||$a['era']!==''||$a['version_type']!==''||$a['session']!=='')$timeline[]=['type'=>'track','id'=>(string)$track['id'],'title'=>(string)($track['title']??$track['id']),'date'=>$date,'era'=>$a['era'],'label'=>$a['version_label']!==''?$a['version_label']:($a['version_type']!==''?$a['version_type']:$a['session'])];}
    foreach($releases as $release){if(!is_array($release)||empty($release['id']))continue;$a=sf_archive_release_meta($release);if($a['era']!=='')$eras[$a['era']]=true;$timeline[]=['type'=>'release','id'=>(string)$release['id'],'title'=>(string)($release['title']??$release['id']),'date'=>$a['original_release_date']!==''?$a['original_release_date']:(string)($release['release_date']??''),'era'=>$a['era'],'label'=>$a['edition']!==''?$a['edition']:(string)($release['type']??'release')];}
    usort($timeline,fn($x,$y)=>strcmp((string)($x['date']?:'9999'),(string)($y['date']?:'9999'))?:strcmp((string)$x['title'],(string)$y['title']));
    $eraList=array_keys($eras);natcasesort($eraList);
    return ['eras'=>array_values($eraList),'timeline'=>$timeline,'version_groups'=>sf_archive_version_groups($catalog),'track_count'=>count($catalog),'release_count'=>count($releases)];
}
