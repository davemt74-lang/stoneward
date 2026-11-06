<?php
declare(strict_types=1);

function sf_agent_scene_display_key(string $text): string {
    $text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
    $text=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$text)??'');
    return preg_replace('/\A(?:(?:please|let s|lets) )?(?:build|make|create|design|render|generate)(?: me)? /','',$text)??$text;
}

function sf_agent_scene_copy_requested(string $brief,string $text): bool {
    $copy='(?:headline|heading|caption|tagline|(?:sub)?title|label|text|copy)';
    $pattern='/\b'.$copy.'\h*(?:(?:that\h+)?(?:reads|says)|reading|saying|of|=|:)?\h*'
        .'(?:"([^"]{1,180})"|“([^”]{1,180})”|‘([^’]{1,180})’|\x27([^\x27]{1,180})\x27)/iu';
    preg_match_all($pattern,$brief,$quoted,PREG_SET_ORDER);
    foreach($quoted as $match){
        $value=implode('',array_slice($match,1));
        if(sf_agent_scene_display_key($value)===$text)return true;
    }
    // Also accept plain input like “the exact headline STAR CITY” or “headline: STAR CITY”.
    preg_match_all('/\b(?:exact\h+'.$copy.'\h*:?\h+|'.$copy.'\h*:\h*)([^\r\n.,;!?]{1,180})(?=[\r\n.,;!?]|$)/iu',$brief,$exact);
    foreach($exact[1] as $value)if(sf_agent_scene_display_key($value)===$text)return true;
    return false;
}

function sf_agent_scene_implementation_note(string $key): bool {
    return preg_match('/\b(?:'
        .'(?:playback|audio|player|play|music|transport) controls? (?:is |are )?(?:provided|supplied|handled|managed|rendered) (?:by |in )?(?:the )?(?:host|parent|app)'
        .'|(?:the )?(?:host|parent|app) (?:provides|supplies|handles|manages|renders) (?:the )?(?:(?:playback|audio|player|play|music|transport) )?controls?'
        .'|(?:sandbox|iframe) (?:limitation|implementation|restriction|does not support)'
        .'|(?:implementation|developer) (?:note|detail)'
        .')\b/u',$key)===1;
}

function sf_agent_scene_echoes_brief(string $key,string $brief): bool {
    if($key===''||$brief==='')return false;
    if($key===$brief)return true;
    return substr_count($key,' ')>=4&&substr_count($brief,' ')>=4&&
        (str_contains(' '.$brief.' ',' '.$key.' ')||str_contains(' '.$key.' ',' '.$brief.' '));
}

/** Review the text in validated scene markup. Inline formatting stays part of the same text run. */
function sf_agent_scene_scrub_copy(array $code,?string $brief=null,bool $trusted=false): array {
    $parts=preg_split('/(<[^>]+>)/u',$code['html'],-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
    if($parts===false)return $code;
    $runs=[];$indexes=[];$text='';$summary=false;$counts=[];
    $flush=static function()use(&$indexes,&$text,&$runs,&$summary,&$counts):void {
        $key=sf_agent_scene_display_key($text);
        if($key!==''){
            $runs[]=[$indexes,$text,$key,$summary];
            $counts[$key]=($counts[$key]??0)+1;
        }
        $indexes=[];$text='';
    };
    foreach($parts as $index=>$part){
        if($part[0]!=='<'){$indexes[]=$index;$text.=$part;continue;}
        if(preg_match('/^<\/?(?:span|em|strong)\b/',$part))continue;
        if(preg_match('/^<br\b/',$part)){$text.=' ';continue;}
        $flush();
        if(preg_match('/^<\/?summary\b/',$part))$summary=$part[1]!=='/';
    }
    $flush();
    $briefKey=$brief===null?'':sf_agent_scene_display_key($brief);
    $removed=[];
    foreach($runs as [$indexes,$text,$key,$summary]){
        $requested=$brief!==null&&sf_agent_scene_copy_requested($brief,$key);
        $canonical=preg_match('/\{\{(?:title|release|story_title|story_summary)\}\}/',$text)&&
            preg_match('/\A[^\p{L}\p{N}]*\z/u',preg_replace('/\{\{(?:title|release|story_title|story_summary)\}\}/','',$text)??$text);
        // Old rows do not retain the brief. Remove explicit build commands and repeated descriptive instructions.
        $legacyEcho=$brief===null&&(
            preg_match('/\A\s*(?:let[’\x27]?s|please)\h+(?:build|make|create|design|render|generate)\b/iu',$text) ||
            ($counts[$key]>1&&substr_count($key,' ')>=7&&preg_match('/\b(?:with|that|and|for|using|featuring)\b/u',$key)));
        $implementation=sf_agent_scene_implementation_note($key);
        if($trusted||$requested||(!$implementation&&!sf_agent_scene_echoes_brief($key,$briefKey)&&!$legacyEcho&&
            ($brief===null||$canonical||($summary&&substr_count($key,' ')<=7))))continue;
        foreach($indexes as $index)$parts[$index]='';
        if($legacyEcho||$implementation)$removed[$key]=true;
    }
    $code['html']=implode('',$parts);
    if(isset($code['title'])&&isset($removed[sf_agent_scene_display_key($code['title'])]))$code['title']='Custom Canvas';
    // Pseudo-elements can paint text too. Keep decorative content without accepting provider copy through CSS.
    $code['css']=preg_replace_callback('/"[^"]*"|\x27[^\x27]*\x27|([;{]\s*content\s*:\s*)((?:"[^"]*"|\x27[^\x27]*\x27|[^"\x27;}])*)/iu',
        static function(array $match):string {
            if(!isset($match[1]))return $match[0];
            $value=trim($match[2]);
            $important=preg_match('/\s*!\s*important\z/i',$value)===1;
            if($important)$value=trim(preg_replace('/\s*!\s*important\z/i','',$value)??$value);
            if(preg_match('/\A(?:none|normal|"[^\p{L}\p{N}"]*"|\x27[^\p{L}\p{N}\x27]*\x27)\z/iu',$value))return $match[0];
            return $match[1].'""'.($important?' !important':'');
        },$code['css'])??$code['css'];
    return $code;
}
