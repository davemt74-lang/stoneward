<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'] as $p)
 if(!str_contains($read($p),'1.3.141'))
  throw new RuntimeException('G4D release identity mismatch: '.$p);
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('version.php'),"'artist_world_private_qc'=>'hash-verified-revision-gated-video-review-cue-sheet-millisecond-seek'"))
 throw new RuntimeException('QC release feature identity or schema mismatch.');
if(!str_contains($read('api/story-render-review-core.php'),'function sf_render_review_cues(')
 ||!str_contains($read('api/story-render-review-core.php'),'sf_render_review_file($editor,$jobId)')
 ||!str_contains($read('api/story-render-review-core.php'),'sf_render_edl_build('))
 throw new RuntimeException('Reviewer QC must certify media rights/output bytes and canonical timeline.');
if(!str_contains($read('admin/api/story-render-review.php'),'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($read('admin/api/story-render-review.php'),"Cache-Control: private, no-store")
 ||!str_contains($read('admin/api/story-render-review.php'),"'cues'"))
 throw new RuntimeException('Reviewer QC endpoint must stay authenticated, no-store and private.');
if(!str_contains($read('admin/assets/story-assembly.js'),'data-load-review-cues')
 ||!str_contains($read('admin/assets/story-assembly.js'),'data-private-review-video')
 ||!str_contains($read('admin/assets/story-assembly.js'),'video.currentTime='))
 throw new RuntimeException('Review player markers and seeking missing.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'section88d3g4d-review-cues.php')
 ||!str_contains($gate,'section88d3g4d-release.php'))
 throw new RuntimeException('QC functional and release acceptance absent from CI.');
echo "88D3G4D: private reviewer feature and release certification passed".PHP_EOL;
