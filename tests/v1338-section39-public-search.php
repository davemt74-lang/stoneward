<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok39($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
$core=(string)file_get_contents($root.'/api/search-core.php');
$api=(string)file_get_contents($root.'/api/site-search.php');
$catalogApi=(string)file_get_contents($root.'/api/search.php');
$app=(string)file_get_contents($root.'/assets/js/app.js');
$css=(string)file_get_contents($root.'/assets/css/site.css');
$shell=(string)file_get_contents($root.'/stonefellow-v120.php');
$ht=(string)file_get_contents($root.'/.htaccess');
$seo=(string)file_get_contents($root.'/api/seo-core.php');
$agent=(string)file_get_contents($root.'/api/agent-runtime.php');
$analyticsApi=(string)file_get_contents($root.'/admin/api/analytics.php');
$analyticsJs=(string)file_get_contents($root.'/admin/assets/analytics.js');
$state=(string)file_get_contents($root.'/admin/api/state.php');
$adminShell=(string)file_get_contents($root.'/admin/index.php');
$mig=(string)file_get_contents($root.'/api/migrations.php');
$version=(string)file_get_contents($root.'/version.php');
$wf=(string)file_get_contents($root.'/.github/workflows/release-gate.yml');

require_once $root.'/api/search-core.php';
ok39(sf_site_search_types()===['all','track','release','show','story','video','photo','product','campaign'],'site search exposes only the governed fan-facing public result types');
ok39(sf_site_search_params(['q'=>str_repeat('x',400),'type'=>'private','limit'=>500])['type']==='all','unsupported site-search result type falls back to all');
ok39(strlen(sf_site_search_params(['q'=>str_repeat('x',400)])['q'])===300,'site-search query is bounded to 300 characters');
ok39(sf_site_search_params(['limit'=>500])['limit']===60,'site-search result limit is bounded');
ok39(sf_search_normalize('  Photos — DESERT!  ')==='photos desert','site search reuses deterministic Section 12 normalization');
ok39(sf_search_score_document('desert',['title'=>'Desert Archive','description'=>''])['score']>0,'site search reuses deterministic Section 12 relevance scoring');

ok39(str_contains($core,'function sf_site_search('),'site-wide public search entry point exists');
foreach(['sf_site_search_public_tracks','sf_site_search_public_releases','sf_site_search_public_shows','sf_site_search_public_stories','sf_site_search_public_videos','sf_site_search_public_photos','sf_site_search_public_products','sf_site_search_public_campaigns'] as $fn)ok39(str_contains($core,'function '.$fn),'public search loader exists: '.$fn);
ok39(str_contains($core,'sf_seo_public_track($id)'),'track results require canonical public track eligibility');
ok39(str_contains($core,'sf_seo_public_release($id)'),'release results require canonical public release eligibility');
ok39(str_contains($core,'sf_live_public_shows()'),'show results use public show records');
ok39(str_contains($core,"sf_story_list(true,''"),'story results use published-ready public stories');
ok39(str_contains($core,'sf_video_public_list()'),'video results use published-ready Video Hub records');
ok39(str_contains($core,'sf_gallery_public_list()'),'photo results use published-ready galleries');
ok39(str_contains($core,'sf_commerce_products(true)'),'store results use active public commerce records');
ok39(str_contains($core,'sf_campaign_is_live($c)'),'offer results require a currently live campaign');
ok39(str_contains($core,"\$title=sf_clean_text((string)(\$landing['headline']??''),220)")&&!str_contains($core,"['description'=>\$snippet,'metadata'=>[(string)(\$c['name']??'')]]"),'public offer search uses public landing copy rather than internal campaign names');
ok39(!str_contains($core,"'press'=>'")&&!str_contains($core,'sf_press_kits'),'public site search intentionally excludes EPK/press-contact content');
ok39(!str_contains($core,'professional_inquir')&&!str_contains($core,'inquiry_'),'public site search does not index professional inquiry records');
ok39(str_contains($core,"unset(\$fields['notes'],\$fields['archive'])"),'site-wide release search strips private/internal release notes from its search document');
$publicTrackFields=sf_site_search_public_track_fields(['id'=>'demo','title'=>'Demo','release'=>'R','story'=>'Public story','mood'=>['quiet'],'themes'=>['night'],'credits'=>['Writer'],'metadata'=>['private'=>'secret'],'archive'=>['source_notes'=>'internal']]);
ok39(!array_key_exists('metadata',$publicTrackFields)&&!array_key_exists('archive',$publicTrackFields),'public track search document excludes generic internal metadata/archive fields');
ok39(str_contains($core,'sf_site_search_public_track_fields($t)'),'site-wide track search uses its public-only field projection');
ok39(str_contains($core,"'lyrics'=>\$songbook?"),'public track search includes lyrics only through published Songbook gating');
ok39(str_contains($core,'sf_site_search_image_from_media'),'site search images resolve from governed public media payloads');

ok39(str_contains($api,"\$method==='GET'"),'anonymous GET site search exists');
ok39(str_contains($api,"\$action==='search'")&&str_contains($api,"\$action==='click'")&&str_contains($api,"\$action==='clear_history'"),'site search API supports search/click/privacy-clear actions');
ok39(str_contains($api,'if($uid)sf_require_csrf()'),'signed-in site-search writes require CSRF');
ok39(str_contains($api,'sf_site_search_result_exists($type,$id)'),'site-search click attribution revalidates current public availability');
ok39(str_contains($api,'Search result is no longer public or available.'),'stale/private result clicks are rejected');
ok39(str_contains($core,"['site_search','site_click']"),'site-wide telemetry uses distinct event names in the existing search table');
ok39(str_contains($core,'sf_search_event_allowed($hash)'),'site-wide telemetry reuses the existing bounded rate policy');
ok39(str_contains($core,"event_type='site_search'"),'site-search recent history is isolated from catalog history');
ok39(str_contains($core,"event_type IN ('site_search','site_click')"),'site-search privacy clear deletes only site-search events');
ok39(str_contains($core,"event_type IN ('search','click','clear')"),'Section 12 analytics/history remain isolated from site-search events');
ok39(str_contains($state,"WHERE e.event_type IN ('search','click','clear')"),'Admin recent catalog-search feed remains catalog-only');

$build39='0.0.0';if(preg_match("/define\('SF_BUILD', '([^']+)'\)/",$shell,$bm39))$build39=$bm39[1];ok39(version_compare($build39,'1.3.38','>='),'public shell remains Section 39 compatible or newer');
ok39(str_contains($shell,"\$publicInitialViews[]='search'"),'server pretty-route handoff explicitly appends Search without disturbing certified route order');
ok39(str_contains($shell,'href="search" data-view="search"'),'public navigation exposes Search');
ok39(substr_count($adminShell,'data-view="')===29,'public Search adds no Admin sidebar item');
ok39(str_contains($ht,'^search/?$')&&str_contains($ht,'view=search'),'Apache serves canonical /search');
$buildHeaderMatches=[];preg_match('/X-Stonefellow-Build "([0-9.]+)"/',$ht,$buildHeaderMatches);
ok39(isset($buildHeaderMatches[1])&&version_compare($buildHeaderMatches[1],'1.3.39','>='),'server build header remains Section 39 compatible or newer');
ok39(str_contains($seo,"if(\$view==='search')")&&str_contains($seo,"'noindex,follow,noarchive'"),'Search has dedicated noindex/follow SEO policy');
ok39(str_contains($seo,"sf_seo_base_url().'/search'"),'Search canonical strips query/filter duplicates');
ok39(!str_contains($seo,"\$add(\$base.'/search'"),'Search page is intentionally absent from the public sitemap');

ok39(str_contains($app,'function siteSearchResultHtml'),'public Search renders dedicated result cards');
ok39(str_contains($app,"['all','track','release','show','story','video','photo','product','campaign']"),'client Search exposes the same governed result type set as the server');
ok39(str_contains($app,"api('site-search.php?")&&str_contains($app,"secureJsonPost('site-search.php'"),'client uses anonymous GET and governed signed-in POST paths');
ok39(str_contains($app,'data-site-search-open')&&str_contains($app,'siteSearchRecordClick'),'result selection is attributed before navigation');
ok39(str_contains($app,"navigate('search',{q:a.query||''"),'Agent open-site-search action routes into public Search');
ok39(str_contains($app,"else if(view==='search'){await renderSiteSearch(params)}"),'SPA navigation renders the Search route');
ok39(str_contains($app,"if(view==='search')")&&str_contains($app,"new URL('search',base)"),'SPA canonical URL builder supports Search');
ok39(str_contains($app,"e.key==='/'")&&str_contains($app,"e.ctrlKey||e.metaKey")&&str_contains($app,"e.key.toLowerCase()==='k'"),'keyboard shortcuts open/focus public Search');
ok39(str_contains($app,"\$\$('[data-site-search-open]',host).forEach")&&str_contains($app,"\$\$('[data-site-recent]',host).forEach")&&str_contains($app,"\$\$('[data-site-suggestion]',suggestions).forEach"),'site Search collection handlers use the collection selector helper consistently');
ok39(str_contains($app,'esc(r.title)')&&str_contains($app,'esc(r.snippet)'),'Search result title/snippet are escaped before HTML rendering');
ok39(str_contains($css,'.site-search-module')&&str_contains($css,'.site-search-result-main')&&str_contains($css,'@media(max-width:640px)'),'Search has dedicated responsive presentation');
ok39(str_contains($css,'.site-search-head h1{font:400 clamp(2.6rem,6vw,5.4rem)/.95 var(--display-font)')&&str_contains($css,'.site-search-copy strong{font:400 1.2rem var(--display-font)'),'Search typography inherits the governed artist display font');
ok39(str_contains($css,'.site-search-art{')&&str_contains($css,'background:var(--surface)')&&str_contains($css,'.site-search-copy em{font-style:normal;font-size:.6rem;color:var(--muted)'),'Search surfaces and secondary copy inherit governed theme variables');

ok39(str_contains($agent,"return 'site_search'"),'local Agent router can choose site-wide Search');
ok39(str_contains($agent,"case 'site_search':"),'Agent policy executes deterministic site-wide Search');
ok39(str_contains($agent,"sf_site_search(['q'=>\$query,'limit'=>8])"),'Agent queries the same canonical public search core');
ok39(str_contains($agent,"'type'=>'open_site_search'"),'Agent emits a public Search navigation action');
ok39(str_contains($agent,"'site_search'=>'Asks to search across the public Stonefellow site"),'JEV route criteria include site-wide Search');
ok39(str_contains($app,"search_query:$('#siteSearchInput'"),'Agent client state includes current site-search query');

ok39(str_contains($core,'function sf_site_search_analytics'),'separate site-search intelligence exists');
ok39(str_contains($core,"event_type IN ('site_search','site_click')"),'site-search analytics reads only site-wide events');
ok39(str_contains($core,"'click_through_rate'=>")&&str_contains($core,"'zero_result_rate'=>"),'site-search analytics measures CTR and zero-result rate');
ok39(str_contains($core,"'type_usage'=>"),'site-search category usage is measured');
ok39(str_contains($analyticsApi,'sf_site_search_analytics($days,null)'),'Admin analytics exposes overall site-search intelligence');
ok39(str_contains($analyticsApi,'sf_site_search_analytics($days,$userId)'),'Admin analytics exposes per-user site-search intelligence');
ok39(str_contains($analyticsJs,'Site-wide search')&&str_contains($analyticsJs,'Most selected public results'),'Performance Intelligence renders a distinct site-search panel');
ok39(str_contains($analyticsJs,'Catalog search & discovery'),'existing Section 12 catalog analytics panel remains present');

ok39(str_contains($core,"return hash('sha256','user|'.\$userId);")&&!str_contains($core,"'user|'.\$userId.'|'"),'signed-in search telemetry throttle identity cannot be rotated by a caller-supplied session key');
ok39(str_contains($app,"if(view==='search'){const u=finish(new URL('search',base))")&&str_contains($app,"u.searchParams.set('q'")&&str_contains($app,"u.searchParams.set('type'"),'public Search keeps canonical /search while preserving bounded query/category state');
ok39(str_contains($app,'const current=await siteSearchRecordClick')&&str_contains($app,"if(!current){say('That search result is no longer public or available.')"),'client refuses navigation when a selected result has become private or unavailable');
ok39(str_contains($app,"view=history.state?.view||window.STONEFELLOW_INITIAL_ROUTE?.view")&&str_contains($app,"endsWith('/search')?'search':'home'"),'Agent client state preserves Search view across pretty-route and SPA navigation');
ok39(!str_contains($core,"'metadata'=>\$tags")&&str_contains($core,"sf_site_search_add(\$rows,\$query,'product'"),'public product search does not index internal merch tags');
ok39(str_contains($core,"\$headline=trim((string)(\$landing['headline']??''))")&&!str_contains($core,"\$x['headline']??\$x['name']"),'zero-result offer suggestions use public landing headlines only');

$app39='0.0.0';if(preg_match("/'stonefellow'=>'([^']+)'/",$version,$vm39))$app39=$vm39[1];ok39(version_compare($app39,'1.3.38','>='),'version endpoint remains Section 39 compatible after later releases');
$schema39='0.0.0';if(preg_match("/'database_schema_target'=>'([^']+)'/",$version,$dm39))$schema39=$dm39[1];ok39(version_compare($schema39,'1.3.32','>='),'database target remains Section 39 compatible or newer');
ok39(str_contains($version,"'public_site_search'=>'songs-releases-shows-stories-videos-photos-products-live-offers'"),'version endpoint advertises unified public search');
$migSchema39='0.0.0';if(preg_match("/const SF_DB_SCHEMA_TARGET = '([^']+)'/",$mig,$mm39))$migSchema39=$mm39[1];ok39(version_compare($migSchema39,'1.3.32','>='),'migration target remains Section 39 compatible or newer');
ok39(!str_contains($mig,"'app_version'=>'1.3.38'"),'Section 39 introduces no unnecessary database migration');
ok39(str_contains($mig,"'id'=>'2026-10-08-012'")&&str_contains($mig,'sf_search_ensure_schema'),'existing migration 012 remains the canonical search telemetry schema');
ok39(str_contains($wf,'php tests/v1338-section39-public-search.php'),'release gate includes Section 39 certification');

echo "Stonefellow v1.3.38 Section 39 Unified Public Search & Discovery audit: PASS\n";
