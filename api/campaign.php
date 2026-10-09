<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';require_once __DIR__.'/agent-runtime.php';sf_campaign_ensure_schema();sf_user_session();
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
    $slug=sf_clean_text($_GET['slug']??'',140);$campaign=sf_campaign_by_slug($slug,true);if(!$campaign)sf_json_response(['ok'=>false,'message'=>'Campaign not found or not currently available.'],404);
    sf_campaign_log_event((int)$campaign['id'],'campaign_viewed',null,null,'',['referrer'=>sf_clean_text($_SERVER['HTTP_REFERER']??'',500)]);
    sf_json_response(['ok'=>true,'campaign'=>sf_campaign_public_payload($campaign)]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'enter');$slug=sf_clean_text($b['slug']??'',140);$campaign=sf_campaign_by_slug($slug,true);if(!$campaign)sf_json_response(['ok'=>false,'message'=>'Campaign not found or not currently available.'],404);
try{
    if($action==='enter'){
        if(trim((string)($b['website']??''))!=='')sf_json_response(['ok'=>true,'accepted'=>true]);
        $now=time();$attempts=array_values(array_filter((array)($_SESSION['sf_campaign_attempts']??[]),fn($t)=>(int)$t>$now-600));if(count($attempts)>=12)sf_json_response(['ok'=>false,'message'=>'Too many campaign attempts. Try again later.'],429);$attempts[]=$now;$_SESSION['sf_campaign_attempts']=$attempts;
        $p=sf_campaign_enter($campaign,(string)($b['email']??''),sf_clean_text($b['name']??'',120),!empty($b['marketing_opt_in']),'landing');
        $_SESSION['sf_campaign_participants'][(string)$campaign['id']]=(int)$p['id'];
        sf_json_response(['ok'=>true,'participant_id'=>(int)$p['id'],'campaign'=>sf_campaign_public_payload($campaign,(int)$p['id'])]);
    }
    if($action==='claim'){
        $pid=(int)(($_SESSION['sf_campaign_participants'][(string)$campaign['id']]??0));if($pid<1)throw new RuntimeException('Enter the campaign before claiming an offer.');
        $q=sf_db()->prepare('SELECT * FROM campaign_participants WHERE id=? AND campaign_id=?');$q->execute([$pid,(int)$campaign['id']]);$p=$q->fetch();if(!$p)throw new RuntimeException('Campaign participant could not be verified.');
        $offer=sf_campaign_claim_offer($campaign,$p,(string)($b['node_id']??''));if(!empty($offer['download_token']))$offer['download_url']='api/campaign-download.php?token='.rawurlencode($offer['download_token']);
        sf_json_response(['ok'=>true,'offer'=>$offer]);
    }
    if($action==='event'){
        $allowed=['form_started','cta_clicked','link_clicked','offer_viewed'];$type=(string)($b['event_type']??'');if(!in_array($type,$allowed,true))throw new InvalidArgumentException('Unsupported campaign event.');
        $pid=(int)(($_SESSION['sf_campaign_participants'][(string)$campaign['id']]??0));$contactId=null;if($pid){$q=sf_db()->prepare('SELECT contact_id FROM campaign_participants WHERE id=? AND campaign_id=?');$q->execute([$pid,(int)$campaign['id']]);$contactId=(int)($q->fetchColumn()?:0);}
        sf_campaign_log_event((int)$campaign['id'],$type,$pid?:null,$contactId?:null,sf_clean_text($b['node_id']??'',120),[]);sf_json_response(['ok'=>true]);
    }
    sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
