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
$profileName=trim((string)($viewer['display_name']??''))?:($viewer?'My account':'Guest');
$venueAdmin=$host&&($viewer['role']??'')==='admin';
$sessionId=$_GET['id']??'';
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="sf-csrf" content="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>">
<?php sf_pwa_shell_head($pwaSplash,(string)(sf_site_settings()['media_app_icon']??'')); ?>
<title>Stonefellow Jukebox</title>
<link rel="stylesheet" href="assets/css/jukebox.css?v=8">
<link rel="stylesheet" href="assets/css/pwa-shell.css?v=1">
<script defer src="assets/js/pwa-shell.js?v=1"></script>
<?php if(!$host): ?><script defer src="assets/js/pwa-phone-alerts.js?v=1"></script><?php endif; ?>
<script defer src="assets/js/jukebox-qr.js?v=1"></script>
<script defer src="assets/js/jukebox-listener.js?v=2"></script>
<script defer src="assets/js/jukebox.js?v=9"></script>
</head>
<body<?=$host?' class="jukebox-host"':''?>>
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
<main>
  <div class="jukebox-page-head">
    <div>
      <p class="jukebox-kicker"><?=$host?'Session management':'Stonefellow Jukebox'?></p>
      <h1 id="title"><?=$host?'Jukebox':'Live Sessions'?></h1>
      <p id="session-intro" class="jukebox-muted" hidden>Open a session to see what’s playing or listen from your phone when enabled.</p>
    </div>
    <div class="jukebox-page-actions">
<?php if($host): ?>
<?php if($venueAdmin): ?>
      <button id="open-venue" type="button" aria-haspopup="dialog" hidden>Enable venue account</button>
<?php endif; ?>
      <button id="open-create" class="jukebox-primary" type="button" aria-haspopup="dialog" hidden>New session</button>
<?php else: ?>
      <button type="button" data-pwa-install hidden>Install Stonefellow</button>
<?php endif; ?>
    </div>
  </div>
  <p id="status" class="jukebox-status" role="status"></p>
  <div id="directory" class="jukebox-directory<?=$host?'':' jukebox-directory-guest'?>" hidden>
    <section class="jukebox-panel" aria-labelledby="session-heading">
      <h2 id="session-heading"><?=$host?'Your sessions':'Live'?></h2>
      <ul id="sessions" class="jukebox-session-list"></ul>
      <p id="session-empty" class="jukebox-empty" hidden>No sessions yet.</p>
    </section>
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
  <dialog id="create-dialog" class="jukebox-dialog" aria-labelledby="create-heading">
    <div class="jukebox-dialog-head"><h2 id="create-heading">Create session</h2><button type="button" class="jukebox-dialog-close" data-jukebox-dialog-close aria-label="Close create session">×</button></div>
    <form id="create">
      <label>Session name <input name="title" maxlength="120" required autofocus></label>
      <label id="listing" class="jukebox-checkbox" hidden><input type="checkbox" name="listed"> Public universal session</label>
      <label class="jukebox-checkbox"><input type="checkbox" name="remote_listening" aria-describedby="create-remote-help"> Allow remote listening</label>
      <small id="create-remote-help" class="remote-help">Anyone can listen to a public session. Private sessions are available to approved phones.</small>
      <label>Scheduled date and time (optional) <input type="datetime-local" name="scheduled_at" aria-describedby="create-schedule-help"></label>
      <small id="create-schedule-help" class="remote-help">Use your local time<span data-schedule-zone></span>. Leave blank to open the session now.</small>
      <p id="create-status" class="jukebox-form-status" role="status"></p>
      <div class="jukebox-dialog-actions">
        <button type="button" data-jukebox-dialog-close>Cancel</button>
        <button type="submit" class="jukebox-primary">Create session</button>
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
<section id="pairing" class="jukebox-panel" hidden>
<form id="join"><label>Display name (optional) <input name="name" maxlength="80" autocomplete="nickname"></label>
<label id="remember" hidden><input type="checkbox" name="remember"> Remember me at this venue</label>
<button>Request access</button></form>
<p id="approval"></p>
<button type="button" id="forget" hidden>Forget me at this venue</button>
</section>
<?php if($host): ?>
<div id="host" class="jukebox-host-view" hidden>
  <section class="jukebox-panel jukebox-host-overview" aria-labelledby="host-now-heading">
    <div>
      <h2 id="host-now-heading" class="jukebox-kicker">Now Playing</h2>
      <p id="host-now" class="jukebox-host-track">Jukebox idle</p>
      <p id="host-progress" class="jukebox-muted"></p>
    </div>
    <a class="jukebox-host-jump" href="#host-phones">Phone approvals <span aria-hidden="true">↓</span></a>
  </section>
  <div class="host-grid">
    <section class="jukebox-panel jukebox-host-queue" aria-labelledby="queue-label">
      <h2 id="queue-label">Up Next</h2>
      <p id="queue-empty" class="jukebox-empty">No songs queued yet.</p>
      <ol id="queue"></ol>
    </section>
    <section id="host-phones" class="jukebox-panel jukebox-host-phones" aria-labelledby="phones-heading">
      <h2 id="phones-heading">Phone connections</h2>
      <p id="remote-listener-count" role="status" hidden></p>
      <ul id="phones"></ul>
      <p class="jukebox-empty jukebox-phones-empty">No phones have requested access yet.</p>
    </section>
  </div>
  <div class="host-grid">
    <section class="jukebox-panel" aria-labelledby="share-heading">
      <h2 id="share-heading">Share this session</h2>
      <div id="active-share"><div id="session-share">
        <p id="session-share-description" hidden></p>
        <div id="qr"></div>
        <div class="actions"><a id="join-link">Open guest join page</a><button type="button" id="copy-session-link">Copy session link</button></div>
        <p id="copy-session-status" role="status"></p>
        <label id="manual-session-copy" hidden>Guest session link <input id="session-link-value" type="url" readonly></label>
      </div></div>
    </section>
    <section class="jukebox-panel jukebox-host-settings" aria-labelledby="host-settings-heading">
      <h2 id="host-settings-heading">Session settings</h2>
      <label class="remote-setting"><input id="remote-listening" type="checkbox" aria-describedby="remote-help"> Allow remote listening</label>
      <small id="remote-help" class="remote-help">Anyone can listen to a public session. Private sessions are available to approved phones.</small>
      <button type="button" id="end-session">End session</button>
    </section>
  </div>
  <section class="jukebox-panel jukebox-host-history" aria-labelledby="history-heading">
    <h2 id="history-heading">Play history</h2>
    <ul id="history"></ul>
    <p class="jukebox-empty jukebox-history-empty">Played songs will appear here.</p>
  </section>
</div>
<?php endif; ?>
<section id="recap" class="jukebox-panel" hidden aria-labelledby="recap-heading">
<h2 id="recap-heading">Session recap</h2>
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
<details id="crm" class="jukebox-panel" hidden><summary>Venue guests and visit history</summary><ul id="guests"></ul></details>
<section id="chat" class="jukebox-panel" hidden>
<h2 id="chat-title" tabindex="-1">Private messages</h2><ul id="messages"></ul>
<form id="send"><label>Message <input name="text" maxlength="1000"></label>
<label>Image (optional) <input name="image" type="file" accept="image/jpeg,image/png,image/webp"></label><button>Send</button></form>
</section>
<section id="music" class="jukebox-panel" hidden>
<nav aria-label="Catalog views" id="tabs"><button data-tab="feed">Feed</button><button data-tab="songs">Songs</button><button data-tab="albums">Albums</button></nav>
<ul id="catalog"></ul>
</section>
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
<footer id="footer" hidden><div class="jukebox-footer-inner"><div><strong id="now">Idle</strong><small id="progress"></small></div>
<div id="listener" hidden>
<audio id="listener-audio" preload="none"></audio>
<div class="actions">
<button type="button" id="listen">Listen on this phone</button>
<button type="button" id="listener-mute" aria-pressed="false" hidden>Mute phone</button>
<button type="button" id="listener-stop" hidden>Stop listening</button>
</div>
<small id="listener-status" role="status"></small>
</div>
<details><summary id="queue-label">Up Next</summary><ol id="queue"></ol></details></div></footer>
<?php endif; ?>
</body>
</html>
