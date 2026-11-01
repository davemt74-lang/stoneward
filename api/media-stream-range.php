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
