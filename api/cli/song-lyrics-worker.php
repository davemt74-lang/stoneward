<?php
declare(strict_types=1);
/** Optional shared-host cron: process at most one queued block per invocation. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
require_once SF_ROOT.'/api/song-lyrics-import.php';

$adminId=0;
foreach(array_slice($argv,1) as $arg)
    if(preg_match('/\A--admin-id=([1-9][0-9]*)\z/',$arg,$match))$adminId=(int)$match[1];
if($adminId<1){fwrite(STDERR,"Usage: php api/cli/song-lyrics-worker.php --admin-id=ADMIN_ID\n");exit(2);}
try{
    sf_song_lyrics_import_admin($adminId);
    sf_song_lyrics_import_schema();
    sf_song_lyrics_import_recover();
    $query=sf_db()->query("SELECT id FROM song_lyrics_import_jobs WHERE status='queued' AND pause_requested=0 ORDER BY id LIMIT 1");
    $id=(int)$query->fetchColumn();$query->closeCursor();
    if($id<1){echo "No queued song lyric blocks.\n";exit;}
    $result=sf_song_lyrics_import_advance($adminId,$id);
    $job=$result['job'];
    echo json_encode(['job_id'=>$job['id'],'status'=>$job['status'],'completed_chunks'=>$job['completed_chunks'],
        'total_chunks'=>$job['total_chunks']],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,"Song lyric import could not advance. Check the listening copy and enabled OpenAI provider.\n");exit(1);
}
