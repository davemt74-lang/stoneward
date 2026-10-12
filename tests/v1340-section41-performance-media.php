<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok41($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/performance-core.php';

$limits=sf_performance_metric_limits();
ok41(array_keys($limits)===['LCP','CLS','INP','FCP','TTFB'],'Web Vitals metric allowlist is fixed and bounded');
ok41($limits['LCP']['good']===2500.0&&$limits['CLS']['good']===0.1&&$limits['INP']['good']===200.0,'Core Web Vitals good thresholds match field guidance');
ok41(sf_performance_rating('LCP',2400)==='good'&&sf_performance_rating('LCP',3000)==='needs_improvement'&&sf_performance_rating('LCP',5000)==='poor','LCP ratings are deterministic');
ok41(sf_performance_rating('CLS',0.09)==='good'&&sf_performance_rating('CLS',0.2)==='needs_improvement'&&sf_performance_rating('CLS',0.4)==='poor','CLS ratings are deterministic');
ok41(sf_performance_percentile([1,2,3,4],.75)===3.0,'p75 calculation is deterministic');
$n=sf_performance_normalize_event(['metric'=>'lcp','value'=>1234.56,'route_type'=>'track','navigation_type'=>'navigate']);
ok41($n['metric']==='LCP'&&$n['value']===1234.6&&$n['route_type']==='track','performance payload is normalized and rounded');
ok41(sf_performance_normalize_event(['metric'=>'CLS','value'=>0.12345,'route_type'=>'private'])['route_type']==='other','unsupported route identity collapses to other');
$bad=false;try{sf_performance_normalize_event(['metric'=>'SECRET','value'=>1]);}catch(InvalidArgumentException $e){$bad=true;}ok41($bad,'unsupported metric is rejected');

$core=(string)file_get_contents($root.'/api/performance-core.php');
$api=(string)file_get_contents($root.'/api/performance.php');
$perf=(string)file_get_contents($root.'/assets/js/performance.js');
$mediaCore=(string)file_get_contents($root.'/api/media-core.php');
$mediaApi=(string)file_get_contents($root.'/api/media.php');
$growth=(string)file_get_contents($root.'/api/growth-analytics-core.php');
$analytics=(string)file_get_contents($root.'/admin/assets/analytics.js');
$adminCss=(string)file_get_contents($root.'/admin/assets/admin.css');
$app=(string)file_get_contents($root.'/assets/js/app.js');
$css=(string)file_get_contents($root.'/assets/css/site.css');
$shell=(string)file_get_contents($root.'/stonefellow-v120.php');
$sw=(string)file_get_contents($root.'/service-worker.js');
$ht=(string)file_get_contents($root.'/.htaccess');
$version=(string)file_get_contents($root.'/version.php');
$admin=(string)file_get_contents($root.'/admin/index.php');
$mig=(string)file_get_contents($root.'/api/migrations.php');
$wf=(string)file_get_contents($root.'/.github/workflows/release-gate.yml');

ok41(str_contains($core,"sf_log_site_event('info','web_vital'"),'field vitals reuse the existing operational event ledger');
ok41(!str_contains($core,'user_id')&&!str_contains($core,'REMOTE_ADDR')&&!str_contains($core,'referrer')&&!str_contains($core,'session_key'),'stored Web Vitals contain no user, IP, referrer or session identity');
ok41(str_contains($core,"'metric'=>\$e['metric']")&&str_contains($core,"'route_type'=>\$e['route_type']"),'stored metric detail is minimized to bounded performance fields');
ok41(str_contains($core,'function sf_performance_percentile')&&str_contains($core,"'p75'=>\$p75"),'performance summary computes p75 server-side');
ok41(str_contains($core,"LIMIT 10000"),'performance analytics query is bounded');
ok41(str_contains($api,"\$method==='POST'")&&str_contains($api,'count($attempts)>=40'),'public performance endpoint is POST-only and rate-limited');
ok41(!str_contains($api,'sf_current_user')&&!str_contains($api,'HTTP_REFERER'),'performance endpoint does not attach account/referrer identity');

ok41(str_contains($mediaCore,"'medium_url'=>")&&str_contains($mediaCore,"'large_url'=>")&&str_contains($mediaCore,"'srcset'=>"),'public Media Library payload exposes existing responsive image variants');
ok41(str_contains($mediaCore,"variant=thumb 320w")&&str_contains($mediaCore,"variant=medium 800w")&&str_contains($mediaCore,"variant=large 1600w"),'responsive public srcset uses 320/800/1600 generated variants');
ok41(str_contains($mediaApi,"else if(\$variant!=='original')\$variant='original'"),'missing variant safely falls back to original MIME/content');
ok41(str_contains($mediaApi,"\$etag='\"'.substr(hash('sha256'"),'public media has deterministic source+variant ETags');
ok41(str_contains($mediaApi,'public, max-age=3600, must-revalidate'),'public media caching is efficient but remains visibility-revocable');
ok41(!str_contains($mediaApi,'immutable'),'public media is not permanently cached after visibility can change');
ok41(str_contains($mediaApi,'HTTP_IF_NONE_MATCH')&&str_contains($mediaApi,'$etag')&&str_contains($mediaApi,'http_response_code(304)'),'public media supports conditional 304 responses');
ok41(str_contains($mediaApi,"private, no-store"),'Admin media remains no-store');

ok41(str_contains($perf,"type:'largest-contentful-paint'")&&str_contains($perf,"type:'layout-shift'")&&str_contains($perf,"type:'event'"),'browser runtime observes LCP, CLS and INP');
ok41(str_contains($perf,"getEntriesByName('first-contentful-paint')")&&str_contains($perf,"responseStart-nav.requestStart"),'browser runtime measures FCP and TTFB');
ok41(str_contains($perf,"img.decoding='async'")&&str_contains($perf,"img.loading='lazy'")&&str_contains($perf,"img.fetchPriority='high'"),'browser runtime applies decoding, lazy loading and LCP priority');
ok41(str_contains($perf,"variant','thumb'")&&str_contains($perf,"variant','medium'")&&str_contains($perf,"variant','large'"),'browser runtime builds responsive Media Library srcset candidates');
ok41(str_contains($perf,'new MutationObserver'),'AJAX-inserted images are optimized after SPA renders');
ok41(str_contains($perf,"document.addEventListener('stonefellow:navigation'"),'vital/image runtime follows SPA route changes');
ok41(str_contains($perf,"keepalive:true"),'vital delivery can complete during page lifecycle exit');

ok41(str_contains($app,'artist-home-hero-media')&&!str_contains($app,'--artist-home-hero:url'),'homepage hero is a real image instead of a CSS background');
ok41(str_contains($css,'.artist-home-hero-media img{display:block;width:100%;height:100%;object-fit:cover}'),'homepage hero has stable responsive image presentation');
ok41(str_contains($shell,'assets/js/performance.js?v=<?=rawurlencode(SF_BUILD)?>'),'public shell loads the Section 41 performance runtime');
ok41(str_contains($shell,'rel="preload" as="image"')&&str_contains($shell,'fetchpriority="high"'),'enabled Artist Homepage hero is preloaded with high fetch priority');
ok41(str_contains($shell,"define('SF_BUILD', '1.3.40')"),'public build advances to v1.3.40');

ok41(str_contains($growth,"'performance'=>\$performance"),'Core Web Vitals join existing Growth Intelligence');
ok41(str_contains($growth,'web vitals p75 LCP'),'Admin Agent brief receives grounded p75 vitals');
ok41(str_contains($analytics,'Core Web Vitals')&&str_contains($analytics,'Privacy-bounded public field measurements'),'Performance Intelligence visibly reports privacy-bounded field vitals');
ok41(str_contains($analytics,"['LCP','Largest Contentful Paint']")&&str_contains($analytics,"['CLS','Cumulative Layout Shift']")&&str_contains($analytics,"['INP','Interaction to Next Paint']"),'Performance Intelligence exposes the three Core Web Vitals');
ok41(str_contains($adminCss,'.web-vitals-strip .web-vital.good')&&str_contains($adminCss,'.web-vitals-strip .web-vital.poor'),'Core Web Vitals ratings have clear dashboard status treatment');

ok41(str_contains($sw,'stonefellow-static-v1.3.40'),'PWA static cache advances with Section 41');
ok41(str_contains($sw,"'assets/js/performance.js'")&&str_contains($sw,'(app|library|performance)'),'offline static shell includes the performance runtime');
ok41(str_contains($ht,'X-Stonefellow-Build "1.3.40"'),'server build header advances to v1.3.40');
ok41(str_contains($version,"'stonefellow'=>'1.3.40'")&&str_contains($version,"'database_schema_target'=>'1.3.32'"),'version endpoint reports v1.3.40 with unchanged DB target');
ok41(str_contains($version,"'responsive_media'=>'media-library-320-800-1600-srcset-lazy-priority'"),'version endpoint advertises responsive media capability');
ok41(str_contains($version,"'core_web_vitals'=>'lcp-cls-inp-fcp-ttfb-p75-privacy-bounded'"),'version endpoint advertises field performance intelligence');
ok41(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.32'")&&!str_contains($mig,"'app_version'=>'1.3.40'"),'Section 41 introduces no unnecessary database migration');
ok41(str_contains($admin,"define('SF_ADMIN_BUILD','1.3.40')"),'Admin asset build advances to v1.3.40');
ok41(substr_count($admin,'data-view="')===29,'Section 41 adds no Admin sidebar item');
ok41(str_contains($wf,'node --check assets/js/performance.js')&&str_contains($wf,'php tests/v1340-section41-performance-media.php'),'release gate certifies performance runtime and Section 41');

echo "Stonefellow v1.3.40 Section 41 Performance, Responsive Media & Core Web Vitals audit: PASS\n";
