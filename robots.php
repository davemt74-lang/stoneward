<?php
declare(strict_types=1);
define('SF_ROOT',__DIR__);
require __DIR__.'/api/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=300');
$base=sf_seo_base_url();
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: /admin/\n";
echo "Disallow: /api/\n";
echo "Disallow: /storage/\n";
echo "Disallow: /data/\n";
echo "Disallow: /*?view=account\n";
echo "Disallow: /*?view=login\n";
echo "Disallow: /*?view=register\n";
echo "Disallow: /*?token=\n";
if($base!=='')echo "Sitemap: ".$base."/sitemap.xml\n";
