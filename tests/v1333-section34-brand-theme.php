<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok34(bool $v,string $m): void { if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n"; }
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=120): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/theme-core.php';

$presets=sf_theme_presets();
ok34(array_keys($presets)===['desert','midnight','paper','monochrome'],'theme system exposes four curated Stonefellow presets');
foreach($presets as $name=>$preset){$probe=['preset'=>$name]+$preset;$probe['mode']='system';$r=sf_theme_readiness($probe);ok34($r['ready']&&$r['score']===100,'preset '.$name.' passes dark and light contrast readiness');}
$badHex=sf_theme_default();$badHex['dark']['bg']='red';$thrown=false;try{sf_theme_normalize($badHex);}catch(InvalidArgumentException $e){$thrown=true;}ok34($thrown,'non-hex theme colors are rejected');
$badFont=sf_theme_default();$badFont['display_font']='https://evil.example/font.woff2';$thrown=false;try{sf_theme_normalize($badFont);}catch(InvalidArgumentException $e){$thrown=true;}ok34($thrown,'remote or arbitrary display fonts are rejected');
$modifiedPreset=sf_theme_default();$modifiedPreset['dark']['bg']='#010203';ok34(sf_theme_normalize($modifiedPreset)['preset']==='custom','server marks a modified preset as Custom instead of mislabeling it');
$low=sf_theme_default();$low['dark']['ink']=$low['dark']['bg'];$low['mode']='dark';$lr=sf_theme_readiness($low);ok34(!$lr['ready']&&count($lr['blockers'])>0,'insufficient primary-text contrast blocks publishing');
$system=sf_theme_default();$system['mode']='system';$cssProbe=sf_theme_css($system);ok34(str_contains($cssProbe,'prefers-color-scheme:light')&&str_contains($cssProbe,'--accent-text:')&&str_contains($cssProbe,'--display-font:'),'system appearance emits paired safe CSS variables');
ok34(sf_theme_contrast('#ffffff','#000000')>20.9,'contrast engine implements WCAG-style luminance ratio');

$core=file_get_contents($root.'/api/theme-core.php');
$adminApi=file_get_contents($root.'/admin/api/theme.php');
$adminJs=file_get_contents($root.'/admin/assets/theme.js');
$adminCss=file_get_contents($root.'/admin/assets/theme.css');
$adminMain=file_get_contents($root.'/admin/assets/admin.js');
$adminIndex=file_get_contents($root.'/admin/index.php');
$shell=file_get_contents($root.'/stonefellow-v120.php');
$app=file_get_contents($root.'/assets/js/app.js');
$siteCss=file_get_contents($root.'/assets/css/site.css');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$ht=file_get_contents($root.'/.htaccess');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

# 34A — Safe single-artist theme model
ok34(!str_contains($core,'artist_id')&&!str_contains($core,'tenant_id')&&!str_contains($core,'workspace_id'),'theme model remains single-artist and tenant-free');
ok34(str_contains($core,"['dark','light','system']")&&str_contains($core,"['square','soft','round']")&&str_contains($core,"['comfortable','compact']"),'appearance mode, corners and density are strict allowlists');
ok34(str_contains($core,'sf_theme_font_options')&&str_contains($core,'sf_theme_hex'),'type and color inputs are server allowlisted/validated');
ok34(!str_contains($core,'custom_css')&&!str_contains($core,'@import'),'theme system exposes no arbitrary CSS or remote stylesheet escape hatch');
ok34(str_contains($core,"site.theme_published_json")&&str_contains($core,"site.theme_draft_json"),'published and draft theme state use existing app_meta settings storage');
ok34(!str_contains($core,'CREATE TABLE'),'visual theming adds no unnecessary database table');

# 34B — Contrast/readiness governance
ok34(str_contains($core,'function sf_theme_luminance')&&str_contains($core,'function sf_theme_contrast'),'server owns deterministic contrast calculations');
ok34(str_contains($core,"['ink_bg','Primary text / background'")&&str_contains($core,"['accent_text','Accent button text'"),'readiness checks primary, secondary, surface, accent and button contrast');
ok34(str_contains($core,'Theme cannot be published until contrast blockers are resolved.'),'server blocks publishing a contrast-failing theme');
ok34(str_contains($adminJs,'CONTRAST BLOCKED')&&str_contains($adminJs,'READY TO PUBLISH'),'Admin shows deterministic theme readiness');

# 34C — Draft, preview, publish and restore
ok34(str_contains($adminApi,'Publishing the public visual theme requires explicit Admin confirmation.'),'theme publish requires explicit Admin confirmation');
ok34(str_contains($adminApi,'Restoring the default public theme requires explicit Admin confirmation.'),'live default restore requires explicit Admin confirmation');
ok34(str_contains($adminApi,'theme_draft_saved')&&str_contains($adminApi,'theme_published')&&str_contains($adminApi,'theme_default_restored'),'theme lifecycle is written to Admin audit');
ok34(str_contains($adminApi,'sf_agent_brain_log')&&str_contains($adminApi,"'route'=>'artist_brand'"),'publish lifecycle is visible in Agent Brain');
ok34(str_contains($adminJs,'markUnsaved')&&str_contains($adminJs,'Save the draft before live preview or publishing.'),'unsaved editor changes cannot masquerade as the saved draft');
ok34(str_contains($adminJs,'Live saved-draft preview')&&str_contains($adminJs,"../?theme_preview=1"),'Admin can open the real public site using the saved draft');
ok34(str_contains($adminJs,"confirm('Publish this visual theme")&&str_contains($adminJs,"confirm('Restore and publish the original Stonefellow Desert"),'editor mirrors publish/restore confirmation');

# 34D — Secure public preview and shell application
ok34(str_contains($shell,'theme_preview')&&str_contains($shell,"role']??'')==='admin'"),'draft visual preview is only available to an authenticated Administrator');
ok34(str_contains($shell,'noindex,nofollow,noarchive')&&str_contains($shell,"canonical']='';"),'admin draft preview is noindex and has no canonical URL');
ok34(str_contains($shell,'sf_theme_css($activeTheme)')&&str_contains($shell,'sf_theme_body_classes($activeTheme)'),'public shell applies theme variables and theme classes server-side');
ok34(str_contains($shell,'prefers-color-scheme: dark')&&str_contains($shell,'prefers-color-scheme: light'),'system appearance emits browser theme-color variants');
ok34(str_contains($shell,'STONEFELLOW_THEME_PREVIEW')&&str_contains($shell,"'appearance'=>"),'public bootstrap exposes bounded appearance state and preview mode');
ok34(str_contains($app,"finish=u=>{if(window.STONEFELLOW_THEME_PREVIEW)u.searchParams.set('theme_preview','1')"),'SPA navigation preserves admin-only draft preview');
ok34(str_contains($app,"if(window.STONEFELLOW_THEME_PREVIEW){m.robots='noindex,nofollow,noarchive';m.canonical=''"),'SPA SEO sync cannot re-index an admin theme preview');
ok34(str_contains($app,'async function recordDiscovery')&&str_contains($app,'if(window.STONEFELLOW_THEME_PREVIEW)return;try{'),'theme preview suppresses public discovery/event writes in the browser');
$storiesApi=file_get_contents($root.'/api/stories.php');
ok34(str_contains($storiesApi,"theme_preview")&&str_contains($storiesApi,"role']??'')==='admin'")&&str_contains($storiesApi,'if(!$themePreview)sf_story_event'),'server-side story page views are suppressed only for authenticated Admin preview');

# 34E — Public surface integration
foreach(['--surface:','--accent-text:','--display-font:','--body-font:','--card-radius:','--control-radius:'] as $token)ok34(str_contains($siteCss,$token),'public CSS includes theme token '.$token);
ok34(str_contains($siteCss,'.topbar{background:linear-gradient')&&str_contains($siteCss,'.chat-footer{background:linear-gradient'),'persistent public shell uses theme colors');
ok34(str_contains($siteCss,'.menu-sheet,.notification-drawer,.queue-drawer,.footer-player,.chatbar'),'menus, drawers, player and chat share themed surfaces');
ok34(str_contains($siteCss,'.splash-screen{background:var(--bg)')&&str_contains($siteCss,'.artist-homepage,.storytelling-about,.story-public-page'),'Splash, Homepage and Storytelling are covered by the theme layer');
ok34(str_contains($siteCss,'font-family:var(--display-font)')&&str_contains($siteCss,'font-family:var(--body-font)'),'public display and body typography use theme tokens');
ok34(!str_contains($siteCss,'Georgia,serif')&&!str_contains($siteCss,'Inter,system-ui,sans-serif'),'legacy hard-coded public type stacks no longer bypass the theme');
ok34(str_contains($shell,'Stonefellow — Maintenance')&&str_contains($shell,'sf_theme_css($activeTheme)'),'maintenance mode uses the published visual theme');
ok34(str_contains($siteCss,'.theme-density-compact')&&str_contains($siteCss,'.theme-corners-square'),'density and corner controls affect real public components');
ok34(str_contains($siteCss,'.site-announcement,.notification-drawer,.queue-drawer')&&str_contains($siteCss,'background:var(--surface2)'),'announcement, drawers and content placeholders use theme surfaces');
ok34(str_contains($siteCss,'.story-lede,.story-rich-copy p')&&str_contains($siteCss,'color:var(--muted)!important'),'Storytelling text follows theme secondary color');

# 34F — Settings/editor integration
ok34(str_contains($adminIndex,'assets/theme.css')&&str_contains($adminIndex,'assets/theme.js'),'Artist Brand Admin assets load in v1.3.33');
ok34(substr_count($adminIndex,'<button type="button" data-view=')===29,'Artist Brand adds no 30th Admin sidebar item');
ok34(str_contains($adminMain,'id="themePanel"')&&str_contains($adminMain,"SFThemeAdmin?.renderInto?.('themePanel')"),'Artist Brand editor is embedded in Settings');
ok34(str_contains($adminMain,'Artist Brand & Visual Theme lives in Settings.'),'Admin Agent routes appearance requests to the theme editor');
ok34(str_contains($adminJs,'type="color"')&&str_contains($adminJs,'name="max_width"')&&str_contains($adminJs,'name="display_font"'),'theme editor exposes bounded colors, width and typography');
ok34(str_contains($adminCss,'.theme-preview-grid')&&str_contains($adminCss,'.theme-contrast-list'),'Admin provides visual preview and contrast detail');

# 34G — Release/version boundaries
ok34(str_contains($boot,"require_once __DIR__ . '/theme-core.php'"),'theme core loads from canonical bootstrap');
ok34(str_contains($shell,"define('SF_BUILD', '1.3.33')")&&str_contains($adminIndex,"define('SF_ADMIN_BUILD','1.3.33')"),'public and Admin asset builds advance to v1.3.33');
ok34(str_contains($ht,'X-Stonefellow-Build "1.3.33"'),'web-server build header advances to v1.3.33');
ok34(str_contains($version,"'stonefellow'=>'1.3.33'")&&str_contains($version,"'database_schema_target'=>'1.3.28'"),'v1.3.33 intentionally keeps database schema 1.3.28');
ok34(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.28'")&&!str_contains($mig,"'id'=>'2026-10-11-030'"),'Artist Brand requires no new database migration');
ok34(str_contains($version,"'artist_brand_theme'=>'single-artist-draft-preview-publish-dark-light-system'"),'version endpoint advertises the Artist Brand contract');

# 34H — Regression gate
ok34(str_contains($wf,'node --check admin/assets/theme.js'),'release gate syntax-checks Theme Admin JavaScript');
ok34(str_contains($wf,'php tests/v1333-section34-brand-theme.php'),'release gate includes Section 34 certification');

echo "Stonefellow v1.3.33 Section 34 Artist Brand & Visual Theme audit: PASS\n";
