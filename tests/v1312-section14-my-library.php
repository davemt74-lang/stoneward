<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$core=src('api/library-core.php');$api=src('api/library.php');$boot=src('api/bootstrap.php');$acct=src('api/account.php');$app=src('assets/js/app.js');$ui=src('assets/js/library.js');$css=src('assets/css/site.css');$html=src('stonefellow-v120.php');$agent=src('api/agent-runtime.php');$adminApi=src('admin/api/analytics.php');$state=src('admin/api/state.php');$admin=src('admin/assets/admin.js');$renderer=src('admin/assets/analytics.js');$adminCss=src('admin/assets/admin.css');$mig=src('api/migrations.php');$version=src('version.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/library-core.php';"),'My Library core loads from bootstrap');
ok(str_contains($core,'function sf_library_ensure_schema'),'library schema initializer exists');
ok(str_contains($core,'CREATE TABLE IF NOT EXISTS user_collections'),'user collections table exists');
ok(str_contains($core,'CREATE TABLE IF NOT EXISTS user_collection_items'),'mixed collection items table exists');
ok(str_contains($core,'FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE'),'collections are user-owned and cascade with account deletion');
ok(str_contains($core,'FOREIGN KEY(collection_id) REFERENCES user_collections(id) ON DELETE CASCADE'),'collection items cascade with collection deletion');
ok(str_contains($core,'UNIQUE(collection_id,item_type,item_key)')||str_contains($core,'UNIQUE KEY idx_collection_item_unique'),'collection items are duplicate-safe');
ok(str_contains($core,'idx_user_collections_user_updated'),'collections have user/update index');
ok(str_contains($core,'idx_collection_items_order'),'collection item order is indexed');

ok(str_contains($core,'function sf_library_collection_owned'),'collection ownership guard exists');
ok(str_contains($core,"(int)\$r['user_id']!==\$userId"),'collection mutations enforce exact owner');
ok(str_contains($core,'function sf_library_resolve_item'),'mixed collection references use canonical resolver');
ok(str_contains($core,"if(\$type==='track')"),'collections can contain catalog tracks');
ok(str_contains($core,"if(\$type==='release')"),'collections can contain releases');
ok(str_contains($core,"if(\$type==='playlist')"),'collections can contain owned playlists');
ok(str_contains($core,"if(\$type==='purchase')"),'collections can contain owned purchases');
ok(str_contains($core,"if(\$type==='build')"),'collections can contain owned saved builds');
ok(str_contains($core,'sf_library_playlist_map($userId)'),'playlist collection references are ownership-scoped');
ok(str_contains($core,'sf_library_purchase_map($userId)'),'purchase collection references are ownership-scoped');
ok(str_contains($core,'sf_library_build_map($userId)'),'build collection references are ownership-scoped');
ok(str_contains($core,"public_visible"),'collection release resolver respects public release visibility');

ok(str_contains($core,'function sf_library_collection_create'),'collection create exists');
ok(str_contains($core,'function sf_library_collection_update'),'collection edit exists');
ok(str_contains($core,'function sf_library_collection_delete'),'collection delete exists');
ok(str_contains($core,'function sf_library_collection_add'),'collection add-item exists');
ok(str_contains($core,"SELECT id FROM user_collection_items WHERE collection_id=? AND item_type=? AND item_key=? LIMIT 1"),'duplicate collection adds are explicit idempotent no-ops');
ok(str_contains($core,'if($inserted){'),'duplicate races do not create false collection activity events');
ok(str_contains($core,'function sf_library_collection_remove'),'collection remove-item exists');
ok(str_contains($core,"'collection_item_removed'"),'actual collection removals are recorded in user activity');
ok(str_contains($core,'function sf_library_collection_move'),'collection reorder exists');
ok(str_contains($core,'function sf_library_collection_resequence'),'collection positions are normalized after removal');
ok(str_contains($core,"'collection_created'"),'collection creation is included in user activity history');
ok(str_contains($core,"'collection_item_added'"),'collection save activity is included in user history');

ok(str_contains($core,'function sf_library_snapshot'),'canonical My Library snapshot exists');
ok(str_contains($core,'sf_personalization_favorites($userId)'),'My Library reuses canonical favorites');
ok(str_contains($core,'sf_playlist_list($userId)'),'My Library reuses canonical playlists');
ok(str_contains($core,'sf_account_saved_builds($userId)'),'My Library reuses canonical saved builds');
ok(str_contains($core,'sf_account_library($userId)'),'My Library reuses canonical purchased-library ownership');
ok(str_contains($core,'sf_analytics_paid_order($order)'),'My Library exposes only paid/completed order items as owned purchases');
ok(str_contains($core,'sf_read_order($orderId)'),'owned-purchase state is verified against the canonical order record');
ok(str_contains($core,'sf_personalization_continue_listening($userId,24)'),'My Library reuses Continue Listening');
ok(str_contains($core,'sf_personalization_history($userId,160)'),'My Library reuses personal listening history');
ok(str_contains($core,"'all'=>\$all"),'My Library exposes unified All view');
foreach(["'tracks'=>\$tracks","'releases'=>\$releases","'playlists'=>\$playlistRows","'purchases'=>\$purchaseRows","'builds'=>\$buildRows","'collections'=>\$collections","'continue_listening'=>\$continue","'history'=>\$history"] as $needle)ok(str_contains($core,$needle),'snapshot section exists: '.$needle);
ok(str_contains($core,'usort($all'),'All view has deterministic recent-item ordering');

ok(str_contains($api,'sf_require_user(false,$write)'),'My Library requires authentication and CSRF for writes');
ok(str_contains($api,"action==='create_collection'"),'library API exposes collection creation');
ok(str_contains($api,"action==='update_collection'"),'library API exposes collection editing');
ok(str_contains($api,"action==='delete_collection'"),'library API exposes collection deletion');
ok(str_contains($api,"action==='add_to_collection'"),'library API exposes add-to-collection');
ok(str_contains($api,"action==='remove_from_collection'"),'library API exposes remove-from-collection');
ok(str_contains($api,"action==='move_collection_item'"),'library API exposes collection reorder');
ok(str_contains($api,"'library'=>sf_library_snapshot(\$uid)"),'library API always returns canonical snapshot after writes');

ok(str_contains($html,'id="menuLibrary"')&&str_contains($html,'?view=library'),'signed-in menu has dedicated My Library entry');
ok(str_contains($html,'assets/js/library.js'),'public shell loads dedicated My Library UI bundle');
ok(str_contains($app,'async function renderMyLibrary'),'app delegates dedicated My Library rendering');
ok(str_contains($app,"view==='library'"),'My Library has a first-class route');
ok(str_contains($app,"'library'].includes(view)"),'My Library preloads personalization for favorite state');
ok(str_contains($app,"u.searchParams.get('tab')"),'My Library tab deep links restore from URL');
ok(str_contains($app,"u.searchParams.get('collection')"),'Collection deep links restore from URL');
ok(str_contains($acct,"'my_library'=>sf_library_snapshot(\$uid)"),'My account receives canonical library summary');
ok(str_contains($app,'account-library-portal'),'Account now points saved-content management to My Library');
ok(str_contains($app,'Your saved tracks and releases, playlists, owned music'),'Account explains My Library consolidation');

foreach(["all:'All'","tracks:'Tracks'","releases:'Releases'","playlists:'Playlists'","purchases:'Purchases'","builds:'Builds'","history:'History'","collections:'Collections'"] as $tab)ok(str_contains($ui,$tab),'My Library tab exists: '.$tab);
ok(str_contains($ui,'Everything you’ve saved, bought, built, played, or organized'),'My Library has unified customer-facing purpose');
ok(str_contains($ui,'library-summary'),'My Library renders cross-source summary');
ok(str_contains($ui,'CONTINUE LISTENING'),'My Library surfaces Continue Listening');
ok(str_contains($ui,'librarySearch'),'My Library has in-library search');
ok(str_contains($ui,'librarySort'),'My Library has sorting');
ok(str_contains($ui,'Recently saved'),'My Library defaults to recent saved content');
ok(str_contains($ui,'data-library-play-track'),'saved tracks/purchases can play directly');
ok(str_contains($ui,'data-library-open-release'),'saved releases open directly');
ok(str_contains($ui,'data-library-play-playlist'),'playlists can play directly');
ok(str_contains($ui,'data-library-open-order'),'owned items link to source order');
ok(str_contains($ui,'data-library-open-build'),'saved builds reopen the builder');
ok(str_contains($ui,'data-library-resume'),'Continue Listening preserves resume action');
ok(str_contains($ui,'favoriteButton(type,key'),'track/release favorite state stays canonical');
ok(str_contains($ui,'bindQueueTrackButtons'),'library track actions keep Up Next integration');

ok(str_contains($ui,'data-library-create-collection'),'collection creation control exists');
ok(str_contains($ui,'data-library-open-collection'),'collection cards open details');
ok(str_contains($ui,'data-library-collection-add'),'saved items can be added to a collection');
ok(str_contains($ui,'data-library-remove-item'),'collection items can be removed');
ok(str_contains($ui,'data-library-move-item'),'collection items can be reordered');
ok(str_contains($ui,'Delete this collection? The saved items themselves will not be deleted.'),'collection deletion clearly preserves source items');
ok(str_contains($ui,'data-library-edit-collection'),'collections can be renamed/described');
ok(str_contains($ui,'clearCatalogSearchHistory')===false,'My Library does not reuse unrelated search-history mutation logic');

ok(str_contains($css,'.my-library-module'),'My Library root layout is styled');
ok(str_contains($css,'.my-library-tabs'),'library tabs are styled');
ok(str_contains($css,'.library-summary'),'library summary is styled');
ok(str_contains($css,'.library-continue-grid'),'Continue Listening rail is styled');
ok(str_contains($css,'.my-library-card'),'mixed saved-item cards are styled');
ok(str_contains($css,'.library-collect-menu'),'collection picker is styled');
ok(str_contains($css,'.library-collection-grid'),'collection overview is styled');
ok(str_contains($css,'.library-collection-detail'),'collection detail is styled');
ok(str_contains($css,'@media(max-width:680px)'),'My Library has mobile responsive rules');

ok(str_contains($agent,"return 'library_open'"),'Agent routes My Library/saved-content requests');
ok(str_contains($agent,"case 'library_open'"),'Agent policy handles My Library');
ok(str_contains($agent,"'type'=>'open_library'"),'Agent emits first-class library navigation action');
ok(str_contains($agent,"'library_open'=>'Asks for My Library"),'JEV criteria include My Library');
ok(str_contains($app,"case'open_library'"),'client executes Agent library navigation');
ok(str_contains($agent,"str_contains(\$mq,'collection')"),'Agent chooses Collections tab when requested');
ok(str_contains($agent,"str_contains(\$mq,'purchase')"),'Agent chooses Purchases tab when requested');

ok(str_contains($core,'function sf_library_admin_analytics'),'read-only library Admin analytics exists');
ok(str_contains($core,"'users_with_library'=>"),'Admin library analytics count participating users');
ok(str_contains($core,"'top_tracks'=>"),'Admin library analytics expose most-saved tracks');
ok(str_contains($core,"'top_releases'=>"),'Admin library analytics expose most-saved releases');
ok(str_contains($adminApi,'sf_library_admin_analytics(null)'),'Admin analytics API exposes overall library analytics');
ok(str_contains($adminApi,'sf_library_admin_analytics($userId)'),'Admin user inspector gets library metrics');
ok(str_contains($adminApi,'sf_library_snapshot($userId)'),'Admin user inspector gets read-only library snapshot');
ok(str_contains($state,'$libraryAnalytics=sf_library_admin_analytics(null)'),'Admin dashboard loads library analytics');
ok(str_contains($state,"'library_users'"),'Admin dashboard state includes library users');
ok(str_contains($state,"'library_collections'"),'Admin dashboard state includes collections');
ok(str_contains($renderer,'Customer libraries'),'Admin analytics renders customer-library panel');
ok(str_contains($renderer,'Most-saved tracks'),'Admin analytics renders top saved tracks');
ok(str_contains($renderer,'Most-saved releases'),'Admin analytics renders top saved releases');
ok(str_contains($renderer,'My Library:'),'per-user inspector renders read-only library summary');
ok(str_contains($admin,'Customer libraries'),'main Admin dashboard renders library KPIs');
ok(str_contains($adminCss,'.library-kpi-stack'),'Admin library KPIs are responsive');

ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.12'"),'database schema target advances to 1.3.12');
ok(str_contains($mig,"'id'=>'2026-10-08-013'")&&str_contains($mig,'sf_library_ensure_schema'),'migration 013 installs collection schema');
ok(str_contains($mig,"'user_collections','user_collection_items'"),'migration integrity requires collection tables');
ok(str_contains($version,"'stonefellow'=>'1.3.12'"),'version endpoint reports v1.3.12');
ok(str_contains($version,"'database_schema_target'=>'1.3.12'"),'version endpoint reports schema 1.3.12');
ok(str_contains($version,"'my_library'=>'unified-saved-music-purchases-builds-history-collections'"),'version endpoint reports My Library capability');
ok(str_contains($wf,'node --check assets/js/library.js'),'release gate syntax-checks My Library JavaScript');
ok(str_contains($wf,'v1312-section14-my-library.php'),'release gate includes Section 14 regression');

echo "Stonefellow v1.3 Section 14 My Library, Collections & Saved Music audit: PASS\n";
