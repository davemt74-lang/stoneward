<?php
declare(strict_types=1);
require __DIR__.'/api/bootstrap.php';
require __DIR__.'/api/pwa-splash-core.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
$site=sf_site_settings();$theme=sf_theme_published();$meta=sf_theme_meta_colors($theme);
$pwaSplash=sf_pwa_splash_published();
$themeColor=(string)$meta[$meta['mode']==='light'?'light':'dark'];
$icons=[
    ['src'=>'assets/icons/stonefellow-pwa.svg','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any maskable'],
    ['src'=>'assets/icons/stonefellow-pwa-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],
    ['src'=>'assets/icons/stonefellow-pwa-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any'],
];
$appIcon=trim((string)($site['media_app_icon']??''));$appIcon=$appIcon!==''?sf_seo_abs($appIcon):'';
if(!empty($pwaSplash['iconUrl'])){
    $size=(int)$pwaSplash['iconSize'];
    $icons[]=['src'=>$pwaSplash['iconUrl'],'sizes'=>$size.'x'.$size,'type'=>$pwaSplash['iconMime'],'purpose'=>'any'];
}elseif($appIcon!==''&&sf_seo_https_url($appIcon)){
    array_unshift($icons,['src'=>$appIcon,'sizes'=>'any','purpose'=>'any']);
}
echo json_encode([
  'id'=>'./','name'=>'Stonefellow','short_name'=>'Stonefellow','lang'=>'en','dir'=>'ltr',
  'description'=>(string)($site['seo_description']??'Stonefellow music, releases, shows and direct fan experiences.'),
  'start_url'=>'./','scope'=>'./','display'=>'standalone','display_override'=>['window-controls-overlay','standalone','minimal-ui'],
  'background_color'=>($pwaSplash['backgroundColor']??'')?:$themeColor,
  'theme_color'=>$themeColor,
  'orientation'=>'any','categories'=>['music','entertainment'],'icons'=>$icons,
  'shortcuts'=>[
    ['name'=>'Music','short_name'=>'Music','url'=>'music'],
    ['name'=>'Search','short_name'=>'Search','url'=>'search'],
    ['name'=>'Store','short_name'=>'Store','url'=>'store'],
    ['name'=>'Live Sessions','short_name'=>'Sessions','url'=>'jukebox.php'],
  ],
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
