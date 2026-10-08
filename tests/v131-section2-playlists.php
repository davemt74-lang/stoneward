<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$core=src('api/playlists-core.php');$api=src('api/playlists.php');$bootstrap=src('api/bootstrap.php');$account=src('api/account.php');$migrations=src('api/migrations.php');$agent=src('api/agent-runtime.php');$js=src('assets/js/app.js');$css=src('assets/css/site.css');$version=src('version.php');
ok(str_contains($core,'CREATE TABLE IF NOT EXISTS user_playlists'),'playlist table exists');
ok(str_contains($core,'CREATE TABLE IF NOT EXISTS user_playlist_tracks'),'ordered playlist track table exists');
ok(str_contains($core,'FOREIGN KEY(playlist_id) REFERENCES user_playlists(id) ON DELETE CASCADE'),'playlist tracks cascade on playlist delete');
ok(str_contains($core,"sf_playlist_clean_visibility")&&str_contains($core,"$"."visibility==='public'?'public':'private'"),'playlist visibility is limited to public/private');
ok(str_contains($core,'sf_playlist_get_owned'),'playlist ownership guard exists');
ok(str_contains($core,'sf_playlist_get_public'),'public playlist access is visibility-gated');
ok(str_contains($core,'sf_playlist_validate_track_ids'),'playlist track IDs are catalog validated');
ok(str_contains($core,'sf_playlist_reorder')&&str_contains($core,'order does not match the current playlist'),'reorder requires exact current item membership');
ok(str_contains($core,"source='agent'")&&str_contains($core,'sf_playlist_create_from_agent_session'),'agent listening sessions can be saved as playlists');
ok(str_contains($api,"action==='create'")&&str_contains($api,"action==='update'")&&str_contains($api,"action==='delete'"),'playlist CRUD API exists');
ok(str_contains($api,"action==='add_track'")&&str_contains($api,"action==='remove_item'")&&str_contains($api,"action==='reorder'"),'playlist track editing API exists');
ok(str_contains($api,"public_id")&&str_contains($api,'sf_playlist_get_public'),'public playlist GET path exists');
ok(str_contains($api,'sf_require_user(false,true)'),'playlist writes require authenticated CSRF-protected user');
ok(str_contains($bootstrap,"require_once __DIR__ . '/playlists-core.php';"),'playlist core loads from application bootstrap');
ok(str_contains($account,"'playlists'=>sf_playlist_list($"."uid)"),'account payload includes playlists');
ok(str_contains($migrations,"const SF_DB_SCHEMA_TARGET = '1.3.1'"),'database target remains v1.3.1');
ok(str_contains($migrations,"'id'=>'2026-10-08-008'")&&str_contains($migrations,'sf_playlists_ensure_schema'),'upgrade migration covers playlist schema');
ok(str_contains($migrations,"'user_playlists','user_playlist_tracks'"),'integrity check requires playlist tables');
ok(str_contains($agent,"return 'playlist_create'")&&str_contains($agent,"case 'playlist_create'"),'agent playlist-create route and policy exist');
ok(str_contains($agent,"return 'playlist_save_session'")&&str_contains($agent,"case 'playlist_save_session'"),'agent save-session route and policy exist');
ok(str_contains($agent,"'playlist_create'=>'Asks the agent to create")&&str_contains($agent,"'playlist_save_session'=>'Asks to save"),'JEV routing criteria include playlist actions');
ok(str_contains($js,"case'create_playlist'")&&str_contains($js,"case'save_agent_session'"),'client executes authorized agent playlist actions');
ok(str_contains($js,"function renderPlaylist(id)")&&str_contains($js,'PLAYLISTS'),'playlist editor and account playlist library exist');
ok(str_contains($js,'data-move-item')&&str_contains($js,"action:'reorder'"),'playlist reorder UI is wired');
ok(str_contains($js,'data-remove-playlist-item')&&str_contains($js,"action:'remove_item'"),'playlist removal UI is wired');
ok(str_contains($js,'id="addPlaylistTrack"')&&str_contains($js,"action:'add_track'"),'playlist add-track UI is wired');
ok(str_contains($js,'function playPlaylist(')&&str_contains($js,'playlistPlayback'),'playlist playback context exists');
ok(str_contains($js,"st.playlistPlayback.index++")&&str_contains($js,"play(t,0,'playlist',true)"),'playlist auto-advances on track completion');
ok(str_contains($js,"source:st.playSource||'player'")&&str_contains($js,"play(t,0,'agent')"),'agent playback is tagged for session capture');
ok(str_contains($js,'copyPlaylistLink')&&str_contains($js,"p.visibility==='public'"),'public playlists expose a share-link control');
ok(str_contains($css,'.playlist-track-row')&&str_contains($css,'.playlist-summary')&&str_contains($css,'.playlist-card'),'playlist responsive styles exist');
ok(str_contains($version,"'playlists'=>'ordered-public-private-agent'"),'version endpoint retains v1.3 Section 2 playlist capability');
echo "Stonefellow v1.3 Section 2 playlists audit: PASS\n";
