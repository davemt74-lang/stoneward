<?php
declare(strict_types=1);
/** 86A single ZIP importer acceptance: synthetic REAPER file and stem bytes only. */
if(!class_exists('ZipArchive'))throw new RuntimeException('PHP zip extension required for 86A test.');
$dir=sys_get_temp_dir().'/sf86zip-'.bin2hex(random_bytes(6));
if(!mkdir($dir,0700,true))throw new RuntimeException('Could not create test directory');
define('SF_ROOT',$dir);
function sf_catalog():array{return [['id'=>'song-1','title'=>'Synthetic Example']];}
function sf_media_attach(int $assetId,string $entity,string $id,string $role,array $options=[]):array {
    $GLOBALS['attached86'][]=compact('assetId','entity','id','role','options');
    return ['id'=>$assetId];
}
require dirname(__DIR__).'/api/stem-zip-core.php';
$checks=0;
function check86z(bool $pass,string $label):void{
    global $checks;
    if(!$pass)throw new RuntimeException('86A ZIP: '.$label);
    $checks++;echo 'PASS '.$label.PHP_EOL;
}
function fail86z(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}}
$project='<REAPER_PROJECT 0.1 "7.09" 1'.PHP_EOL.'  TEMPO 122 4 4'.PHP_EOL;
foreach([['{11111111-1111-1111-1111-111111111111}','Drums','Drums consolidated.wav'],
         ['{22222222-2222-2222-2222-222222222222}','Bass','BASS-STEM.mp3']] as $row){
    [$guid,$name,$file]=$row;
    $project.="  <TRACK $guid\n    NAME \"$name\"\n    <ITEM\n      POSITION 0\n      LENGTH 12\n      <SOURCE WAV\n        FILE \"$file\"\n      >\n    >\n  >\n";
}
$project.="  >\n";
function fixture86z(string $file,array $entries):void{
    $zip=new ZipArchive();
    if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)
        throw new RuntimeException('Test fixture ZIP failed');
    foreach($entries as $key=>$value){
        $zip->addFromString((string)$key,(string)$value);
        $zip->setCompressionName((string)$key,ZipArchive::CM_DEFLATE);
    }
    $zip->close();
}
$zipFile=$dir.'/project.zip';
fixture86z($zipFile,['Session/Earthgirl.rpp'=>$project,
    'Stem Exports/Drums consolidated.wav'=>'RIFF'.str_repeat('drums',30),
    'Stem Exports/bass-stem.mp3'=>'ID3'.str_repeat('bass',30),
    'Exports/not-a-stem.mp3'=>'ID3'.str_repeat('other',30)]);
$ingested=[];
$ingest=static function(string $path,string $name,int $user)use(&$ingested):array {
    $raw=file_get_contents($path);
    if(!is_string($raw)||strlen($raw)<12)throw new RuntimeException('Empty staged stem');
    $ingested[]=['name'=>$name,'bytes'=>$raw,'user'=>$user];
    $i=count($ingested);
    return ['id'=>$i,'uuid'=>'M'.str_pad((string)$i,20,'0',STR_PAD_LEFT),'category'=>'audio'];
};
$r=sf_stem_zip_import('song-1',$zipFile,'Earthgirl-all-stems.zip',8,$ingest);
check86z($r['report']['stem_tracks']===2,'RPP creates the existing song two editor lanes');
check86z($r['report']['matched_stems']===2&&$r['report']['uploaded_media_assets']===2,
    'Single ZIP securely ingests and assigns both referenced stems');
check86z(count($ingested)===2&&count($GLOBALS['attached86']??[])===2,'Reuses only the private Media Library role');
check86z($GLOBALS['attached86'][0]['role']==='stem_audio'
    &&$GLOBALS['attached86'][0]['options']['public_visible']===false,
    'Stem associations are private and cannot become public previews');
check86z($r['report']['missing_files']===[],'Case-insensitive nested stem filenames matched');
check86z($r['report']['unmatched_audio_files']===['not-a-stem.mp3'],
    'Non-project audio is not silently imported');
check86z($r['project']['revision']===2&&is_file($dir.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp'),
    'Original project retained and imported stems saved at a coherent revision');
check86z($ingested[0]['bytes']==='RIFF'.str_repeat('drums',30)
    &&$ingested[1]['bytes']==='ID3'.str_repeat('bass',30),
    'ZIP audio transferred byte-for-byte without executing plugins');
fixture86z($zipFile,['project.rpp'=>$project,'bad/../escape.wav'=>'bad']);
check86z(fail86z(fn()=>sf_stem_zip_import('song-1',$zipFile,'unsafe.zip',8,$ingest)),
    'ZIP path traversal rejected');
fixture86z($zipFile,['project.rpp'=>$project,'a/STEM.wav'=>'a','b/stem.wav'=>'b']);
check86z(fail86z(fn()=>sf_stem_zip_import('song-1',$zipFile,'duplicate.zip',8,$ingest)),
    'Ambiguous duplicate basename rejected');
fixture86z($zipFile,['a.wav'=>'RIFF'.str_repeat('x',25)]);
check86z(fail86z(fn()=>sf_stem_zip_import('song-1',$zipFile,'missing-project.zip',8,$ingest)),
    'ZIP without an .rpp is rejected');
fixture86z($zipFile,['a.rpp'=>$project,'b.rpp'=>$project]);
check86z(fail86z(fn()=>sf_stem_zip_import('song-1',$zipFile,'two-projects.zip',8,$ingest)),
    'ZIP with multiple REAPER projects is rejected');
fixture86z($zipFile,['a.rpp'=>$project,'nested/file.wav'=>str_repeat('0',1024*1024+1)]);
check86z(fail86z(fn()=>sf_stem_zip_import('song-1',$zipFile,'zip-bomb.zip',8,$ingest)),
    'High-compression payload rejected before extraction');
check86z(fail86z(fn()=>sf_stem_zip_import('song-1',$zipFile,'wrong-ext.exe',8,$ingest)),
    'Non-ZIP extension rejected');
check86z(fail86z(fn()=>sf_stem_zip_import('../escape',$zipFile,'attack.zip',8,$ingest)),
    'Target song identifier is validated before import');
$api=file_get_contents(dirname(__DIR__).'/admin/api/stem-studio.php');
$js=file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js');
check86z(str_contains($api,'sf_admin_require_auth(')&&str_contains($api,'sf_stem_zip_import(')
   &&str_contains($js,'data-studio-bundle')&&str_contains($js,'import_report'),
    'Existing Admin session and stem editor are integrated with single-ZIP upload');
foreach(glob($dir.'/storage/stem-projects/.*.tmp')?:[] as $p)@unlink($p);
foreach(glob($dir.'/storage/stem-projects/*')?:[] as $p)if(is_file($p))@unlink($p);
@rmdir($dir.'/storage/stem-projects/.zip-stage');
@rmdir($dir.'/storage/stem-projects');
@rmdir($dir.'/storage');
@unlink($zipFile);@rmdir($dir);
echo "86A single ZIP upload: $checks / $checks checks passed".PHP_EOL;
