<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$core=src('api/personalization-core.php');$api=src('api/listening-history.php');$notifications=src('api/notifications.php');$js=src('assets/js/app.js');$css=src('assets/css/site.css');$migrations=src('api/migrations.php');$version=src('version.php');
ok(str_contains($core,'function sf_personalization_history_summary'),'listening history summary exists');
ok(str_contains($core,'COUNT(DISTINCT session_key) AS sessions'),'summary counts listening sessions');
ok(str_contains($core,'function sf_personalization_history_page'),'paginated listening history loader exists');
ok(str_contains($core,'session_key,track_id,event_type'),'history query includes session/track/event context');
ok(str_contains($core,"'release'=>"."$"."t['release']"),'history rows include release context');
ok(str_contains($core,"'source'=>"."$"."r['source']"),'history rows include playback source');
ok(str_contains($core,"'resume_position'=>"."$"."resume")&&str_contains($core,"'can_resume'=>"."$"."resume>0"),'history rows include safe resume state');
ok(str_contains($core,"id<?")&&str_contains($core,'next_before_id'),'history pagination uses stable event IDs');
ok(str_contains($core,'function sf_personalization_clear_track_history'),'per-track privacy clearing exists');
ok(str_contains($core,'UPDATE listening_events SET user_id=NULL WHERE user_id=? AND track_id=?'),'per-track clearing anonymizes aggregate events');
ok(str_contains($core,'DELETE FROM user_listening_progress WHERE user_id=? AND track_id=?'),'per-track clearing removes resume state');
ok(str_contains($api,"action==='clear_all'")&&str_contains($api,"action==='clear_track'"),'history API supports all/track privacy clearing');
ok(str_contains($api,'sf_require_user(false,$write)'),'history endpoint requires authenticated user and CSRF on writes');
ok(str_contains($notifications,"'listening_preview'=>sf_personalization_history("."$"."uid,12)"),'activity drawer payload includes recent listening');
ok(str_contains($js,'function renderListeningHistory(')&&str_contains($js,'Listening History'),'dedicated listening history UI exists');
ok(str_contains($js,'historySessionLabel')&&str_contains($js,'data-history-session'),'history UI groups events by session');
ok(str_contains($js,'id="historySearch"')&&str_contains($js,'id="historyEventFilter"'),'history search and event filters exist');
ok(str_contains($js,'data-history-position')&&str_contains($js,'resume_position'),'history resume controls use server resume state');
ok(str_contains($js,'historyLoadMore')&&str_contains($js,'before_id'),'load-earlier pagination is wired');
ok(str_contains($js,'clearTrackListeningHistory')&&str_contains($js,"action:'clear_track'"),'per-track privacy control is wired');
ok(str_contains($js,'clearAllListeningHistory')&&str_contains($js,"action:'clear_all'"),'clear-all privacy control is wired');
ok(str_contains($js,'drawerOpenHistory')&&str_contains($js,"navigate('history')"),'drawer History tab links to full listening history');
ok(str_contains($css,'.history-session')&&str_contains($css,'.history-event')&&str_contains($css,'.drawer-history-link'),'history and drawer styles exist');
ok(str_contains($migrations,"const SF_DB_SCHEMA_TARGET = '1.3.1'"),'Section 3 correctly requires no new schema migration');
ok(str_contains($version,"'listening_history'=>'sessions-resume-privacy'"),'version endpoint retains Section 3 listening-history capability');
echo "Stonefellow v1.3 Section 3 listening history audit: PASS\n";
