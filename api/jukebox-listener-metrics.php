<?php
declare(strict_types=1);

const SF_JUKEBOX_LISTENER_ACTIVE_MS = 12000;
const SF_JUKEBOX_LISTENER_CREDIT_MS = 6000;

/** Install outside the session transaction, as with the other jukebox tables. */
function sf_jukebox_listener_schema(bool $mysql,string $engine): void {
    $activeIndex=$mysql?', INDEX jukebox_listener_active (session_id,active_until_ms)':'';
    sf_db()->exec("CREATE TABLE IF NOT EXISTS jukebox_listener_players (
        session_id CHAR(32) NOT NULL, player_hash CHAR(64) NOT NULL, phone_id CHAR(32) NULL,
        last_seq BIGINT NOT NULL, item_id BIGINT NOT NULL, generation BIGINT NOT NULL,
        sampled_at_ms BIGINT NOT NULL, active_until_ms BIGINT NOT NULL, listening_ms BIGINT NOT NULL,
        PRIMARY KEY(session_id,player_hash)$activeIndex)$engine");
    if(!$mysql)sf_db()->exec('CREATE INDEX IF NOT EXISTS jukebox_listener_active ON jukebox_listener_players (session_id,active_until_ms)');
    sf_db()->exec("CREATE TABLE IF NOT EXISTS jukebox_listener_totals (
        session_id CHAR(32) PRIMARY KEY, unique_players BIGINT NOT NULL,
        peak_players BIGINT NOT NULL, listening_ms BIGINT NOT NULL)$engine");
}

function sf_jukebox_listener_invalidate(string $id): void {
    sf_jukebox_query('UPDATE jukebox_listener_players SET active_until_ms=0,sampled_at_ms=0,phone_id=NULL
        WHERE session_id=? AND (sampled_at_ms>0 OR phone_id IS NOT NULL)',[$id]);
}

function sf_jukebox_listener_clear_expired(string $id,int $now): void {
    // Phone IDs are used for live revocation only; no historical identity is needed.
    sf_jukebox_query('UPDATE jukebox_listener_players SET phone_id=NULL
        WHERE session_id=? AND active_until_ms>0 AND active_until_ms<=? AND phone_id IS NOT NULL',[$id,$now]);
}

function sf_jukebox_listener_active(array $session,int $now): int {
    $public=$session['listed']?" OR l.phone_id IS NULL OR p.status='pending'":'';
    return (int)sf_jukebox_query("SELECT COUNT(*) FROM jukebox_listener_players l
        LEFT JOIN jukebox_phones p ON p.id=l.phone_id AND p.session_id=l.session_id
        WHERE l.session_id=? AND l.active_until_ms>? AND l.item_id=? AND l.generation=?
        AND (p.status='approved'$public)",[$session['id'],$now,$session['current_id'],$session['control_revision']])->fetchColumn();
}

function sf_jukebox_listener_metrics(array $session,?array $transport=null): array {
    $counts=sf_jukebox_query('SELECT unique_players,peak_players,listening_ms
        FROM jukebox_listener_totals WHERE session_id=?',[$session['id']])->fetch();
    return [
        'active_players'=>!(int)$session['ended']&&($transport['status']??null)==='playing'
            ?sf_jukebox_listener_active($session,$transport['server_time_ms']):0,
        'unique_players'=>(int)($counts['unique_players']??0),
        'peak_players'=>(int)($counts['peak_players']??0),
        'listening_ms'=>(int)($counts['listening_ms']??0),
    ];
}

/** The caller holds the session write lock while validating and updating observations. */
function sf_jukebox_listener_report(array $session,?array $phone,array $body): void {
    $player=$body['player']??null;
    $seq=$body['seq']??null;
    $item=$body['item']??null;
    $generation=$body['generation']??null;
    $advanced=$body['advanced_ms']??null;
    $active=$body['active']??null;
    if(!is_string($player)||!preg_match('/\A[a-f0-9]{32}\z/',$player)
        ||!is_int($seq)||$seq<0||!is_string($item)||!preg_match('/\A[1-9][0-9]{0,18}\z/',$item)
        ||!is_int($generation)||$generation<0||!is_int($advanced)||$advanced<0||!is_bool($active))
        throw new InvalidArgumentException('A valid remote playback sample is required.');
    if(!sf_jukebox_remote_access($session,$phone))throw new DomainException('Remote listening is unavailable.');
    if((string)$session['current_id']!==$item||(int)$session['control_revision']!==$generation
        ||$session['remote_status']!=='playing'||(bool)$session['paused']||!sf_jukebox_player_online($session))return;
    $confirmed=sf_jukebox_query("SELECT played FROM jukebox_requests WHERE id=? AND session_id=?
        AND status='current' AND played IS NOT NULL",[$item,$session['id']])->fetchColumn();
    if($confirmed===false)return;
    $transport=sf_jukebox_transport($session,['played'=>$confirmed],true);
    if($transport['status']!=='playing')return;

    $id=$session['id'];
    $now=$transport['server_time_ms'];
    $hash=hash('sha256',$id.':'.$player);
    $row=sf_jukebox_query('SELECT last_seq,item_id,generation,sampled_at_ms,active_until_ms,listening_ms,phone_id
        FROM jukebox_listener_players WHERE session_id=? AND player_hash=?',[$id,$hash])->fetch();
    if($row&&$seq<=(int)$row['last_seq'])return;
    $same=$row&&(string)$row['item_id']===$item&&(int)$row['generation']===$generation;
    $previous=$same?(int)$row['sampled_at_ms']:0;
    $gap=$now-$previous;
    $credit=$previous>0&&$gap>0&&$gap<=SF_JUKEBOX_LISTENER_ACTIVE_MS
        ?min($advanced,$gap,SF_JUKEBOX_LISTENER_CREDIT_MS):0;
    $wasActive=$same&&(int)$row['active_until_ms']>$now;
    $until=$active?($credit>0?$now+SF_JUKEBOX_LISTENER_ACTIVE_MS:($wasActive?(int)$row['active_until_ms']:0)):0;
    $phoneId=$until>0?($credit>0?($phone['id']??null):$row['phone_id']):null;
    // A zero inactive report arms an initial sample; a stop disarms a running sample.
    $sampled=($active||(!$wasActive&&$advanced===0))?$now:0;
    sf_jukebox_listener_clear_expired($id,$now);
    if($row){
        sf_jukebox_query('UPDATE jukebox_listener_players SET last_seq=?,item_id=?,generation=?,sampled_at_ms=?,
            active_until_ms=?,listening_ms=listening_ms+?,phone_id=? WHERE session_id=? AND player_hash=?',
            [$seq,$item,$generation,$sampled,$until,$credit,$phoneId,$id,$hash]);
    }else{
        sf_jukebox_query('INSERT INTO jukebox_listener_players (session_id,player_hash,last_seq,item_id,generation,
            sampled_at_ms,active_until_ms,listening_ms,phone_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$id,$hash,$seq,$item,$generation,$sampled,$until,0,$phoneId]);
    }
    if($credit>0){
        $unique=(int)((int)$row['listening_ms']===0);
        // A final sample can prove a short listen without ever reporting live activity.
        $concurrent=$until>0&&(!$wasActive||$phoneId!==$row['phone_id'])?sf_jukebox_listener_active($session,$now):1;
        $mysql=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $sql='INSERT INTO jukebox_listener_totals (session_id,unique_players,peak_players,listening_ms) VALUES (?,?,?,?)';
        $sql.=$mysql?' ON DUPLICATE KEY UPDATE unique_players=unique_players+VALUES(unique_players),
            peak_players=GREATEST(peak_players,VALUES(peak_players)),listening_ms=listening_ms+VALUES(listening_ms)'
            :' ON CONFLICT(session_id) DO UPDATE SET unique_players=unique_players+excluded.unique_players,
            peak_players=MAX(peak_players,excluded.peak_players),listening_ms=listening_ms+excluded.listening_ms';
        sf_jukebox_query($sql,[$id,$unique,$concurrent,$credit]);
    }
}
