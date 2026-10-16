<?php
declare(strict_types=1);
/**
 * 87C1: bounded, read-only engagement signal normalization.
 * Inputs must originate from trusted Stonefellow server-side readers.
 * Neither browser-supplied identities nor marketing consent are trusted here.
 */
function sf_engagement_normalize_events(array $events, array $publicTrackIds, ?int $ownerId, bool $listeningConsent): array {
    if ($ownerId === null || $ownerId < 1) return [];
    $public = array_fill_keys(array_map('strval', $publicTrackIds), true);
    $types = ['listen'=>true,'save'=>true,'cart'=>true,'paid_purchase'=>true,'vinyl_interest'=>true];
    $seen = []; $out = [];
    foreach (array_slice($events, 0, 5000) as $e) {
        if (!is_array($e) || (int)($e['user_id'] ?? 0) !== $ownerId) continue;
        $type = (string)($e['type'] ?? '');
        $track = (string)($e['track_id'] ?? '');
        if (!isset($types[$type], $public[$track])) continue;
        if (($type === 'listen' || $type === 'save') && !$listeningConsent) continue;
        if ($type === 'paid_purchase' && ($e['payment_verified'] ?? false) !== true) continue;
        $id = (string)($e['event_id'] ?? '');
        if ($id === '' || strlen($id) > 128 || isset($seen[$type.'|'.$id])) continue;
        $at = (string)($e['occurred_at'] ?? '');
        try { $date = new DateTimeImmutable($at); }
        catch (Exception $ex) { continue; }
        if ($at === '' || $date->getTimestamp() > time() + 300) continue;
        $seen[$type.'|'.$id] = true;
        $out[] = ['type'=>$type, 'track_id'=>$track, 'occurred_at'=>$date->format(DateTimeInterface::ATOM)];
    }
    return $out;
}
/** Aggregates engagement without revenue estimates, identity disclosure or inferred consent. */
function sf_engagement_summary(array $normalized): array {
    $byTrack = [];
    foreach ($normalized as $e) {
        $track = (string)$e['track_id']; $type = (string)$e['type'];
        if (!isset($byTrack[$track])) $byTrack[$track] = [
            'listen'=>0, 'save'=>0, 'cart'=>0, 'paid_purchase'=>0, 'vinyl_interest'=>0
        ];
        if (array_key_exists($type, $byTrack[$track])) $byTrack[$track][$type]++;
    }
    ksort($byTrack, SORT_STRING);
    return ['tracks'=>$byTrack, 'event_count'=>count($normalized),
        'interpretation'=>'Observed owner-scoped events, not unique fans, conversion rates, or revenue.'];
}
