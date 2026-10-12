<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok40($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
$manifest=(string)file_get_contents($root.'/manifest.php');
$sw=(string)file_get_contents($root.'/service-worker.js');
$offline=(string)file_get_contents($root.'/offline.html');
$a11y=(string)file_get_contents($root.'/assets/js/accessibility.js');
$app=(string)file_get_contents($root.'/assets/js/app.js');
$css=(string)file_get_contents($root.'/assets/css/site.css');
$shell=(string)file_get_contents($root.'/stonefellow-v120.php');
$ht=(string)file_get_contents($root.'/.htaccess');
$version=(string)file_get_contents($root.'/version.php');
$admin=(string)file_get_contents($root.'/admin/index.php');
$mig=(string)file_get_contents($root.'/api/migrations.php');
$wf=(string)file_get_contents($root.'/.github/workflows/release-gate.yml');
$releaseNotes=(string)file_get_contents($root.'/RELEASE-NOTES.md');

ok40(str_contains($manifest,"'name'=>'Stonefellow'")&&str_contains($manifest,"'short_name'=>'Stonefellow'"),'manifest identifies the single Stonefellow app');
ok40(str_contains($manifest,"'start_url'=>'./'")&&str_contains($manifest,"'scope'=>'./'"),'manifest is subdirectory-safe');
ok40(str_contains($manifest,"'display'=>'standalone'")&&str_contains($manifest,"'categories'=>['music','entertainment']"),'manifest is installable and music-scoped');
ok40(str_contains($manifest,"stonefellow-pwa.svg")&&str_contains($manifest,"'purpose'=>'any maskable'"),'manifest includes an app-owned maskable fallback icon');
ok40(str_contains($manifest,'sf_seo_https_url($appIcon)'),'configured app icon must be HTTPS before manifest exposure');

ok40(preg_match("/stonefellow-static-v1\\.3\\.(?:39|[4-9][0-9])/",$sw)===1,'service worker cache remains Section 40 compatible or newer');
ok40(str_contains($sw,"SHELL=['offline.html','assets/css/site.css','assets/js/app.js','assets/js/library.js'")&&str_contains($sw,"'assets/icons/stonefellow-pwa.svg'"),'offline precache retains the certified public static shell');
ok40(!str_contains($sw,'config.js')&&!str_contains($sw,'catalog.js')&&!str_contains($sw,'releases.js'),'generated catalog/config data is not precached');
ok40(!str_contains($sw,'/api/')&&!str_contains($sw,'/admin/'),'service worker has no API/Admin caching path');
ok40(str_contains($sw,"req.mode==='navigate'")&&str_contains($sw,"fetch(req).catch"),'navigation is network-first with offline fallback');
ok40(str_contains($sw,'c.match(req,{ignoreSearch:true})'),'version-query static assets can use safe precache fallback');
ok40(str_contains($sw,"!/no-store/i.test"),'service worker refuses to persist no-store responses');
ok40(str_contains($offline,'Nothing private is cached for offline use.'),'offline shell explicitly states private data is not cached');
ok40(str_contains($offline,'Try again'),'offline shell provides a keyboard-accessible retry action');

ok40(str_contains($shell,'rel="manifest" href="manifest.webmanifest"'),'public shell links the web manifest');
ok40(str_contains($shell,'apple-mobile-web-app-capable'),'public shell includes mobile install metadata');
ok40(str_contains($shell,'class="skip-link" href="#mainContent"'),'skip navigation is present');
ok40(str_contains($shell,'id="mainContent" class="stage" tabindex="-1"'),'skip target can receive focus');
ok40(str_contains($shell,'id="a11yStatus"')&&str_contains($shell,'aria-live="polite"'),'dedicated accessibility status region exists');
ok40(str_contains($shell,'id="notificationDrawer"')&&str_contains($shell,'role="dialog" aria-modal="true" aria-labelledby="activityDrawerTitle"'),'activity drawer is a named modal dialog');
ok40(str_contains($shell,'id="queueDrawer"')&&str_contains($shell,'role="dialog" aria-modal="true" aria-labelledby="queueDrawerTitle"'),'queue drawer is a named modal dialog');
ok40(str_contains($shell,'id="installAppButton"')&&str_contains($shell,'hidden>Install Stonefellow'),'install control is progressive and hidden until eligible');
ok40(str_contains($shell,'assets/js/accessibility.js'),'accessibility/PWA runtime loads after the main app');
$build40='0.0.0';if(preg_match("/define\\('SF_BUILD', '([^']+)'\\)/",$shell,$b40))$build40=$b40[1];ok40(version_compare($build40,'1.3.39','>='),'public shell remains Section 40 compatible or newer');

ok40(str_contains($a11y,"e.key==='Escape'")&&str_contains($a11y,"e.key==='Tab'"),'accessibility runtime handles Escape and trapped Tab navigation');
ok40(str_contains($a11y,'focusables(dialog)')&&str_contains($a11y,'lastOpener.focus()'),'modal focus is trapped and restored to the opener');
ok40(str_contains($a11y,"setAttribute('aria-current','page')"),'SPA navigation exposes current-page semantics');
ok40(str_contains($app,"new CustomEvent('stonefellow:navigation'"),'main SPA emits navigation state for accessibility semantics');
ok40(str_contains($a11y,'beforeinstallprompt')&&str_contains($a11y,'appinstalled'),'PWA install prompt lifecycle is governed');
ok40(str_contains($a11y,"'serviceWorker' in navigator")&&str_contains($a11y,"location.protocol==='https:'"),'service worker registration is restricted to secure contexts/localhost');
ok40(str_contains($a11y,"window.addEventListener('offline'")&&str_contains($a11y,"window.addEventListener('online'"),'network status changes are announced');

ok40(str_contains($css,'.skip-link')&&str_contains($css,':focus-visible'),'visible keyboard focus and skip-link styling exist');
ok40(str_contains($css,'prefers-reduced-motion:reduce')&&str_contains($css,'animation:none!important'),'reduced-motion preference disables decorative motion');
ok40(str_contains($css,'forced-colors:active'),'forced-colors/high-contrast mode is supported');
ok40(str_contains($css,'pointer:coarse')&&str_contains($css,'min-height:44px'),'coarse-pointer controls receive 44px touch targets');

ok40(str_contains($ht,'^manifest\.webmanifest$ manifest.php'),'Apache exposes canonical manifest URL');
ok40(str_contains($ht,'<Files "service-worker.js">')&&str_contains($ht,'no-cache, no-store, must-revalidate'),'service worker itself is never served stale');
ok40(!str_contains($ht,'Service-Worker-Allowed'),'service worker cannot expand beyond its default app-root scope');
ok40(preg_match('/X-Stonefellow-Build "1\\.3\\.(?:39|[4-9][0-9])"/',$ht)===1,'server build header remains Section 40 compatible or newer');

$app40='0.0.0';if(preg_match("/'stonefellow'=>'([^']+)'/",$version,$v40))$app40=$v40[1];ok40(version_compare($app40,'1.3.39','>='),'version endpoint remains Section 40 compatible after later releases');
ok40(str_contains($version,"'database_schema_target'=>'1.3.32'"),'Section 40 intentionally adds no database migration');
ok40(str_contains($version,"'pwa_install'=>'dynamic-manifest-install-prompt-standalone-shell'"),'version endpoint advertises PWA install support');
ok40(str_contains($version,"'accessibility_keyboard'=>'skip-link-dialog-focus-trap-escape-focus-return-aria-current'"),'version endpoint advertises keyboard accessibility support');
ok40(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.32'")&&!str_contains($mig,"'app_version'=>'1.3.39'"),'migration target remains unchanged with no unnecessary Section 40 migration');
ok40(preg_match("/define\\('SF_ADMIN_BUILD','([^']+)'\\)/",$admin,$a40)&&version_compare($a40[1],'1.3.39','>='),'Admin asset build remains Section 40 compatible or newer');
ok40(substr_count($admin,'data-view="')===29,'Section 40 adds no Admin sidebar item');
ok40(str_contains($wf,'node --check assets/js/accessibility.js')&&str_contains($wf,'php tests/v1339-section40-accessibility-pwa.php'),'release gate certifies accessibility runtime and Section 40');
ok40(!str_contains($releaseNotes,'NaN# Stonefellow'),'release notes contain no NaN/corruption artifact');

echo "Stonefellow v1.3.39 Section 40 Accessibility, PWA & Mobile Resilience audit: PASS\n";
