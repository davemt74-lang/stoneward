<?php
declare(strict_types=1);
/** Section 91B8B — server-side contract for an explicitly submitted song clip. */
function sf_song_transcribe_request(array $raw,array $published):array{
    $id=(string)($raw['track_id']??'');
    if($id===''||strlen($id)>180)throw new InvalidArgumentException('Valid song identity required.');
    $matching=array_values(array_filter($published,static fn($t)=>
        (string)($t['id']??'')===$id&&!empty($t['audio'])));
    if(count($matching)!==1)throw new InvalidArgumentException('Published playable song not found.');
    $start=$raw['start_ms']??null;$end=$raw['end_ms']??null;
    if(!is_scalar($start)||!is_scalar($end)||!ctype_digit((string)$start)||!ctype_digit((string)$end))
        throw new InvalidArgumentException('Capture timestamps required.');
    $start=(int)$start;$end=(int)$end;
    if($start<0||$end<=$start||$end>24*3600*1000||$end-$start<500||$end-$start>90000)
        throw new InvalidArgumentException('Capture must be a continuous section from 0.5 to 90 seconds.');
    $capturedAt=trim((string)($raw['captured_at']??''));
    if($capturedAt===''||strlen($capturedAt)>50||strtotime($capturedAt)===false)
        throw new InvalidArgumentException('Capture time required.');
    $track=$matching[0];
    return ['track_id'=>$id,'title'=>substr((string)($track['title']??''),0,180),
        'release'=>substr((string)($track['release']??''),0,180),
        'start_ms'=>$start,'end_ms'=>$end,'duration_ms'=>$end-$start,
        'captured_at'=>$capturedAt];
}
function sf_song_transcribe_mime(string $mime):string{
    $supported=['audio/webm','video/webm','audio/mp4','audio/x-m4a','video/mp4','audio/mpeg','audio/ogg','audio/wav','audio/x-wav'];
    if(!in_array($mime,$supported,true))throw new InvalidArgumentException('Unsupported captured audio format.');
    return $mime;
}
function sf_song_transcribe_draft(array $metadata,array $provider,array $result):array{
    $raw=trim((string)($result['text']??''));
    if($raw==='')throw new RuntimeException('Transcription provider returned no lyrics.');
    $clean=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$raw)??'';
    $text=trim(function_exists('mb_substr')?mb_substr($clean,0,4200):substr($clean,0,4200));
    if($text==='')throw new RuntimeException('Transcription contains no usable words.');
    return ['track_id'=>$metadata['track_id'],'title'=>$metadata['title'],
        'release'=>$metadata['release'],'start_ms'=>$metadata['start_ms'],
        'end_ms'=>$metadata['end_ms'],'captured_at'=>$metadata['captured_at'],
        'text'=>$text,'origin'=>'machine_draft','review_status'=>'unverified',
        'timing'=>'whole_clip_estimate','provider'=>(string)$provider['provider'],
        'model'=>(string)$provider['model']];
}
