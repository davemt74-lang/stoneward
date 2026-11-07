<?php
declare(strict_types=1);

const SF_CANVAS_EARTHQUAKES_SOURCE='U.S. Geological Survey';

/** Read an approved USGS summary feed without forwarding any viewer input. */
function sf_canvas_earthquakes_read(string $feed,?callable $transport=null,?int $now=null,?string $cacheDir=null): array {
    $filename=match($feed){
        'm25-day'=>'2.5_day.geojson',
        'm25-week'=>'2.5_week.geojson',
        'm45-day'=>'4.5_day.geojson',
        'm45-week'=>'4.5_week.geojson',
        default=>throw new InvalidArgumentException('Choose an approved USGS earthquake feed.'),
    };
    $adapter=new SfCanvasUsgsEarthquakes($cacheDir??SF_ROOT.'/storage/canvas-earthquakes/v1',$now,$transport);
    return $adapter->read($filename);
}

final class SfCanvasUsgsEarthquakes {
    private const FEED_URL='https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/';
    private const EVENT_URL='https://earthquake.usgs.gov/earthquakes/eventpage/';
    private const FRESH_SECONDS=60;
    private const STALE_SECONDS=900;
    private const MAX_RESPONSE=1_572_864;
    private const MAX_CACHE=131_072;
    private const MAX_ROWS=120;
    private readonly int $deadline;
    private readonly ?Closure $transport;

    public function __construct(private readonly string $directory,private readonly ?int $clock,?callable $transport) {
        $this->deadline=hrtime(true)+8_000_000_000;
        $this->transport=$transport===null?null:Closure::fromCallable($transport);
    }

    public function read(string $filename): array {
        $unavailable=['status'=>'unavailable','source'=>SF_CANVAS_EARTHQUAKES_SOURCE,
            'fetched_at'=>null,'expires_at'=>null,'timezone'=>null,'rows'=>[]];
        if(preg_match('/\A[24]\.5_(?:day|week)\.geojson\z/',$filename)!==1||
            (!is_dir($this->directory)&&!@mkdir($this->directory,0700,true)&&!is_dir($this->directory)))return $unavailable;
        $entry=$this->cached($filename);
        $now=$this->now();
        if(!$this->usable($entry))return $unavailable;
        return ['status'=>$entry['expires']>$now?'fresh':'stale','source'=>SF_CANVAS_EARTHQUAKES_SOURCE,
            'fetched_at'=>gmdate('c',$entry['fetched']),'expires_at'=>gmdate('c',$entry['expires']),
            'timezone'=>'UTC','rows'=>$entry['rows']];
    }

    private function now(): int {return $this->clock??time();}

    private function remainingMilliseconds(): int {
        return max(0,intdiv($this->deadline-hrtime(true),1_000_000));
    }

    private function usable(?array $entry): bool {
        return is_array($entry['rows']??null)&&$entry['discard']>$this->now();
    }

    /** The four fixed slots coalesce concurrent requests across all viewers. */
    private function cached(string $filename): ?array {
        $path=$this->directory.'/'.$filename.'.cache';
        $entry=$this->readEntry($path);
        if(($entry['retry_at']??0)>$this->now()||($entry['expires']??0)>$this->now())return $entry;
        $lock=@fopen($path.'.lock','c');
        if($lock===false)return $entry;
        try{
            while(!flock($lock,LOCK_EX|LOCK_NB)){
                $entry=$this->readEntry($path);
                if($this->usable($entry)||$this->remainingMilliseconds()<100)return $entry;
                usleep(50_000);
            }
            $entry=$this->readEntry($path);
            if(($entry['retry_at']??0)>$this->now()||($entry['expires']??0)>$this->now())return $entry;
            if($this->remainingMilliseconds()<100)return $entry;

            try{
                $response=$this->transport===null?$this->https($filename):($this->transport)(self::FEED_URL.$filename);
            }catch(RuntimeException){
                $response=[];
            }
            $now=$this->now();
            $loaded=($response['code']??null)===200&&is_array($response['body']??null)?
                $this->normalize($response['body'],$now):null;
            if($loaded===null||$loaded['generated']<($entry['generated']??0)){
                $entry??=['rows'=>null,'generated'=>0,'fetched'=>0,'expires'=>0,'discard'=>0];
                $retry=$response['retry_after']??null;
                $entry['retry_at']=$now+(is_int($retry)?max(5,min(300,$retry)):30);
            }else{
                $ttl=$response['max_age']??null;
                $ttl=is_int($ttl)?max(0,min(self::FRESH_SECONDS,$ttl)):self::FRESH_SECONDS;
                $expires=min($now+$ttl,intdiv($loaded['generated'],1000)+self::FRESH_SECONDS);
                $stale=($response['no_stale']??false)===true?0:self::STALE_SECONDS;
                $entry=$loaded+['fetched'=>$now,'expires'=>$expires,'discard'=>$expires+$stale,
                    'retry_at'=>$expires<=$now?$now+30:0];
                if(($response['no_store']??false)===true){
                    @unlink($path);
                    return $entry;
                }
            }
            $encoded=json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(is_string($encoded)&&strlen($encoded)<=self::MAX_CACHE){
                $temp=$path.'.tmp';
                if(@file_put_contents($temp,$encoded)!==strlen($encoded)||!@rename($temp,$path))@unlink($temp);
            }
            return $entry;
        }finally{fclose($lock);}
    }

    private function readEntry(string $path): ?array {
        $raw=@file_get_contents($path,false,null,0,self::MAX_CACHE+1);
        if(!is_string($raw)||strlen($raw)>self::MAX_CACHE)return null;
        $entry=json_decode($raw,true,16);
        return is_array($entry)&&is_int($entry['generated']??null)&&is_int($entry['fetched']??null)&&
            is_int($entry['expires']??null)&&is_int($entry['discard']??null)&&is_int($entry['retry_at']??null)?$entry:null;
    }

    private function normalize(array $body,int $now): ?array {
        $features=$body['features']??null;
        $metadata=$body['metadata']??null;
        $generated=$metadata['generated']??null;
        if(($body['type']??null)!=='FeatureCollection'||!is_array($features)||!array_is_list($features)||
            ($metadata['status']??null)!==200||($metadata['count']??null)!==count($features)||
            !is_int($generated)||$generated>($now+120)*1000||
            $generated<=($now-self::FRESH_SECONDS-self::STALE_SECONDS)*1000)return null;

        $byId=[];$withdrawn=[];$recognized=false;$hasEarthquakes=false;
        foreach($features as $feature){
            $id=$feature['id']??null;$properties=$feature['properties']??null;$type=$properties['type']??null;
            if(($feature['type']??null)!=='Feature'||!is_string($id)||preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/',$id)!==1||
                !is_string($type)||preg_match('/\A[a-z][a-z0-9 -]{0,39}\z/',$type)!==1)continue;
            if($type!=='earthquake'){
                $recognized=true;
                continue;
            }
            if(($properties['status']??null)==='deleted'){
                $updated=$properties['updated']??null;
                if(is_int($updated)&&$updated>0&&$updated<=$generated+120_000){
                    $recognized=true;
                    $withdrawn[$id]=max($withdrawn[$id]??0,$updated);
                }
                continue;
            }
            $hasEarthquakes=true;
            $row=self::event($id,$feature,$properties,$generated);
            if($row===null)continue;
            $recognized=true;
            $prior=$byId[$id]??null;
            if($prior===null||$row['updated_at']>$prior['updated_at']||
                ($row['updated_at']===$prior['updated_at']&&strcmp(serialize($row),serialize($prior))>0))$byId[$id]=$row;
        }
        if(($features&&!$recognized)||($hasEarthquakes&&!$byId))return null;
        foreach($withdrawn as $id=>$updated){
            if(isset($byId[$id])&&self::isoTime($updated)>=$byId[$id]['updated_at'])unset($byId[$id]);
        }
        $rows=array_values($byId);
        usort($rows,static fn(array $a,array $b):int=>strcmp($b['occurred_at'],$a['occurred_at'])?:strcmp($a['event_id'],$b['event_id']));
        return ['generated'=>$generated,'rows'=>array_slice($rows,0,self::MAX_ROWS)];
    }

    private static function event(string $id,array $feature,array $properties,int $generated): ?array {
        $geometry=$feature['geometry']??null;$coordinates=$geometry['coordinates']??null;
        $occurred=$properties['time']??null;$updated=$properties['updated']??null;
        $magnitude=$properties['mag']??null;
        if(($geometry['type']??null)!=='Point'||!is_array($coordinates)||!array_is_list($coordinates)||count($coordinates)!==3||
            !self::number($magnitude,-5,12)||!is_int($occurred)||!is_int($updated)||$occurred<1||$occurred>$generated||
            $updated<$occurred||$updated>$generated+120_000)return null;
        [$lon,$lat,$depth]=$coordinates;
        if(!self::number($lon,-180,180)||!self::number($lat,-90,90)||!self::number($depth,-50,1000))return null;
        $place=$properties['place']??null;
        $place=is_string($place)?trim(preg_replace('/[\p{C}\s]+/u',' ',$place)??''):'';
        $magnitudeType=$properties['magType']??null;
        $status=$properties['status']??null;
        return ['event_id'=>$id,'place'=>preg_match('/\A.{1,180}/us',$place,$text)===1?$text[0]:null,
            'magnitude'=>$magnitude,'magnitude_type'=>is_string($magnitudeType)&&
                preg_match('/\A[A-Za-z0-9_-]{1,12}\z/',$magnitudeType)===1?$magnitudeType:null,
            'lat'=>$lat,'lon'=>$lon,'depth_km'=>$depth,'occurred_at'=>self::isoTime($occurred),
            'updated_at'=>self::isoTime($updated),'review_status'=>in_array($status,['automatic','reviewed'],true)?$status:null,
            'source_url'=>self::EVENT_URL.$id];
    }

    private static function number(mixed $value,float $min,float $max): bool {
        return (is_int($value)||is_float($value))&&is_finite($value)&&$value>=$min&&$value<=$max;
    }

    private static function isoTime(int $milliseconds): string {
        return gmdate('Y-m-d\TH:i:s',intdiv($milliseconds,1000)).sprintf('.%03dZ',$milliseconds%1000);
    }

    private function https(string $filename): array {
        $remaining=$this->remainingMilliseconds();
        if($remaining<100||!function_exists('curl_init'))return [];
        $curl=curl_init(self::FEED_URL.$filename);
        if($curl===false)return [];
        $raw='';$headers=[];
        $options=[CURLOPT_HTTPHEADER=>['Accept: application/geo+json, application/json'],
            CURLOPT_USERAGENT=>'Stonefellow Canvas Data/1.0 (https://github.com/davemt74-lang/stoneward)',
            CURLOPT_CONNECTTIMEOUT_MS=>min(6000,$remaining),CURLOPT_TIMEOUT_MS=>min(7500,$remaining),CURLOPT_NOSIGNAL=>true,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_ENCODING=>'',
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int {
                if(strlen($raw)+strlen($chunk)>self::MAX_RESPONSE)return 0;
                $raw.=$chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers):int {
                if(str_starts_with($line,'HTTP/'))$headers=[];
                elseif(preg_match('/\A(cache-control|age|retry-after):\s*([^\r\n]{0,300})/i',$line,$match)===1){
                    $name=strtolower($match[1]);
                    $value=trim($match[2]);
                    $headers[$name]=$name==='cache-control'&&isset($headers[$name])?$headers[$name].','.$value:$value;
                }
                return strlen($line);
            }];
        if(defined('CURLOPT_PROTOCOLS_STR'))$options[CURLOPT_PROTOCOLS_STR]='https';
        else $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
        try{
            curl_setopt_array($curl,$options);
            $ok=curl_exec($curl);
            $code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        }finally{curl_close($curl);}
        $age=ctype_digit($headers['age']??'')?(int)$headers['age']:0;
        $control=$headers['cache-control']??'';
        $maxAge=preg_match_all('/(?:^|,)\s*(?:s-maxage|max-age)="?([0-9]{1,9})"?/i',$control,$matches)>0?
            max(0,min(array_map('intval',$matches[1]))-$age):null;
        $retry=$headers['retry-after']??'';
        $retryAt=$retry!==''&&!ctype_digit($retry)?strtotime($retry):false;
        return ['code'=>$code,'body'=>$ok!==false&&$code===200?json_decode($raw,true,32):null,'max_age'=>$maxAge,
            'retry_after'=>ctype_digit($retry)?(int)$retry:($retryAt===false?null:max(0,$retryAt-$this->now())),
            'no_store'=>preg_match('/(?:^|,)\s*(?:private|no-store|no-cache)\b/i',$control)===1,
            'no_stale'=>preg_match('/(?:^|,)\s*(?:must-revalidate|proxy-revalidate)\b/i',$control)===1];
    }
}
