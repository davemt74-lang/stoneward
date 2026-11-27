/**
 * Browser acceptance: actual Admin document/modules/styles and feature routes,
 * then actual app.js play/footer/seek/lifecycle/telemetry in a focused media
 * host. The ten unrelated Admin startup APIs are explicit empty adapters.
 * VP3, connection state, import, catalog editing, auth, native media, CORS and
 * listening routes are real. This is not live hosting or physical iOS proof.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';
import {musicIntegrationFixture, artistOrigin, providerOrigin, repository, createRegistration, verifyActualProof} from './vp3-music-fixture.mjs';

const engine = process.env.SF_AUDIO_BROWSER || 'chromium';
assert.ok(['chromium', 'webkit'].includes(engine), 'SF_AUDIO_BROWSER must be chromium or webkit');
const artifactDirectory = path.resolve(process.env.VP3_MUSIC_ARTIFACT_DIR || path.join(repository, 'artifacts', 'vp3-music-' + engine));
const sensitive = /vmp1_[a-f0-9]{64}|mps1_[a-f0-9]{64}|mpd1\.[A-Za-z0-9_.-]+/g;
const clean = value => String(value).replace(sensitive, '[redacted]');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(check, label, ms = 10000) {
  const deadline = Date.now() + ms;
  do {if (await check()) return; await sleep(25);} while (Date.now() < deadline);
  throw new Error('Timed out: ' + label);
}
export function playerHost(fixture, imported) {
  const source = fs.readFileSync(path.join(repository, 'assets/js/app.js'), 'utf8');
  const block = (begin, end) => {
    const a = source.indexOf(begin); const b = source.indexOf(end, a + begin.length);
    assert.ok(a >= 0 && b > a, 'Known production extraction boundaries: ' + begin);
    return source.slice(a, b);
  };
  const apiAt = source.indexOf('  async function api(');
  assert.ok(apiAt >= 0);
  const api = source.slice(apiAt, source.indexOf('\n', apiAt));
  const playable = source.split('\n').find(line => line.includes('const hasPlayableAudio='));
  assert.ok(playable, 'Actual app typed-source predicate is present');
  const tracks = JSON.stringify([imported, fixture.native]).replaceAll('<', '\\u003c');
  const html = `<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="/"><title>Production VP3 player acceptance</title><style>body{margin:0;padding:24px;font:16px system-ui;color:#e8f1ff;background:#101725}main{max-width:850px;margin:auto}h1{font-size:1.6rem}.controls{display:flex;gap:12px;flex-wrap:wrap}button{min-height:48px;padding:12px 18px;color:#edf4ff;background:#263d60;border:1px solid #728db5;border-radius:8px}audio{width:100%;margin-top:24px}pre{white-space:pre-wrap;overflow-wrap:anywhere}</style>
<main><p>Focused host · actual Stoneward player functions and real provider</p><h1>VP3 streaming playback</h1><div class="controls"><button id="main">Play imported song</button><button id="footer">Footer pause/resume</button><button id="seek">Seek to 60 percent</button><button id="renew">Renew this session</button><button id="local">Play existing local song</button></div><div id="player"><audio id="canonical" controls></audio><p id="playbackStatus" role="status"></p></div><pre id="state"></pre></main>
<script>window.fixtureTracks=${tracks};</script>
<script src="/assets/js/player-playback-lifecycle.js"></script><script src="/assets/js/listening-telemetry.js"></script><script src="/assets/js/vp3-music-player.js"></script>
<script>
const audio=document.getElementById('canonical'),footerPlayer=document.getElementById('player'),playerPlaybackStatus=document.getElementById('playbackStatus');
const cfg={apiBase:'/api'},st={auth:{authenticated:false,user:null,csrf:''},track:null,playing:false,playlistPlayback:{}};
let vp3Main=null,pendingNetworkRecovery=null,playbackWantsAudio=false,playbackBufferTimer=null,songCaptureSkill=null,songListeningSkill=null;
const playbackLifecycle=StonefellowPlaybackLifecycle.create();
const canvasOwner=()=>String(st.auth.user?.id||''),time=value=>String(Math.floor(value||0));
const clearPlaylistPlayback=()=>{},updateQueueUi=()=>{},agent=()=>{};
const updateFooterPlayer=()=>{document.getElementById('state').textContent=JSON.stringify({track:st.track?.id,playing:!audio.paused,position:Math.round(audio.currentTime||0),receipt:vp3Main?.state()},null,2);};
${playable}\n${api}\n${block('  function ensureListenSession(){', '  function fmtWhen(')}
${block('  function playbackEndedEarly(){', '  async function stepTrack(')}
document.getElementById('main').onclick=()=>play(fixtureTracks[0]);
document.getElementById('footer').onclick=toggle;document.getElementById('seek').onclick=()=>seekPlayback(.6);
document.getElementById('local').onclick=()=>play(fixtureTracks[1]);
document.getElementById('renew').onclick=()=>{window.renewOutcome='pending';vp3MainPlayer().renew().then(()=>{window.renewOutcome='complete';updateFooterPlayer();},e=>{window.renewOutcome=e.code||'failed';updateFooterPlayer();});};
audio.addEventListener('timeupdate',updateFooterPlayer);audio.addEventListener('pause',updateFooterPlayer);
window.playerSnapshot=()=>({track:st.track?.id,playing:!audio.paused,position:audio.currentTime,provider:vp3Main?.state(),native:SFListeningTelemetry.hasMain(),renewing:vp3Main?.isRenewing()});
window.stopProvider=()=>{vp3Main?.stop();audio.pause();};
window.fixtureReady=(async()=>{st.auth=await api('auth.php');listeningTelemetryMain();return true;})();
</script></html>`;
  fs.writeFileSync(path.join(fixture.root, 'music.php'), html);
}

async function geometry(page, selector) {
  const element = page.locator(selector);
  await element.scrollIntoViewIfNeeded();
  return element.evaluate(node => {
    const r = node.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + r.height / 2;
    const top = document.elementFromPoint(x, y);
    const clipped = [];
    for (let parent = node.parentElement; parent; parent = parent.parentElement) {
      const css = getComputedStyle(parent);
      if (parent !== document.body && parent !== document.documentElement && ['auto', 'scroll'].includes(css.overflowY) && parent.scrollHeight > parent.clientHeight + 2)
        clipped.push({tag: parent.tagName, id: parent.id});
    }
    return {viewport: innerWidth, page_width: document.documentElement.scrollWidth,
      width: r.width, height: r.height, x: r.x, y: r.y,
      unobstructed: top === node || node.contains(top), nested_scroll: clipped};
  });
}

async function capture(page, filename) {
  // Measure the actionable scrolled position first, then place fixed Admin
  // chrome at the document top for a coherent full-page review artifact.
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({path: path.join(artifactDirectory, filename), fullPage: true});
}

export async function runBrowserAcceptance() {
  fs.mkdirSync(artifactDirectory, {recursive: true});
  const playwright = await import(process.env.SF_PLAYWRIGHT_MODULE
    ? pathToFileURL(path.resolve(process.env.SF_PLAYWRIGHT_MODULE)).href : 'playwright');
  const evidence = {browser: engine, stoneward_revision: execFileSync('git', ['rev-parse', 'HEAD'], {cwd: repository, encoding: 'utf8'}).trim(),
    provider_revision: '', scope: 'actual admin shell with unrelated startup adapters; focused production app player host; real cross-repo feature routes and media',
    widths: [], checks: [], passed: false};
  const check = (value, label) => {assert.ok(value, label); evidence.checks.push(label);};
  let activePage;
  try {
    for (const width of [1440, 390]) {
      const fixture = await musicIntegrationFixture();
      let browser, context, reviewGeometry;
      let revocationStarted = false;
      const errors = [], expectedNetworkDiagnostics = [];
      try {
        evidence.provider_revision = fixture.providerRevision;
        const discovery = await (await fetch(fixture.provider.base + '/api/music-provider-v1.php')).json();
        const extendedPages = discovery.source_page_profile === 'public_music_pages_v2';
        assert.ok(!discovery.source_page_profile || extendedPages, 'Explicit accepted source-page profile');
        evidence.source_page_profile = discovery.source_page_profile || 'public_music_pages_v1';
        const identity = fixture.provider.identities.find(row => Number(row.catalog_track_id) === 10);
        const hostileTitle = 'Long artist song title for mobile selection — ' + 'a lovely refrain '.repeat(6) + '<img src=x onerror=window.injected=true>';
        fixture.provider.execute('UPDATE artist_catalog_tracks_v181 SET title=? WHERE id=10', [hostileTitle]);
        const registration = createRegistration(fixture);
        const launch = {headless: true, proxy: fixture.browserProxy};
        if (engine === 'chromium') {
          launch.args = ['--no-sandbox'];
          if (process.env.CHROMIUM_BIN) launch.executablePath = process.env.CHROMIUM_BIN;
        }
        browser = await playwright[engine].launch(launch);
        context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900},
          hasTouch: width < 500, reducedMotion: 'reduce'});
        // A provider cookie must not accompany any provider playback/API/media request.
        await context.addCookies([{name: 'unrelated_provider_session', value: 'fixture-cookie-must-not-travel', url: providerOrigin, secure: true, sameSite: 'None'}]);
        const page = activePage = await context.newPage();
        page.on('pageerror', error => {
          const message = clean(error.message);
          // WebKit emits an engine pageerror for a deliberately rejected CORS
          // request even when fetch rejects into the application's handler.
          // Accept only this exact site endpoint after intentional revocation;
          // every other JavaScript/network diagnostic remains a failure.
          if (engine === 'webkit' && revocationStarted && message.includes(new URL(providerOrigin).hostname +
              '/api/music-provider-v1.php?site_id=' + registration.site.site_id) && message.endsWith('due to access control checks.')) {
            expectedNetworkDiagnostics.push({phase: 'revoked_provider', message});
          } else errors.push(message);
        });
        const unrelatedAdmin = new Set(['templates.php', 'state.php', 'releases.php', 'shows.php', 'crm.php',
          'knowledge.php', 'users.php', 'packages.php', 'ai-providers.php', 'customer-lifecycle.php']);
        await page.route(url => url.origin === artistOrigin && url.pathname.startsWith('/admin/api/') && unrelatedAdmin.has(url.pathname.split('/').at(-1)),
          route => route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({ok: true})}));
        await page.goto(artistOrigin + '/api/auth.php');
        const login = await page.evaluate(async () => {
          const session = await fetch('/api/auth.php').then(r => r.json());
          const response = await fetch('/admin/api/auth.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf},
            body: JSON.stringify({action: 'login', email: 'artist@example.invalid', password: 'fixture-only-password'})});
          return {status: response.status, authenticated: (await response.json()).authenticated};
        });
        assert.deepEqual(login, {status: 200, authenticated: true});
        await page.goto(artistOrigin + '/admin/?view=catalog', {waitUntil: 'domcontentloaded'});
        await page.locator('#adminCanvas [data-view="vp3-music"]').waitFor({state: 'visible'});
        await page.locator('#adminCanvas [data-view="vp3-music"]').click();
        await page.getByLabel('VP3 address', {exact: false}).waitFor({state: 'visible'});
        await page.getByLabel('VP3 address', {exact: false}).fill(providerOrigin);
        await page.getByLabel('Music connection credential', {exact: false}).fill(registration.credential);
        await page.getByLabel('Site verification JSON', {exact: true}).fill(JSON.stringify({...registration.site.verification.response,
          origin: 'https://other.music.example.com'}));
        await page.getByRole('button', {name: 'Save connection', exact: true}).click();
        await page.waitForFunction(() => !!document.querySelector('#vp3MusicStatus .form-error')?.textContent &&
          document.querySelector('.vp3-music')?.getAttribute('aria-busy') === 'false');
        check(!await page.getByRole('button', {name: 'Save connection', exact: true}).isDisabled() &&
          await page.getByRole('button', {name: 'Retry the same request', exact: true}).count() === 0,
          engine + '/' + width + ': explicit pre-write origin rejection permits form correction');
        await page.getByLabel('VP3 address', {exact: false}).fill(providerOrigin);
        await page.getByLabel('Music connection credential', {exact: false}).fill(registration.credential);
        await page.getByLabel('Site verification JSON', {exact: true}).fill(JSON.stringify(registration.site.verification.response));
        fixture.controls.dropAction = 'connect';
        await page.getByRole('button', {name: 'Save connection', exact: true}).click();
        await page.getByRole('button', {name: 'Retry the same request', exact: true}).waitFor({state: 'visible'});
        check(await page.getByRole('button', {name: 'Save connection', exact: true}).isDisabled(), engine + '/' + width + ': uncertain connect locks competing writes');
        check(await page.getByLabel('Music connection credential', {exact: false}).inputValue() === '', engine + '/' + width + ': credential field clears before network acknowledgement');
        await page.getByRole('button', {name: 'Retry the same request', exact: true}).click();
        await page.getByRole('button', {name: 'Check connection', exact: true}).waitFor({state: 'visible'});
        const connectionTraffic = fixture.traffic.filter(row => row.action === 'connect' && row.status === 200);
        assert.equal(connectionTraffic.length, 2); assert.equal(connectionTraffic[0].body_sha256, connectionTraffic[1].body_sha256);
        check(true, engine + '/' + width + ': connection retry resends the identical body after actual server commit');
        await verifyActualProof(fixture, registration);
        await page.getByRole('button', {name: 'Check connection', exact: true}).click();
        const checkbox = page.locator('[data-vp3-recording="' + identity.recording_id + '"]');
        await checkbox.waitFor({state: 'visible'});
        await checkbox.check();
        check(!(await page.evaluate(() => !!window.injected)) && await page.locator('.vp3-track img[src="x"]').count() === 0,
          engine + '/' + width + ': provider title stays escaped text');
        await page.getByRole('button', {name: 'Review selection', exact: true}).click();
        reviewGeometry = await geometry(page, '#vp3Import');
        check(reviewGeometry.page_width <= width + 1 && reviewGeometry.unobstructed && reviewGeometry.height >= 40,
          engine + '/' + width + ': import review button fits viewport and is unobstructed');
        check(reviewGeometry.nested_scroll.length === 0, engine + '/' + width + ': importer uses page flow without a nested scroll trap');
        await capture(page, 'admin-' + width + '-review.png');
        fixture.controls.dropAction = 'import';
        await page.getByRole('button', {name: 'Import selected songs', exact: true}).click();
        await page.getByRole('button', {name: 'Retry the same request', exact: true}).waitFor({state: 'visible'});
        check(await page.getByRole('button', {name: 'Review selection', exact: true}).isDisabled(),
          engine + '/' + width + ': lost import acknowledgement freezes reviewed selection');
        await page.getByRole('button', {name: 'Retry the same request', exact: true}).click();
        await page.waitForFunction(() => document.getElementById('vp3MusicStatus')?.textContent.includes('Import complete'));
        const imports = fixture.traffic.filter(row => row.action === 'import');
        assert.equal(imports.length, 2); assert.equal(imports[0].body_sha256, imports[1].body_sha256);
        const catalog = JSON.parse(fs.readFileSync(path.join(fixture.root, 'data/catalog.json'), 'utf8'));
        assert.equal(catalog.length, 2); const imported = catalog.find(row => row.audio_source?.kind === 'vp3');
        assert.ok(imported); assert.deepEqual(catalog.find(row => row.id === fixture.native.id), fixture.native);
        check(true, engine + '/' + width + ': real imported catalog contains one stable provider song and unchanged native song');
        check(!await page.evaluate(() => /vmp1_|mps1_|mpd1\./.test(JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]))),
          engine + '/' + width + ': browser storage contains no provider secrets');
        await capture(page, 'admin-' + width + '-complete.png');
        playerHost(fixture, imported);
        await page.goto(artistOrigin + '/music.php?private=PRIVATE-QUERY#PRIVATE-FRAGMENT', {waitUntil: 'domcontentloaded'});
        await page.evaluate(() => window.fixtureReady);
        await page.locator('#local').click();
        await page.waitForFunction(() => playerSnapshot().track === 'local-song' && audio.currentTime > .5 && !audio.paused);
        await page.locator('#footer').click();
        await page.waitForFunction(() => audio.paused);
        check(true, engine + '/' + width + ': existing local song plays and footer pauses through production app functions');
        fixture.controls.dropAction = 'playback.begin';
        await page.locator('#main').click();
        await page.waitForFunction(() => playerSnapshot().provider?.accepted_seq >= 1 && audio.currentTime > .6 && !audio.paused, undefined, {timeout: 20000});
        const initial = await page.evaluate(() => playerSnapshot());
        const beginRows = fixture.traffic.filter(row => row.action === 'playback.begin');
        assert.equal(beginRows.length, 2); assert.equal(beginRows[0].body_sha256, beginRows[1].body_sha256);
        assert.equal(beginRows[0].source_url, artistOrigin + '/music.php');
        assert.equal(beginRows[0].source_surface, 'player');
        assert.equal(fixture.provider.readDb('SELECT COUNT(*) AS count FROM music_provider_sessions_v1')[0].count, 1);
        const beginReceipt = JSON.parse(fixture.provider.readDb('SELECT begin_receipt_json FROM music_provider_sessions_v1 WHERE session_id=?', [initial.provider.session_id])[0].begin_receipt_json);
        assert.equal(beginReceipt.accepted_played_ms, 0);
        check(true, engine + '/' + width + ': real begin retry creates one zero-heard-time provider session and strips page query/fragment');
        await page.waitForFunction(() => playerSnapshot().provider?.pending_events === 0);
        fixture.controls.dropAction = 'playback.events';
        await page.locator('#footer').click();
        await page.waitForFunction(() => audio.paused && playerSnapshot().provider?.pending_events === 0 && playerSnapshot().provider?.accepted_seq >= 2);
        const pauses = fixture.traffic.filter(row => row.action === 'playback.events' && row.event_type === 'pause');
        assert.equal(pauses.length, 2); assert.equal(pauses[0].body_sha256, pauses[1].body_sha256);
        assert.equal(pauses[0].event_id, pauses[1].event_id); assert.equal(pauses[0].seq, pauses[1].seq);
        check(true, engine + '/' + width + ': actual pause receipt loss preserves frozen event identity and sequence');
        await page.locator('#seek').click();
        await page.waitForFunction(() => audio.currentTime > 17 && !audio.seeking);
        await page.locator('#footer').click();
        await page.waitForFunction(() => audio.currentTime > 18.6 && !audio.paused);
        const beforeRenew = await page.evaluate(() => playerSnapshot());
        const nativeStarts = fixture.traffic.filter(row => row.action === 'begin' && row.track_id === imported.id).length;
        fixture.controls.dropAction = 'playback.renew';
        await page.locator('#renew').click();
        await page.waitForFunction(() => window.renewOutcome === 'complete' && !audio.paused, undefined, {timeout: 20000});
        const afterRenew = await page.evaluate(() => playerSnapshot());
        assert.equal(afterRenew.provider.session_id, initial.provider.session_id);
        assert.equal(afterRenew.provider.play_id, initial.provider.play_id); assert.equal(afterRenew.provider.asset_id, initial.provider.asset_id);
        assert.ok(afterRenew.position >= beforeRenew.position - .35 && afterRenew.position < beforeRenew.position + 4);
        const renewals = fixture.traffic.filter(row => row.action === 'playback.renew');
        assert.equal(renewals.length, 2); assert.equal(renewals[0].body_sha256, renewals[1].body_sha256);
        assert.equal(fixture.traffic.filter(row => row.action === 'begin' && row.track_id === imported.id).length, nativeStarts);
        assert.equal(nativeStarts, 1);
        check(true, engine + '/' + width + ': real media renewal preserves session/asset/position and one native history start');
        fixture.controls.holdAction = 'playback.renew';
        await page.locator('#renew').click();
        await until(() => fixture.controls.held.length === 1, 'actual renewal acknowledgement is held');
        await page.locator('#footer').click();
        await page.waitForFunction(() => audio.paused);
        fixture.controls.held.shift()();
        await page.waitForFunction(() => window.renewOutcome === 'complete' && !playerSnapshot().renewing);
        check(await page.evaluate(() => audio.paused), engine + '/' + width + ': pause during real renewal remains paused after response');
        await page.waitForFunction(() => playerSnapshot().provider?.pending_events === 0);
        const heard = fixture.provider.readDb('SELECT accepted_played_ms,position_ms FROM music_provider_sessions_v1 WHERE session_id=?', [initial.provider.session_id])[0];
        check(Number(heard.accepted_played_ms) > 0 && Number(heard.accepted_played_ms) < 10000 && Number(heard.position_ms) > 17000,
          engine + '/' + width + ': seeking does not count skipped audio as heard time');
        await capture(page, 'player-' + width + '-renewed.png');
        for (const [route, expected, stored = expected || null] of [['/', artistOrigin + '/'], ['/music?private=hidden#secret', artistOrigin + '/music'],
          ['/account.php?session=hidden', ''], ['/music-workspace.php', ''],
          ['/track/' + imported.id + '?private=hidden#secret', artistOrigin + '/track/' + imported.id, extendedPages ? artistOrigin + '/track/' + imported.id : null],
          ['/release/public-release/', artistOrigin + '/release/public-release/', extendedPages ? artistOrigin + '/release/public-release/' : null],
          ['/stonefellow-v120.php?view=music&private=hidden', artistOrigin + '/stonefellow-v120.php', extendedPages ? artistOrigin + '/stonefellow-v120.php' : null]]) {
          await page.evaluate(route => {stopProvider(); history.replaceState({}, '', route);}, route);
          const count = fixture.traffic.filter(row => row.action === 'playback.begin').length;
          await page.locator('#main').click();
          await page.waitForFunction(() => playerSnapshot().provider?.accepted_seq >= 1 && !audio.paused);
          const fresh = fixture.traffic.filter(row => row.action === 'playback.begin').slice(count);
          assert.equal(fresh.length, 1); assert.equal(fresh[0].source_url, expected);
          const receipt = await page.evaluate(() => playerSnapshot().provider);
          const source = JSON.parse(fixture.provider.readDb('SELECT source_json FROM music_provider_sessions_v1 WHERE session_id=?', [receipt.session_id])[0].source_json);
          assert.equal(source.url, stored); assert.equal(source.page_verified, false); assert.equal(source.verified_origin, artistOrigin);
        }
        check(true, engine + '/' + width + ': public song/release/shell pages follow ' + evidence.source_page_profile + '; private paths omitted and original site-only compatibility preserved');
        const providerTraffic = fixture.traffic.filter(row => row.hostname === new URL(providerOrigin).hostname);
        check(providerTraffic.some(row => row.pathname === '/api/music-provider-media-v1.php' && [200, 206].includes(row.status)),
          engine + '/' + width + ': native browser consumes real provider audio over cross-origin HTTPS');
        check(providerTraffic.filter(row => row.method !== 'OPTIONS').every(row => !row.cookie && row.bearer_kind !== 'vmp1_'),
          engine + '/' + width + ': provider browser requests omit cookies and management credentials');
        check(providerTraffic.filter(row => row.action.startsWith('playback.')).every(row => row.origin === artistOrigin && !row.referrer),
          engine + '/' + width + ': real CORS uses registered artist origin and no referrer');
        const revision = Number(fixture.provider.readDb('SELECT revision FROM music_provider_sites_v1 WHERE site_id=?', [registration.site.site_id])[0].revision);
        const beforeRevocation = await page.evaluate(() => playerSnapshot());
        fixture.provider.ownerService({action: 'site.revoke', workspace_id: 1, site_id: registration.site.site_id,
          request_id: cryptoRandomUUID(), expected_revision: revision});
        revocationStarted = true;
        const revocationTrafficAt = fixture.traffic.length;
        const beforeRevocationBegins = fixture.traffic.filter(row => row.action === 'playback.begin').length;
        await page.locator('#renew').click();
        await page.waitForFunction(() => {
          const provider = playerSnapshot().provider;
          return audio.paused && !playerSnapshot().renewing && (provider?.confirmation_required === true || provider?.terminal === true) &&
            window.renewOutcome !== 'pending' && window.renewOutcome !== 'complete';
        }, undefined, {timeout: 20000});
        const rejected = await page.evaluate(() => playerSnapshot());
        assert.equal(rejected.provider.session_id, beforeRevocation.provider.session_id);
        assert.equal(rejected.provider.play_id, beforeRevocation.provider.play_id);
        assert.ok(fixture.traffic.slice(revocationTrafficAt).some(row => row.hostname === new URL(providerOrigin).hostname &&
          row.pathname === '/api/music-provider-v1.php' && row.status === 403 && (row.action === 'playback.renew' || row.method === 'OPTIONS')),
          'Actual pinned provider rejects revoked renewal or its preflight');
        assert.equal(fixture.traffic.filter(row => row.action === 'playback.begin').length, beforeRevocationBegins);
        check(true, engine + '/' + width + ': actual remote revocation pauses unconfirmed playback without inventing a revoke reason or another play');
        await page.locator('#footer').click();
        await page.waitForFunction(() => {
          const provider = playerSnapshot().provider;
          return audio.paused && footerPlayer.dataset.playbackPhase === 'error' &&
            (provider?.confirmation_required === true || provider?.terminal === true);
        }, undefined, {timeout: 20000});
        const refusedResume = await page.evaluate(() => playerSnapshot());
        assert.equal(refusedResume.provider.session_id, rejected.provider.session_id);
        assert.equal(refusedResume.provider.play_id, rejected.provider.play_id);
        assert.ok(Math.abs(refusedResume.position - rejected.position) < .1, 'Failed grant confirmation never resumes buffered audio');
        assert.equal(fixture.traffic.filter(row => row.action === 'playback.begin').length, beforeRevocationBegins);
        const rejectedRenewals = fixture.traffic.slice(revocationTrafficAt).filter(row => row.action === 'playback.renew');
        assert.ok(new Set(rejectedRenewals.map(row => row.body_sha256)).size <= 1, 'Explicit retry preserves the unacknowledged renewal identity');
        check(true, engine + '/' + width + ': footer retry cannot resume a rejected grant or create another provider session');
        await capture(page, 'player-' + width + '-revoked.png');
        await page.locator('#local').click();
        await page.waitForFunction(() => playerSnapshot().track === 'local-song' && audio.currentTime > .3 && !audio.paused);
        check(!await page.evaluate(() => /vmp1_|mps1_|mpd1\./.test(JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]))),
          engine + '/' + width + ': local playback still works after provider revocation without persistent provider secrets');
        check(errors.length === 0, engine + '/' + width + ': no browser exceptions: ' + errors.join('; '));
        evidence.widths.push({width, review_geometry: reviewGeometry, browser_errors: errors,
          expected_network_diagnostics: expectedNetworkDiagnostics, traffic: fixture.traffic});
      } catch (error) {
        evidence.widths.push({width, failed: true, failure: clean(error.message), review_geometry: reviewGeometry,
          browser_errors: errors, expected_network_diagnostics: expectedNetworkDiagnostics, traffic: fixture.traffic});
        try {await activePage?.screenshot({path: path.join(artifactDirectory, 'failure-' + width + '.png'), fullPage: true});} catch {}
        throw error;
      } finally {
        if (errors.length) evidence.checks.push('Browser exceptions: ' + errors.join('; '));
        await context?.close(); await browser?.close(); await fixture.close(); activePage = null;
      }
    }
    evidence.passed = true;
    process.stdout.write(engine + ' real browser acceptance PASS (' + evidence.checks.length + ' checks).\n');
    return evidence;
  } catch (error) {
    evidence.failure = clean(error.message);
    try {await activePage?.screenshot({path: path.join(artifactDirectory, 'failure.png'), fullPage: true});} catch {}
    throw error;
  } finally {
    fs.writeFileSync(path.join(artifactDirectory, 'evidence.json'), clean(JSON.stringify(evidence, null, 2)) + '\n');
  }
}

function cryptoRandomUUID() {
  return globalThis.crypto.randomUUID();
}
