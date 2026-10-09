<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$site=src('api/site-settings.php');$settings=src('admin/api/settings.php');$admin=src('admin/assets/admin.js');$shell=src('stonefellow-v120.php');$app=src('assets/js/app.js');$community=src('api/community.php');$crm=src('api/crm-core.php');$agent=src('api/agent-runtime.php');$version=src('version.php');$mig=src('api/migrations.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($site,"'fan_community_enabled'=>sf_meta_get('site.fan_community_enabled','0')==='1'"),'Fan Community launch control defaults OFF');
ok(str_contains($site,"['fan_community_enabled','announcement_enabled','maintenance_enabled']"),'site settings persistence includes Fan Community toggle');
ok(str_contains($settings,"'fan_community_enabled'=>!empty($body['fan_community_enabled'])"),'Admin settings API accepts Fan Community toggle');
ok(str_contains($settings,"'fan_community'=>$site['fan_community_enabled']"),'Admin audit records Fan Community launch state');
ok(str_contains($admin,'name="fan_community_enabled"')&&str_contains($admin,"f.get('fan_community_enabled')==='on'"),'Admin Settings exposes and saves the on/off control');
ok(str_contains($admin,'CRM, newsletter and fan intelligence remain active when off'),'Admin UI clearly separates community launch from CRM');

ok(str_contains($shell,"if(!empty($siteSettings['fan_community_enabled']))")&&str_contains($shell,'Fan Community'),'public navigation and quick action are server-gated');
ok(str_contains($shell,"'fanCommunityEnabled'=>(bool)($siteSettings['fan_community_enabled']??false)"),'public runtime receives the launch state');
ok(str_contains($community,"error'=>'community_disabled'")&&str_contains($community,"sf_site_settings()['fan_community_enabled']"),'community API blocks feed and posting while disabled');
ok(str_contains($app,'fanCommunityEnabled=!!site.fanCommunityEnabled'),'public app reads launch state');
ok(str_contains($app,'function renderNewsletter()'),'newsletter remains a standalone public CRM surface');
ok(str_contains($app,"view==='newsletter'")&&str_contains($app,"navigate('newsletter')"),'newsletter routing no longer depends on community');
ok(str_contains($app,"if(!fanCommunityEnabled){renderNewsletter();return}"),'direct community route exposes no feed while disabled');
ok(str_contains($app,"fanCommunityEnabled?navigate('community'):navigate('newsletter')"),'chat quick-action handler cannot bypass the launch gate');
ok(str_contains($app,"fanCommunityEnabled?'<button")&&str_contains($app,'Stonefellow updates'),'personalized home keeps newsletter but hides community CTA while disabled');

ok(str_contains($crm,"$communityEnabled=!empty(sf_site_settings()['fan_community_enabled'])"),'CRM engagement policy knows the community launch state');
ok(str_contains($crm,"$communityEnabled&&$type==='community_post'"),'historical community posts do not trigger proactive community outreach while disabled');
ok(str_contains($crm,'function sf_crm_record_user_activity(')&&str_contains($crm,'function sf_crm_newsletter_signup('),'core CRM and newsletter integrations remain active');
ok(str_contains($agent,"sf_site_settings()['fan_community_enabled']")&&str_contains($agent,"view'=>'newsletter"),'Agent policy sends disabled-community requests to the still-active newsletter/CRM surface');

preg_match("/'stonefellow'=>'([0-9]+)\.([0-9]+)\.([0-9]+)'/",$version,$vm);
ok(isset($vm[1],$vm[2],$vm[3])&&[(int)$vm[1],(int)$vm[2],(int)$vm[3]]>=[1,3,16],'version endpoint reports v1.3.16 or later');
ok(str_contains($version,"'database_schema_target'=>'1.3.13'"),'community launch toggle does not require a database migration');
ok(str_contains($version,"'fan_community_launch_control'=>'admin-toggle-default-off-crm-stays-active'"),'version endpoint advertises launch-control capability');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.13'"),'database schema target remains 1.3.13');
ok(str_contains($wf,'php tests/v1316-section17-community-toggle.php'),'release gate includes community launch-control regression suite');
echo "Stonefellow v1.3.16 Fan Community launch-control audit: PASS\n";
