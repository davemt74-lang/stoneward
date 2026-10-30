<?php
declare(strict_types=1);
/** Section 91B8F: explicit per-note sharing, revocation, account isolation. */
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT)');
$db->exec("INSERT INTO users VALUES(1,'user'),(2,'user'),(3,'user')");
// Simulate an existing 91B8D deployment: there are no sharing columns yet.
$db->exec("CREATE TABLE agent_song_annotations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,
    track_id TEXT NOT NULL,title TEXT NOT NULL,release_title TEXT NOT NULL,
    start_ms INTEGER NOT NULL,end_ms INTEGER NOT NULL,captured_at TEXT NOT NULL,
    transcript_draft TEXT NOT NULL,transcript_corrected TEXT NOT NULL DEFAULT '',
    analysis_question TEXT NOT NULL DEFAULT '',analysis_report TEXT NOT NULL DEFAULT '',
    analysis_mode TEXT NOT NULL DEFAULT '',user_note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
$consent=[1=>true,2=>false,3=>true];
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_privacy_personalization_enabled(int $id):bool{global $consent;return !empty($consent[$id]);}
require dirname(__DIR__).'/api/agent-song-annotations-core.php';
$passes=0;
$check=static function(bool $ok,string $label)use(&$passes):void{
    if(!$ok){fwrite(STDERR,"91B8F FAIL: $label\n");exit(1);}
    $passes++;echo "91B8F PASS: $label\n";
};
sf_song_annotations_ensure_schema();
$columns=array_column($db->query('PRAGMA table_info(agent_song_annotations)')->fetchAll(),'name');
$check(in_array('agent_share_scope',$columns,true)&&in_array('agent_shared_at',$columns,true),
    'Existing private annotation table gains additive sharing fields');
$catalog=[['id'=>'published-1','title'=>'First Track','release'=>'Happier Than Hay','audio'=>'/songs/one.mp3']];
$payload=['track_id'=>'published-1','start_ms'=>10000,'end_ms'=>24000,
    'captured_at'=>'2026-10-29T10:12:35Z','transcript'=>'Machine original secret lyric',
    'corrected_transcript'=>'Listener edited secret lyric','annotation'=>'Morning listening reminds me of Arizona',
    'question'=>'What does it mean?','mode'=>'interpretation','report'=>'Interpretive report, not verified song facts'];
$id=sf_song_annotation_save(1,$payload,$catalog)['id'];
$check(sf_song_annotation_list(1)[0]['agent_share_scope']==='private',
    'New saved notes default to private, with no implied LLM consent');
$check(sf_song_annotation_agent_context(1,'What do my lyric notes say?')==='',
    'Private annotation is absent from future LLM context by default');
try{sf_song_annotation_set_share_scope(1,$id,'public');$check(false,'Invalid public scope accepted');}
catch(InvalidArgumentException){$check(true,'Public sharing scope is rejected');}
try{sf_song_annotation_set_share_scope(2,$id,'full');$check(false,'Opt-out accepted');}
catch(RuntimeException){$check(true,'Opted-out account cannot enable sharing');}
$check(sf_song_annotation_set_share_scope(3,$id,'full')===null,
    'Other opted-in account cannot share a note by guessing its ID');
$summary=sf_song_annotation_set_share_scope(1,$id,'summary');
$check(($summary['scope']??'')==='summary'&&$summary['changed']===true,
    'Owner explicitly opts this note into summary-only Agent Brain use');
$ctx=sf_song_annotation_agent_context(1,'What do my saved lyric notes mean?');
$check(str_contains($ctx,'Morning listening')&&str_contains($ctx,'Interpretive report'),
    'Shared summary includes only the listener note and report excerpt');
$check(!str_contains($ctx,'Machine original secret lyric')&&!str_contains($ctx,'Listener edited secret lyric'),
    'Summary-only access never transmits the raw or corrected lyric draft');
$check(sf_song_annotation_agent_context(1,'Remember my appointment','published-1')==='',
    'Unrelated reminder conversation cannot pull shared song notes');
$check(sf_song_annotation_agent_context(1,'What does this verse mean?','other-track')==='',
    'Wrong active track and no broad request do not expose the note');
$check(sf_song_annotation_agent_context(2,'What do my lyric notes say?')==='',
    'An account without access never sees another listener’s shared notes');
$full=sf_song_annotation_set_share_scope(1,$id,'full');
$ctx=sf_song_annotation_agent_context(1,'What does this verse mean?','published-1');
$check(($full['scope']??'')==='full'&&str_contains($ctx,'Listener edited secret lyric'),
    'Separate full-scope authorization permits relevant edited draft context');
$check(str_contains($ctx,'user_edited_unverified')&&str_contains($ctx,'untrusted'),
    'Model context labels edited drafts as unverified and untrusted data');
$consent[1]=false;
$check(sf_song_annotation_agent_context(1,'What do my lyric notes mean?')==='',
    'Turning off account personalization suppresses previously shared content');
$revoke=sf_song_annotation_set_share_scope(1,$id,'private');
$check(($revoke['scope']??'')==='private'&&sf_song_annotation_list(1)[0]['agent_share_scope']==='private',
    'Revocation remains available after account consent is disabled');
$history=sf_song_annotation_share_history(1,$id);
$check(count($history)===3&&$history[0]['new_scope']==='private',
    'Private change history records summary, full, and revocation');
$check(sf_song_annotation_share_history(3,$id)===[],
    'Cross-account sharing history cannot be read');
$consent[1]=true;
sf_song_annotation_set_share_scope(1,$id,'full');
$check(sf_song_annotation_update(1,$id,['corrected_transcript'=>'Different confirmed revision']),
    'Owner can change their saved correction');
$check(sf_song_annotation_list(1)[0]['agent_share_scope']==='private',
    'Editing a shared note automatically revokes earlier consent');
$check(sf_song_annotation_agent_context(1,'What does this verse mean?','published-1')==='',
    'Changed, unapproved lyrics cannot leak through the old sharing setting');
$check(sf_song_annotation_delete(1,$id)&&sf_song_annotation_share_history(1,$id)===[],
    'Deleting a note also removes its private sharing audit entries');
$route=(string)file_get_contents(dirname(__DIR__).'/api/agent-song-annotations.php');
$runtime=(string)file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$check(str_contains($route,"\$action==='share'")&&str_contains($route,'sf_require_user(false,$method===\'POST\')'),
    'Sharing endpoint requires authenticated CSRF-protected writes');
$check(str_contains($runtime,'sf_song_annotation_agent_context($userId,$message')&&str_contains($runtime,"if(\$sharedSongNotes==='')"),
    'Signed-in chat uses scoped context and skips secondary LLM evaluation on private-note turns');
echo "Section 91B8F: $passes sharing and privacy assertions passed\n";
