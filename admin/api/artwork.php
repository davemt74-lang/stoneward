<?php
declare(strict_types=1); require __DIR__.'/bootstrap.php'; sf_admin_require_auth(true);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
if(empty($_FILES['file'])||!is_array($_FILES['file']))sf_json_response(['ok'=>false,'message'=>'Artwork file is required.'],422);
$f=$_FILES['file'];if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)sf_json_response(['ok'=>false,'message'=>'Artwork upload failed.'],422);
if((int)$f['size']>25*1024*1024)sf_json_response(['ok'=>false,'message'=>'Artwork must be 25 MB or smaller.'],422);
$ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION));if(!in_array($ext,['jpg','jpeg','png','webp'],true))sf_json_response(['ok'=>false,'message'=>'Artwork must be JPG, PNG or WebP.'],422);
$mime=function_exists('mime_content_type')?(string)@mime_content_type($f['tmp_name']):'';if($mime!==''&&!in_array($mime,['image/jpeg','image/png','image/webp'],true))sf_json_response(['ok'=>false,'message'=>'Unsupported artwork file type.'],422);
$sha=hash_file('sha256',$f['tmp_name']);$safe=sf_admin_slug(pathinfo((string)$f['name'],PATHINFO_FILENAME),'artwork');$name=substr($sha,0,12).'-'.$safe.'.'.($ext==='jpeg'?'jpg':$ext);
if(!is_dir(SF_ADMIN_ARTWORK_DIR))@mkdir(SF_ADMIN_ARTWORK_DIR,0770,true);if(!is_dir(SF_ADMIN_ARTWORK_PUBLIC))@mkdir(SF_ADMIN_ARTWORK_PUBLIC,0775,true);
$private=SF_ADMIN_ARTWORK_DIR.'/'.$name;$public=SF_ADMIN_ARTWORK_PUBLIC.'/'.$name;if(!is_file($private)&&!move_uploaded_file($f['tmp_name'],$private))sf_json_response(['ok'=>false,'message'=>'Could not store artwork.'],500);if(!is_file($public)&&!copy($private,$public))sf_json_response(['ok'=>false,'message'=>'Could not publish artwork preview.'],500);
sf_json_response(['ok'=>true,'path'=>'assets/images/catalog/'.$name,'sha256'=>$sha,'name'=>$f['name']]);
