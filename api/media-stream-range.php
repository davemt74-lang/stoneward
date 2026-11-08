<?php
declare(strict_types=1);

/** Parse a single HTTP byte range for local media playback, including suffix ranges. */
function sf_media_stream_range(string $header, int $size): array {
    if ($size < 1) throw new InvalidArgumentException('Empty media asset.');
    $start = 0;
    $end = $size - 1;
    $partial = false;
    if ($header !== '') {
        if (strlen($header) > 100) throw new InvalidArgumentException('Invalid range header.');
        if (preg_match('/^bytes=([0-9]+)-([0-9]*)$/D', $header, $match)) {
            $start = (int)$match[1];
            if ($match[2] !== '') $end = min($end, (int)$match[2]);
        } elseif (preg_match('/^bytes=-([0-9]+)$/D', $header, $match) && (int)$match[1] > 0) {
            $start = max(0, $size - (int)$match[1]);
        } else {
            throw new InvalidArgumentException('Unsupported range header.');
        }
        if ($start > $end || $start >= $size) throw new InvalidArgumentException('Range not satisfiable.');
        $partial = true;
    }
    return ['start' => $start, 'end' => $end, 'length' => $end - $start + 1, 'partial' => $partial];
}

/** Stream an authorized MP3, returning an HTTP error only if the source cannot be opened safely. */
function sf_media_stream_mp3(array $source, string $method): int {
    $file=@fopen($source['path'],'rb');
    if($file===false)return 404;
    $stat=fstat($file);
    if(!$stat||($stat['mode']&0170000)!==0100000||$stat['dev']!==$source['device']||$stat['ino']!==$source['inode']||$stat['size']<1){
        fclose($file);
        return 404;
    }
    $size=(int)$stat['size'];
    if(session_status()===PHP_SESSION_ACTIVE&&!session_write_close()){
        fclose($file);
        return 503;
    }
    @ini_set('zlib.output_compression','0');
    if(function_exists('apache_setenv'))@apache_setenv('no-gzip','1');
    if(function_exists('set_time_limit'))@set_time_limit(0);
    while(ob_get_level()>0){
        $state=ob_get_status();
        if(!((int)($state['flags']??0)&PHP_OUTPUT_HANDLER_REMOVABLE))break;
        @ob_end_clean();
    }
    try{$range=sf_media_stream_range((string)($_SERVER['HTTP_RANGE']??''),$size);}
    catch(InvalidArgumentException){
        fclose($file);
        header('Content-Range: bytes */'.$size);
        header('Cache-Control: private, no-store');
        http_response_code(416);
        exit;
    }
    http_response_code($range['partial']?206:200);
    header('Content-Type: audio/mpeg');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store, no-transform');
    header('Content-Length: '.$range['length']);
    if($range['partial'])header('Content-Range: bytes '.$range['start'].'-'.$range['end'].'/'.$size);
    if($method!=='HEAD'){
        fseek($file,$range['start']);
        $left=$range['length'];
        while($left>0&&!feof($file)&&!connection_aborted()){
            $chunk=fread($file,min(65536,$left));
            if($chunk===false||$chunk==='')break;
            echo $chunk;
            $left-=strlen($chunk);
            if(function_exists('flush'))@flush();
        }
    }
    fclose($file);
    exit;
}
