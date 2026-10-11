<?php
declare(strict_types=1);
define('SF_ROOT',__DIR__);
require __DIR__.'/api/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
$rows=sf_seo_sitemap_entries();
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach($rows as $r){
  echo "  <url><loc>".htmlspecialchars((string)$r['loc'],ENT_XML1|ENT_QUOTES,'UTF-8')."</loc>";
  $last=trim((string)($r['lastmod']??''));if($last!==''){if(($ts=strtotime($last))!==false)$last=gmdate('Y-m-d',$ts);echo "<lastmod>".htmlspecialchars($last,ENT_XML1|ENT_QUOTES,'UTF-8')."</lastmod>";}
  echo "<priority>".htmlspecialchars((string)($r['priority']??'0.7'),ENT_XML1|ENT_QUOTES,'UTF-8')."</priority></url>\n";
}
echo "</urlset>\n";
