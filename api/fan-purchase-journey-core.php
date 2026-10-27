<?php
declare(strict_types=1);
require_once __DIR__.'/fan-discovery-paths.php';

/** Fan journey has no purchase or marketing write capabilities. Ownership is
 * derived only from the existing payment-verified private account library. */
function sf_fan_purchase_owned_ids(int $uid):array {
 if($uid<1||!function_exists('sf_library_purchase_rows'))return [];
 $owned=[];
 foreach(sf_library_purchase_rows($uid) as $row){
  $type=(string)($row['item_type']??'');
  $meta=(array)($row['metadata']??[]);
  if($type==='track'){
   $id=(string)($row['track_id']??$meta['track_id']??'');
   if($id!=='')$owned[$id]=true;
  }elseif($type==='release'){
   foreach((array)($meta['track_ids']??[]) as $id)
    if(is_string($id)&&$id!=='')$owned[$id]=true;
  }
 }
 return $owned;
}
/** Favorites are explicit saved-library choices, not inferred purchases. */
function sf_fan_purchase_saved_ids(int $uid):array {
 if($uid<1||!function_exists('sf_personalization_favorites'))return [];
 $saved=[];$favorites=sf_personalization_favorites($uid);
 foreach((array)($favorites['tracks']??[]) as $track)
  if(!empty($track['id']))$saved[(string)$track['id']]=true;
 return $saved;
}
/** This is a decision guide, not a quote, entitlement or payment promise.
 * Only sf_quote / checkout can calculate prices and grant rights. */
function sf_fan_purchase_paths(?int $uid,string $path='start_here',int $limit=8):array {
 $state=sf_fan_discovery_state($uid,$path,$limit);
 $catalog=sf_track_map();
 $owned=$uid!==null&&$uid>0?sf_fan_purchase_owned_ids($uid):[];
 $saved=$uid!==null&&$uid>0?sf_fan_purchase_saved_ids($uid):[];
 $eligible=[];
 foreach($state['items'] as $item){
  $id=(string)$item['track_id'];$track=$catalog[$id]??null;
  if(!$track)continue;
  $isOwned=isset($owned[$id]);
  $vinylEligible=!empty($track['podEligible'])&&
   ($track['audio_publishing_mode']??'')!=='preview_only'&&
   (($track['audio_publishing_mode']??'')!=='preview_master_split'
     ||!empty($track['private_master_audio']));
  $eligible[]=$item+[
   'owned'=>$isOwned,
   'saved'=>isset($saved[$id]),
   'save_available'=>$uid!==null&&$uid>0,
   'digital'=>[
     'action'=>$isOwned?'open_library':'add_to_cart',
     'label'=>$isOwned?'In your library':'Add digital song to cart',
     'requires_checkout'=>!$isOwned,
     'price_source'=>'cart_quote_only'
   ],
   'vinyl'=>[
     'eligible'=>$vinylEligible,
     'action'=>$vinylEligible?'open_builder':'unavailable',
     'label'=>$vinylEligible?'Build a custom vinyl':'Vinyl not available for this song',
     'license_balance'=>'not_checked',
     'requires_separate_eligibility_and_quote'=>true
   ]
  ];
 }
 $state['items']=$eligible;
 $state['next_steps']=['listen','save','cart_review','checkout','licensed_vinyl_builder'];
 $state['commercial_terms']='Final prices, vinyl license balances and availability are verified in the existing cart, builder and checkout.';
 return $state;
}
