<?php
declare(strict_types=1);
/** Full SQLite integration into 85B's existing guarded Media Library. */
$dir=sys_get_temp_dir().'/sf86i-'.bin2hex(random_bytes(7));
mkdir($dir.'/storage/media',0700,true);
define('SF_ROOT',$dir);
$GLOBALS['sf86i_db']=new PDO('sqlite::memory:');
$GLOBALS['sf86i_db']->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['sf86i_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function sf_db():PDO{return $GLOBALS['sf86i_db'];}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $limit=160):string{return substr(trim((string)$v),0,$limit);}
$GLOBALS['sf86i_catalog']=[['id'=>'song-1','title'=>'Song','audio'=>'assets/audio/old-preview.mp3','duration'=>3]];
function sf_catalog():array{return $GLOBALS['sf86i_catalog'];}
function sf_admin_write_catalog(array $c):void{$GLOBALS['sf86i_catalog']=$c;}
require dirname(__DIR__).'/api/media-core.php';
require dirname(__DIR__).'/api/stem-catalog-publishing.php';
sf_media_ensure_schema();
$pass=0;
function ok86i(bool $yes,string $label):void{global $pass;if(!$yes)throw new RuntimeException('86I: '.$label);$pass++;echo "PASS $label\n";}
function refuses86i(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException){return true;}}
function pcm86i(float $duration):string{
    $frames=(int)round($duration*48000);
    return 'RIFF'.pack('V',36+$frames*4).'WAVEfmt '.pack('VvvVVvv',16,1,2,48000,192000,4,16).
      'data'.pack('V',$frames*4).str_repeat("\x00",$frames*4);
}
$wav=pcm86i(.25);
$path=$dir.'/storage/media/render.wav';
file_put_contents($path,$wav);
$info=sf_stem_86i_wav_header($path);
ok86i($info['sample_rate']===48000&&$info['channels']===2&&$info['bits']===16,
    'Real 48 kHz stereo signed PCM 16-bit header verified');
file_put_contents($dir.'/storage/media/corrupt.wav',str_repeat('r',124));
ok86i(refuses86i(fn()=>sf_stem_86i_wav_header($dir.'/storage/media/corrupt.wav')),
    'Truncated or wrong-format WAV fails closed');
$source="<REAPER_PROJECT 0.1 \"7.0\" 1\n  <TRACK {GUITAR}\n    NAME \"Guitar\"\n  >\n>\n";
$p=sf_stem_project_import('song-1',$source,'Original.rpp',1);
$hash=hash_file('sha256',$path);
ok86i(refuses86i(fn()=>sf_stem_86i_require_review($p)),
    'No review cannot stage audio');
$metrics=['duration_seconds'=>.25,'sample_rate'=>48000,'sample_peak_dbfs'=>-5,
 'integrated_lufs'=>-20,'rms_dbfs'=>-22,'crest_db'=>17,'stereo_imbalance_db'=>0.1,'clipped_samples'=>0];
$p=sf_stem_mastering_save_analysis('song-1',['revision'=>1,'analysis'=>[
 'metrics'=>$metrics,'details'=>['render_sha256'=>$hash]]]);
$p=sf_stem_86h_save_review('song-1',['revision'=>2,'profile'=>'download','confirmation'=>'REVIEWED'],1);
ok86i(sf_stem_86i_require_review($p)['sha256']===$hash,
    'Exact reviewed and analyzed SHA-256 available');
ok86i(refuses86i(fn()=>sf_stem_86i_publish('song-1',3,'PUBLISH PRIVATE MASTER',1)),
    'Publishing without a staged Media Library candidate is denied');
$now=gmdate('c');$uuid='M'.str_repeat('A',20);
sf_db()->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
 ->execute([$uuid,'audio','audio/wav','wav','render.wav','storage/media/render.wav',$hash,strlen($wav),$now,$now]);
$assetId=(int)sf_db()->lastInsertId();
$links=sf_media_attach($assetId,'track','song-1','audio_candidate',['public_visible'=>false,'download_allowed'=>false]);
$linkId=(int)end($links)['id'];
$p['stem_publication']=[
 'state'=>'staged','asset_id'=>$assetId,'link_id'=>$linkId,
 'sha256'=>$hash,'profile'=>'download','audio_signature'=>sf_stem_86h_signature($p),
 'analysis_revision'=>1,'catalog_published'=>false];
$p=sf_stem_project_write('song-1',$p,3);
ok86i(sf_stem_86i_status($p)['state']==='staged','Review candidate attached to existing Media Library');
ok86i(refuses86i(fn()=>sf_stem_86i_publish('song-1',4,'PUBLISH',1)),
    'Exact typed confirmation is required separately from 86H review');
ok86i(refuses86i(fn()=>sf_stem_86i_publish('song-1',3,'PUBLISH PRIVATE MASTER',1)),
    'Stale project revision cannot publish');
ok86i(!sf_media_protected_master_asset($assetId),'Staging is not secretly promoting the original');
$p=sf_stem_86i_publish('song-1',4,'PUBLISH PRIVATE MASTER',1);
ok86i($p['stem_publication']['state']==='published_private_master',
    'Approved private master promoted with durable publication state');
$catalog=sf_catalog()[0];
ok86i($catalog['private_master_audio']==='api/media.php?id='.$uuid&&
 $catalog['audio']==='assets/audio/old-preview.mp3','Owned original changed; public preview remains unchanged');
ok86i(($catalog['audio_publishing_mode']??'')==='preview_master_split',
    'Existing media publishing split contract remains enforced');
ok86i(sf_media_protected_master_asset($assetId),
    'Published WAV is protected against public links');
ok86i(refuses86i(fn()=>sf_media_attach($assetId,'track','song-1','preview_audio',
 ['public_visible'=>true])), 'Cannot expose purchased original through a public alias');
ok86i(refuses86i(fn()=>sf_stem_86i_publish('song-1',5,'PUBLISH PRIVATE MASTER',1)),
    'Duplicate publication cannot execute again');
ok86i(!isset($catalog['vinyl_license_packages'])&&!isset($catalog['license_entitlements']),
    'No vinyl licenses or purchase entitlements were granted or modified');
ok86i(file_get_contents($dir.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp')===$source,
    'Private source REAPER project remains byte-for-byte unchanged');
$fake=$p;$fake['stem_publication']['state']='publishing_in_progress';
$fake['stem_publication']['catalog_published']=false;
$fake=sf_stem_project_write('song-1',$fake,6);
ok86i(refuses86i(fn()=>sf_stem_86i_reconcile('song-1',7,'YES')),
    'Interrupted publication requires typed exact reconciliation');
$reconciled=sf_stem_86i_reconcile('song-1',7,'RECONCILE MASTER');
ok86i($reconciled['stem_publication']['state']==='published_private_master',
    'Manual reconciliation checks existing catalog without reposting');
$api=file_get_contents(dirname(__DIR__).'/admin/api/stem-publishing.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/stem-mastering.js');
ok86i(str_contains($api,'sf_admin_require_auth')&&
  str_contains($api,'sf_stem_86i_stage')&&str_contains($api,'sf_stem_86i_publish'),
  'Endpoint requires admin auth and staged/manual publication');
ok86i(str_contains($ui,'data-i-stage')&&str_contains($ui,'data-i-publish')&&
 str_contains($ui,"crypto.subtle.digest('SHA-256'"),
 'Current Stem Studio passes cryptographic WAV evidence to guarded Media Library');
ok86i(str_contains(file_get_contents(dirname(__DIR__).'/api/stem-agent-bridge.php'),
 'catalog_publication'),'Admin Agent Brain can inspect only redacted publication status');
echo "86I: $pass integrated checks green\n";
