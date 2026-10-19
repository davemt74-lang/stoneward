<?php
declare(strict_types=1);
/** 88D3G4E: explicit per-cut FFmpeg effects stored only for an exact approved revision. */
function sf_render_cut_schema():void{
 static $ready=false;if($ready)return;
 sf_render_transition_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_cut_presets(
    assembly_id INTEGER PRIMARY KEY, revision INTEGER NOT NULL,cuts_json TEXT NOT NULL,
    reviewed_by INTEGER NOT NULL,reviewed_at TEXT NOT NULL,
    FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE,
    FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_cut_presets(
    assembly_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,revision INT NOT NULL,
    cuts_json TEXT NOT NULL,reviewed_by BIGINT UNSIGNED NOT NULL,
    reviewed_at VARCHAR(40) NOT NULL,
    CONSTRAINT fk_render_cut_assembly FOREIGN KEY(assembly_id)
      REFERENCES story_assemblies(id) ON DELETE CASCADE,
    CONSTRAINT fk_render_cut_editor FOREIGN KEY(reviewed_by)
      REFERENCES users(id) ON DELETE RESTRICT)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_render_cut_settings(int $editor,int $assembly,int $revision):?array{
 sf_assembly_editor($editor);sf_render_cut_schema();
 $q=sf_db()->prepare('SELECT revision,cuts_json FROM story_render_cut_presets WHERE assembly_id=?');
 $q->execute([$assembly]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row||(int)$row['revision']!==$revision)return null;
 $cuts=json_decode((string)$row['cuts_json'],true,16,JSON_THROW_ON_ERROR);
 if(!is_array($cuts)||!array_is_list($cuts))throw new RuntimeException('Corrupt revision-locked transition ledger.');
 return $cuts;
}
/** Called within the active global-preset transaction, so old overrides cannot linger. */
function sf_render_cut_clear(int $assembly):void{
 sf_render_cut_schema();
 $q=sf_db()->prepare('DELETE FROM story_render_cut_presets WHERE assembly_id=?');
 $q->execute([$assembly]);
}
function sf_render_cut_set(int $editor,int $assembly,int $revision,array $cuts):void{
 sf_assembly_editor($editor);sf_render_cut_schema();
 $plan=sf_render_plan($editor,$assembly,$revision); // source SHA and rights
 $settings=sf_render_transition_settings($editor,$assembly,$revision);
 if($settings===null)throw new RuntimeException('Save an approved global crossfade preset before customizing cuts.');
 $candidate=['style'=>$settings['style'],'overlap_ms'=>$settings['overlap_ms'],'cuts'=>$cuts];
 sf_render_transition_validate($plan,$candidate['style'],$candidate['overlap_ms'],$candidate);
 $canonical=sf_render_transition_cuts($plan,$candidate);
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested cut preset transaction forbidden.');
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 $db->beginTransaction();
 try{
  $q=$db->prepare("SELECT revision FROM story_assemblies WHERE id=? AND status='draft'");
  $q->execute([$assembly]);if((int)$q->fetchColumn()!==$revision)
   throw new RuntimeException('Timeline revision changed.');
  $q=$db->prepare('SELECT id FROM story_render_jobs WHERE assembly_id=? AND revision=?');
  $q->execute([$assembly,$revision]);
  if($q->fetchColumn()!==false)throw new RuntimeException('Queued render locks per-cut controls.');
  $q=$db->prepare('SELECT revision FROM story_render_transitions WHERE assembly_id=?');
  $q->execute([$assembly]);if((int)$q->fetchColumn()!==$revision)
   throw new RuntimeException('Global crossfade authorization expired.');
  $q=$db->prepare('SELECT assembly_id FROM story_render_cut_presets WHERE assembly_id=?');
  $q->execute([$assembly]);
  $json=json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
  if($q->fetchColumn()===false){
   $q=$db->prepare('INSERT INTO story_render_cut_presets
    (assembly_id,revision,cuts_json,reviewed_by,reviewed_at) VALUES(?,?,?,?,?)');
   $q->execute([$assembly,$revision,$json,$editor,gmdate('c')]);
  }else{
   $q=$db->prepare('UPDATE story_render_cut_presets
    SET revision=?,cuts_json=?,reviewed_by=?,reviewed_at=? WHERE assembly_id=?');
   $q->execute([$revision,$json,$editor,gmdate('c'),$assembly]);
  }
  sf_team_audit($editor,'capture.render_cuts_reviewed','story_assembly',(string)$assembly,
    'Private per-cut audio/video overlaps authorized at exact immutable revision');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
