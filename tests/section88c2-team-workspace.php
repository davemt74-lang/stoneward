<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,display_name TEXT,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'root@example.invalid','Super Admin','admin','active'),
 (2,'band@example.invalid','Musician','user','active'),
 (3,'crew@example.invalid','Crew','user','active'),
 (4,'off@example.invalid','Revoked','user','disabled')");
$db->exec('CREATE TABLE admin_audit_log(id INTEGER PRIMARY KEY,admin_user_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,created_at TEXT)');
$db->exec('CREATE TABLE site_event_log(id INTEGER PRIMARY KEY,severity TEXT,event_type TEXT,message TEXT,created_at TEXT)');
$db->exec("INSERT INTO admin_audit_log VALUES(1,1,'track_published','track','song-one','2026-10-17T17:00:00+00:00')");
$db->exec("INSERT INTO site_event_log VALUES(1,'info','catalog_indexed','Catalog indexed','2026-10-17T17:00:01+00:00')");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_ops_ensure_schema():void{}
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
require dirname(__DIR__).'/api/team-workspace-core.php';
$n=0;
function gateTeam(bool $test,string $name):void{global $n;if(!$test)throw new RuntimeException('Team FAIL '.$name);$n++;echo "PASS $name\n";}
gateTeam(in_array('website_manager',array_keys(sf_team_role_catalog()),true),'Website Manager role exists');
gateTeam(in_array('road_crew',array_keys(sf_team_role_catalog()),true),'Crew types are roles');
gateTeam(sf_team_can(1,'team.assign_roles'),'Actual admin manages roles');
gateTeam(!sf_team_can(2,'media.upload'),'No implicit access to upload');
$roles=sf_team_assign_roles(2,['band_member','website_manager','sound_crew'],1);
gateTeam(count($roles)===3,'Multiple roles on one login');
gateTeam(sf_team_can(2,'media.upload')&&sf_team_can(2,'content.draft'),'Union permissions');
gateTeam(!sf_team_can(2,'team.assign_roles'),'Website Manager is not Super Admin');
gateTeam(!sf_team_can(2,'store.refund')&&!sf_team_can(2,'system.secrets'),'No cross-domain privilege escalation');
gateTeam(count(sf_team_roster(2))===1,'Website Manager can see team roster');
gateTeam(sf_team_assign_roles(3,['road_crew'],1)===['road_crew'],'Crew assignment');
gateTeam(!sf_team_can(3,'media.upload'),'Road crew cannot upload media');
gateTeam(!sf_team_can(4,'team.enter'),'Disabled user cannot gain access');
sf_team_audit(2,'media.uploaded','media_asset','19','Uploaded song-one.wav',
 ['filename'=>'song-one.wav','category'=>'audio','password'=>'secret','token'=>'secret']);
$before=sf_team_timeline(2);
gateTeam($before['scope']==='team_activity'&&count($before['events'])>=2,'Website Manager sees shared team operations');
gateTeam(!str_contains(json_encode($before),'track_published')&&!str_contains(json_encode($before),'Catalog indexed'),
 'Team cannot read private Admin or site audit');
gateTeam(!str_contains(json_encode($db->query('SELECT * FROM team_activity')->fetchAll()),'secret'),
 'Timeline whitelist excludes secrets');
gateTeam(sf_team_timeline(1)['scope']==='all_site_operations','Admin sees unified cross-system timeline');
$adminEvents=sf_team_timeline(1)['events'];
gateTeam(count(array_filter($adminEvents,fn($e)=>$e['source']==='admin'))===1,'Existing admin audit incorporated');
gateTeam(count(array_filter($adminEvents,fn($e)=>$e['source']==='site'))===1,'Existing site events incorporated');
gateTeam(sf_team_timeline(1,1)['has_more'],'Timeline pagination does not truncate history');
gateTeam(sf_team_timeline(3)['scope']==='own_activity','Road crew restricted to own events');
sf_team_assign_roles(2,['band_member'],1);
gateTeam(!sf_team_can(2,'media.upload')&&!sf_team_can(2,'team.timeline.read'),'Revocation is immediate on permission reads');
gateTeam(sf_team_roles(2)===['band_member'],'One remaining role preserved after multi-role revocation');
gateTeam(sf_team_assign_roles(2,['band_member','sound_crew'],1)===['band_member','sound_crew'],'Revoked role may be granted again');
try{sf_team_assign_roles(2,['super_admin'],1);throw new RuntimeException('Role accepted');}
catch(InvalidArgumentException $e){gateTeam(true,'Unknown role never silently grants authority');}
$endpoint=file_get_contents(dirname(__DIR__).'/api/team-workspace.php');
$upload=file_get_contents(dirname(__DIR__).'/api/team-media-upload.php');
$assign=file_get_contents(dirname(__DIR__).'/admin/api/team-workspace.php');
gateTeam(str_contains($endpoint,"sf_team_require('team.enter')"),'Shared session protected Team Workspace');
gateTeam(str_contains($upload,"sf_team_require('media.upload',true)")&&str_contains($upload,'sf_team_audit('),'Team upload requires permission and CSRF and logs actor');
gateTeam(str_contains($assign,"sf_admin_require_auth(")&&str_contains($assign,"confirmed"),'Only Admin assigns roles with confirmation');

$adminPage=file_get_contents(dirname(__DIR__).'/admin/index.php');
$adminJs=file_get_contents(dirname(__DIR__).'/admin/assets/admin.js');
$adminModule=file_get_contents(dirname(__DIR__).'/admin/assets/team-workspace.js');
$sitePage=file_get_contents(dirname(__DIR__).'/stonefellow-v120.php');
$siteJs=file_get_contents(dirname(__DIR__).'/assets/js/app.js');
$legacyUpload=file_get_contents(dirname(__DIR__).'/admin/api/upload.php');
$catalog=file_get_contents(dirname(__DIR__).'/admin/api/catalog.php');
gateTeam(str_contains($adminPage,'data-view="team"')
 &&str_contains($adminPage,'team-workspace.js?v=')
 &&str_contains($adminJs,'window.SFTeamAdmin?.render'), 'Admin has Team Workspace navigation');
gateTeam(str_contains($adminModule,'[data-team-role]:checked')
 &&str_contains($adminModule,"action:'assign_roles'")
 &&str_contains($adminModule,'teamAdminTimeline'), 'Admin can assign multiple roles and inspect timeline');
gateTeam(str_contains($sitePage,'sf_team_can((int)$currentUser['."'".'id'."'".'],'."'".'team.enter'."'".')')
 &&str_contains($siteJs,"view==='team'")&&str_contains($siteJs,'renderTeamWorkspace()'),
 'Existing site login opens role-gated team workspace');
gateTeam(str_contains($siteJs,'team-media-upload.php')
 &&str_contains($siteJs,"'X-CSRF-Token':st.auth.csrf"),'Team upload is connected to authenticated browser UI');
gateTeam(str_contains($legacyUpload,"'song_source_uploaded'")
 &&str_contains($legacyUpload,"(int)$"."me['id']"),'Original song upload pipeline logs the uploading admin');
gateTeam(str_contains($catalog,"'song_metadata_updated'")
 &&str_contains($catalog,"(int)$"."me['id']"),'Existing catalog edits log the responsible admin');

echo "TEAM: $n acceptance checks green\n";
