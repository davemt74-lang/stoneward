<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$site=$read('stonefellow-v120.php');preg_match("/define\\('SF_BUILD',\\s*'([0-9.]+)'\\)/",$site,$m);$version=$m[1]??'';
$checks=[
 'Latest Stonefellow release number'=>version_compare($version,'1.3.104','>='),
 'Admin and public version aligned'=>str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','".$version."')"),
 'Version endpoint aligned'=>str_contains($read('version.php'),"'stonefellow'=>'".$version."'"),
 'PWA and JS cache invalidated'=>str_contains($read('service-worker.js'),"stonefellow-static-v".$version)
   &&str_contains($read('service-worker.js'),"const BUILD='".$version."'")
   &&str_contains($read('assets/js/app.js'),$version),
 'Apache header aligned'=>str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$version.'"'),
 'Stylesheet version aligned'=>str_contains($read('assets/css/turntable-builder.css'),$version),
 'Canonical payment verification exists'=>str_contains($read('api/fan-conversion-verified.php'),'sf_87d_order_payment_class'),
 'Consent-bound intent tracking and purge present'=>str_contains($read('api/fan-conversion-intent-core.php'),'sf_87d_intent_clear'),
 'Real checkout and cart links preserved'=>str_contains($read('assets/js/app.js'),"recordConversionReview('checkout_review')")
     &&str_contains($read('assets/js/app.js'),"recordConversionReview('cart_review')"),
 'Admin panel and Agent Brain connected'=>str_contains($read('admin/assets/analytics.js'),'verifiedConversionPanel(c,verified)')
     &&str_contains($read('api/agent-admin-brain.php'),'sf_87d_admin_agent_message('),
 'Release gate has all 87D regressions'=>str_contains($read('.github/workflows/release-gate.yml'),'php tests/section87d1-verified-conversions.php')
     &&str_contains($read('.github/workflows/release-gate.yml'),'php tests/section87d2-consented-intent.php')
     &&str_contains($read('.github/workflows/release-gate.yml'),'php tests/section87d3-admin-conversion.php')
];
$bad=0;foreach($checks as $n=>$ok){echo ($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$bad++;}
exit($bad?1:0);
