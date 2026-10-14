<?php
declare(strict_types=1);
/** Section 84B5: original-catalog-only private media grants and leased transfer. */
$tmp=sys_get_temp_dir().'/sf84b5-'.bin2hex(random_bytes(6));
if(!mkdir($tmp.'/storage/media/masters',0700,true))throw new RuntimeException('Test setup unavailable.');
define('SF_ROOT',$tmp);
require dirname(__DIR__).'/api/store-order-visibility.php';
require dirname(__DIR__).'/api/kunaki-media-transfer.php';
$passed=0;$failed=0;
function pass85(bool $ok,string $label):void {global $passed,$failed;$ok?$passed++:$failed++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
function blocked85(callable $cb):bool {try{$cb();return false;}catch(Throwable $e){return true;}}
$audioFile=$tmp.'/storage/media/masters/audio.wav';
$frontFile=$tmp.'/storage/media/masters/front.jpg';
$backFile=$tmp.'/storage/media/masters/back.jpg';
file_put_contents($audioFile,'RIFF'.pack('V',14).'WAVEfmt '.str_repeat('x',2048));
file_put_contents($frontFile,"\xFF\xD8\xFF".str_repeat('f',1700));
file_put_contents($backFile,"\xFF\xD8\xFF".str_repeat('b',1700));
$assets=[];
foreach([
 'M11111111111111111111'=>['audio',$audioFile,'wav',0],
 'M22222222222222222222'=>['image',$frontFile,'jpg',3000],
 'M33333333333333333333'=>['image',$backFile,'jpg',3000],
] as $id=>$row){
 [$kind,$file,$ext,$pixels]=$row;
 $assets[$id]=['uuid'=>$id,'category'=>$kind,'extension'=>$ext,
   'stored_path'=>'storage/media/masters/'.basename($file),
   'sha256'=>hash_file('sha256',$file),'width'=>$pixels,'height'=>$pixels];
}
$lookup=static fn(string $id):?array=>$assets[$id]??null;
$builder=[
 'title'=>'First Record','coverFront'=>'api/media.php?id=M22222222222222222222&variant=large',
 'coverBack'=>'api/media.php?id=M33333333333333333333&variant=large',
 'sides'=>['A'=>['tracks'=>[['id'=>'a1','title'=>'Song One','duration'=>175]]],
   'B'=>['tracks'=>[]]]
];
$job=[
 'schema'=>'stonefellow.kunaki.publish-job.v1',
 'job_id'=>'KJP-1234567890ABCDEF12345678','order_id'=>'SF-PILOT85',
 'owner_id'=>7,'item_index'=>0,'proof_sha256'=>str_repeat('a',64),
 'license_track_ids'=>['a1'],'lease_expires'=>time()+600,
 'browser_upload_enabled'=>false,'kunaki_submission_enabled'=>false
];
$order=['id'=>'SF-PILOT85','user_id'=>7,'status'=>'paid',
 'payment'=>['provider'=>'stripe','status'=>'paid','livemode'=>true,
    'payment_intent'=>'pi_verified_fixture','postpayment_done'=>['pod'=>true,'library'=>true]],
 'quote'=>['items'=>[['type'=>'custom_media','format'=>'vinyl','builder'=>$builder]]]];
$catalog=['a1'=>['podEligible'=>true,'audio'=>'api/media.php?id=M11111111111111111111']];
$secret=str_repeat('k',48);$worker='HS-kunaki-primary';$lease=str_repeat('f',48);
$sample=sf_kunaki_media_job_assets($job,$order,$catalog,$lookup);
pass85(count($sample)===3&&array_column($sample,'asset_key')===['audio-A-1','artwork-front','artwork-back'],
 'Manufacturing manifest contains only one purchased original audio plus front/back artwork');
pass85($sample[0]['local_path']===$audioFile,'Original catalog media used instead of a storefront preview');
pass85($sample[1]['extension']==='jpg'&&$sample[2]['extension']==='jpg','Artwork uses original JPEG assets');
$m=sf_kunaki_media_manifest($job,$order,$catalog,$worker,$lease,$secret,$lookup);
pass85($m['schema']==='stonefellow.kunaki.media-manifest.v1'
 &&count($m['assets'])===3&&$m['expires_at']<=time()+300,
 'Manifest binds three asset grants to a five-minute expiry');
pass85(!str_contains(json_encode($m,JSON_THROW_ON_ERROR),'storage/media/')
 &&!str_contains(json_encode($m),'pi_verified_fixture'),
 'Cloud does not expose private filesystem paths or Stripe payment references');
pass85($m['browser_upload_enabled']===false&&$m['kunaki_submission_enabled']===false,
 'Staging grants never authorize browser execution or real Kunaki orders');
$grant=$m['assets'][0]['grant'];
$c=sf_kunaki_media_claims($secret,$grant,time());
pass85($c['job_id']===$job['job_id']&&$c['worker_id']===$worker
  &&$c['asset_key']==='audio-A-1','Opaque asset grant contains job, worker and file scope');
$asset=sf_kunaki_media_granted_file($job,$order,$catalog,$worker,$lease,$grant,$secret,$lookup);
pass85($asset['local_path']===$audioFile&&$asset['sha256']===hash_file('sha256',$audioFile),
 'Signed asset grant returns only exact original media file');
pass85(blocked85(fn()=>sf_kunaki_media_granted_file($job,$order,$catalog,'HS-attacker-001',
 $lease,$grant,$secret,$lookup)),'Other HomeServer worker cannot use the grant');
pass85(blocked85(fn()=>sf_kunaki_media_granted_file($job,$order,$catalog,$worker,
 'wrong-lease',$grant,$secret,$lookup)),'Grant does not survive worker lease replacement');
$badToken=substr($grant,0,-1).($grant[-1]==='a'?'b':'a');
pass85(blocked85(fn()=>sf_kunaki_media_claims($secret,$badToken,time())),
 'Tampered grant signature rejected');
pass85(blocked85(fn()=>sf_kunaki_media_claims($secret,$grant,time()+301)),
 'Expired grant rejected');
$badOrder=$order;$badOrder['user_id']=8;
pass85(blocked85(fn()=>sf_kunaki_media_job_assets($job,$badOrder,$catalog,$lookup)),
 'Other account cannot stage purchased audio');
$badOrder=$order;$badOrder['payment']['livemode']=false;
pass85(blocked85(fn()=>sf_kunaki_media_job_assets($job,$badOrder,$catalog,$lookup)),
 'Stripe TEST order cannot generate production media transfer');
$wrongTracks=$job;$wrongTracks['license_track_ids']=['a2'];
pass85(blocked85(fn()=>sf_kunaki_media_job_assets($wrongTracks,$order,$catalog,$lookup)),
 'Changed song license sequence blocks all media grants');
$wrongMaster=$catalog;$wrongMaster['a1']['audio']='https://attacker.example/evil.wav';
pass85(blocked85(fn()=>sf_kunaki_media_job_assets($job,$order,$wrongMaster,$lookup)),
 'External/SSRF media URL never accepted');
$wrongMaster=$catalog;$wrongMaster['a1']['audio']='../../config/secrets.php';
pass85(blocked85(fn()=>sf_kunaki_media_job_assets($job,$order,$wrongMaster,$lookup)),
 'Local path traversal never accepted');
$badArt=$order;$badArt['quote']['items'][0]['builder']['coverFront']='desert';
pass85(blocked85(fn()=>sf_kunaki_media_job_assets($job,$badArt,$catalog,$lookup)),
 'Template placeholder is NOT misrepresented as uploaded JPEG artwork');
$tooSmall=$assets;$tooSmall['M22222222222222222222']['width']=1200;
$smallLookup=static fn(string $id):?array=>$tooSmall[$id]??null;
pass85(blocked85(fn()=>sf_kunaki_media_job_assets($job,$order,$catalog,$smallLookup)),
 'Low-resolution original artwork requires manual remediation');
file_put_contents($audioFile,'tampered-master');
pass85(blocked85(fn()=>sf_kunaki_media_granted_file($job,$order,$catalog,$worker,
 $lease,$grant,$secret,$lookup)),'Asset tampering after grant invalidates download');
file_put_contents($audioFile,'RIFF'.pack('V',14).'WAVEfmt '.str_repeat('x',2048));
$endpoints=file_get_contents(dirname(__DIR__).'/api/kunaki-worker.php');
pass85(str_contains($endpoints,"case 'manifest':")&&str_contains($endpoints,"case 'download':")
 &&str_contains($endpoints,'sf_kunaki_media_lease_job'),
 'Signed worker endpoint provides only leased manifest and asset download');
pass85(!str_contains($endpoints,'curl_exec(')
 &&!str_contains($endpoints,'kunaki.com'),
 'Cloud transfers never submit anything to Kunaki');
foreach([$audioFile,$frontFile,$backFile] as $file)unlink($file);
rmdir($tmp.'/storage/media/masters');rmdir($tmp.'/storage/media');rmdir($tmp.'/storage');rmdir($tmp);
echo 'Section 84B5 secure media transfer: '.$passed.'/'.($passed+$failed).' passed'.PHP_EOL;
exit($failed?1:0);
