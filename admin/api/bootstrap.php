<?php
declare(strict_types=1);
require_once __DIR__ . '/../../api/bootstrap.php';
require_once SF_ROOT . '/api/agent-runtime.php';

const SF_ADMIN_TEMPLATE_DIR = SF_ROOT . '/storage/admin/templates';
const SF_ADMIN_MEDIA_INDEX = SF_ROOT . '/storage/media/index.json';
const SF_ADMIN_RELEASES_FILE = SF_ROOT . '/data/releases.json';
const SF_ADMIN_KNOWLEDGE_INDEX = SF_ROOT . '/storage/knowledge/index.json';
const SF_ADMIN_KNOWLEDGE_FILES = SF_ROOT . '/storage/knowledge/files';
const SF_ADMIN_ARTWORK_DIR = SF_ROOT . '/storage/media/artwork';
const SF_ADMIN_ARTWORK_PUBLIC = SF_ROOT . '/assets/images/catalog';

function sf_admin_session(): void { sf_user_session(); }
function sf_admin_configured(): bool { return sf_installed(); }
function sf_admin_authenticated(): bool { $u=sf_current_user(); return $u && ($u['role']??'')==='admin'; }
function sf_admin_csrf(): string { return sf_user_csrf(); }
function sf_admin_require_auth(bool $write = false): array { return sf_require_user(true,$write); }
function sf_admin_slug(string $text, string $fallback = 'item'): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? substr($text, 0, 80) : $fallback;
}

function sf_admin_template_files(): array {
    if (!is_dir(SF_ADMIN_TEMPLATE_DIR)) @mkdir(SF_ADMIN_TEMPLATE_DIR, 0770, true);
    return glob(SF_ADMIN_TEMPLATE_DIR . '/*.json') ?: [];
}

function sf_admin_templates(): array {
    $rows=[];
    foreach (sf_admin_template_files() as $path) {
        $d=json_decode((string)file_get_contents($path), true);
        if (is_array($d) && isset($d['id'])) $rows[]=$d;
    }
    usort($rows, fn($a,$b)=>strcmp((string)($b['updated_at']??''),(string)($a['updated_at']??'')));
    return $rows;
}

function sf_admin_template(string $id): ?array {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $id)) return null;
    $path=SF_ADMIN_TEMPLATE_DIR.'/'.$id.'.json';
    if (!is_file($path)) return null;
    $d=json_decode((string)file_get_contents($path), true);
    return is_array($d)?$d:null;
}

function sf_admin_bool(mixed $v): bool {
    if (is_bool($v)) return $v;
    return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
}

function sf_admin_array_strings(mixed $v, int $max = 30): array {
    if (!is_array($v)) return [];
    $out=[];
    foreach ($v as $x) {
        $s=sf_clean_text($x,120);
        if ($s!=='' && !in_array($s,$out,true)) $out[]=$s;
        if (count($out)>=$max) break;
    }
    return $out;
}

function sf_admin_normalize_isrc(mixed $v): string {
    $s=strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)$v) ?? '');
    return $s;
}

function sf_admin_isrc_valid(string $s): bool { return $s==='' || (bool)preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/',$s); }

function sf_admin_write_catalog(array $catalog): void {
    $json=json_encode(array_values($catalog), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if ($json===false) throw new RuntimeException('Could not encode catalog.');
    $catalogPath=SF_ROOT.'/data/catalog.json';
    $tmp=$catalogPath.'.tmp.'.bin2hex(random_bytes(3));
    if(file_put_contents($tmp,$json,LOCK_EX)===false) throw new RuntimeException('Could not write catalog.');
    if(!rename($tmp,$catalogPath)){@unlink($tmp);throw new RuntimeException('Could not finalize catalog.');}
    $js="window.STONEFELLOW_CATALOG = ".$json.";\n";
    $jsPath=SF_ROOT.'/assets/js/catalog.js';
    $tmp2=$jsPath.'.tmp.'.bin2hex(random_bytes(3));
    if(file_put_contents($tmp2,$js,LOCK_EX)===false) throw new RuntimeException('Could not write catalog JavaScript.');
    if(!rename($tmp2,$jsPath)){@unlink($tmp2);throw new RuntimeException('Could not finalize catalog JavaScript.');}
}

function sf_admin_media_index(): array {
    if (!is_file(SF_ADMIN_MEDIA_INDEX)) return ['schema'=>'stonefellow.media-index.v1','files'=>[]];
    $d=json_decode((string)file_get_contents(SF_ADMIN_MEDIA_INDEX),true);
    return is_array($d)?$d:['schema'=>'stonefellow.media-index.v1','files'=>[]];
}

function sf_admin_write_media_index(array $index): void { sf_write_json(SF_ADMIN_MEDIA_INDEX,$index); }

function sf_admin_template_defaults(array $raw): array {
    $keys=['artist_display','artist_legal','words_by','music_by','performing_artist','pro_affiliation','ipi_cae','publisher','publisher_pro','publisher_ipi','composition_copyright','master_copyright','rights_notes','producer','co_producer','executive_producer','recording_engineer','mixing_engineer','mastering_engineer','studio','recording_location','release_year','release_date','label','catalog_number','genre','subgenre','language','isrc_policy','isrc_country','isrc_registrant','isrc_year','album_cover','back_cover','label_art_a','label_art_b','social_square','social_story','artwork_behavior'];
    $out=[];
    foreach($keys as $k){$max=$k==='rights_notes'?1000:(in_array($k,['album_cover','back_cover','label_art_a','label_art_b','social_square','social_story'],true)?300:180);$out[$k]=sf_clean_text($raw[$k]??'',$max);}
    $out['price']=max(0,min(999,(float)($raw['price']??2)));
    $out['pod_eligible']=sf_admin_bool($raw['pod_eligible']??true);
    $out['explicit']=sf_admin_bool($raw['explicit']??false);
    $out['co_writers']=[];
    foreach((array)($raw['co_writers']??[]) as $cw){
        if(!is_array($cw))continue;
        $name=sf_clean_text($cw['name']??'',140); if($name==='')continue;
        $out['co_writers'][]=['name'=>$name,'role'=>sf_clean_text($cw['role']??'',80),'split_percent'=>max(0,min(100,(float)($cw['split_percent']??0))),'pro'=>sf_clean_text($cw['pro']??'',40),'ipi'=>sf_clean_text($cw['ipi']??'',60)];
    }
    $out['performers']=[];
    foreach((array)($raw['performers']??[]) as $p){
        if(!is_array($p))continue;
        $name=sf_clean_text($p['name']??'',140); if($name==='')continue;
        $out['performers'][]=['name'=>$name,'instrument'=>sf_clean_text($p['instrument']??'',100),'role'=>sf_clean_text($p['role']??'',80)];
    }
    return $out;
}

function sf_admin_public_credits(array $m): array {
    $c=[];
    $words=trim((string)($m['words_by']??'')); $music=trim((string)($m['music_by']??''));
    if($words!=='' && $music!=='' && $words===$music) $c[]='Words and music by '.$words;
    else { if($words!=='')$c[]='Words by '.$words; if($music!=='')$c[]='Music by '.$music; }
    foreach((array)($m['co_writers']??[]) as $cw) if(!empty($cw['name'])) $c[]='Co-writer: '.$cw['name'];
    if(!empty($m['producer']))$c[]='Produced by '.$m['producer'];
    if(!empty($m['co_producer']))$c[]='Co-produced by '.$m['co_producer'];
    if(!empty($m['mixing_engineer']))$c[]='Mixed by '.$m['mixing_engineer'];
    if(!empty($m['mastering_engineer']))$c[]='Mastered by '.$m['mastering_engineer'];
    return array_values(array_unique($c));
}

function sf_admin_merge_metadata(array $template, array $overrides, string $isrc, string $batchId, array $source): array {
    $base=is_array($template['defaults']??null)?$template['defaults']:[];
    $allowed=array_keys(sf_admin_template_defaults([]));
    foreach($allowed as $key) if(array_key_exists($key,$overrides)) $base[$key]=$overrides[$key];
    $base=sf_admin_template_defaults($base);
    $base['isrc']=$isrc;
    $base['template']=['id'=>(string)$template['id'],'name'=>(string)$template['name'],'version'=>(int)($template['version']??1)];
    $base['source']=['batch_id'=>$batchId]+$source;
    return $base;
}

function sf_admin_orders(): array {
    $rows=[];
    foreach(glob(SF_ROOT.'/storage/orders/*.json')?:[] as $p){$d=json_decode((string)file_get_contents($p),true);if(is_array($d))$rows[]=$d;}
    usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
    return $rows;
}

function sf_admin_pod_handoffs(): array {
    $rows=[];
    foreach(glob(SF_ROOT.'/storage/pod/*.json')?:[] as $p){$d=json_decode((string)file_get_contents($p),true);if(is_array($d)){$d['_file']=basename($p);$rows[]=$d;}}
    usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
    return $rows;
}


function sf_admin_releases(): array {
    if (!is_file(SF_ADMIN_RELEASES_FILE)) return [];
    $d=json_decode((string)file_get_contents(SF_ADMIN_RELEASES_FILE),true); return is_array($d)?array_values($d):[];
}
function sf_admin_write_releases(array $rows): void {
    sf_write_json(SF_ADMIN_RELEASES_FILE,array_values($rows));
    $json=json_encode(array_values($rows),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false) throw new RuntimeException('Could not encode releases.');
    if(file_put_contents(SF_ROOT.'/assets/js/releases.js',"window.STONEFELLOW_RELEASES = ".$json.";\n",LOCK_EX)===false) throw new RuntimeException('Could not write release JavaScript.');
}
function sf_admin_knowledge_index(): array {
    if(!is_file(SF_ADMIN_KNOWLEDGE_INDEX)) return ['schema'=>'stonefellow.knowledge.v1','folders'=>[],'files'=>[]];
    $d=json_decode((string)file_get_contents(SF_ADMIN_KNOWLEDGE_INDEX),true);
    if(!is_array($d)) return ['schema'=>'stonefellow.knowledge.v1','folders'=>[],'files'=>[]];
    $d['folders']=array_values((array)($d['folders']??[])); $d['files']=array_values((array)($d['files']??[])); return $d;
}
function sf_admin_write_knowledge(array $d): void { sf_write_json(SF_ADMIN_KNOWLEDGE_INDEX,$d); }
function sf_admin_safe_file_name(string $name): string {
    $name=preg_replace('/[^A-Za-z0-9._ -]+/','_',basename($name)) ?? 'file';
    return substr(trim($name),0,180) ?: 'file';
}
function sf_admin_extract_text(string $path,string $ext): string {
    $ext=strtolower($ext); $txt='';
    if(in_array($ext,['txt','md','csv','json','html','htm','xml'],true)) $txt=(string)@file_get_contents($path);
    elseif($ext==='docx' && class_exists('ZipArchive')) {
        $z=new ZipArchive(); if($z->open($path)===true){$xml=$z->getFromName('word/document.xml');$z->close();if(is_string($xml))$txt=strip_tags(str_replace(['</w:p>','</w:tr>'],["\n","\n"],$xml));}
    }
    $txt=preg_replace('/\s+/u',' ',strip_tags($txt)) ?? ''; return substr(trim($txt),0,120000);
}
