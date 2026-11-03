<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-song-audio-excerpt.php';

$checks=0;$files=[];$dir=sys_get_temp_dir().'/sf-audio-test-'.bin2hex(random_bytes(8));
if(!mkdir($dir,0700))throw new RuntimeException('Could not create audio test directory.');
function check(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";}
function rejects(callable $fn,string $label):void{$failed=false;try{$r=$fn();if(is_array($r)&&isset($r['path']))@unlink($r['path']);}catch(Throwable $e){$failed=true;}check($failed,$label);}
function run_audio(array $command):string{
    $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Could not start fixture/decode tool.');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $status=proc_close($p);if($status!==0)throw new RuntimeException('Fixture/decode command failed: '.$stderr);
    if($stderr!=='')throw new RuntimeException('Fixture/decode reported an audio error: '.$stderr);
    return $stdout;
}
function wav_file(string $path,int $rate,int $channels,int $bits,string $pcm,string $extra=''):void{
    $align=intdiv($channels*$bits,8);$fmt=pack('vvVVvv',1,$channels,$rate,$rate*$align,$align,$bits);
    $body='WAVEfmt '.pack('V',16).$fmt.$extra.'data'.pack('V',strlen($pcm)).$pcm.(strlen($pcm)&1?"\0":'');
    file_put_contents($path,'RIFF'.pack('V',strlen($body)).$body);
}
function excerpt(string $path,string $ext,int $start,int $end):array{global $files;$r=sf_song_audio_extract($path,$ext,$start,$end);$files[]=$r['path'];return $r;}
function tone_hz(array $samples,int $rate,float $start,float $seconds=.22):float{
    $from=(int)round($start*$rate);$to=min(count($samples),(int)round(($start+$seconds)*$rate));$crossings=0;
    for($i=max(1,$from);$i<$to;++$i)if($samples[$i-1]<=0&&$samples[$i]>0)++$crossings;
    return $crossings*$rate/max(1,$to-$from);
}
try{
    $pcm='';for($i=0;$i<32000;++$i)$pcm.=pack('v',$i&65535);
    $secret='PRIVATE-TITLE-DO-NOT-SEND';$junk='LIST'.pack('V',strlen($secret)).$secret.(strlen($secret)&1?"\0":'');
    wav_file($dir.'/pcm.wav',16000,1,16,$pcm,$junk);
    $before=glob(sys_get_temp_dir().'/sf-lyric-*')?:[];
    check(sf_song_audio_available($dir.'/pcm.wav','wav'),'PCM WAV readiness');
    check($before===(glob(sys_get_temp_dir().'/sf-lyric-*')?:[]),'readiness creates no temporary output');
    $w=excerpt($dir.'/pcm.wav','wav',500,1500);$bytes=file_get_contents($w['path']);
    check(substr($bytes,44)===substr($pcm,16000,32000),'WAV selects exact requested samples');
    check(!str_contains($bytes,$secret)&&strlen($bytes)===32044,'WAV removes metadata chunks');
    check(($w['start_ms']===500)&&($w['end_ms']===1500)&&$w['duration_ms']===1000&&$w['silent_lead_ms']===0,'WAV reports source timing');
    check((fileperms($w['path'])&0777)===0600,'temporary excerpt mode is 0600');
    foreach([[0,499],[0,90001],[-1,1000],[1000,1000],[1500,2500]] as [$s,$e])rejects(fn()=>sf_song_audio_extract($dir.'/pcm.wav','wav',$s,$e),'reject invalid/out-of-source interval '.$s.'-'.$e);
    foreach([['wav/../mp3',$dir.'/pcm.wav'],['mp3',$dir.'/pcm.wav'],['wav','php://memory'],['wav',$dir.'/missing']] as [$ext,$p])check(!sf_song_audio_available($p,$ext),'reject unsupported extension/source '.$ext.' '.basename($p));
    symlink($dir.'/pcm.wav',$dir.'/link.wav');check(!sf_song_audio_available($dir.'/link.wav','wav'),'reject direct source symlink');
    file_put_contents($dir.'/empty.wav','');check(!sf_song_audio_available($dir.'/empty.wav','wav'),'reject empty source');
    $bad=file_get_contents($dir.'/pcm.wav');$bad=substr_replace($bad,pack('V',0xffffffff),4,4);file_put_contents($dir.'/bad.wav',$bad);check(!sf_song_audio_available($dir.'/bad.wav','wav'),'reject truncated RIFF length');
    $bad=file_get_contents($dir.'/pcm.wav');$bad=substr_replace($bad,pack('v',7),32,2);file_put_contents($dir.'/bad.wav',$bad);check(!sf_song_audio_available($dir.'/bad.wav','wav'),'reject inconsistent WAV block alignment');
    $huge=fopen($dir.'/large.wav','wb');ftruncate($huge,SF_SONG_AUDIO_SOURCE_MAX+1);fclose($huge);check(!sf_song_audio_available($dir.'/large.wav','wav'),'reject source larger than 64 MiB');
    $length=48000000;$fmt=pack('vvVVvv',1,2,192000,768000,4,16);$huge=fopen($dir.'/output-limit.wav','wb');fwrite($huge,'RIFF'.pack('V',36+$length).'WAVEfmt '.pack('V',16).$fmt.'data'.pack('V',$length));ftruncate($huge,44+$length);fclose($huge);
    rejects(fn()=>sf_song_audio_extract($dir.'/output-limit.wav','wav',0,60000),'reject excerpt larger than 20 MiB');
    // A known silent MPEG-1 Layer III frame is enough for parser/error checks,
    // even on developer machines where fixture encoders are not installed.
    $frame="\xff\xfb\x90\x00".str_repeat("\0",413);$raw=str_repeat($frame,100);
    file_put_contents($dir.'/silent.mp3',$raw);check(sf_song_audio_available($dir.'/silent.mp3','mp3'),'raw unprotected Layer III readiness');
    $cut=excerpt($dir.'/silent.mp3','mp3',500,1500);check($cut['start_ms']>=500&&$cut['end_ms']<=1500,'raw MP3 frames round inward');
    foreach(['crc'=>"\xff\xfa\x90\x00",'free'=>"\xff\xfb\x00\x00",'layer2'=>"\xff\xfd\x90\x00",'reserved'=>"\xff\xfb\xfc\x00"] as $label=>$header){file_put_contents($dir.'/'.$label.'.mp3',$header.substr($raw,4));check(!sf_song_audio_available($dir.'/'.$label.'.mp3','mp3'),'reject '.$label.' MP3 header');}
    file_put_contents($dir.'/truncated.mp3',substr($raw,0,-1));rejects(fn()=>sf_song_audio_extract($dir.'/truncated.mp3','mp3',500,1500),'reject truncated final MP3 frame');
    file_put_contents($dir.'/corrupt.mp3',substr_replace($raw,'JUNK',2*417,4));rejects(fn()=>sf_song_audio_extract($dir.'/corrupt.mp3','mp3',500,1500),'reject corruption without resynchronizing past it');
    file_put_contents($dir.'/rate-change.mp3',substr_replace($raw,"\xff\xfb\x94\x00",2*417,4));rejects(fn()=>sf_song_audio_extract($dir.'/rate-change.mp3','mp3',500,1500),'reject MP3 sample-rate changes');
    file_put_contents($dir.'/reservoir.mp3',substr_replace($raw,"\xff\x80",4,2));rejects(fn()=>sf_song_audio_extract($dir.'/reservoir.mp3','mp3',500,1500),'reject missing initial MP3 reservoir');
    $id3Body='PRIVATE-ID3-SECRET';$tag='ID3'."\x04\x00\x10\x00\x00\x00".chr(strlen($id3Body));$footer='3DI'.substr($tag,3);
    file_put_contents($dir.'/tagged.mp3',$tag.$id3Body.$footer.$raw.'TAG'.str_pad($secret,125,"\0"));$tagged=excerpt($dir.'/tagged.mp3','mp3',500,1500);
    check(!str_contains(file_get_contents($tagged['path']),'PRIVATE-'),'MP3 strips ID3v2.4 footer and ID3v1 private tags');
    $apeBody='PRIVATE-APE-TITLE';$apeFooter='APETAGEX'.pack('VVVV',2000,32+strlen($apeBody),1,0).str_repeat("\0",8);
    file_put_contents($dir.'/ape.mp3',$raw.$apeBody.$apeFooter);$ape=excerpt($dir.'/ape.mp3','mp3',500,1500);check(!str_contains(file_get_contents($ape['path']),'PRIVATE-'),'MP3 strips APEv2 private tags');
    $it='iTunSMPB 00000000 00000840 00000000';file_put_contents($dir.'/itunes.mp3','ID3'."\x03\x00\x00\x00\x00\x00".chr(strlen($it)).$it.$raw);check(!sf_song_audio_available($dir.'/itunes.mp3','mp3'),'reject unsupported iTunes-only gapless timing');
    file_put_contents($dir.'/bad-id3.mp3','ID3'."\x04\x00\x00\x80\x00\x00\x00".$raw);check(!sf_song_audio_available($dir.'/bad-id3.mp3','mp3'),'reject non-synchsafe ID3 size');
    $before=glob(sys_get_temp_dir().'/sf-lyric-*')?:[];rejects(fn()=>sf_song_audio_extract($dir.'/silent.mp3','mp3',500,3000),'reject MP3 range past EOF after tentative extraction');check($before===(glob(sys_get_temp_dir().'/sf-lyric-*')?:[]),'failure removes tentative temporary excerpt');

    $ffmpeg='/usr/bin/ffmpeg';$ffprobe='/usr/bin/ffprobe';
    $hasTools=is_executable($ffmpeg)&&is_executable($ffprobe)&&function_exists('proc_open');
    if(!$hasTools&&getenv('SF_REQUIRE_AUDIO_DECODE_TESTS')==='1')throw new RuntimeException('FFmpeg/FFprobe required for decode acceptance.');
    if($hasTools){
        foreach([[44100,2,'cbr'],[44100,2,'vbr'],[48000,1,'cbr'],[22050,2,'vbr'],[16000,1,'cbr'],[11025,2,'vbr'],[8000,1,'cbr']] as [$rate,$channels,$mode]){
            $name=$rate.'-'.$channels.'-'.$mode;$source=$dir.'/'.$name.'.mp3';
            $filter='aevalsrc=0.2*sin(2*PI*if(lt(t\,2)\,330\,if(lt(t\,5)\,880\,1760))*t):s='.$rate.':d=8';
            $command=[$ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-f','lavfi','-i',$filter,'-ac',(string)$channels,'-c:a','libmp3lame'];
            $command=array_merge($command,$mode==='vbr'?['-q:a','2']:['-b:a',$rate>=32000?'128k':'32k'],['-metadata','title='.$secret,'-y',$source]);
            run_audio($command);check(sf_song_audio_available($source,'mp3'),$name.' encoded source readiness');
            $r=excerpt($source,'mp3',2000,5000);
            check($r['start_ms']>=2000&&$r['start_ms']-2000<100&&$r['end_ms']<=5000&&5000-$r['end_ms']<100,$name.' reported actual bounds stay inside selected audio');
            check(!str_contains(file_get_contents($r['path']),$secret),$name.' private title never enters excerpt');
            $out=$dir.'/'.$name.'.pcm';run_audio([$ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-xerror','-err_detect','explode','-i',$r['path'],'-ac','1','-c:a','pcm_f32le','-f','f32le','-y',$out]);
            $samples=array_values(unpack('g*',file_get_contents($out)));$duration=count($samples)*1000/$rate;
            check(abs($duration-$r['audio_duration_ms'])<2,$name.' independent decoder confirms serialized sample duration');
            $lead=(int)round($r['silent_lead_ms']*$rate/1000);$max=0.;foreach(array_slice($samples,0,max(0,$lead-2)) as $v)$max=max($max,abs($v));
            check($max<0.000001,$name.' reservoir seed decodes only silence');
            foreach([.15,.7,1.5,2.65] as $at)check(abs(tone_hz($samples,$rate,$at)-880)<12,$name.' selected audio has inside frequency at '.$at.'s');
            $probe=json_decode(run_audio([$ffprobe,'-v','error','-count_frames','-show_entries','stream=sample_rate,nb_read_frames','-of','json',$r['path']]),true);
            $spf=$rate>=32000?1152:576;$frames=(int)($probe['streams'][0]['nb_read_frames']??0);
            check($frames*$spf===count($samples),$name.' FFprobe independently counts every output frame');
            rejects(fn()=>sf_song_audio_extract($source,'mp3',7500,8500),$name.' rejects gapless end overrun');
        }
        // A continuously changing pitch proves the selected timestamp, not just
        // that some decodable audio exists somewhere in the song.
        $source=$dir.'/chirp.mp3';run_audio([$ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-f','lavfi','-i','aevalsrc=0.2*sin(2*PI*(220*t+40*t*t)):s=44100:d=8','-c:a','libmp3lame','-q:a','2','-y',$source]);
        $r=excerpt($source,'mp3',3000,6000);$out=$dir.'/chirp.pcm';run_audio([$ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-xerror','-i',$r['path'],'-ac','1','-c:a','pcm_f32le','-f','f32le','-y',$out]);$samples=array_values(unpack('g*',file_get_contents($out)));
        foreach([.3,1.,2.] as $at){$sourceAt=$r['start_ms']/1000+$at+.11-$r['silent_lead_ms']/1000;check(abs(tone_hz($samples,44100,$at)-(220+80*$sourceAt))<12,'chirp matches selected source timestamp at '.$at.'s');}
        $code='require '.var_export(dirname(__DIR__).'/api/agent-song-audio-excerpt.php',true).';echo json_encode(sf_song_audio_extract('.var_export($source,true).',"mp3",2000,5000));';
        $child=json_decode(run_audio([PHP_BINARY,'-d','disable_functions=exec,shell_exec,system,passthru,proc_open,popen','-r',$code]),true);$files[]=$child['path'];
        check(is_file($child['path'])&&$child['start_ms']>=2000,'production MP3 extraction works with every execution function disabled');
    }else echo "SKIP real MP3 codec acceptance (FFmpeg/FFprobe unavailable; CI must set SF_REQUIRE_AUDIO_DECODE_TESTS=1)\n";
    echo json_encode(['checks'=>$checks,'passed'=>$checks,'real_codec_acceptance'=>$hasTools],JSON_UNESCAPED_SLASHES)."\n";
}finally{
    foreach($files as $file)@unlink($file);
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
}
