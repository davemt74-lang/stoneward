<?php
declare(strict_types=1);
require_once __DIR__.'/song-moments-core.php';
require_once __DIR__.'/song-lyrics-import.php';

/** An explicit admin action promotes reviewed timeline words into the existing Songbook catalog. */
function sf_song_lyrics_apply_catalog(string $trackId,int $adminId,bool $confirmed): array {
    if($adminId<1||!$confirmed)throw new InvalidArgumentException('Confirm saving reviewed lyrics to this song.');
    $track=sf_song_moments_track($trackId);$fingerprint=sf_song_moments_audio_fingerprint($track);
    if($fingerprint==='')throw new InvalidArgumentException('Choose a managed published listening copy.');
    $path=SF_ROOT.'/data/catalog.json';$bytes=file_get_contents($path);
    $catalog=is_string($bytes)?json_decode($bytes,true):null;
    if(!is_array($catalog))throw new RuntimeException('The song catalog could not be read.');
    sf_song_lyrics_import_schema();
    $job=sf_db()->prepare("SELECT status FROM song_lyrics_import_jobs WHERE track_id=? AND source_fingerprint=? ORDER BY id DESC LIMIT 1");
    $job->execute([$trackId,$fingerprint]);$importState=$job->fetchColumn();$job->closeCursor();
    if($importState!==false&&$importState!=='ready')throw new InvalidArgumentException('Finish importing the whole song before saving its catalog lyrics.');
    sf_song_moments_schema();$q=sf_db()->prepare("SELECT body,status,lyric_state,at_ms,end_ms,revision FROM song_timeline_moments WHERE track_id=? AND source_fingerprint=? AND kind='lyric' AND source_annotation_id=0 AND status<>'removed' ORDER BY at_ms,id LIMIT 701");
    $q->execute([$trackId,$fingerprint]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$rows||count($rows)>700)throw new InvalidArgumentException('Import and review this song’s lyric segments first.');
    foreach($rows as $row)if($row['status']!=='published'||$row['lyric_state']!=='approved')
        throw new InvalidArgumentException('Review and publish every current lyric segment before saving the song lyrics.');
    $lyrics=implode("\n",array_column($rows,'body'));
    if(strlen($lyrics)>42000)throw new InvalidArgumentException('The combined song lyrics exceed the catalog limit.');
    $found=false;
    foreach($catalog as &$entry)if((string)($entry['id']??'')===$trackId){
        if(!hash_equals($fingerprint,sf_song_moments_audio_fingerprint($entry)))throw new RuntimeException('The song audio changed. Reload before saving lyrics.');
        $entry['lyrics']=$lyrics;
        if(!is_array($entry['metadata']??null))$entry['metadata']=[];
        $entry['metadata']['lyrics_source']=['kind'=>'reviewed_timeline','fingerprint'=>$fingerprint,'approved_by'=>$adminId,
            'approved_at'=>gmdate('c'),'segments'=>count($rows),'revision_hash'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
        $found=true;break;
    }
    unset($entry);
    if(!$found)throw new InvalidArgumentException('Song not found.');
    sf_admin_write_catalog($catalog,hash('sha256',$bytes));
    return ['track_id'=>$trackId,'segments'=>count($rows),'source_fingerprint'=>$fingerprint,'saved'=>true];
}
