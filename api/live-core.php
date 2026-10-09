<?php
declare(strict_types=1);

function sf_live_shows(): array {
    $path=SF_ROOT.'/data/shows.json';
    if(!is_file($path))return [];
    $rows=json_decode((string)file_get_contents($path),true);
    return is_array($rows)?array_values(array_filter($rows,'is_array')):[];
}
function sf_live_public_shows(): array {
    $rows=array_values(array_filter(sf_live_shows(),fn($s)=>($s['public_visible']??true)!==false));
    usort($rows,fn($a,$b)=>strcmp((string)($a['date']??''),(string)($b['date']??''))?:strcmp((string)($a['venue']??''),(string)($b['venue']??'')));
    return $rows;
}
function sf_live_show_by_id(string $id): ?array {
    foreach(sf_live_public_shows() as $show)if((string)($show['id']??'')===$id)return $show;
    return null;
}
function sf_live_location(array $show): string {
    return implode(', ',array_values(array_filter([(string)($show['city']??''),(string)($show['region']??''),(string)($show['country']??'')])));
}
function sf_live_summary(array $show,array $trackMap=[]): string {
    $set=[];foreach((array)($show['setlist_track_ids']??[]) as $id)if(isset($trackMap[(string)$id]))$set[]=(string)($trackMap[(string)$id]['title']??$id);
    return implode(' | ',array_values(array_filter([
        (string)($show['date']??''),(string)($show['venue']??''),sf_live_location($show),(string)($show['tour']??''),(string)($show['era']??''),
        $set?'setlist '.implode(', ',$set):'',(string)($show['archive_notes']??'')
    ])));
}
