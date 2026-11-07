<?php
declare(strict_types=1);
require_once __DIR__.'/canvas-capabilities.php';

final class SfCanvasAccessConflict extends RuntimeException {}
function sf_canvas_access_table_exists(): bool {
    $db=sf_db();$sql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT 1 FROM sqlite_master WHERE type='table' AND name='canvas_access_grants'":"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='canvas_access_grants'";
    return $db->query($sql)->fetchColumn()!==false;
}
function sf_canvas_access_schema(): void {
    $db=sf_db();if($db->inTransaction())throw new LogicException('Initialize canvas access before a transaction.');
    $engine=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $db->exec("CREATE TABLE IF NOT EXISTS canvas_access_state(user_id BIGINT PRIMARY KEY,revision BIGINT NOT NULL)".$engine);
    $db->exec("CREATE TABLE IF NOT EXISTS canvas_access_grants(user_id BIGINT NOT NULL,context_key VARCHAR(80) NOT NULL,permission_id VARCHAR(100) NOT NULL,fields_json TEXT NOT NULL,granted_at VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,context_key,permission_id))".$engine);
}
/** The lineage column is host-owned; input JSON can never choose or change it. */
function sf_canvas_access_root(int $uid,int $sceneId): int {
    $db=sf_db();$seen=[];$current=$sceneId;
    while($current>0){
        if(isset($seen[$current]))throw new RuntimeException('Invalid canvas lineage.');$seen[$current]=true;
        $q=$db->prepare('SELECT access_root_id,previous_id FROM agent_scene_history WHERE user_id=? AND id=?');$q->execute([$uid,$current]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row)throw new OutOfBoundsException('Canvas unavailable.');
        if((int)($row['access_root_id']??0)>0)return (int)$row['access_root_id'];
        if(!(int)$row['previous_id'])return $current;$current=(int)$row['previous_id'];
    }
    throw new OutOfBoundsException('Canvas unavailable.');
}
function sf_canvas_access_context(mixed $raw): array {
    if(!is_array($raw)||array_is_list($raw)||count($raw)!==1)throw new InvalidArgumentException('Choose one canvas.');
    if(isset($raw['scene_id'])&&is_int($raw['scene_id'])&&$raw['scene_id']>0)return ['scene_id'=>$raw['scene_id']];
    if(isset($raw['gallery_id'])&&is_string($raw['gallery_id'])&&preg_match('/\A[a-f0-9]{32}\z/',$raw['gallery_id']))return ['gallery_id'=>$raw['gallery_id']];
    throw new InvalidArgumentException('Choose a valid canvas.');
}
/** Resolve code from authoritative storage, not caller-supplied markup or scope IDs. */
function sf_canvas_access_resolve(array $user,array $context): array {
    $context=sf_canvas_access_context($context);$uid=(int)($user['id']??0);
    if(isset($context['scene_id'])){
        require_once __DIR__.'/agent-scene-history.php';
        require_once __DIR__.'/agent-scene-core.php';
        $scene=sf_agent_scene_history_get($uid,$context['scene_id']);if(!$scene)throw new OutOfBoundsException('Canvas unavailable.');
        if($scene['canvas_kind']==='custom')$scene=sf_agent_scene_scrub_copy($scene);
        $scene['html']=sf_agent_scene_strip_legacy_player($scene['html']);
        return ['scene'=>$scene,'key'=>'scene:'.sf_canvas_access_root($uid,$scene['id']),'context'=>$context];
    }
    require_once __DIR__.'/canvas-gallery-core.php';
    $row=sf_canvas_gallery_row($context['gallery_id']);
    if(!$row||$row['status']!=='approved'||!sf_canvas_gallery_available($context['gallery_id']))throw new OutOfBoundsException('Canvas unavailable.');
    return ['scene'=>json_decode($row['snapshot_json'],true,16,JSON_THROW_ON_ERROR),'key'=>'gallery:'.$context['gallery_id'],'context'=>$context];
}
function sf_canvas_access_fields(array $card): array {
    $fields=['@access:'.($card['access_contract_version']??1)];
    foreach($card['fields'] as $name=>$type)$fields[]=$name.':'.$type;
    sort($fields);return $fields;
}
function sf_canvas_access_declared(string $html): array {
    preg_match_all('/<[a-z][a-z0-9]*\s+class="([A-Za-z0-9_ -]{1,160})"(?:\s*\/)?\s*>/',$html,$matches);$ids=[];
    foreach($matches[1] as $classes)foreach(explode(' ',$classes) as $class)if(preg_match('/\Asf-dataset-([a-z][a-z0-9_-]{0,63})\z/',$class,$m))$ids[$m[1]]=true;
    return $ids;
}
/** External source authority comes from approved bindings instead of HTML markers. */
function sf_canvas_access_external(array $sources): array {
    if(!$sources)return [];
    require_once __DIR__.'/canvas-data-core.php';
    return sf_canvas_data_declared_capabilities($sources);
}

/** An explicit account-source selection limits private data; older canvases retain their grants. */
function sf_canvas_access_in_scope(string $id,bool $personal,?array $meTools): bool {
    return !$personal||$meTools===null||in_array($id,$meTools,true);
}
function sf_canvas_access_grants(int $uid,string $key): array {
    if($uid<1||!sf_canvas_access_table_exists())return [];
    $q=sf_db()->prepare('SELECT permission_id,fields_json FROM canvas_access_grants WHERE user_id=? AND context_key=?');$q->execute([$uid,$key]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$fields=json_decode($row['fields_json'],true,16,JSON_THROW_ON_ERROR);if(!is_array($fields)||!array_is_list($fields))throw new RuntimeException('Invalid canvas access grant.');$out[$row['permission_id']]=$fields;}
    return $out;
}
function sf_canvas_access_revision(int $uid): int {
    if($uid<1||!sf_canvas_access_table_exists())return 0;
    $q=sf_db()->prepare('SELECT revision FROM canvas_access_state WHERE user_id=?');$q->execute([$uid]);return (int)$q->fetchColumn();
}
function sf_canvas_access_summary(array $user,array $context): array {
    $resolved=sf_canvas_access_resolve($user,$context);$scene=$resolved['scene'];$uid=(int)($user['id']??0);$policy=sf_canvas_permission_policy();
    $meTools=$scene['artist_inputs']['me_tools']??null;
    $dataSources=$scene['artist_inputs']['data_sources']??[];
    $external=sf_canvas_access_external($dataSources);
    if($uid>0){
        $q=sf_db()->prepare("SELECT 1 FROM users WHERE id=? AND status='active'");$q->execute([$uid]);
        $active=$q->fetchColumn()!==false;$q->closeCursor();
        if(!$active)$uid=0;
    }
    $grants=sf_canvas_access_grants($uid,$resolved['key']);$declared=sf_canvas_access_declared($scene['html']);$permissions=[];$needed=false;$scopes=[];
    foreach(sf_canvas_capability_cards() as $id=>$card){
        $personal=$card['requires_sign_in'];
        $bindings=($card['transport']??null)==='host_read'?$external:$declared;
        if(!isset($bindings[$id])||!sf_canvas_access_in_scope($id,$personal,$meTools))continue;
        $fields=sf_canvas_access_fields($card);
        $granted=!$personal||($uid>0&&isset($grants[$card['permission_id']])&&!array_diff($fields,$grants[$card['permission_id']]));
        $enabled=sf_canvas_permission_enabled($id,$policy);if($personal&&$enabled&&!$granted)$needed=true;
        $permissions[]=['id'=>$card['permission_id'],'label'=>$card['label'],'description'=>$card['description'],'personal'=>$personal,'enabled'=>$enabled,'granted'=>$granted,'version'=>hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR))];
        $scopes[$card['permission_id']]=$fields;
    }
    // The fingerprint guards consent, not creative execution. Grant reuse is based on scope inclusion.
    $fingerprintInputs=[$resolved['context'],$scene['html'],$scene['css'],$scene['js']??'', $scopes,$policy['revision'],$uid,$uid>0?sf_agent_scene_history_epoch($uid):0,sf_canvas_access_revision($uid)];
    if($meTools!==null)$fingerprintInputs[]=$meTools;
    if($dataSources)$fingerprintInputs[]=$dataSources;
    $fingerprint=hash('sha256',json_encode($fingerprintInputs,JSON_THROW_ON_ERROR));
    return ['context'=>$resolved['context'],'fingerprint'=>$fingerprint,'title'=>$scene['title'],'permissions'=>$permissions,'requires_consent'=>$needed,'signed_in'=>$uid>0];
}
function sf_canvas_access_change(array $user,array $context,string $action,string $fingerprint,array $ids): array {
    $uid=(int)($user['id']??0);if($uid<1)throw new DomainException('Sign in to manage canvas access.');
    if(!in_array($action,['grant','revoke'],true)||!preg_match('/\A[a-f0-9]{64}\z/',$fingerprint)||!array_is_list($ids)||!$ids||count($ids)>32)throw new InvalidArgumentException('Review the current canvas permissions.');
    foreach($ids as $id)if(!is_string($id))throw new InvalidArgumentException('Choose registered permissions.');
    $epoch=sf_agent_scene_history_epoch($uid);
    $initial=sf_canvas_access_summary($user,$context);
    if(!hash_equals($initial['fingerprint'],$fingerprint))throw new SfCanvasAccessConflict('This canvas or its permissions changed. Review access again.');
    sf_canvas_access_schema();sf_canvas_permission_schema();$db=sf_db();$db->beginTransaction();
    try{
        // Serialize with privacy reset, closure and other grant/revoke requests for this viewer.
        $lock=$db->prepare("SELECT id FROM users WHERE id=? AND status='active'".($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$lock->execute([$uid]);$active=$lock->fetchColumn()!==false;$lock->closeCursor();
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->query('SELECT revision FROM canvas_permission_policy WHERE id=1 FOR UPDATE')->closeCursor();
        if(!$active||sf_agent_scene_history_epoch($uid)!==$epoch)throw new SfCanvasAccessConflict('This account or canvas changed. Review access again.');
        $summary=sf_canvas_access_summary($user,$context);if(!hash_equals($summary['fingerprint'],$fingerprint))throw new SfCanvasAccessConflict('This canvas or its permissions changed. Review access again.');
        $resolved=sf_canvas_access_resolve($user,$context);$known=array_column($summary['permissions'],null,'id');$cards=array_column(sf_canvas_capability_cards(),null,'permission_id');
        $oldGrants=sf_canvas_access_grants($uid,$resolved['key']);
        foreach(array_unique($ids) as $id){
            if(!isset($known[$id])||!$known[$id]['personal']||($action==='grant'&&!$known[$id]['enabled']))throw new InvalidArgumentException('Choose an available personal-data permission declared by this canvas.');
            $db->prepare('DELETE FROM canvas_access_grants WHERE user_id=? AND context_key=? AND permission_id=?')->execute([$uid,$resolved['key'],$id]);
            if($action==='grant')$db->prepare('INSERT INTO canvas_access_grants(user_id,context_key,permission_id,fields_json,granted_at) VALUES(?,?,?,?,?)')->execute([$uid,$resolved['key'],$id,json_encode(array_values(array_unique(array_merge($oldGrants[$id]??[],sf_canvas_access_fields($cards[$id])))),JSON_THROW_ON_ERROR),gmdate('c')]);
        }
        $revision=sf_canvas_access_revision($uid);
        $db->prepare('DELETE FROM canvas_access_state WHERE user_id=?')->execute([$uid]);
        $db->prepare('INSERT INTO canvas_access_state(user_id,revision) VALUES(?,?)')->execute([$uid,$revision+1]);
        $db->commit();return sf_canvas_access_summary($user,$context);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_canvas_access_export(int $uid): array {
    if(!sf_canvas_access_table_exists())return [];$q=sf_db()->prepare('SELECT context_key,permission_id,fields_json,granted_at FROM canvas_access_grants WHERE user_id=? ORDER BY context_key,permission_id');$q->execute([$uid]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_canvas_access_clear(int $uid): int {
    if(!sf_canvas_access_table_exists())return 0;$q=sf_db()->prepare('DELETE FROM canvas_access_grants WHERE user_id=?');$q->execute([$uid]);$count=$q->rowCount();sf_db()->prepare('DELETE FROM canvas_access_state WHERE user_id=?')->execute([$uid]);return $count;
}

function sf_canvas_access_saved(int $uid): array {
    $cards=array_column(sf_canvas_capability_cards(),null,'permission_id');$out=[];
    foreach(sf_canvas_access_export($uid) as $row)$out[]=['context_key'=>$row['context_key'],'permission_id'=>$row['permission_id'],'label'=>$cards[$row['permission_id']]['label']??$row['permission_id'],'granted_at'=>$row['granted_at']];
    return $out;
}
function sf_canvas_access_revoke_saved(array $user,string $key,array $ids): array {
    $uid=(int)($user['id']??0);
    if($uid<1||!preg_match('/\A(?:scene:[1-9][0-9]*|gallery:[a-f0-9]{32})\z/',$key)||!array_is_list($ids)||!$ids||count($ids)>32)throw new InvalidArgumentException('Choose saved canvas permissions.');
    foreach($ids as $id)if(!is_string($id)||!preg_match('/\Acanvas\.data\.[a-z][a-z0-9_]{0,63}\z/',$id))throw new InvalidArgumentException('Choose saved permissions.');
    $epoch=sf_agent_scene_history_epoch($uid);sf_canvas_access_schema();$db=sf_db();$db->beginTransaction();
    try{
        $q=$db->prepare("SELECT id FROM users WHERE id=? AND status='active'".($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$uid]);$active=$q->fetchColumn()!==false;$q->closeCursor();
        if(!$active||sf_agent_scene_history_epoch($uid)!==$epoch)throw new SfCanvasAccessConflict('Your account changed. Refresh permissions.');
        foreach(array_unique($ids) as $id)$db->prepare('DELETE FROM canvas_access_grants WHERE user_id=? AND context_key=? AND permission_id=?')->execute([$uid,$key,$id]);
        $revision=sf_canvas_access_revision($uid);$db->prepare('DELETE FROM canvas_access_state WHERE user_id=?')->execute([$uid]);$db->prepare('INSERT INTO canvas_access_state(user_id,revision) VALUES(?,?)')->execute([$uid,$revision+1]);
        $db->commit();return sf_canvas_access_saved($uid);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

/** Public projection: declared capabilities only, never creator grants or data. */
function sf_canvas_access_public_uses(string $html,?array $meTools=null,array $dataSources=[]): array {
    $declared=sf_canvas_access_declared($html);$policy=sf_canvas_permission_policy();$uses=[];
    $external=sf_canvas_access_external($dataSources);
    foreach(sf_canvas_capability_cards() as $id=>$card){
        $bindings=($card['transport']??null)==='host_read'?$external:$declared;
        if(isset($bindings[$id])&&sf_canvas_access_in_scope($id,$card['requires_sign_in'],$meTools))
            $uses[]=['id'=>$card['permission_id'],'label'=>$card['label'],'personal'=>$card['requires_sign_in'],'enabled'=>sf_canvas_permission_enabled($id,$policy)];
    }
    return $uses;
}
