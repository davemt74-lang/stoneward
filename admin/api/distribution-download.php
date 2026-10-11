<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_admin_require_auth(false);$releaseId=sf_clean_text($_GET['release_id']??'',160);if($releaseId===''){http_response_code(400);exit('Release ID required.');}
$r=sf_distribution_record($releaseId,false);if(!$r||!empty($r['package_stale'])){http_response_code(409);exit('The distribution package is stale. Rebuild it before download.');}$storageKey=sf_distribution_storage_key($releaseId);$rel=(string)($r['package_path']??'');if($rel===''||!str_starts_with($rel,'storage/distribution/'.$storageKey.'/')){http_response_code(404);exit('No package is available.');}
$root=realpath(SF_ROOT.'/storage/distribution');$abs=realpath(SF_ROOT.'/'.$rel);if(!$root||!$abs||!str_starts_with($abs,$root.DIRECTORY_SEPARATOR)||!is_file($abs)){http_response_code(404);exit('Package file not found.');}$expected=(string)($r['package_sha256']??'');$actual=hash_file('sha256',$abs)?:'';if($expected===''||$actual===''||!hash_equals($expected,$actual)){http_response_code(409);exit('Package integrity verification failed. Rebuild the distribution package.');}
header('Content-Type: application/zip');header('Content-Length: '.filesize($abs));header('Content-Disposition: attachment; filename="'.str_replace('"','',basename($abs)).'"');header('Cache-Control: private, no-store');readfile($abs);exit;
