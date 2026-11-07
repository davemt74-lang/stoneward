<?php
declare(strict_types=1);

/** National Weather Service adapter for selected public locations. */
function sf_canvas_weather_read(float $lat,float $lon,string $product,?callable $transport=null,?int $now=null,?string $cacheDir=null): array {
    if(!is_finite($lat)||!is_finite($lon)||abs($lat)>90||abs($lon)>180||
        !in_array($product,['current','hourly','daily','alerts'],true))
        throw new InvalidArgumentException('Choose a valid public weather location and product.');
    $adapter=new SfCanvasNwsWeather($cacheDir??SF_ROOT.'/storage/canvas-weather/v1',$now,$transport);
    return $adapter->read(sprintf('%.4F,%.4F',$lat,$lon),$product);
}

final class SfCanvasNwsWeather {
    private const OBSERVATION_AGE=5400;
    private const OBSERVATION_RETENTION=21600;
    private const FORECAST_AGE=86400;
    private const MAX_RESPONSE=1572864;
    private const UPSTREAM_PER_MINUTE=48;
    private readonly ?Closure $transport;
    private readonly int $deadline;

    public function __construct(private readonly string $directory,private readonly ?int $clock,?callable $transport) {
        $this->transport=$transport===null?null:Closure::fromCallable($transport);
        $this->deadline=hrtime(true)+16_000_000_000;
    }

    private function now(): int {
        return $this->clock??time();
    }

    public function read(string $coordinate,string $product): array {
        $result=['status'=>'unavailable','source'=>'National Weather Service','timezone'=>null,
            'fetched_at'=>null,'expires_at'=>null,'rows'=>[]];
        if(!is_dir($this->directory)&&!@mkdir($this->directory,0700,true)&&!is_dir($this->directory))return $result;
        $ttl=match($product){'current'=>120,'alerts'=>60,default=>300};
        // A failed request may reuse weather for display, but never mark that copy fresh.
        $staleFor=match($product){'current'=>3600,'alerts'=>0,default=>1800};
        $entry=$this->cached('result',$product.':'.$coordinate,$ttl,$staleFor,
            fn():?array=>$this->loadProduct($coordinate,$product));
        if($entry===null)return $result;
        $value=$entry['value'];
        $rows=$value['rows']??null;
        if(!is_array($rows)||!array_is_list($rows)||array_filter($rows,static fn(mixed $row):bool=>!is_array($row)))return $result;
        if(in_array($product,['hourly','daily','alerts'],true)){
            $field=$product==='alerts'?'expires_at':'end_at';
            $rows=array_values(array_filter($rows,fn(array $row):bool=>
                (self::parseTime($row[$field]??null)??0)>$this->now()&&
                ($product!=='alerts'||($row['ends_at']??null)===null||(self::parseTime($row['ends_at'])??0)>$this->now())));
        }
        if($product!=='alerts'&&!$rows)return $result;
        $now=$this->now();
        $fresh=$entry['expires']>$now&&($this->transport!==null||$this->remainingMilliseconds()>0);
        return ['status'=>$fresh?'fresh':'stale',
            'source'=>'National Weather Service','timezone'=>is_string($value['timezone']??null)?$value['timezone']:null,
            'fetched_at'=>gmdate('c',$entry['fetched']),'expires_at'=>gmdate('c',$fresh?$entry['expires']:min($now,$entry['expires'])),
            'rows'=>$rows];
    }

    private function loadProduct(string $coordinate,string $product): ?array {
        $point=$this->cached('point',$coordinate,21600,0,function()use($coordinate):?array {
            $response=$this->request('/points/'.$coordinate);
            $properties=$response['body']['properties']??[];
            $office=$properties['gridId']??null;
            $x=$properties['gridX']??null;
            $y=$properties['gridY']??null;
            if(!is_string($office)||preg_match('/\A[A-Z]{3}\z/',$office)!==1||
                !is_int($x)||!is_int($y)||min($x,$y)<0||max($x,$y)>9999)return null;
            $timezone=$properties['timeZone']??null;
            if(!is_string($timezone)||!in_array($timezone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))$timezone=null;
            return ['value'=>['grid'=>'/gridpoints/'.$office.'/'.$x.','.$y,'timezone'=>$timezone],
                ...$response['cache']];
        });
        // A successful NWS points lookup confirms coverage for all four products.
        if($point===null)return null;
        $grid=$point['value']['grid'];
        $timezone=$point['value']['timezone'];
        if($product==='current')return $this->loadCurrent($grid,$timezone);

        $path=match($product){
            'alerts'=>'/alerts?active=true&point='.$coordinate,
            'hourly'=>$grid.'/forecast/hourly',
            default=>$grid.'/forecast',
        };
        $response=$this->request($path);
        if($response===null)return null;
        if($product==='alerts'){
            $rows=$this->alerts($response['body']);
            if($rows===null)return null;
            return ['value'=>['timezone'=>$timezone,'rows'=>$rows],...$response['cache']];
        }
        $properties=$response['body']['properties']??[];
        $issued=self::parseTime($properties['updateTime']??$properties['updated']??null);
        $periods=$properties['periods']??null;
        if($issued===null||$issued>$this->now()||$issued+self::FORECAST_AGE<=$this->now()||
            !is_array($periods)||!array_is_list($periods))return null;
        $rows=[];
        $limit=$product==='hourly'?48:14;
        foreach($periods as $period){
            if(!is_array($period))continue;
            $row=$this->forecastPeriod($period);
            if($row!==null)$rows[]=$row;
            if(count($rows)>=$limit)break;
        }
        if(!$rows)return null;
        return ['value'=>['timezone'=>$timezone,'rows'=>$rows],...$response['cache'],
            'valid_until'=>$issued+self::FORECAST_AGE];
    }

    private function loadCurrent(string $grid,?string $timezone): ?array {
        $stations=$this->cached('stations',$grid,21600,0,function()use($grid):?array {
            $response=$this->request($grid.'/stations');
            $features=$response['body']['features']??[];
            if(!is_array($features)||!array_is_list($features))return null;
            $ids=[];
            foreach($features as $feature){
                $id=$feature['properties']['stationIdentifier']??null;
                if(is_string($id)&&preg_match('/\A[A-Z0-9]{3,12}\z/',$id)===1)$ids[$id]=$id;
                if(count($ids)>=2)break;
            }
            return $ids?['value'=>array_values($ids),...$response['cache']]:null;
        });
        if($stations===null)return null;
        $best=null;
        foreach($stations['value'] as $station){
            $response=$this->request('/stations/'.$station.'/observations/latest?require_qc=true');
            $properties=$response['body']['properties']??null;
            if(!is_array($properties))continue;
            $row=$this->observation($properties,$station);
            if($row===null)continue;
            $observed=self::parseTime($row['observed_at']);
            if($best!==null&&$observed<=$best['observed'])continue;
            $best=['observed'=>$observed,'value'=>['timezone'=>$timezone,'rows'=>[$row]],...$response['cache'],
                'valid_until'=>$observed+self::OBSERVATION_AGE,'discard_at'=>$observed+self::OBSERVATION_RETENTION];
            if($best['valid_until']>$this->now())break;
        }
        return $best;
    }

    private function observation(array $properties,string $station): ?array {
        $observed=self::parseTime($properties['timestamp']??null);
        if($observed===null||$observed>$this->now()||$observed+self::OBSERVATION_RETENTION<=$this->now())return null;
        $temperature=self::temperature($properties['temperature']??null);
        if($temperature===null)return null;
        $apparent=self::temperature($properties['heatIndex']??null)??self::temperature($properties['windChill']??null);
        $humidity=self::quantity($properties['relativeHumidity']??null,['wmoUnit:percent'=>1],0,100);
        $wind=self::quantity($properties['windSpeed']??null,['wmoUnit:km_h-1'=>0.621371,'wmoUnit:m_s-1'=>2.236936,'wmoUnit:kt'=>1.150779],0,350);
        $direction=self::quantity($properties['windDirection']??null,['wmoUnit:degree_(angle)'=>1],0,360);
        $precipitation=self::quantity($properties['precipitationLastHour']??null,['wmoUnit:m'=>39.370079,'wmoUnit:mm'=>0.039370079],0,50);
        return ['station_id'=>$station,'observed_at'=>gmdate('c',$observed),
            'temperature_f'=>(int)round($temperature*1.8+32),'temperature_c'=>round($temperature,1),
            'condition'=>self::safeText($properties['textDescription']??null,180),
            'feels_like_f'=>$apparent===null?null:(int)round($apparent*1.8+32),
            'relative_humidity_percent'=>$humidity===null?null:round($humidity,1),
            'wind_mph'=>$wind===null?null:round($wind,1),
            'wind_direction_degrees'=>$direction===null?null:(int)round($direction),
            'precipitation_1h_inches'=>$precipitation===null?null:round($precipitation,3)];
    }

    private function forecastPeriod(array $period): ?array {
        $start=self::parseTime($period['startTime']??null);
        $end=self::parseTime($period['endTime']??null);
        if($start===null||$end===null||$end<=$start||$end<=$this->now()||$start>$this->now()+8*86400)return null;
        $value=$period['temperature']??null;
        if(is_int($value)||is_float($value)){
            $unit=$period['temperatureUnit']??null;
            $value=['value'=>$value,'unitCode'=>is_string($unit)?'wmoUnit:deg'.$unit:''];
        }
        $temperature=self::temperature($value);
        $probability=self::quantity($period['probabilityOfPrecipitation']??null,['wmoUnit:percent'=>1],0,100);
        return ['name'=>self::safeText($period['name']??null,80),
            'start_at'=>gmdate('c',$start),'end_at'=>gmdate('c',$end),
            'is_daytime'=>is_bool($period['isDaytime']??null)?$period['isDaytime']:null,
            'temperature_f'=>$temperature===null?null:(int)round($temperature*1.8+32),
            'temperature_c'=>$temperature===null?null:round($temperature,1),
            'condition'=>self::safeText($period['shortForecast']??null,180),
            'detailed_forecast'=>self::safeText($period['detailedForecast']??null,1200),
            'wind_speed'=>self::safeText($period['windSpeed']??null,80),
            'wind_direction'=>self::safeText($period['windDirection']??null,40),
            'precipitation_probability_percent'=>$probability===null?null:round($probability,1)];
    }

    private function alerts(array $body): ?array {
        $features=$body['features']??null;
        if(!is_array($features)||!array_is_list($features))return null;
        $rows=[];
        foreach($features as $feature){
            $properties=$feature['properties']??null;
            if(!is_array($properties))return null;
            $status=$properties['status']??null;
            $type=$properties['messageType']??null;
            if(!in_array($status,['Actual','Exercise','System','Test','Draft'],true)||
                !in_array($type,['Alert','Update','Cancel','Ack','Error'],true))return null;
            if($status!=='Actual'||!in_array($type,['Alert','Update'],true))continue;
            $id=self::safeText($properties['id']??null,220);
            $expires=self::parseTime($properties['expires']??null);
            $ends=self::parseTime($properties['ends']??null);
            if($id===null||$expires===null||self::safeText($properties['event']??null,220)===null)return null;
            if($expires<=$this->now()||($ends!==null&&$ends<=$this->now()))continue;
            $row=['id'=>$id];
            foreach(['event','severity','certainty','urgency','headline','description','instruction'] as $field)
                $row[$field]=self::safeText($properties[$field]??null,in_array($field,['description','instruction'],true)?1200:220);
            foreach(['sent','effective','onset','expires','ends'] as $field){
                $timestamp=self::parseTime($properties[$field]??null);
                $row[$field.'_at']=$timestamp===null?null:gmdate('c',$timestamp);
            }
            $rows[]=$row;
            if(count($rows)>=20)break;
        }
        return $rows;
    }

    private static function temperature(mixed $quantity): ?float {
        if(!is_array($quantity)||in_array($quantity['qualityControl']??null,['X','Q','B'],true))return null;
        $value=$quantity['value']??null;
        if(!is_int($value)&&!is_float($value))return null;
        $celsius=match($quantity['unitCode']??null){
            'wmoUnit:degC'=>(float)$value,
            'wmoUnit:degF'=>($value-32)/1.8,
            default=>null,
        };
        return $celsius!==null&&is_finite($celsius)&&$celsius>=-100&&$celsius<=70?$celsius:null;
    }

    private static function quantity(mixed $quantity,array $units,float $min,float $max): ?float {
        if(!is_array($quantity)||in_array($quantity['qualityControl']??null,['X','Q','B'],true))return null;
        $value=$quantity['value']??null;
        $unit=$quantity['unitCode']??null;
        if((!is_int($value)&&!is_float($value))||!is_string($unit)||!isset($units[$unit]))return null;
        $normalized=$value*$units[$unit];
        return is_finite($normalized)&&$normalized>=$min&&$normalized<=$max?$normalized:null;
    }

    private static function parseTime(mixed $value): ?int {
        if(!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/',$value)!==1)return null;
        try{
            $date=new DateTimeImmutable($value);
            $errors=DateTimeImmutable::getLastErrors();
            return $errors===false||(!$errors['warning_count']&&!$errors['error_count'])?$date->getTimestamp():null;
        }catch(Exception){return null;}
    }

    private static function safeText(mixed $value,int $maximum): ?string {
        if(!is_string($value))return null;
        $clean=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value);
        if($clean===null||preg_match('/\A.{0,'.$maximum.'}/us',trim($clean),$match)!==1||$match[0]==='')return null;
        return $match[0];
    }

    /** Hashed cache slots bound disk use. Locks avoid parallel upstream refreshes. */
    private function cached(string $group,string $key,int $ttl,int $staleFor,callable $load): ?array {
        $path=$this->directory.'/'.$group.'-'.substr(hash('sha256',$key),0,2);
        $entry=$this->readEntry($path,$key);
        if(($entry['expires']??0)>$this->now())return $entry;
        $lock=@fopen($path.'.lock','c');
        if($lock===false)return $this->usable($entry);
        try{
            while(!flock($lock,LOCK_EX|LOCK_NB)){
                // An independent product can be waiting on the same first point lookup.
                // Reuse displayable data immediately, or wait for the peer's atomic write.
                $usable=$this->usable($this->readEntry($path,$key));
                if($usable!==null||$this->remainingMilliseconds()<100)return $usable;
                usleep(50_000);
            }
            $entry=$this->readEntry($path,$key);
            if(($entry['expires']??0)>$this->now()||($entry['retry_at']??0)>$this->now())return $this->usable($entry);
            $loaded=$load();
            $now=$this->now();
            if($loaded===null){
                $entry??=['key'=>$key,'value'=>null,'fetched'=>0,'expires'=>0,'discard_at'=>0];
                $entry['retry_at']=$now+30;
            }else{
                $age=min($ttl,$loaded['max_age']??$ttl);
                $expires=min($now+$age,$loaded['valid_until']??PHP_INT_MAX);
                $staleAge=empty($loaded['no_stale'])?$staleFor:0;
                $discard=min($now+$age+$staleAge,$loaded['discard_at']??PHP_INT_MAX);
                $entry=['key'=>$key,'value'=>$loaded['value'],'fetched'=>$now,'expires'=>$expires,
                    'discard_at'=>$discard,'retry_at'=>$expires<=$now?$now+30:0];
                if(!empty($loaded['no_store'])){
                    // Delete older data for this key too, so a later failure cannot replay it.
                    @unlink($path.'.json');
                    return $this->usable($entry);
                }
            }
            $this->writeEntry($path,$entry);
            return $this->usable($entry);
        }finally{fclose($lock);}
    }

    private function usable(?array $entry): ?array {
        return is_array($entry['value']??null)&&$entry['discard_at']>$this->now()?$entry:null;
    }

    private function readEntry(string $path,string $key): ?array {
        $raw=@file_get_contents($path.'.json',false,null,0,131073);
        if(!is_string($raw)||strlen($raw)>131072)return null;
        $entry=json_decode($raw,true,16);
        return is_array($entry)&&($entry['key']??null)===$key&&
            is_int($entry['expires']??null)&&is_int($entry['fetched']??null)&&
            is_int($entry['discard_at']??null)&&is_int($entry['retry_at']??null)?$entry:null;
    }

    private function writeEntry(string $path,array $entry): void {
        $data=json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($data)||strlen($data)>131072)return;
        $temp=$path.'.tmp';
        if(@file_put_contents($temp,$data)===strlen($data)&&@rename($temp,$path.'.json'))return;
        @unlink($temp);
    }

    /** One quota spans all aliases, canvases, cities and PHP processes on this host. */
    private function quota(int $backoff=0): bool {
        $file=@fopen($this->directory.'/upstream-quota.json','c+');
        if($file===false)return false;
        try{
            if(!flock($file,$backoff>0?LOCK_EX:LOCK_EX|LOCK_NB))return false;
            $raw=stream_get_contents($file,8193);
            $prior=is_string($raw)&&strlen($raw)<=8192?json_decode($raw,true):null;
            $times=array_values(array_filter((array)($prior['times']??[]),fn(mixed $time):bool=>
                is_int($time)&&$time>$this->now()-60&&$time<=$this->now()));
            $blocked=is_int($prior['blocked']??null)?$prior['blocked']:0;
            if($backoff>0)$blocked=max($blocked,$this->now()+$backoff);
            else{
                if($blocked>$this->now()||count($times)>=self::UPSTREAM_PER_MINUTE)return false;
                $times[]=$this->now();
            }
            $encoded=json_encode(['times'=>$times,'blocked'=>$blocked],JSON_THROW_ON_ERROR);
            return rewind($file)&&ftruncate($file,0)&&fwrite($file,$encoded)===strlen($encoded)&&fflush($file);
        }finally{fclose($file);}
    }

    private function request(string $path): ?array {
        $coordinate='-?[0-9]{1,3}\.[0-9]{4},-?[0-9]{1,3}\.[0-9]{4}';
        if(preg_match('~\A/(?:points/'.$coordinate.'|gridpoints/[A-Z]{3}/[0-9]{1,4},[0-9]{1,4}/(?:stations|forecast(?:/hourly)?)|stations/[A-Z0-9]{3,12}/observations/latest\?require_qc=true|alerts\?active=true&point='.$coordinate.')\z~',$path)!==1)
            return null;
        if(($this->transport===null&&$this->remainingMilliseconds()<100)||!$this->quota())return null;
        try{$response=$this->transport===null?$this->https($path):($this->transport)($path);}
        catch(RuntimeException){return null;}
        if(($response['code']??null)===429||($response['code']??null)===503){
            $retry=$response['retry_after']??null;
            if(($response['code']??null)===429||is_int($retry))$this->quota(max(5,min(300,is_int($retry)?$retry:30)));
        }
        if(($response['code']??null)!==200||!is_array($response['body']??null))return null;
        $maxAge=$response['max_age']??null;
        return ['body'=>$response['body'],'cache'=>[
            'max_age'=>is_int($maxAge)?max(0,$maxAge):null,
            'no_store'=>($response['no_store']??null)===true,
            'no_stale'=>($response['no_stale']??null)===true,
        ]];
    }

    private function remainingMilliseconds(): int {
        return max(0,intdiv($this->deadline-hrtime(true),1_000_000));
    }

    private function https(string $path): array {
        $remaining=$this->remainingMilliseconds();
        if($remaining<100||!function_exists('curl_init'))return [];
        $curl=curl_init('https://api.weather.gov'.$path);
        if($curl===false)return [];
        $userAgent=getenv('STONEFELLOW_NWS_USER_AGENT');
        if(!is_string($userAgent)||preg_match('/\A[\x20-\x7E]{4,240}\z/',$userAgent)!==1)
            $userAgent='Stonefellow Weather Canvas/1.0 (https://github.com/davemt74-lang/stoneward)';
        $raw='';$headers=[];
        $options=[CURLOPT_HTTPHEADER=>['Accept: application/geo+json'],CURLOPT_USERAGENT=>$userAgent,
            CURLOPT_CONNECTTIMEOUT_MS=>min(4500,$remaining),CURLOPT_TIMEOUT_MS=>min(6000,$remaining),CURLOPT_NOSIGNAL=>true,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_ENCODING=>'',
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int {
                if(strlen($raw)+strlen($chunk)>self::MAX_RESPONSE)return 0;
                $raw.=$chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers):int {
                if(str_starts_with($line,'HTTP/'))$headers=[];
                elseif(preg_match('/\A(cache-control|age|retry-after):\s*([^\r\n]{0,300})/i',$line,$match)===1)
                    $headers[strtolower($match[1])]=trim($match[2]);
                return strlen($line);
            }];
        if(defined('CURLOPT_PROTOCOLS_STR'))$options[CURLOPT_PROTOCOLS_STR]='https';
        else $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
        try{
            curl_setopt_array($curl,$options);
            $ok=curl_exec($curl);
            $code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        }finally{curl_close($curl);}
        if($ok===false)return ['code'=>$code];
        $age=isset($headers['age'])&&ctype_digit($headers['age'])?(int)$headers['age']:0;
        $control=$headers['cache-control']??'';
        $noStore=preg_match('/(?:^|,)\s*(?:no-store|no-cache)\b/i',$control)===1;
        $noStale=preg_match('/(?:^|,)\s*(?:must-revalidate|proxy-revalidate)\b/i',$control)===1;
        $maxAge=null;
        if(preg_match_all('/(?:^|,)\s*(?:s-maxage|max-age)="?([0-9]{1,9})"?/i',$control,$match)>0)
            $maxAge=max(0,min(array_map('intval',$match[1]))-$age);
        $retry=$headers['retry-after']??'';
        $retryAfter=ctype_digit($retry)?(int)$retry:null;
        if($retryAfter===null&&$retry!==''&&($retryAt=strtotime($retry))!==false)$retryAfter=max(0,$retryAt-$this->now());
        return ['code'=>$code,'body'=>$code===200?json_decode($raw,true,48):null,'max_age'=>$maxAge,'retry_after'=>$retryAfter,
            'no_store'=>$noStore,'no_stale'=>$noStale];
    }
}
