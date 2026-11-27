/** Real Stoneward HTTP controllers against the pinned VP3 PHP/SQLite provider.
 * --http-only deliberately omits browser assertions; the normal invocation
 * requires Playwright and real media in the requested Chromium/WebKit engine.
 */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {musicIntegrationFixture, artistOrigin, providerOrigin, protocol, createRegistration, verifyActualProof} from './vp3-music-fixture.mjs';
import providerPlayer from '../assets/js/vp3-music-player.js';
export {createRegistration, verifyActualProof} from './vp3-music-fixture.mjs';

const endpoint = '/admin/api/vp3-music.php';
const uuid = () => crypto.randomUUID();
const privatePattern = /vmp1_|mps1_|mpd1\.|credential_payload|stored_path|source_fingerprint|contact_email|rights_notes/;

export function httpClient(fixture) {
  const cookies = new Map();
  let csrf = '';
  async function request(route, body, {csrf: sendCsrf = true, headers = {}, method} = {}) {
    const response = await fetch(fixture.base + route, {
      method: method || (body === undefined ? 'GET' : 'POST'),
      headers: {Host: new URL(artistOrigin).hostname, Origin: artistOrigin,
        ...(cookies.size ? {Cookie: [...cookies].map(([name, value]) => name + '=' + value).join('; ')} : {}),
        ...(body !== undefined ? {'Content-Type': 'application/json', ...(sendCsrf ? {'X-CSRF-Token': csrf} : {})} : {}), ...headers},
      ...(body === undefined ? {} : {body: JSON.stringify(body)}),
    });
    for (const value of response.headers.getSetCookie()) {
      const pair = value.split(';', 1)[0]; const at = pair.indexOf('=');
      if (at > 0) cookies.set(pair.slice(0, at), pair.slice(at + 1));
    }
    const text = await response.text();
    let data; try {data = JSON.parse(text);} catch {data = text;}
    if (data?.csrf) csrf = data.csrf;
    return {status: response.status, data, headers: response.headers};
  }
  async function login(admin = true) {
    const session = await request('/api/auth.php');
    assert.equal(session.status, 200); assert.ok(session.data.csrf);
    const result = await request(admin ? '/admin/api/auth.php' : '/api/auth.php', {
      action: 'login', email: admin ? 'artist@example.invalid' : 'fan@example.invalid', password: 'fixture-only-password',
    });
    assert.equal(result.status, 200, 'Real fixture account login succeeds');
    assert.equal(result.data.authenticated, true);
    return result;
  }
  return {request, login};
}

export function importInput(catalog, recordingIds) {
  return {action: 'import', request_id: uuid(), expected_revision: catalog.connection.revision,
    expected_selection_revision: catalog.selection_revision, catalog_hash: catalog.catalog_hash,
    recording_ids: [...recordingIds].sort()};
}

export async function runHttpAcceptance() {
  const fixture = await musicIntegrationFixture();
  const client = httpClient(fixture);
  const checks = [];
  const expect = (result, status, label) => {
    assert.equal(result.status, status, label + ': ' + JSON.stringify(result.data));
    checks.push(label); return result.data;
  };
  const catalogFile = path.join(fixture.root, 'data/catalog.json');
  const readCatalog = () => JSON.parse(fs.readFileSync(catalogFile, 'utf8'));
  try {
    expect(await client.request(endpoint), 401, 'guest cannot inspect private connection');
    expect(await client.request('/api/vp3-music-site.php'), 404, 'disconnected proof fails closed');
    expect(await client.request('/api/vp3-music-source.php?track_id=local-song'), 404, 'local song is not a provider source');
    const fan = httpClient(fixture); await fan.login(false);
    expect(await fan.request(endpoint), 403, 'authenticated listener cannot manage connection');
    await client.login();
    expect(await client.request(endpoint, {action: 'catalog'}, {csrf: false}), 403, 'administrator mutation requires CSRF');
    const empty = expect(await client.request(endpoint), 200, 'administrator sees disconnected status');
    assert.equal(empty.connection.state, 'disconnected'); assert.equal(empty.connection.revision, 0);
    const registration = createRegistration(fixture);
    const connect = {action: 'connect', request_id: uuid(), expected_revision: 0, provider_url: providerOrigin,
      credential: registration.credential, verification: registration.site.verification.response};
    for (const provider_url of ['http://vp3.music.example.com', 'https://127.0.0.1', 'https://localhost',
      'https://vp3.music.example.com:8443', 'https://user:password@vp3.music.example.com', 'https://vp3.music.example.com?target=x']) {
      expect(await client.request(endpoint, {...connect, request_id: uuid(), provider_url}), 422, 'unsafe provider address is rejected');
    }
    for (const origin of ['https://other.music.example.com', artistOrigin + '/artist']) {
      expect(await client.request(endpoint, {...connect, request_id: uuid(), verification: {...connect.verification, origin}}),
        422, 'proof origin must match this root artist installation');
    }
    const connected = expect(await client.request(endpoint, connect), 200, 'actual provider discovery connects');
    assert.equal(connected.connection.state, 'pending_verification');
    assert.equal(connected.connection.provider_url, providerOrigin + '/api/music-provider-v1.php');
    assert.doesNotMatch(JSON.stringify(connected), privatePattern);
    assert.ok(!fs.readFileSync(path.join(fixture.root, 'storage/vp3-music/state.json'), 'utf8').includes(registration.credential),
      'Connection credential is encrypted at rest');
    assert.deepEqual(expect(await client.request(endpoint, connect), 200, 'lost connection acknowledgement is safely replayable'), connected);
    expect(await client.request(endpoint, {...connect, credential: 'vmp1_' + 'f'.repeat(64)}), 409, 'same request cannot change credential');
    expect(await client.request('/api/vp3-music-site.php?challenge=reflected'), 405, 'proof rejects reflected query parameters');
    expect(await client.request('/api/vp3-music-site.php', {}), 405, 'proof is read only');
    const pendingCatalog = await client.request(endpoint, {action: 'catalog', offset: 0, search: ''});
    assert.ok([401, 403].includes(pendingCatalog.status), 'Unverified provider denies catalog: ' + JSON.stringify(pendingCatalog.data) + ' status=' + pendingCatalog.status);
    assert.deepEqual(readCatalog(), [fixture.native], 'Unverified discovery does not alter local songs');
    checks.push('unverified registration cannot import');
    await verifyActualProof(fixture, registration);
    checks.push('real Stoneward proof passes actual VP3 owner verification');
    const catalog = expect(await client.request(endpoint, {action: 'catalog', offset: 0, search: ''}), 200, 'verified provider returns actual catalog');
    assert.equal(catalog.connection.state, 'ready');
    const owned = fixture.provider.readDb('SELECT recording_id FROM music_recordings_v1 WHERE workspace_id=1').map(row => row.recording_id).sort();
    assert.deepEqual(catalog.items.map(row => row.recording_id).sort(), owned, 'Catalog contains exactly this artist workspace recordings');
    const identity = fixture.provider.identities.find(row => Number(row.catalog_track_id) === 10);
    assert.ok(catalog.items.some(row => row.recording_id === identity.recording_id && row.availability === 'ready'));
    assert.ok(!catalog.items.some(row => row.recording_id === fixture.provider.identities.find(row => Number(row.catalog_track_id) === 20).recording_id));
    assert.doesNotMatch(JSON.stringify(catalog), privatePattern);
    const approved = importInput(catalog, [identity.recording_id]);
    expect(await client.request(endpoint, {...approved, expected_revision: 0}), 409, 'stale connection cannot import');
    expect(await client.request(endpoint, {...approved, catalog_hash: '0'.repeat(64)}), 409, 'stale catalog cannot import');
    const concurrentAdmin = httpClient(fixture); await concurrentAdmin.login();
    const concurrent = await Promise.all([client.request(endpoint, approved), concurrentAdmin.request(endpoint, approved)]);
    const imported = expect(concurrent[0], 200, 'real selected provider manifest imports');
    assert.deepEqual(expect(concurrent[1], 200, 'concurrent duplicate import replays the same receipt'), imported);
    assert.equal(imported.imported_count, 1); assert.equal(imported.updated_count, 0);
    const rows = readCatalog(); assert.equal(rows.length, 2); assert.deepEqual(rows[0], fixture.native);
    const track = rows[1]; assert.ok(track.id.startsWith('vp3-'));
    assert.deepEqual(track.audio_source, {kind: 'vp3', installation_id: connected.connection.installation_id,
      site_id: registration.site.site_id, recording_id: identity.recording_id});
    assert.equal(track.audio, ''); assert.equal(track.digital_purchase_enabled, false); assert.equal(track.podEligible, false);
    assert.deepEqual(track.vinyl_license_packages, []); assert.equal(track.audio_publishing_mode, 'vp3_stream_only');
    for (const relative of ['data/catalog.json', 'assets/js/catalog.js']) {
      const publicText = fs.readFileSync(path.join(fixture.root, relative), 'utf8');
      assert.doesNotMatch(publicText, privatePattern, relative + ' contains public metadata and stable references only');
      assert.ok(!publicText.includes('music-provider-media-v1.php'));
    }
    const source = expect(await client.request('/api/vp3-music-source.php?track_id=' + track.id), 200, 'public resolver returns current stable provider identity');
    assert.equal(source.source.provider_api, providerOrigin + '/api/music-provider-v1.php');
    assert.doesNotMatch(JSON.stringify(source), privatePattern);
    const discovery = await (await fetch(fixture.provider.base + '/api/music-provider-v1.php')).json();
    const extendedPages = discovery.source_page_profile === 'public_music_pages_v2';
    assert.ok(!discovery.source_page_profile || extendedPages, 'Explicit accepted source-page profile');
    for (const route of ['/track/' + track.id, '/release/public-release/', '/stonefellow-v120.php']) {
      const safePage = providerPlayer.safeSourcePage(artistOrigin + route + '?credential=PRIVATE#PRIVATE', artistOrigin);
      assert.equal(safePage, artistOrigin + route, 'Actual Stoneward sanitizer retains the public route only');
      const begin = {protocol, action: 'playback.begin', site_id: registration.site.site_id, recording_id: identity.recording_id,
        play_id: uuid(), playback_key: 'mps1_' + crypto.randomBytes(32).toString('hex'), source_url: safePage, source_surface: 'player', position_ms: 0, playback_rate: 1};
      const response = await fixture.provider.request(begin, {origin: artistOrigin});
      const receipt = expect({status: response.status, data: await response.json()}, 200,
        'real paired provider accepts public route ' + route);
      assert.equal(receipt.source.url, extendedPages ? safePage : null,
        extendedPages ? 'Current provider retains public page' : 'Original provider preserves site-only fallback');
      assert.equal(receipt.source.verified_origin, artistOrigin); assert.equal(receipt.source.page_verified, false);
      assert.doesNotMatch(JSON.stringify(receipt.source), /PRIVATE|credential/);
      const retry = await fixture.provider.request(begin, {origin: artistOrigin});
      const repeated = expect({status: retry.status, data: await retry.json()}, 200, 'source-page begin retry preserves one session');
      delete repeated.correlation_id; delete receipt.correlation_id; assert.deepEqual(repeated, receipt);
    }
    checks.push(extendedPages ? 'paired public_music_pages_v2 retains new pages' : 'paired original provider safely retains site attribution for new pages');
    const canvasCatalog = expect(await client.request('/api/canvas-audio.php?action=catalog&track_id=' + track.id), 200,
      'actual canvas catalog exposes typed imported playback capability');
    const canvasSong = canvasCatalog.tracks?.find(row => String(row.id || row.track_id) === track.id);
    assert.ok(canvasSong, 'Imported recording appears in actual canvas catalog');
    assert.equal(canvasSong.source_available, true); assert.equal(canvasSong.source_kind, 'vp3');
    assert.equal(canvasSong.raw_audio_available, false);
    assert.deepEqual(canvasSong.audio_source, track.audio_source); assert.doesNotMatch(JSON.stringify(canvasSong), privatePattern);
    const canvasSource = expect(await client.request('/api/canvas-audio.php?action=stream&track_id=' + track.id), 200,
      'actual canvas stream action resolves the current provider identity');
    assert.doesNotMatch(JSON.stringify(canvasSource), privatePattern);
    expect(await client.request('/api/canvas-audio.php?action=source&track_id=' + track.id), 404,
      'canvas raw audio refuses provider recordings');
    expect(await client.request('/api/canvas-audio.php?action=stream&track_id=' + track.id + '&gallery_id=' + '0'.repeat(32)), 404,
      'canvas provider stream cannot bypass unavailable gallery context');
    assert.deepEqual(expect(await client.request(endpoint, approved), 200, 'exact import retry returns original receipt'), imported);
    assert.equal(readCatalog().length, 2, 'Import retry preserves one stable local identity');
    expect(await client.request(endpoint, {...approved, recording_ids: []}), 409, 'import request identifier cannot be repurposed');
    expect(await client.request('/admin/api/catalog.php', {id: track.id, track: {digital_purchase_enabled: true}}), 422, 'import cannot enable paid download');
    expect(await client.request('/admin/api/catalog.php', {id: track.id, track: {podEligible: true}}), 422, 'import cannot enable manufacturing');
    expect(await client.request('/admin/api/catalog.php', {id: track.id, track: {title: 'Artist editorial title', story: 'Artist editorial story'}}),
      200, 'actual catalog editor can customize imported presentation');
    const refreshed = expect(await client.request(endpoint, {action: 'catalog', offset: 0, search: ''}), 200, 'refresh obtains current selection revision');
    const reimport = importInput(refreshed, [identity.recording_id]);
    const refreshReceipt = expect(await client.request(endpoint, reimport), 200, 'reimport updates without duplicating identity');
    assert.equal(refreshReceipt.imported_count, 0); assert.equal(refreshReceipt.updated_count, 1);
    assert.equal(readCatalog()[1].id, track.id); assert.equal(readCatalog()[1].title, 'Artist editorial title');
    assert.equal(readCatalog()[1].story, 'Artist editorial story');
    // Fail the real second publication step, after canonical JSON is written.
    // This is a genuine filesystem interruption, not a substitute import path.
    const next = expect(await client.request(endpoint, {action: 'catalog', offset: 0, search: ''}), 200, 'recovery test reads current catalog');
    const interrupted = importInput(next, [identity.recording_id]);
    const publicFile = path.join(fixture.root, 'assets/js/catalog.js');
    fs.renameSync(publicFile, publicFile + '.before'); fs.mkdirSync(publicFile);
    const failed = expect(await client.request(endpoint, interrupted), 503, 'actual interrupted catalog publication remains recoverable');
    assert.equal(failed.pending.request_id, interrupted.request_id);
    const pendingStatus = expect(await client.request(endpoint), 200, 'pending publication survives a new HTTP request');
    assert.equal(pendingStatus.pending.request_id, interrupted.request_id);
    fs.rmdirSync(publicFile); fs.renameSync(publicFile + '.before', publicFile);
    const resumed = expect(await client.request(endpoint, {action: 'resume', request_id: interrupted.request_id,
      expected_revision: next.connection.revision}), 200, 'saved journal recovers real JSON to JS publication');
    assert.equal(resumed.status, 'complete'); assert.equal(readCatalog().length, 2);
    assert.ok(fs.readFileSync(publicFile, 'utf8').includes(track.id));
    assert.equal((await client.request(endpoint)).data.pending, null);
    const siteRevision = Number(fixture.provider.readDb('SELECT revision FROM music_provider_sites_v1 WHERE site_id=?', [registration.site.site_id])[0].revision);
    fixture.provider.ownerService({action: 'site.revoke', workspace_id: 1, site_id: registration.site.site_id,
      request_id: uuid(), expected_revision: siteRevision});
    const revoked = await client.request(endpoint, {action: 'catalog', offset: 0, search: ''});
    assert.ok([401, 403].includes(revoked.status), 'Actual provider revocation denies access');
    checks.push('real provider revocation makes checked connection unavailable');
    expect(await client.request('/api/vp3-music-source.php?track_id=' + track.id), 404, 'unavailable connection cannot resolve provider source');
    const disconnected = expect(await client.request(endpoint, {action: 'disconnect', request_id: uuid(),
      expected_revision: next.connection.revision}), 200, 'disconnect removes local connection without deleting songs');
    assert.equal(disconnected.remote_revoked, false); assert.equal(readCatalog().length, 2);
    expect(await client.request('/api/vp3-music-site.php'), 404, 'disconnect withdraws public verification proof');
    expect(await client.request('/api/vp3-music-source.php?track_id=' + track.id), 404, 'disconnect closes new provider resolution');
    const nativeAudio = await fetch(fixture.base + '/assets/audio/local.mp3');
    assert.equal(nativeAudio.status, 200); assert.ok((await nativeAudio.arrayBuffer()).byteLength > 1000);
    checks.push('existing local audio remains available through disconnect');
    const evidence = {mode: 'http', provider_revision: fixture.providerRevision, assertions: checks, passed: checks.length};
    process.stdout.write(JSON.stringify(evidence, null, 2) + '\n');
    return evidence;
  } catch (error) {
    error.message += '\nStoneward PHP log (no request bodies):\n' + fixture.logs.slice(-10000);
    throw error;
  } finally {await fixture.close();}
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await runHttpAcceptance();
  if (process.argv.includes('--http-only')) process.stdout.write('HTTP acceptance PASS; browser assertions intentionally not run.\n');
  else {
    const {runBrowserAcceptance} = await import('./vp3-music-browser.mjs');
    await runBrowserAcceptance();
  }
}
