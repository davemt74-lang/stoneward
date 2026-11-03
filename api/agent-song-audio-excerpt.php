<?php
declare(strict_types=1);
/**
 * 91B21B4: ephemeral, bounded excerpts from a separately authorized LOCAL song.
 * No executable, provider request, microphone, database or persistent media copy.
 * The caller must authorize the exact catalog source before calling this module,
 * and unlink the returned path in finally after provider processing.
 *
 * WAV: PCM 8/16/24/32-bit, mono/stereo, including PCM WAVEFORMATEXTENSIBLE.
 * MP3: MPEG-1/2/2.5 Layer III, CBR/VBR, all channel modes, consistent rate/channels.
 * CRC-protected/free-format MP3 and VBRI are deliberately rejected. Xing/Info
 * and recognized LAME/Lavc/Lavf gapless timing are read, never copied as tags.
 * iTunes-only gapless timing is rejected instead of guessing its time offset.
 *
 * MP3 cuts round INWARD to complete audio frames (12-72 ms), not byte estimates.
 * A synthetic all-zero-side-information frame decodes to silence and seeds the
 * decoder's bit reservoir with the bytes required by the first selected frame.
 * It contains no earlier audio granules. No source preroll frame is emitted.
 * See FFmpeg libavcodec/mpegaudiodec_template.c, mp_decode_layer3(): side-info
 * part2_3_length determines decoded data; main_data_begin addresses retained
 * main-data bytes. Independent FFmpeg decode is exercised by the regression.
 * Reset synthesis overlap can soften the first few milliseconds of the cut.
 * silent_lead_ms/audio_duration_ms disclose the extra silent frame; start_ms and
 * end_ms describe ONLY the selected source audio, never that decoding lead-in.
 */
const SF_SONG_AUDIO_SOURCE_MAX = 67108864;
const SF_SONG_AUDIO_OUTPUT_MAX = 20971520;

/** @return resource */
function sf_song_audio_open(string $path, string $extension) {
    if (!in_array($extension, ['mp3', 'wav'], true) || $path === '' || str_contains($path, "\0")
        || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) || !str_starts_with($path, '/')
        || is_link($path) || !is_file($path) || !is_readable($path)) {
        throw new InvalidArgumentException('A supported, readable local song file is required.');
    }
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) throw new RuntimeException('The song source could not be opened.');
    $stat = fstat($handle);
    if (!$stat || (($stat['mode'] & 0170000) !== 0100000)
        || $stat['size'] < 44 || $stat['size'] > SF_SONG_AUDIO_SOURCE_MAX) {
        fclose($handle);
        throw new InvalidArgumentException('The song source is empty or exceeds the 64 MiB processing limit.');
    }
    return $handle;
}

/** @param resource $handle */
function sf_song_audio_read($handle, int $length): string {
    if ($length < 0 || $length > SF_SONG_AUDIO_OUTPUT_MAX) throw new RuntimeException('Invalid audio read.');
    $result = '';
    while (strlen($result) < $length) {
        $part = fread($handle, $length - strlen($result));
        if ($part === false || $part === '') throw new InvalidArgumentException('The song source is truncated.');
        $result .= $part;
    }
    return $result;
}

/** @param resource $handle */
function sf_song_audio_wav_info($handle): array {
    rewind($handle);
    $size = (int) fstat($handle)['size'];
    $head = sf_song_audio_read($handle, 12);
    if (substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
        throw new InvalidArgumentException('The source is not a supported PCM WAV.');
    }
    $limit = (int) unpack('V', substr($head, 4, 4))[1] + 8;
    if ($limit < 44 || $limit > $size) throw new InvalidArgumentException('The WAV container is truncated.');
    $fmt = null; $data = null; $chunks = 0;
    while (ftell($handle) + 8 <= $limit) {
        if (++$chunks > 256) throw new InvalidArgumentException('The WAV has too many chunks.');
        $chunk = sf_song_audio_read($handle, 8);
        $length = (int) unpack('V', substr($chunk, 4, 4))[1];
        $offset = ftell($handle); $next = $offset + $length + ($length & 1);
        if ($next > $limit) throw new InvalidArgumentException('The WAV chunk is truncated.');
        if (substr($chunk, 0, 4) === 'fmt ') {
            if ($fmt !== null || $length < 16 || $length > 4096) throw new InvalidArgumentException('Invalid WAV format.');
            $raw = sf_song_audio_read($handle, $length);
            $fmt = unpack('vformat/vchannels/Vrate/Vbyte_rate/valign/vbits', substr($raw, 0, 16));
            if ($fmt['format'] === 65534) {
                $pcmGuid = "\x01\x00\x00\x00\x00\x00\x10\x00\x80\x00\x00\xaa\x00\x38\x9b\x71";
                if ($length < 40 || unpack('v', substr($raw, 16, 2))[1] < 22
                    || substr($raw, 24, 16) !== $pcmGuid
                    || unpack('v', substr($raw, 18, 2))[1] !== $fmt['bits']) {
                    throw new InvalidArgumentException('Only ordinary PCM WAV samples are supported.');
                }
                $fmt['format'] = 1;
            }
            if ($fmt['format'] !== 1 || !in_array($fmt['channels'], [1, 2], true)
                || !in_array($fmt['bits'], [8, 16, 24, 32], true)
                || $fmt['rate'] < 8000 || $fmt['rate'] > 192000
                || $fmt['align'] !== intdiv($fmt['channels'] * $fmt['bits'], 8)
                || $fmt['byte_rate'] !== $fmt['rate'] * $fmt['align']) {
                throw new InvalidArgumentException('Unsupported or inconsistent PCM WAV format.');
            }
        } elseif (substr($chunk, 0, 4) === 'data') {
            if ($data !== null || $length < 1) throw new InvalidArgumentException('Invalid WAV audio data.');
            $data = ['offset' => $offset, 'bytes' => $length];
        }
        if (fseek($handle, $next) !== 0) throw new RuntimeException('The WAV source could not be read.');
    }
    if ($fmt === null || $data === null || ($data['bytes'] % $fmt['align']) !== 0) {
        throw new InvalidArgumentException('The WAV has no complete PCM audio.');
    }
    return $fmt + $data + ['samples' => intdiv($data['bytes'], $fmt['align'])];
}

function sf_song_audio_mp3_header(string $raw): array {
    if (strlen($raw) !== 4 || ord($raw[0]) !== 255 || (ord($raw[1]) & 224) !== 224) {
        throw new InvalidArgumentException('The MP3 contains an invalid frame header.');
    }
    $b = ord($raw[1]); $c = ord($raw[2]); $d = ord($raw[3]);
    $version = ($b >> 3) & 3; $bitrate = ($c >> 4) & 15; $rateIndex = ($c >> 2) & 3;
    if ($version === 1 || (($b >> 1) & 3) !== 1 || !($b & 1)
        || $bitrate === 0 || $bitrate === 15 || $rateIndex === 3 || ($d & 3) === 2) {
        throw new InvalidArgumentException('MP3 needs unprotected, non-free-format MPEG Layer III frames.');
    }
    $rates = [44100, 48000, 32000];
    $rate = intdiv($rates[$rateIndex], $version === 3 ? 1 : ($version === 2 ? 2 : 4));
    $bitrates = $version === 3
        ? [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320]
        : [0,8,16,24,32,40,48,56,64,80,96,112,128,144,160];
    $channels = ($d >> 6) === 3 ? 1 : 2;
    $side = $version === 3 ? ($channels === 1 ? 17 : 32) : ($channels === 1 ? 9 : 17);
    $length = intdiv(($version === 3 ? 144000 : 72000) * $bitrates[$bitrate], $rate) + (($c >> 1) & 1);
    if ($length <= 4 + $side) throw new InvalidArgumentException('The MP3 frame has no audio payload.');
    return ['version' => $version, 'rate' => $rate, 'channels' => $channels, 'side' => $side,
        'length' => $length, 'samples' => $version === 3 ? 1152 : 576, 'header' => $raw];
}

function sf_song_audio_bits(string $raw, int &$offset, int $length): int {
    if ($offset + $length > strlen($raw) * 8) throw new InvalidArgumentException('Truncated MP3 side information.');
    $value = 0;
    for ($i = 0; $i < $length; ++$i, ++$offset) {
        $value = ($value << 1) | ((ord($raw[intdiv($offset, 8)]) >> (7 - ($offset & 7))) & 1);
    }
    return $value;
}

function sf_song_audio_mp3_side(string $frame, array $header): array {
    $side = substr($frame, 4, $header['side']); $offset = 0;
    $back = sf_song_audio_bits($side, $offset, $header['version'] === 3 ? 9 : 8);
    $offset += $header['version'] === 3 ? ($header['channels'] === 1 ? 5 : 3) : $header['channels'];
    if ($header['version'] === 3) $offset += 4 * $header['channels'];
    $total = 0;
    for ($g = 0; $g < ($header['version'] === 3 ? 2 : 1); ++$g) {
        for ($ch = 0; $ch < $header['channels']; ++$ch) {
            $total += sf_song_audio_bits($side, $offset, 12);
            if (sf_song_audio_bits($side, $offset, 9) > 288) throw new InvalidArgumentException('Invalid MP3 sample count.');
            $offset += 8 + ($header['version'] === 3 ? 4 : 9);
            $switched = sf_song_audio_bits($side, $offset, 1);
            if ($switched) {
                if (sf_song_audio_bits($side, $offset, 2) === 0) throw new InvalidArgumentException('Invalid MP3 block type.');
                $offset += 1;
                $tables = 2;
            } else $tables = 3;
            for ($t = 0; $t < $tables; ++$t) {
                if (in_array(sf_song_audio_bits($side, $offset, 5), [4, 14], true)) {
                    throw new InvalidArgumentException('Invalid MP3 Huffman table.');
                }
            }
            $offset += $switched ? 9 : 7;
            $offset += $header['version'] === 3 ? 3 : 2;
        }
    }
    $payloadBytes = $header['length'] - 4 - $header['side'];
    if ($total > ($back + $payloadBytes) * 8) throw new InvalidArgumentException('The MP3 bit reservoir is inconsistent.');
    return ['back' => $back, 'bits' => $total];
}

/** Locate audio; inspect tag markers locally but never copy them. @param resource $handle */
function sf_song_audio_mp3_bounds($handle): array {
    $size = (int) fstat($handle)['size']; $start = 0; $end = $size; $itunes = false;
    rewind($handle); $first = sf_song_audio_read($handle, 10);
    if (substr($first, 0, 3) === 'ID3') {
        $version = ord($first[3]); $flags = ord($first[5]);
        if (!in_array($version, [2, 3, 4], true) || ord($first[4]) === 255
            || ($flags & ($version === 2 ? 63 : ($version === 3 ? 31 : 15)))) {
            throw new InvalidArgumentException('Invalid MP3 ID3 header.');
        }
        $tagBytes = 0;
        for ($i = 6; $i < 10; ++$i) {
            $byte = ord($first[$i]);
            if ($byte & 128) throw new InvalidArgumentException('Invalid MP3 ID3 size.');
            $tagBytes = ($tagBytes << 7) | $byte;
        }
        $start = 10 + $tagBytes;
        if ($start > $size) throw new InvalidArgumentException('Truncated MP3 ID3 data.');
        // FFmpeg/Apple can use iTunSMPB when no LAME delay exists. That offset
        // must not silently be treated as zero. Bounded streaming also detects
        // UTF-16 marker strings without retaining album art/private tag bodies.
        $tail = '';
        for ($left = $tagBytes; $left > 0; $left -= $take) {
            $take = min($left, 65536);
            $marker = $tail . str_replace("\0", '', sf_song_audio_read($handle, $take));
            if (str_contains($marker, 'iTunSMPB')) $itunes = true;
            $tail = substr($marker, -16);
        }
        if ($version === 4 && ($flags & 16)) {
            if ($start + 10 > $size) throw new InvalidArgumentException('Truncated MP3 ID3 footer.');
            fseek($handle, $start); $footer = sf_song_audio_read($handle, 10);
            if (substr($footer, 0, 3) !== '3DI' || substr($footer, 3) !== substr($first, 3)) {
                throw new InvalidArgumentException('Invalid MP3 ID3 footer.');
            }
            $start += 10;
        }
    }
    if ($end >= 128) { fseek($handle, $end - 128); if (fread($handle, 3) === 'TAG') $end -= 128; }
    if ($end >= 32) {
        fseek($handle, $end - 32); $footer = sf_song_audio_read($handle, 32);
        if (substr($footer, 0, 8) === 'APETAGEX') {
            $ape = unpack('Vversion/Vsize/Vitems/Vflags', substr($footer, 8, 16));
            if (!in_array($ape['version'], [1000, 2000], true) || $ape['size'] < 32 || $ape['size'] > $end - $start) {
                throw new InvalidArgumentException('Invalid MP3 APE footer.');
            }
            $end -= $ape['size'];
            if ($ape['flags'] & 0x80000000) {
                if ($end - 32 < $start) throw new InvalidArgumentException('Truncated MP3 APE header.');
                fseek($handle, $end - 32);
                if (sf_song_audio_read($handle, 8) !== 'APETAGEX') throw new InvalidArgumentException('Invalid MP3 APE header.');
                $end -= 32;
            }
        }
    }
    if ($end - $start < 8) throw new InvalidArgumentException('No MP3 audio frames remain.');
    // Some encoders put zero padding after ID3. No arbitrary-junk resynchronizing.
    fseek($handle, $start);
    for ($n = 0; $n < 4096 && $start < $end; ++$n) {
        $byte = sf_song_audio_read($handle, 1);
        if ($byte !== "\0") break;
        ++$start;
    }
    fseek($handle, $start);
    return ['start' => $start, 'end' => $end, 'itunes_gapless' => $itunes];
}

/** @param resource $handle */
function sf_song_audio_mp3_frame($handle, int $end, ?array $format): array {
    $at = ftell($handle);
    if ($at + 4 > $end) throw new InvalidArgumentException('Truncated MP3 frame.');
    $header = sf_song_audio_mp3_header(sf_song_audio_read($handle, 4));
    if ($format !== null && ($header['version'] !== $format['version'] || $header['rate'] !== $format['rate']
        || $header['channels'] !== $format['channels'])) {
        throw new InvalidArgumentException('The MP3 changes its sample format.');
    }
    if ($at + $header['length'] > $end) throw new InvalidArgumentException('The MP3 ends inside a frame.');
    $frame = $header['header'] . sf_song_audio_read($handle, $header['length'] - 4);
    return ['frame' => $frame, 'info' => $header, 'side' => sf_song_audio_mp3_side($frame, $header)];
}

function sf_song_audio_mp3_timing(array $frame): array {
    $raw = $frame['frame']; $h = $frame['info']; $data = substr($raw, 4 + $h['side']);
    if (substr($raw, 36, 4) === 'VBRI') throw new InvalidArgumentException('VBRI MP3 timing is not supported for bounded excerpts.');
    if (!in_array(substr($data, 0, 4), ['Xing', 'Info'], true)) return ['tag' => false, 'lead' => 0, 'padding' => 0, 'frames' => null];
    if (strlen($data) < 8 || $frame['side']['bits'] !== 0 || $frame['side']['back'] !== 0) {
        throw new InvalidArgumentException('Invalid MP3 timing header.');
    }
    $flags = unpack('N', substr($data, 4, 4))[1];
    if ($flags & ~15) throw new InvalidArgumentException('Unsupported MP3 timing header.');
    $p = 8; $frames = null;
    foreach ([1 => 4, 2 => 4, 4 => 100, 8 => 4] as $flag => $length) {
        if (!($flags & $flag)) continue;
        if ($p + $length > strlen($data)) throw new InvalidArgumentException('Truncated MP3 timing header.');
        if ($flag === 1) $frames = (int) unpack('N', substr($data, $p, 4))[1];
        $p += $length;
    }
    $lead = 0; $padding = 0;
    if ($p + 24 <= strlen($data) && in_array(substr($data, $p, 4), ['LAME', 'Lavc', 'Lavf'], true)) {
        $v = (ord($data[$p + 21]) << 16) | (ord($data[$p + 22]) << 8) | ord($data[$p + 23]);
        $delay = $v >> 12; $padding = $v & 4095;
        // Layer III synthesis adds 528+1 samples, as in FFmpeg's MP3 demuxer.
        $lead = $delay + 529;
        $padding = max(0, $padding - 529);
    }
    return ['tag' => true, 'lead' => $lead, 'padding' => $padding, 'frames' => $frames];
}

/** Fast readiness: bounded header reads only; no output file or provider call. */
function sf_song_audio_available(string $path, string $extension): bool {
    $handle = null;
    try {
        $handle = sf_song_audio_open($path, $extension);
        if ($extension === 'wav') { sf_song_audio_wav_info($handle); return true; }
        $bounds = sf_song_audio_mp3_bounds($handle);
        $first = sf_song_audio_mp3_frame($handle, $bounds['end'], null);
        $timing = sf_song_audio_mp3_timing($first);
        if ($bounds['itunes_gapless'] && $timing['lead'] === 0) return false;
        sf_song_audio_mp3_frame($handle, $bounds['end'], $first['info']);
        return true;
    } catch (Throwable $e) { return false; }
    finally { if (is_resource($handle)) fclose($handle); }
}

/** @return array{0:string,1:resource} */
function sf_song_audio_temp(): array {
    $path = @tempnam(sys_get_temp_dir(), 'sf-lyric-');
    if ($path === false) throw new RuntimeException('Temporary audio storage is unavailable.');
    if (!@chmod($path, 0600)) { @unlink($path); throw new RuntimeException('Temporary audio could not be secured.'); }
    $handle = @fopen($path, 'wb');
    if (!is_resource($handle)) { @unlink($path); throw new RuntimeException('Temporary audio could not be opened.'); }
    return [$path, $handle];
}

/** @param resource $handle */
function sf_song_audio_write($handle, string $bytes): void {
    if (ftell($handle) + strlen($bytes) > SF_SONG_AUDIO_OUTPUT_MAX) throw new InvalidArgumentException('The audio excerpt exceeds 20 MiB.');
    $offset = 0;
    while ($offset < strlen($bytes)) {
        $written = fwrite($handle, substr($bytes, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('Temporary audio could not be written.');
        $offset += $written;
    }
}

function sf_song_audio_mp3_clean(string $frame, array $h): string {
    // Private/copyright flags are not needed for a temporary analysis stream.
    $frame[2] = chr(ord($frame[2]) & 254);
    $frame[3] = chr(ord($frame[3]) & 243);
    $mask = $h['version'] === 3 ? ($h['channels'] === 1 ? 131 : 143) : ($h['channels'] === 1 ? 127 : 63);
    $frame[5] = chr(ord($frame[5]) & $mask);
    return $frame;
}

function sf_song_audio_extract(string $path, string $extension, int $startMs, int $endMs): array {
    if ($startMs < 0 || $endMs > 86400000 || $endMs <= $startMs || $endMs - $startMs < 500 || $endMs - $startMs > 90000) {
        throw new InvalidArgumentException('Choose a song section lasting 0.5 to 90 seconds.');
    }
    $input = null; $output = null; $temporary = null; $success = false;
    try {
        $input = sf_song_audio_open($path, $extension);
        if ($extension === 'wav') {
            $w = sf_song_audio_wav_info($input);
            $from = intdiv($startMs * $w['rate'] + 999, 1000);
            $to = intdiv($endMs * $w['rate'], 1000);
            if ($to > $w['samples'] || $to <= $from) throw new InvalidArgumentException('The selected section extends beyond the song audio.');
            $length = ($to - $from) * $w['align'];
            if ($length + 44 > SF_SONG_AUDIO_OUTPUT_MAX) throw new InvalidArgumentException('The audio excerpt exceeds 20 MiB.');
            [$temporary, $output] = sf_song_audio_temp();
            $fmt = pack('vvVVvv', 1, $w['channels'], $w['rate'], $w['byte_rate'], $w['align'], $w['bits']);
            sf_song_audio_write($output, 'RIFF' . pack('V', 36 + $length + ($length & 1)) . 'WAVEfmt ' . pack('V', 16) . $fmt . 'data' . pack('V', $length));
            fseek($input, $w['offset'] + $from * $w['align']);
            for ($left = $length; $left > 0; $left -= $take) {
                $take = min($left, 65536);
                sf_song_audio_write($output, sf_song_audio_read($input, $take));
            }
            if ($length & 1) sf_song_audio_write($output, "\0");
            $actualStart = (int) round($from * 1000 / $w['rate']); $actualEnd = (int) round($to * 1000 / $w['rate']);
            $silence = 0; $serialized = (int) round(($to - $from) * 1000 / $w['rate']);
        } else {
            $bounds = sf_song_audio_mp3_bounds($input);
            $first = sf_song_audio_mp3_frame($input, $bounds['end'], null);
            $h = $first['info']; $timing = sf_song_audio_mp3_timing($first);
            if ($bounds['itunes_gapless'] && $timing['lead'] === 0) {
                throw new InvalidArgumentException('iTunes-only gapless MP3 timing is not supported for bounded excerpts.');
            }
            if (!$timing['tag']) fseek($input, $bounds['start']);
            $firstSample = intdiv($startMs * $h['rate'] + 999, 1000) + $timing['lead'];
            $lastSample = intdiv($endMs * $h['rate'], 1000) + $timing['lead'];
            $from = intdiv($firstSample + $h['samples'] - 1, $h['samples']);
            $to = intdiv($lastSample, $h['samples']);
            if ($to <= $from) throw new InvalidArgumentException('No complete MP3 frames fit this section.');
            $tail = ''; $mainBytes = 0; $previousEndBits = 0; $count = 0; $selected = 0;
            while (ftell($input) < $bounds['end']) {
                if ($count >= 250000) throw new InvalidArgumentException('The MP3 exceeds the frame-processing limit.');
                $part = sf_song_audio_mp3_frame($input, $bounds['end'], $h);
                $back = $part['side']['back']; $bits = $part['side']['bits'];
                $data = substr($part['frame'], 4 + $h['side']);
                $beginBits = ($mainBytes - $back) * 8;
                if ($back > strlen($tail) || $beginBits < $previousEndBits) {
                    throw new InvalidArgumentException('The MP3 references unavailable or already consumed audio data.');
                }
                if ($count >= $from && $count < $to) {
                    if ($selected === 0) {
                        [$temporary, $output] = sf_song_audio_temp();
                        // Highest legal bitrate leaves room for the complete <=511-byte reservoir.
                        $seedHeader = $part['frame'][0] . chr(ord($part['frame'][1]) | 1)
                            . chr((ord($part['frame'][2]) & 12) | 224) . chr(ord($part['frame'][3]) & 243);
                        $seedInfo = sf_song_audio_mp3_header($seedHeader);
                        $capacity = $seedInfo['length'] - 4 - $h['side'];
                        if ($back > $capacity) throw new InvalidArgumentException('The MP3 reservoir exceeds the silent seed capacity.');
                        $seed = $seedHeader . str_repeat("\0", $h['side'] + $capacity - $back)
                            . ($back === 0 ? '' : substr($tail, -$back));
                        sf_song_audio_write($output, $seed);
                    }
                    sf_song_audio_write($output, sf_song_audio_mp3_clean($part['frame'], $h));
                    ++$selected;
                }
                $mainBytes += strlen($data); $previousEndBits = $beginBits + $bits;
                $tail = substr($tail . $data, -($h['version'] === 3 ? 511 : 255));
                ++$count;
            }
            $audibleSamples = $count * $h['samples'] - $timing['lead'] - $timing['padding'];
            if (($timing['frames'] !== null && $timing['frames'] !== $count)
                || $lastSample - $timing['lead'] > $audibleSamples || $selected !== $to - $from || $selected < 1) {
                throw new InvalidArgumentException('The selected section extends beyond verified MP3 audio.');
            }
            $actualStart = (int) round(($from * $h['samples'] - $timing['lead']) * 1000 / $h['rate']);
            $actualEnd = (int) round(($to * $h['samples'] - $timing['lead']) * 1000 / $h['rate']);
            $silence = (int) round($h['samples'] * 1000 / $h['rate']);
            $serialized = (int) round(($selected + 1) * $h['samples'] * 1000 / $h['rate']);
        }
        if (!is_resource($output) || !fflush($output)) throw new RuntimeException('The temporary excerpt could not be finalized.');
        $success = true;
        return ['path' => $temporary, 'mime' => $extension === 'mp3' ? 'audio/mpeg' : 'audio/wav',
            'extension' => $extension, 'start_ms' => $actualStart, 'end_ms' => $actualEnd,
            'duration_ms' => $actualEnd - $actualStart, 'silent_lead_ms' => $silence,
            'audio_duration_ms' => $serialized, 'timing' => $extension === 'mp3' ? 'complete_frames_inward' : 'pcm_samples_inward'];
    } finally {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        if (!$success && is_string($temporary)) @unlink($temporary);
    }
}
