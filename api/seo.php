<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$view=sf_clean_text($_GET['view']??'',40);$id=sf_clean_text($_GET['id']??'',160);$slug=sf_clean_text($_GET['slug']??'',160);
$m=sf_seo_resolve($view,$id,$slug);
sf_json_response(['ok'=>true,'meta'=>['title'=>$m['title'],'description'=>$m['description'],'canonical'=>$m['canonical'],'image'=>$m['image'],'type'=>$m['type'],'robots'=>$m['robots'],'json_ld'=>$m['json_ld']]]);
