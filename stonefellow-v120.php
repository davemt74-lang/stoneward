<?php
declare(strict_types=1);
define('SF_ROOT', __DIR__);
define('SF_BUILD', '1.3.317');
require_once __DIR__.'/api/bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Stonefellow-Build: '.SF_BUILD);
if(!sf_installed()){
    http_response_code(503);
    header('Cache-Control: no-store');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stonefellow configuration required</title><style>body{margin:0;background:#090807;color:#f2eee8;font:16px/1.5 system-ui,sans-serif}.wrap{width:min(720px,calc(100% - 36px));margin:12vh auto}.brand{font-weight:800;letter-spacing:.25em}.card{margin-top:32px;border-top:1px solid #342f29;padding-top:24px;color:#aaa}h1{font:400 clamp(2rem,6vw,4rem) Georgia,serif;color:#f2eee8}</style></head><body><main class="wrap"><div class="brand">STONEFELLOW</div><div class="card"><h1>Configuration required.</h1><p>The installed application cannot find its database configuration. The live storefront does not redirect to an installer.</p></div></main></body></html><?php
    exit;
}
$currentUser=sf_current_user();
$voiceEngineBootstrap='legacy';
if($currentUser){
    require_once __DIR__.'/api/speech-engine-core.php';
    try{$voiceState=sf_speech_engine_public_state((int)$currentUser['id']);$voiceEngineBootstrap=(string)($voiceState['effective_engine']??'legacy');}
    catch(Throwable $e){$voiceEngineBootstrap='legacy';}
}
$siteSettings=sf_site_settings();$themePreview=!empty($_GET['theme_preview'])&&(($currentUser['role']??'')==='admin');$activeTheme=$themePreview?sf_theme_draft():sf_theme_published();$themeMeta=sf_theme_meta_colors($activeTheme);
require_once __DIR__.'/api/pwa-shell.php';
$pwaSplash=sf_pwa_splash_published();
$configuredBase=rtrim(sf_public_base_url(),'/');
$scriptDir=rtrim(str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/'))),'/');
$appBaseHref=$configuredBase!==''?$configuredBase.'/':(($scriptDir!==''?$scriptDir:'').'/');
$initialView=sf_clean_text($_GET['view']??'',40);$publicInitialViews=['press','campaign','story','video','photo','track','release','show','store','music','releases','shows','videos','contact','about','archive'];$publicInitialViews[]='photos';$publicInitialViews[]='search';if(!in_array($initialView,$publicInitialViews,true))$initialView='';
$initialId=sf_clean_text($_GET['id']??'',160);$initialSlug=sf_clean_text($_GET['slug']??'',160);
$initialRoute=['view'=>$initialView,'id'=>$initialId,'slug'=>$initialSlug];
$seo=sf_seo_resolve($initialView,$initialId,$initialSlug);if($themePreview){$seo['robots']='noindex,nofollow,noarchive';$seo['canonical']='';}
$launchSplash=$pwaSplash['launch']??[];
$showLaunch=($_GET['view']??'')===''&&!$themePreview&&in_array($launchSplash['mode']??'', ['image','code'], true);
$launchFrame=$showLaunch&&$launchSplash['mode']==='code'?sf_pwa_splash_surface_url('launch',(int)$pwaSplash['revision']):'';
$framePolicy=sf_pwa_shell_frame_policy($appBaseHref,$launchFrame);
function sf_h(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
?>
<?php if($siteSettings['maintenance_enabled'] && (($currentUser['role']??'')!=='admin')): ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Stonefellow — Maintenance</title><style><?=sf_theme_css($activeTheme)?>body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 var(--body-font)}.wrap{width:min(680px,calc(100% - 40px));margin:18vh auto}.brand{font-weight:800;letter-spacing:.28em;font-size:.75rem}h1{font:400 clamp(2.5rem,8vw,5rem)/1 var(--display-font);margin:42px 0 18px;color:var(--ink)}p{color:var(--muted);max-width:560px}</style></head><body><main class="wrap"><div class="brand">STONEFELLOW</div><h1>We’ll be right back.</h1><p><?=sf_h($siteSettings['maintenance_message'])?></p></main></body></html>
<?php exit; endif; ?>
<!doctype html>
<html lang="en">
<head>
  <meta http-equiv="Content-Security-Policy" content="<?=sf_h($framePolicy)?>" data-sf-canvas-frame-policy>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <base href="<?=sf_h($appBaseHref)?>">
<?php sf_pwa_shell_head($pwaSplash,(string)($siteSettings['media_app_icon']??'')); ?>
<?php if($themeMeta['mode']==='system'): ?>  <meta name="theme-color" media="(prefers-color-scheme: dark)" content="<?=sf_h($themeMeta['dark'])?>">
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="<?=sf_h($themeMeta['light'])?>">
<?php else: ?>  <meta name="theme-color" content="<?=sf_h($themeMeta[$themeMeta['mode']])?>">
<?php endif; ?>
  <meta name="description" content="<?=sf_h((string)$seo['description'])?>">
  <meta name="robots" content="<?=sf_h((string)$seo['robots'])?>">
<?php if(!empty($seo['canonical'])): ?>  <link rel="canonical" href="<?=sf_h((string)$seo['canonical'])?>">
<?php endif; ?>  <meta property="og:site_name" content="Stonefellow">
  <meta property="og:title" content="<?=sf_h((string)$seo['title'])?>">
  <meta property="og:description" content="<?=sf_h((string)$seo['description'])?>">
  <meta property="og:type" content="<?=sf_h((string)$seo['type'])?>">
<?php if(!empty($seo['canonical'])): ?>  <meta property="og:url" content="<?=sf_h((string)$seo['canonical'])?>">
<?php endif; ?><?php if(!empty($seo['image'])): ?>  <meta property="og:image" content="<?=sf_h((string)$seo['image'])?>">
<?php endif; ?>  <meta name="twitter:card" content="<?=!empty($seo['image'])?'summary_large_image':'summary'?>">
  <meta name="twitter:title" content="<?=sf_h((string)$seo['title'])?>">
  <meta name="twitter:description" content="<?=sf_h((string)$seo['description'])?>">
<?php if(!empty($seo['image'])): ?>  <meta name="twitter:image" content="<?=sf_h((string)$seo['image'])?>">
<?php endif; ?><?php if(!empty($siteSettings['media_app_icon'])): ?>  <link rel="icon" href="<?=sf_h($siteSettings['media_app_icon'])?>">
<?php endif; ?>
  <meta name="stonefellow-build" content="<?=sf_h(SF_BUILD)?>">
  <title><?=sf_h((string)$seo['title'])?></title>
<?php foreach((array)$seo['json_ld'] as $structured): ?>  <script type="application/ld+json" data-seo-jsonld="1"><?=json_encode($structured,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<?php endforeach; ?>
  <link rel="stylesheet" href="assets/css/site.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/pwa-shell.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/agent-song-capture.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/public-agent-canvas.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/canvas-image-review.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/canvas-image-analysis.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/canvas-music-picker.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/canvas-projects.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/canvas-resource-picker.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/account-token-meter.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/canvas-access.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/canvas-gallery.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/fan-vocal-studio.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/song-moments.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/turntable-builder.css?v=<?=rawurlencode(SF_BUILD)?>">
  <link rel="stylesheet" href="assets/css/artist-world.css?v=<?=rawurlencode(SF_BUILD)?>">
  <style id="stonefellowTheme"><?=sf_theme_css($activeTheme)?></style>
  <style id="stonefellowBootStyle">
    body.sf-site-booting{background:#000!important;overflow:hidden}
    body.sf-site-booting>:not(#sfSiteBoot):not(script){visibility:hidden!important}
    #sfSiteBoot{position:fixed;inset:0;z-index:2147483600;display:grid;place-items:center;background:#000;color:#f7ecd5;font-family:system-ui,-apple-system,sans-serif;transition:opacity .58s ease,visibility .58s ease}
    #sfSiteBoot.is-ready{opacity:0;visibility:hidden;pointer-events:none}
    #sfSiteBoot[hidden]{display:none!important}
    .sf-boot-center{width:min(340px,calc(100vw - 54px));display:flex;align-items:center;flex-direction:column;text-align:center;gap:18px}
    .sf-boot-brand{letter-spacing:.4em;font-size:13px;font-weight:800;color:#fff5df}
    .sf-boot-orb{width:84px;height:84px;border-radius:50%;position:relative;border:1px solid #f2cd854d;background:radial-gradient(circle at 37% 29%,#ffedba 1%,#da9746 5%,#57331c 33%,#090806 72%);box-shadow:0 0 45px #ecab553b,inset 0 0 32px #000;animation:sfBootBreathe 2.8s ease-in-out infinite}
    .sf-boot-orb:before,.sf-boot-orb:after{content:"";position:absolute;inset:-16px;border:1px solid #efd4a37a;border-radius:50%;transform:rotate(-28deg) scaleY(.62);animation:sfBootOrbit 4s linear infinite}
    .sf-boot-orb:after{inset:-26px;border-color:#d9b88652;animation-duration:6s;animation-direction:reverse;transform:rotate(36deg) scaleY(.7)}
    .sf-boot-status{font:600 10px/1.6 ui-monospace,monospace;letter-spacing:.16em;text-transform:uppercase;color:#c6bba8;min-height:17px}
    .sf-boot-track{width:100%;height:2px;background:#38302a;border-radius:99px;overflow:hidden}
    #sfBootBar{height:100%;width:8%;border-radius:99px;background:linear-gradient(90deg,#b78650,#fff1cb);box-shadow:0 0 16px #f2cc95;transition:width .45s ease}
    #sfBootRetry{background:transparent;border:1px solid #b99764;color:#fff1cd;border-radius:99px;padding:10px 19px;font:600 12px system-ui;cursor:pointer}
    #sfBootRetry[hidden]{display:none}
    @keyframes sfBootBreathe{50%{filter:brightness(1.25);transform:scale(1.04)}}
    @keyframes sfBootOrbit{to{transform:rotate(332deg) scaleY(.62)}}
    @media(prefers-reduced-motion:reduce){.sf-boot-orb,.sf-boot-orb:before,.sf-boot-orb:after{animation:none}#sfSiteBoot,#sfBootBar{transition:none}}
  </style>
</head>
<body class="<?=sf_h(trim('sf-site-booting sf-agent-canvas-active sf-agent-chatbar-collapsed '.sf_theme_body_classes($activeTheme).' '.(($siteSettings['announcement_enabled'] && $siteSettings['announcement_text']!=='')?'has-announcement ':'').(!empty($siteSettings['media_hero'])?'has-site-hero':'')))?>"<?=!empty($siteSettings['media_hero'])?' style="--site-hero-image:url(\''.sf_h($siteSettings['media_hero']).'\')"':''?>>
  <section id="sfSiteBoot" role="status" aria-live="polite" aria-label="Loading Stonefellow">
    <div class="sf-boot-center"><div class="sf-boot-brand">STONEFELLOW</div><div class="sf-boot-orb" aria-hidden="true"></div><div id="sfBootStatus" class="sf-boot-status">Preparing Agent Canvas</div><div class="sf-boot-track" aria-hidden="true"><div id="sfBootBar"></div></div><button id="sfBootRetry" type="button" hidden onclick="location.reload()">Retry loading</button></div>
  </section>
  <script>
    window.StonefellowBoot=(()=>{const layer=document.getElementById('sfSiteBoot'),status=document.getElementById('sfBootStatus'),bar=document.getElementById('sfBootBar'),retry=document.getElementById('sfBootRetry');let completed=false;
      const watchdog=setTimeout(()=>{if(!completed){status.textContent='Stonefellow is taking longer than expected.';retry.hidden=false;}},18000);
      return {step(percent,label){if(completed)return;bar.style.width=Math.max(8,Math.min(98,Number(percent)||8))+'%';if(label)status.textContent=label},finish(){if(completed)return;completed=true;clearTimeout(watchdog);bar.style.width='100%';status.textContent='Agent ready';document.body.classList.remove('sf-site-booting');layer.classList.add('is-ready');setTimeout(()=>{layer.hidden=true},650)},fail(){if(completed)return;clearTimeout(watchdog);status.textContent='Unable to start the Agent Canvas.';retry.hidden=false}};
    })();
  </script>
<a class="skip-link" href="#mainContent">Skip to main content</a>
  <div id="a11yStatus" class="sr-only" aria-live="polite" aria-atomic="true"></div>
<?php if($siteSettings['announcement_enabled'] && $siteSettings['announcement_text']!==''): ?><div class="site-announcement"><?=sf_h($siteSettings['announcement_text'])?></div><?php endif; ?>
<?php if($showLaunch): ?>
  <dialog class="sf-pwa-launch" data-pwa-launch="<?=(int)$pwaSplash['revision']?>" aria-labelledby="pwa-launch-title">
    <h2 id="pwa-launch-title"><?=sf_h($launchSplash['title']?:'Welcome to Stonefellow')?></h2>
<?php if($launchSplash['message']!==''): ?>    <p><?=sf_h($launchSplash['message'])?></p>
<?php endif; sf_pwa_shell_art($launchSplash,$launchFrame); ?>
    <form method="dialog"><button type="submit" autofocus>Continue</button></form>
  </dialog>
<?php endif; ?>
  <header class="topbar">
    <a class="wordmark" href="./" id="homeLink" aria-label="Stonefellow home"><?php if(!empty($siteSettings['media_logo'])): ?><img src="<?=sf_h($siteSettings['media_logo'])?>" alt="Stonefellow"><?php else: ?>STONEFELLOW<?php endif; ?></a>
    <div class="header-actions">
      <button id="notificationButton" class="notification-button" type="button" aria-label="Open notifications and history" aria-expanded="false">
        <span class="notification-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg></span><span id="notificationBadge" class="notification-badge" hidden>0</span>
      </button>
    <details id="menuPanel" class="menu-details">
      <summary id="menuButton" class="menu-button" aria-label="Open menu">•••</summary>
      <div class="menu-sheet">
        <button class="menu-close" id="menuClose" type="button" aria-label="Close menu">×</button>
        <div class="menu-atmosphere" role="group" aria-label="Agent atmosphere presets">
          <span class="menu-atmosphere-label">ATMOSPHERE</span>
          <div class="menu-atmosphere-options">
            <button type="button" data-sf-atmosphere="ember" aria-pressed="false" title="Cinematic sunset">Ember</button>
            <button type="button" data-sf-atmosphere="mesh" aria-pressed="false" title="Blue mesh">Mesh</button>
            <button type="button" data-sf-atmosphere="violet" aria-pressed="false" title="Violet signal">Violet</button>
            <button type="button" data-sf-atmosphere="lunar" aria-pressed="false" title="Moonlit mountain horizon">Lunar</button>
          </div>
        </div>
        <section id="menuTokenMeter" class="menu-token-meter" aria-label="Your Active Tokens" hidden>
          <div class="menu-token-meter-head"><strong data-token-title>Active Tokens</strong><span class="menu-token-meter-count" data-token-count></span></div>
          <progress data-token-bar max="100" aria-label="Percentage of current token allowance remaining" hidden></progress>
          <span class="menu-token-meter-percentage" data-token-percentage></span>
          <small data-token-basis></small><small data-token-held hidden></small>
          <small class="menu-token-meter-status" data-token-status role="status"></small>
        </section>
        <nav>
          <button id="menuShowAgent" class="menu-link-button" type="button" hidden>Show Agent</button>
          <details id="menuCodingModelField" class="menu-coding-model" hidden>
            <summary id="menuCodingModel">Coding Model <span id="menuCodingModelCurrent">Use default</span></summary>
            <fieldset id="menuCodingModelOptions" aria-label="Coding Model" aria-describedby="menuCodingModelStatus"></fieldset>
            <span id="menuCodingModelStatus" role="status"></span>
          </details>
          <button id="menuCanvasPermissions" class="menu-link-button" type="button" hidden>Canvas data permissions</button>
          <section id="menuCanvasProjects" class="menu-canvas-projects" aria-label="Canvas project settings" hidden>
            <label><input id="menuCanvasIdeas" type="checkbox" checked>Canvas ideas</label>
            <label><input id="menuCanvasAutoApprove" type="checkbox">Auto-approve agent projects</label>
            <small>Projects wait for your approval by default. Planning uses Active Tokens.</small>
            <button id="menuCanvasSuggest" type="button">Suggest a canvas</button>
            <small id="menuCanvasProjectsStatus" role="status"></small>
          </section>
          <a href="./" data-view="home">Agent Canvas</a>
          <a href="?view=canvas-gallery" data-view="canvas-gallery">Canvas Catalog</a>
          <a href="world" data-view="world">Artist World</a>
          <a href="music" data-view="music">Music</a>
          <a href="search" data-view="search">Search</a>
          <a href="releases" data-view="releases">Releases</a>
          <a href="archive" data-view="archive">Music Archive</a>
          <a href="shows" data-view="shows">Shows &amp; Live</a>
          <a href="jukebox.php">Live Sessions</a>
          <a href="videos" data-view="videos">Videos</a>
          <a href="photos" data-view="photos">Photos</a>
          <a href="?view=tickets" data-view="tickets">Tickets &amp; VIP</a>
<?php if(!empty($siteSettings['fan_community_enabled'])): ?>
          <a href="?view=community" data-view="community">Fan Community</a>
<?php endif; ?>
          <a href="store" data-view="store">Store</a>
          <a href="?view=builder" data-view="builder">Create a record</a>
          <a href="?view=cart" data-view="cart">Cart</a>
          <a href="?view=plans" data-view="plans">Plans</a>
          <a href="about" data-view="about">About</a>
          <a href="contact" data-view="contact">Contact</a>
          <button id="installAppButton" class="menu-install" type="button" hidden>Install Stonefellow</button>
          <div class="sf-pwa-alert-controls" data-pwa-phone-alerts hidden>
            <button type="button" data-pwa-alert-toggle>Enable phone alerts</button>
            <p role="status" data-pwa-alert-status></p>
            <a href="?view=login" data-pwa-alert-signin hidden>Sign in for phone alerts</a>
          </div>
          <a href="?view=privacy" data-view="privacy">Privacy</a>
          <a href="?view=terms" data-view="terms">Terms</a>
<?php if($currentUser): ?>
          <div class="menu-identity">
            <span>Signed in</span><strong><?=sf_h((string)$currentUser['display_name'])?></strong>
          </div>
          <a href="?view=library" data-view="library" id="menuLibrary">My Library</a>
          <a href="?view=account" data-view="account" id="menuAccount">My account</a>
          <a href="?view=capture" data-view="capture" id="menuCreativeCapture">Creative Capture</a>
<?php require_once __DIR__.'/api/team-workspace-core.php'; if(sf_team_can((int)$currentUser['id'],'team.enter')): ?>
          <a href="?view=team" data-view="team" id="menuTeamWorkspace">Team Workspace</a>
<?php endif; ?>
          <a href="?view=agent-brain" data-view="agent-brain" id="menuAgentBrain">My Agent Brain</a>
          <a href="?view=agent-chat" data-view="agent-chat" id="menuAgentChat">My Agent Chat</a>
          <a href="#" id="menuQueueLink">Up Next</a>
<?php else: ?>
          <a href="?view=login" data-view="login" id="menuLogin">Login</a>
          <a href="?view=register" data-view="register" id="menuRegister">Create account</a>
<?php endif; ?>
        </nav>
<?php if($currentUser): ?>
        <div class="menu-session-actions" aria-label="Account actions">
<?php if(($currentUser['role']??'')==='admin'): ?>
          <a href="admin/" id="menuAdmin">Admin dashboard</a>
<?php endif; ?>
          <a href="logout.php" id="menuLogout">Logout</a>
        </div>
<?php endif; ?>
        <div class="menu-version">Stonefellow v<?=sf_h(SF_BUILD)?></div>
      </div>
    </details>
    </div>
  </header>


  <div id="notificationBackdrop" class="drawer-backdrop" hidden></div>
  <aside id="notificationDrawer" class="notification-drawer" hidden role="dialog" aria-modal="true" aria-labelledby="activityDrawerTitle">
    <div class="drawer-head">
      <div><span class="drawer-kicker">STONEFELLOW</span><h2 id="activityDrawerTitle">Activity</h2></div>
      <button id="notificationClose" type="button" aria-label="Close activity drawer">×</button>
    </div>
    <div class="drawer-tabs" data-drawer-group="activity" role="tablist" aria-label="Activity views">
      <button type="button" class="active" data-drawer-tab="notifications" role="tab">Notifications <span id="drawerUnreadCount"></span></button>
      <button type="button" data-drawer-tab="history" role="tab">History</button>
      <button type="button" data-drawer-tab="brain" role="tab">Agent Brain</button>
    </div>
    <div class="drawer-tabs" data-drawer-group="music" role="tablist" aria-label="Music library views" hidden>
      <button type="button" data-drawer-tab="songs" role="tab">Songs</button>
      <button type="button" data-drawer-tab="albums" role="tab">Albums</button>
    </div>
    <div class="drawer-tabs" data-drawer-group="commerce" role="tablist" aria-label="Shop and tickets views" hidden>
      <button type="button" data-drawer-tab="merch" role="tab">Merch</button>
      <button type="button" data-drawer-tab="tickets" role="tab">Tickets</button>
      <button type="button" data-drawer-tab="cart" role="tab">Cart <span id="drawerCartCount"></span></button>
    </div>
    <div class="drawer-tools" id="activityDrawerTools"><button id="markAllNotifications" type="button">Mark all read</button><button id="refreshNotifications" type="button">Refresh</button></div>
    <div id="drawerContent" class="drawer-content"><p class="drawer-empty">Loading…</p></div>
  </aside>

  <main id="mainContent" class="stage" tabindex="-1">
    <section id="dynamicCanvas" class="dynamic-canvas" aria-live="polite"><?php if($initialView!==''): ?><?=sf_seo_server_html($seo)?><?php endif; ?></section>
  </main>
  <!-- Keep the floating companion outside the scrolling page and player layers. -->
    <section class="agent-stage" aria-label="Stonefellow Agent">
      <button id="agentOrb" class="agent-orb" type="button" aria-label="Open Agent Chat" aria-controls="agentChatPopup" aria-expanded="false">
        <span class="orb-core"></span><span class="orb-ring ring-a"></span><span class="orb-ring ring-b"></span>
        <span class="agent-face" aria-hidden="true"><span class="agent-eye"></span><span class="agent-eye"></span><span class="agent-mouth"></span></span>
      </button>
      <div class="agent-meta"><span id="agentState">IDLE</span></div>
      <p id="agentMessage" class="agent-message" aria-live="polite">Hey. I’m the Stonefellow agent. I can play you something, tell you about the music, or help you make your own record.</p>
      <button id="agentInterrupt" class="agent-interrupt" type="button" hidden aria-label="Interrupt the Agent and listen">Stop speaking · listen</button>
      <div id="starterActions" class="starter-actions">
        <button type="button" data-command="play something">Play something</button>
        <button type="button" data-command="make me a record">Make me a record</button>
        <button type="button" data-command="show me the catalog">Look around</button>
      </div>
    </section>

  <div id="queueBackdrop" class="drawer-backdrop queue-backdrop" hidden></div>
  <aside id="queueDrawer" class="queue-drawer" hidden role="dialog" aria-modal="true" aria-labelledby="queueDrawerTitle">
    <div class="queue-drawer-head">
      <div><span class="drawer-kicker">STONEFELLOW</span><h2 id="queueDrawerTitle">Up Next</h2></div>
      <button id="queueClose" type="button" aria-label="Close Up Next queue">×</button>
    </div>
    <div class="queue-drawer-tools"><span id="queueDrawerCount">0 tracks</span><button id="queueClear" type="button">Clear queue</button></div>
    <div id="queueContent" class="queue-content"><p class="drawer-empty">Your queue is empty.</p></div>
  </aside>

  <div id="vocalStudioBackdrop" class="sf-vocal-backdrop" hidden></div>
  <aside id="vocalStudioDrawer" class="sf-vocal-drawer" hidden role="dialog" aria-modal="true" aria-labelledby="vocalStudioTitle">
    <div class="sf-vocal-drawer-head">
      <div><small>STONEFELLOW / RECORDING</small><h2 id="vocalStudioTitle">Record Studio</h2></div>
      <button id="vocalStudioClose" type="button" aria-label="Close recording studio">×</button>
    </div>
    <div class="sf-vocal-tabs" role="tablist" aria-label="Recording mode">
      <button type="button" role="tab" class="active" data-vocal-tab="vocal" aria-selected="true">Vocal Overdub</button>
      <button type="button" role="tab" data-vocal-tab="clip" aria-selected="false">Song Clip</button>
    </div>
    <div class="sf-vocal-drawer-body">
      <section data-vocal-panel="vocal" role="tabpanel" aria-label="Vocal overdub">
        <div class="sf-vocal-intro"><span>01 / RECORD WITH THE SONG</span><p>Sing over the Stonefellow track in the player. We record your microphone as a separate vocal take and create a combined mix you can preview. Recordings stay in this browser until you download them.</p></div>
        <div class="sf-vocal-current-track"><span>NOW PLAYING</span><strong id="vocalSong">Load a song from the music library</strong></div>
        <div class="sf-vocal-device"><label for="vocalMicSelect">MICROPHONE / USB INTERFACE</label><select id="vocalMicSelect"><option value="">System default microphone</option></select><button type="button" id="vocalMicConnect">Enable microphone</button><small id="vocalMicLabel">Permission is requested only when you enable a microphone.</small><div class="sf-vocal-meter" aria-label="Microphone input level"><span id="vocalMicMeter"></span></div></div>
        <p class="sf-vocal-device-note">Connect your Focusrite through USB and select it from this list. Use headphones to prevent the song from leaking into the microphone. Direct monitoring can be enabled on your interface.</p>
        <div class="sf-vocal-mix-controls"><label>VOCAL LEVEL<input id="vocalInputLevel" type="range" min="0" max="200" value="100" step="5" disabled></label><label>SONG LEVEL<input id="vocalSongLevel" type="range" min="0" max="200" value="85" step="5" disabled></label></div>
        <div class="sf-vocal-record-controls"><button type="button" id="vocalStart">● Start vocal take</button><button type="button" id="vocalStop" disabled>■ Stop &amp; finish</button><span id="vocalTimer" aria-label="Recording duration">0:00</span></div>
        <div id="vocalStudioRecording" class="sf-vocal-live" hidden><span></span> RECORDING YOUR VOCAL + SONG MIX</div>
        <div id="vocalStudioStatus" class="sf-vocal-feedback" role="status" aria-live="polite"></div>
        <section id="vocalResults" class="sf-vocal-results" hidden><h3>Your take is ready</h3><label>COMBINED SONG + VOCAL<audio id="vocalMixPreview" controls preload="none"></audio></label><a id="vocalMixDownload" class="sf-vocal-download" download>↓ Download the mix</a><label>VOCAL TRACK ONLY<audio id="vocalDryPreview" controls preload="none"></audio></label><a id="vocalDryDownload" class="sf-vocal-download" download>↓ Download vocal stem</a><p>Downloads include audio from this session. Nothing is uploaded to Stonefellow or published automatically.</p></section>
      </section>
      <section data-vocal-panel="clip" role="tabpanel" aria-label="Song clip recorder" hidden>
        <div class="sf-vocal-intro"><span>02 / CAPTURE A SONG EXCERPT</span><p>This is the original Record Clip feature. It captures part of the song from the player, without using a microphone. It is separate from your vocal overdubs.</p></div>
        <div class="sf-vocal-record-controls"><button id="vocalClipStart" type="button">● Record song clip</button><button id="vocalClipStop" type="button">■ Stop song clip</button></div>
        <p id="vocalClipStatus" class="sf-vocal-feedback" role="status">Play a song to start a clip.</p>
      </section>
    </div>
  </aside>

  <footer class="chat-footer">
    <div id="footerPlayer" class="footer-player" hidden aria-label="Music player">
      <button id="playerArtwork" class="player-artwork" type="button" aria-label="View current song"><span>SF</span></button>
      <div class="player-track-meta">
        <button id="playerTitle" class="player-title" type="button">Nothing playing</button>
        <span id="playerRelease" class="player-release">Stonefellow</span>
        <span id="playerPlaybackStatus" class="player-playback-status" role="status" hidden></span>
      </div>
      <button id="playerPrev" class="player-icon" type="button" aria-label="Previous song">‹</button>
      <button id="playerToggle" class="player-toggle" type="button" aria-label="Play">▶</button>
      <button id="playerNext" class="player-icon" type="button" aria-label="Next song">›</button>
      <button id="playerQueueButton" class="player-queue-button" type="button" aria-label="Open Up Next queue" aria-expanded="false">Queue <span id="playerQueueCount">0</span></button>
      <button id="playerMomentsButton" class="player-queue-button sf-player-moments-button" type="button" aria-label="Open Song Moments timeline">Moments <span aria-hidden="true">✧</span></button>
      <div class="player-timeline">
        <span id="playerCurrent">0:00</span>
        <input id="playerSeek" type="range" min="0" max="1000" value="0" aria-label="Song position">
        <span id="playerDuration">0:00</span>
      </div>
      <button id="playerClose" class="player-close" type="button" aria-label="Close player">×</button>
      <audio id="persistentAudio" preload="metadata"></audio>
    </div>
    <div class="chat-composer-shell">
      <button id="chatFooterHide" class="sf-chat-footer-hide" type="button" aria-label="Hide chat footer" title="Hide chat">×</button>
      <div id="voiceTranscript" class="voice-transcript" role="status" aria-live="polite" hidden><span class="voice-transcript-dot" aria-hidden="true"></span><span id="voiceTranscriptText"></span></div>
      <section id="agentChatPopup" class="agent-chat-popup" aria-label="Stonefellow conversation" hidden>
        <header class="agent-chat-popup-head"><div><strong>Stonefellow</strong><span id="agentChatPopupState" role="status">Ready</span></div><div class="agent-chat-popup-tools"><button id="agentVoiceReplyToggle" type="button" aria-pressed="true" title="Toggle spoken Agent replies">Voice on</button><button id="agentChatNew" type="button">New chat</button><button id="agentChatClose" type="button" aria-label="Close Agent Chat">×</button></div></header>
        <div id="agentChatPopupMessages" class="agent-chat-popup-messages" role="log" aria-live="polite" aria-label="Conversation history"><p class="agent-chat-empty">Your conversation will appear here. Use the bar below to type or speak.</p></div>
        <section id="canvasDebugPanel" class="sf-canvas-debug" aria-labelledby="canvasDebugTitle" hidden>
          <header><strong id="canvasDebugTitle">Canvas debug</strong><button id="canvasDebugCopy" type="button">Copy debug report</button></header>
          <textarea id="canvasDebugReport" aria-label="Canvas debug report" rows="4" readonly spellcheck="false"></textarea>
          <p id="canvasDebugStatus" role="status"></p>
        </section>
      </section>
      <div id="canvasImageEditDraft" class="sf-canvas-image-edit-draft" hidden>
        <span role="status" data-canvas-image-edit-label></span>
        <button type="button" data-canvas-image-edit-cancel aria-label="Cancel image edit">Cancel</button>
      </div>
    <div class="chat-command-anchor">
    <nav id="chatQuickMenu" class="chat-quick-menu" hidden aria-label="Slash commands">
      <label class="sr-only" for="chatCommandSearch">Search slash commands</label>
      <input id="chatCommandSearch" type="search" placeholder="Search commands…" autocomplete="off">
      <p data-command-empty role="status" hidden>No matching commands.</p>
      <button type="button" data-quick-action="record">Create Record</button>
      <button type="button" data-quick-action="playlist">Create Playlist</button>
      <button type="button" data-quick-action="shows">Tour Dates</button>
      <button type="button" data-quick-action="store">Merch Store</button>
      <button type="button" data-quick-action="membership">Membership + VIP</button>
      <button type="button" data-quick-action="tickets">Tickets + VIP</button>
<?php if(!empty($siteSettings['fan_community_enabled'])): ?>
      <button type="button" data-quick-action="community">Fan Community</button>
<?php endif; ?>
      <button type="button" data-quick-action="newsletter">Newsletter</button>
    </nav>
    <form id="chatForm" class="chatbar" autocomplete="off">
      <button id="chatQuickButton" class="chat-action plus" type="button" aria-label="Open slash commands" aria-controls="chatQuickMenu" aria-expanded="false">+</button>
      <button id="agentChatToggle" class="chat-action chat-toggle" type="button" aria-label="Open Agent Chat" aria-expanded="false" aria-controls="agentChatPopup" title="Open Agent Chat">▤</button>
      <label class="sr-only" for="chatInput">Ask Stonefellow anything</label>
      <input id="chatInput" type="text" placeholder="Ask Stonefellow anything…" maxlength="320">
      <button id="voiceButton" class="chat-action mic" type="button" aria-label="Start Voice Chat" aria-pressed="false">◉</button>
      <button class="chat-action send" type="submit" aria-label="Send message">↑</button>
    </form>
    </div>
    </div>
    <div class="social-links" aria-label="Stonefellow social links">
      <a href="#" data-social="instagram">Instagram</a>
      <a href="#" data-social="youtube">YouTube</a>
      <a href="#" data-social="bandcamp">Bandcamp</a>
      <a href="#" data-social="spotify">Spotify</a>
    </div>
  </footer>
  <button id="chatFooterRestore" class="sf-chat-footer-restore" type="button" aria-label="Show chat footer" title="Show chat footer · zzz on desktop">Chat <span>zzz</span></button>

  <script>window.STONEFELLOW_BUILD=<?=json_encode(SF_BUILD)?>;window.STONEFELLOW_THEME_PREVIEW=<?=json_encode($themePreview)?>;window.STONEFELLOW_VOICE_ENGINE_BOOTSTRAP=<?=json_encode($voiceEngineBootstrap)?>;window.STONEFELLOW_AUTH_BOOTSTRAP=<?=json_encode(['authenticated'=>(bool)$currentUser,'user'=>sf_user_public($currentUser)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;window.STONEFELLOW_SITE=<?=json_encode(['artistHomepageEnabled'=>(bool)($siteSettings['artist_homepage_enabled']??false),'homepageTemplate'=>$siteSettings['homepage_template']??'classic','fanCommunityEnabled'=>(bool)($siteSettings['fan_community_enabled']??false),'orbSongMomentsEnabled'=>(bool)($siteSettings['orb_song_moments_enabled']??false),'socials'=>['instagram'=>$siteSettings['social_instagram'],'youtube'=>$siteSettings['social_youtube'],'bandcamp'=>$siteSettings['social_bandcamp'],'spotify'=>$siteSettings['social_spotify']],'privacy'=>$siteSettings['privacy_text'],'terms'=>$siteSettings['terms_text'],'appearance'=>['mode'=>$activeTheme['mode'],'preset'=>$activeTheme['preset'],'density'=>$activeTheme['density'],'corner_style'=>$activeTheme['corner_style'],'max_width'=>$activeTheme['max_width']],'media'=>['logo'=>$siteSettings['media_logo']??'','hero'=>$siteSettings['media_hero']??'','social_share'=>$siteSettings['media_social_share']??'','app_icon'=>$siteSettings['media_app_icon']??'','artist_photo'=>$siteSettings['media_artist_photo']??'']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;</script>
  <script>window.STONEFELLOW_INITIAL_ROUTE=<?=json_encode($initialRoute,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script>
  <script src="assets/js/config.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script>if(window.STONEFELLOW_CONFIG){window.STONEFELLOW_CONFIG.socials=window.STONEFELLOW_CONFIG.socials||{};for(const [k,v] of Object.entries(window.STONEFELLOW_SITE.socials||{})){if(v)window.STONEFELLOW_CONFIG.socials[k]=v;}}</script>
  <script src="assets/js/catalog.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/releases.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/owned-music.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/library.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/agent-brain.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/agent-song-experience-ui.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/listening-telemetry.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-audio-sdk.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-location.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/vendor/canvas/color-thief.js?v=<?=rawurlencode(SF_BUILD)?>" integrity="sha256-9KjGmp30P1mNciedabWfLZ0pVho0/pmxF0ktvJCBY+4="></script>
  <script src="assets/js/media-artwork-palette.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-agent-theme.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-projects-ui.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-resource-picker.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-data-picker.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/account-token-meter.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-access.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-gallery.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-app-notifications.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/vendor/acorn.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-preview-guard.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-scene-tester.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-build-workflow.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-library-runtime.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/public-agent-canvas.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-image-review.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-image-analysis.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/canvas-music-picker.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/agent-chat-canvas.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/artist-home-renderer.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/turntable-parts.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/fan-discovery.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/conversation-barge-in.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/speech-segments.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/agent-audio-player-skill.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/agent-song-capture-skill.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/fan-vocal-studio.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/song-moments.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/agent-song-catalog-listening.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/player-playback-lifecycle.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/app.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/account-voice-engine.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/speech-engine-conversation.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/build-integrity.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/accessibility.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script defer src="assets/js/pwa-shell.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script defer src="assets/js/pwa-phone-alerts.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
</body>
</html>
