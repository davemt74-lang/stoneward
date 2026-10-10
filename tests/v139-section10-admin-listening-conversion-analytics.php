<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$ops=src('api/operations.php');$personal=src('api/personalization-core.php');$player=src('assets/js/app.js');$core=src('api/analytics-core.php');$boot=src('api/bootstrap.php');$adminApi=src('admin/api/analytics.php');$state=src('admin/api/state.php');$admin=src('admin/assets/admin.js');$renderer=src('admin/assets/analytics.js');$css=src('admin/assets/admin.css');$index=src('admin/index.php');$wf=src('.github/workflows/release-gate.yml');$mig=src('api/migrations.php');$version=src('version.php');

ok(str_contains($ops,"'skip'"),'listening recorder accepts explicit skip events');
ok(str_contains($player,"recordListen('skip')"),'player emits skip telemetry when playback is interrupted');
ok(str_contains($player,'pos<Math.max(0,dur-2)'),'near-natural-end transitions are not counted as skips');
ok(str_contains($personal,"'start','resume','pause','complete','skip'"),'personal listening history includes skip events');
ok(str_contains($personal,"['complete','skip']"),'skip terminates Continue Listening progress');

ok(str_contains($boot,"require_once __DIR__ . '/analytics-core.php';"),'analytics core loads through application bootstrap');
ok(str_contains($core,'function sf_engagement_analytics'),'canonical engagement analytics aggregate exists');
ok(str_contains($core,'function sf_analytics_paid_order'),'paid-order classifier exists');
ok(str_contains($core,'function sf_analytics_order_rows'),'windowed order loader exists');
ok(str_contains($core,"event_type"),'analytics reads listening event types');
ok(str_contains($core,"type==='start'")&&str_contains($core,"type==='complete'")&&str_contains($core,"type==='skip'"),'analytics separates starts, completes, and skips');
ok(str_contains($core,'repeatStarts'),'repeat-start analytics exist');
ok(str_contains($core,'repeatUsers'),'repeat-listener analytics exist');
ok(str_contains($core,'completion_rate')&&str_contains($core,'skip_rate')&&str_contains($core,'repeat_rate'),'quality rates are calculated');
ok(str_contains($core,'user_favorites'),'analytics reads favorites');
ok(str_contains($core,'user_playlists'),'analytics reads playlist creation');
ok(str_contains($core,'user_saved_builds'),'analytics reads saved custom builds');
ok(str_contains($core,"storage/orders/*.json"),'analytics reads canonical order records');
ok(str_contains($core,"type==='custom_media'"),'analytics identifies custom-media purchases');
ok(str_contains($core,"type==='track'"),'analytics identifies digital-track purchases');
ok(str_contains($core,'digital_purchases'),'per-track digital purchase conversion exists');
ok(str_contains($core,'custom_build_uses'),'per-track custom-build use exists');
ok(str_contains($core,'favoriteUsers')&&str_contains($core,'playlistUsers')&&str_contains($core,'buildUsers'),'listener engagement conversion sets exist');
ok(str_contains($core,'purchaseUsers')&&str_contains($core,'customMediaUsers'),'listener purchase conversion sets exist');
ok(str_contains($core,'firstListenAt'),'conversion is anchored to first listening activity in the selected window');
ok(str_contains($core,'revenue_cents'),'revenue is included in analytics');
ok(str_contains($core,'sources')&&str_contains($core,'percent'),'playback-source distribution is included');
ok(str_contains($core,'dailyRows'),'daily listening/conversion trend rows exist');
ok(str_contains($core,'userAgg'),'per-user engagement aggregation exists');
ok(str_contains($core,'trackAgg'),'per-track performance aggregation exists');

ok(str_contains($adminApi,'sf_engagement_analytics($days,null)'),'admin analytics API uses canonical engagement aggregate');
ok(str_contains($adminApi,'sf_engagement_analytics($days,$userId)'),'per-user inspector uses same analytics aggregate');
ok(str_contains($adminApi,'sf_personalization_favorites($userId)'),'user inspector includes favorites');
ok(str_contains($adminApi,'sf_playlist_list($userId)'),'user inspector includes playlists');
ok(str_contains($adminApi,'sf_account_saved_builds($userId)'),'user inspector includes saved builds');
ok(str_contains($adminApi,'sf_analytics_order_rows'),'user inspector includes orders in the selected window');

ok(str_contains($state,'sf_engagement_analytics(30,null)'),'main admin dashboard uses expanded analytics aggregate');
ok(str_contains($state,"'listen_skip_30d'"),'dashboard state includes skip rate');
ok(str_contains($state,"'repeat_starts_30d'"),'dashboard state includes repeat listening');
ok(str_contains($state,"'favorites_30d'")&&str_contains($state,"'playlists_30d'")&&str_contains($state,"'builds_30d'"),'dashboard state includes engagement creation metrics');
ok(str_contains($state,"'purchase_conversion_30d'")&&str_contains($state,"'custom_media_conversion_30d'"),'dashboard state includes purchase conversion');
ok(str_contains($state,"'revenue_30d_cents'"),'dashboard state includes 30-day revenue');
ok(str_contains($state,"('start','complete','skip')"),'recent listening feed includes skips');

ok(str_contains($index,'Listening + Conversion')||str_contains($index,'Performance Intelligence'),'admin navigation names the expanded analytics workspace');
ok(str_contains($index,'assets/analytics.js'),'admin shell loads the dedicated analytics renderer');
ok(str_contains($admin,'window.StonefellowAdminAnalytics'),'admin app delegates analytics rendering to Section 10 renderer');
ok(str_contains($admin,'Revenue · 30 days'),'main dashboard surfaces recent revenue');
ok(str_contains($admin,'Listener → purchase'),'main dashboard surfaces listener purchase conversion');
ok(str_contains($admin,'SKIP RATE')&&str_contains($admin,'REPEAT')&&str_contains($admin,'FAVORITES')&&str_contains($admin,'CUSTOM MEDIA'),'main dashboard surfaces listening quality and conversion KPIs');
ok(str_contains($admin,"event_type==='skip'"),'recent-listening dashboard labels skip events');

ok(str_contains($renderer,'Listening & Conversion Analytics')||str_contains($renderer,'Performance Intelligence'),'analytics workspace title is updated');
ok(str_contains($renderer,'Listener conversion'),'listener conversion funnel is rendered');
ok(str_contains($renderer,'Listening quality'),'starts/completions/skips trend is rendered');
ok(str_contains($renderer,'Playback sources'),'playback-source analytics are rendered');
ok(str_contains($renderer,'Track performance'),'per-track performance table is rendered');
ok(str_contains($renderer,'Per-user engagement'),'per-user engagement table is rendered');
ok(str_contains($renderer,'digital_purchases')&&str_contains($renderer,'custom_build_uses'),'track table shows digital/custom-media conversion');
ok(str_contains($renderer,'favorites_added')&&str_contains($renderer,'playlists_created')&&str_contains($renderer,'builds_created'),'user table shows engagement creation');
ok(str_contains($renderer,'paid_orders')&&str_contains($renderer,'revenue_cents'),'user table shows purchase/revenue outcomes');
ok(str_contains($renderer,'Agent Brain'),'per-user inspector retains Agent Brain context');
ok(str_contains($renderer,'Listening history'),'per-user inspector retains listening history');

ok(str_contains($css,'.conversion-grid')&&str_contains($css,'.conversion-card'),'conversion funnel is styled');
ok(str_contains($css,'.analytics-trend')&&str_contains($css,'.analytics-bars'),'listening quality trend is styled');
ok(str_contains($css,'.analytics-commerce-strip'),'commerce metrics are styled');
ok(str_contains($css,'.analytics-detail-grid'),'user inspector details are responsive');
ok(str_contains($css,'.table-scroll'),'wide analytics tables are scroll-safe');

ok(str_contains($wf,'node --check admin/assets/analytics.js'),'release gate syntax-checks analytics renderer');
ok(str_contains($wf,'v139-section10-admin-listening-conversion-analytics.php'),'release gate includes Section 10 regression');
ok(str_contains($version,"'admin_conversion_analytics'=>'skips-repeat-favorites-playlists-builds-purchases-users'"),'Section 10 analytics capability survives later schema migrations');
ok(str_contains($version,"'admin_conversion_analytics'=>'skips-repeat-favorites-playlists-builds-purchases-users'"),'version endpoint retains Section 10 analytics capability');
ok(str_contains($version,"'database_schema_target'=>'"),'version endpoint continues to report the current database schema target');
ok(str_contains($version,"'admin_conversion_analytics'=>'skips-repeat-favorites-playlists-builds-purchases-users'"),'version endpoint reports Section 10 analytics capability');

echo "Stonefellow v1.3 Section 10 Admin Listening & Conversion Analytics audit: PASS\n";
