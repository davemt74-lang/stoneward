<?php
declare(strict_types=1);
define('SF_ROOT',__DIR__);
require __DIR__.'/api/bootstrap.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
$site=sf_site_settings();$theme=sf_theme_published();$meta=sf_theme_meta_colors($theme);$base=rtrim(sf_public_base_url(),'/').'/';
$icons=[['src'=>'assets/icons/stonefellow-pwa.svg','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any maskable']];
$appIcon=trim((string)($site['media_app_icon']??''));if($appIcon!=='')array_unshift($icons,['src'=>$appIcon,'sizes'=>'any','purpose'=>'any']);
echo json_encode([
  'id'=>'./','name'=>'Stonefellow','short_name'=>'Stonefellow',
  'description'=>(string)($site['seo_description']??'Stonefellow music, releases, shows and direct fan experiences.'),
  'start_url'=>'./','scope'=>'./','display'=>'standalone','display_override'=>['window-controls-overlay','standalone','minimal-ui'],
  'background_color'=>(string)$meta[$meta['mode']==='light'?'light':'dark'],
  'theme_color'=>(string)$meta[$meta['mode']==='light'?'light':'dark'],
  'orientation'=>'any','categories'=>['music','entertainment'],'icons'=>$icons,
  'shortcuts'=>[
    ['name'=>'Music','short_name'=>'Music','url'=>'music'],
    ['name'=>'Search','short_name'=>'Search','url'=>'search'],
    ['name'=>'Store','short_name'=>'Store','url'=>'store'],
  ],
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
