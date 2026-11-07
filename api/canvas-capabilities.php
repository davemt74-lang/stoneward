<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-registry.php';

/** Canvas permissions govern dataset delivery, not the underlying shop/account pages. */
function sf_canvas_capability_cards(): array {
    $cards=[];
    foreach(sf_agent_tool_registry() as $id=>$tool){
        $data=$tool['canvas_data']??$tool['canvas_external']??null;
        if($data===null)continue;
        $cards[$id]=['id'=>$id,'permission_id'=>'canvas.data.'.$id,'schema_version'=>1,
            'kind'=>'data_source','label'=>$tool['label'],'description'=>$data['description'],
            'scope'=>$tool['scope'],'access'=>'read','requires_sign_in'=>$tool['scope']==='signed_in_fan',
            'consent_status'=>'explicit_canvas_grant','access_contract_version'=>1,'fields'=>$data['fields'],
            'filters'=>$data['filters']??[],'actions'=>$data['actions']??[],
            'max_rows'=>$data['max_rows'],'version'=>hash('sha256',json_encode($data,JSON_THROW_ON_ERROR))];
        if(isset($tool['canvas_external']))$cards[$id]['transport']='host_read';
    }
    return $cards;
}
function sf_canvas_permission_schema(): void {
    $pdo=sf_db();
    if($pdo->inTransaction())throw new RuntimeException('Canvas permission schema cannot initialize inside another transaction.');
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $text=$sqlite?'TEXT':'LONGTEXT';
    $engine=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS canvas_permission_policy (id INTEGER PRIMARY KEY,revision INTEGER NOT NULL,disabled_json $text NOT NULL,updated_by INTEGER NOT NULL,updated_at VARCHAR(40) NOT NULL)".$engine);
    $pdo->exec("CREATE TABLE IF NOT EXISTS canvas_permission_history (revision INTEGER PRIMARY KEY,changes_json $text NOT NULL,updated_by INTEGER NOT NULL,updated_at VARCHAR(40) NOT NULL)".$engine);
    $sql=$sqlite?'INSERT OR IGNORE':'INSERT IGNORE';
    $pdo->exec("$sql INTO canvas_permission_policy(id,revision,disabled_json,updated_by,updated_at) VALUES(1,1,'[]',0,'')");
}
function sf_canvas_permission_policy(): array {
    $pdo=sf_db();
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $exists=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='canvas_permission_policy'")->fetchColumn();
    }else{
        $exists=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='canvas_permission_policy'")->fetchColumn();
    }
    if(!$exists)return ['revision'=>1,'disabled'=>[],'updated_by'=>0,'updated_at'=>''];
    $row=sf_db()->query('SELECT revision,disabled_json,updated_by,updated_at FROM canvas_permission_policy WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Canvas policy unavailable.');
    $disabled=json_decode($row['disabled_json'],true,32,JSON_THROW_ON_ERROR);
    if(!is_array($disabled)||!array_is_list($disabled)||array_filter($disabled,static fn($id)=>!is_string($id)))throw new RuntimeException('Invalid canvas policy.');
    return ['revision'=>(int)$row['revision'],'disabled'=>$disabled,'updated_by'=>(int)$row['updated_by'],'updated_at'=>$row['updated_at']];
}
function sf_canvas_permission_enabled(string $id,?array $policy=null): bool {
    $card=sf_canvas_capability_cards()[$id]??null;
    if(!$card)return false;
    $policy??=sf_canvas_permission_policy();
    return !in_array($card['permission_id'],$policy['disabled'],true);
}
function sf_canvas_permission_admin_view(): array {
    sf_canvas_permission_schema();
    $policy=sf_canvas_permission_policy();$cards=[];
    foreach(sf_canvas_capability_cards() as $id=>$card)$cards[]=$card+['enabled'=>sf_canvas_permission_enabled($id,$policy)];
    $history=sf_db()->query('SELECT revision,changes_json,updated_by,updated_at FROM canvas_permission_history ORDER BY revision DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
    foreach($history as &$row){$row['revision']=(int)$row['revision'];$row['updated_by']=(int)$row['updated_by'];$row['changes']=json_decode($row['changes_json'],true,32,JSON_THROW_ON_ERROR);unset($row['changes_json']);}unset($row);
    return ['policy'=>$policy,'capabilities'=>$cards,'history'=>$history];
}
function sf_canvas_permission_save(array $admin,int $revision,array $changes): array {
    if(($admin['role']??'')!=='admin'||(int)($admin['id']??0)<1)throw new DomainException('Administrator access required.');
    if($revision<1||!$changes||array_is_list($changes))throw new InvalidArgumentException('Choose permission changes and the current policy revision.');
    $cards=sf_canvas_capability_cards();$known=array_column($cards,null,'permission_id');
    foreach($changes as $id=>$enabled)if(!isset($known[$id])||!is_bool($enabled))throw new InvalidArgumentException('Use registered canvas permissions and boolean values.');
    $pdo=sf_db();
    if($pdo->inTransaction())throw new RuntimeException('Policy updates require an independent transaction.');
    sf_canvas_permission_schema();
    $pdo->beginTransaction();
    try{
        $policy=sf_canvas_permission_policy();
        if($policy['revision']!==$revision)throw new UnexpectedValueException('Permissions changed. Refresh and review before saving.');
        $disabled=array_fill_keys($policy['disabled'],true);$actual=[];
        foreach($changes as $id=>$enabled){$before=!isset($disabled[$id]);if($before===$enabled)continue;$actual[$id]=['before'=>$before,'after'=>$enabled];if($enabled)unset($disabled[$id]);else $disabled[$id]=true;}
        if(!$actual){$pdo->commit();return sf_canvas_permission_admin_view();}
        $ids=array_keys($disabled);sort($ids);$now=gmdate('c');
        $q=$pdo->prepare('UPDATE canvas_permission_policy SET revision=?,disabled_json=?,updated_by=?,updated_at=? WHERE id=1 AND revision=?');
        $q->execute([$revision+1,json_encode($ids,JSON_THROW_ON_ERROR),(int)$admin['id'],$now,$revision]);
        if($q->rowCount()!==1)throw new UnexpectedValueException('Permissions changed. Refresh and review before saving.');
        $q=$pdo->prepare('INSERT INTO canvas_permission_history(revision,changes_json,updated_by,updated_at) VALUES(?,?,?,?)');
        $q->execute([$revision+1,json_encode($actual,JSON_THROW_ON_ERROR),(int)$admin['id'],$now]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return sf_canvas_permission_admin_view();
}
