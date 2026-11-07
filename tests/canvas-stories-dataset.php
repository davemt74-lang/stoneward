<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-story-dataset-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/audio'] as $directory)mkdir($fixture.'/'.$directory,0700,true);
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'],true).';');
file_put_contents($fixture.'/config/store.php','<?php return [];');
file_put_contents($fixture.'/data/catalog.json',json_encode([
    ['id'=>'published-track','title'=>'Published song','release_id'=>'published-release','audio'=>'assets/audio/published.mp3'],
    ['id'=>'hidden-track','title'=>'Private draft track','state'=>'draft'],
],JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/assets/audio/published.mp3',"ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x00".str_repeat('A',413),8));
$release=['id'=>'published-release','title'=>'Published release','state'=>'published','track_ids'=>['published-track']];
file_put_contents($fixture.'/data/releases.json',json_encode([$release],JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/shows.json','[]');
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/agent-canvas-data.php';

$checks=0;
function check(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
    $GLOBALS['checks']++;
    echo "PASS $message\n";
}

sf_db()->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
sf_canvas_story_prepare();
$ids=[];
$story=['entry_type'=>'essay','status'=>'published','body_text'=>'A published story paragraph.'];
for($i=1;$i<=125;$i++){
    $ids[]=(string)sf_story_save(['title'=>'Public story '.$i,'position'=>$i]+$story,0)['id'];
}
$body="An opening paragraph.\n\n".str_repeat('🥁',4100);
$linked=(string)sf_story_save([
    'entry_type'=>'track_story','status'=>'published','entity_id'=>'published-track',
    'title'=>'The session','summary'=>str_repeat('é',700),'body_text'=>$body,
    'seo_description'=>'SECRET_PRIVATE_SEO_NOT_IN_CANVAS',
    'tags'=>['behind-the-song','live-session'],
],0)['id'];
$draft=(string)sf_story_save([
    'title'=>'SECRET_DRAFT_TITLE','body_text'=>'SECRET_DRAFT_BODY','status'=>'draft','tags'=>['behind-the-song'],
]+$story,0)['id'];

$base='<div class="sf-data-row"><span class="sf-field-title"></span></div>';
$html='<section class="sf-dataset-published_stories">'.$base.'</section>';
$read=static fn(?array $scope,string $markup='',?array $music=null,array $viewer=[]):array=>
    sf_agent_canvas_data_for_scene($viewer,$markup?:$html,'',$music,$scope===null?null:['stories'=>$scope]);
$all=['mode'=>'all'];
$allRows=$read($all)['published_stories']['rows'];
check(count($allRows)===126&&!in_array($draft,array_column($allRows,'story_id'),true),
    'All Stories delivers every published row beyond the display limit and excludes drafts');
check($allRows===$read($all,'',null,['id'=>999,'role'=>'admin'])['published_stories']['rows'],
    'Guest and signed-in viewers receive the same public story projection');
$last=end($ids);
$scope=['mode'=>'selected','story_ids'=>[$last,$linked,$ids[0],$draft]];
$selected=$read($scope)['published_stories']['rows'];
check(array_column($selected,'story_id')===[$last,$linked,$ids[0]],
    'Explicit selection retains source order while dropping unavailable private rows');
$late='<section class="sf-dataset-published_stories sf-filter-story_id--'.$last.'">'.$base.'</section>';
check(array_column($read($all,$late)['published_stories']['rows'],'story_id')===[$last],
    'Exact story ID filter reaches a late row in All Stories');
$none=$read(['mode'=>'selected','story_ids'=>[]])['published_stories']['rows'];
check($none===[],'Explicit clearing does not reopen the full public catalog');

$row=$selected[1];
check($row['summary_truncated']===1&&mb_strlen($row['summary'],'UTF-8')===640&&
      $row['body_truncated']===1&&mb_strlen($row['body_text'],'UTF-8')===4000&&str_starts_with($row['body_text'],"An opening paragraph.\n\n"),
    'Narrative projection preserves Unicode and paragraphs at explicit length bounds');
check($row['track_id']==='published-track'&&$row['related_id']==='published-track'&&$row['related_title']==='Published song',
    'A linked published song exposes its canonical playback identity and public relation');
check(!str_contains(json_encode($allRows,JSON_THROW_ON_ERROR),'SECRET_')&&
      !array_intersect_key($row,array_flip(['created_by','stored_path','media','readiness'])),
    'Canvas rows allowlist public facts and exclude administrative and storage fields');
$tag=['mode'=>'tag','tag'=>'behind-the-song'];
check(array_column($read($tag)['published_stories']['rows'],'story_id')===[$linked]&&str_contains($row['tags'],'behind-the-song'),
    'A live tag scope keeps only its matching published stories');
$onlyLinked=['mode'=>'selected','story_ids'=>[$linked]];
$noAudio=$read($onlyLinked,'',['mode'=>'selected','track_ids'=>[]])['published_stories']['rows'][0];
$oneAudio=$read($onlyLinked,'',['mode'=>'selected','track_ids'=>['published-track']])['published_stories']['rows'][0];
check($noAudio['track_id']===''&&$noAudio['related_id']==='published-track'&&$oneAudio['track_id']==='published-track',
    'Explicit audio scopes guard linked playback without removing public story metadata');

$tools=array_column(sf_agent_canvas_data_tools(),null,'id');
check($tools['published_stories']['scope']==='fan'&&$tools['published_stories']['fields']['body_text']==='long_text',
    'Provider inventory exposes the public story binding with a long-text contract');
sf_canvas_permission_save(['id'=>7,'role'=>'admin'],1,['canvas.data.published_stories'=>false]);
check(!isset($read($all)['published_stories'])&&!in_array('published_stories',array_column(sf_agent_canvas_data_tools(),'id'),true),
    'Disabled story permission removes dataset delivery and discovery');
sf_canvas_permission_save(['id'=>7,'role'=>'admin'],2,['canvas.data.published_stories'=>true]);

sf_db()->prepare("UPDATE story_entries SET status='archived' WHERE id=?")->execute([$last]);
check(count($read($all)['published_stories']['rows'])===125&&
      array_column($read($scope)['published_stories']['rows'],'story_id')===[$linked,$ids[0]],
    'Fresh all and selected scopes omit archived story data');
file_put_contents($fixture.'/data/releases.json',json_encode([array_replace($release,['state'=>'draft'])],JSON_THROW_ON_ERROR));
check($read($tag)['published_stories']['rows']===[]&&$read($onlyLinked)['published_stories']['rows']===[],
    'Withdrawing a linked release removes the story from its tag and exact selection');
echo "Story dataset checks passed: $checks\n";
