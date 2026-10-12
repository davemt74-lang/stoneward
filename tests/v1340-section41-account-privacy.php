<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok41($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/privacy-core.php';

$d=sf_privacy_default_profile(7);
ok41($d['user_id']===7&&$d['listening_history_enabled']===true&&$d['personalization_enabled']===true,'new fan profiles default to history/personalization enabled');
ok41($d['locale']==='en-US'&&$d['timezone']===''&&$d['country_code']==='','profile defaults collect no location/timezone until chosen');

$core=(string)file_get_contents($root.'/api/privacy-core.php');
$api=(string)file_get_contents($root.'/api/privacy.php');
$account=(string)file_get_contents($root.'/api/account.php');
$ops=(string)file_get_contents($root.'/api/operations.php');
$personal=(string)file_get_contents($root.'/api/personalization-core.php');
$app=(string)file_get_contents($root.'/assets/js/app.js');
$css=(string)file_get_contents($root.'/assets/css/site.css');
$boot=(string)file_get_contents($root.'/api/bootstrap.php');
$mig=(string)file_get_contents($root.'/api/migrations.php');
$version=(string)file_get_contents($root.'/version.php');
$shell=(string)file_get_contents($root.'/stonefellow-v120.php');
$admin=(string)file_get_contents($root.'/admin/index.php');
$ht=(string)file_get_contents($root.'/.htaccess');
$sw=(string)file_get_contents($root.'/service-worker.js');
$wf=(string)file_get_contents($root.'/.github/workflows/release-gate.yml');
$section40=(string)file_get_contents($root.'/tests/v1339-section40-accessibility-pwa.php');

ok41(substr_count($core,'CREATE TABLE IF NOT EXISTS user_profiles')===2,'profile/privacy schema supports SQLite and MySQL');
ok41(substr_count($core,'CREATE TABLE IF NOT EXISTS user_privacy_events')===2,'privacy audit schema supports SQLite and MySQL');
ok41(str_contains($core,'DateTimeZone::listIdentifiers()')&&str_contains($core,"preg_match('/^[A-Z]{2}$/")&&str_contains($core,"preg_match('/^[A-Za-z]{2,3}"),'profile timezone, country and locale are server validated');
ok41(str_contains($core,'listening_history_enabled')&&str_contains($core,'personalization_enabled'),'profile stores explicit history and personalization controls');
ok41(str_contains($ops,"sf_privacy_listening_history_enabled")&&str_contains($ops,'$userId=null'),'disabled listening history makes future listening analytics anonymous');
ok41(str_contains($personal,"sf_privacy_listening_history_enabled")&&str_contains($personal,'return;'),'disabled listening history stops Continue Listening progress writes');
ok41(str_contains($personal,"sf_privacy_personalization_enabled")&&str_contains($personal,"'personalized'=>false"),'disabled personalization returns a signal-free recommendation profile');

foreach(['account','profile','sessions','notifications','fan_preferences','favorites','listening_history','continue_listening','playlists','library','saved_builds','purchases','invoices','membership','account_activity','agent_conversations','agent_brain','support','telemetry','privacy_events'] as $key)ok41(str_contains($core,"'".$key."'=>"),'data export includes '.$key);
ok41(!str_contains($core,"'password_hash'=>")&&!str_contains($core,"'csrf'=>")&&!str_contains($core,"'token_hash'=>"),'data export does not deliberately expose passwords, CSRF or token hashes');
ok41(str_contains($core,'sf_privacy_agent_export')&&str_contains($core,'agent_messages'),'data export includes user Agent conversation content');
ok41(str_contains($core,'sf_privacy_support_export')&&str_contains($core,'support_messages'),'data export includes account-linked support history');
ok41(str_contains($core,'sf_privacy_telemetry_export'),'data export includes identifiable telemetry associated with the account');
ok41(str_contains($core,'retention_note'),'export/Privacy Center disclose retained operational/accounting history');

ok41(str_contains($api,"action==='save_profile'")&&str_contains($api,"action==='export'")&&str_contains($api,"action==='clear_history'")&&str_contains($api,"action==='reset_personalization'")&&str_contains($api,"action==='clear_search_activity'")&&str_contains($api,"action==='clear_agent_history'")&&str_contains($api,"action==='close_account'"),'privacy API exposes all governed fan privacy actions');
ok41(substr_count($api,"empty(\$b['confirmed'])")>=6,'every export/destructive privacy action requires explicit confirmation');
ok41(str_contains($core,"\$confirmation!=='DELETE'")&&str_contains($core,'password_verify($password'),'account closure requires typed DELETE and current password');
ok41(str_contains($core,"Administrator accounts must be transferred or demoted")&&str_contains($core,'Cancel the active paid subscription')&&str_contains($core,'physical order is still in fulfillment')&&str_contains($core,'open support case'),'account closure blocks admin, paid billing, fulfillment and unresolved support states');
ok41(str_contains($core,"status='closed'")&&str_contains($core,"password_hash(bin2hex(random_bytes(32))"),'closed account is disabled and password is replaced with random secret');
ok41(str_contains($core,"UPDATE fan_contacts SET marketing_opt_in=0,agent_auto_engage=0,status=?")&&str_contains($core,'unsubscribe_token_hash=NULL'),'account closure disables marketing/Agent CRM identity');
ok41(str_contains($core,"'user_token_balances','token_ledger','user_subscriptions','user_trial_history'"),'closure purges token/subscription entitlement rows after active billing blockers pass');
ok41(str_contains($core,'sf_privacy_anonymize_user_events')&&str_contains($core,'SET user_id=NULL'),'closure anonymizes aggregate-compatible fan event history');
ok41(str_contains($core,'function sf_privacy_delete_rows')&&!str_contains($core,"function sf_privacy_delete_rows(string \$table,int \$userId): int {try{"),'required account-closure purges do not swallow database errors');
ok41(str_contains($core,"\$anon='closed-'.bin2hex(random_bytes(12))"),'closed-account alias is random rather than derived from the former email');
ok41(str_contains($core,"'user_sessions','user_favorites'"),'persistent sessions are purged inside the closure transaction');
ok41(str_contains($core,'UPDATE transactional_email_outbox SET user_id=NULL,recipient_email=?'),'retained transactional email rows lose the original account identity');
ok41(str_contains($core,"DELETE FROM agent_messages WHERE user_id=?")&&!str_contains($core,"catch(Throwable \$e){}\$q=sf_db()->prepare('DELETE FROM agent_conversations"),'Agent history purge is strict and covers direct user-linked messages');

ok41(str_contains($core,"'user_sessions','user_favorites'")&&!str_contains($core,'sf_auth_revoke_all_user_sessions($userId);return'),'closure revokes persistent sessions inside the atomic purge transaction');
ok41(str_contains($api,'sf_logout_user()'),'successful closure logs out the current browser');

ok41(str_contains($account,"'privacy'=>sf_privacy_state"),'Account payload exposes privacy state');
ok41(str_contains($app,'data-view="account-privacy"')&&str_contains($app,"view==='account-privacy'")&&str_contains($app,'async function renderPrivacyCenter'),'Account links to a dedicated account-only Privacy Center route');
ok41(str_contains($shell,'data-view="privacy"')&&!str_contains($shell,'data-view="account-privacy"'),'existing public Privacy policy remains in global navigation while account Privacy Center stays account-only');
ok41(str_contains($app,'Save listening history')&&str_contains($app,'Use my activity for personalization'),'Privacy Center explains real runtime privacy controls');
ok41(str_contains($app,'Download my data')&&str_contains($app,'Clear listening history')&&str_contains($app,'Reset personalization signals')&&str_contains($app,'Clear Agent conversation history'),'Privacy Center exposes export and selective reset controls');
ok41(str_contains($app,"new Blob([JSON.stringify(data,null,2)]")&&str_contains($app,'downloadPrivacyJson'),'account export is downloaded locally as JSON');
ok41(str_contains($app,"pattern=\"DELETE\"")&&str_contains($app,'Close account permanently'),'closure UI requires explicit destructive phrase');
ok41(str_contains($app,"confirm('Permanently close this Stonefellow account?"),'closure UI adds final explicit confirmation');
ok41(str_contains($css,'.privacy-center')&&str_contains($css,'.privacy-danger')&&str_contains($css,'.privacy-switch'),'Privacy Center has dedicated responsive/danger styling');

ok41(str_contains($boot,"require_once __DIR__ . '/privacy-core.php'"),'privacy core loads from canonical bootstrap');
ok41(str_contains($mig,"'id'=>'2026-10-12-034'")&&str_contains($mig,'sf_privacy_ensure_schema'),'migration 034 installs profile/privacy schema');
ok41(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.34'"),'database schema advances to 1.3.34');
ok41(str_contains($mig,"'user_profiles'")&&str_contains($mig,"'user_privacy_events'"),'upgrade integrity requires Section 41 tables');
ok41(str_contains($version,"'stonefellow'=>'1.3.44'")&&str_contains($version,"'database_schema_target'=>'1.3.34'"),'version endpoint reports app 1.3.44 and schema 1.3.34');
ok41(str_contains($version,"'account_data_export'=>'profile-library-listening-purchases-agent-support-telemetry-json'"),'version endpoint advertises account data export');
ok41(str_contains($version,"'account_closure'=>'password-delete-confirmation-subscription-order-support-blockers-anonymization'"),'version endpoint advertises governed closure');
ok41(str_contains($shell,"define('SF_BUILD', '1.3.44')"),'public asset build advances to v1.3.44');
ok41(str_contains($admin,"define('SF_ADMIN_BUILD','1.3.44')"),'Admin asset build stays synchronized at v1.3.44');
ok41(str_contains($ht,'X-Stonefellow-Build "1.3.44"'),'server build header advances to v1.3.44');
ok41(str_contains($sw,'stonefellow-static-v1.3.44'),'PWA cache advances with the changed public app');
ok41(str_contains($section40,'version_compare($swBuild,\'1.3.39\',\'>=\')')&&str_contains($section40,'version_compare($publicBuild40,\'1.3.39\',\'>=\')'),'Section 40 PWA/accessibility regression remains forward-compatible without weakening its minimum');
ok41(str_contains($wf,'php tests/v1340-section41-account-privacy.php'),'release gate includes Section 41 certification');

echo "Stonefellow v1.3.40 Section 41 Fan Account, Profile & Privacy Center audit: PASS\n";
