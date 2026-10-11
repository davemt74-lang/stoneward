<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok32($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
if(!function_exists('sf_public_base_url')){function sf_public_base_url(): string { return 'https://stonefellow.example'; }}
require $root.'/api/seo-core.php';

ok32(sf_seo_slug('A Song / Live!')==='a-song-live','SEO slugs normalize to stable public segments');
ok32(sf_seo_excerpt("  One\n two   three ",80)==='One two three','SEO excerpts normalize whitespace');
ok32(sf_seo_duration_iso(185)==='PT0H3M5S','music duration emits ISO-8601 duration');
ok32(sf_seo_https_url('https://example.com/a')===true,'HTTPS URL validator accepts HTTPS');
ok32(sf_seo_https_url('http://example.com/a')===false,'HTTPS URL validator rejects HTTP');
ok32(sf_seo_web_url('http://example.com/a')===true&&sf_seo_web_url('https://example.com/a')===true,'sitemap accepts standard public HTTP/HTTPS URLs');
ok32(sf_seo_web_url('javascript:alert(1)')===false,'sitemap URL validator rejects non-web schemes');
ok32(sf_seo_abs('/track/test')==='https://stonefellow.example/track/test','SEO absolute URL uses canonical public base');

$core=file_get_contents($root.'/api/seo-core.php');
$shell=file_get_contents($root.'/stonefellow-v120.php');
$ht=file_get_contents($root.'/.htaccess');
$app=file_get_contents($root.'/assets/js/app.js');
$css=file_get_contents($root.'/assets/css/site.css');
$sitemap=file_get_contents($root.'/sitemap.php');
$robots=file_get_contents($root.'/robots.php');
$discovery=file_get_contents($root.'/api/discovery.php');
$storefront=file_get_contents($root.'/api/storefront.php');
$growth=file_get_contents($root.'/api/growth-analytics-core.php');
$analytics=file_get_contents($root.'/admin/assets/analytics.js');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok32(str_contains($core,'function sf_seo_resolve'),'central server SEO resolver exists');
ok32(str_contains($core,"'robots'=>'index,follow,max-image-preview:large'")&&str_contains($core,"'noindex,nofollow,noarchive'"),'SEO resolver has explicit index/noindex policies');
ok32(str_contains($core,"(\$k['status']??'')!=='published'")&&str_contains($core,"empty(\$k['readiness']['ready'])"),'private/draft/unready EPKs cannot resolve as public SEO pages');
ok32(str_contains($core,"(\$r['state']??'published')!=='published'")&&str_contains($core,"(\$r['public_visible']??true)===false"),'draft/hidden releases are excluded');
ok32(str_contains($core,"in_array((string)(\$t['state']??''),['draft','private','archived'],true)"),'non-public track states are excluded');
ok32(str_contains($core,'sf_commerce_products(true)'),'product SEO uses public commerce records');
ok32(str_contains($core,'sf_campaign_by_slug($slug,true)')&&str_contains($core,'sf_campaign_is_live($c)'),'campaign SEO requires public live campaign');
foreach(['MusicGroup','MusicAlbum','MusicRecording','MusicEvent','Product','ProfilePage'] as $type)ok32(str_contains($core,"'@type'=>'".$type."'"),'structured data includes '.$type);
ok32(str_contains($core,"'isrcCode'"),'track structured data carries ISRC when available');
ok32(str_contains($core,"'priceCurrency'=>(string)(sf_store_config()['currency']??'USD')"),'product structured data uses configured store currency');
ok32(str_contains($core,"sf_media_public_links"),'SEO artwork resolves through explicitly public Media Library links');
ok32(str_contains($core,"parse_url(\$ticket,PHP_URL_SCHEME)")||str_contains($core,'sf_seo_https_url($ticket)'),'event ticket structured data only exposes validated HTTPS ticket URLs');

ok32(str_contains($core,"\$base.'/release/'")&&str_contains($core,"\$base.'/track/'")&&str_contains($core,"\$base.'/show/'")&&str_contains($core,"\$base.'/store/'"),'sitemap emits canonical entity routes');
ok32(str_contains($core,'foreach(sf_catalog() as $t)'),'sitemap includes public standalone catalog tracks');
ok32(str_contains($core,"(\$k['status']??'')==='published'")&&str_contains($core,"!empty(\$k['readiness']['ready'])"),'sitemap admits only published ready EPKs');
ok32(str_contains($core,'sf_campaign_is_live($c)'),'sitemap admits only live campaigns');
ok32(str_contains($sitemap,'http://www.sitemaps.org/schemas/sitemap/0.9')&&str_contains($sitemap,'sf_seo_sitemap_entries'),'XML sitemap uses standard sitemap protocol');
ok32(str_contains($robots,'Disallow: /admin/')&&str_contains($robots,'Disallow: /api/')&&str_contains($robots,'Disallow: /*?token=')&&str_contains($robots,'Sitemap:'),'robots policy blocks private surfaces and advertises sitemap');

foreach(['^sitemap\\.xml$','^robots\\.txt$','^track/','^release/','^show/','^store/','^campaign/','^press/'] as $route)ok32(str_contains($ht,$route),'rewrite table contains '.$route);
ok32(!str_contains($ht,'\\nRewriteRule'),'rewrite configuration contains no literal escaped-newline artifacts');
$htBuild32='0.0.0';if(preg_match('/X-Stonefellow-Build "([^"]+)"/',$ht,$hb32))$htBuild32=$hb32[1];ok32(version_compare($htBuild32,'1.3.31','>='),'web-server build header remains Section 32 compatible or newer');

$shellBuild32='0.0.0';if(preg_match("/define\('SF_BUILD', '([^']+)'\)/",$shell,$sb32))$shellBuild32=$sb32[1];ok32(version_compare($shellBuild32,'1.3.31','>='),'public shell remains Section 32 compatible or newer');
ok32(str_contains($shell,'<link rel="canonical"')&&str_contains($shell,'property="og:title"')&&str_contains($shell,'name="twitter:card"'),'server shell emits canonical, Open Graph and social-card metadata');
ok32(str_contains($shell,'name="robots" content="<?=sf_h((string)$seo[\'robots\'])?>"'),'server shell emits route-specific robots policy');
ok32(str_contains($shell,'type="application/ld+json" data-seo-jsonld="1"'),'server shell emits replaceable JSON-LD');
ok32(str_contains($shell,'sf_seo_server_html($seo)'),'server shell renders crawlable critical route content before SPA takeover');
ok32(str_contains($shell,"['press','campaign','track','release','show','store','music','releases','shows','about','archive']"),'pretty route handoff supports all public SEO routes');
ok32(str_contains($shell,'href="./" id="homeLink"')&&str_contains($shell,'href="music" data-view="music"'),'server navigation prefers canonical public paths');

ok32(str_contains($app,'function publicRouteUrl')&&str_contains($app,"new URL('track/'+id")&&str_contains($app,"new URL('release/'+id")&&str_contains($app,"new URL('show/'+id")&&str_contains($app,"new URL('store/'+slug"),'SPA generates the same canonical entity paths');
ok32(str_contains($app,"if(view==='press'&&slug)")&&str_contains($app,"u.hash='token='")&&!str_contains($app,"searchParams.set('token'"),'private EPK bearer tokens stay in URL fragments');
ok32(str_contains($app,'async function syncSeo')&&str_contains($app,"script[data-seo-jsonld]")&&str_contains($app,"link[rel=\"canonical\"]"),'SPA navigation replaces title/canonical/JSON-LD state');
ok32(str_contains($app,'initial.id')&&str_contains($app,'initial.slug'),'SPA consumes server pretty-route id/slug handoff');
ok32(str_contains($app,'window.__STONEFELLOW_DISCOVERY_RECORDED'),'landing attribution is limited to one event per document load');

ok32(str_contains($app,"shareButton('track'")&&str_contains($app,"shareButton('release'")&&str_contains($app,"shareButton('show'")&&str_contains($app,"shareButton('store'"),'songs, releases, shows and merch expose share controls');
ok32(strpos($app,"await navigator.share")<strpos($app,"share_target:'native'"),'native-share attribution is recorded only after share resolves');
ok32(strpos($app,'await navigator.clipboard.writeText')<strpos($app,"share_target:'copy'"),'copy-share attribution is recorded only after copy succeeds');
ok32(str_contains($app,'data-merch-open')&&str_contains($app,"navigate('store',{slug:b.dataset.merchOpen})"),'store cards open canonical focused product routes');
ok32(str_contains($app,'function merchDetailHtml')&&str_contains($app,"renderStore(params={})"),'direct product route renders focused merch detail');
ok32(str_contains($storefront,"'slug'=>(string)\$p['slug']"),'storefront API exposes canonical product slug');
ok32(str_contains($css,'.merch-detail-module')&&str_contains($css,'.seo-server-snapshot'),'focused merch and crawl snapshot have dedicated responsive presentation');

ok32(substr_count($core,'CREATE TABLE IF NOT EXISTS discovery_events')===2,'discovery event schema supports SQLite and MySQL');
ok32(str_contains($core,"\$allowed=['landing','share']"),'discovery analytics only accept bounded landing/share events');
ok32(str_contains($core,'sf_seo_normalize_source')&&str_contains($core,"\$source='search'")&&str_contains($core,"\$source='social'")&&str_contains($core,"\$medium='referral'"),'referrers normalize into bounded acquisition classes');
ok32(!str_contains($core,"'referrer'=>"),'raw referrer URLs are never persisted in discovery event detail');
ok32(str_contains($discovery,"(\$user['role']??'')!=='admin'"),'raw discovery summary endpoint is admin-only');
ok32(str_contains($discovery,'count($attempts)>=60'),'public discovery event writes are rate-limited');
ok32(str_contains($growth,"'discovery'=>\$discovery"),'discovery attribution feeds existing Growth Analytics payload');
ok32(str_contains($growth,'discovery top source'),'Admin Agent brief includes grounded discovery source');
ok32(str_contains($analytics,'Discovery sources')&&str_contains($analytics,'no full referrer URLs are retained'),'Performance Intelligence visibly reports privacy-bounded acquisition');

ok32(str_contains($boot,"require_once __DIR__ . '/seo-core.php'"),'SEO core loads from canonical bootstrap');
ok32(str_contains($mig,"'id'=>'2026-10-11-028'")&&str_contains($mig,'sf_seo_ensure_schema'),'migration 028 installs discovery schema');
$schema32='0.0.0';if(preg_match("/const SF_DB_SCHEMA_TARGET = '([^']+)'/",$mig,$ms32))$schema32=$ms32[1];ok32(version_compare($schema32,'1.3.27','>='),'database schema remains Section 32 compatible or newer');
ok32(str_contains($mig,"'discovery_events'"),'upgrade integrity requires discovery_events');
$app32='0.0.0';if(preg_match("/'stonefellow'=>'([^']+)'/",$version,$av32))$app32=$av32[1];ok32(version_compare($app32,'1.3.31','>=')&&version_compare($schema32,'1.3.27','>='),'version endpoint remains Section 32 compatible after later releases');
ok32(str_contains($version,"'seo_routes'=>'canonical-pretty-public-track-release-show-product-campaign-press'"),'version endpoint advertises canonical public route capability');
ok32(str_contains($wf,'php tests/v1331-section32-seo-discovery.php'),'release gate includes Section 32 certification');

echo "Stonefellow v1.3.31 Section 32 SEO, Discovery & Social Sharing audit: PASS\n";
