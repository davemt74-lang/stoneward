<?php
declare(strict_types=1);
$privateRoot=sys_get_temp_dir().'/sf-render-cert-'.bin2hex(random_bytes(5));
mkdir($privateRoot.'/storage/media/library',0700,true);
define('SF_ROOT',$privateRoot);
require __DIR__.'/section88d3d-story-assembly.php';
require dirname(__DIR__).'/api/story-media-evidence-core.php';
require dirname(__DIR__).'/api/story-composer-core.php';
require dirname(__DIR__).'/api/storyboard-preview-core.php';
require dirname(__DIR__).'/api/story-render-source-core.php';
$checks=0;
function passG1(bool $yes,string $name):void {
 global $checks;if(!$yes)throw new RuntimeException('FAIL '.$name);
 $checks++;echo "PASS $name\n";
}
function blockedG1(callable $fn,string $name):void {
 try{$fn();throw new LogicException('Expected rejection');}
 catch(InvalidArgumentException|RuntimeException $e){passG1(true,$name);}
}
$db->exec("ALTER TABLE media_assets ADD COLUMN stored_path TEXT NOT NULL DEFAULT ''");
$db->exec("ALTER TABLE media_assets ADD COLUMN size_bytes INTEGER NOT NULL DEFAULT 0");
$db->exec("ALTER TABLE media_assets ADD COLUMN sha256 TEXT NOT NULL DEFAULT ''");
$db->exec("ALTER TABLE media_assets ADD COLUMN duration_seconds REAL NOT NULL DEFAULT 0");
$video=pack('N',24).'ftypisom'.pack('N',0).'isomiso2';
$wav='RIFF'.pack('V',38).'WAVEfmt '.pack('V',16).pack('v',1).pack('v',1).
 pack('V',16000).pack('V',32000).pack('v',2).pack('v',16).'data'.pack('V',2)."\x00\x00";
function sourceG1(int $id,string $bytes,string $category):array{
 global $privateRoot,$db;
 $rel='storage/media/library/cert-'.$id.($category==='video'?'.mp4':'.wav');
 $path=$privateRoot.'/'.$rel;file_put_contents($path,$bytes);
 $sha=hash('sha256',$bytes);$size=strlen($bytes);
 $q=$db->prepare('UPDATE media_assets SET stored_path=?,size_bytes=?,sha256=?,duration_seconds=? WHERE id=?');
 $q->execute([$rel,$size,$sha,120,$id]);
 return ['path'=>$path,'sha'=>$sha,'size'=>$size];
}
$a=sourceG1(4,$video,'video');$b=sourceG1(5,$wav,'audio');
$db->exec("INSERT INTO media_assets(id,category,stored_path,size_bytes,sha256,duration_seconds)
 VALUES(7,'audio','','', '',0)"); // update with actual WAV next
$c=sourceG1(7,$wav,'audio');
sf_evidence_record($sceneSub,4,['sha256'=>$a['sha'],'byte_size'=>$a['size'],
 'mime_type'=>'video/mp4','image_width'=>null,'image_height'=>null]);
sf_evidence_record($dialogueSub,5,['sha256'=>$b['sha'],'byte_size'=>$b['size'],
 'mime_type'=>'audio/wav','image_width'=>null,'image_height'=>null]);
sf_assembly_replace(1,$id,3,[$sceneItem,$transItem,$musicItem]);
$rev=4;
blockedG1(fn()=>sf_render_source_manifest(2,$id,$rev),'Contributor cannot certify private story');
blockedG1(fn()=>sf_render_source_manifest(1,$id,$rev),'Missing independently reviewed publication licenses block render source certificate');
sf_capture_rights_review(1,$sceneSub,'publication','cleared',
 'Independently verified signed video publishing appearance release.');
sf_capture_rights_review(1,$dialogueSub,'publication','cleared',
 'Independently verified signed recorded dialogue publishing permission.');
sf_storyboard_license_review(1,$id,'stone-song-1','sync','cleared',
 'Independently verified synchronization permission for this film and song.',4);
sf_storyboard_license_review(1,$id,'stone-song-1','master','cleared',
 'Independently verified master recording authorization for this film.',4);
blockedG1(fn()=>sf_render_source_manifest(1,$id,$rev),'Music cue needs independent private playback-master source');
blockedG1(fn()=>sf_render_master_bind(2,$id,4,'stone-song-1',7),'Contributor cannot bind a music master');
blockedG1(fn()=>sf_render_master_bind(1,$id,4,'unknown-track',7),'Invented canonical song cannot bind to playback master');
blockedG1(fn()=>sf_render_master_bind(1,$id,3,'stone-song-1',7),'Stale storyboard revision cannot bind music master');
$failAudit=true;
blockedG1(fn()=>sf_render_master_bind(1,$id,4,'stone-song-1',7),'Audit failure rolls back media binding');
$failAudit=false;
passG1((int)$db->query('SELECT COUNT(*) FROM story_render_track_masters')->fetchColumn()===0,'No orphan music approval after audit failure');
sf_render_master_bind(1,$id,4,'stone-song-1',7);
$m=sf_render_source_manifest(1,$id,$rev);
passG1($m['source_hashes_verified']&&count($m['items'])===3,'Three private verified inputs certified with SHA-256');
passG1($m['items'][0]['category']==='video'&&$m['items'][1]['category']==='audio'
 &&$m['items'][2]['category']==='audio','Video, dialogue and song master map to their actual Media Library assets');
passG1(!$m['public_output_allowed']&&!$m['render_started'],'Certification cannot start render or publish');
$admin=sf_render_source_admin_status(1,$id,$rev);
passG1($admin['eligible']&&!str_contains(json_encode($admin),$privateRoot),'Admin sees certification but never local media paths');
file_put_contents($a['path'],$video.'changed');
blockedG1(fn()=>sf_render_source_manifest(1,$id,$rev),'Changed footage bytes reject render even if Media Library reference remains');
file_put_contents($a['path'],$video);
$db->prepare('UPDATE media_assets SET sha256=? WHERE id=4')->execute([str_repeat('a',64)]);
blockedG1(fn()=>sf_render_source_manifest(1,$id,$rev),'Original immutable capture evidence cannot be overridden by changing Media Library hash');
$db->prepare('UPDATE media_assets SET sha256=? WHERE id=4')->execute([$a['sha']]);
sf_storyboard_license_review(1,$id,'stone-song-1','master','restricted',
 'Music master owner revoked permission pending a revised agreement.',4);
blockedG1(fn()=>sf_render_source_manifest(1,$id,$rev),'Music license revocation instantly disables certificate');
sf_storyboard_license_review(1,$id,'stone-song-1','master','cleared',
 'Revised master-use contract independently inspected and approved.',4);
sf_capture_rights_review(1,$sceneSub,'publication','restricted',
 'The performer revoked written publication permission for this footage.');
blockedG1(fn()=>sf_render_source_manifest(1,$id,$rev),'Contributor likeness permission revocation disables certificate');
sf_capture_rights_review(1,$sceneSub,'publication','cleared',
 'New signed video appearance release independently checked.');
$items=array_map(static fn($v)=>['kind'=>$v['kind'],'source_id'=>(string)$v['source_id'],
 'in_ms'=>(int)$v['in_ms'],'out_ms'=>(int)$v['out_ms'],
 'duration_ms'=>(int)$v['duration_ms'],'notes'=>$v['notes']],sf_assembly_read(1,$id)['items']);
sf_assembly_replace(1,$id,4,$items);
blockedG1(fn()=>sf_render_source_manifest(1,$id,5),'Revision changes expire both music use decisions and playback master binding');
foreach([$a,$b,$c] as $f)unlink($f['path']);
rmdir($privateRoot.'/storage/media/library');rmdir($privateRoot.'/storage/media');rmdir($privateRoot.'/storage');rmdir($privateRoot);
echo "88D3G1: $checks assertions passed\n";
