import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {execFileSync, spawn} from 'node:child_process';
import {fileURLToPath, pathToFileURL} from 'node:url';

export const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const acceptedProviderRevision = '41603520b24c3ac6146dca5cb7310d04a8a3de9e';
export const artistOrigin = 'https://artist.music.example.com';
export const providerOrigin = 'https://vp3.music.example.com';
export const protocol = 'vp3_music_provider_v1';

/**
 * Real Stoneward controllers, authorization, encrypted connection/import state,
 * catalog writer, and the actual pinned VP3 PHP/SQLite provider. Only the
 * network transport from the disposable PHP process is adapted to its local
 * provider socket. The production URL checks and response validation still
 * run. No HTTP request can select that transport in the application.
 *
 * The browser uses actual HTTPS origins through a temporary reverse proxy.
 * A local CONNECT proxy changes the socket destination only; Origin, URL,
 * CORS, cookie and media rules remain active. The temporary certificate is
 * trusted only in the browser fixture. This is not a live DNS/TLS deployment.
 */
export async function musicIntegrationFixture() {
  const providerRepository = path.resolve(process.env.VP3_TEST_REPOSITORY
    || path.join(repository, '..', 'software-provider-reference'));
  const providerRevision = execFileSync('git', ['rev-parse', 'HEAD'], {
    cwd: providerRepository, encoding: 'utf8',
  }).trim();
  assert.equal(providerRevision, process.env.VP3_TEST_REVISION || acceptedProviderRevision,
    'Integration fixture must use the explicitly accepted provider revision');
  const {musicProviderFixture} = await import(pathToFileURL(path.join(providerRepository,
    'tests/music-provider-fixture-v1.mjs')).href);
  const php = process.env.SF_TEST_PHP || process.env.PHP_BINARY || 'php';
  process.env.PHP_BINARY = php;
  const provider = await musicProviderFixture();
  // Configure the disposable provider installation with public-shaped origins;
  // keep Stoneward's production reserved-DNS rejection fully active.
  for (const relative of ['includes/bootstrap.php', 'includes/music-provider-bootstrap-v1.php', 'test-config.php', 'config.php']) {
    const filename = path.join(provider.root, relative);
    fs.writeFileSync(filename, fs.readFileSync(filename, 'utf8').replaceAll('https://provider.example.test', providerOrigin));
  }
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'stoneward-vp3-music-'));
  let server, proxy, tunnel;
  const tunnelSockets = new Set();
  let logs = '';
  const traffic = [];
  const controls = {dropAction: null, holdAction: null, held: []};
  const close = async () => {
    for (const release of controls.held.splice(0)) release();
    for (const socket of tunnelSockets) socket.destroy();
    if (tunnel?.listening) await new Promise(resolve => tunnel.close(resolve));
    proxy?.closeAllConnections?.();
    if (proxy?.listening) await new Promise(resolve => proxy.close(resolve));
    if (server?.pid) {
      try {process.platform === 'linux' ? process.kill(-server.pid, 'SIGTERM') : server.kill();} catch {}
    }
    provider.close();
    fs.rmSync(root, {recursive: true, force: true});
  };
  try {
    for (const directory of ['api', 'admin', 'assets', 'config']) {
      fs.cpSync(path.join(repository, directory), path.join(root, directory), {recursive: true});
    }
    for (const entry of fs.readdirSync(repository, {withFileTypes: true})) {
      if (entry.isFile() && /\.php$/.test(entry.name)) fs.copyFileSync(path.join(repository, entry.name), path.join(root, entry.name));
    }
    for (const directory of ['storage', 'data', 'sessions', 'assets/audio']) fs.mkdirSync(path.join(root, directory), {recursive: true});
    fs.writeFileSync(path.join(root, 'config/database.php'), "<?php return ['driver'=>'sqlite','path'=>dirname(__DIR__).'/fixture.sqlite'];\n");
    fs.writeFileSync(path.join(root, '_transport.json'), JSON.stringify({provider_base: provider.base, provider_origin: providerOrigin}));
    const transport = `
function sf_vp3_music_runtime_context(): array {
    return ['transport'=>static function(string $url,string $method,?array $body,?string $bearer): array {
        $fixture=json_decode(file_get_contents(SF_ROOT.'/_transport.json'),true,512,JSON_THROW_ON_ERROR);
        $origin=$fixture['provider_origin'];
        if(!str_starts_with($url,$origin.'/')) throw new RuntimeException('Fixture rejected an unexpected provider destination.');
        $relative=substr($url,strlen($origin));
        $headers=['Accept: application/json','Host: '.parse_url($origin,PHP_URL_HOST)];
        if($bearer!==null&&$bearer!=='')$headers[]='Authorization: Bearer '.$bearer;
        $curl=curl_init($fixture['provider_base'].$relative);
        $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>10,
            CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers];
        if($body!==null){$headers[]='Content-Type: application/json';$options[CURLOPT_HTTPHEADER]=$headers;$options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR);}
        curl_setopt_array($curl,$options);
        $bytes=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$failed=$bytes===false;curl_close($curl);
        if($failed)throw new RuntimeException('Fixture provider socket unavailable.');
        $decoded=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        return ['status'=>$status,'body'=>$decoded];
    }];
}
`;
    const bootstrap = path.join(root, 'api/bootstrap.php');
    const originalBootstrap = fs.readFileSync(bootstrap, 'utf8');
    assert.ok(originalBootstrap.startsWith('<?php\ndeclare(strict_types=1);'), 'Known Stoneward fixture bootstrap boundary');
    fs.writeFileSync(bootstrap, originalBootstrap.replace('declare(strict_types=1);', 'declare(strict_types=1);\n' + transport));
    fs.writeFileSync(path.join(root, '_prepend.php'), "<?php $_SERVER['HTTPS']='on';\n");
    fs.writeFileSync(path.join(root, 'data/releases.json'), '[]');
    fs.writeFileSync(path.join(root, 'data/shows.json'), '[]');
    const native = {id: 'local-song', title: 'Existing local song', duration: 24, state: 'published',
      public_visible: true, release: 'Local release', audio: 'assets/audio/local.mp3',
      digital_purchase_enabled: false, podEligible: false};
    fs.writeFileSync(path.join(root, 'data/catalog.json'), JSON.stringify([native]));
    fs.writeFileSync(path.join(root, 'assets/js/catalog.js'), 'window.STONEFELLOW_CATALOG=' + JSON.stringify([native]) + ';\n');
    execFileSync('ffmpeg', ['-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=320:sample_rate=22050',
      '-t', '24', '-ac', '1', '-b:a', '32k', path.join(root, 'assets/audio/local.mp3')]);
    const run = (code, ...args) => execFileSync(php, ['-r',
      "define('SF_ROOT',$argv[1]);require SF_ROOT.'/api/bootstrap.php';" + code, root, ...args,
    ], {encoding: 'utf8', cwd: repository});
    run(`$pdo=sf_db();
      $pdo->exec('CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)');
      $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
      $q=$pdo->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
      foreach([[1,'artist@example.invalid','Artist','admin'],[2,'fan@example.invalid','Fan','user']] as $u)
        $q->execute([$u[0],$u[1],$u[2],password_hash('fixture-only-password',PASSWORD_DEFAULT),$u[3],'active',gmdate('c'),gmdate('c')]);
      sf_customer_lifecycle_ensure_schema();sf_ops_ensure_schema();sf_ai_meta_set('site.base_url',$argv[2]);`, artistOrigin);
    const socket = net.createServer();
    await new Promise((resolve, reject) => {socket.once('error', reject); socket.listen(0, '127.0.0.1', resolve);});
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    server = spawn(php, ['-d', 'display_errors=stderr', '-d', 'opcache.enable_cli=0', '-d',
      'session.save_path=' + path.join(root, 'sessions'), '-d', 'auto_prepend_file=' + path.join(root, '_prepend.php'),
      '-S', '127.0.0.1:' + port, '-t', root], {stdio: ['ignore', 'pipe', 'pipe'],
      detached: process.platform === 'linux',
      env: {...process.env, ...(process.platform === 'linux' ? {PHP_CLI_SERVER_WORKERS: '4'} : {})}});
    server.stdout.on('data', bytes => {logs += bytes;});
    server.stderr.on('data', bytes => {logs += bytes;});
    server.on('error', error => {logs += error.message;});
    const base = 'http://127.0.0.1:' + port;
    let ready = false;
    for (let attempt = 0; attempt < 80; attempt++) {
      try {await fetch(base + '/api/auth.php'); ready = true; break;}
      catch {if (server.exitCode !== null) break; await new Promise(resolve => setTimeout(resolve, 40));}
    }
    assert.ok(ready, 'Stoneward fixture failed to start: ' + logs);

    const key = path.join(root, '_tls-key.pem');
    const certificate = path.join(root, '_tls-cert.pem');
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '1',
      '-subj', '/CN=vp3.music.example.com', '-addext',
      'subjectAltName=DNS:vp3.music.example.com,DNS:artist.music.example.com,DNS:other.music.example.com',
      '-keyout', key, '-out', certificate], {stdio: 'ignore'});
    proxy = https.createServer({key: fs.readFileSync(key), cert: fs.readFileSync(certificate)}, (request, response) => {
      const hostname = (request.headers.host || '').split(':')[0];
      const target = hostname === 'vp3.music.example.com' ? new URL(provider.base)
        : hostname === 'artist.music.example.com' ? new URL(base) : null;
      if (!target) {response.writeHead(404); response.end('Unknown fixture origin'); return;}
      const chunks = [];
      request.on('data', chunk => chunks.push(chunk));
      request.on('end', () => {
        const bytes = Buffer.concat(chunks);
        let body = null;
        try {body = bytes.length ? JSON.parse(bytes.toString('utf8')) : null;} catch {}
        const pathname = new URL(request.url, 'https://' + hostname).pathname;
        const record = {hostname, method: request.method, pathname, action: body?.action || '',
          cookie: Boolean(request.headers.cookie), origin: request.headers.origin || '',
          referrer: Boolean(request.headers.referer), source_url: body?.source_url || '', source_surface: body?.source_surface || '',
          bearer_kind: /^Bearer (mps1_|vmp1_)/.exec(request.headers.authorization || '')?.[1] || '',
          body_sha256: crypto.createHash('sha256').update(bytes).digest('hex'),
          track_id: body?.track_id || '', event_type: body?.event_type || '',
          elapsed_ms: body?.elapsed_ms ?? null, cumulative_played_ms: body?.cumulative_played_ms ?? null,
          play_id: body?.play_id || '', event_id: body?.event_id || '', request_id: body?.request_id || '',
          session_id: body?.session_id || '', seq: body?.seq ?? null};
        traffic.push(record);
        const drop = controls.dropAction === record.action;
        const hold = controls.holdAction === record.action;
        if (drop) controls.dropAction = null;
        if (hold) controls.holdAction = null;
        const upstream = http.request({hostname: target.hostname, port: target.port,
          method: request.method, path: request.url, headers: request.headers}, incoming => {
          record.status = incoming.statusCode;
          if (drop || hold) {
            const output = [];
            incoming.on('data', chunk => output.push(chunk));
            incoming.on('end', () => {
              if (drop) {request.socket.destroy(); return;}
              controls.held.push(() => {
                if (!response.destroyed) {response.writeHead(incoming.statusCode, incoming.headers); response.end(Buffer.concat(output));}
              });
            });
          } else {response.writeHead(incoming.statusCode, incoming.headers); incoming.pipe(response);}
        });
        upstream.on('error', () => {if (!response.headersSent) response.writeHead(502); response.end('Fixture upstream unavailable.');});
        upstream.end(bytes);
      });
    });
    await new Promise((resolve, reject) => {proxy.once('error', reject); proxy.listen(0, '127.0.0.1', resolve);});
    const tlsPort = proxy.address().port;
    // CONNECT is supported by Chromium and WebKit; it preserves the public
    // HTTPS origin while changing only the destination of this test socket.
    tunnel = http.createServer((request, response) => {response.writeHead(405); response.end();});
    tunnel.on('connect', (request, client, head) => {
      if (!['vp3.music.example.com:443', 'artist.music.example.com:443', 'other.music.example.com:443'].includes(request.url)) {
        client.end('HTTP/1.1 403 Forbidden\r\n\r\n'); return;
      }
      const target = net.connect(tlsPort, '127.0.0.1', () => {
        client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
        if (head.length) target.write(head);
        client.pipe(target); target.pipe(client);
      });
      for (const socket of [client, target]) {
        tunnelSockets.add(socket); socket.on('close', () => tunnelSockets.delete(socket));
        socket.on('error', () => {client.destroy(); target.destroy();});
      }
    });
    await new Promise((resolve, reject) => {tunnel.once('error', reject); tunnel.listen(0, '127.0.0.1', resolve);});
    const browserProxy = {server: 'http://127.0.0.1:' + tunnel.address().port};
    const readDb = (sql, params = []) => JSON.parse(run('$q=sf_db()->prepare($argv[2]);$q->execute(json_decode($argv[3],true,512,JSON_THROW_ON_ERROR));echo json_encode($q->fetchAll(),JSON_THROW_ON_ERROR);', sql, JSON.stringify(params)));
    return {root, base, php, run, readDb, provider, native, traffic, controls, browserProxy, providerRevision,
      close, get logs() {return logs;}};
  } catch (error) {await close(); throw error;}
}

export function createRegistration(fixture, origin = artistOrigin) {
  const credential = 'vmp1_' + crypto.randomBytes(32).toString('hex');
  const created = fixture.provider.ownerService({action: 'site.create', workspace_id: 1,
    request_id: crypto.randomUUID(), origin, label: 'Stoneward integration acceptance', environment: 'production', credential});
  assert.equal(created.ok, true);
  return {credential, created, site: created.site};
}

export async function verifyActualProof(fixture, registration) {
  const response = await fetch(fixture.base + '/api/vp3-music-site.php');
  assert.equal(response.status, 200, 'Actual fixed Stoneward proof endpoint responds');
  const proof = await response.json();
  assert.deepEqual(proof, registration.site.verification.response,
    'Actual public proof is exactly the registered VP3 challenge');
  // The only substitution is the network fetch boundary: the actual fetched
  // Stoneward bytes enter VP3's production owner action/challenge comparison.
  const verified = JSON.parse(fixture.provider.run(
    '$site=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);$proof=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);' +
    '$input=["protocol"=>"vp3_music_provider_v1","action"=>"site.verify","workspace_id"=>$site["workspace_id"],"site_id"=>$site["site_id"],"expected_revision"=>$site["revision"],"request_id"=>$argv[4]];' +
    'echo json_encode(music_provider_v1_owner_action(db(),current_user(),$input,["verify_request"=>static fn()=>$proof]),JSON_THROW_ON_ERROR);',
    JSON.stringify(registration.site), JSON.stringify(proof), crypto.randomUUID()));
  assert.equal(verified.ok, true); assert.equal(verified.site.status, 'active');
  registration.site = verified.site;
  return verified;
}
