<?php
declare(strict_types=1);

require __DIR__.'/api/bootstrap.php';
require __DIR__.'/api/pwa-shell.php';
$host=($_GET['host']??'')==='1';
$viewer=($host||sf_auth_cookie_value()!=='')?sf_current_user():null;
if($host&&!$viewer){
    header('Location: stonefellow-v120.php?view=login');
    exit;
}
$csrf=sf_user_csrf();
session_write_close();
$profileName=trim((string)($viewer['display_name']??''));
if($profileName==='')$profileName=$viewer?'My account':'Guest';
$venueAdmin=$host&&($viewer['role']??'')==='admin';
$sessionTabs=$host?['overview'=>'Overview','phones'=>'Phones','music'=>'Music','chat'=>'Chat','history'=>'History','settings'=>'Settings']
    :['overview'=>'Overview','music'=>'Music','chat'=>'Chat'];
$sessionId=$_GET['id']??'';
$hostDirectory=$host&&$sessionId==='';
$signInHref='./?view=login';
if(is_string($sessionId)&&preg_match('/^[a-f0-9]{32}$/D',$sessionId))$signInHref.='#return_jukebox='.$sessionId;
$pwaSplash=sf_pwa_splash_published();
$waitingSplash=$pwaSplash['waiting']??[];
$showWaiting=!$host&&in_array($waitingSplash['mode']??'', ['image','code'], true);
$waitingFrame=$showWaiting&&$waitingSplash['mode']==='code'?sf_pwa_splash_surface_url('waiting',(int)$pwaSplash['revision']):'';
$framePolicy=sf_pwa_shell_frame_policy(sf_public_base_url(),$waitingFrame);
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
header('Content-Security-Policy: '.$framePolicy);
$renderSessionShare=static function(bool $host):void {
?>
<div id="session-share">
<?php if($host): ?>
  <p id="session-share-description" hidden></p>
<?php else: ?>
  <p id="session-share-description" class="jukebox-muted">Scan with your camera to open this session on another device.</p>
<?php endif; ?>
  <div id="qr"></div>
  <div class="actions">
<?php if($host): ?>
    <a id="join-link">Open guest join page</a>
<?php endif; ?>
    <button type="button" id="copy-session-link">Copy session link</button>
  </div>
  <p id="copy-session-status" role="status"></p>
  <label id="manual-session-copy" hidden>Guest session link <input id="session-link-value" type="url" readonly></label>
</div>
<?php
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="sf-csrf" content="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>">
<?php sf_pwa_shell_head($pwaSplash,(string)(sf_site_settings()['media_app_icon']??'')); ?>
<title><?=$hostDirectory?sf_pwa_shell_attr($profileName).' · Stonefellow':'Stonefellow Jukebox'?></title>
<link rel="stylesheet" href="assets/css/jukebox.css?v=17">
<link rel="stylesheet" href="assets/css/pwa-shell.css?v=1">
<script defer src="assets/js/pwa-shell.js?v=1"></script>
<?php if(!$host): ?><script defer src="assets/js/pwa-phone-alerts.js?v=1"></script><?php endif; ?>
<script defer src="assets/js/jukebox-qr.js?v=1"></script>
<script defer src="assets/js/jukebox-clock.js?v=1"></script>
<script defer src="assets/js/jukebox-listener.js?v=5"></script>
<script defer src="assets/js/jukebox.js?v=19"></script>
</head>
<body class="<?=$host?'jukebox-host':'jukebox-guest'?>">
<header class="jukebox-header">
  <div class="jukebox-header-inner">
    <a class="jukebox-brand" href="./">
      <span class="jukebox-record" aria-hidden="true"></span>
      <span>Stonefellow</span>
    </a>
    <nav class="jukebox-navigation" aria-label="Jukebox navigation">
      <a id="mode-link" href="<?=$host?'jukebox.php':'jukebox.php?host=1'?>"><?=$host?'Live Sessions':'Host jukebox'?></a>
      <details id="jukebox-profile" class="jukebox-profile">
        <summary aria-label="Profile menu for <?=sf_pwa_shell_attr($profileName)?>">
          <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="8" r="3.1"/><path d="M5.5 20v-1.2a6.5 5.5 0 0 1 13 0V20"/></svg>
          <span class="jukebox-profile-name"><?=sf_pwa_shell_attr($profileName)?></span>
          <svg viewBox="0 0 12 12" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m2.5 4 3.5 3.5L9.5 4"/></svg>
        </summary>
        <div class="jukebox-profile-menu">
<?php if($viewer): ?>
          <div class="jukebox-profile-identity">
            <span>Signed in</span>
            <strong><?=sf_pwa_shell_attr($profileName)?></strong>
          </div>
          <a href="./?view=account">My account</a>
          <a href="./?view=library">My Library</a>
<?php if(($viewer['role']??'')==='admin'): ?>
          <a href="admin/">Admin dashboard</a>
<?php endif; ?>
          <a href="logout.php">Sign out</a>
<?php else: ?>
          <a href="<?=sf_pwa_shell_attr($signInHref)?>">Sign in</a>
          <a href="./?view=register">Create account</a>
<?php endif; ?>
        </div>
      </details>
    </nav>
  </div>
</header>
<?php if($hostDirectory): ?>
<div id="host-cover-banner" class="jukebox-host-cover">
  <img id="host-cover" alt="Hosted events cover image" decoding="async" hidden>
  <button type="button" id="open-cover" class="jukebox-cover-edit" aria-haspopup="dialog" hidden>
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h4l2-3h4l2 3h4a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"/><circle cx="12" cy="13" r="3"/></svg>
    <span id="cover-edit-label">Add cover image</span>
  </button>
</div>
<?php endif; ?>
<main>
  <div class="jukebox-page-head">
    <div class="jukebox-page-identity">
      <div id="host-logo-frame" class="jukebox-host-logo"<?=$host||$sessionId!==''?'':' hidden'?>>
        <img id="host-logo" alt="Host logo" width="80" height="80" hidden>
        <span id="host-logo-fallback" class="jukebox-record" aria-hidden="true"></span>
<?php if($hostDirectory): ?>
        <button type="button" id="open-branding" class="jukebox-avatar-edit" aria-haspopup="dialog" aria-label="Upload profile picture" title="Upload profile picture" hidden>
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h4l2-3h4l2 3h4a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"/><circle cx="12" cy="13" r="3"/></svg>
        </button>
<?php endif; ?>
      </div>
      <div>
      <p class="jukebox-kicker"><?=$host?'Session management':'Stonefellow Jukebox'?></p>
      <h1 id="title"><?=$hostDirectory?sf_pwa_shell_attr($profileName):($host?'Jukebox':'Live Sessions')?></h1>
<?php if($hostDirectory): ?>
      <div id="host-venue-details" class="jukebox-host-username jukebox-muted" hidden>
        <span id="host-username"><?=sf_pwa_shell_attr($profileName)?></span>
        <button id="open-venue-name" type="button" aria-haspopup="dialog">Edit venue name</button>
      </div>
<?php endif; ?>
      <p id="session-intro" class="jukebox-muted" hidden>Open a session to see what’s playing or listen from your phone when enabled.</p>
      </div>
    </div>
<?php if(!$host): ?>
    <div class="jukebox-page-actions">
      <button type="button" data-pwa-install hidden>Install Stonefellow</button>
    </div>
<?php endif; ?>
  </div>
  <p id="status" class="jukebox-status" role="status"></p>
  <section id="session-connection" class="jukebox-connection" aria-label="Session connection" hidden>
    <div class="jukebox-connection-copy">
      <span id="connection-badge" class="jukebox-connection-badge" data-connection="connecting">Connecting</span>
      <p id="connection-detail" role="status" tabindex="-1">Checking the session connection…</p>
    </div>
    <button type="button" id="connection-retry" hidden>Reconnect</button>
  </section>
  <div id="directory" class="jukebox-directory<?=$host?'':' jukebox-directory-guest'?>" hidden>
<?php if($host): ?>
    <div class="jukebox-directory-toolbar">
      <nav id="directory-tabs" class="jukebox-directory-tabs" role="tablist" aria-label="Your sessions">
        <button type="button" id="directory-tab-active" role="tab" data-directory-scope="active" aria-controls="directory-results" aria-selected="true">Active</button>
        <button type="button" id="directory-tab-archive" role="tab" data-directory-scope="archive" aria-controls="directory-results" aria-selected="false" tabindex="-1">Archive</button>
      </nav>
      <div class="jukebox-directory-actions">
<?php if($venueAdmin): ?>
        <button id="open-venue" type="button" aria-haspopup="dialog" hidden>+ Account</button>
<?php endif; ?>
        <button id="open-create" class="jukebox-primary" type="button" aria-haspopup="dialog" hidden>+ Session</button>
      </div>
    </div>
<?php endif; ?>
    <section id="directory-results" class="jukebox-panel" <?=$host?'role="tabpanel" aria-labelledby="directory-tab-active" tabindex="0"':'aria-labelledby="session-heading"'?>>
      <h2 id="session-heading"><?=$host?'Your sessions':'Live'?></h2>
<?php if($host): ?>
      <p id="directory-description" class="jukebox-muted jukebox-directory-description">Open and scheduled sessions. Completed sessions move to Archive.</p>
<?php endif; ?>
      <ul id="sessions" class="jukebox-session-list"></ul>
      <p id="session-empty" class="jukebox-empty" hidden>No sessions yet.</p>
<?php if($host): ?>
      <div class="jukebox-directory-pagination">
        <p id="directory-status" class="jukebox-muted" role="status"></p>
        <button type="button" id="directory-more" hidden>Load more</button>
      </div>
<?php endif; ?>
    </section>
<?php if($host&&!$hostDirectory): ?>
    <section id="host-branding" class="jukebox-panel jukebox-branding-settings" aria-labelledby="host-branding-heading" hidden>
      <div class="jukebox-branding-summary">
        <div class="jukebox-branding-image">
          <img id="branding-current" alt="Current host logo" width="64" height="64" hidden>
          <span id="branding-current-fallback" class="jukebox-record" aria-hidden="true"></span>
        </div>
        <div><h2 id="host-branding-heading">Host logo / icon</h2><p class="jukebox-muted">Give your sessions a recognizable identity. Your public logo appears on all sessions you host.</p></div>
        <button type="button" id="open-branding" aria-haspopup="dialog">Upload logo</button>
      </div>
    </section>
<?php endif; ?>
    <section id="upcoming-directory" class="jukebox-panel" aria-labelledby="upcoming-directory-heading" hidden>
      <h2 id="upcoming-directory-heading">Upcoming</h2>
      <ul id="upcoming-sessions" class="jukebox-session-list"></ul>
      <p id="upcoming-empty" class="jukebox-empty" hidden>No upcoming public sessions.</p>
    </section>
    <section id="following-directory" class="jukebox-panel" aria-labelledby="following-directory-heading" hidden>
      <h2 id="following-directory-heading">Following</h2>
      <ul id="following-sessions" class="jukebox-session-list"></ul>
    </section>
  </div>
<?php if($host): ?>
  <dialog id="branding-dialog" class="jukebox-dialog" aria-labelledby="branding-heading" aria-describedby="branding-description">
    <div class="jukebox-dialog-head"><h2 id="branding-heading">Host logo / icon</h2><button type="button" class="jukebox-dialog-close" data-branding-close aria-label="Close host logo">×</button></div>
    <form id="branding">
      <p id="branding-description" class="jukebox-muted">This is a public logo, visible on your host page and every session you host.</p>
      <div class="jukebox-branding-preview">
        <img id="branding-preview" alt="Host logo preview" width="160" height="160" hidden>
        <span id="branding-preview-fallback" class="jukebox-record" aria-hidden="true"></span>
      </div>
      <label><span id="branding-file-label">Choose a logo or icon</span> <input type="file" id="branding-file" name="image" accept="image/png,image/jpeg,image/webp" aria-describedby="branding-file-help" autofocus></label>
      <small id="branding-file-help" class="jukebox-muted">PNG, JPEG, or WebP. Maximum 1 MB. Square artwork works well and fits without cropping.</small>
      <p id="branding-status" class="jukebox-form-status" role="status" tabindex="-1"></p>
      <button type="button" id="branding-reload" hidden>Load latest logo</button>
      <div class="jukebox-dialog-actions">
        <button type="button" data-branding-close>Cancel</button>
        <button type="button" id="branding-remove" disabled>Remove logo</button>
        <button type="submit" id="branding-save" class="jukebox-primary" disabled>Save logo</button>
      </div>
    </form>
  </dialog>
  <dialog id="create-dialog" class="jukebox-dialog" aria-labelledby="create-heading">
    <div class="jukebox-dialog-head"><h2 id="create-heading">Create session</h2><button type="button" class="jukebox-dialog-close" data-jukebox-dialog-close aria-label="Close create session">×</button></div>
    <form id="create">
      <p id="create-source" class="jukebox-muted" hidden></p>
      <label>Session name <input name="title" maxlength="120" required autofocus></label>
      <label id="listing" class="jukebox-checkbox"<?=$venueAdmin?'':' hidden'?>><input type="checkbox" name="listed"> Public universal session</label>
      <label class="jukebox-checkbox"><input type="checkbox" name="remote_listening" aria-describedby="create-remote-help"> Allow remote listening</label>
      <small id="create-remote-help" class="remote-help">Anyone can listen to a public session. Private sessions are available to approved phones.</small>
      <label class="jukebox-checkbox"><input type="checkbox" name="auto_approve_phones" aria-describedby="create-auto-approve-help"> Auto-approve phone connections</label>
      <small id="create-auto-approve-help" class="remote-help">New phones can join without waiting for host approval.</small>
      <label>Scheduled date and time (optional) <input type="datetime-local" name="scheduled_at" aria-describedby="create-schedule-help"></label>
      <small id="create-schedule-help" class="remote-help">Use your local time<span data-schedule-zone></span>. Leave blank to open the session now.</small>
      <p id="create-status" class="jukebox-form-status" role="status"></p>
      <div class="jukebox-dialog-actions">
        <button type="button" data-jukebox-dialog-close>Cancel</button>
        <button type="submit" class="jukebox-primary">Create session</button>
      </div>
    </form>
  </dialog>
  <dialog id="edit-dialog" class="jukebox-dialog" aria-labelledby="edit-heading" aria-describedby="edit-description">
    <div class="jukebox-dialog-head"><h2 id="edit-heading">Edit session</h2><button type="button" class="jukebox-dialog-close" data-jukebox-dialog-close aria-label="Close edit session">×</button></div>
    <form id="edit">
      <label>Session name <input name="title" maxlength="120" required autofocus></label>
      <div id="edit-options" class="jukebox-edit-options">
        <label id="edit-listing" class="jukebox-checkbox"<?=$venueAdmin?'':' hidden'?>><input type="checkbox" name="listed"> Public universal session</label>
        <label class="jukebox-checkbox"><input type="checkbox" name="remote_listening" aria-describedby="edit-remote-help"> Allow remote listening</label>
        <small id="edit-remote-help" class="remote-help">Anyone can listen to a public session. Private sessions are available to approved phones.</small>
        <label class="jukebox-checkbox"><input type="checkbox" name="auto_approve_phones" aria-describedby="edit-auto-approve-help"> Auto-approve phone connections</label>
        <small id="edit-auto-approve-help" class="remote-help">New phones can join without waiting for host approval.</small>
        <label id="edit-schedule">Scheduled date and time <input type="datetime-local" name="scheduled_at" aria-describedby="edit-schedule-help"></label>
        <small id="edit-schedule-help" class="remote-help">Use your local time<span data-schedule-zone></span>.</small>
      </div>
      <p id="edit-description" class="jukebox-muted"></p>
      <p id="edit-status" class="jukebox-form-status" role="status"></p>
      <button type="button" id="edit-reload" hidden>Load latest settings</button>
      <div class="jukebox-dialog-actions">
        <button type="button" data-jukebox-dialog-close>Cancel</button>
        <button type="submit" class="jukebox-primary">Save changes</button>
      </div>
    </form>
  </dialog>
<?php endif; ?>
<?php if($venueAdmin): ?>
  <dialog id="venue-dialog" class="jukebox-dialog" aria-labelledby="venue-heading">
    <div class="jukebox-dialog-head"><h2 id="venue-heading">Enable a venue account</h2><button type="button" class="jukebox-dialog-close" data-jukebox-dialog-close aria-label="Close venue setup">×</button></div>
    <form id="venue">
      <label>Account
        <select id="venue-owner" name="owner" required>
          <option value="" disabled hidden>Choose an account</option>
          <option value="<?=(int)$viewer['id']?>" selected>My account (<?=sf_pwa_shell_attr($profileName)?>)</option>
        </select>
      </label>
      <details id="venue-lookup" class="jukebox-venue-lookup">
        <summary>Find another account</summary>
        <div class="jukebox-venue-search">
          <label>Name or email <input type="search" id="venue-search" maxlength="120" autocomplete="off"></label>
          <button type="button" id="venue-search-button">Search</button>
        </div>
        <p id="venue-search-status" class="jukebox-form-status" role="status"></p>
      </details>
      <label>Venue name <input name="name" maxlength="120" required autofocus></label>
      <p id="venue-status" class="jukebox-form-status" role="status"></p>
      <div class="jukebox-dialog-actions">
        <button type="button" data-jukebox-dialog-close>Cancel</button>
        <button type="submit" class="jukebox-primary">Save venue</button>
      </div>
    </form>
  </dialog>
<?php endif; ?>
<?php if($hostDirectory): ?>
  <dialog id="venue-name-dialog" class="jukebox-dialog" aria-labelledby="venue-name-heading">
    <div class="jukebox-dialog-head"><h2 id="venue-name-heading">Edit venue name</h2><button type="button" class="jukebox-dialog-close" data-jukebox-dialog-close aria-label="Close venue name editor">×</button></div>
    <form id="venue-name">
      <label>Venue name <input name="name" maxlength="120" required autofocus></label>
      <p id="venue-name-status" class="jukebox-form-status" role="status"></p>
      <div class="jukebox-dialog-actions">
        <button type="button" data-jukebox-dialog-close>Cancel</button>
        <button type="submit" class="jukebox-primary">Save name</button>
      </div>
    </form>
  </dialog>
<?php endif; ?>
<section id="upcoming" class="jukebox-panel" aria-labelledby="upcoming-heading" hidden>
<h2 id="upcoming-heading">Waiting for the session to start</h2>
<p id="scheduled-start-line">Scheduled for <time id="scheduled-start"></time></p>
<p id="upcoming-message" role="status"></p>
<?php if($showWaiting): ?>
<div data-pwa-waiting hidden>
<?php if($waitingSplash['title']!==''): ?><h3><?=sf_pwa_shell_attr($waitingSplash['title'])?></h3><?php endif; ?>
<?php if($waitingSplash['message']!==''): ?><p><?=sf_pwa_shell_attr($waitingSplash['message'])?></p><?php endif; ?>
<?php sf_pwa_shell_art($waitingSplash,$waitingFrame); ?>
</div>
<?php endif; ?>
<?php if($host): ?>
<div class="actions jukebox-session-management-actions">
<button type="button" id="upcoming-edit-session" aria-haspopup="dialog">Edit session</button>
<button type="button" id="upcoming-reuse-session" aria-haspopup="dialog">Use settings</button>
</div>
<div id="schedule-controls">
<div id="upcoming-share"></div>
<form id="reschedule">
<label>New date and time <input type="datetime-local" name="scheduled_at" aria-describedby="reschedule-help" required></label>
<small id="reschedule-help" class="remote-help">Use your local time<span data-schedule-zone></span>.</small>
<button>Save new time</button>
</form>
<div class="actions">
<button type="button" id="start-session">Start session</button>
<button type="button" id="cancel-session">Cancel session</button>
</div>
</div>
<?php endif; ?>
</section>
<?php if(!$host): ?>
<div class="sf-pwa-alert-controls jukebox-panel" data-pwa-session-follow hidden>
<button type="button" data-pwa-follow-toggle>Follow this session</button>
<p role="status" data-pwa-follow-status></p>
<a href="./?view=login" data-pwa-follow-signin hidden>Sign in to follow</a>
</div>
<div class="sf-pwa-alert-controls jukebox-panel" data-pwa-phone-alerts hidden>
<button type="button" data-pwa-alert-toggle>Enable phone alerts</button>
<p role="status" data-pwa-alert-status></p>
<a href="./?view=login" data-pwa-alert-signin hidden>Sign in for phone alerts</a>
</div>
<?php endif; ?>
<?php if($host): ?><div id="host" class="jukebox-host-view" hidden><?php endif; ?>
<nav id="session-tabs" class="jukebox-session-tabs" role="tablist" aria-label="Session sections" hidden>
<?php foreach($sessionTabs as $tab=>$label): ?>
  <button type="button" id="session-tab-<?=$tab?>" role="tab" data-session-tab="<?=$tab?>"
    aria-controls="session-panel-<?=$tab?>" aria-selected="<?=$tab==='overview'?'true':'false'?>" tabindex="<?=$tab==='overview'?'0':'-1'?>">
    <?=$label?><?php if($tab==='chat'): ?><span id="chat-unread" class="jukebox-tab-badge" hidden></span><?php endif; ?>
  </button>
<?php endforeach; ?>
</nav>
<p id="chat-notice" class="jukebox-sr-only" role="status"></p>
<div id="session-panel-overview" class="jukebox-session-panel" role="tabpanel" aria-labelledby="session-tab-overview" tabindex="0" hidden>
  <div id="session-overview-details" class="jukebox-overview-details" hidden></div>
<?php if(!$host): ?>
  <div class="jukebox-guest-join">
<?php endif; ?>
  <section id="pairing" class="jukebox-panel" aria-labelledby="pairing-heading" hidden>
    <h2 id="pairing-heading">Phone access</h2>
    <form id="join">
      <label>Display name (optional) <input name="name" maxlength="80" autocomplete="nickname"></label>
      <label id="remember" hidden><input type="checkbox" name="remember"> Remember me at this venue</label>
      <button>Request access</button>
    </form>
    <p id="approval" role="status"></p>
    <button type="button" id="forget" hidden>Forget me at this venue</button>
  </section>
<?php if(!$host): ?>
    <section id="guest-share" class="jukebox-panel" aria-labelledby="share-heading" hidden>
      <h2 id="share-heading">Join on another device</h2>
      <?php $renderSessionShare(false); ?>
    </section>
  </div>
<?php endif; ?>
<?php if($host): ?>
  <section class="jukebox-panel jukebox-host-overview" aria-labelledby="host-now-heading">
    <div>
      <h2 id="host-now-heading" class="jukebox-kicker">Now Playing</h2>
      <p id="host-now" class="jukebox-host-track">Jukebox idle</p>
      <p id="host-progress" class="jukebox-muted"></p>
    </div>
    <button type="button" class="jukebox-host-jump" data-session-open="phones">Phone approvals <span aria-hidden="true">→</span></button>
  </section>
<?php endif; ?>
  <section class="jukebox-panel jukebox-session-queue" aria-labelledby="queue-label">
    <h2 id="queue-label">Up Next</h2>
    <ol id="queue"></ol>
    <p id="queue-empty" class="jukebox-empty">No songs queued yet.</p>
  </section>
</div>
<?php if($host): ?>
<div id="session-panel-phones" class="jukebox-session-panel" role="tabpanel" aria-labelledby="session-tab-phones" tabindex="0" hidden>
  <div class="host-grid">
    <section id="host-phones" class="jukebox-panel jukebox-host-phones" aria-labelledby="phones-heading">
      <h2 id="phones-heading">Phone connections</h2>
      <p id="remote-listener-count" role="status" hidden></p>
      <p id="phone-approval-mode" class="jukebox-muted"></p>
      <div class="actions jukebox-session-management-actions">
        <button type="button" id="disconnect-all-phones" disabled>Disconnect all</button>
        <p id="phone-disconnect-status" class="jukebox-form-status" role="status"></p>
      </div>
      <ul id="phones"></ul>
      <p class="jukebox-empty jukebox-phones-empty">No phones have requested access yet.</p>
    </section>
    <section class="jukebox-panel" aria-labelledby="share-heading">
      <h2 id="share-heading">Share this session</h2>
      <div id="active-share"><?php $renderSessionShare(true); ?></div>
    </section>
  </div>
</div>
<?php endif; ?>
<div id="session-panel-music" class="jukebox-session-panel" role="tabpanel" aria-labelledby="session-tab-music" tabindex="0" hidden>
  <section id="music" class="jukebox-panel" hidden>
    <nav aria-label="Catalog views" id="tabs"><button type="button" data-tab="feed">Feed</button><button type="button" data-tab="songs">Songs</button><button type="button" data-tab="albums">Albums</button></nav>
    <div id="catalog-context" class="jukebox-catalog-context" hidden></div>
    <ul id="catalog" class="jukebox-catalog-list" aria-label="Music catalog"></ul>
    <p id="catalog-empty" class="jukebox-empty" hidden>No music is available in this view.</p>
  </section>
  <p class="jukebox-panel jukebox-music-placeholder">Songs will appear here when they’re available for this session.</p>
</div>
<div id="session-panel-chat" class="jukebox-session-panel" role="tabpanel" aria-labelledby="session-tab-chat" tabindex="0" hidden>
  <div class="jukebox-chat-layout<?=$host?' jukebox-chat-host':''?>">
<?php if($host): ?>
    <section class="jukebox-panel" aria-labelledby="chat-threads-heading">
      <h2 id="chat-threads-heading">Phone conversations</h2>
      <ul id="chat-threads" class="jukebox-chat-threads"></ul>
      <p class="jukebox-empty jukebox-chat-threads-empty">Approved phones will appear here.</p>
    </section>
<?php endif; ?>
    <div class="jukebox-chat-content">
      <p id="chat-empty" class="jukebox-panel"><?=$host?'Choose a phone conversation to start chatting.':'Request access in Overview to chat with the host.'?></p>
      <section id="chat" class="jukebox-panel" hidden>
        <h2 id="chat-title" tabindex="-1">Private messages</h2>
        <p id="chat-status" class="jukebox-form-status" role="status" hidden></p>
        <ul id="messages" tabindex="0" aria-label="Message history"></ul>
        <form id="send">
          <label>Message <input name="text" maxlength="1000"></label>
          <label>Image (optional) <input name="image" type="file" accept="image/jpeg,image/png,image/webp"></label>
          <button>Send</button>
        </form>
      </section>
    </div>
  </div>
</div>
<?php if($host): ?>
<div id="session-panel-history" class="jukebox-session-panel" role="tabpanel" aria-labelledby="session-tab-history" tabindex="0" hidden>
  <section class="jukebox-panel jukebox-host-history" aria-labelledby="history-heading">
    <h2 id="history-heading">Play history</h2>
    <ul id="history"></ul>
    <p class="jukebox-empty jukebox-history-empty">Played songs will appear here.</p>
  </section>
  <details id="crm" class="jukebox-panel" hidden><summary>Venue guests and visit history</summary><ul id="guests"></ul></details>
</div>
<div id="session-panel-settings" class="jukebox-session-panel" role="tabpanel" aria-labelledby="session-tab-settings" tabindex="0" hidden>
  <div id="branding-settings-slot"></div>
  <section class="jukebox-panel jukebox-host-settings" aria-labelledby="host-settings-heading">
    <h2 id="host-settings-heading">Session settings</h2>
    <p class="jukebox-muted">Update the name and settings, or use them to create a new session.</p>
    <div class="actions jukebox-session-management-actions">
      <button type="button" id="edit-session" aria-haspopup="dialog">Edit session</button>
      <button type="button" id="reuse-session" aria-haspopup="dialog">Use settings</button>
    </div>
    <label class="remote-setting"><input id="remote-listening" type="checkbox" aria-describedby="remote-help"> Allow remote listening</label>
    <small id="remote-help" class="remote-help">Anyone can listen to a public session. Private sessions are available to approved phones.</small>
    <button type="button" id="end-session">End session</button>
  </section>
</div>
</div>
<?php else: ?>
<details id="crm" hidden><summary>Venue guests and visit history</summary><ul id="guests"></ul></details>
<?php endif; ?>
<section id="recap" class="jukebox-panel" hidden aria-labelledby="recap-heading">
<h2 id="recap-heading">Session recap</h2>
<?php if($host): ?>
<div class="actions jukebox-session-management-actions">
<button type="button" id="recap-edit-session" aria-haspopup="dialog">Rename session</button>
<button type="button" id="recap-reuse-session" aria-haspopup="dialog">Use settings</button>
</div>
<?php endif; ?>
<p id="recap-status" role="status"></p>
<button type="button" id="recap-retry" hidden>Retry recap</button>
<div id="recap-content" hidden>
<p id="recap-start"></p>
<dl id="recap-totals" class="recap-metrics"></dl>
<p>Confirmed plays count songs that started playing. A song can count as both a confirmed play and a skip.</p>
<section id="recap-listeners" hidden>
<h3>Phone listening</h3>
<dl id="recap-listener-totals" class="recap-metrics"></dl>
<p>These totals reflect recorded phone playback. Phone approvals and host playback appear in the session totals above.</p>
</section>
<p id="recap-empty" hidden>No songs were requested.</p>
<table id="recap-tracks" hidden>
<caption>Song requests and playback</caption>
<thead><tr><th scope="col">Song</th><th scope="col">Requests</th><th scope="col">Confirmed plays</th><th scope="col">Outcomes</th></tr></thead>
<tbody></tbody>
</table>
<section id="recap-history" hidden>
<h3>Recent play history</h3><ul id="recap-plays"></ul>
</section>
</div>
</section>
<div id="ended-session-tools" class="jukebox-session-panel" hidden></div>
<?php if($host): ?><div id="branding-session-tools" class="jukebox-session-panel jukebox-branding-session-tools" hidden></div><?php endif; ?>
</main>
<?php if($host): ?>
<footer id="footer" class="jukebox-host-player" aria-label="Session audio player" hidden>
  <div class="jukebox-footer-inner jukebox-host-player-inner">
    <audio id="audio" preload="none"></audio>
    <div class="jukebox-host-player-track">
      <span class="jukebox-record" aria-hidden="true"></span>
      <div>
        <strong id="now">Jukebox idle</strong>
        <small id="progress"></small>
      </div>
    </div>
    <div class="jukebox-host-player-actions">
      <button type="button" id="enable" class="jukebox-primary">Enable player on this device</button>
      <button type="button" id="audio-start" class="jukebox-primary" hidden>Start audio</button>
      <div class="actions" id="controls" hidden>
        <button type="button" id="player-toggle" hidden>Pause</button>
        <button type="button" id="player-skip" hidden>Skip</button>
      </div>
    </div>
    <label class="jukebox-host-volume">Volume <input id="volume" type="range" min="0" max="1" step="0.05" value="1"></label>
    <p id="player-status" role="status"></p>
  </div>
</footer>
<?php else: ?>
<footer id="footer" class="jukebox-guest-player" aria-label="Phone audio player" hidden>
  <div class="jukebox-footer-inner jukebox-guest-player-inner">
    <div class="jukebox-player-track">
      <span class="jukebox-record" aria-hidden="true"></span>
      <div><strong id="now">Jukebox idle</strong><small id="progress"></small></div>
    </div>
    <div id="listener" hidden>
      <audio id="listener-audio" preload="none"></audio>
      <div class="actions jukebox-listener-actions">
        <button type="button" id="listen" class="jukebox-primary">Stream on this phone</button>
        <button type="button" id="listener-resync" hidden>Resync</button>
        <button type="button" id="listener-mute" aria-pressed="false" hidden>Mute phone</button>
        <button type="button" id="listener-stop" hidden>Stop listening</button>
      </div>
      <span id="listener-connection" class="jukebox-connection-badge" data-connection="idle">Ready</span>
      <small id="listener-status" role="status"></small>
    </div>
  </div>
</footer>
<?php endif; ?>
</body>
</html>
