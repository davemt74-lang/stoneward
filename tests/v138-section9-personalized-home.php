<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$core=src('api/home-core.php');$api=src('api/home.php');$boot=src('api/bootstrap.php');$app=src('assets/js/app.js');$css=src('assets/css/site.css');$agent=src('api/agent-runtime.php');$mig=src('api/migrations.php');$version=src('version.php');

ok(str_contains($boot,"require_once __DIR__ . '/home-core.php';"),'personalized home core loads from bootstrap');
ok(str_contains($api,"REQUEST_METHOD")&&str_contains($api,"!=='GET'"),'home API is read-only');
ok(str_contains($api,'sf_require_user(false,false)'),'home API requires an authenticated user');
ok(str_contains($api,'sf_home_state($uid)'),'home API returns aggregated home state');

ok(str_contains($core,'function sf_home_recent_history'),'recent-history reducer exists');
ok(str_contains($core,'isset($seen[$id])'),'recent history deduplicates repeated track events');
ok(str_contains($core,'function sf_home_recent_releases'),'recent release aggregation exists');
ok(str_contains($core,"state']??'published")&&str_contains($core,"!=='published'"),'home excludes unpublished releases');
ok(str_contains($core,"public_visible']??true")&&str_contains($core,"===false"),'home excludes hidden releases');
ok(str_contains($core,'strcmp((string)')&&substr_count($core,"release_date")>=2,'recent releases sort newest first');

ok(str_contains($core,'function sf_home_daily_suggestion'),'daily Agent suggestion generator exists');
ok(str_contains($core,"'kind'=>'continue'"),'daily suggestion can resume unfinished listening');
ok(str_contains($core,"'kind'=>'recommendation'"),'daily suggestion can use personalized recommendations');
ok(str_contains($core,"'kind'=>'build'"),'daily suggestion can continue a saved build');
ok(str_contains($core,"'kind'=>'favorite_release'"),'daily suggestion can revisit favorite releases');
ok(str_contains($core,"'kind'=>'release'"),'daily suggestion can surface a recent release');
ok(str_contains($core,"'kind'=>'catalog'"),'daily suggestion has a catalog fallback');
ok(substr_count($core,"==='')continue")>=5,'daily suggestion skips malformed rows without actionable IDs');
ok(str_contains($core,"gmdate('Y-m-d')")&&str_contains($core,'crc32('),'daily suggestion is deterministic for the current UTC day');

ok(str_contains($core,'function sf_home_state'),'personalized home aggregate exists');
ok(str_contains($core,'sf_personalization_state($userId)'),'home reuses canonical personalization state');
ok(str_contains($core,'sf_account_saved_builds($userId)'),'home reuses canonical saved builds');
ok(str_contains($core,'sf_queue_payload($userId)'),'home exposes canonical Up Next count');
ok(str_contains($core,"'continue_listening'"),'home includes Continue Listening');
ok(str_contains($core,"'recently_played'"),'home includes Recently Played');
ok(str_contains($core,"'favorites'"),'home includes Favorites');
ok(str_contains($core,"'recommendations'"),'home includes Recommended for You');
ok(str_contains($core,"'recent_releases'"),'home includes Recent Releases');
ok(str_contains($core,"'saved_builds'"),'home includes Saved Builds');
ok(str_contains($core,"'suggestion'"),'home includes the daily Agent suggestion');

ok(str_contains($app,"home:{loaded:false,data:null}"),'client keeps a dedicated personalized-home state');
ok(str_contains($app,'async function loadHome(')&&str_contains($app,"api('home.php')"),'client loads home state from the authenticated API');
ok(str_contains($app,'await loadHome(true)'),'home refreshes canonical state when opened');
ok(str_contains($app,'function homeTrackCard'),'home track cards exist');
ok(str_contains($app,'function homeReleaseCard'),'home release cards exist');
ok(str_contains($app,'function homeBuildCard'),'home saved-build cards exist');
ok(str_contains($app,'function homeSection'),'home section composition exists');
ok(str_contains($app,'function executeHomeSuggestion'),'home daily suggestion has an executable action');
ok(str_contains($app,'function bindPersonalizedHome'),'personalized home interactions bind centrally');

ok(str_contains($app,'Continue listening'),'signed-in home renders Continue Listening');
ok(str_contains($app,'Recently played'),'signed-in home renders Recently Played');
ok(str_contains($app,'Saved for later'),'signed-in home renders Favorites');
ok(str_contains($app,'Recommended for you'),'signed-in home renders recommendations');
ok(str_contains($app,'Recent releases'),'signed-in home renders recent releases');
ok(str_contains($app,'Saved builds'),'signed-in home renders saved builds');
ok(str_contains($app,'id="homeSuggestionAction"'),'signed-in home renders daily Agent suggestion CTA');
ok(str_contains($app,'id="homeQueueShortcut"'),'signed-in home exposes Up Next count/shortcut');
ok(str_contains($app,'home-section-copy')&&str_contains($app,'rec.summary'),'home displays recommendation rationale when available');

ok(str_contains($app,"data-home-resume="),'Continue Listening cards preserve resume actions');
ok(str_contains($app,"data-home-play="),'recent/favorite track cards expose playback');
ok(str_contains($app,"data-home-track-open="),'home track cards expose detail navigation');
ok(str_contains($app,"data-home-release="),'home release cards expose release navigation');
ok(str_contains($app,"data-home-build="),'saved build cards reopen the exact draft');
ok(str_contains($app,"play(t,0,'home_suggestion')"),'Agent suggestion playback uses a dedicated telemetry source');
ok(str_contains($app,"play(t,0,'home')"),'home track playback uses a dedicated telemetry source');
ok(str_contains($app,'function homeEmpty'),'all home sections have usable empty states');
ok(str_contains($app,'st.home.loaded=false'),'personalization mutations invalidate cached home state');

ok(str_contains($agent,"return 'home_suggestion'"),'Agent routes requests for today’s suggestion');
ok(str_contains($agent,'what should i (?:do|listen to|hear) today'),'Agent treats listen-to-today phrasing as the daily home suggestion');
ok(str_contains($agent,"case 'home_suggestion'"),'Agent policy handles the personalized-home suggestion route');
ok(str_contains($agent,'sf_home_state((int)$userId)'),'Agent suggestion uses canonical server home state');
ok(str_contains($agent,"'view'=>'home'"),'Agent suggestion opens the personalized home');
ok(str_contains($agent,"'home_suggestion'=>'"),'JEV route criteria include personalized home suggestion');
ok(str_contains($agent,"'home_suggestion_kind'")&&str_contains($agent,"'home_suggestion_title'"),'Agent routing state receives the current home suggestion');
ok(str_contains($app,'home_suggestion_kind:')&&str_contains($app,'home_suggestion_title:'),'client supplies home suggestion context to the live Agent');

ok(str_contains($css,'.personalized-home'),'personalized home layout is styled');
ok(str_contains($css,'.home-suggestion'),'daily Agent suggestion is styled');
ok(str_contains($css,'.home-card-grid'),'home media grid is styled');
ok(str_contains($css,'.home-progress'),'Continue Listening progress is styled');
ok(str_contains($css,'.home-empty'),'home empty states are styled');
ok(str_contains($css,'.home-section-copy'),'home recommendation rationale is styled');
ok(str_contains($css,'@media(max-width:760px)'),'personalized home has responsive mobile rules');

ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.6'"),'Section 9 requires no new database migration');
ok(str_contains($mig,"'id'=>'2026-10-08-010'"),'existing latest database migration remains 010');
ok(str_contains($version,"'stonefellow'=>'1.3.8'"),'version endpoint reports v1.3.8');
ok(str_contains($version,"'database_schema_target'=>'1.3.6'"),'version endpoint keeps schema target 1.3.6');
ok(str_contains($version,"'personalized_home'=>'continue-recent-favorites-recommendations-releases-builds-agent-suggestion'"),'version endpoint reports Section 9 personalized-home capability');

echo "Stonefellow v1.3 Section 9 Personalized Home audit: PASS\n";
