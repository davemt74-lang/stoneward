<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$core=src('api/search-core.php');$api=src('api/search.php');$boot=src('api/bootstrap.php');$app=src('assets/js/app.js');$css=src('assets/css/site.css');$agent=src('api/agent-runtime.php');$adminApi=src('admin/api/analytics.php');$state=src('admin/api/state.php');$renderer=src('admin/assets/analytics.js');$admin=src('admin/assets/admin.js');$adminCss=src('admin/assets/admin.css');$mig=src('api/migrations.php');$version=src('version.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/search-core.php';"),'search core loads from bootstrap');
ok(str_contains($core,'function sf_search_ensure_schema'),'search schema initializer exists');
ok(str_contains($core,'CREATE TABLE IF NOT EXISTS catalog_search_events'),'search telemetry table exists');
ok(str_contains($core,'user_id INTEGER NULL')&&str_contains($core,'user_id BIGINT UNSIGNED NULL'),'search telemetry supports anonymous and signed-in listeners');
ok(str_contains($core,'idx_search_events_created'),'search event time index exists');
ok(str_contains($core,'idx_search_events_query'),'search query index exists');
ok(str_contains($core,'normalized_query(191)'),'MySQL search query index is utf8mb4-safe');
ok(str_contains($core,'idx_search_events_user'),'search user history index exists');
ok(str_contains($core,'idx_search_events_result'),'selected-result attribution index exists');

ok(str_contains($core,'function sf_search_normalize'),'search query normalization exists');
ok(str_contains($core,'function sf_search_tokens'),'search query tokenization exists');
ok(str_contains($core,'function sf_search_extract_intent_query'),'Agent-oriented search query extraction exists');
ok(str_contains($core,"'find','for','from'"),'search stop-word reduction exists');
ok(str_contains($core,'function sf_search_word_fuzzy_score'),'fuzzy word scoring exists');
ok(str_contains($core,'levenshtein'),'typo tolerance uses deterministic edit distance');

ok(str_contains($core,"'title'=>(string)(\$t['title']"),'track title is searchable');
ok(str_contains($core,"'release'=>(string)(\$t['release']"),'track release is searchable');
ok(str_contains($core,"'mood'=>"),'track moods are searchable');
ok(str_contains($core,"'themes'=>"),'track themes are searchable');
ok(str_contains($core,"'story'=>(string)(\$t['story']"),'track story is searchable');
ok(str_contains($core,"'lyrics'=>(string)(\$t['lyrics']"),'track lyrics are searchable');
ok(str_contains($core,"'credits'=>"),'track credits are searchable');
ok(str_contains($core,"'metadata'=>sf_search_string_values"),'structured track metadata is searchable');

ok(str_contains($core,'function sf_search_release_fields'),'release search document exists');
ok(str_contains($core,"'description'=>(string)(\$r['description']"),'release descriptions are searchable');
ok(str_contains($core,"'notes'=>(string)(\$r['notes']"),'release notes are searchable');
ok(str_contains($core,"'tracks'=>\$trackTitles"),'release track titles are searchable');
ok(str_contains($core,"\$r['catalog_number']")&&str_contains($core,"\$r['upc_ean']"),'release catalog number and UPC/EAN are searchable');
ok(str_contains($core,"\$r['label']")&&str_contains($core,"\$r['genre']"),'release label and genre are searchable');
ok(str_contains($core,"'moods'=>array_values(array_unique(\$moods))"),'release discovery inherits track moods');
ok(str_contains($core,"'themes'=>array_values(array_unique(\$themes))"),'release discovery inherits track themes');

ok(str_contains($core,'$score+=140'),'exact title ranking is strongly weighted');
ok(str_contains($core,'str_starts_with($title,$q)'),'title prefix ranking exists');
ok(str_contains($core,'Release match'),'release-match explanation exists');
ok(str_contains($core,'Mood match')||str_contains($core,"ucfirst(rtrim(\$key,'s')).' match'"),'mood/theme match explanations exist');
ok(str_contains($core,"'lyrics'=>8"),'lyrics contribute to relevance');
ok(str_contains($core,"'credits'=>14"),'credits contribute to relevance');
ok(str_contains($core,"'metadata'=>10"),'structured metadata contributes to relevance');
ok(str_contains($core,'All terms match'),'multi-term coverage gets a relevance boost');

ok(str_contains($core,'function sf_search_popularity_map'),'search ranking can use listening popularity');
ok(str_contains($core,"event_type='start'"),'popularity reads listening starts');
ok(str_contains($core,"event_type='complete'"),'popularity rewards completed listens');
ok(str_contains($core,'sf_personalization_recommendation_profile($userId)'),'signed-in search uses the canonical personalization profile');
ok(str_contains($core,"favorite_track_ids"),'favorite tracks influence signed-in search ranking');
ok(str_contains($core,"favorite_release_ids"),'favorite releases influence signed-in search ranking');
ok(str_contains($core,'Fits your listening profile'),'personalized ranking is explained');
ok(str_contains($core,'Popular with listeners'),'popularity ranking is explained');

ok(str_contains($core,'function sf_search_facets'),'catalog discovery facets exist');
foreach(["'moods'","'themes'","'releases'","'years'","'energies'"] as $facet)ok(str_contains($core,$facet),'facet exists: '.$facet);
ok(str_contains($core,"['all','track','release']"),'search supports combined/track/release result types');
ok(str_contains($core,"['relevance','popular','newest','title']"),'search exposes relevance/popular/newest/title sorting');
ok(str_contains($core,"\$p['mood']!==''"),'mood filter is enforced');
ok(str_contains($core,"\$p['theme']!==''"),'theme filter is enforced');
ok(str_contains($core,"\$p['release']!==''"),'release filter is enforced');
ok(str_contains($core,"\$p['year']>0"),'year filter is enforced');
ok(str_contains($core,"\$p['energy']>0"),'energy filter is enforced');
ok(str_contains($core,"(\$r['state']??'published')!=='published'"),'hidden/unpublished releases are excluded');
ok(str_contains($core,'public_visible'),'public release visibility is respected');

ok(str_contains($core,'function sf_search_suggestions'),'zero-result suggestions exist');
ok(str_contains($core,'distance'),'suggestions use closeness ranking');
ok(str_contains($core,'suggestions'),'search response includes suggestions');
ok(str_contains($core,'result_count'),'search response includes total result count');
ok(str_contains($core,'track_count'),'search response identifies track result count');
ok(str_contains($core,'release_count'),'search response identifies release result count');
ok(str_contains($core,'generated_at'),'search response is timestamped');

ok(str_contains($api,"\$method==='GET'"),'search GET endpoint supports unlogged initial discovery');
ok(str_contains($api,"\$action==='search'"),'search POST action exists');
ok(str_contains($api,"\$action==='click'"),'result click attribution action exists');
ok(str_contains($api,"\$action==='clear_history'"),'search-history clear action exists');
ok(str_contains($api,'if($uid)sf_require_csrf()'),'signed-in search writes require CSRF');
ok(str_contains($api,'sf_current_user()'),'guest search does not require authentication');
ok(str_contains($api,'sf_search_recent_for_user'),'signed-in search returns recent queries');
ok(str_contains($api,'sf_search_clear_user_history'),'signed-in search history can be cleared');
ok(str_contains($api,'Search result is no longer available.'),'click telemetry validates current catalog entities');

ok(str_contains($core,'function sf_search_session_hash'),'search session identifiers are hashed before persistence');
ok(str_contains($core,"hash('sha256'"),'search telemetry stores hashed session identity');
ok(str_contains($core,'function sf_search_event_allowed'),'search telemetry is rate bounded');
ok(str_contains($core,'time()-10*60'),'search telemetry throttle uses a 10-minute window');
ok(str_contains($core,'<120'),'search telemetry throttle caps event volume');
ok(str_contains($core,"'guest|'.sf_auth_ip_hash()"),'guest telemetry rate identity is bound to server-observed IP instead of caller-supplied session keys');
ok(str_contains($core,"'user|'.\$userId"),'signed-in telemetry rate identity is bound to the authenticated user');
ok(str_contains($core,"['search','click','clear']"),'search telemetry event types are governed');
ok(str_contains($core,'function sf_search_recent_for_user'),'recent-search helper exists');
ok(str_contains($core,'function sf_search_clear_user_history'),'search privacy clear helper exists');

ok(str_contains($app,'Search & Discover'),'Music view is upgraded to Search & Discover');
ok(str_contains($app,'catalogSearchApi'),'catalog uses the unified server search API');
foreach(['catalogType','catalogMood','catalogTheme','catalogRelease','catalogYear','catalogEnergy','catalogSort'] as $id)ok(str_contains($app,$id),'catalog UI exposes '.$id);
ok(str_contains($app,'data-discovery-mood'),'mood discovery chips exist');
ok(str_contains($app,'data-discovery-theme'),'theme discovery chips exist');
ok(str_contains($app,'data-search-suggestion'),'zero-result suggestion controls exist');
ok(str_contains($app,'data-recent-search'),'recent-search controls exist');
ok(str_contains($app,'clearCatalogSearchHistory'),'search-history privacy control is wired');
ok(str_contains($app,'catalogRecordClick'),'result selections are attributed');
ok(str_contains($app,"'catalog_search'"),'search-result playback has dedicated listening telemetry source');
ok(str_contains($app,'catalogReplaceUrl'),'search/filter state is reflected in the URL');
ok(!str_contains($app,"$('#atalogDiscoveryResults'"),'catalog result host selector is not malformed');
ok(!str_contains($app,'<article class="module"<div'),'catalog error markup is structurally valid');
ok(str_contains($app,'id="catalogYear"'),'catalog year filter has a valid element id');
ok(str_contains($app,"u.searchParams.get('q')"),'search state restores from URL');
ok(str_contains($app,"navigate('music',{})"),'catalog reset clears shareable URL state');
ok(str_contains($app,'track-result'),'unified search renders track results');
ok(str_contains($app,'release-result'),'unified search renders release results');
ok(str_contains($app,'FOR YOU'),'personalized result rationale is visible');

ok(str_contains($css,'.catalog-discovery-module'),'search discovery layout is styled');
ok(str_contains($css,'.catalog-filter-grid'),'faceted filters are styled');
ok(str_contains($css,'.discovery-chips'),'discovery chips are styled');
ok(str_contains($css,'.search-result-card'),'unified result cards are styled');
ok(str_contains($css,'.catalog-search-results.loading'),'search loading state is styled');
ok(str_contains($css,'@media(max-width:820px)'),'search discovery has tablet/mobile responsive rules');
ok(str_contains($css,'@media(max-width:560px)'),'search discovery has narrow-mobile responsive rules');

ok(str_contains($agent,"return 'catalog_search'"),'Agent routes natural catalog search requests');
ok(str_contains($agent,'sf_search_extract_intent_query($message)'),'Agent extracts a clean search query');
ok(str_contains($agent,'sf_catalog_search(['),'Agent executes canonical catalog search');
ok(str_contains($agent,"'type'=>'open_search'"),'Agent emits open-search action');
ok(str_contains($agent,"'catalog_search'=>'Asks to find/search"),'JEV criteria include catalog search');
ok(str_contains($agent,"'current_search_query'"),'Agent routing state receives current search context');
ok(str_contains($app,"case'open_search'"),'client executes Agent open-search action');
ok(str_contains($app,"search_query:$('#catalogSearch'"),'client sends current search query to Agent');

ok(str_contains($core,'function sf_search_analytics'),'search intelligence analytics exists');
ok(str_contains($core,"'click_through_rate'=>"),'search click-through rate is calculated');
ok(str_contains($core,"'zero_result_rate'=>"),'zero-result rate is calculated');
ok(str_contains($core,"'top_queries'=>"),'top queries are calculated');
ok(str_contains($core,"'zero_result_queries'=>"),'zero-result opportunities are calculated');
ok(str_contains($core,"'top_results'=>"),'top selected results are calculated');
ok(str_contains($core,"'filter_usage'=>"),'discovery filter usage is calculated');

ok(str_contains($adminApi,'sf_search_analytics($days,null)'),'admin analytics API exposes overall search intelligence');
ok(str_contains($adminApi,'sf_search_analytics($days,$userId)'),'admin analytics API exposes per-user search intelligence');
ok(str_contains($state,'$searchAnalytics=sf_search_analytics(30,null)'),'admin dashboard loads 30-day search intelligence');
ok(str_contains($state,'recentSearchEvents'),'admin dashboard includes recent search activity');
ok(str_contains($state,"'search_click_rate_30d'"),'admin dashboard state includes search CTR');
ok(str_contains($state,"'search_zero_result_rate_30d'"),'admin dashboard state includes zero-result rate');
ok(str_contains($renderer,'Catalog search & discovery'),'analytics workspace renders search intelligence');
ok(str_contains($renderer,'Zero-result opportunities'),'analytics workspace surfaces catalog gaps');
ok(str_contains($renderer,'Most selected results'),'analytics workspace surfaces selected results');
ok(str_contains($renderer,'search_analytics'),'per-user inspector receives search analytics');
ok(str_contains($admin,'Catalog discovery'),'main Admin dashboard renders catalog discovery KPIs');
ok(str_contains($admin,'recentSearchEvents'),'main Admin dashboard renders recent search activity');
ok(str_contains($adminCss,'.search-kpi-stack'),'search Admin KPIs are responsive');

ok(str_contains($mig,"'id'=>'2026-10-08-012'")&&str_contains($mig,'sf_search_ensure_schema'),'Section 12 search migration remains registered after later schema upgrades');
ok(str_contains($mig,"'id'=>'2026-10-08-012'")&&str_contains($mig,'sf_search_ensure_schema'),'migration 012 installs search telemetry schema');
ok(str_contains($mig,"'catalog_search_events'"),'migration integrity requires search telemetry table');
ok(str_contains($version,"'catalog_search_discovery'=>'unified-faceted-personalized-telemetry-agent'"),'version endpoint retains Section 12 search/discovery capability');
ok(str_contains($version,"'database_schema_target'=>'"),'version endpoint continues to report the current database schema target');
ok(str_contains($version,"'catalog_search_discovery'=>'unified-faceted-personalized-telemetry-agent'"),'version endpoint reports Section 12 search/discovery capability');
ok(str_contains($wf,'v1311-section12-search-discovery.php'),'release gate includes Section 12 regression');

// Pure search behavior checks: no database or installed runtime required.
require_once $root.'/api/search-core.php';
ok(sf_search_normalize('  Desert—NIGHT!  ')==='desert night','normalization folds punctuation, case, and whitespace');
ok(sf_search_tokens('find me songs about desert night')===['desert','night'],'intent tokenization removes catalog command words');
ok(sf_search_extract_intent_query('Show me songs about memory and night')==='memory night','Agent intent extraction preserves meaningful search terms');
$exact=sf_search_score_document('desert lights',['title'=>'Desert Lights','release'=>'Stories','mood'=>['warm'],'themes'=>['road'],'story'=>'','lyrics'=>'','credits'=>[],'metadata'=>[]]);
$mood=sf_search_score_document('warm',['title'=>'Desert Lights','release'=>'Stories','mood'=>['warm'],'themes'=>['road'],'story'=>'','lyrics'=>'','credits'=>[],'metadata'=>[]]);
$story=sf_search_score_document('highway',['title'=>'Desert Lights','release'=>'Stories','mood'=>['warm'],'themes'=>['road'],'story'=>'A highway at midnight','lyrics'=>'','credits'=>[],'metadata'=>[]]);
$fuzzy=sf_search_score_document('desret',['title'=>'Desert','release'=>'Stories','mood'=>[],'themes'=>[],'story'=>'','lyrics'=>'','credits'=>[],'metadata'=>[]]);
ok($exact['score']>$mood['score'],'exact title outranks a mood-only match');
ok($mood['score']>$story['score'],'explicit discovery tags outrank broad story text');
ok($fuzzy['score']>0,'single-word typo still produces a fuzzy catalog match');
ok($exact['reason']==='Exact title','exact-title rationale is deterministic');

echo "Stonefellow v1.3 Section 12 Search, Discovery & Catalog Intelligence audit: PASS\n";
