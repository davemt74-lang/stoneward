<?php
declare(strict_types=1);

function sf_theme_font_options(): array {
    return [
      'display'=>[
        'editorial'=>['label'=>'Editorial Serif','css'=>'Georgia, "Times New Roman", serif'],
        'modern'=>['label'=>'Modern Sans','css'=>'Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif'],
        'humanist'=>['label'=>'Humanist','css'=>'"Trebuchet MS", "Segoe UI", ui-sans-serif, sans-serif'],
      ],
      'body'=>[
        'system'=>['label'=>'System Sans','css'=>'Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif'],
        'humanist'=>['label'=>'Humanist Sans','css'=>'"Trebuchet MS", "Segoe UI", ui-sans-serif, sans-serif'],
        'mono'=>['label'=>'Studio Mono','css'=>'ui-monospace, "SFMono-Regular", Consolas, "Liberation Mono", monospace'],
      ],
    ];
}
function sf_theme_presets(): array {
    return [
      'desert'=>[
        'label'=>'Desert','mode'=>'dark','display_font'=>'editorial','body_font'=>'system','corner_style'=>'soft','density'=>'comfortable','max_width'=>1080,
        'dark'=>['bg'=>'#090807','surface'=>'#12100e','surface_alt'=>'#1a1612','ink'=>'#f2eee8','muted'=>'#a59e94','accent'=>'#c9a96e','accent_text'=>'#090807','danger'=>'#d68a7f'],
        'light'=>['bg'=>'#f6f1e8','surface'=>'#fffaf1','surface_alt'=>'#e9dfd0','ink'=>'#211b16','muted'=>'#645b52','accent'=>'#7c5425','accent_text'=>'#ffffff','danger'=>'#983f37'],
      ],
      'midnight'=>[
        'label'=>'Midnight','mode'=>'dark','display_font'=>'modern','body_font'=>'system','corner_style'=>'soft','density'=>'comfortable','max_width'=>1120,
        'dark'=>['bg'=>'#060a12','surface'=>'#0d1421','surface_alt'=>'#121d2f','ink'=>'#f3f6fb','muted'=>'#a3afc2','accent'=>'#8db6ff','accent_text'=>'#07101f','danger'=>'#ff9b91'],
        'light'=>['bg'=>'#f5f8fd','surface'=>'#ffffff','surface_alt'=>'#e7eef8','ink'=>'#162033','muted'=>'#59677d','accent'=>'#315fba','accent_text'=>'#ffffff','danger'=>'#9d3e39'],
      ],
      'paper'=>[
        'label'=>'Paper','mode'=>'light','display_font'=>'editorial','body_font'=>'humanist','corner_style'=>'square','density'=>'comfortable','max_width'=>1040,
        'dark'=>['bg'=>'#171512','surface'=>'#211e1a','surface_alt'=>'#2a251f','ink'=>'#f7f1e7','muted'=>'#b4aa9d','accent'=>'#e0b86f','accent_text'=>'#17120b','danger'=>'#e08f83'],
        'light'=>['bg'=>'#fbf8f0','surface'=>'#ffffff','surface_alt'=>'#eee8dc','ink'=>'#24201b','muted'=>'#685f55','accent'=>'#725126','accent_text'=>'#ffffff','danger'=>'#953f37'],
      ],
      'monochrome'=>[
        'label'=>'Monochrome','mode'=>'system','display_font'=>'modern','body_font'=>'system','corner_style'=>'square','density'=>'compact','max_width'=>1180,
        'dark'=>['bg'=>'#080808','surface'=>'#111111','surface_alt'=>'#1b1b1b','ink'=>'#f5f5f5','muted'=>'#b0b0b0','accent'=>'#ffffff','accent_text'=>'#111111','danger'=>'#ff9b92'],
        'light'=>['bg'=>'#fafafa','surface'=>'#ffffff','surface_alt'=>'#e9e9e9','ink'=>'#171717','muted'=>'#5e5e5e','accent'=>'#171717','accent_text'=>'#ffffff','danger'=>'#923a34'],
      ],
    ];
}
function sf_theme_default(): array { $p=sf_theme_presets();return ['preset'=>'desert']+$p['desert']; }
function sf_theme_hex(string $v,string $label='Color'): string {
    $v=strtolower(trim($v));if(!preg_match('/^#[0-9a-f]{6}$/',$v))throw new InvalidArgumentException($label.' must be a six-digit hex color.');return $v;
}
function sf_theme_palette(array $raw,array $base,string $label): array {
    $out=[];foreach(['bg','surface','surface_alt','ink','muted','accent','accent_text','danger'] as $key)$out[$key]=sf_theme_hex((string)($raw[$key]??$base[$key]??''),$label.' '.str_replace('_',' ',$key));return $out;
}
function sf_theme_normalize(array $raw,?array $base=null): array {
    $defaults=$base?:sf_theme_default();$presets=sf_theme_presets();$preset=sf_clean_text($raw['preset']??$defaults['preset']??'custom',40);if(!isset($presets[$preset])&&$preset!=='custom')$preset='custom';
    $seed=isset($presets[$preset])?$presets[$preset]:$defaults;$mode=sf_clean_text($raw['mode']??$seed['mode']??'dark',20);if(!in_array($mode,['dark','light','system'],true))throw new InvalidArgumentException('Theme mode must be dark, light, or system.');
    $fonts=sf_theme_font_options();$display=sf_clean_text($raw['display_font']??$seed['display_font']??'editorial',40);$body=sf_clean_text($raw['body_font']??$seed['body_font']??'system',40);if(!isset($fonts['display'][$display]))throw new InvalidArgumentException('Unsupported display typeface.');if(!isset($fonts['body'][$body]))throw new InvalidArgumentException('Unsupported body typeface.');
    $corners=sf_clean_text($raw['corner_style']??$seed['corner_style']??'soft',20);if(!in_array($corners,['square','soft','round'],true))throw new InvalidArgumentException('Unsupported corner style.');$density=sf_clean_text($raw['density']??$seed['density']??'comfortable',20);if(!in_array($density,['comfortable','compact'],true))throw new InvalidArgumentException('Unsupported density.');
    $max=max(880,min(1320,(int)($raw['max_width']??$seed['max_width']??1080)));
    return ['preset'=>$preset,'mode'=>$mode,'display_font'=>$display,'body_font'=>$body,'corner_style'=>$corners,'density'=>$density,'max_width'=>$max,'dark'=>sf_theme_palette((array)($raw['dark']??[]),(array)$seed['dark'],'Dark'),'light'=>sf_theme_palette((array)($raw['light']??[]),(array)$seed['light'],'Light')];
}
function sf_theme_decode(string $raw,array $fallback): array { $d=json_decode($raw,true);if(!is_array($d))return $fallback;try{return sf_theme_normalize($d,$fallback);}catch(Throwable $e){return $fallback;} }
function sf_theme_published(): array { $d=sf_theme_default();return sf_theme_decode(sf_meta_get('site.theme_published_json',''),$d); }
function sf_theme_draft(): array { $p=sf_theme_published();return sf_theme_decode(sf_meta_get('site.theme_draft_json',''),$p); }
function sf_theme_state(): array {
    $p=sf_theme_published();$d=sf_theme_draft();return ['published'=>$p,'draft'=>$d,'published_at'=>sf_meta_get('site.theme_published_at',''),'draft_updated_at'=>sf_meta_get('site.theme_draft_updated_at',''),'readiness'=>sf_theme_readiness($d),'published_readiness'=>sf_theme_readiness($p)];
}
function sf_theme_save_draft(array $raw): array {
    $draft=sf_theme_normalize($raw,sf_theme_draft());sf_meta_set('site.theme_draft_json',json_encode($draft,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));sf_meta_set('site.theme_draft_updated_at',gmdate('c'));return ['draft'=>$draft,'readiness'=>sf_theme_readiness($draft)];
}
function sf_theme_publish_draft(): array {
    $draft=sf_theme_draft();$r=sf_theme_readiness($draft);if(!$r['ready'])throw new RuntimeException('Theme cannot be published until contrast blockers are resolved.');sf_meta_set('site.theme_published_json',json_encode($draft,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));sf_meta_set('site.theme_published_at',gmdate('c'));return sf_theme_state();
}
function sf_theme_reset_draft(): array { $p=sf_theme_published();sf_meta_set('site.theme_draft_json',json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));sf_meta_set('site.theme_draft_updated_at',gmdate('c'));return sf_theme_state(); }
function sf_theme_restore_default(): array { $d=sf_theme_default();$j=json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);sf_meta_set('site.theme_published_json',$j);sf_meta_set('site.theme_draft_json',$j);$now=gmdate('c');sf_meta_set('site.theme_published_at',$now);sf_meta_set('site.theme_draft_updated_at',$now);return sf_theme_state(); }

function sf_theme_rgb(string $hex): array { $h=ltrim($hex,'#');return [hexdec(substr($h,0,2)),hexdec(substr($h,2,2)),hexdec(substr($h,4,2))]; }
function sf_theme_luminance(string $hex): float {
    $rgb=sf_theme_rgb($hex);$parts=[];foreach($rgb as $v){$x=$v/255;$parts[]=$x<=.03928?$x/12.92:(($x+.055)/1.055)**2.4;}return .2126*$parts[0]+.7152*$parts[1]+.0722*$parts[2];
}
function sf_theme_contrast(string $a,string $b): float { $x=sf_theme_luminance($a);$y=sf_theme_luminance($b);$hi=max($x,$y);$lo=min($x,$y);return ($hi+.05)/($lo+.05); }
function sf_theme_readiness(array $raw): array {
    try{$t=sf_theme_normalize($raw);}catch(Throwable $e){return ['ready'=>false,'score'=>0,'checks'=>[],'blockers'=>[$e->getMessage()],'warnings'=>[]];}
    $modes=$t['mode']==='system'?['dark','light']:[$t['mode']];$checks=[];$blockers=[];$warnings=[];
    foreach($modes as $mode){$p=$t[$mode];foreach([
      ['ink_bg','Primary text / background',$p['ink'],$p['bg'],4.5,true],
      ['muted_bg','Secondary text / background',$p['muted'],$p['bg'],4.5,true],
      ['ink_surface','Primary text / surface',$p['ink'],$p['surface'],4.5,true],
      ['accent_bg','Accent / background',$p['accent'],$p['bg'],4.5,true],
      ['accent_text','Accent button text',$p['accent_text'],$p['accent'],4.5,true],
      ['danger_bg','Danger / background',$p['danger'],$p['bg'],3.0,false],
    ] as [$key,$label,$fg,$bg,$minimum,$required]){$ratio=sf_theme_contrast($fg,$bg);$ok=$ratio+0.0001>=$minimum;$detail=$label.' '.number_format($ratio,2).':1 (minimum '.number_format($minimum,1).':1).';$checks[]=['key'=>$mode.'_'.$key,'label'=>ucfirst($mode).' '.$label,'ok'=>$ok,'ratio'=>round($ratio,2),'minimum'=>$minimum,'required'=>$required,'detail'=>$detail];if(!$ok){if($required)$blockers[]=$detail;else$warnings[]=$detail;}}}
    $passed=count(array_filter($checks,fn($x)=>!empty($x['ok'])));$score=count($checks)?(int)round($passed*100/count($checks)):0;return ['ready'=>$blockers===[],'score'=>$score,'checks'=>$checks,'blockers'=>$blockers,'warnings'=>$warnings];
}
function sf_theme_font_css(array $t): array { $f=sf_theme_font_options();return ['display'=>$f['display'][$t['display_font']]['css'],'body'=>$f['body'][$t['body_font']]['css']]; }
function sf_theme_radius_values(string $style): array { return match($style){'square'=>['card'=>'0px','control'=>'0px'],'round'=>['card'=>'24px','control'=>'999px'],default=>['card'=>'12px','control'=>'10px']}; }
function sf_theme_palette_css(array $p): string {
    [$br,$bg,$bb]=sf_theme_rgb($p['bg']);[$ir,$ig,$ib]=sf_theme_rgb($p['ink']);[$ar,$ag,$ab]=sf_theme_rgb($p['accent']);[$sr,$sg,$sb]=sf_theme_rgb($p['surface']);
    return '--bg:'.$p['bg'].';--surface:'.$p['surface'].';--surface2:'.$p['surface_alt'].';--ink:'.$p['ink'].';--muted:'.$p['muted'].';--accent:'.$p['accent'].';--accent-text:'.$p['accent_text'].';--danger:'.$p['danger'].';--bg-rgb:'.$br.','.$bg.','.$bb.';--ink-rgb:'.$ir.','.$ig.','.$ib.';--accent-rgb:'.$ar.','.$ag.','.$ab.';--surface-rgb:'.$sr.','.$sg.','.$sb.';--line:rgba('.$ir.','.$ig.','.$ib.',.13);--line2:rgba('.$ir.','.$ig.','.$ib.',.26);--soft:rgba('.$ar.','.$ag.','.$ab.',.14);';
}
function sf_theme_css(array $raw): string {
    $t=sf_theme_normalize($raw);$fonts=sf_theme_font_css($t);$radius=sf_theme_radius_values($t['corner_style']);$shared='--max:'.$t['max_width'].'px;--display-font:'.$fonts['display'].';--body-font:'.$fonts['body'].';--card-radius:'.$radius['card'].';--control-radius:'.$radius['control'].';';
    if($t['mode']==='system')return ':root{'.$shared.sf_theme_palette_css($t['dark']).'}html{color-scheme:dark light}@media(prefers-color-scheme:light){:root{'.sf_theme_palette_css($t['light']).'}}';
    return ':root{'.$shared.sf_theme_palette_css($t[$t['mode']]).'}html{color-scheme:'.$t['mode'].'}';
}
function sf_theme_body_classes(array $raw): string { $t=sf_theme_normalize($raw);return 'theme-mode-'.$t['mode'].' theme-density-'.$t['density'].' theme-corners-'.$t['corner_style']; }
function sf_theme_meta_colors(array $raw): array { $t=sf_theme_normalize($raw);return ['mode'=>$t['mode'],'dark'=>$t['dark']['bg'],'light'=>$t['light']['bg']]; }
