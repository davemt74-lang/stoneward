<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$paths=['version.php','stonefellow-v120.php','admin/index.php','service-worker.js'];
foreach($paths as $path){
  $s=(string)file_get_contents($root.'/'.$path);
  if(!str_contains($s,'1.3.41')){fwrite(STDERR,"FAIL version sync: $path\n");exit(1);}
  echo "PASS version sync: $path\n";
}
