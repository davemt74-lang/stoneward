<?php
declare(strict_types=1);
require_once __DIR__.'/stem-rpp-plugins.php';
require_once __DIR__.'/stem-routing-core.php';
/** Safe, read-only REAPER RPP metadata importer (never executes plugin state). */
function sf_stem_safe_filename(string $value):string {
    $name=basename(str_replace('\\','/',$value));
    return substr(preg_replace('/[\x00-\x1F\x7F]/','',$name)??'',0,255);
}
function sf_stem_rpp_fx(string $raw):array {
    $name=substr(trim($raw),0,180);
    $low=strtolower($name);
    $type=str_contains($low,'reaeq')?'eq':(str_contains($low,'reacomp')?'compressor':(str_contains($low,'reaverb')?'reverb':(str_contains($low,'readelay')?'delay':(str_contains($low,'limiter')?'limiter':'unsupported'))));
    return ['name'=>$name,'type'=>$type,'mapping'=>$type==='unsupported'?'identity-only':'approximate-browser-emulation', 'enabled'=>true];
}
function sf_stem_rpp_parse(string $text):array {
    if(strlen($text)>8388608 || strlen($text)<20 || !preg_match('/^<REAPER_PROJECT\s+/',ltrim($text)))
        throw new InvalidArgumentException('Expected a REAPER .rpp text project under 8 MB.');
    $lines=preg_split('/\r\n|\r|\n/',$text);
    if(!is_array($lines)||count($lines)>160000)throw new InvalidArgumentException('REAPER project is too large.');
    $project=['format'=>'stonefellow.stem-rpp.v1','project_name'=>'REAPER Import','tempo_bpm'=>120.0,'time_signature'=>'4/4','sample_rate'=>0,'tracks'=>[],'master_fx'=>[],'markers'=>[],'media_required'=>[],'item_count'=>0];
    $track=null;$item=null;$inFx=false;$insideTrack=false;
    $finishItem=static function()use(&$track,&$item,&$project):void {
        if($item!==null&&$track!==null){
            if(count($track['clips'])<2048){$track['clips'][]=$item;$project['item_count']++;}
            $item=null;
        }
    };
    $finishTrack=static function()use(&$track,&$project,&$finishItem):void {
        $finishItem();
        if($track!==null){
            if($track['name']==='')$track['name']='Track '.(count($project['tracks'])+1);
            $track['duration_seconds']=0;
            foreach($track['clips'] as $clip)$track['duration_seconds']=max($track['duration_seconds'],$clip['position']+$clip['length']);
            $selected=null;
            foreach($track['clips'] as $clip){if($clip['file']!==''&&str_contains(strtolower($clip['file']),'consolidated')){$selected=$clip;break;}}
            if(!$selected)foreach($track['clips'] as $clip){if($clip['file']!==''){$selected=$clip;break;}}
            $track['source_file']=$selected['file']??'';
            $track['source_position']=$selected['position']??0;
            $project['tracks'][]=$track;
            $track=null;
        }
    };
    foreach($lines as $line){
        if(str_starts_with($line,'  <TRACK ')){
            $finishTrack();if(count($project['tracks'])>=256)throw new InvalidArgumentException('Too many REAPER tracks.');
            preg_match('/^  <TRACK\s+(\{[^}]{1,80}\})/',$line,$guid);
            $track=['guid'=>($guid[1]??'track-'.count($project['tracks'])),'name'=>'','role'=>'Other','volume'=>1.0,'pan'=>0.0,'mute'=>false,'solo'=>false,'clips'=>[],'fx'=>[],'asset_uuid'=>null];
            $insideTrack=true;$inFx=false;continue;
        }
        if(!$insideTrack){
            if(preg_match('/^  TEMPO\s+([\d.]+)\s+(\d+)\s+(\d+)/',$line,$m)){$project['tempo_bpm']=max(20,min(400,(float)$m[1]));$project['time_signature']=(int)$m[2].'/'.(int)$m[3];}
            if(preg_match('/^  SAMPLERATE\s+(\d+)/',$line,$m))$project['sample_rate']=(int)$m[1];
            if(preg_match('/^\s+<(VST|JS)\s+"?([^"\r\n]+?)(?:"|\s+""|$)/',$line,$m)){$project['master_fx'][]=sf_stem_rpp_fx($m[2]);}
            if(preg_match('/^  MARKER\s+\d+\s+([\d.]+)\s+"([^"]*)"/',$line,$m))$project['markers'][]=['position'=>(float)$m[1],'name'=>substr($m[2],0,120)];
            continue;
        }
        if(preg_match('/^    NAME\s+"(.*)"\s*$/',$line,$m)){$track['name']=substr($m[1],0,180);continue;}
        if(preg_match('/^    VOLPAN\s+([\d.eE+-]+)\s+([\d.eE+-]+)/',$line,$m)){$track['volume']=max(0,min(4,(float)$m[1]));$track['pan']=max(-1,min(1,(float)$m[2]));continue;}
        if(preg_match('/^    MUTESOLO\s+(\d+)\s+(\d+)/',$line,$m)){$track['mute']=(int)$m[1]!==0;$track['solo']=(int)$m[2]!==0;continue;}
        if(preg_match('/^    <(?:VST|JS)\s+"?([^"\r\n]+?)(?:"|\s+""|$)/',$line,$m) || preg_match('/^      <(?:VST|JS)\s+"?([^"\r\n]+?)(?:"|\s+""|$)/',$line,$m)){
            $track['fx'][]=sf_stem_rpp_fx($m[1]);continue;
        }
        if(preg_match('/^    <ITEM\b/',$line)){$finishItem();$item=['position'=>0.0,'length'=>0.0,'offset'=>0.0,'file'=>'','source_type'=>''];continue;}
        if($item!==null){
            if(preg_match('/^      POSITION\s+([\d.eE+-]+)/',$line,$m))$item['position']=max(0,(float)$m[1]);
            if(preg_match('/^      LENGTH\s+([\d.eE+-]+)/',$line,$m))$item['length']=max(0,(float)$m[1]);
            if(preg_match('/^        SOFFS\s+([\d.eE+-]+)/',$line,$m))$item['offset']=max(0,(float)$m[1]);
            if(preg_match('/^        <SOURCE\s+(\w+)/',$line,$m))$item['source_type']=substr($m[1],0,20);
            if(preg_match('/^\s+FILE\s+"([^"]{1,4096})"/',$line,$m))$item['file']=sf_stem_safe_filename($m[1]);
        }
    }
    $finishTrack();
    if(!$project['tracks'])throw new InvalidArgumentException('No audio tracks found in REAPER project.');
    foreach($project['tracks'] as $t)if($t['source_file']!=='')$project['media_required'][$t['source_file']]=true;
    $project['media_required']=array_keys($project['media_required']);
    $project['track_count']=count($project['tracks']);
    sf_stem_route_import($project,$text);
    return sf_stem_rpp_enrich_plugins($project,$text);
}
