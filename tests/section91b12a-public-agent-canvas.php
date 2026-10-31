<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static function(string $file)use($root):string{
    $text=file_get_contents($root.'/'.$file);
    if($text===false)throw new RuntimeException('Missing file '.$file);
    return $text;
};
$count=0;
$check=static function(bool $yes,string $label)use(&$count):void{
    if(!$yes){fwrite(STDERR,"91B12A FAIL: $label\n");exit(1);}
    echo "91B12A PASS: $label\n";$count++;
};
$settings=$read('api/site-settings.php');
$admin=$read('admin/api/settings.php');
$public=$read('stonefellow-v120.php');
$check(str_contains($settings,"'homepage_template'=>sf_meta_get('site.homepage_template','classic')"),'Current installations keep classic homepage unless switched');
$check(str_contains($settings,"['classic','agent_canvas']"),'Server only permits supported templates');
$check(str_contains($settings,"sf_meta_set('site.homepage_template'"),'Site template is saved using existing settings metadata');
$check(str_contains($admin,"'homepage_template'=>\$body['homepage_template']??'classic'"),'Admin endpoint persists the selected layout');
$check(str_contains($public,"'homepageTemplate'=>\$siteSettings['homepage_template']??'classic'"),'Public bootstrap includes the selected layout');
$check(str_contains($public,'<header class="topbar">')&&str_contains($public,'<footer class="chat-footer">'),'Existing header and chat footer remain unchanged');
$check(str_contains($public,'public-agent-canvas.css')&&str_contains($public,'public-agent-canvas.js'),'Public template CSS and module are included');
echo "Section 91B12A: $count PHP site-setting integration checks passed\n";
