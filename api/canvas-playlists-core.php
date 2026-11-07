<?php
declare(strict_types=1);

require_once __DIR__.'/playlists-core.php';

function sf_canvas_playlists_media_ready(bool $prepared=false): bool {
    static $ready=false;if($prepared)$ready=true;return $ready;
}

/** Artist publication approves a playlist revision; ordinary public sharing does not. */
function sf_canvas_playlists_ensure_schema(): void {
    static $done=false;
    $pdo=sf_db();
    if(!$pdo->inTransaction()&&!sf_canvas_playlists_media_ready()){
        // The legacy source resolver lazily creates media tables. Warm it before
        // a transaction, including when the publication schema was already read.
        sf_media_ensure_schema();sf_canvas_playlists_media_ready(true);
    }
    if($done)return;
    if($pdo->inTransaction()){
        // MySQL CREATE TABLE can commit a caller's approval transaction, even
        // with IF NOT EXISTS. Cold nested readers only inspect existing tables.
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach(['user_playlists','user_playlist_tracks','canvas_artist_playlists'] as $table){
            if($driver==='sqlite'){$query=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");}
            else{$query=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');}
            $query->execute([$table]);
            if($query->fetchColumn()===false)throw new RuntimeException('Initialize playlist resources before opening an approval transaction.');
        }
        $done=true;return;
    }
    sf_playlists_ensure_schema();
    if((sf_db_config()['driver']??'')==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS canvas_artist_playlists (playlist_id INTEGER PRIMARY KEY,revision_hash TEXT NOT NULL,revision INTEGER NOT NULL,status TEXT NOT NULL,published_by INTEGER NULL,published_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(playlist_id) REFERENCES user_playlists(id) ON DELETE CASCADE,FOREIGN KEY(published_by) REFERENCES users(id) ON DELETE SET NULL)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS canvas_artist_playlists (playlist_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,revision_hash CHAR(64) NOT NULL,revision BIGINT NOT NULL,status VARCHAR(16) NOT NULL,published_by BIGINT UNSIGNED NULL,published_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,CONSTRAINT fk_canvas_artist_playlist FOREIGN KEY(playlist_id) REFERENCES user_playlists(id) ON DELETE CASCADE,CONSTRAINT fk_canvas_artist_publisher FOREIGN KEY(published_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}

function sf_canvas_playlists_ref(mixed $raw): ?array {
    if(!is_string($raw)||preg_match('/\A(own|artist)-([1-9][0-9]*)\z/',$raw,$match)!==1)return null;
    $id=filter_var($match[2],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    return $id===false?null:['ref'=>$raw,'source'=>$match[1],'id'=>$id];
}

/** No database access or sampling: this is also the canonical approval fingerprint input. */
function sf_canvas_playlists_inputs(mixed $raw): ?array {
    if($raw===null)return null;
    if(!is_array($raw)||array_is_list($raw)||array_keys($raw)!==['items']||!is_array($raw['items'])||!array_is_list($raw['items']))
        throw new InvalidArgumentException('Choose playlists from the playlist picker.');
    $items=[];$seen=[];
    foreach($raw['items'] as $item){
        if(!is_array($item)||array_is_list($item)||count($item)!==2||array_diff(array_keys($item),['ref','version'])||
            sf_canvas_playlists_ref($item['ref']??null)===null||!is_string($item['version']??null)||
            preg_match('/\A[a-f0-9]{64}\z/',$item['version'])!==1)
            throw new InvalidArgumentException('Choose a current playlist revision from the picker.');
        $ref=$item['ref'];$version=$item['version'];
        if(isset($seen[$ref])){
            if(!hash_equals($seen[$ref],$version))throw new InvalidArgumentException('The playlist selection contains different revisions. Refresh the picker.');
            continue;
        }
        $seen[$ref]=$version;$items[]=['ref'=>$ref,'version'=>$version];
    }
    return ['items'=>$items];
}

/** Tokens are source references, never an implicit request for a particular player layout. */
function sf_canvas_playlists_selection(string $brief,mixed $raw=null,bool $provided=false): array {
    $items=[];$clear=false;
    $clean=preg_replace_callback('/(`?)\{(playlist:([^}]*?)|playlists-clear)\}\1/i',
        static function(array $match)use(&$items,&$clear):string {
            if(strcasecmp($match[2],'playlists-clear')===0){$clear=true;return ' ';}
            if(preg_match('/\A((?:own|artist)-[1-9][0-9]*)@([a-f0-9]{64})\z/',$match[3]??'',$parts)!==1)
                throw new InvalidArgumentException('Choose a current playlist revision from the picker.');
            $items[]=['ref'=>$parts[1],'version'=>$parts[2]];return ' ';
        },$brief);
    if($clean===null||($clear&&$items))throw new InvalidArgumentException('Choose playlists or clear the playlist selection.');
    $tokens=($clear||$items)?sf_canvas_playlists_inputs(['items'=>$items]):null;
    $inputs=$provided?sf_canvas_playlists_inputs($raw):$tokens;
    if($provided&&$tokens!==null&&$inputs!==$tokens)
        throw new InvalidArgumentException('The playlist selection changed. Apply the current picker selection.');
    return [trim($clean),$inputs,$provided||$tokens!==null];
}

/** A single read transaction keeps names, entry order and publication from different revisions apart. */
function sf_canvas_playlists_transaction(callable $run): mixed {
    sf_canvas_playlists_ensure_schema();$pdo=sf_db();$own=!$pdo->inTransaction();
    if($own)$pdo->beginTransaction();
    try{$result=$run();if($own)$pdo->commit();return $result;}
    catch(Throwable $error){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function sf_canvas_playlists_account(int $uid,bool $admin=false): bool {
    if($uid<1)return false;
    $query=sf_db()->prepare('SELECT role,status FROM users WHERE id=?'.sf_canvas_playlists_current_read());$query->execute([$uid]);$row=$query->fetch(PDO::FETCH_ASSOC);
    return $row&&$row['status']==='active'&&(!$admin||$row['role']==='admin');
}

/** Locking reads see committed revocations even inside a caller's older MySQL RR snapshot. */
function sf_canvas_playlists_current_read(bool $write=false): string {
    return sf_db()->inTransaction()&&(string)sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?
        ($write?' FOR UPDATE':' LOCK IN SHARE MODE'):'';
}

/** Raw identities remain server-side, including private/deleted song references used only for hashing. */
function sf_canvas_playlists_raw(array $row,bool $current=false): array {
    $query=sf_db()->prepare('SELECT id,track_id,position FROM user_playlist_tracks WHERE playlist_id=? ORDER BY position ASC,id ASC'.($current?sf_canvas_playlists_current_read():''));
    $query->execute([(int)$row['id']]);$entries=[];
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $entry)$entries[]=['item_id'=>(int)$entry['id'],'position'=>(int)$entry['position'],'track_id'=>(string)$entry['track_id']];
    return ['version'=>1,'playlist_id'=>(int)$row['id'],'owner_id'=>(int)$row['user_id'],
        'name'=>(string)$row['name'],'description'=>(string)$row['description'],'visibility'=>(string)$row['visibility'],'items'=>$entries];
}

function sf_canvas_playlists_hash(array $raw): string {
    return hash('sha256',json_encode($raw,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

/** Only this current public catalog supplies visible metadata and authorized listening URLs. */
function sf_canvas_playlists_public_tracks(): array {
    require_once __DIR__.'/canvas-audio-core.php';$tracks=[];
    if(sf_db()->inTransaction()&&!sf_canvas_playlists_media_ready()){
        foreach(sf_public_catalog() as $track)if(preg_match('#\A/?api/media\.php(?:\?|$)#',(string)($track['audio']??'')))
            throw new RuntimeException('Initialize playlist media resources before opening an approval transaction.');
    }
    foreach(sf_canvas_audio_catalog()['tracks'] as $track)$tracks[$track['track_id']]=$track;
    return $tracks;
}

function sf_canvas_playlists_projection(array $raw,string $source,array $tracks,?string $version=null): array {
    $items=[];
    foreach($raw['items'] as $entry){
        $track=$tracks[$entry['track_id']]??null;
        $available=$track!==null&&($track['source_available']??false)===true;
        $items[]=['item_id'=>$entry['item_id'],'position'=>$entry['position'],
            'track_id'=>$track===null?null:$track['track_id'],'title'=>$track===null?'Unavailable track':$track['title'],
            'duration_seconds'=>$track===null?0:$track['duration_seconds'],'available'=>$available,
            'reason'=>$available?'available':($track===null?'unavailable':'source_unavailable')];
    }
    return ['ref'=>$source.'-'.$raw['playlist_id'],'source'=>$source,'title'=>$raw['name'],'description'=>$raw['description'],
        'revision_hash'=>$version??sf_canvas_playlists_hash($raw),'items'=>$items];
}

function sf_canvas_playlists_publication(int $id,bool $current=false): ?array {
    $query=sf_db()->prepare('SELECT revision_hash,revision,status FROM canvas_artist_playlists WHERE playlist_id=?'.($current?sf_canvas_playlists_current_read():''));
    $query->execute([$id]);$row=$query->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

/** A publication epoch prevents publish/withdraw/publish from reviving older project approvals. */
function sf_canvas_playlists_public_version(string $contentHash,int $revision): string {
    return hash('sha256',json_encode(['content_hash'=>$contentHash,'publication_revision'=>$revision],JSON_THROW_ON_ERROR));
}

/** Explicit source authorization is repeated for every compile, not inferred from a saved picker row. */
function sf_canvas_playlists_resolve(int $uid,array $input,array $tracks): array {
    $ref=sf_canvas_playlists_ref($input['ref']);
    $query=sf_db()->prepare('SELECT * FROM user_playlists WHERE id=?'.sf_canvas_playlists_current_read());$query->execute([$ref['id']]);$row=$query->fetch(PDO::FETCH_ASSOC);
    if(!$row||!sf_canvas_playlists_account((int)$row['user_id'])||
        ($ref['source']==='own'&&($uid<1||(int)$row['user_id']!==$uid)))
        throw new InvalidArgumentException('A selected playlist is no longer available. Refresh the playlist picker.');
    $raw=sf_canvas_playlists_raw($row,true);$hash=sf_canvas_playlists_hash($raw);$version=$hash;
    if($ref['source']==='artist'){
        $publication=sf_canvas_playlists_publication($ref['id'],true);
        if($row['visibility']!=='public'||!$publication||$publication['status']!=='published'||!hash_equals($publication['revision_hash'],$hash))
            throw new InvalidArgumentException('A selected artist playlist is no longer published. Refresh the playlist picker.');
        $version=sf_canvas_playlists_public_version($hash,(int)$publication['revision']);
    }
    if(!hash_equals($input['version'],$version))throw new InvalidArgumentException('A selected playlist changed. Review its current revision in the playlist picker.');
    return sf_canvas_playlists_projection($raw,$ref['source'],$tracks,$version);
}

/** A strict persisted DTO also protects history, export and gallery code from arbitrary nested data. */
function sf_canvas_playlists_manifest_validate(mixed $raw): ?array {
    if($raw===null)return null;
    if(!is_array($raw)||array_is_list($raw)||count($raw)!==2||array_diff(array_keys($raw),['version','playlists'])||
        ($raw['version']??null)!==1||!is_array($raw['playlists']??null)||!array_is_list($raw['playlists']))
        throw new InvalidArgumentException('Use a valid playlist snapshot.');
    $playlists=[];$seen=[];
    foreach($raw['playlists'] as $playlist){
        if(!is_array($playlist)||array_is_list($playlist)||count($playlist)!==6||
            array_diff(array_keys($playlist),['ref','source','title','description','revision_hash','items'])||
            ($ref=sf_canvas_playlists_ref($playlist['ref']??null))===null||($playlist['source']??null)!==$ref['source']||
            !is_string($playlist['title']??null)||!is_string($playlist['description']??null)||
            !is_string($playlist['revision_hash']??null)||preg_match('/\A[a-f0-9]{64}\z/',$playlist['revision_hash'])!==1||
            !is_array($playlist['items']??null)||!array_is_list($playlist['items'])||isset($seen[$ref['ref']]))
            throw new InvalidArgumentException('Use a valid playlist snapshot.');
        $seen[$ref['ref']]=true;$items=[];$entryIds=[];$last=null;
        foreach($playlist['items'] as $item){
            if(!is_array($item)||array_is_list($item)||count($item)!==7||
                array_diff(array_keys($item),['item_id','position','track_id','title','duration_seconds','available','reason'])||
                !is_int($item['item_id']??null)||$item['item_id']<1||isset($entryIds[$item['item_id']])||
                !is_int($item['position']??null)||$item['position']<0||
                !array_key_exists('track_id',$item)||($item['track_id']!==null&&(!is_string($item['track_id'])||preg_match('/\A[A-Za-z0-9_-]{1,180}\z/',$item['track_id'])!==1))||
                !is_string($item['title']??null)||!is_int($item['duration_seconds']??null)||$item['duration_seconds']<0||
                !is_bool($item['available']??null)||!in_array($item['reason']??null,['available','unavailable','source_unavailable'],true))
                throw new InvalidArgumentException('Use a valid ordered playlist snapshot.');
            if(($item['available']&&($item['track_id']===null||$item['reason']!=='available'))||
                (!$item['available']&&$item['reason']==='available')||
                ($item['track_id']===null&&($item['available']||$item['reason']!=='unavailable'||$item['title']!=='Unavailable track'||$item['duration_seconds']!==0))||
                ($item['track_id']!==null&&$item['reason']==='unavailable')||
                ($last!==null&&([$item['position'],$item['item_id']]<=$last)))
                throw new InvalidArgumentException('Use a valid ordered playlist snapshot.');
            $entryIds[$item['item_id']]=true;$last=[$item['position'],$item['item_id']];
            $items[]=['item_id'=>$item['item_id'],'position'=>$item['position'],'track_id'=>$item['track_id'],'title'=>$item['title'],
                'duration_seconds'=>$item['duration_seconds'],'available'=>$item['available'],'reason'=>$item['reason']];
        }
        $playlists[]=['ref'=>$ref['ref'],'source'=>$ref['source'],'title'=>$playlist['title'],'description'=>$playlist['description'],
            'revision_hash'=>$playlist['revision_hash'],'items'=>$items];
    }
    return ['version'=>1,'playlists'=>$playlists];
}

/** Frozen inputs are bound to current access and full exact membership before any metered work. */
function sf_canvas_playlists_compile(int $uid,mixed $raw,mixed $expectedManifest=null): array {
    $inputs=sf_canvas_playlists_inputs($raw);$expected=sf_canvas_playlists_manifest_validate($expectedManifest);
    if($inputs===null){
        if($expected!==null)throw new InvalidArgumentException('The approved playlist snapshot changed.');
        return ['manifest'=>null,'track_ids'=>null];
    }
    return sf_canvas_playlists_transaction(static function()use($uid,$inputs,$expected):array {
        if($uid>0&&!sf_canvas_playlists_account($uid))throw new InvalidArgumentException('Sign in to use your playlist selection.');
        $tracks=$inputs['items']?sf_canvas_playlists_public_tracks():[];$playlists=[];$ids=[];
        foreach($inputs['items'] as $input){
            $playlist=sf_canvas_playlists_resolve($uid,$input,$tracks);$playlists[]=$playlist;
            foreach($playlist['items'] as $item)if($item['available'])$ids[$item['track_id']]=$item['track_id'];
        }
        $manifest=sf_canvas_playlists_manifest_validate(['version'=>1,'playlists'=>$playlists]);
        if($expected!==null&&$expected!==$manifest)throw new InvalidArgumentException('The approved playlist snapshot changed. Review the current playlist selection.');
        return ['manifest'=>$manifest,'track_ids'=>array_values($ids)];
    });
}

/** Compilation never changes the caller's direct selection or inserts a host UI. */
function sf_canvas_playlists_effective_music(mixed $direct,mixed $rawManifest): ?array {
    require_once __DIR__.'/agent-canvas-music-core.php';
    $direct=sf_agent_canvas_music_inputs($direct);$manifest=sf_canvas_playlists_manifest_validate($rawManifest);
    if($manifest===null||($direct['mode']??null)==='all')return $direct;
    $ids=[];
    foreach($direct['track_ids']??[] as $id)$ids[$id]=$id;
    foreach($manifest['playlists'] as $playlist)foreach($playlist['items'] as $item)if($item['available'])$ids[$item['track_id']]=$item['track_id'];
    return ['mode'=>'selected','track_ids'=>array_values($ids)];
}

function sf_canvas_playlists_inventory_item(array $playlist): array {
    $available=count(array_filter($playlist['items'],static fn(array $item):bool=>$item['available']));
    return ['id'=>$playlist['ref'],'ref'=>$playlist['ref'],'source'=>$playlist['source'],'category'=>$playlist['source'],
        'title'=>$playlist['title'],'description'=>$playlist['description'],'revision_hash'=>$playlist['revision_hash'],
        'track_count'=>count($playlist['items']),'available_count'=>$available,
        'availability_label'=>$available.' of '.count($playlist['items']).' tracks available','items'=>$playlist['items']];
}

/** Guests see approved artist data. Owned lists come only from the active current account. */
function sf_canvas_playlists_inventory(int $uid): array {
    return sf_canvas_playlists_transaction(static function()use($uid):array {
        if($uid>0&&!sf_canvas_playlists_account($uid))throw new InvalidArgumentException('Sign in to choose your playlists.');
        $tracks=sf_canvas_playlists_public_tracks();$items=[];
        if($uid>0){
            $query=sf_db()->prepare('SELECT * FROM user_playlists WHERE user_id=? ORDER BY updated_at DESC,id DESC');$query->execute([$uid]);
            foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row)$items[]=sf_canvas_playlists_inventory_item(sf_canvas_playlists_projection(sf_canvas_playlists_raw($row),'own',$tracks));
        }
        $query=sf_db()->query("SELECT p.*,a.revision_hash AS approved_hash,a.revision AS publication_revision FROM user_playlists p JOIN canvas_artist_playlists a ON a.playlist_id=p.id JOIN users u ON u.id=p.user_id WHERE p.visibility='public' AND a.status='published' AND u.status='active' ORDER BY a.published_at DESC,p.id DESC");
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
            $raw=sf_canvas_playlists_raw($row);
            $hash=sf_canvas_playlists_hash($raw);
            if(hash_equals($row['approved_hash'],$hash))$items[]=sf_canvas_playlists_inventory_item(sf_canvas_playlists_projection($raw,'artist',$tracks,
                sf_canvas_playlists_public_version($hash,(int)$row['publication_revision'])));
        }
        return $items;
    });
}

function sf_canvas_playlists_require_admin(array $actor): int {
    $id=$actor['id']??null;
    if(!is_int($id)||!sf_canvas_playlists_account($id,true))throw new DomainException('Administrator access is required to publish artist playlists.');
    return $id;
}

/** Administrators review public candidates and any prior publication needing withdrawal. */
function sf_canvas_playlists_admin_inventory(array $actor): array {
    return sf_canvas_playlists_transaction(static function()use($actor):array {
        $admin=sf_canvas_playlists_require_admin($actor);$tracks=sf_canvas_playlists_public_tracks();$items=[];
        $query=sf_db()->query("SELECT p.*,a.revision_hash AS approved_hash,a.status AS publication_status,a.revision AS publication_revision FROM user_playlists p JOIN users u ON u.id=p.user_id LEFT JOIN canvas_artist_playlists a ON a.playlist_id=p.id WHERE (p.visibility='public' AND u.status='active') OR a.playlist_id IS NOT NULL ORDER BY p.updated_at DESC,p.id DESC");
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
            $raw=sf_canvas_playlists_raw($row);$sourceAvailable=sf_canvas_playlists_account((int)$row['user_id'])&&
                ($row['visibility']==='public'||(int)$row['user_id']===$admin);
            $revision=(int)($row['publication_revision']??0);
            $projection=sf_canvas_playlists_projection($raw,'artist',$tracks,sf_canvas_playlists_public_version(sf_canvas_playlists_hash($raw),$revision));
            if(!$sourceAvailable){$projection['title']='Unavailable playlist';$projection['description']='';$projection['items']=[];}
            $item=sf_canvas_playlists_inventory_item($projection);$item['source_available']=$sourceAvailable;
            $item['playlist_id']=(int)$row['id'];$item['visibility']=(string)$row['visibility'];
            $item['can_publish']=$row['visibility']==='public'&&sf_canvas_playlists_account((int)$row['user_id']);
            $item['publication_status']=$row['publication_status']??'unpublished';
            $item['approved_hash']=$row['approved_hash']===null?null:sf_canvas_playlists_public_version($row['approved_hash'],$revision);
            $item['published']=$item['can_publish']&&$item['publication_status']==='published'&&hash_equals((string)$item['approved_hash'],$item['revision_hash']);
            $items[]=$item;
        }
        return $items;
    });
}

/** Review the exact displayed revision; rechecking actor role prevents stale-session authorization. */
function sf_canvas_playlists_moderate(array $actor,int $id,string $action,string $expectedHash): array {
    if($id<1||!in_array($action,['publish','withdraw'],true)||preg_match('/\A[a-f0-9]{64}\z/',$expectedHash)!==1)
        throw new InvalidArgumentException('Review an exact playlist revision before publication.');
    return sf_canvas_playlists_transaction(static function()use($actor,$id,$action,$expectedHash):array {
        $admin=sf_canvas_playlists_require_admin($actor);$pdo=sf_db();
        $lock=(sf_db_config()['driver']??'')==='mysql'?' FOR UPDATE':'';
        $query=$pdo->prepare('SELECT * FROM user_playlists WHERE id=?'.$lock);$query->execute([$id]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new InvalidArgumentException('This playlist is no longer available.');
        $hash=sf_canvas_playlists_hash(sf_canvas_playlists_raw($row,true));$previous=sf_canvas_playlists_publication($id,true);
        $revision=(int)($previous['revision']??0);
        if(!hash_equals($expectedHash,sf_canvas_playlists_public_version($hash,$revision)))
            throw new InvalidArgumentException('The playlist or its publication changed after review. Refresh the publication list.');
        if($action==='publish'&&($row['visibility']!=='public'||!sf_canvas_playlists_account((int)$row['user_id'])))
            throw new InvalidArgumentException('Only a public playlist from an active account can be published.');
        $now=gmdate('c');$status=$action==='publish'?'published':'withdrawn';$revision++;
        if((sf_db_config()['driver']??'')==='sqlite'){
            $query=$pdo->prepare('INSERT INTO canvas_artist_playlists(playlist_id,revision_hash,revision,status,published_by,published_at,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(playlist_id) DO UPDATE SET revision_hash=excluded.revision_hash,revision=excluded.revision,status=excluded.status,published_by=excluded.published_by,published_at=excluded.published_at,updated_at=excluded.updated_at');
        }else{
            $query=$pdo->prepare('INSERT INTO canvas_artist_playlists(playlist_id,revision_hash,revision,status,published_by,published_at,updated_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revision_hash=VALUES(revision_hash),revision=VALUES(revision),status=VALUES(status),published_by=VALUES(published_by),published_at=VALUES(published_at),updated_at=VALUES(updated_at)');
        }
        $query->execute([$id,$hash,$revision,$status,$admin,$now,$now]);
        return ['playlist_id'=>$id,'revision_hash'=>sf_canvas_playlists_public_version($hash,$revision),'status'=>$status];
    });
}
