<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_admin_require_auth(false);$releaseId=sf_clean_text($_GET['release_id']??'',160);if($releaseId===''){http_response_code(400);exit('Release ID required.');}
$r=sf_distribution_record($releaseId,false);$rel=(string)($r['package_path']??'');if($rel===''||!str_starts_with($rel,'storage/distribution/'.$releaseId.'/')){http_response_code(404);exit('No package is available.');}
$root=realpath(SF_ROOT.'/storage/distribution');$abs=realpath(SF_ROOT.'/'.$rel);if(!$root||!$abs||!str_starts_with($abs,$root.DIRECTORY_SEPARATOR)||!is_file($abs)){http_response_code(404);exit('Package file not found.');}
header('Content-Type: application/zip');header('Content-Length: '.filesize($abs));header('Content-Disposition: attachment; filename="'.str_replace('"','',basename($abs)).'"');header('Cache-Control: private, no-store');readfile($abs);exit;
