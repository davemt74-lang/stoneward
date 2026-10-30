<?php
declare(strict_types=1);
/** Section 91B8D: private database storage, authorization, catalog, provenance. */
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT)');
$db->exec("INSERT INTO users VALUES(1,'user'),(2,'user')");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_privacy_personalization_enabled(int $id):bool{return $id===1;}
require dirname(__DIR__).'/api/agent-song-annotations-core.php';
$n=0;
$check=static function(bool $valid,string $name)use(&$n):void{
    if(!$valid){fwrite(STDERR,"91B8D FAIL: $name\n");exit(1);}
    echo "91B8D PASS: $name\n";$n++;
};
$catalog=[['id'=>'published-1','title'=>'First Track','release'=>'Happier Than Hay','audio'=>'/songs/one.mp3']];
$payload=['track_id'=>'published-1','title'=>'Spoof title','start_ms'=>12100,'end_ms'=>27700,
    'captured_at'=>'2020-10-30T10:12:35Z','transcript'=>"First guess\nSecond guess",
    'corrected_transcript'=>'Listener revision','question'=>'What does this section mean?',
    'report'=>'Interpretive report, not verified lyrics','mode'=>'interpretation','annotation'=>'My morning thought'];
$normalized=sf_song_annotation_validate($payload,$catalog);
$check($normalized['title']==='First Track'&&$normalized['release_title']==='Happier Than Hay',
    'Song and release identity resolved from trusted published catalog');
$check($normalized['start_ms']===12100&&$normalized['end_ms']===27700&&$normalized['review_status']==='user_edited_unverified',
    'Exact selection offsets and edited-but-unverified provenance');
$check($normalized['captured_at']==='2020-10-30T10:12:35+00:00',
    'Capture timestamp normalized to UTC');
$entry=sf_song_annotation_save(1,$payload,$catalog);
$check($entry['id']===1&&$entry['title']==='First Track','Explicit save returns a scoped annotation receipt');
$rows=sf_song_annotation_list(1);
$check(count($rows)===1&&$rows[0]['transcript_corrected']==='Listener revision',
    'Private corrected draft persists with original machine draft');
$check($rows[0]['transcript_draft']==="First guess\nSecond guess"&&$rows[0]['analysis_report']===$payload['report'],
    'Transcription and report remain separate provenance fields');
$check(sf_song_annotation_list(2)===[],'Cross-user reads denied by owner-bound database query');
$check(!sf_song_annotation_delete(2,1)&&count(sf_song_annotation_list(1))===1,
    'Cross-user deletion denied by owner-bound database query');
$reject=static function(array $change,array $tracks)use($payload,$check):void{
    try{sf_song_annotation_validate(array_replace($payload,$change),$tracks);$check(false,'Rejected invalid annotation');}
    catch(InvalidArgumentException){$check(true,'Invalid annotation rejected');}
};
$reject(['track_id'=>'private'], $catalog);
$reject([],[]);
$reject(['end_ms'=>12100],$catalog);
$reject(['transcript'=>''],$catalog);
$reject(['transcript'=>[]],$catalog);
$reject(['report'=>str_repeat('r',6501)],$catalog);
$reject(['corrected_transcript'=>str_repeat('x',4201)],$catalog);
$reject(['mode'=>'injected_override'],$catalog);
$reject(['report'=>'Analysis without a question','question'=>''],$catalog);
$reject(['captured_at'=>'2999-12-30T00:00:00Z'],$catalog);
try{sf_song_annotation_save(2,$payload,$catalog);$check(false,'Opted-out user cannot save to private Agent Brain');}
catch(RuntimeException){$check(true,'Personalization consent required before writing private song memory');}
$check(sf_song_annotation_delete(1,1),'Owner can delete their saved annotation');
$check(sf_song_annotation_list(1)===[],'Deleted annotation is no longer retrievable');
$check(sf_song_annotation_list(0)===[]&&!sf_song_annotation_delete(0,1),'Missing user identity cannot read or delete');
$check(!sf_song_annotation_delete(1,99),'Nonexistent note does not claim a deletion');
$controller=(string)file_get_contents(dirname(__DIR__).'/api/agent-song-annotations.php');
$check(str_contains($controller,'sf_require_user(false,$method===\'POST\')'),'Endpoint authenticates and checks CSRF for writes');
$check(!str_contains($controller,'sf_ai_llm_candidates')&&!str_contains($controller,'sf_openai_call'),
    'Private note storage does not automatically call external AI providers');
echo "Section 91B8D: $n private-annotation assertions passed\n";
