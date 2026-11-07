<?php
declare(strict_types=1);

require __DIR__.'/api/bootstrap.php';
$host=($_GET['host']??'')==='1';
if($host&&!sf_current_user()){
    header('Location: stonefellow-v120.php?view=login');
    exit;
}
$csrf=sf_user_csrf();
session_write_close();
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="sf-csrf" content="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>">
<title>Stonefellow Jukebox</title>
<link rel="stylesheet" href="assets/css/jukebox.css?v=2">
<script defer src="assets/js/jukebox-qr.js?v=1"></script>
<script defer src="assets/js/jukebox.js?v=2"></script>
</head>
<body>
<main>
<header><a href="./">Stonefellow</a> <a id="mode-link" href="jukebox.php?host=1">Host jukebox</a></header>
<h1 id="title">Jukebox</h1>
<p id="status" role="status"></p>
<section id="directory" hidden>
<h2 id="session-heading">Live sessions</h2>
<ul id="sessions"></ul>
<form id="create" hidden><label>Session name <input name="title" maxlength="120" required></label>
<label id="listing" hidden><input type="checkbox" name="listed"> Public universal session</label><button>Create session</button></form>
<form id="venue" hidden><h2>Enable a venue account</h2><label>Account ID <input type="number" name="owner" min="1" required></label>
<label>Venue name <input name="name" maxlength="120" required></label><button>Save venue</button></form>
</section>
<section id="pairing" hidden>
<form id="join"><label>Display name (optional) <input name="name" maxlength="80" autocomplete="nickname"></label>
<label id="remember" hidden><input type="checkbox" name="remember"> Remember me at this venue</label>
<button>Request access</button></form>
<p id="approval"></p>
<button type="button" id="forget" hidden>Forget me at this venue</button>
</section>
<section id="host" hidden>
<h2>Tonight</h2>
<div class="host-grid">
<section><h3>Phone connections</h3><div id="qr"></div><a id="join-link">Open guest join page</a><ul id="phones"></ul></section>
<section><h3>Session player</h3><audio id="audio" preload="none"></audio><button id="enable">Enable player on this device</button>
<label>Player volume <input id="volume" type="range" min="0" max="1" step="0.05" value="1"></label>
<p id="player-status"></p><div id="controls"></div><h3>Play history</h3><ul id="history"></ul></section>
</div>
</section>
<section id="recap" hidden aria-labelledby="recap-heading">
<h2 id="recap-heading">Session recap</h2>
<p id="recap-status" role="status"></p>
<button type="button" id="recap-retry" hidden>Retry recap</button>
<div id="recap-content" hidden>
<p id="recap-start"></p>
<dl id="recap-totals"></dl>
<p>Confirmed plays count songs that started playing. A song can count as both a confirmed play and a skip.</p>
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
<details id="crm" hidden><summary>Venue guests and visit history</summary><ul id="guests"></ul></details>
<section id="chat" hidden>
<h2 id="chat-title">Private messages</h2><ul id="messages"></ul>
<form id="send"><label>Message <input name="text" maxlength="1000"></label>
<label>Image (optional) <input name="image" type="file" accept="image/jpeg,image/png,image/webp"></label><button>Send</button></form>
</section>
<section id="music" hidden>
<nav aria-label="Catalog views" id="tabs"><button data-tab="feed">Feed</button><button data-tab="songs">Songs</button><button data-tab="albums">Albums</button></nav>
<ul id="catalog"></ul>
</section>
</main>
<footer id="footer" hidden><div><strong id="now">Idle</strong><small id="progress"></small></div>
<details><summary id="queue-label">Up Next</summary><ol id="queue"></ol></details></footer>
</body>
</html>
