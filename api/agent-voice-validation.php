<?php
declare(strict_types=1);
/**
 * Validate a bounded MPEG audio response before sending bytes or debiting tokens.
 * Accept ID3 headers or an MPEG audio frame sync prefix.
 */
function sf_agent_voice_valid_mp3(string $audio): bool {
    $length=strlen($audio);
    if($length<64||$length>12*1024*1024)return false;
    if(substr($audio,0,3)==='ID3')return true;
    return ord($audio[0])===0xff && (ord($audio[1])&0xe0)===0xe0;
}
