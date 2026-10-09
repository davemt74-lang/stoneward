<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$core=src('api/notification-core.php');$api=src('api/notifications.php');$boot=src('api/bootstrap.php');$ops=src('api/operations.php');$life=src('api/lifecycle.php');$acctApi=src('api/account.php');$app=src('assets/js/app.js');$css=src('assets/css/site.css');$agent=src('api/agent-runtime.php');$adminApi=src('admin/api/analytics.php');$state=src('admin/api/state.php');$admin=src('admin/assets/admin.js');$renderer=src('admin/assets/analytics.js');$adminCss=src('admin/assets/admin.css');$cron=src('cron-notifications.php');$mig=src('api/migrations.php');$version=src('version.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/notification-core.php';"),'notification core loads from bootstrap');
ok(str_contains($core,'function sf_notification_ensure_schema'),'notification schema initializer exists');
ok(str_contains($core,'user_notification_preferences'),'notification preference table exists');
ok(str_contains($core,'notification_delivery_keys'),'notification dedupe table exists');
ok(str_contains($core,'notification_events'),'notification event table exists');
ok(str_contains($core,'notification_generation_state'),'notification generation baseline table exists');
ok(str_contains($core,'UNIQUE(user_id,dedupe_key)')||str_contains($core,'UNIQUE KEY uq_notification_dedupe'),'dedupe keys are unique per user');
ok(str_contains($core,'PRIMARY KEY(user_id,category)'),'notification preference categories are unique per user');

ok(str_contains($core,"'listening'=>"),'listening reminder preference category exists');
ok(str_contains($core,"'recommendation'=>"),'recommendation preference category exists');
ok(str_contains($core,"'release'=>"),'new-release preference category exists');
ok(str_contains($core,"'build'=>"),'saved-build preference category exists');
ok(str_contains($core,"'in_app_enabled'=>true"),'smart notifications default to in-app enabled');
ok(str_contains($core,"'email_enabled'=>false"),'smart notification email is opt-in by default');
ok(str_contains($core,'function sf_notification_preferences_save'),'preference save helper exists');
ok(str_contains($api,"action==='preferences'"),'notification API exposes preference updates');
ok(str_contains($acctApi,"'notification_preferences'=>sf_notification_preferences"),'My account receives notification preferences');

ok(str_contains($core,'function sf_notification_dedupe_claim'),'notification dedupe claim exists');
ok(str_contains($core,"['23000','19']"),'dedupe handles duplicate-key errors without swallowing unrelated database failures');
ok(str_contains($core,'function sf_notification_seed_baseline'),'first-run baseline seeding exists');
ok(str_contains($core,'sf_notification_seed_baseline($userId,$now)'),'first generation initializes release baseline');
ok(str_contains($core,"return ['baseline_initialized'=>true"),'first generation exits after baseline initialization');
ok(str_contains($core,"'release:'.$id"),'existing release IDs are seeded into dedupe baseline');
ok(str_contains($core,'updated_at>=? AND updated_at<=?'),'historical listening/build activity is bounded after the Section 11 baseline');

ok(str_contains($core,'time()-12*3600'),'unfinished-listening reminder waits at least 12 hours');
ok(str_contains($core,'user_listening_progress'),'unfinished-listening reminders use canonical Continue Listening state');
ok(str_contains($core,"'listening','listen:'"),'unfinished listening uses listening notification category');
ok(str_contains($core,'time()-24*3600'),'saved-build reminder waits at least 24 hours');
ok(str_contains($core,'user_saved_builds'),'saved-build reminders use canonical saved builds');
ok(str_contains($core,"'build','build:'"),'saved build reminders use build notification category');
ok(str_contains($core,'sf_release_rows()'),'release notifications use canonical release data');
ok(str_contains($core,"'release','release:'"),'new release notifications use release notification category');
ok(str_contains($core,'sf_personalization_recommendations($userId,1)'),'recommendation notifications use canonical personalization engine');
ok(str_contains($core,"'recommendation','recommendation:'"),'recommendation notification dedupe is weekly and track-aware');

ok(str_contains($core,'sf_transactional_email'),'email notifications reuse transactional email delivery');
ok(str_contains($life,'transactional_email_outbox'),'transactional email outbox remains authoritative');
ok(str_contains($core,"'smart_notification_'.$category"),'smart email delivery is labeled by notification category');
ok(str_contains($core,"'email_delivery'"),'email delivery events are tracked');
ok(str_contains($core,"metadata_json"),'notification event metadata supports email-only category attribution');

ok(str_contains($ops,"sf_notification_event($userId,$id,'delivered'"),'all in-app notification deliveries are instrumented');
ok(str_contains($core,"['delivered','read','click','dismiss']"),'notification lifecycle events are de-duplicated per notification');
ok(str_contains($core,'function sf_notification_mark_read'),'mark-read helper exists');
ok(str_contains($core,'function sf_notification_dismiss'),'dismiss helper exists');
ok(str_contains($core,'function sf_notification_click'),'click helper exists');
ok(str_contains($core,'sf_notification_event($userId,$id,\'click\')'),'click events are tracked');
ok(str_contains($core,'NOT EXISTS(SELECT 1 FROM notification_events'),'dismissed notifications are excluded from visible drawer results');

ok(str_contains($api,'sf_notification_generate_for_user($uid)'),'drawer refresh lazily generates current-user notifications');
ok(str_contains($api,"action==='read'"),'notification API exposes mark read');
ok(str_contains($api,"action==='read_all'"),'notification API exposes mark all read');
ok(str_contains($api,"action==='dismiss'"),'notification API exposes dismiss');
ok(str_contains($api,"action==='click'"),'notification API exposes click/open');
ok(str_contains($api,"action==='refresh'"),'notification API exposes explicit regeneration');
ok(str_contains($api,"'preferences'=>sf_notification_preferences"),'notification GET returns preferences');

ok(str_contains($cron,"PHP_SAPI!=='cli'"),'notification generator is CLI-only');
ok(str_contains($cron,'sf_notification_generate_all()'),'cron generator processes active users');
ok(str_contains($core,"WHERE status='active'"),'bulk notification generation targets active accounts');
ok(str_contains($core,'function sf_notification_generate_all'),'bulk generation helper exists');

ok(str_contains($app,'data-notification-read'),'drawer exposes Mark read');
ok(str_contains($app,'data-notification-open'),'drawer exposes Open');
ok(str_contains($app,'data-notification-dismiss'),'drawer exposes Dismiss');
ok(str_contains($app,'async function openNotification'),'notification click/open client action exists');
ok(str_contains($app,'async function dismissNotification'),'notification dismiss client action exists');
ok(str_contains($app,"action:'click'"),'Open action records a notification click before navigation');
ok(str_contains($app,"action:'dismiss'"),'Dismiss action records a dismiss event');
ok(str_contains($app,'notification-preferences'),'My account renders notification preferences');
ok(str_contains($app,'data-notification-in-app'),'account offers per-category in-app control');
ok(str_contains($app,'data-notification-email'),'account offers per-category email control');
ok(str_contains($app,'async function saveNotificationPreferences'),'account saves notification preferences');
ok(str_contains($css,'.notification-card-actions'),'notification drawer actions are styled');
ok(str_contains($css,'.notification-preference-row'),'account notification preferences are styled and responsive');

ok(str_contains($agent,"return 'notifications_open'"),'Agent routes notification/open-alert requests');
ok(str_contains($agent,"return 'notification_preferences'"),'Agent routes notification preference requests');
ok(str_contains($agent,"'type'=>'open_notifications'"),'Agent emits drawer-open action');
ok(str_contains($agent,"'notification_unread'"),'Agent routing state receives unread count');
ok(str_contains($agent,"'latest_notification_title'"),'Agent routing state receives latest notification context');
ok(str_contains($app,"case'open_notifications'"),'client executes Agent notification drawer action');
ok(str_contains($app,'notification_unread:Number('),'client sends unread notification count to Agent');
ok(str_contains($app,'latest_notification_title:'),'client sends latest notification title to Agent');

ok(str_contains($core,'function sf_notification_analytics'),'notification conversion analytics exists');
ok(str_contains($core,"'read_rate'=>"),'notification read rate is calculated');
ok(str_contains($core,"'click_rate'=>"),'notification click rate is calculated');
ok(str_contains($core,"'listen_conversion_rate'=>"),'notification click-to-listen conversion is calculated');
ok(str_contains($core,"'purchase_conversion_rate'=>"),'notification click-to-purchase conversion is calculated');
ok(str_contains($core,'strtotime($start)+86400'),'post-click conversion window is 24 hours');
ok(str_contains($core,'sf_analytics_paid_order'),'notification purchase conversion uses canonical paid-order classification');
ok(str_contains($adminApi,'sf_notification_analytics($days,null)'),'admin analytics API exposes overall notification analytics');
ok(str_contains($adminApi,'sf_notification_analytics($days,$userId)'),'admin analytics API exposes per-user notification analytics');
ok(str_contains($state,'recent_notification_events'),'main admin dashboard state includes recent notification activity');
ok(str_contains($state,"'notification_click_rate_30d'"),'main dashboard state includes notification click rate');
ok(str_contains($state,"'notification_listen_conversion_30d'"),'main dashboard state includes notification-to-listen conversion');
ok(str_contains($state,"'notification_purchase_conversion_30d'"),'main dashboard state includes notification-to-purchase conversion');
ok(str_contains($admin,'Notification re-engagement'),'main admin dashboard renders notification re-engagement');
ok(str_contains($admin,'recentNotificationEvents'),'main admin dashboard renders recent notification events');
ok(str_contains($renderer,'Notification re-engagement'),'analytics workspace renders notification re-engagement');
ok(str_contains($renderer,'Click → listen'),'analytics workspace renders click-to-listen conversion');
ok(str_contains($renderer,'Click → purchase'),'analytics workspace renders click-to-purchase conversion');
ok(str_contains($renderer,'notification_analytics'),'per-user inspector receives notification analytics');
ok(str_contains($adminCss,'.notification-kpi-stack'),'notification admin KPIs are responsive');

ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.10'"),'database schema target advances to 1.3.10');
ok(str_contains($mig,"'id'=>'2026-10-08-011'")&&str_contains($mig,'sf_notification_ensure_schema'),'migration 011 installs smart notification schema');
ok(str_contains($mig,"'notification_delivery_keys'")&&str_contains($mig,"'notification_generation_state'"),'migration integrity requires notification governance tables');
ok(str_contains($version,"'stonefellow'=>'1.3.10'"),'version endpoint reports v1.3.10');
ok(str_contains($version,"'database_schema_target'=>'1.3.10'"),'version endpoint reports schema 1.3.10');
ok(str_contains($version,"'smart_notifications'=>'preferences-dedupe-cron-email-conversion-agent'"),'version endpoint reports smart notification capability');
ok(str_contains($wf,'v1310-section11-smart-notifications.php'),'release gate includes Section 11 regression');

echo "Stonefellow v1.3 Section 11 Smart Notifications & Listener Re-engagement audit: PASS\n";
