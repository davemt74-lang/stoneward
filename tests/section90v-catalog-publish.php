<?php
declare(strict_types=1);
function sf_admin_public_credits(array $metadata):array{return [];}
require dirname(__DIR__).'/api/media-catalog-intake.php';
function check90v(bool $pass,string $message):void {
    if(!$pass){fwrite(STDERR,'FAIL: '.$message."\n");exit(1);}
    echo 'PASS: '.$message."\n";
}
$required=['title'=>'Song title','artist_display'=>'Artist name',
    'words_by'=>'Lyrics / words credit','music_by'=>'Music / composition credit',
    'master_owner'=>'Master recording owner','composition_owner'=>'Composition rights owner'];
check90v(sf_media_catalog_missing_fields([])===$required,
    'All required publication fields are identified before the writer runs.');
$ready=['title'=>'Daily Delusions','artist_display'=>'Stonefellow','words_by'=>'David Evans',
    'music_by'=>'David Evans','master_owner'=>'David Evans','composition_owner'=>'David Evans'];
check90v(sf_media_catalog_missing_fields($ready)===[],
    'Complete song credits and ownership permit public review.');
$ready['master_owner']='   ';
check90v(sf_media_catalog_missing_fields($ready)===['master_owner'=>'Master recording owner'],
    'Whitespace-only ownership cannot pass publication review.');
echo "Section 90V catalog publication preflight: PASS\n";
