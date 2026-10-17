<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/band-member-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
header('Cache-Control: public, max-age=60');
$id=max(0,(int)($_GET['id']??0));
if($id>0){$profile=sf_band_profile_public($id);
  if(!$profile)sf_json_response(['ok'=>false,'error'=>'not_found'],404);
  sf_json_response(['ok'=>true,'member'=>$profile]);}
sf_band_ensure_schema();
$rows=sf_db()->query("SELECT user_id FROM band_member_profiles WHERE public_at IS NOT NULL ORDER BY user_id LIMIT 150")->fetchAll(PDO::FETCH_COLUMN);
$members=[];foreach($rows as $row){$p=sf_band_profile_public((int)$row);if($p)$members[]=$p;}
sf_json_response(['ok'=>true,'members'=>$members]);
