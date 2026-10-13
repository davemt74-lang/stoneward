<?php
declare(strict_types=1);
// Section 52: private, administrator-only homepage staging and release history.
// No public endpoint reads from these tables. MySQL and SQLite are supported.
function sf_home_design_schema(): void {
    static $ready=false;
    if($ready)return;
    $pdo=sf_db();
    if((sf_db_config()['driver']??'')==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_home_design_draft (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, base_hash TEXT NOT NULL, snapshot_json TEXT NOT NULL, updated_at TEXT NOT NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_home_design_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, snapshot_json TEXT NOT NULL, created_at TEXT NOT NULL)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_home_design_draft (id INT NOT NULL PRIMARY KEY, revision INT NOT NULL, base_hash CHAR(64) NOT NULL, snapshot_json LONGTEXT NOT NULL, updated_at VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_home_design_versions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, snapshot_json LONGTEXT NOT NULL, created_at VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready=true;
}
function sf_home_design_snapshot(): array {
    return array_map(static fn(array $s):array=>[
        'section_key'=>(string)$s['section_key'],
        'enabled'=>(bool)$s['enabled'],
        'position'=>(int)$s['position'],
        'eyebrow'=>(string)$s['eyebrow'],
        'heading'=>(string)$s['heading'],
        'body_text'=>(string)$s['body_text'],
        'config'=>(array)$s['config']
    ],sf_artist_home_sections(false));
}
function sf_home_design_hash(array $sections): string {
    return hash('sha256',sf_artist_home_json($sections));
}
function sf_home_design_clean(array $sections): array {
    $keys=sf_artist_home_keys();
    if(count($sections)!==count($keys))throw new InvalidArgumentException('Draft must contain every homepage section.');
    $seen=[];$out=[];
    foreach($sections as $section){
        if(!is_array($section))throw new InvalidArgumentException('Invalid homepage section.');
        $key=(string)($section['section_key']??'');
        if(!in_array($key,$keys,true)||isset($seen[$key]))throw new InvalidArgumentException('Unknown or duplicate homepage section.');
        $seen[$key]=true;
        $config=$section['config']??[];
        if(!is_array($config))throw new InvalidArgumentException('Invalid section configuration.');
        $out[]=[
          'section_key'=>$key,
          'enabled'=>!empty($section['enabled']),
          'position'=>max(0,min(999,(int)($section['position']??0))),
          'eyebrow'=>sf_clean_text($section['eyebrow']??'',120),
          'heading'=>sf_clean_text($section['heading']??'',255),
          'body_text'=>sf_clean_text($section['body_text']??'',6000),
          'config'=>sf_artist_home_normalize_config($key,$config),
        ];
    }
    usort($out,static fn($a,$b)=>($a['position']<=>$b['position'])?:strcmp($a['section_key'],$b['section_key']));
    return $out;
}
function sf_home_design_state(): array {
    sf_home_design_schema();
    $row=sf_db()->query('SELECT revision,base_hash,snapshot_json,updated_at FROM artist_home_design_draft WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $published=sf_home_design_snapshot();
    $hash=sf_home_design_hash($published);
    if(!$row)return ['revision'=>0,'base_hash'=>$hash,'published_hash'=>$hash,'sections'=>$published,'updated_at'=>null];
    $sections=sf_artist_home_decode($row['snapshot_json'],[]);
    return ['revision'=>(int)$row['revision'],'base_hash'=>$row['base_hash'],'published_hash'=>$hash,'sections'=>$sections,'updated_at'=>$row['updated_at']];
}
function sf_home_design_versions(): array {
    sf_home_design_schema();
    return sf_db()->query('SELECT id,created_at FROM artist_home_design_versions ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
}
function sf_home_design_save(array $sections,int $expectedRevision,string $baseHash): array {
    $clean=sf_home_design_clean($sections);
    $pdo=sf_db();sf_home_design_schema();
    $current=sf_home_design_state();
    if($current['revision']!==$expectedRevision||!hash_equals($current['base_hash'],$baseHash))
        throw new RuntimeException('Another editor changed this draft. Reload before saving.');
    $next=$expectedRevision+1;$now=gmdate('c');
    if($expectedRevision===0&&$current['updated_at']===null){
        $q=$pdo->prepare('INSERT INTO artist_home_design_draft (id,revision,base_hash,snapshot_json,updated_at) VALUES (1,?,?,?,?)');
        $q->execute([$next,$baseHash,sf_artist_home_json($clean),$now]);
    }else{
        $q=$pdo->prepare('UPDATE artist_home_design_draft SET revision=?,snapshot_json=?,updated_at=? WHERE id=1 AND revision=? AND base_hash=?');
        $q->execute([$next,sf_artist_home_json($clean),$now,$expectedRevision,$baseHash]);
        if($q->rowCount()!==1)throw new RuntimeException('Another editor changed this draft. Reload before saving.');
    }
    return sf_home_design_state();
}
function sf_home_design_publish(int $revision,string $expectedHash): array {
    sf_home_design_schema();$pdo=sf_db();$pdo->beginTransaction();
    try{
        $row=$pdo->query('SELECT revision,base_hash,snapshot_json FROM artist_home_design_draft WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['revision']!==$revision)throw new RuntimeException('Save the latest draft before publishing.');
        $currentHash=sf_home_design_hash(sf_home_design_snapshot());
        if(!hash_equals($row['base_hash'],$currentHash)||!hash_equals($expectedHash,$currentHash))
            throw new RuntimeException('Published homepage changed since this draft began. Reopen and review before publishing.');
        $sections=sf_home_design_clean(sf_artist_home_decode($row['snapshot_json'],[]));
        foreach($sections as $section)sf_artist_home_save_section($section); // all updates are one transaction
        $published=sf_home_design_snapshot();
        $q=$pdo->prepare('INSERT INTO artist_home_design_versions (snapshot_json,created_at) VALUES (?,?)');
        $q->execute([sf_artist_home_json($published),gmdate('c')]);
        $version=(int)$pdo->lastInsertId();
        $newHash=sf_home_design_hash($published);
        $q=$pdo->prepare('UPDATE artist_home_design_draft SET revision=revision+1,base_hash=?,snapshot_json=?,updated_at=? WHERE id=1 AND revision=?');
        $q->execute([$newHash,sf_artist_home_json($published),gmdate('c'),$revision]);
        if($q->rowCount()!==1)throw new RuntimeException('Draft changed during publishing.');
        $pdo->commit();
        return ['version'=>$version,'draft'=>sf_home_design_state()];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sf_home_design_restore(int $version,int $expectedRevision,string $baseHash): array {
    if($version<1)throw new InvalidArgumentException('Invalid version.');
    sf_home_design_schema();
    $q=sf_db()->prepare('SELECT snapshot_json FROM artist_home_design_versions WHERE id=?');$q->execute([$version]);
    $raw=$q->fetchColumn();if($raw===false)throw new InvalidArgumentException('Saved version not found.');
    // Restore only to draft; publishing still requires explicit review and confirmation.
    return sf_home_design_save(sf_artist_home_decode($raw,[]),$expectedRevision,$baseHash);
}
