<?php
declare(strict_types=1);
/** Section 91B8E: edits are scoped, drafts immutable, and consented. */
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT)');
$db->exec("INSERT INTO users VALUES(1,'user'),(2,'user'),(3,'user')");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_privacy_personalization_enabled(int $id):bool{return in_array($id,[1,2],true);}
require dirname(__DIR__).'/api/agent-song-annotations-core.php';
$checks=0;
$check=static function(bool $condition,string $name)use(&$checks):void{
    if(!$condition){fwrite(STDERR,"91B8E FAIL: $name\n");exit(1);}
    echo "91B8E PASS: $name\n";$checks++;
};
$catalog=[['id'=>'published-1','title'=>'First Track','release'=>'Happier Than Hay','audio'=>'/songs/one.mp3']];
$payload=['track_id'=>'published-1','start_ms'=>10000,'end_ms'=>24000,
    'captured_at'=>'2026-10-29T10:12:35Z','transcript'=>'Original machine guess',
    'question'=>'What does it mean?','mode'=>'interpretation','report'=>'Old interpretation'];
$saved=sf_song_annotation_save(1,$payload,$catalog);$id=$saved['id'];
$check($id>0,'Authenticated and consented owner created a note');
$check(!sf_song_annotation_update(2,$id,['corrected_transcript'=>'Attacker overwrote this']),
    'Another account cannot update even by knowing the ID');
$check(sf_song_annotation_update(1,$id,['corrected_transcript'=>'Corrected listening draft','annotation'=>'My note']),
    'Owner can explicitly correct their saved draft');
$row=sf_song_annotation_list(1)[0];
$check($row['transcript_draft']==='Original machine guess'&&$row['transcript_corrected']==='Corrected listening draft',
    'Original machine draft is immutable and correction separately labeled');
$check($row['start_ms']==10000&&$row['end_ms']==24000&&$row['track_id']==='published-1',
    'Correction preserves song identity and exact offsets');
$check($row['analysis_report']===''&&$row['analysis_question']===''&&$row['analysis_mode']==='',
    'Report for the earlier draft is invalidated on correction');
$check($row['user_note']==='My note','Personal note persists separately');
foreach([['corrected_transcript'=>''],['corrected_transcript'=>str_repeat('x',4201)],['corrected_transcript'=>[]]] as $bad){
    try{sf_song_annotation_update(1,$id,$bad);$check(false,'Invalid edit accepted');}
    catch(InvalidArgumentException){$check(true,'Invalid edit rejected');}
}
try{sf_song_annotation_update(3,$id,['corrected_transcript'=>'Consent disabled']);$check(false,'Opted-out update accepted');}
catch(RuntimeException){$check(true,'Opt-out cannot add more private edits');}
$check(sf_song_annotation_delete(1,$id)&&sf_song_annotation_list(1)===[],
    'Owner can permanently delete saved annotation');
$controller=file_get_contents(dirname(__DIR__).'/api/agent-song-annotations.php');
$check(str_contains($controller,"\$action==='update'")&&str_contains($controller,'sf_require_user(false,$method===\'POST\')'),
    'Update endpoint uses the same signed-in CSRF-protected write path');
echo "Section 91B8E: $checks edit and privacy assertions passed\n";
