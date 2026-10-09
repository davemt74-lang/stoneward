<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$core=src('api/personalization-core.php');$agent=src('api/agent-runtime.php');$playlists=src('api/playlists-core.php');$js=src('assets/js/app.js');$css=src('assets/css/site.css');$version=src('version.php');$migrations=src('api/migrations.php');
ok(str_contains($core,'function sf_personalization_recommendation_profile'),'recommendation profile exists');
ok(str_contains($core,"FROM user_favorites WHERE user_id=?"),'favorites feed the recommendation profile');
ok(str_contains($core,"FROM listening_events WHERE user_id=?"),'listening history feeds the recommendation profile');
ok(str_contains($core,'INNER JOIN user_playlists p ON p.id=upt.playlist_id'),'playlist membership feeds the recommendation profile');
ok(str_contains($core,'function sf_personalization_rank_catalog'),'catalog ranking helper exists');
ok(str_contains($core,"'reasons'=>array_slice("),'recommendations include transparent reasons');
ok(str_contains($core,'function sf_personalization_recommendations'),'recommendation payload exists');
ok(str_contains($core,"'recommendations'=>sf_personalization_recommendations("),'personalization state exposes recommendations');
ok(str_contains($agent,'sf_personalization_recommendation_profile($userId)'),'agent recommendations use the listener profile');
ok(str_contains($agent,'sf_agent_recommend_track($message,$active,$userId)'),'recommend route passes the current user');
ok(str_contains($playlists,'sf_personalization_rank_catalog(sf_catalog(),$profile'),'agent playlist curation shares personalized ranking');
ok(str_contains($playlists,'?int $userId=null'),'playlist recommendation helper remains backwards compatible');
ok(str_contains($js,'function recommendationCard(')&&str_contains($js,'Recommended for you'),'home recommendation UI exists');
ok(str_contains($js,"play(t,0,'recommendation')"),'recommendation playback has its own telemetry source');
ok(str_contains($js,'RECOMMENDED FOR YOU'),'account surfaces recommendations');
ok(str_contains($js,"if(st.auth.authenticated&&[")&&str_contains($js,"'home'")&&str_contains($js,"await loadPersonalization()"),'authenticated views continue to preload personalization');
ok(str_contains($js,"querySelectorAll('[data-recommend-play]')")&&str_contains($js,"querySelectorAll('[data-resume-track]')"),'recommendation and resume controls bind as collections');
ok(str_contains($css,'.recommendation-grid')&&str_contains($css,'.recommendation-card'),'responsive recommendation styles exist');
ok(str_contains($version,"'recommendations'=>'favorites-listening-playlists-agent'"),'version endpoint retains Section 4 recommendation capability');
ok(str_contains($migrations,"'id'=>'2026-10-08-008'")&&str_contains($migrations,'sf_playlists_ensure_schema'),'Section 4 remains compatible with the pre-Section-6 schema baseline');
echo "Stonefellow v1.3 Section 4 personalized recommendations audit: PASS\n";
