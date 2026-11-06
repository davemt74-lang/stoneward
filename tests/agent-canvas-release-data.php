<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/sf-canvas-release-data-'.bin2hex(random_bytes(5));
mkdir($fixture.'/config',0700,true);mkdir($fixture.'/data',0700,true);
register_shutdown_function(static function()use($fixture):void{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'],true).';');
file_put_contents($fixture.'/config/store.php','<?php return [];');
$catalog=[];$bulkIds=[];
for($i=1;$i<=150;$i++){
    $id='bulk-'.$i;$bulkIds[]=$id;
    $catalog[]=['id'=>$id,'title'=>'Published song '.$i,'release_id'=>'bulk','duration'=>120,'private_master_audio'=>'private-master-path'];
}
$catalog=[...$catalog,
    ['id'=>'target-first','title'=>'First target recording','release_id'=>'target','duration'=>'310'],
    ['id'=>'target-last','title'=>'Last target recording','release_id'=>'target','duration'=>-5],
    ['id'=>'private-song','title'=>'Private recording','release_id'=>'target','public_visible'=>false],
    ['id'=>'draft-song','title'=>'Draft recording','release_id'=>'target','state'=>'draft'],
    ['id'=>'hidden-release-song','title'=>'Hidden release recording','release_id'=>'hidden'],
    ['id'=>'legacy-id','title'=>'Legacy ID association','release_id'=>'legacy','duration'=>51],
    ['id'=>'legacy-title','title'=>'Legacy exact title association','release'=>'Legacy name','duration'=>71],
    ['id'=>'wrong-title-case','title'=>'Different legacy label','release'=>'legacy NAME'],
    ['id'=>'explicit-other-release','title'=>'Explicit other relationship','release_id'=>'empty-1','release'=>'Legacy name'],
];
$releases=[
    ['id'=>'bulk','title'=>'Bulk release','track_ids'=>$bulkIds,'release_date'=>'2050-01-01'],
    ['id'=>'target','title'=>'Requested release','track_ids'=>['target-last','private-song','draft-song','target-first','target-last','hidden-release-song','missing-song'],'release_date'=>'2001-01-01'],
    ['id'=>'legacy','title'=>'Legacy name','release_date'=>'2000-01-01'],
    ['id'=>'hidden','title'=>'Hidden release','track_ids'=>['hidden-release-song'],'state'=>'published','public_visible'=>false,'release_date'=>'2060-01-01'],
    ['id'=>'draft','title'=>'Draft release','state'=>'draft','track_ids'=>[],'release_date'=>'2061-01-01'],
];
for($i=1;$i<=7;$i++)$releases[]=['id'=>'empty-'.$i,'title'=>'Empty release '.$i,'track_ids'=>[],'release_date'=>'2020-01-0'.$i];
file_put_contents($fixture.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/releases.json',json_encode($releases,JSON_THROW_ON_ERROR));
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require $root.'/api/agent-canvas-data.php';
$checks=0;
function check(bool $condition,string $label):void{
    global $checks;
    if(!$condition)throw new RuntimeException($label);
    $checks++;echo 'PASS '.$label.PHP_EOL;
}
$tools=array_column(sf_agent_canvas_data_tools(),null,'id');
check(isset($tools['published_release_tracks'])&&$tools['published_release_tracks']['max_rows']===120&&
    $tools['published_release_tracks']['filters']===['release_id','track_id'],
    'public release track tool advertises a bounded exact-filter contract');
foreach(['published_catalog','published_release_tracks'] as $id){
    check($tools[$id]['actions']===['play_track'=>['field'=>'track_id']]&&sf_agent_tool_allowed($id,'guest'),
        $id.' declares the existing track playback action for public listeners');
}
$rows=sf_agent_canvas_release_track_rows();
$target=array_values(array_filter($rows,static fn(array $row):bool=>$row['release_id']==='target'));
check(array_column($target,'track_id')===['target-last','target-first']&&array_column($target,'position')===[1,2]&&
    array_column($target,'duration_seconds')===[0,310],
    'release membership preserves public recording order and removes private, missing and duplicate tracks');
$legacy=array_values(array_filter($rows,static fn(array $row):bool=>$row['release_id']==='legacy'));
check(array_column($legacy,'track_id')===['legacy-id','legacy-title'],
    'legacy membership uses exact canonical release IDs or exact release titles');
check(!str_contains(json_encode($rows,JSON_THROW_ON_ERROR),'Hidden release recording')&&
    !str_contains(json_encode($rows,JSON_THROW_ON_ERROR),'private-master-path'),
    'release projection excludes unpublished metadata and private storage fields');
$html='<section class="sf-dataset-published_release_tracks sf-filter-release_id--target"><div class="sf-data-row"><span class="sf-field-title"></span></div></section>';
$data=sf_agent_canvas_data_for_scene([],$html);
check($data['published_release_tracks']['rows']===$target&&$data['published_release_tracks']['actions']===$tools['published_release_tracks']['actions'],
    'a requested release beyond the first 120 rows resolves before truncation and carries playback authority');
check(array_keys($data['published_release_tracks']['rows'][0])===['release_id','release_title','track_id','title','duration_seconds','position'],
    'delivered release rows include only the declared dataset fields');
$single=sf_agent_canvas_data_for_scene([],str_replace('sf-filter-release_id--target','sf-filter-release_id--target sf-filter-track_id--target-first',$html));
check(array_column($single['published_release_tracks']['rows'],'track_id')===['target-first'],
    'multiple filters on one release container must all match');
$unknown=sf_agent_canvas_data_for_scene([],str_replace('sf-filter-release_id--target','sf-filter-release_id--not-a-real-release',$html));
check($unknown['published_release_tracks']['rows']===[],
    'an unknown release filter produces an empty dataset');
$invalid=sf_agent_canvas_data_for_scene([],str_replace('sf-filter-release_id--target','sf-filter-private_field--target',$html));
check($invalid['published_release_tracks']['rows']===[],
    'an undeclared filter cannot select dataset values');
$shared=sf_agent_canvas_data_for_scene([],$html.'<section class="sf-dataset-published_release_tracks"><div class="sf-data-row"></div></section>');
check(count($shared['published_release_tracks']['rows'])===120&&array_slice($shared['published_release_tracks']['rows'],0,2)===$target,
    'an additional unfiltered view retains explicitly requested release rows within the shared limit');
$current=sf_agent_canvas_data_for_scene([],'<section class="sf-dataset-published_release_tracks sf-current-track"><div class="sf-data-row"></div></section>','target-first');
check(array_column($current['published_release_tracks']['current_rows'],'track_id')===['target-first']&&
    $current['published_release_tracks']['current_rows'][0]['release_id']==='target',
    'current-track data remains available outside the global catalog page');
$track=sf_agent_canvas_data_for_scene([],'<section class="sf-dataset-published_catalog sf-filter-track_id--target-first"><div class="sf-data-row"></div></section>');
check(array_column($track['published_catalog']['rows'],'track_id')===['target-first']&&
    $track['published_catalog']['actions']===$tools['published_catalog']['actions'],
    'the existing published catalog also scopes an exact late track before its delivery limit');
$sources=sf_agent_canvas_release_sources('Make a custom player for Requested release','legacy-title');
check(count($sources)===6&&$sources[0]===['release_id'=>'target','title'=>'Requested release','track_count'=>2]&&
    $sources[1]===['release_id'=>'legacy','title'=>'Legacy name','track_count'=>2],
    'source metadata ranks exact requested releases then canonical playing-track relationships');
check(array_keys($sources[0])===['release_id','title','track_count']&&
    !in_array('hidden',array_column($sources,'release_id'),true)&&!in_array('draft',array_column($sources,'release_id'),true)&&
    !str_contains(json_encode($sources,JSON_THROW_ON_ERROR),'target-first'),
    'model source candidates reveal bounded public release metadata without sample songs');
$releases[1]['public_visible']=false;
file_put_contents($fixture.'/data/releases.json',json_encode($releases,JSON_THROW_ON_ERROR));
$revoked=sf_agent_canvas_data_for_scene([],$html);
check($revoked['published_release_tracks']['rows']===[]&&!in_array('target',array_column(sf_agent_canvas_release_sources('Requested release'),'release_id'),true),
    'reopening rechecks release visibility instead of retaining old published membership');
echo 'Canvas release data: '.$checks.' checks passed'.PHP_EOL;
