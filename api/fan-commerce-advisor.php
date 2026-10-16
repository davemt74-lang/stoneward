<?php
declare(strict_types=1);
require_once __DIR__.'/fan-purchase-journey-core.php';

/** Exact public song and current owner's paid account data only. Never accept
 * a customer_id, order ID, licence balance or supplied price from client. */
function sf_fan_commerce_decision(?int $userId,string $songId,bool $vinyl=false):array {
 $songId=trim($songId);
 $track=null;
 foreach(sf_fan_discovery_public(sf_catalog()) as $row)
  if((string)($row['id']??'')===$songId){$track=$row;break;}
 if(!$track)throw new InvalidArgumentException('That public song is unavailable.');
 $signedIn=$userId!==null&&$userId>0;
 $owned=$signedIn&&isset(sf_fan_purchase_owned_ids($userId)[$songId]);
 $saved=$signedIn&&isset(sf_fan_purchase_saved_ids($userId)[$songId]);
 $vinylReady=!empty($track['podEligible'])&&
  (($track['audio_publishing_mode']??'')!=='preview_master_split'
    ||!empty($track['private_master_audio']));
 $next=$vinyl?($vinylReady?'open_builder':'open_track')
    :($owned?'open_library':'open_track');
 $reason=$vinyl?
  ($vinylReady?'Custom vinyl can be reviewed in Builder; the actual license balance and pressing charges are checked there.':'This song is not currently eligible for custom vinyl.')
  :($owned?'This song is already part of your verified purchased library.':
    ($signedIn?'This song is not in your confirmed paid library.':'Sign in if you want to verify whether you own a download.'));
 return [
  'track_id'=>$songId,'title'=>(string)($track['title']??$songId),
  'ownership'=>$signedIn?($owned?'verified_paid':'not_found_in_paid_library'):'not_checked_guest',
  'saved'=>$saved,'vinyl_eligible'=>$vinylReady,'intent'=>$vinyl?'vinyl':'digital',
  'next_view'=>$next,'next_track_id'=>$songId,'reason'=>$reason,
  'quote_required'=>!$owned&&!$vinyl,'license_balance_verified'=>false,
  'checkout_executed'=>false,'marketing_enrolled'=>false,
  'restrictions'=>'Price and entitlement changes require existing cart/checkout; manufacturing requires existing builder licensing checks.'
 ];
}
/** One allowlisted fact sheet; no customer order records or license identifiers. */
function sf_fan_commerce_agent_brief(array $decision):array {
 return [
   'title'=>substr((string)$decision['title'],0,160),
   'ownership'=>(string)$decision['ownership'],
   'saved'=>!empty($decision['saved']),
   'vinyl_eligible'=>!empty($decision['vinyl_eligible']),
   'next_view'=>(string)$decision['next_view'],
   'reason'=>(string)$decision['reason'],
   'quote_required'=>!empty($decision['quote_required']),
   'license_balance_verified'=>false,
   'changes_executed'=>false,
   'checkout_and_vinyl_controls'=>'The fan must use the existing cart, quote, checkout and builder; no automatic payments or print-license grants.'
 ];
}
