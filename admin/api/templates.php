<?php
declare(strict_types=1); require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){sf_admin_require_auth(false);sf_json_response(['ok'=>true,'templates'=>sf_admin_templates()]);}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
sf_admin_require_auth(true); $body=sf_request_json(); $action=(string)($body['action']??'save');
if($action==='delete'){
  $id=sf_admin_slug((string)($body['id']??''),''); if($id==='')sf_json_response(['ok'=>false,'message'=>'Template id is required.'],422);
  $path=SF_ADMIN_TEMPLATE_DIR.'/'.$id.'.json'; if(is_file($path))unlink($path); sf_json_response(['ok'=>true]);
}
$raw=is_array($body['template']??null)?$body['template']:[]; $name=sf_clean_text($raw['name']??'',120); if($name==='')sf_json_response(['ok'=>false,'message'=>'Template name is required.'],422);
$id=sf_admin_slug((string)($raw['id']??$name),'template'); $existing=sf_admin_template($id); $version=(int)($existing['version']??0)+1;
if($existing){$hist=SF_ADMIN_TEMPLATE_DIR.'/history';if(!is_dir($hist))@mkdir($hist,0770,true);sf_write_json($hist.'/'.$id.'-v'.(int)$existing['version'].'.json',$existing);}
$template=['schema'=>'stonefellow.metadata-template.v1','id'=>$id,'name'=>$name,'description'=>sf_clean_text($raw['description']??'',500),'version'=>$version,'defaults'=>sf_admin_template_defaults(is_array($raw['defaults']??null)?$raw['defaults']:[]),'created_at'=>$existing['created_at']??gmdate('c'),'updated_at'=>gmdate('c')];
sf_write_json(SF_ADMIN_TEMPLATE_DIR.'/'.$id.'.json',$template); sf_json_response(['ok'=>true,'template'=>$template]);
