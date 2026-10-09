<?php
declare(strict_types=1); require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){sf_admin_require_auth(false);sf_json_response(['ok'=>true,'catalog'=>sf_catalog()]);}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
sf_admin_require_auth(true);$body=sf_request_json();$id=(string)($body['id']??'');$catalog=sf_catalog();$idx=null;foreach($catalog as $i=>$t)if((string)($t['id']??'')===$id){$idx=$i;break;}if($idx===null)sf_json_response(['ok'=>false,'message'=>'Track not found.'],404);
$track=$catalog[$idx];$patch=is_array($body['track']??null)?$body['track']:[];
foreach(['title','release','lyrics','story','artwork'] as $k)if(array_key_exists($k,$patch))$track[$k]=sf_clean_text($patch[$k],$k==='lyrics'||$k==='story'?10000:240);
if(array_key_exists('duration',$patch))$track['duration']=max(0,(int)$patch['duration']);
if(array_key_exists('year',$patch))$track['year']=max(0,(int)$patch['year']);
if(array_key_exists('price',$patch))$track['price']=max(0,(float)$patch['price']);
if(array_key_exists('podEligible',$patch))$track['podEligible']=sf_admin_bool($patch['podEligible']);
if(array_key_exists('mood',$patch))$track['mood']=sf_admin_array_strings($patch['mood']);
if(array_key_exists('themes',$patch))$track['themes']=sf_admin_array_strings($patch['themes']);
if(array_key_exists('energy',$patch))$track['energy']=max(1,min(5,(int)$patch['energy']));
if(array_key_exists('metadata',$patch)&&is_array($patch['metadata'])){
  $m=is_array($track['metadata']??null)?$track['metadata']:[];$incoming=$patch['metadata'];
  foreach(['words_by','music_by','performing_artist','pro_affiliation','ipi_cae','publisher','publisher_pro','publisher_ipi','producer','co_producer','executive_producer','recording_engineer','mixing_engineer','mastering_engineer','studio','recording_location','release_year','release_date','label','catalog_number','genre','subgenre','language','composition_copyright','master_copyright','rights_notes'] as $k)if(array_key_exists($k,$incoming))$m[$k]=sf_clean_text($incoming[$k],$k==='rights_notes'?1000:180);
  if(array_key_exists('isrc',$incoming)){$isrc=sf_admin_normalize_isrc($incoming['isrc']);if(!sf_admin_isrc_valid($isrc))sf_json_response(['ok'=>false,'message'=>'ISRC must be 12 characters, for example USABC2600001.'],422);foreach($catalog as $other)if(($other['id']??'')!==$id&&(($other['metadata']['isrc']??'')===$isrc)&&$isrc!=='')sf_json_response(['ok'=>false,'message'=>'That ISRC is already assigned to another track.'],409);$m['isrc']=$isrc;}
  $track['metadata']=$m;$track['credits']=sf_admin_public_credits($m);
}
if(array_key_exists('archive',$patch)&&is_array($patch['archive'])){
  $a=is_array($track['archive']??null)?$track['archive']:[];$incoming=$patch['archive'];
  foreach(['era','canonical_work_id','version_type','version_label','version_of','recorded_date','session','source_notes'] as $k)if(array_key_exists($k,$incoming))$a[$k]=sf_clean_text($incoming[$k],$k==='source_notes'?4000:180);
  if(array_key_exists('alternate_track_ids',$incoming)){
    $ids=[];foreach((array)$incoming['alternate_track_ids'] as $otherId){$otherId=sf_clean_text($otherId,100);if($otherId===''||$otherId===$id||in_array($otherId,$ids,true))continue;$exists=false;foreach($catalog as $candidate)if((string)($candidate['id']??'')===$otherId){$exists=true;break;}if(!$exists)sf_json_response(['ok'=>false,'message'=>'Unknown alternate track: '.$otherId],422);$ids[]=$otherId;}$a['alternate_track_ids']=$ids;
  }
  if(!empty($a['version_of'])){$exists=false;foreach($catalog as $candidate)if((string)($candidate['id']??'')===(string)$a['version_of']){$exists=true;break;}if(!$exists||$a['version_of']===$id)sf_json_response(['ok'=>false,'message'=>'Version of must reference another catalog track.'],422);}
  if(array_key_exists('personnel',$incoming)){$rows=[];foreach((array)$incoming['personnel'] as $p){if(!is_array($p))continue;$name=sf_clean_text($p['name']??'',140);if($name==='')continue;$rows[]=['name'=>$name,'instrument'=>sf_clean_text($p['instrument']??'',100),'role'=>sf_clean_text($p['role']??'',100)];if(count($rows)>=80)break;}$a['personnel']=$rows;}
  if(array_key_exists('media',$incoming)){$rows=[];foreach((array)$incoming['media'] as $mrow){if(!is_array($mrow))continue;$url=sf_clean_text($mrow['url']??'',500);if($url==='')continue;$rows[]=['type'=>sf_clean_text($mrow['type']??'link',40),'title'=>sf_clean_text($mrow['title']??'Archive item',180),'url'=>$url,'caption'=>sf_clean_text($mrow['caption']??'',500),'date'=>sf_clean_text($mrow['date']??'',20)];if(count($rows)>=80)break;}$a['media']=$rows;}
  $track['archive']=$a;
}
$catalog[$idx]=$track;sf_admin_write_catalog($catalog);sf_json_response(['ok'=>true,'track'=>$track]);
