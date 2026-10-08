<?php
declare(strict_types=1);
define('SF_ROOT', __DIR__);
define('SF_BUILD', '1.3.4');
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
$siteSettings=sf_site_settings();
function sf_h(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
?>
<?php if($siteSettings['maintenance_enabled'] && (($currentUser['role']??'')!=='admin')): ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Stonefellow — Maintenance</title><style>body{margin:0;background:#090807;color:#f2eee8;font:16px/1.6 system-ui,sans-serif}.wrap{width:min(680px,calc(100% - 40px));margin:18vh auto}.brand{font-weight:800;letter-spacing:.28em;font-size:.75rem}h1{font:400 clamp(2.5rem,8vw,5rem)/1 Georgia,serif;margin:42px 0 18px}p{color:#aaa;max-width:560px}</style></head><body><main class="wrap"><div class="brand">STONEFELLOW</div><h1>We’ll be right back.</h1><p><?=sf_h($siteSettings['maintenance_message'])?></p></main></body></html>
<?php exit; endif; ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#090807">
  <meta name="description" content="<?=sf_h($siteSettings['seo_description'])?>">
  <meta name="stonefellow-build" content="<?=sf_h(SF_BUILD)?>">
  <title><?=sf_h($siteSettings['seo_title'])?></title>
  <link rel="stylesheet" href="assets/css/site.css?v=<?=rawurlencode(SF_BUILD)?>">
</head>
<body class="<?=($siteSettings['announcement_enabled'] && $siteSettings['announcement_text']!=='')?'has-announcement':''?>">
<?php if($siteSettings['announcement_enabled'] && $siteSettings['announcement_text']!==''): ?><div class="site-announcement"><?=sf_h($siteSettings['announcement_text'])?></div><?php endif; ?>
<?php if($siteSettings['splash_enabled']): ?>
  <section id="splashScreen" class="splash-screen" data-revision="<?=sf_h((string)$siteSettings['splash_revision'])?>" aria-label="Stonefellow welcome">
    <div class="splash-inner">
      <div class="splash-wordmark">STONEFELLOW</div>
      <div class="splash-orb" aria-hidden="true"><span></span></div>
      <p>Listen. Explore the catalog. Make something of your own.</p>
      <button id="splashEnter" type="button">Enter</button>
    </div>
  </section>
<?php endif; ?>
  <header class="topbar">
    <a class="wordmark" href="?view=home" id="homeLink" aria-label="Stonefellow home">STONEFELLOW</a>
    <div class="header-actions">
<?php if($currentUser): ?>
      <button id="notificationButton" class="notification-button" type="button" aria-label="Open notifications and history" aria-expanded="false">
        <span class="notification-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg></span><span id="notificationBadge" class="notification-badge" hidden>0</span>
      </button>
<?php endif; ?>
    <details id="menuPanel" class="menu-details">
      <summary id="menuButton" class="menu-button" aria-label="Open menu">•••</summary>
      <div class="menu-sheet">
        <button class="menu-close" id="menuClose" type="button" aria-label="Close menu">×</button>
        <nav>
          <a href="?view=home" data-view="home">Agent</a>
          <a href="?view=music" data-view="music">Music</a>
          <a href="?view=releases" data-view="releases">Releases</a>
          <a href="?view=builder" data-view="builder">Create a record</a>
          <a href="?view=cart" data-view="cart">Cart</a>
          <a href="?view=plans" data-view="plans">Plans</a>
          <a href="?view=about" data-view="about">About</a>
          <a href="?view=privacy" data-view="privacy">Privacy</a>
          <a href="?view=terms" data-view="terms">Terms</a>
<?php if($currentUser): ?>
          <div class="menu-identity">
            <span>Signed in</span><strong><?=sf_h((string)$currentUser['display_name'])?></strong>
          </div>
          <a href="?view=account" data-view="account" id="menuAccount">My account</a>
<?php if(($currentUser['role']??'')==='admin'): ?>
          <a href="admin/" id="menuAdmin">Admin</a>
<?php endif; ?>
          <a href="logout.php" id="menuLogout">Logout</a>
<?php else: ?>
          <a href="?view=login" data-view="login" id="menuLogin">Login</a>
          <a href="?view=register" data-view="register" id="menuRegister">Create account</a>
<?php endif; ?>
          <div class="menu-version">Stonefellow v<?=sf_h(SF_BUILD)?></div>
        </nav>
      </div>
    </details>
    </div>
  </header>


<?php if($currentUser): ?>
  <div id="notificationBackdrop" class="drawer-backdrop" hidden></div>
  <aside id="notificationDrawer" class="notification-drawer" hidden aria-label="Account activity">
    <div class="drawer-head">
      <div><span class="drawer-kicker">STONEFELLOW</span><h2>Activity</h2></div>
      <button id="notificationClose" type="button" aria-label="Close activity drawer">×</button>
    </div>
    <div class="drawer-tabs" role="tablist" aria-label="Activity views">
      <button type="button" class="active" data-drawer-tab="notifications" role="tab">Notifications <span id="drawerUnreadCount"></span></button>
      <button type="button" data-drawer-tab="history" role="tab">History</button>
      <button type="button" data-drawer-tab="brain" role="tab">Agent Brain</button>
    </div>
    <div class="drawer-tools"><button id="markAllNotifications" type="button">Mark all read</button><button id="refreshNotifications" type="button">Refresh</button></div>
    <div id="drawerContent" class="drawer-content"><p class="drawer-empty">Loading…</p></div>
  </aside>
<?php endif; ?>

  <main class="stage">
    <section class="agent-stage" aria-label="Stonefellow Agent">
      <button id="agentOrb" class="agent-orb" type="button" aria-label="Stonefellow Agent">
        <span class="orb-core"></span><span class="orb-ring ring-a"></span><span class="orb-ring ring-b"></span>
      </button>
      <div class="agent-meta"><span id="agentState">IDLE</span></div>
      <p id="agentMessage" class="agent-message" aria-live="polite">Hey. I’m the Stonefellow agent. I can play you something, tell you about the music, or help you make your own record.</p>
      <div id="starterActions" class="starter-actions">
        <button type="button" data-command="play something">Play something</button>
        <button type="button" data-command="make me a record">Make me a record</button>
        <button type="button" data-command="show me the catalog">Look around</button>
      </div>
    </section>
    <section id="dynamicCanvas" class="dynamic-canvas" aria-live="polite"></section>
  </main>

  <footer class="chat-footer">
    <div id="footerPlayer" class="footer-player" hidden aria-label="Music player">
      <button id="playerArtwork" class="player-artwork" type="button" aria-label="View current song"><span>SF</span></button>
      <div class="player-track-meta">
        <button id="playerTitle" class="player-title" type="button">Nothing playing</button>
        <span id="playerRelease" class="player-release">Stonefellow</span>
      </div>
      <button id="playerPrev" class="player-icon" type="button" aria-label="Previous song">‹</button>
      <button id="playerToggle" class="player-toggle" type="button" aria-label="Play">▶</button>
      <button id="playerNext" class="player-icon" type="button" aria-label="Next song">›</button>
      <div class="player-timeline">
        <span id="playerCurrent">0:00</span>
        <input id="playerSeek" type="range" min="0" max="1000" value="0" aria-label="Song position">
        <span id="playerDuration">0:00</span>
      </div>
      <button id="playerClose" class="player-close" type="button" aria-label="Close player">×</button>
      <audio id="persistentAudio" preload="metadata"></audio>
    </div>
    <form id="chatForm" class="chatbar" autocomplete="off">
      <label class="sr-only" for="chatInput">Ask Stonefellow anything</label>
      <input id="chatInput" type="text" placeholder="Ask Stonefellow anything…" maxlength="320">
      <button id="voiceButton" class="chat-action mic" type="button" aria-label="Start conversation" aria-pressed="false">◉</button>
      <button class="chat-action send" type="submit" aria-label="Send message">↑</button>
    </form>
    <div class="social-links" aria-label="Stonefellow social links">
      <a href="#" data-social="instagram">Instagram</a>
      <a href="#" data-social="youtube">YouTube</a>
      <a href="#" data-social="bandcamp">Bandcamp</a>
      <a href="#" data-social="spotify">Spotify</a>
    </div>
  </footer>

  <script>window.STONEFELLOW_BUILD=<?=json_encode(SF_BUILD)?>;window.STONEFELLOW_AUTH_BOOTSTRAP=<?=json_encode(['authenticated'=>(bool)$currentUser,'user'=>sf_user_public($currentUser)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;window.STONEFELLOW_SITE=<?=json_encode(['socials'=>['instagram'=>$siteSettings['social_instagram'],'youtube'=>$siteSettings['social_youtube'],'bandcamp'=>$siteSettings['social_bandcamp'],'spotify'=>$siteSettings['social_spotify']],'privacy'=>$siteSettings['privacy_text'],'terms'=>$siteSettings['terms_text']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;</script>
  <script src="assets/js/config.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script>if(window.STONEFELLOW_CONFIG){window.STONEFELLOW_CONFIG.socials=window.STONEFELLOW_CONFIG.socials||{};for(const [k,v] of Object.entries(window.STONEFELLOW_SITE.socials||{})){if(v)window.STONEFELLOW_CONFIG.socials[k]=v;}}</script>
  <script src="assets/js/catalog.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/releases.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
  <script src="assets/js/app.js?v=<?=rawurlencode(SF_BUILD)?>"></script>
</body>
</html>
