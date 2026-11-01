<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/media-stream-range.php';

$passed=0;
function check91b14d(bool $condition,string $label):void {
    global $passed;
    if(!$condition)throw new RuntimeException('91B14D FAIL '.$label);
    $passed++;
    echo '91B14D PASS '.$label."\n";
}
function denied91b14d(string $range,int $size):bool {
    try{sf_media_stream_range($range,$size);return false;}
    catch(InvalidArgumentException $e){return true;}
}

$full=sf_media_stream_range('',1000);
check91b14d(!$full['partial']&&$full['length']===1000&&$full['end']===999,'Full file has exact 200 response length');
$first=sf_media_stream_range('bytes=0-99',1000);
check91b14d($first['partial']&&$first['start']===0&&$first['end']===99&&$first['length']===100,'Leading 206 byte range is exact');
$open=sf_media_stream_range('bytes=750-',1000);
check91b14d($open['start']===750&&$open['end']===999&&$open['length']===250,'Open range streams through end of file');
$suffix=sf_media_stream_range('bytes=-100',1000);
check91b14d($suffix['start']===900&&$suffix['end']===999&&$suffix['length']===100,'iPhone suffix range returns actual final bytes');
$oversize=sf_media_stream_range('bytes=-1500',1000);
check91b14d($oversize['start']===0&&$oversize['length']===1000,'Large suffix range clamps to file');
check91b14d(denied91b14d('bytes=1000-',1000)&&denied91b14d('bytes=900-500',1000),'Out-of-bounds ranges are rejected');
check91b14d(denied91b14d('bytes=0-99,300-399',1000)&&denied91b14d('bytes=-0',1000),'Multipart and zero-length suffix are rejected');
check91b14d(denied91b14d('bytes=0-99garbage',1000)&&denied91b14d('',0),'Malformed range and empty media are rejected');

$route=file_get_contents(dirname(__DIR__).'/api/media.php');
check91b14d(str_contains($route,'sf_media_stream_range(')&&str_contains($route,"header('Content-Length: '.\$range['length'])")&&str_contains($route,'no-transform'), 'Public media endpoint streams uncompressed byte-exact responses');
echo "Section 91B14D: {$passed} ranged streaming checks passed\n";
