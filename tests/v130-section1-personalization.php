<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$core=src('api/personalization-core.php');$api=src('api/personalization.php');$listening=src('api/listening.php');$account=src('api/account.php');$migrations=src('api/migrations.php');$js=src('assets/js/app.js');$libraryJs=src('assets/js/library.js');$css=src('assets/css/site.css');$version=src('version.php');
ok(str_contains($core,'CREATE TABLE IF NOT EXISTS user_favorites'),'favorites table exists');
ok(str_contains($core,'UNIQUE(user_id,item_type,item_id)')||str_contains($core,'UNIQUE KEY uq_user_favorite'),'favorites are unique per user/item');
ok(str_contains($core,'CREATE TABLE IF NOT EXISTS user_listening_progress'),'resume/progress table exists');
ok(str_contains($core,"in_array(\$type,['track','release'],true)"),'favorites are limited to track/release types');
ok(str_contains($core,'sf_personalization_item_exists'),'favorite IDs are validated against catalog/releases');
ok(str_contains($core,'position_seconds>=10'),'continue listening excludes trivial starts');
ok(str_contains($core,'completed=0'),'completed tracks do not remain in Continue Listening');
ok(str_contains($core,'UPDATE listening_events SET user_id=NULL WHERE user_id=?'),'clear history anonymizes events instead of deleting aggregate analytics');
ok(str_contains($core,'DELETE FROM user_listening_progress WHERE user_id=?'),'clear history removes resume positions');
ok(str_contains($core,"event_type IN ('listen_start','listen_complete')"),'clear history removes personal listening activity links');
ok(str_contains($api,"action==='set_favorite'"),'favorite write API exists');
ok(str_contains($api,"action==='clear_history'"),'clear-history API exists');
ok(str_contains($api,'sf_require_user(false,$write)'),'personalization API requires auth/CSRF on writes');
ok(str_contains($listening,'sf_personalization_record_progress'),'listening telemetry updates resume state');
ok(str_contains($account,"'personalization'=>sf_personalization_state(\$uid)"),'account payload includes personalization');
ok(str_contains($migrations,"'id'=>'2026-10-08-007'")&&str_contains($migrations,'sf_personalization_ensure_schema'),'Section 1 personalization migration remains registered');
ok(str_contains($migrations,"'id'=>'2026-10-08-007'")&&str_contains($migrations,'sf_personalization_ensure_schema'),'upgrade migration covers personalization schema');
ok(str_contains($migrations,"'user_favorites','user_listening_progress'"),'integrity check requires personalization tables');
ok(str_contains($libraryJs,'CONTINUE LISTENING')&&str_contains($libraryJs,"history:'History'")&&str_contains($libraryJs,"tracks:'Tracks'")&&str_contains($libraryJs,"releases:'Releases'"),'My Library surfaces favorites, Continue Listening, and listening history');
ok(str_contains($js,'favoriteButton(')&&str_contains($js,'bindFavoriteButtons'),'favorite controls are wired');
ok(str_contains($js,"favoriteButton('release'")&&str_contains($js,"favoriteButton('track'"),'track and release favorites exist');
ok(str_contains($js,'resumeTrack(')&&str_contains($js,'play(t,resumeAt=0'),'Continue Listening resumes saved playback position');
ok(str_contains($js,'clearListeningHistory')&&str_contains($js,"action:'clear_history'"),'Clear History control is wired');
ok(str_contains($css,'.favorite-control')&&str_contains($css,'.listen-progress')&&str_contains($css,'.library-grid'),'personalization styles exist');
ok(str_contains($version,"'personalization'=>'favorites-library-history'"),'version endpoint retains Section 1 personalization capability');
echo "Stonefellow v1.3 Section 1 favorites/library/history audit: PASS\n";
