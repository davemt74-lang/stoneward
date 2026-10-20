<?php
declare(strict_types=1);
/**
 * Artist-owner standing instruction: Stonefellow ORIGINAL catalog music can
 * satisfy internal production sync/master-use review automatically.
 * A license gate decision is operational, not a legal opinion or proof of title.
 * Third-party restrictions and human restrictions always win.
 */
function sf_stonefellow_music_auto_status(string $trackId):array{
 if(!function_exists('sf_catalog')||$trackId==='')return ['eligible'=>false,'reason'=>'not_catalog_music'];
 if(function_exists('sf_ai_meta_get')&&
    sf_ai_meta_get('artist_world.stonefellow_original_music_auto_approval','1')==='0')
  return ['eligible'=>false,'reason'=>'standing_authorization_disabled'];
 $track=null;
 foreach(sf_catalog() as $row)
  if(is_array($row)&&(string)($row['id']??'')===$trackId){$track=$row;break;}
 if(!$track||empty($track['title'])||(float)($track['duration']??0)<=0)
  return ['eligible'=>false,'reason'=>'not_a_valid_catalog_master'];
 $meta=(array)($track['metadata']??[]);
 foreach(['is_cover','is_remix','is_sample','third_party_rights','third_party_master',
   'third_party_composition','sample_clearance_required','external_license_required',
   'rights_restricted','music_auto_approval_disabled'] as $key)
   if(!empty($meta[$key])&&!in_array(strtolower((string)$meta[$key]),['false','no','0'],true))
    return ['eligible'=>false,'reason'=>'explicit_rights_exception'];
 foreach(['copyright_text','license_status','rights_notes','master_copyright',
   'composition_copyright'] as $key){
  $value=strtolower((string)($meta[$key]??''));
  if(preg_match('/\b(?:unverified|restricted|uncleared|third.party|sample|cover|remix|license pending|not owned)\b/',$value))
   return ['eligible'=>false,'reason'=>'explicit_rights_exception'];
 }
 // If registered licensing records explicitly restrict use, the owner's standing
 // authorization must never overrule that more specific rights record.
 if(function_exists('sf_rights_work')){
  try{
   $work=sf_rights_work($trackId);
   if($work){
    foreach((array)($work['splits']??[]) as $split)
     if(in_array((string)($split['split_type']??''),['composition','master'],true)
        &&empty($split['controlled']))
      return ['eligible'=>false,'reason'=>'uncontrolled_rights_split'];
    foreach((array)($work['licenses']??[]) as $license)
     if(in_array((string)($license['license_type']??''),
          ['sync','master_use','sample','cover','remix'],true)
        &&in_array((string)($license['status']??''),
          ['pending','restricted','expired'],true))
      return ['eligible'=>false,'reason'=>'existing_license_restriction'];
   }
  }catch(Throwable $e){return ['eligible'=>false,'reason'=>'rights_record_unavailable'];}
 }
 return ['eligible'=>true,'reason'=>'stonefellow_original_standing_authorization',
   'scope'=>'artist_world_sync_and_master_use','automatic'=>true];
}
