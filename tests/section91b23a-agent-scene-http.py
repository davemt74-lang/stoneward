"""Exercise the real listening room endpoint with isolated model and gateway fixtures."""
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from datetime import date, timedelta
import http.client
import http.cookiejar
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request
import wave


ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SF_TEST_PHP', 'php')
SCENE = '/api/agent-scene.php'
AUTH = '/api/auth.php'
PASSWORD = 'fixture-only-password'
# The first byte of é occupies byte 180, the old fallback's cutoff.
PUBLISHED_TITLE_PREFIX = 'Published song ' + 'a' * 164
PUBLISHED_TITLE = PUBLISHED_TITLE_PREFIX + 'é and more'
STARTING_BALANCE = 30000
MODEL_USAGE = 18
NDJSON = 'application/x-ndjson'
REVIEWED_CHORUS = 'Reviewed chorus fixture café and ember.'
OTHER_LYRIC_FIXTURES = (
    'Unselected approved verse fixture.',
    'Unpublished chorus draft fixture.',
    'Private chorus annotation fixture.',
    'Obsolete audio chorus fixture.',
)
ALBUM_REF = 'media-fixture-album'
ALBUM_WALL_REF = 'media-albums'
MERCH_REF = 'merch-hoodies'
SHOW_REF = 'show-locations'
TICKET_REF = 'price-ticket'
PRIVATE_ALBUM_REF = 'media-fixture-private-album'
ALBUM_TITLE = 'Stonefellow Fixture Album'
HOODIE_TITLE = 'Stonefellow Fixture Hoodies'


def check(ok, label):
    assert ok, label
    print('PASS', label, flush=True)


def browser():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
    )


def open_response(client, base, path, *, method='GET', data=None, csrf=None, accept='application/json'):
    headers = {'Accept': accept}
    if data is not None:
        headers['Content-Type'] = 'application/json'
        if not isinstance(data, bytes):
            data = json.dumps(data).encode()
    if csrf is not None:
        headers['X-CSRF-Token'] = csrf
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    try:
        return client.open(req, timeout=10)
    except urllib.error.HTTPError as error:
        return error


def raw_request(client, base, path, **options):
    with open_response(client, base, path, **options) as response:
        return response.status, response.headers, response.read()


def request(client, base, path, **options):
    status, headers, raw = raw_request(client, base, path, **options)
    assert headers.get_content_type() == 'application/json', (path, status, raw[:250])
    assert 'no-store' in headers.get('Cache-Control', '')
    try:
        body = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise AssertionError((path, status, raw[:250])) from error
    return status, headers, body


@contextmanager
def gateway_replacing_scene_response(upstream_port):
    upstream_responses = []

    class Gateway(BaseHTTPRequestHandler):
        def do_POST(self):
            body = self.rfile.read(int(self.headers['Content-Length']))
            upstream = http.client.HTTPConnection('127.0.0.1', upstream_port, timeout=10)
            try:
                headers = {name: self.headers[name] for name in ('Content-Type', 'Cookie', 'X-CSRF-Token')}
                upstream.request('POST', self.path, body, headers)
                response = upstream.getresponse()
                upstream_responses.append((response.status, response.read()))
            finally:
                upstream.close()
            self.send_response(502)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.end_headers()
            self.wfile.write(b'<html><title>Bad Gateway</title></html>')

        def log_message(self, *_):
            pass

    with ThreadingHTTPServer(('127.0.0.1', 0), Gateway) as gateway:
        thread = threading.Thread(target=gateway.serve_forever, daemon=True)
        thread.start()
        try:
            yield f'http://127.0.0.1:{gateway.server_port}', upstream_responses
        finally:
            gateway.shutdown()
            thread.join()


def sign_in(client, base, email):
    status, _, body = request(client, base, AUTH)
    assert status == 200 and not body['authenticated']
    status, _, body = request(client, base, AUTH, method='POST', csrf=body['csrf'],
                              data={'action': 'login', 'email': email, 'password': PASSWORD})
    assert status == 200 and body['authenticated'], body
    return body['csrf']


def install_model_transport(site):
    # Replace the HTTPS wrapper before PHP dispatches the unmodified endpoint.
    # With curl_init disabled and HTTP unregistered, PHP cannot reach a provider.
    (site / 'model-transport.php').write_text(r'''<?php
class FixtureModelTransport {
    public $context;
    private static int $attempt = 0;
    private string $response = '';
    private ?string $afterRelease = null;
    private bool $terminalStall = false;
    private bool $attemptTimeout = false;
    private bool $awaitCancellation = false;
    private int $offset = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool {
        $fixtureMode = trim(file_get_contents(__DIR__ . '/model-mode'));
        $options = stream_context_get_options($this->context);
        if (($options['http']['method'] ?? '') === 'GET' && str_starts_with($fixtureMode, 'model-catalog')) {
            $headers = $options['http']['header'];
            if ($options['http']['follow_location'] !== 0 || $options['http']['timeout'] > 8) return false;
            if ($path === 'https://api.openai.com/v1/models' && str_contains($headers, 'Authorization: Bearer fixture-key')) {
                $payload = $fixtureMode === 'model-catalog-error' ? ['error'=>['message'=>'fixture-upstream-private-error']] : ['data'=>[
                    ['id'=>'fixture-openai-discovered'], ['id'=>'text-embedding-3-small'], ['id'=>'gpt-4o-mini-transcribe'], ['id'=>'bad model id'],
                ]];
            } elseif (str_contains($headers, 'x-api-key: fixture-canvas-secret') && str_contains($headers, 'anthropic-version: 2023-06-01')) {
                $payload = match ($path) {
                    'https://api.anthropic.com/v1/models?limit=100' => ['data'=>[
                        ['id'=>'fixture-claude-first','display_name'=>'First Claude'],
                    ], 'has_more'=>true, 'last_id'=>'fixture-claude-first'],
                    'https://api.anthropic.com/v1/models?limit=100&after_id=fixture-claude-first' => ['data'=>[
                        ['id'=>'fixture-claude-second','display_name'=>'Second Claude'],
                    ], 'has_more'=>false],
                    default => null,
                };
                if ($payload === null) return false;
            } else return false;
            file_put_contents(__DIR__ . '/catalog-calls', $path . "\n", FILE_APPEND | LOCK_EX);
            $this->response = json_encode($payload, JSON_THROW_ON_ERROR);
            return true;
        }
        $anthropic = $path === 'https://api.anthropic.com/v1/messages';
        if ($path !== 'https://api.openai.com/v1/responses' && !($anthropic && in_array($fixtureMode, ['stream-fallback', 'stream-coding-choice'], true))) return false;
        file_put_contents(__DIR__ . '/model-calls', $fixtureMode . "\n", FILE_APPEND | LOCK_EX);
        if ($fixtureMode === 'unavailable') return false;
        if ($fixtureMode === 'wait' && !$this->waitFor('model-release')) return false;
        $requestJson = $options['http']['content'] ?? '';
        file_put_contents(__DIR__ . '/model-request.json', $requestJson);
        $html = in_array($fixtureMode, ['invalid', 'stream-invalid'], true)
            ? '<script>unsafe()</script>' : '<article><h1>{{title}}</h1></article>';
        if ($fixtureMode === 'valid') $html = '<article class="fixture-canvas"></article>';
        $design = ['html' => $html, 'css' => 'article{color:tan}'];
        if ($fixtureMode === 'stream-custom') {
            $design = ['concept' => 'a pulsing EQ background', 'title' => 'Amber Observatory',
                'html' => '<article class="amber-observatory"></article>'] + $design;
        }
        if ($fixtureMode === 'stream-terminal-stall') {
            $design = [
                'concept' => 'a space theme EQ with star clusters that pulse with the music',
                'html' => '<article><h1>Star Clusters</h1></article>',
                'css' => 'article{background:radial-gradient(circle,#82c9ff,#090c2a);opacity:calc(.4 + var(--sf-audio-bass, 0) * .6)}',
            ];
        }
        if ($fixtureMode === 'catalog-choose') {
            $design['catalog_refs'] = ['media-fixture-album', 'media-fixture-private-album', 'media-model-invented'];
        }
        if ($fixtureMode === 'visual-only') {
            $request = json_decode($requestJson, true);
            $prompt = json_decode($request['input'][0]['content'][0]['text'], true);
            $brief = $prompt['design_brief'];
            $echoed = htmlspecialchars($brief, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $design = ['title' => $brief,
                'html' => '<article><h1>' . $echoed . '</h1><p>{{canvas_title}}</p><div class="stars"></div></article>',
                'catalog_refs' => ['media-fixture-album']] + $design;
        }
        if ($fixtureMode === 'visual-headline') {
            $design = ['title' => 'Star City', 'html' => '<article><h1>STAR CITY</h1></article>'] + $design;
        }
        if ($fixtureMode === 'canvas-data-tools') {
            $design = ['title'=>'Listener view',
                'html'=>'<article><ol class="sf-dataset-personal_listening_stats"><li class="sf-data-row">'
                    .'<strong class="sf-field-title"></strong><span class="sf-field-listens"></span></li></ol>'
                    .'<ol class="sf-dataset-personal_listening_stats sf-current-track"><li class="sf-data-row">'
                    .'<strong class="sf-field-title"></strong><span class="sf-field-completes"></span></li></ol>'
                    .'<div class="sf-dataset-published_catalog sf-current-track"><div class="sf-data-row"><span class="sf-field-title"></span></div></div>'
                    .'<section class="sf-dataset-published_merch sf-filter-category--apparel"><div class="sf-data-row">'
                    .'<span class="sf-field-title"></span><small class="sf-field-price_text"></small></div></section>'
                    .'<section class="sf-dataset-published_ticket_offers"><div class="sf-data-row"><span class="sf-field-title"></span></div></section>'
                    .'<ul class="sf-dataset-my_purchases"><li class="sf-data-row"><span class="sf-field-item_label"></span></li></ul>'
                    .'<ul class="sf-dataset-my_tickets"><li class="sf-data-row"><span class="sf-field-offer_title"></span></li></ul>'
                    .'<div class="sf-dataset-admin_knowledge_inventory"><div class="sf-data-row"></div></div></article>',
                'css'=>'ol{display:grid;gap:.5rem}li{opacity:clamp(.2,calc(var(--sf-value-listens) / 10),1)}'];
        }
        if ($fixtureMode === 'quality-player') {
            $design = ['title' => 'Night Drive',
                'html' => '<article class="radio"><small>A boom box player with audio controls and pulsing speakers</small>' .
                    '<h1>A boom box player <span>with audio controls</span> and pulsing speakers</h1>' .
                    '<div class="cabinet"><div class="speaker"></div><div class="sf-host-playback"></div></div>' .
                    '<footer>Playback controls are provided by the host</footer></article>',
                'css' => '.cabinet{display:grid}.speaker{scale:calc(1 + var(--sf-audio-bass,0) * .2)}' .
                    '.radio::before{content:"Playback controls are provided by the host"}' .
                    '@media(max-width:640px){.cabinet{max-width:90vw}}'];
        }
        $code = json_encode($design, JSON_THROW_ON_ERROR);
        $usage = ['input_tokens' => 11, 'output_tokens' => 7];
        if (str_starts_with($fixtureMode, 'stream-')) {
            $request = json_decode($requestJson, true);
            if (($request['stream'] ?? false) !== true) return false;
            $delta = 'response.output_text.delta';
            if ($fixtureMode === 'stream-full-first') {
                file_put_contents(__DIR__ . '/model-full-first.json', $requestJson);
                if (($request['max_output_tokens'] ?? 0) < 8192) {
                    $this->response = $this->event('response.incomplete', ['response' => [
                        'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
                    ]]);
                    return true;
                }
            }
            if ($fixtureMode === 'stream-cancel') {
                $this->response = $this->event($delta, ['delta'=>'{"concept":"a new revision","html":"<article>']);
                $this->response .= ':'.str_repeat(' ', 8189-strlen($this->response))."\n\n";
                $this->awaitCancellation = true;
                return true;
            }
            if (in_array($fixtureMode, ['stream-timeout', 'stream-timeout-twice'], true)) {
                $attempt = ++self::$attempt;
                file_put_contents(__DIR__ . '/model-retry-request-' . $attempt . '.json', $requestJson);
                if ($attempt === 1 || $fixtureMode === 'stream-timeout-twice') {
                    $this->response = $this->event($delta, ['delta' => '{"concept":"a first pass","html":"<article>']);
                    $this->response .= ':'.str_repeat(' ', 8189-strlen($this->response))."\n\n";
                    $this->attemptTimeout = true;
                    return true;
                }
                $code = json_encode(['concept'=>'a compact cassette deck', 'title'=>'Compact Night Drive',
                    'html'=>'<article class="deck"><div class="sf-host-playback"></div></article>',
                    'css'=>'.deck{display:grid}@media(max-width:640px){.deck{width:90vw}}'], JSON_THROW_ON_ERROR);
            }
            if (in_array($fixtureMode, ['stream-incomplete', 'stream-eof', 'stream-incomplete-twice', 'stream-fallback', 'stream-compact'], true)) {
                $attempt = ++self::$attempt;
                file_put_contents(__DIR__ . '/model-retry-request-' . $attempt . '.json', $requestJson);
                $budget = $request[$anthropic ? 'max_tokens' : 'max_output_tokens'] ?? 0;
                $needsMoreOutput = in_array($fixtureMode, ['stream-incomplete', 'stream-fallback'], true) && $budget <= 4096;
                $needsCompactDesign = $fixtureMode === 'stream-compact' && !str_contains($request['instructions'] ?? '', 'smallest complete design');
                if ($attempt === 1 || $fixtureMode === 'stream-incomplete-twice' || $needsMoreOutput || $needsCompactDesign) {
                    $partial = '{"concept":"an unfinished first pass","html":"<article>';
                    if ($anthropic) {
                        $this->response = $this->event('message_start', ['message' => ['usage' => ['input_tokens' => 11]]]) .
                            $this->event('content_block_delta', ['delta' => ['type' => 'text_delta', 'text' => $partial]]) .
                            $this->event('message_delta', ['delta' => ['stop_reason' => 'max_tokens'], 'usage' => ['output_tokens' => $budget]]);
                    } else {
                        $this->response = $this->event($delta, ['delta' => $partial]);
                        if ($fixtureMode !== 'stream-eof') {
                            $this->response .= $this->event('response.incomplete', ['response' => [
                                'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
                            ]]);
                        }
                    }
                    return true;
                }
                $code = json_encode([
                    'concept' => 'a multicolor star cluster EQ for Earth Girl',
                    'html' => '<article class="star-clusters"><h1>Earth Girl Star Clusters</h1></article>',
                    'css' => 'article{background:radial-gradient(circle,#8b5cf6,#091627);color:#d5f5ff}',
                ], JSON_THROW_ON_ERROR);
            }
            if ($anthropic) {
                $this->response = $this->event('message_start', ['message' => ['usage' => ['input_tokens' => 11]]]) .
                    $this->event('content_block_delta', ['delta' => ['type' => 'text_delta', 'text' => $code]]) .
                    $this->event('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 7]]) .
                    $this->event('message_stop', []);
                return true;
            }
            $this->response = $this->event($delta, ['delta' => substr($code, 0, 13)]);
            $rest = $this->event($delta, ['delta' => substr($code, 13)]) .
                $this->event('response.completed', ['response' => ['status' => 'completed', 'usage' => $usage]]);
            if ($fixtureMode === 'stream-wait') {
                // Pad the first upstream chunk so native stream reads can return it without blocking for the tail.
                $this->response .= str_repeat(": fixture keepalive\n\n", 400);
                $this->afterRelease = $rest;
            } else {
                $this->response .= $rest;
            }
            if ($fixtureMode === 'stream-terminal-stall') {
                // Ensure a full upstream read can deliver the terminal event before the blocked tail.
                $this->response .= str_repeat(": fixture keepalive\n\n", 400);
                $this->terminalStall = true;
            }
        } else {
            $this->response = json_encode(['output_text' => $code, 'usage' => $usage], JSON_THROW_ON_ERROR);
        }
        return true;
    }

    private function event(string $type, array $payload): string {
        return "event: $type\ndata: " . json_encode(['type' => $type] + $payload, JSON_THROW_ON_ERROR) . "\n\n";
    }

    private function waitFor(string $file): bool {
        $path = __DIR__ . '/' . $file;
        $deadline = microtime(true) + 5;
        while (!file_exists($path) && microtime(true) < $deadline) usleep(10000);
        return file_exists($path);
    }

    public function stream_stat(): array { return ['size' => strlen($this->response)]; }
    public function stream_set_option($option, $arg1, $arg2): bool { return true; }
    public function stream_read($count): string {
        if ($this->offset >= strlen($this->response) && $this->awaitCancellation) {
            usleep(50000);
            return ':'.str_repeat(' ', $count-3)."\n\n";
        }
        if ($this->offset >= strlen($this->response) && $this->attemptTimeout)
            throw new SfAgentSceneStreamInterrupted('timeout');
        if ($this->offset >= strlen($this->response) && $this->terminalStall) {
            touch(__DIR__ . '/model-read-after-terminal');
            $this->waitFor('model-terminal-release');
            $this->terminalStall = false;
        }
        if ($this->offset >= strlen($this->response) && $this->afterRelease !== null) {
            if ($this->waitFor('model-stream-release')) $this->response .= $this->afterRelease;
            $this->afterRelease = null;
        }
        $chunk = substr($this->response, $this->offset, $count);
        $this->offset += strlen($chunk);
        return $chunk;
    }
    public function stream_eof(): bool {
        return !$this->awaitCancellation && !$this->attemptTimeout && !$this->terminalStall && $this->afterRelease === null && $this->offset >= strlen($this->response);
    }
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');
stream_wrapper_register('https', FixtureModelTransport::class);
''')


def enable_model(site):
    balance = subprocess.check_output([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_ai_save_provider('openai', ['enabled'=>true, 'api_key'=>'fixture-key', 'model'=>'fixture-model']);
        sf_enroll_user(1, null);
        echo sf_token_adjust(1, (int)$argv[1] - sf_token_balance(1), 'admin_grant', 'fixture-balance');
    ''', str(STARTING_BALANCE)], cwd=site, text=True)
    assert int(balance) == STARTING_BALANCE, balance


def seed_saved_lyrics(site):
    uuid = 'M' + 'A' * 20
    audio = site / 'storage/media/fixture.wav'
    audio.parent.mkdir()
    with wave.open(str(audio), 'wb') as wav:
        wav.setparams((1, 2, 8000, 0, 'NONE', 'not compressed'))
        wav.writeframes(b'\0\0' * 8000 * 12)
    catalog_path = site / 'data/catalog.json'
    catalog = json.loads(catalog_path.read_text())
    catalog[0]['audio'] = 'api/media.php?id=' + uuid
    catalog_path.write_text(json.dumps(catalog))
    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        require_once 'api/song-moments-core.php';
        sf_media_ensure_schema();
        sf_song_moments_schema();
        $db=sf_db();
        $now=gmdate('c');
        $path='storage/media/fixture.wav';
        $db->prepare("INSERT INTO media_assets
            (uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,duration_seconds,created_at,updated_at)
            VALUES(?,'audio','audio/wav','wav','fixture.wav',?,?,?,12,?,?)")
            ->execute([$argv[1],$path,hash_file('sha256',$path),filesize($path),$now,$now]);
        sf_media_attach((int)$db->lastInsertId(),'track','fixture-song','primary_audio',['public_visible'=>true]);
        $fingerprint=sf_song_moments_audio_fingerprint(sf_song_moments_track('fixture-song'));
        if ($fingerprint==='') throw new RuntimeException('Fixture media is not public.');
        $insert=$db->prepare("INSERT INTO song_timeline_moments
            (track_id,kind,title,body,at_ms,end_ms,status,lyric_state,source_annotation_id,source_fingerprint,created_at,updated_at)
            VALUES('fixture-song','lyric',?,?,?,?,?,?,?,?,?,?)");
        foreach ([
            ['Chorus',$argv[4],100,1000,'draft','unverified',0,$fingerprint],
            ['Chorus',$argv[5],150,1100,'published','approved',7,$fingerprint],
            ['Chorus',$argv[6],200,1200,'published','approved',0,str_repeat('b',64)],
            ['Verse',$argv[3],1000,2000,'published','approved',0,$fingerprint],
            ['Chorus',$argv[2],2200,4200,'published','approved',0,$fingerprint],
        ] as $row) $insert->execute([...$row,$now,$now]);
    ''', uuid, REVIEWED_CHORUS, *OTHER_LYRIC_FIXTURES], cwd=site, check=True)


def seed_shortcode_catalog(site):
    releases = [
        {'id': 'fixture-album', 'title': ALBUM_TITLE, 'type': 'album', 'state': 'published',
         'track_ids': ['album-second', 'album-hidden', 'album-first'], 'artwork': 'assets/images/fixture-album.png'},
        {'id': 'fixture-private-album', 'title': 'Private unreleased album fixture', 'type': 'album',
         'state': 'draft', 'track_ids': ['album-private-cut']},
    ]
    (site / 'data/releases.json').write_text(json.dumps(releases))
    catalog_path = site / 'data/catalog.json'
    catalog = json.loads(catalog_path.read_text())
    catalog.extend([
        {'id': 'album-first', 'title': 'Fixture Dawn', 'release_id': 'fixture-album', 'duration': 123,
         'audio': 'audio/fixture-dawn.mp3', 'state': 'published'},
        {'id': 'album-second', 'title': 'Fixture Dusk', 'release_id': 'fixture-album', 'duration': 112,
         'audio': 'audio/fixture-dusk.mp3', 'state': 'published'},
        {'id': 'album-hidden', 'title': 'Hidden track fixture', 'release_id': 'fixture-album', 'state': 'private'},
        {'id': 'album-private-cut', 'title': 'Unreleased album song fixture', 'release_id': 'fixture-private-album',
         'audio': 'audio/fixture-private.mp3', 'state': 'published'},
    ])
    catalog_path.write_text(json.dumps(catalog))
    show_date = (date.today() + timedelta(days=30)).isoformat()
    (site / 'data/shows.json').write_text(json.dumps([
        {'id': 'fixture-show', 'title': 'Stonefellow Fixture Show', 'venue': 'Fixture Harbor Hall',
         'city': 'Fixture Harbor', 'date': show_date, 'status': 'scheduled', 'public_visible': True},
        {'id': 'fixture-private-show', 'venue': 'Private venue fixture', 'city': 'Private city fixture',
         'date': show_date, 'status': 'scheduled', 'public_visible': False},
    ]))
    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_commerce_save_product(['id'=>'fixture-hoodies','title'=>$argv[1],'category'=>'hoodies','tags'=>['hoodie'],
            'status'=>'active','base_price_cents'=>2900,'inventory_mode'=>'finite','inventory_qty'=>3,
            'metadata'=>['private_note'=>'Fixture internal product cost']], 0);
        sf_commerce_save_product(['id'=>'fixture-private-hoodies','title'=>'Private hoodie draft fixture','category'=>'hoodies',
            'status'=>'draft','base_price_cents'=>9900], 0);
        sf_ticket_offer_save(['show_id'=>'fixture-show','title'=>'Fixture general tickets','offer_type'=>'ticket',
            'status'=>'published','fulfillment_type'=>'external','external_url'=>'https://example.invalid/fixture-tickets',
            'price_cents'=>1800,'capacity'=>25], 0);
        sf_ticket_offer_save(['show_id'=>'fixture-show','title'=>'Fixture balcony tickets','offer_type'=>'ticket',
            'status'=>'published','fulfillment_type'=>'external','external_url'=>'https://example.invalid/fixture-tickets',
            'price_cents'=>2600,'capacity'=>10], 0);
        sf_ticket_offer_save(['show_id'=>'fixture-private-show','title'=>'Private ticket fixture','offer_type'=>'ticket',
            'status'=>'published','fulfillment_type'=>'external','external_url'=>'https://example.invalid/private-tickets',
            'price_cents'=>8800], 0);
    ''', HOODIE_TITLE], cwd=site, check=True)


@contextmanager
def php_server(site, log_name):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (site / log_name).open('w+') as log:
        server = subprocess.Popen(
            [PHP, '-d', f'session.save_path={site / "sessions"}', '-d', 'display_errors=1',
             '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_init,mb_substr',
             '-d', f'auto_prepend_file={site / "model-transport.php"}',
             '-S', f'127.0.0.1:{port}', '-t', str(site)], stdout=log, stderr=log
        )
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError('Fixture server did not start')
            yield f'http://127.0.0.1:{port}', port
        except Exception:
            log.seek(0)
            print(log.read())
            raise
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()


def assert_settled_balance(site, scene_count, balance, calls, paid_calls=1):
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        assert db.execute('SELECT balance FROM user_token_balances WHERE user_id=1').fetchone() == (balance,)
        assert db.execute("SELECT COUNT(*) FROM ai_token_reservations WHERE user_id=1 AND status='held'").fetchone() == (0,)
        assert db.execute('SELECT COUNT(*) FROM agent_scene_history WHERE user_id=1').fetchone() == (scene_count,)
        charges = db.execute("SELECT delta,input_tokens,output_tokens FROM token_ledger WHERE user_id=1 AND entry_type='usage'").fetchall()
        assert charges == [(-MODEL_USAGE, 11, 7)] * paid_calls, charges
    assert (site / 'model-calls').read_text().splitlines() == calls


def exercise_gateway_recovery(site, base, port, first, second, csrf, data):
    request_id = 'a' * 32
    payload = {**data, 'request_id': request_id}
    recovery_path = SCENE + '?request_id=' + request_id
    (site / 'model-mode').write_text('wait')
    # A second PHP worker can serve the recovery read while the first awaits the model.
    with (
        php_server(site, 'poll-server.log') as (poll_base, _),
        gateway_replacing_scene_response(port) as (gateway_base, upstream),
        ThreadPoolExecutor(max_workers=1) as executor,
    ):
        create = executor.submit(raw_request, first, gateway_base, SCENE, method='POST', data=payload, csrf=csrf)
        try:
            for _ in range(100):
                if (site / 'model-calls').exists():
                    break
                if create.done():
                    raise AssertionError(('create returned before calling model', create.result(), upstream))
                time.sleep(.025)
            else:
                raise AssertionError('model transport was never reached')
            status, _, pending = request(first, poll_base, recovery_path)
            check(status == 200 and pending.get('state') == 'pending' and 'scene' not in pending,
                  'owner can check an accepted request while the provider is still working')
            status, _, pending = request(first, poll_base, SCENE, method='POST', data=payload, csrf=csrf)
            check(status == 202 and pending.get('state') == 'pending' and 'scene' not in pending,
                  'a simultaneous duplicate sees pending without starting the provider again')
        finally:
            (site / 'model-release').touch()
        status, headers, raw = create.result()
        check(len(upstream) == 1 and upstream[0][0] == 201 and status == 502
              and headers.get_content_type() == 'text/html' and b'Bad Gateway' in raw,
              'intermediary replaces a committed PHP 201 with an HTML 502')
        committed = json.loads(upstream[0][1])

    status, _, result = request(first, base, recovery_path)
    check(status == 200 and result.get('state') == 'ready' and result.get('active_tokens_used') == MODEL_USAGE
          and result.get('active_tokens_remaining') == STARTING_BALANCE - MODEL_USAGE
          and result['scene'] == committed['scene'] and result['scene']['track_id'] == data['track_id']
          and PUBLISHED_TITLE[:180] in result['scene']['html'],
          'exact request lookup recovers the committed room and its token receipt')
    status, _, duplicate = request(first, base, SCENE, method='POST', data=payload, csrf=csrf)
    check(status == 200 and duplicate == result,
          'an exact duplicate returns the original result and receipt')
    status, _, conflict = request(first, base, SCENE, method='POST', data={**payload, 'brief': 'A different brief'}, csrf=csrf)
    check(status == 422 and conflict.get('error') == 'invalid_scene_request',
          'the same request ID cannot be reused for a different brief')
    status, _, denied = request(second, base, recovery_path)
    check(status == 404 and 'scene' not in denied, 'another account cannot recover the original request')
    assert_settled_balance(site, 2, STARTING_BALANCE - MODEL_USAGE, ['wait'])
    print('PASS gateway loss, polling and duplicate submission use one provider call and one charge', flush=True)
    return result['scene']['id']


def exercise_standard_room(site, base, first, csrf, data):
    for request_id, mode in [('b' * 32, 'invalid'), ('c' * 32, 'unavailable')]:
        (site / 'model-mode').write_text(mode)
        status, _, room = request(first, base, SCENE, method='POST', data={**data, 'request_id': request_id}, csrf=csrf)
        check(status == 201 and room.get('design_source') == 'standard' and room.get('active_tokens_used') == 0
              and room.get('active_tokens_remaining') == STARTING_BALANCE - MODEL_USAGE
              and room['scene']['track_id'] == data['track_id'] and '<script>' not in room['scene']['html'],
              f'{mode} model attempt returns a saved standard room and restores the hold')
        status, _, recovered = request(first, base, SCENE + '?request_id=' + request_id)
        check(status == 200 and recovered.get('state') == 'ready' and recovered.get('design_source') == 'standard'
              and recovered.get('active_tokens_used') == 0 and recovered['scene']['id'] == room['scene']['id'],
              f'{mode} model fallback is recoverable by its request ID')
        status, _, restored = request(first, base, SCENE + '?id=' + str(room['scene']['id']))
        check(status == 200 and restored.get('design_source') == 'standard',
              f'{mode} model fallback keeps its design label on reopen')
    assert_settled_balance(site, 4, STARTING_BALANCE - MODEL_USAGE, ['wait', 'invalid', 'unavailable'])
    print('PASS neither standard room debits Active Tokens or leaves a hold', flush=True)


def exercise_failed_revision(site, base, first, csrf, data, original_id):
    request_id = 'd' * 32
    payload = {**data, 'request_id': request_id, 'previous_id': original_id}
    (site / 'model-mode').write_text('invalid')
    status, _, body = request(first, base, SCENE, method='POST', data=payload, csrf=csrf)
    check(status == 422 and body.get('error') == 'invalid_scene_output',
          'invalid generated revision returns a readable error and keeps the original room')
    status, _, body = request(first, base, SCENE + '?request_id=' + request_id)
    check(status == 200 and body.get('state') == 'failed' and 'scene' not in body,
          'owner can confirm the accepted revision failed')
    status, _, body = request(first, base, SCENE, method='POST', data=payload, csrf=csrf)
    check(status == 200 and body.get('state') == 'failed' and 'scene' not in body,
          'replay of a failed revision does not call the model again')
    assert_settled_balance(site, 4, STARTING_BALANCE - MODEL_USAGE, ['wait', 'invalid', 'unavailable', 'invalid'])


def stream_event(response):
    line = response.readline()
    assert line, 'Expected another listening-room event'
    return json.loads(line)


def exercise_stream(site, base, first, csrf, data, original_id):
    request_id = 'e' * 32
    payload = {**data, 'request_id': request_id}
    recovery_path = SCENE + '?request_id=' + request_id
    (site / 'model-mode').write_text('stream-wait')
    with php_server(site, 'stream-poll-server.log') as (poll_base, _):
        with open_response(first, base, SCENE, method='POST', data=payload, csrf=csrf, accept=NDJSON) as response:
            check(response.status == 200 and response.headers.get_content_type() == NDJSON
                  and 'no-store' in response.headers.get('Cache-Control', ''),
                  'streaming create returns private NDJSON as soon as source arrives')
            try:
                first_delta = stream_event(response)
                check(first_delta == {'type': 'delta', 'text': '{"html":"<art'},
                      'first raw source delta reaches HTTP client before provider completion')
                status, _, pending = request(first, poll_base, recovery_path)
                status_history, _, history = request(first, poll_base, SCENE)
                check(status == 200 and pending.get('state') == 'pending' and 'scene' not in pending
                      and status_history == 200 and len(history['scenes']) == 4,
                      'partial source is not a saved room and private status remains pending')
            finally:
                (site / 'model-stream-release').touch()
            next_delta = stream_event(response)
            result = stream_event(response)
            source = json.loads(first_delta['text'] + next_delta['text'])
            check(next_delta['type'] == 'delta' and source['html'] == '<article><h1>{{title}}</h1></article>'
                  and source['css'] == 'article{color:tan}' and not response.readline(),
                  'source text deltas arrive in order before one final event')
    room = result['data']
    check(result['type'] == 'result' and result['status'] == 201 and room.get('ok')
          and room.get('design_source') == 'model' and room.get('active_tokens_used') == MODEL_USAGE
          and room.get('active_tokens_remaining') == STARTING_BALANCE - 2 * MODEL_USAGE
          and PUBLISHED_TITLE[:180] in room['scene']['html'],
          'terminal streamed result contains the validated room and settled receipt')
    status, _, recovered = request(first, base, recovery_path)
    check(status == 200 and recovered.get('state') == 'ready'
          and {key: recovered[key] for key in room} == room,
          'private request recovery returns the exact streamed room and receipt')
    status, _, replayed = request(first, base, SCENE, method='POST', data=payload, csrf=csrf, accept=NDJSON)
    check(status == 200 and replayed == recovered,
          'repeating a completed stream request returns the original receipt without another stream')

    (site / 'model-mode').write_text('stream-invalid')
    request_id = 'f' * 32
    with open_response(first, base, SCENE, method='POST', data={**data, 'request_id': request_id},
                       csrf=csrf, accept=NDJSON) as response:
        check(response.headers.get_content_type() == NDJSON, 'invalid streamed source still ends with a typed event')
        events = [json.loads(line) for line in response]
    final = events[-1]
    source = json.loads(''.join(event['text'] for event in events[:-1]))
    check(all(event['type'] == 'delta' for event in events[:-1]) and '<script>' in source['html']
          and final['type'] == 'result' and final['status'] == 201 and final['data']['design_source'] == 'standard'
          and final['data']['active_tokens_used'] == 0 and '<script>' not in final['data']['scene']['html'],
          'unsafe partial source never becomes a room and final fallback is free')
    status, _, recovered = request(first, base, SCENE + '?request_id=' + request_id)
    check(status == 200 and recovered.get('state') == 'ready' and recovered['design_source'] == 'standard'
          and recovered['scene'] == final['data']['scene'], 'standard streamed room can be recovered after delivery loss')

    request_id = '0' * 32
    payload = {**data, 'request_id': request_id, 'previous_id': original_id}
    with open_response(first, base, SCENE, method='POST', data=payload, csrf=csrf, accept=NDJSON) as response:
        events = [json.loads(line) for line in response]
    final = events[-1]
    check(final['type'] == 'error' and final['status'] == 422 and final['data']['error'] == 'invalid_scene_output',
          'invalid streamed revision terminates with a typed 422 error')
    status, _, failed = request(first, base, SCENE + '?request_id=' + request_id)
    check(status == 200 and failed.get('state') == 'failed' and 'scene' not in failed,
          'failed streamed revision remains failed in private status')
    assert_settled_balance(site, 6, STARTING_BALANCE - 2 * MODEL_USAGE,
                           ['wait', 'invalid', 'unavailable', 'invalid', 'stream-wait', 'stream-invalid', 'stream-invalid'],
                           paid_calls=2)


def exercise_stream_disconnect(site, base, first, csrf, data):
    request_id = '1' * 32
    release = site / 'model-stream-release'
    release.unlink()
    (site / 'model-mode').write_text('stream-wait')
    try:
        with open_response(first, base, SCENE, method='POST', data={**data, 'request_id': request_id},
                           csrf=csrf, accept=NDJSON) as response:
            assert response.headers.get_content_type() == NDJSON and stream_event(response)['type'] == 'delta'
            # Close the client before the provider's remaining source and usage can arrive.
    finally:
        release.touch()
    status, _, recovered = request(first, base, SCENE + '?request_id=' + request_id)
    check(status == 200 and recovered.get('state') == 'ready' and recovered['design_source'] == 'model'
          and recovered['active_tokens_used'] == MODEL_USAGE,
          'disconnect after a source delta leaves one recoverable, settled room')
    assert_settled_balance(site, 7, STARTING_BALANCE - 3 * MODEL_USAGE,
                           ['wait', 'invalid', 'unavailable', 'invalid', 'stream-wait', 'stream-invalid', 'stream-invalid', 'stream-wait'],
                           paid_calls=3)


def exercise_canvas_builds(site, base, first, second, csrf, track_id):
    model_calls = (site / 'model-calls').read_text().splitlines()
    balance = STARTING_BALANCE - 3 * MODEL_USAGE
    eq_payload = {'canvas_kind': 'space_eq', 'track_id': track_id, 'request_id': '2' * 32}
    # Even checking provider availability must be unnecessary for the trusted canvas.
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        db.execute("UPDATE ai_provider_settings SET enabled=0 WHERE provider='openai'")
    try:
        status, _, built = request(first, base, SCENE, method='POST', data=eq_payload, csrf=csrf, accept=NDJSON)
    finally:
        with sqlite3.connect(site / 'fixture.sqlite') as db:
            db.execute("UPDATE ai_provider_settings SET enabled=1 WHERE provider='openai'")
    eq = built['scene']
    check(status == 201 and built['design_source'] == 'standard' and built['active_tokens_used'] == 0
          and eq['canvas_kind'] == 'space_eq' and eq['track_id'] == track_id and eq['title'].startswith('Space EQ'),
          'Space EQ builds without an enabled model or a token debit')
    status, _, recovered = request(first, base, SCENE + '?request_id=' + eq_payload['request_id'])
    check(status == 200 and recovered.get('state') == 'ready' and recovered['scene'] == eq,
          'procedural canvas reopens through private request recovery')
    for invalid_track in ('missing-song', 'private-song'):
        status, _, error = request(first, base, SCENE, method='POST', csrf=csrf,
                                   data={'canvas_kind': 'space_eq', 'track_id': invalid_track})
        check(status == 422 and error.get('error') == 'invalid_scene_request',
              f'Space EQ rejects track {invalid_track}')
    status, _, error = request(first, base, SCENE, method='POST', csrf=csrf,
                               data={**eq_payload, 'canvas_kind': 'arbitrary', 'brief': 'An invalid kind'})
    check(status == 422 and error.get('error') == 'invalid_scene_request', 'canvas kinds are limited to supported values')
    assert_settled_balance(site, 8, balance, model_calls, paid_calls=3)

    (site / 'model-mode').write_text('stream-custom')
    custom_brief = 'An amber observatory with a quiet horizon'
    custom_payload = {'canvas_kind': 'custom', 'brief': custom_brief, 'request_id': '3' * 32}
    with open_response(first, base, SCENE, method='POST', data=custom_payload, csrf=csrf, accept=NDJSON) as response:
        assert response.headers.get_content_type() == NDJSON
        events = [json.loads(line) for line in response]
    final = events[-1]
    built = final['data']
    custom = built['scene']
    check(events[0] == {'type': 'concept', 'text': 'a pulsing EQ background'} and events[1]['type'] == 'delta',
          'model announces the brief concept before streaming code')
    check(final['type'] == 'result' and final['status'] == 201
          and built['design_source'] == 'model' and built['active_tokens_used'] == MODEL_USAGE
          and custom['canvas_kind'] == 'custom' and not custom['track_id'] and custom['title'] == 'Amber Observatory'
          and '<article class="amber-observatory"></article>' == custom['html'],
          'a generic custom canvas streams without a song or artist reference and uses one model charge')
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = json.loads(model_request['input'][-1]['content'][0]['text'])
    check(prompt['design_brief'] == custom_brief and prompt['published_song'] is None
          and 'optional_playing_song' not in prompt,
          'the standalone design brief reaches the model without song context')
    restore = SCENE + '?id=' + str(custom['id'])
    status, _, restored = request(first, base, restore)
    check(status == 200 and restored['scene'] == custom, 'owner can reopen a trackless custom canvas')
    status, _, denied = request(second, base, restore)
    check(status == 404 and denied.get('error') == 'scene_not_found', 'another account cannot reopen the custom canvas')

    seed_saved_lyrics(site)
    (site / 'model-mode').write_text('valid')
    lyric_brief = 'Copper Chorus Fixture'
    lyric_payload = {'canvas_kind': 'custom', 'track_id': track_id, 'brief': lyric_brief,
                     'lyric_section': 'chorus', 'request_id': '4' * 32}
    status, _, built = request(first, base, SCENE, method='POST', data=lyric_payload, csrf=csrf)
    lyric_scene = built['scene']
    cue = lyric_scene['lyric_cue']
    check(status == 201 and lyric_scene['canvas_kind'] == 'custom' and lyric_scene['song_title'] == PUBLISHED_TITLE[:180]
          and cue['section'] == 'chorus' and cue['label'] == 'Chorus' and cue['start_ms'] == 2200 and cue['end_ms'] == 4200
          and cue['timing'] == 'saved_segments' and REVIEWED_CHORUS not in json.dumps(built, ensure_ascii=False),
          'custom canvas returns only the selected chorus cue with approved saved timing')
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = model_request['input'][-1]['content'][0]['text']
    check(REVIEWED_CHORUS in prompt and all(other not in prompt for other in OTHER_LYRIC_FIXTURES),
          'only the selected reviewed current-audio lyrics reach the model')
    status, _, restored = request(first, base, SCENE + '?id=' + str(lyric_scene['id']))
    check(status == 200 and restored['scene']['lyric_cue'] == cue, 'the saved canvas retains its lyric cue on reopen')
    status, _, conflict = request(first, base, SCENE, method='POST', csrf=csrf,
                                  data={**lyric_payload, 'lyric_section': 'verse'})
    check(status == 422 and conflict.get('error') == 'invalid_scene_request',
          'a request ID cannot be replayed with a different approved lyric section')
    status, _, error = request(first, base, SCENE, method='POST', csrf=csrf,
                               data={'canvas_kind': 'custom', 'brief': custom_brief, 'lyric_section': 'chorus'})
    check(status == 422 and error.get('error') == 'invalid_scene_request', 'lyric context requires a published song')

    status, _, history = request(first, base, SCENE)
    newest = history['scenes'][:3]
    check(status == 200 and [(row['id'], row['canvas_kind']) for row in newest] == [
        (lyric_scene['id'], 'custom'), (custom['id'], 'custom'), (eq['id'], 'space_eq')
    ] and all('html' not in row and 'css' not in row for row in newest),
          'private canvas history retains each kind and exposes only summaries')
    suggestion_path = SCENE + '?suggestions=1'
    status, _, guest = request(browser(), base, suggestion_path)
    check(status == 401 and guest.get('error') == 'authentication_required', 'canvas suggestions require a signed-in account')
    owner_status, _, owner = request(first, base, suggestion_path)
    other_status, _, other = request(second, base, suggestion_path)
    owner_suggestions, defaults = owner['suggestions'], other['suggestions']
    check(owner_status == other_status == 200 and [item['label'] for item in defaults] == ['Listening Room', 'Album Wall']
          and owner_suggestions[2:] == defaults and lyric_scene['title'] in owner_suggestions[0]['command']
          and custom['title'] in owner_suggestions[1]['command']
          and all(set(item) == {'label', 'command'} for item in owner_suggestions),
          'suggestions use the newest private custom titles followed by shared defaults')
    assert_settled_balance(site, 10, STARTING_BALANCE - 5 * MODEL_USAGE, model_calls + ['stream-custom', 'valid'], paid_calls=5)
    print('PASS procedural canvas and suggestions use no provider calls or tokens', flush=True)

    status, _, error = request(first, base, SCENE, method='POST', csrf=csrf,
                               data={'canvas_kind': 'space_eq', 'track_id': track_id,
                                     'previous_id': eq['id'], 'brief': 'Make it purple'})
    check(status == 422 and 'custom canvas' in error.get('message', ''), 'procedural EQ revisions explain how to customize the canvas')

    revision_payload = {'canvas_kind': 'custom', 'track_id': track_id,
                        'previous_id': lyric_scene['id'], 'brief': 'Make the layout more minimal'}
    status, _, revised = request(first, base, SCENE, method='POST', csrf=csrf, data=revision_payload)
    revision = revised['scene']
    check(status == 201 and revision['title'] == 'Custom Canvas' and revision['previous_id'] == lyric_scene['id']
          and revision['lyric_section'] == 'chorus' and revision['lyric_cue'] == cue,
          'cosmetic revision preserves the build title and current published lyric cue')
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        db.execute("UPDATE song_timeline_moments SET status='draft' WHERE title='Chorus' AND body=?", (REVIEWED_CHORUS,))
    status, _, revised = request(first, base, SCENE, method='POST', csrf=csrf, data=revision_payload)
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = model_request['input'][-1]['content'][0]['text']
    check(status == 201 and revised['scene']['lyric_section'] is None and revised['scene']['lyric_cue'] is None
          and REVIEWED_CHORUS not in prompt, 'cosmetic revision drops a revoked lyric cue and never sends its words to the model')
    status, _, error = request(first, base, SCENE, method='POST', csrf=csrf,
                               data={**revision_payload, 'lyric_section': 'chorus'})
    check(status == 422 and error.get('error') == 'invalid_scene_request', 'explicitly requested revoked lyrics fail before generation')
    assert_settled_balance(site, 12, STARTING_BALANCE - 7 * MODEL_USAGE, model_calls + ['stream-custom', 'valid', 'valid', 'valid'], paid_calls=7)

    _, _, second_auth = request(second, base, AUTH)
    second_csrf = second_auth['csrf']
    standalone_revision = {'canvas_kind': 'custom', 'brief': 'Make it brighter', 'previous_id': custom['id']}
    status, _, error = request(second, base, SCENE, method='POST', csrf=second_csrf, data=standalone_revision)
    check(status == 422 and error.get('error') == 'invalid_scene_request', 'another account cannot revise a private parent canvas')
    status, _, revised = request(first, base, SCENE, method='POST', csrf=csrf, data=standalone_revision)
    check(status == 201 and revised['scene']['title'] == 'Custom Canvas' and revised['scene']['previous_id'] == custom['id'],
          'an owned standalone canvas can be revised without a song')
    assert_settled_balance(site, 13, STARTING_BALANCE - 8 * MODEL_USAGE,
                           model_calls + ['stream-custom', 'valid', 'valid', 'valid', 'valid'], paid_calls=8)

    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_enroll_user(2, null);
        sf_token_adjust(2, (int)$argv[1] - sf_token_balance(2), 'admin_grant', 'fixture-second-balance');
    ''', str(STARTING_BALANCE)], cwd=site, check=True)
    status, _, second_build = request(second, base, SCENE, method='POST', csrf=second_csrf,
                                      data={'canvas_kind': 'custom', 'brief': 'A window for Stonefellow’s next show'})
    check(status == 201 and second_build['active_tokens_used'] == MODEL_USAGE and
          second_build['active_tokens_remaining'] == STARTING_BALANCE - MODEL_USAGE,
          'another signed-in account pays from its own balance')
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        charges = db.execute("SELECT user_id, COUNT(*) FROM token_ledger WHERE entry_type='usage' GROUP BY user_id ORDER BY user_id").fetchall()
        first_balance = db.execute('SELECT balance FROM user_token_balances WHERE user_id=1').fetchone()[0]
    check(charges == [(1, 8), (2, 1)] and first_balance == STARTING_BALANCE - 8 * MODEL_USAGE,
          'canvas model usage is tracked separately for each account')


def exercise_catalog_shortcodes(site, base, first, second, csrf):
    seed_shortcode_catalog(site)
    model_calls = (site / 'model-calls').read_text().splitlines()
    (site / 'model-mode').write_text('valid')
    explicit = {'canvas_kind': 'custom', 'brief': 'Design a shelf for {media-fixture-album}, {media-albums}, '
                '{merch-hoodies}, {show-locations} and {price-ticket}.',
                'request_id': '5' * 32}
    status, _, built = request(first, base, SCENE, method='POST', data=explicit, csrf=csrf)
    scene = built['scene']
    check(status == 201 and scene['catalog_refs'] == [ALBUM_REF, ALBUM_WALL_REF, MERCH_REF, SHOW_REF, TICKET_REF]
          and built['active_tokens_used'] == MODEL_USAGE,
          'explicit shortcodes persist canonical references on a standalone canvas')
    blocks = {block['ref']: block for block in scene['catalog_blocks']}
    album = blocks[ALBUM_REF]
    merch = blocks[MERCH_REF]
    check(album['title'] == ALBUM_TITLE and [row['title'] for row in album['items']] == ['Fixture Dusk', 'Fixture Dawn'],
          'album binding uses published tracks in release order and excludes a hidden track')
    wall = blocks[ALBUM_WALL_REF]['items']
    check([row['title'] for row in wall] == [ALBUM_TITLE] and wall[0]['artwork'] == 'assets/images/fixture-album.png',
          'Album Wall resolves only published releases and their approved covers')
    check([row['title'] for row in merch['items']] == [HOODIE_TITLE]
          and merch['items'][0]['price_cents'] == 2900 and merch['items'][0]['available'] is True,
          'merch binding uses public stock and price without a draft product')
    shows = blocks[SHOW_REF]['items']
    tickets = {row['title']: row['price_cents'] for row in blocks[TICKET_REF]['items']}
    check([row['venue'] for row in shows] == ['Fixture Harbor Hall']
          and tickets == {'Fixture general tickets': 1800, 'Fixture balcony tickets': 2600},
          'show and ticket bindings use published events and their public offers')
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = json.loads(model_request['input'][-1]['content'][0]['text'])
    catalog_facts = json.dumps(prompt['catalog_references'], ensure_ascii=False)
    check(ALBUM_REF in catalog_facts and ALBUM_TITLE in catalog_facts and MERCH_REF in catalog_facts
          and all(hidden not in catalog_facts for hidden in (
              'Hidden track fixture', 'Private hoodie draft fixture', 'Fixture internal product cost',
              'Private unreleased album fixture', 'Private venue fixture', 'Private ticket fixture',
          )),
          'model receives only public catalog facts for offered references')

    for ref in (PRIVATE_ALBUM_REF, 'media-nonexistent-album'):
        status, _, error = request(first, base, SCENE, method='POST', csrf=csrf,
                                   data={'canvas_kind': 'custom', 'brief': f'Build a shelf for {{{ref}}}.'})
        check(status == 422 and error.get('error') == 'invalid_scene_request',
              f'explicit reference {ref} fails before generation')

    (site / 'model-mode').write_text('catalog-choose')
    selected = {'canvas_kind': 'custom', 'brief': f'Design an album page for {ALBUM_TITLE}.', 'request_id': '6' * 32}
    status, _, model_built = request(first, base, SCENE, method='POST', data=selected, csrf=csrf)
    check(status == 201 and model_built['scene']['catalog_refs'] == [ALBUM_REF]
          and [block['ref'] for block in model_built['scene']['catalog_blocks']] == [ALBUM_REF],
          'model can select an offered album while invented and private selections are discarded')
    assert_settled_balance(site, 15, STARTING_BALANCE - 10 * MODEL_USAGE,
                           model_calls + ['valid', 'catalog-choose'], paid_calls=10)

    restore_path = SCENE + '?id=' + str(scene['id'])
    recovery_path = SCENE + '?request_id=' + explicit['request_id']
    status, _, denied = request(second, base, restore_path)
    check(status == 404 and denied.get('error') == 'scene_not_found', 'catalog-bound canvas remains private to its owner')
    status, _, denied = request(second, base, recovery_path)
    check(status == 404 and denied.get('error') == 'scene_request_not_found', 'catalog-bound receipt stays private to its owner')

    with sqlite3.connect(site / 'fixture.sqlite') as db:
        db.execute("UPDATE store_products SET base_price_cents=3100, inventory_qty=0 WHERE id='fixture-hoodies'")
        db.execute("UPDATE ticket_offers SET price_cents=2100 WHERE title='Fixture general tickets'")
    releases_path = site / 'data/releases.json'
    releases = json.loads(releases_path.read_text())
    releases[0]['track_ids'] = ['album-first', 'album-second']
    releases_path.write_text(json.dumps(releases))
    status, _, restored = request(first, base, restore_path)
    refreshed = restored['scene']
    refreshed_blocks = {block['ref']: block for block in refreshed['catalog_blocks']}
    product = refreshed_blocks[MERCH_REF]['items'][0]
    ticket_prices = {row['title']: row['price_cents'] for row in refreshed_blocks[TICKET_REF]['items']}
    check(status == 200 and refreshed['catalog_refs'] == scene['catalog_refs'] and refreshed['html'] == scene['html']
          and product['price_cents'] == 3100 and product['available'] is False
          and ticket_prices == {'Fixture general tickets': 2100, 'Fixture balcony tickets': 2600}
          and [row['title'] for row in refreshed_blocks[ALBUM_REF]['items']] == ['Fixture Dawn', 'Fixture Dusk'],
          'reopening keeps the design and refreshes merch stock, both prices and album order')
    status, _, recovered = request(first, base, recovery_path)
    check(status == 200 and recovered['state'] == 'ready' and recovered['scene'] == refreshed
          and recovered['active_tokens_remaining'] == built['active_tokens_remaining'],
          'request recovery refreshes catalog data and keeps its original token receipt')
    status, _, replayed = request(first, base, SCENE, method='POST', data=explicit, csrf=csrf)
    check(status == 200 and replayed == recovered, 'duplicate catalog build returns fresh data without a second charge')

    exercise_record_store_fallback(site, base, first, csrf)
    exercise_custom_eq_errors(site, base, first, csrf)

    releases[0]['state'] = 'draft'
    releases_path.write_text(json.dumps(releases))
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        db.execute("UPDATE store_products SET status='archived' WHERE id='fixture-hoodies'")
    status, _, restored = request(first, base, restore_path)
    current_scene = restored['scene']
    current_blocks = json.dumps(current_scene['catalog_blocks'], ensure_ascii=False)
    check(status == 200 and current_scene['catalog_refs'] == scene['catalog_refs']
          and all(secret not in current_blocks for secret in (ALBUM_TITLE, 'Fixture Dusk', HOODIE_TITLE)),
          'saved refs survive while unpublished catalog data is no longer shown')
    assert_settled_balance(site, 18, STARTING_BALANCE - 10 * MODEL_USAGE,
                           model_calls + ['valid', 'catalog-choose', 'unavailable', 'stream-invalid', 'stream-invalid',
                                          'unavailable', 'unavailable', 'invalid', 'invalid'],
                           paid_calls=10)
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        assert db.execute('SELECT balance FROM user_token_balances WHERE user_id=2').fetchone() == (STARTING_BALANCE - MODEL_USAGE,)
    print('PASS catalog reads and exact replay do not charge either account', flush=True)


def exercise_record_store_fallback(site, base, first, csrf):
    # The chat parser removes “Let’s build” and the final period from the pasted command.
    brief = 'a Stonefellow record-store window with `{media-albums}` and `{merch-hoodies}`'
    balance = STARTING_BALANCE - 10 * MODEL_USAGE
    for key, mode in [('7', 'unavailable'), ('8', 'stream-invalid'), ('9', 'disabled')]:
        payload = {'canvas_kind': 'custom', 'brief': brief, 'request_id': key * 32}
        calls_before = (site / 'model-calls').read_text().splitlines()
        (site / 'model-mode').write_text(mode)
        if mode == 'stream-invalid':
            with open_response(first, base, SCENE, method='POST', data=payload, csrf=csrf, accept=NDJSON) as response:
                assert response.headers.get_content_type() == NDJSON
                events = [json.loads(line) for line in response]
            assert events[0]['type'] == 'delta' and events[-1]['type'] == 'result', events
            status, built = events[-1]['status'], events[-1]['data']
        else:
            if mode == 'disabled':
                with sqlite3.connect(site / 'fixture.sqlite') as db:
                    db.execute("UPDATE ai_provider_settings SET enabled=0 WHERE provider='openai'")
            try:
                status, _, built = request(first, base, SCENE, method='POST', data=payload, csrf=csrf, accept=NDJSON)
            finally:
                if mode == 'disabled':
                    with sqlite3.connect(site / 'fixture.sqlite') as db:
                        db.execute("UPDATE ai_provider_settings SET enabled=1 WHERE provider='openai'")
        scene = built.get('scene', {})
        check(status == 201 and built.get('design_source') == 'standard' and built.get('active_tokens_used') == 0
              and built.get('active_tokens_remaining') == balance and scene.get('canvas_kind') == 'custom'
              and scene.get('html') and '<script>' not in scene['html']
              and scene.get('catalog_refs') == [ALBUM_WALL_REF, MERCH_REF],
              f'pasted record-store build saves a free native design when provider is {mode}')
        blocks = {block['ref']: block for block in scene['catalog_blocks']}
        check([row['title'] for row in blocks[ALBUM_WALL_REF]['items']] == [ALBUM_TITLE]
              and [row['title'] for row in blocks[MERCH_REF]['items']] == [HOODIE_TITLE],
              f'{mode} fallback still includes live album and merch cards')
        status, _, recovered = request(first, base, SCENE + '?request_id=' + payload['request_id'])
        status_replay, _, replayed = request(first, base, SCENE, method='POST', data=payload, csrf=csrf, accept=NDJSON)
        expected_calls = calls_before if mode == 'disabled' else calls_before + [mode] * (2 if mode == 'stream-invalid' else 1)
        check(status == status_replay == 200 and recovered.get('state') == 'ready' and recovered['scene'] == scene
              and recovered.get('active_tokens_used') == 0 and replayed == recovered
              and (site / 'model-calls').read_text().splitlines() == expected_calls,
              f'{mode} fallback is recoverable and replay does not call the model again')

    (site / 'model-mode').write_text('unavailable')
    status, _, error = request(first, base, SCENE, method='POST', csrf=csrf, data={
        'canvas_kind': 'custom', 'previous_id': scene['id'], 'brief': 'Make this record-store window brighter',
    })
    restore_status, _, original = request(first, base, SCENE + '?id=' + str(scene['id']))
    check(status == 422 and error.get('error') == 'scene_model_unavailable' and restore_status == 200
          and original['scene'] == scene,
          'failed catalog revision keeps the earlier saved design')


def exercise_custom_eq_errors(site, base, first, csrf):
    eq = {'canvas_kind': 'custom', 'brief': 'Build an EQ', 'context_track_id': 'fixture-song', 'request_id': 'a7' * 16}
    (site / 'model-mode').write_text('unavailable')
    status, _, error = request(first, base, SCENE, method='POST', csrf=csrf, data=eq, accept=NDJSON)
    check(status == 422 and error.get('error') == 'scene_model_unavailable' and 'scene' not in error
          and error.get('reason') == 'provider_unavailable' and error.get('request_id') == eq['request_id']
          and any(word in error.get('message', '').lower() for word in ('model', 'provider')),
          'custom EQ provider connection failure returns a readable provider error with a request reference')
    status, _, failed = request(first, base, SCENE + '?request_id=' + eq['request_id'])
    check(status == 200 and failed.get('state') == 'failed' and 'scene' not in failed,
          'failed custom EQ request never creates a saved canvas')

    (site / 'model-mode').write_text('invalid')
    status, _, error = request(first, base, SCENE, method='POST', csrf=csrf, data={**eq, 'request_id': 'a8' * 16})
    check(status == 422 and error.get('error') == 'invalid_scene_output' and 'scene' not in error
          and error.get('reason') == 'invalid_markup' and error.get('request_id') == 'a8' * 16,
          'custom EQ distinguishes invalid model code from a connection failure')


def exercise_agent_design(site, base, first, second, csrf):
    calls = (site / 'model-calls').read_text().splitlines()
    (site / 'model-mode').write_text('valid')
    brief = 'Stonefellow moonlit stage with {show-locations}'
    payload = {'canvas_kind': 'custom', 'brief': brief + ' {hide-agent}', 'request_id': 'a9' * 16}
    status, _, built = request(first, base, SCENE, method='POST', data=payload, csrf=csrf)
    hidden = built.get('scene', {})
    check(status == 201 and hidden.get('agent_design') == 'hidden' and hidden['catalog_refs'] == [SHOW_REF],
          'hide-agent changes the canvas presentation alongside a live catalog reference')
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = json.loads(model_request['input'][-1]['content'][0]['text'])
    check(prompt['design_brief'] == brief and prompt['requested_catalog_refs'] == [SHOW_REF]
          and '{hide-agent}' not in hidden['title'],
          'presentation directives stay out of the model brief, title, and catalog references')

    restore = SCENE + '?id=' + str(hidden['id'])
    status, _, restored = request(first, base, restore)
    recovery_status, _, recovered = request(first, base, SCENE + '?request_id=' + payload['request_id'])
    denied_status, _, denied = request(second, base, restore)
    check(status == recovery_status == 200 and restored['scene'] == recovered['scene'] == hidden
          and denied_status == 404 and denied.get('error') == 'scene_not_found',
          'private canvas reopen and request recovery retain the saved agent presentation')
    status, _, conflict = request(first, base, SCENE, method='POST', csrf=csrf,
                                  data={**payload, 'brief': brief + ' {design-agent}'})
    check(status == 422 and conflict.get('error') == 'invalid_scene_request',
          'a request ID cannot be replayed with a different agent presentation')

    revision = {'canvas_kind': 'custom', 'previous_id': hidden['id'], 'brief': 'Add a soft glow'}
    status, _, revised = request(first, base, SCENE, method='POST', data=revision, csrf=csrf)
    inherited = revised.get('scene', {})
    check(status == 201 and inherited.get('agent_design') == 'hidden'
          and inherited['previous_id'] == hidden['id'] and inherited['catalog_refs'] == [SHOW_REF],
          'creative revisions inherit the original canvas presentation and catalog binding')

    status, _, revised = request(first, base, SCENE, method='POST', csrf=csrf, data={
        **revision, 'previous_id': inherited['id'], 'brief': 'Use copper accents {hide-agent} {design-agnet}',
    })
    square = revised.get('scene', {})
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = json.loads(model_request['input'][-1]['content'][0]['text'])
    check(status == 201 and square.get('agent_design') == 'square' and prompt['design_brief'] == 'Use copper accents',
          'design-agnet alias applies the last presentation directive in a creative revision')
    status, _, restored = request(first, base, restore)
    check(status == 200 and restored['scene'] == hidden, 'revision leaves the previous canvas presentation intact')

    status, _, eq_build = request(first, base, SCENE + '?request_id=' + '2' * 32)
    assert status == 200 and eq_build.get('state') == 'ready'
    eq = eq_build['scene']
    revision = {'canvas_kind': 'space_eq', 'track_id': eq['track_id'], 'previous_id': eq['id'], 'brief': '{design-agent}'}
    status, _, revised = request(first, base, SCENE, method='POST', data=revision, csrf=csrf)
    eq_square = revised.get('scene', {})
    check(status == 201 and revised.get('active_tokens_used') == 0 and revised.get('design_source') == 'standard'
          and eq_square.get('agent_design') == 'square' and eq_square['canvas_kind'] == 'space_eq'
          and eq_square['previous_id'] == eq['id'] and all(eq_square[key] == eq[key] for key in ('html', 'css'))
          and (site / 'model-calls').read_text().splitlines() == calls + ['valid'] * 3,
          'display-only edit of Space EQ preserves source and uses no model call or tokens')

    status, _, revised = request(first, base, SCENE, method='POST', csrf=csrf, data={
        **revision, 'canvas_kind': 'custom', 'previous_id': eq_square['id'], 'brief': 'Build a copper ripple backdrop',
    })
    creative = revised.get('scene', {})
    check(status == 201 and revised.get('active_tokens_used') == MODEL_USAGE and creative.get('canvas_kind') == 'custom'
          and creative.get('agent_design') == 'square' and creative['previous_id'] == eq_square['id']
          and creative['track_id'] == eq['track_id'],
          'creative edit of Space EQ saves a custom revision for the same song')
    assert_settled_balance(site, 23, STARTING_BALANCE - 14 * MODEL_USAGE, calls + ['valid'] * 4, paid_calls=14)


def exercise_incomplete_custom_canvas(site, base, first, second, csrf):
    track_id = 'sf-825d97f97e3106f7'
    catalog_path = site / 'data/catalog.json'
    catalog = json.loads(catalog_path.read_text())
    catalog.append({'id': track_id, 'title': 'Earth Girl', 'duration': 120, 'state': 'published'})
    catalog_path.write_text(json.dumps(catalog))
    brief = 'a star cluster EQ background for earth girl.  the star clusters should pulse different colors'
    payload = {'canvas_kind': 'custom', 'track_id': track_id, 'brief': brief}
    calls = (site / 'model-calls').read_text().splitlines()
    for index, mode in enumerate(['stream-incomplete', 'stream-eof'], start=1):
        request_id = f'{index + 0xf0:02x}' * 16
        create = {**payload, 'request_id': request_id}
        (site / 'model-mode').write_text(mode)
        with open_response(first, base, SCENE, method='POST', data=create, csrf=csrf, accept=NDJSON) as response:
            assert response.headers.get_content_type() == NDJSON, response.read()
            assert response.headers['X-Stonefellow-Scene-Request'] == request_id
            events = [json.loads(line) for line in response]
        check([event['type'] for event in events] == ['concept', 'delta', 'retry', 'concept', 'delta', 'delta', 'result']
              and events[0]['text'] == 'an unfinished first pass'
              and events[3]['text'] == 'a multicolor star cluster EQ for Earth Girl',
              f'{mode} resets incomplete source and streams the recovered concept and code')
        initial = json.loads((site / 'model-retry-request-1.json').read_text())
        retry = json.loads((site / 'model-retry-request-2.json').read_text())
        initial_brief = json.loads(initial['input'][0]['content'][0]['text'])
        retry_brief = json.loads(retry['input'][0]['content'][0]['text'])
        check(initial['max_output_tokens'] == retry['max_output_tokens'] == 8192
              and 'reasoning' not in initial and 'reasoning' not in retry
              and initial_brief == retry_brief and retry_brief['design_brief'] == brief
              and retry_brief['published_song']['title'] == 'Earth Girl',
              f'{mode} retries within the correct output budget and retains the exact song brief')
        terminal = events[-1]
        built = terminal['data']
        check(terminal['status'] == 201 and built['scene']['canvas_kind'] == 'custom'
              and built['scene']['track_id'] == track_id
              and '<article class="star-clusters">' in built['scene']['html']
              and 'Earth Girl Star Clusters' not in built['scene']['html']
              and built['design_source'] == 'model' and built['active_tokens_used'] == MODEL_USAGE,
              f'{mode} saves the generated Earth Girl canvas and charges only the successful attempt')
        recovery_path = SCENE + '?request_id=' + request_id
        status, _, recovered = request(first, base, recovery_path)
        replay_status, _, replayed = request(first, base, SCENE, method='POST', data=create, csrf=csrf, accept=NDJSON)
        other_status, _, _ = request(second, base, recovery_path)
        check(status == replay_status == 200 and other_status == 404 and recovered == replayed
              and recovered['scene'] == built['scene'],
              f'{mode} private request recovery and replay return the same finished canvas')
        calls += [mode, mode]
        assert_settled_balance(site, 23 + index, STARTING_BALANCE - (14 + index) * MODEL_USAGE,
                               calls, paid_calls=14 + index)

    request_id = 'f3' * 16
    (site / 'model-mode').write_text('stream-incomplete-twice')
    with open_response(first, base, SCENE, method='POST', data={**payload, 'request_id': request_id}, csrf=csrf,
                       accept=NDJSON) as response:
        assert response.headers.get_content_type() == NDJSON, response.read()
        events = [json.loads(line) for line in response]
    terminal = events[-1]
    check(sum(event['type'] == 'retry' for event in events) == 1
          and terminal['type'] == 'error' and terminal['data']['error'] == 'scene_model_unavailable'
          and terminal['data'].get('reason') == 'output_limit' and terminal['data'].get('request_id') == request_id,
          'two incomplete attempts return one terminal output-limit error with a request reference')
    status, _, failed = request(first, base, SCENE + '?request_id=' + request_id)
    check(status == 200 and failed['state'] == 'failed' and 'scene' not in failed,
          'an unrecovered build leaves only a failed receipt')
    assert_settled_balance(site, 25, STARTING_BALANCE - 16 * MODEL_USAGE,
                           calls + ['stream-incomplete-twice'] * 2, paid_calls=16)
    calls += ['stream-incomplete-twice'] * 2

    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_ai_save_provider('anthropic', ['enabled'=>true,'api_key'=>'fixture-fallback-key','model'=>'fixture-fallback-model']);
        sf_ai_meta_set('ai.fallback_enabled','1');
    '''], cwd=site, check=True)
    (site / 'model-mode').write_text('stream-fallback')
    with open_response(first, base, SCENE, method='POST', data={**payload, 'request_id': 'f4' * 16}, csrf=csrf,
                       accept=NDJSON) as response:
        assert response.headers.get_content_type() == NDJSON, response.read()
        events = [json.loads(line) for line in response]
    fallback = json.loads((site / 'model-retry-request-2.json').read_text())
    fallback_brief = json.loads(fallback['messages'][0]['content'])
    check([event['type'] for event in events] == ['concept', 'delta', 'retry', 'concept', 'delta', 'result']
          and events[-1]['status'] == 201 and events[-1]['data']['scene']['track_id'] == track_id
          and fallback['max_tokens'] == 8192 and fallback_brief['design_brief'] == brief
          and 'smallest complete design' in fallback['system'],
          'configured alternate text provider completes the same Earth Girl request')
    assert_settled_balance(site, 26, STARTING_BALANCE - 17 * MODEL_USAGE,
                           calls + ['stream-fallback'] * 2, paid_calls=17)
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        check(db.execute("SELECT provider FROM token_ledger WHERE user_id=1 AND entry_type='usage' ORDER BY id DESC LIMIT 1")
              .fetchone() == ('anthropic',),
              'fallback receipt charges only the successful configured provider')


def exercise_canvas_source_independence(site, base, first, second, csrf, room_id):
    calls = (site / 'model-calls').read_text().splitlines()
    payload = {'canvas_kind': 'space_eq', 'request_id': 'f5' * 16}
    status, _, built = request(first, base, SCENE, method='POST', data=payload, csrf=csrf)
    trackless = built['scene']
    check(status == 201 and built['active_tokens_used'] == 0 and built['design_source'] == 'standard'
          and trackless['canvas_kind'] == 'space_eq' and trackless['title'] == 'Space EQ'
          and trackless['track_id'] == '' and trackless['song_title'] is None,
          'Space EQ can be built and saved with no source song or model charge')
    status, _, restored = request(first, base, SCENE + '?id=' + str(trackless['id']))
    check(status == 200 and restored['scene'] == trackless, 'trackless Space EQ can be reopened from history')
    status, _, rejected = request(first, base, SCENE, method='POST', csrf=csrf,
                                  data={'canvas_kind': 'space_eq', 'lyric_section': 'chorus'})
    check(status == 422 and rejected.get('error') == 'invalid_scene_request',
          'trackless Space EQ cannot claim a lyric section')

    previous = []
    for query in ('?request_id=' + '4' * 32, '?request_id=' + '2' * 32, '?id=' + str(room_id)):
        status, _, saved = request(first, base, SCENE + query)
        assert status == 200 and saved['scene']['track_id'] == 'fixture-song'
        previous.append(saved['scene'])

    catalog_path = site / 'data/catalog.json'
    catalog = json.loads(catalog_path.read_text())
    source = next(track for track in catalog if track['id'] == 'fixture-song')
    source.update(state='private', title='Now private source fixture')
    catalog_path.write_text(json.dumps(catalog))
    for scene in previous:
        restore = SCENE + '?id=' + str(scene['id'])
        status, _, restored = request(first, base, restore)
        other_status, _, _ = request(second, base, restore)
        saved = restored['scene']
        check(status == 200 and other_status == 404
              and all(saved[key] == scene[key] for key in ('track_id', 'title', 'html', 'css'))
              and all(saved[key] is None for key in ('song_title', 'artwork', 'story', 'lyric_cue')),
              f"owner can reopen saved {scene['canvas_kind']} after its source is unpublished without live source data")

    lyric_scene = previous[0]
    status, _, replayed = request(first, base, SCENE, method='POST', csrf=csrf, data={
        'canvas_kind': 'custom', 'track_id': lyric_scene['track_id'], 'brief': 'Copper Chorus Fixture',
        'lyric_section': 'chorus', 'request_id': '4' * 32,
    })
    check(status == 200 and replayed['state'] == 'ready' and replayed['scene']['id'] == lyric_scene['id']
          and replayed['scene']['lyric_cue'] is None and (site / 'model-calls').read_text().splitlines() == calls,
          'an existing request receipt replays saved art after unpublishing without a second model call')

    revision = {'canvas_kind': 'custom', 'track_id': lyric_scene['track_id'], 'previous_id': lyric_scene['id'],
                'brief': 'Soften the copper accents'}
    status, _, rejected = request(first, base, SCENE, method='POST', csrf=csrf,
                                  data={**revision, 'lyric_section': 'chorus'})
    check(status == 422 and rejected.get('error') == 'invalid_scene_request',
          'an unpublished source does not authorize fresh lyrics for a revision')
    status, _, rejected = request(first, base, SCENE, method='POST', csrf=csrf,
                                  data={'canvas_kind': 'space_eq', 'track_id': lyric_scene['track_id']})
    check(status == 422 and rejected.get('error') == 'invalid_scene_request',
          'new builds still reject an explicitly supplied unpublished song')

    (site / 'model-mode').write_text('valid')
    status, _, result = request(first, base, SCENE, method='POST', csrf=csrf, data=revision)
    revised = result['scene']
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = json.loads(model_request['input'][-1]['content'][0]['text'])
    check(status == 201 and revised['previous_id'] == lyric_scene['id'] and revised['track_id'] == lyric_scene['track_id']
          and revised['lyric_section'] is None and revised['song_title'] is None
          and prompt['published_song'] is None and prompt['previous']['html'] == lyric_scene['html']
          and source['title'] not in json.dumps(prompt),
          'saved custom canvas can be edited without reusing unpublished source material')
    assert_settled_balance(site, 28, STARTING_BALANCE - 18 * MODEL_USAGE, calls + ['valid'], paid_calls=18)


def exercise_terminal_stall(site, base, first, csrf):
    mode = 'stream-terminal-stall'
    brief = 'a space theme EQ with star clusters that pulse with the music'
    request_id = 'f6' * 16
    payload = {'canvas_kind': 'custom', 'context_track_id': 'sf-825d97f97e3106f7', 'brief': brief, 'request_id': request_id}
    calls = (site / 'model-calls').read_text().splitlines()
    (site / 'model-mode').write_text(mode)
    try:
        with open_response(first, base, SCENE, method='POST', data=payload, csrf=csrf, accept=NDJSON) as response:
            assert response.headers.get_content_type() == NDJSON, response.read()
            assert response.headers['X-Stonefellow-Scene-Request'] == request_id
            events = [json.loads(line) for line in response]
        terminal = events[-1]
        check(terminal['type'] == 'result' and terminal['status'] == 201
              and not (site / 'model-read-after-terminal').exists(),
              'completed metered canvas returns without waiting for the provider to close the stream')
    finally:
        (site / 'model-terminal-release').touch()

    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = json.loads(model_request['input'][0]['content'][0]['text'])
    check(prompt['design_brief'] == brief and prompt['optional_playing_song']['title'] == 'Earth Girl'
          and all('--sf-audio-' + band in model_request['instructions'] for band in ('bass', 'mid', 'treble', 'level')),
          'custom design prompt exposes live spectrum CSS variables and preserves the requested brief')
    built = terminal['data']
    source = json.loads(''.join(event['text'] for event in events if event['type'] == 'delta'))
    check(events[0] == {'type': 'concept', 'text': brief}
          and built['scene']['canvas_kind'] == 'custom' and built['scene']['css'] == source['css']
          and 'var(--sf-audio-bass, 0)' in built['scene']['css'] and built['active_tokens_used'] == MODEL_USAGE,
          'generated live spectrum CSS survives validation and is charged as one completed canvas')
    status, _, recovered = request(first, base, SCENE + '?request_id=' + request_id)
    replay_status, _, replayed = request(first, base, SCENE, method='POST', data=payload, csrf=csrf, accept=NDJSON)
    check(status == replay_status == 200 and recovered == replayed and recovered['scene'] == built['scene'],
          'completed metered canvas reopens from its receipt without another provider request')
    assert_settled_balance(site, 29, STARTING_BALANCE - 19 * MODEL_USAGE, calls + [mode], paid_calls=19)


def exercise_constrained_output_retry(site, base, first, csrf):
    payload = {'canvas_kind': 'custom', 'context_track_id': 'sf-825d97f97e3106f7',
               'brief': 'Build a Stonefellow midnight mosaic with one quiet ripple'}
    calls = (site / 'model-calls').read_text().splitlines()
    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_ai_save_provider('openai', ['enabled'=>true,'model'=>'gpt-6-luna']);
        sf_ai_meta_set('ai.fallback_enabled','0');
    '''], cwd=site, check=True)

    # A failed probe supplies the exact prompt bytes without changing canvas history.
    (site / 'model-mode').write_text('stream-incomplete-twice')
    with open_response(first, base, SCENE, method='POST', data={**payload, 'request_id': 'f7' * 16}, csrf=csrf,
                       accept=NDJSON) as response:
        assert [json.loads(line) for line in response][-1]['type'] == 'error'
    probe = json.loads((site / 'model-retry-request-1.json').read_text())
    message = probe['input'][0]['content'][0]['text']
    prompt = json.loads(message)
    static_bytes = sum(len(json.dumps(prompt[key], ensure_ascii=False, separators=(',', ':')).replace('/', r'\/').encode())
                       for key in ('canvas_data_tools', 'canvas_libraries'))
    static_bytes += len(subprocess.check_output(
        [PHP, '-r', "require 'api/canvas-library-core.php'; echo SF_CANVAS_LIBRARY_BUILD_INSTRUCTIONS;"], cwd=site))
    fixed_cost = len(probe['instructions'].encode()) + len(message.encode()) - static_bytes + (static_bytes + 3) // 4 + 128
    balance = fixed_cost + 2100
    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_token_adjust(1, (int)$argv[1] - sf_token_balance(1), 'admin_grant', 'constrained-fixture-balance');
    ''', str(balance)], cwd=site, check=True)

    (site / 'model-mode').write_text('stream-compact')
    with open_response(first, base, SCENE, method='POST', data={**payload, 'request_id': 'f8' * 16}, csrf=csrf,
                       accept=NDJSON) as response:
        events = [json.loads(line) for line in response]
    initial = json.loads((site / 'model-retry-request-1.json').read_text())
    retry = json.loads((site / 'model-retry-request-2.json').read_text())
    check(1600 <= initial['max_output_tokens'] == retry['max_output_tokens'] <= 2100
          and initial['input'] == retry['input'] and 'smallest complete design' in retry['instructions']
          and events[-1]['type'] == 'result' and events[-1]['data']['active_tokens_used'] == MODEL_USAGE,
          'a constrained fan can still build with a compact retry inside their available token budget')
    check(initial['reasoning'] == retry['reasoning'] == {'effort': 'low'},
          'streamed gpt-6-luna canvas requests use low reasoning effort')
    assert_settled_balance(site, 30, balance - MODEL_USAGE,
                           calls + ['stream-incomplete-twice'] * 2 + ['stream-compact'] * 2, paid_calls=20)


def exercise_visual_only_canvas(site, base, first, csrf):
    # A playing song can still be used as visual inspiration even when it belongs to an album.
    releases_path = site / 'data/releases.json'
    releases = json.loads(releases_path.read_text())
    releases[0]['state'] = 'published'
    releases_path.write_text(json.dumps(releases))
    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_token_adjust(1, (int)$argv[1] - sf_token_balance(1), 'admin_grant', 'visual-fixture-balance');
    ''', str(STARTING_BALANCE)], cwd=site, check=True)
    calls = (site / 'model-calls').read_text().splitlines()
    (site / 'model-mode').write_text('visual-only')
    brief = 'Make blue stars ripple over a softly lit night sky'
    payload = {'canvas_kind': 'custom', 'context_track_id': 'album-first', 'brief': brief,
               'request_id': 'f9' * 16}
    status, _, built = request(first, base, SCENE, method='POST', data=payload, csrf=csrf)
    assert status == 201, built
    scene = built['scene']
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = json.loads(model_request['input'][0]['content'][0]['text'])
    check(prompt['design_brief'] == brief and prompt['optional_playing_song']['title'] == 'Fixture Dawn'
          and 'catalog_references' not in prompt and 'requested_catalog_refs' not in prompt,
          'a playing album track supplies visual context without offering catalog widgets')
    check(scene['catalog_refs'] == [] and scene['catalog_blocks'] == [],
          'a model-selected album cannot add a catalog widget to a visuals-only canvas')
    check(scene['title'] == 'Custom Canvas' and brief not in scene['html'] and '{{canvas_title}}' not in scene['html']
          and 'Custom Canvas' not in scene['html'] and '<div class="stars"></div>' in scene['html'],
          'an echoed brief is kept out of the title and visible artwork without removing the design')
    status, _, restored = request(first, base, SCENE + '?id=' + str(scene['id']))
    check(status == 200 and restored['scene'] == scene,
          'a visuals-only canvas reopens without a catalog widget')
    (site / 'model-mode').write_text('visual-headline')
    status, _, built = request(first, base, SCENE, method='POST', csrf=csrf,
                               data={**payload, 'brief': 'Make a star field with the exact headline STAR CITY',
                                     'request_id': 'fa' * 16})
    check(status == 201 and built['scene']['title'] == 'Star City' and '<h1>STAR CITY</h1>' in built['scene']['html'],
          'a specifically requested headline remains visible')
    status, _, original = request(first, base, SCENE + '?request_id=' + '5' * 32)
    assert status == 200 and original['state'] == 'ready', original
    prior = original['scene']
    status, _, edited = request(first, base, SCENE, method='POST', csrf=csrf,
                                data={'canvas_kind': 'custom', 'previous_id': prior['id'],
                                      'brief': 'remove the album widget', 'request_id': 'fb' * 16})
    check(status == 201 and edited['active_tokens_used'] == 0
          and edited['scene']['catalog_refs'] == [MERCH_REF, SHOW_REF, TICKET_REF]
          and all(edited['scene'][key] == prior[key] for key in ('html', 'css')),
          'an existing canvas can drop album widgets without a model call or artwork changes')
    assert_settled_balance(site, 33, STARTING_BALANCE - 2 * MODEL_USAGE,
                           calls + ['visual-only', 'visual-headline'], paid_calls=22)


def exercise_delete_canvas(site, base, first, second, csrf, second_csrf):
    payload = {'canvas_kind': 'custom', 'context_track_id': 'album-first',
               'brief': 'Make blue stars ripple over a softly lit night sky', 'request_id': 'f9' * 16}
    recovery_path = SCENE + '?request_id=' + payload['request_id']
    status, _, receipt = request(first, base, recovery_path)
    assert status == 200 and receipt['state'] == 'ready', receipt
    parent = receipt['scene']
    delete = {'action': 'delete', 'id': parent['id']}
    status, _, child = request(first, base, SCENE, method='POST', csrf=csrf, data={
        'canvas_kind': 'custom', 'previous_id': parent['id'], 'brief': '{hide-agent}',
    })
    assert status == 201 and child['scene']['previous_id'] == parent['id'], child
    child = child['scene']
    status, _, other_history = request(second, base, SCENE)
    assert status == 200 and other_history['scenes'], other_history

    status, _, result = request(second, base, SCENE, method='POST', csrf=second_csrf, data=delete)
    check(status == 404 and result['error'] == 'scene_not_found', 'a fan cannot delete another owner’s canvas')
    status, _, result = request(first, base, SCENE, method='POST', csrf='invalid', data=delete)
    check(status == 403 and result['error'] == 'csrf_failed', 'canvas deletion requires the account CSRF token')
    status, _, result = request(first, base, SCENE, method='POST', csrf=csrf, data={**delete, 'id': 0})
    check(status == 422 and result['error'] == 'invalid_scene_request', 'canvas deletion validates the canvas ID')

    calls = (site / 'model-calls').read_text().splitlines()
    release = site / 'model-release'
    release.unlink(missing_ok=True)
    (site / 'model-mode').write_text('wait')
    with (php_server(site, 'delete-server.log') as (delete_base, _), ThreadPoolExecutor(max_workers=1) as executor):
        pending = executor.submit(request, first, base, SCENE, method='POST', csrf=csrf, data={
            'canvas_kind': 'custom', 'previous_id': parent['id'], 'brief': 'Add a soft glow', 'request_id': 'fc' * 16,
        })
        try:
            for _ in range(100):
                if (site / 'model-calls').read_text().splitlines() == calls + ['wait']:
                    break
                time.sleep(.02)
            else:
                raise AssertionError('Revision did not reach the model')
            status, _, result = request(first, delete_base, SCENE, method='POST', csrf=csrf, data=delete)
            check(status == 200 and result == {'ok': True, 'deleted_id': parent['id']},
                  'the owner can delete one saved canvas')
        finally:
            release.touch()
        status, _, result = pending.result()
        check(status == 422 and result['error'] == 'invalid_scene_request',
              'a pending revision cannot settle after its parent is deleted')

    status, _, result = request(first, base, SCENE + '?id=' + str(parent['id']))
    list_status, _, history = request(first, base, SCENE)
    check(status == 404 and result['error'] == 'scene_not_found' and list_status == 200
          and all(row['id'] != parent['id'] for row in history['scenes']),
          'the deleted canvas is absent from history and cannot reopen')
    status, _, restored = request(first, base, SCENE + '?id=' + str(child['id']))
    other_status, _, other_restored = request(second, base, SCENE)
    check(status == 200 and restored['scene'] == {**child, 'previous_id': None}
          and other_status == 200 and other_restored == other_history,
          'saved revisions and other accounts survive deletion')
    status, _, result = request(first, base, recovery_path)
    replay_status, _, replay = request(first, base, SCENE, method='POST', csrf=csrf, data=payload)
    check(status == replay_status == 200 and result == replay == {'ok': True, 'state': 'failed'},
          'a delayed request replay cannot regenerate a deleted canvas')
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        assert db.execute('SELECT state,scene_id,active_tokens_used FROM agent_scene_requests WHERE user_id=? AND request_id=?',
                          (1, payload['request_id'])).fetchone() == ('deleted', None, MODEL_USAGE)
    assert_settled_balance(site, 33, STARTING_BALANCE - 2 * MODEL_USAGE, calls + ['wait'], paid_calls=22)


def exercise_player_canvas(site, base, first, csrf):
    slot = '<div class="sf-host-playback"></div>'
    calls = (site / 'model-calls').read_text().splitlines()
    (site / 'model-mode').write_text('quality-player')
    payload = {'canvas_kind': 'custom', 'context_track_id': 'album-first'}
    status, _, result = request(first, base, SCENE, method='POST', csrf=csrf, data={
        **payload, 'brief': 'Build a boom box player with audio controls and pulsing speakers', 'request_id': 'fd' * 16,
    })
    assert status == 201, result
    scene = result['scene']
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = json.loads(model_request['input'][0]['content'][0]['text'])
    check('player_controls_requested' not in prompt and 'player_controls' not in scene
          and slot not in scene['html'] and scene['title'] == 'Night Drive'
          and 'window.StonefellowAudio' in model_request['instructions'],
          'a requested player uses the general coding contract and strips obsolete host slots without adding UI')
    check('boom box player' not in scene['html'] and 'pulsing speakers' not in scene['html']
          and 'provided by the host' not in scene['html'] + scene['css']
          and '<div class="speaker"></div>' in scene['html'] and '--sf-audio-bass' in scene['css'],
          'split prompt text and implementation notes are removed while responsive audio-reactive code survives')
    status, _, reopened = request(first, base, SCENE + '?id=' + str(scene['id']))
    check(status == 200 and reopened['scene'] == scene,
          'reopening a custom canvas retains its distinct title and sanitized authored code')
    status, _, result = request(first, base, SCENE, method='POST', csrf=csrf, data={
        **payload, 'brief': 'Build a purple city at night with speakers but no audio controls', 'request_id': 'fe' * 16,
    })
    check(status == 201 and 'player_controls' not in result['scene'] and slot not in result['scene']['html'],
          'dialog wording imposes no standard player UI and obsolete model slots are stripped without repair')
    assert_settled_balance(site, 35, STARTING_BALANCE - 4 * MODEL_USAGE,
                           calls + ['quality-player', 'quality-player'], paid_calls=24)


def exercise_canvas_timeout(site, base, first, csrf):
    status, _, prior = request(first, base, SCENE + '?request_id=' + 'fd' * 16)
    assert status == 200 and prior['state'] == 'ready', prior
    parent = prior['scene']
    calls = (site / 'model-calls').read_text().splitlines()
    payload = {'canvas_kind': 'custom', 'previous_id': parent['id'],
               'brief': 'Make the cassette deck fit a phone screen'}
    (site / 'model-mode').write_text('stream-timeout')
    with open_response(first, base, SCENE, method='POST', csrf=csrf, accept=NDJSON,
                       data={**payload, 'request_id': 'de' * 16}) as response:
        events = [json.loads(line) for line in response]
    initial = json.loads((site / 'model-retry-request-1.json').read_text())
    retry = json.loads((site / 'model-retry-request-2.json').read_text())
    prompt = json.loads(retry['input'][0]['content'][0]['text'])
    check([event['type'] for event in events] == ['concept', 'delta', 'retry', 'concept', 'delta', 'delta', 'result']
          and initial['input'] == retry['input'] and prompt['design_brief'] == payload['brief']
          and 'player_controls_requested' not in prompt and initial['max_output_tokens'] == 8192
          and retry['max_output_tokens'] == 2000 and 'The first attempt timed out' in retry['instructions'],
          'a timed-out stream starts one compact retry of the same authored revision without forced UI requirements')
    result = events[-1]['data']
    check(events[-1]['status'] == 201 and result['scene']['previous_id'] == parent['id']
          and 'player_controls' not in result['scene'] and '<div class="sf-host-playback"></div>' not in result['scene']['html']
          and result['active_tokens_used'] == MODEL_USAGE,
          'a successful timeout retry saves a new revision and charges only for the completed canvas')

    request_id = 'df' * 16
    (site / 'model-mode').write_text('stream-timeout-twice')
    with open_response(first, base, SCENE, method='POST', csrf=csrf, accept=NDJSON,
                       data={**payload, 'request_id': request_id}) as response:
        events = [json.loads(line) for line in response]
    terminal = events[-1]
    check(sum(event['type'] == 'retry' for event in events) == 1 and terminal['type'] == 'error'
          and terminal['status'] == 422 and terminal['data']['reason'] == 'timeout'
          and terminal['data']['request_id'] == request_id and 'Your saved canvas' in terminal['data']['message'],
          'a second timeout reports the original build reference and preserves the saved canvas')
    status, _, saved = request(first, base, SCENE + '?id=' + str(parent['id']))
    receipt_status, _, receipt = request(first, base, SCENE + '?request_id=' + request_id)
    check(status == receipt_status == 200 and saved['scene'] == parent and receipt == {'ok': True, 'state': 'failed'},
          'a failed timeout retry leaves the parent intact and no chargeable result')
    assert_settled_balance(site, 36, STARTING_BALANCE - 5 * MODEL_USAGE,
                           calls + ['stream-timeout'] * 2 + ['stream-timeout-twice'] * 2, paid_calls=25)


def exercise_canvas_cancel(site, base, first, second, csrf, second_csrf):
    status, _, prior = request(first, base, SCENE + '?request_id=' + 'fd' * 16)
    assert status == 200 and prior['state'] == 'ready', prior
    parent = prior['scene']
    calls = (site / 'model-calls').read_text().splitlines()
    canceled = {'ok': True, 'state': 'canceled'}
    payload = {'canvas_kind': 'custom', 'previous_id': parent['id'], 'brief': 'Restyle the deck controls'}
    early = {'action': 'cancel', 'request_id': 'ce' * 16}
    status, _, stopped = request(first, base, SCENE, method='POST', csrf=csrf, data=early)
    build_status, _, build = request(first, base, SCENE, method='POST', csrf=csrf,
                                    data={**payload, 'request_id': early['request_id']})
    check(status == build_status == 200 and stopped == build == canceled
          and (site / 'model-calls').read_text().splitlines() == calls,
          'Stop Agent can arrive before the build without starting the provider')

    request_id = 'cf' * 16
    cancel = {'action': 'cancel', 'request_id': request_id}
    (site / 'model-mode').write_text('stream-cancel')
    with php_server(site, 'cancel-server.log') as (cancel_base, _):
        with open_response(first, base, SCENE, method='POST', csrf=csrf, accept=NDJSON,
                           data={**payload, 'request_id': request_id}) as response:
            first_event = json.loads(response.readline())
            assert first_event['type'] == 'concept', first_event
            other_status, _, other_stop = request(second, cancel_base, SCENE, method='POST', csrf=second_csrf, data=cancel)
            pending_status, _, pending = request(first, cancel_base, SCENE + '?request_id=' + request_id)
            check(other_status == pending_status == 200 and other_stop == canceled and pending['state'] == 'pending',
                  'Stop Agent is scoped to the account that owns the build')
            status, _, stopped = request(first, cancel_base, SCENE, method='POST', csrf=csrf, data=cancel)
            events = [json.loads(line) for line in response]
        check(status == 200 and stopped == canceled and events[-1] == {'type': 'result', 'status': 200, 'data': canceled}
              and all(event['type'] != 'retry' for event in events),
              'Stop Agent interrupts an open provider stream without a retry')
    status, _, saved = request(first, base, SCENE + '?id=' + str(parent['id']))
    receipt_status, _, receipt = request(first, base, SCENE + '?request_id=' + request_id)
    check(status == receipt_status == 200 and saved['scene'] == parent and receipt == canceled,
          'A stopped revision leaves the saved canvas intact')
    status, _, ready = request(first, base, SCENE, method='POST', csrf=csrf,
                               data={'action': 'cancel', 'request_id': 'de' * 16})
    check(status == 200 and ready['state'] == 'ready' and ready['scene']['previous_id'] == parent['id'],
          'Stop Agent returns a finished canvas when completion wins the race')
    assert_settled_balance(site, 36, STARTING_BALANCE - 5 * MODEL_USAGE, calls + ['stream-cancel'], paid_calls=25)


def exercise_canvas_data_tools(site, base, first, second, csrf):
    calls = (site / 'model-calls').read_text().splitlines()
    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_commerce_save_product(['id'=>'canvas-jacket','title'=>'Canvas jacket','status'=>'active',
            'category'=>'apparel','base_price_cents'=>6500,'metadata'=>['supplier'=>'private canvas supplier'],
            'variants'=>[
                ['title'=>'Small','options'=>['Size'=>'Small'],'price_cents'=>6200],
                ['title'=>'Medium','options'=>['Size'=>'Medium'],'inventory_mode'=>'finite','inventory_qty'=>0],
                ['title'=>'Hidden jacket variant','status'=>'archived'],
            ]],0);
        sf_ticket_offer_save(['show_id'=>'fixture-show','title'=>'Canvas free RSVP','status'=>'published'],0);
        sf_ticket_offer_save(['show_id'=>'fixture-show','title'=>'Canvas external admission','status'=>'published',
            'fulfillment_type'=>'external','external_url'=>'https://example.invalid/canvas-checkout'],0);
        sf_ticket_offer_save(['show_id'=>'fixture-show','title'=>'Private draft admission','status'=>'draft'],0);
    '''], cwd=site, check=True)
    orders = site / 'storage/orders'
    orders.mkdir(exist_ok=True)
    for key, owner_id, label in [('SF-CANVAS-OWNER', 1, 'My canvas jacket'),
                                 ('SF-CANVAS-LEGACY', None, 'Unlinked guest purchase'),
                                 ('SF-CANVAS-OTHER', 2, 'Other fan purchase')]:
        (orders / (key + '.json')).write_text(json.dumps({
            'id': key, 'user_id': owner_id, 'created_at': '2026-01-05T12:00:00Z', 'status': 'paid',
            'customer': {'email': 'fan-one@example.test', 'shipping': {'line1': 'private shipping address'}},
            'payment': {'status': 'paid', 'provider_session_id': 'private payment reference'},
            'fulfillment': {'status': 'shipped', 'tracking_number': 'private shipment number'},
            'quote': {'currency': 'USD', 'total_cents': 6200, 'items': [{
                'type': 'merch', 'label': label, 'product_id': 'canvas-jacket', 'quantity': 1, 'price_cents': 6200,
            }]},
        }))
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        db.executemany('''INSERT INTO listening_events(user_id, session_key, track_id, event_type, created_at)
            VALUES(?,?,?,?,strftime('%Y-%m-%dT%H:%M:%SZ','now'))''', [
            (1, 'private-first-fixture', 'album-first', 'start'),
            (1, 'private-first-fixture', 'album-first', 'complete'),
            (1, 'private-first-fixture', 'private-song', 'start'),
            (2, 'private-second-fixture', 'album-second', 'start'),
        ])
        offer_id = db.execute("SELECT id FROM ticket_offers WHERE title='Canvas free RSVP'").fetchone()[0]
        db.executemany('''INSERT INTO ticket_reservations(offer_id,show_id,user_id,email,display_name,quantity,status,
            confirmation_code,request_key,reserved_at,created_at,updated_at)
            VALUES(?,'fixture-show',?,?,?,?,'reserved',?,?, 'now','now','now')''', [
            (offer_id, 1, 'fan-one@example.test', 'Private fan one', 1, 'private-owner-ticket-code', 'owner-ticket-request'),
            (offer_id, 2, 'fan-two@example.test', 'Private fan two', 7, 'private-other-ticket-code', 'other-ticket-request'),
        ])
    request_id = 'c1' * 16
    (site / 'model-mode').write_text('canvas-data-tools')
    status, _, built = request(first, base, SCENE, method='POST', csrf=csrf, data={
        'canvas_kind': 'custom', 'brief': 'Build a Stonefellow merch page. Show ticket offers, my purchases, my tickets and my listening.',
        'request_id': request_id, 'context_track_id': 'album-first',
    })
    assert status == 201, built
    scene = built['scene']
    datasets = scene['datasets']
    check(all(scene['datasets'][key]['rows'] == [] and scene['datasets'][key]['state'] == 'consent_required'
              for key in ['personal_listening_stats', 'my_purchases', 'my_tickets']),
          'Personal canvas datasets remain withheld before the current viewer explicitly allows them')
    access = scene['canvas_access']
    granted_status, _, granted = request(first, base, '/api/canvas-access.php', method='POST', csrf=csrf, data={
        'action': 'grant', 'context': {'scene_id': scene['id']}, 'fingerprint': access['fingerprint'],
        'permission_ids': ['canvas.data.personal_listening_stats', 'canvas.data.my_purchases', 'canvas.data.my_tickets'],
    })
    assert granted_status == 200 and not granted['access']['requires_consent'], granted
    read_status, _, fresh = request(first, base, SCENE + '?id=' + str(scene['id']) + '&current_track_id=album-first')
    assert read_status == 200, fresh
    scene = fresh['scene']
    datasets = scene['datasets']
    model_request = json.loads((site / 'model-request.json').read_text())
    prompt = json.loads(model_request['input'][0]['content'][0]['text'])
    tools = {tool['id']: tool for tool in prompt['canvas_data_tools']}
    expected = {'personal_listening_stats', 'published_catalog', 'published_merch', 'published_ticket_offers', 'my_purchases', 'my_tickets'}
    check(expected <= set(tools)
          and 'private-first-fixture' not in json.dumps(model_request) and 'datasets' not in prompt,
          'The Coding Agent discovers usable data tools without receiving private listening values')
    rows = {row['track_id']: row for row in datasets['personal_listening_stats']['rows']}
    check(set(datasets) == expected and 'album-second' not in rows and 'private-song' not in rows
          and rows['album-first']['listens'] == 1 and rows['album-first']['completes'] == 1
          and 'Private song' not in json.dumps(datasets['published_catalog']),
          'A canvas receives only declared allowed tools, owner counts and published catalog rows')
    check(scene['dataset_track_id'] == 'album-first' and datasets['personal_listening_stats']['current_rows'] == [rows['album-first']]
          and [row['track_id'] for row in datasets['published_catalog']['current_rows']] == ['album-first']
          and 'track_id' in tools['personal_listening_stats']['filters'] and 'track_id' in tools['published_catalog']['filters'],
          'A reusable current-track binding receives the matching published track and owner data')
    check('category' in tools['published_merch']['filters'] and tools['my_purchases']['scope'] == 'signed_in_fan'
          and scene['catalog_refs'] == scene['catalog_blocks'] == [],
          'Ordinary commerce prompts use agent-authored datasets without inserting legacy host cards')
    merch = {row['variant_title']: row for row in datasets['published_merch']['rows']}
    offers = {row['title']: row for row in datasets['published_ticket_offers']['rows']}
    check(set(merch) == {'Small', 'Medium'} and merch['Small']['product_id'] == 'canvas-jacket'
          and merch['Small']['price_cents'] == 6200 and merch['Medium']['availability'] == 'sold_out'
          and offers['Fixture general tickets']['show_id'] == 'fixture-show'
          and offers['Canvas free RSVP']['price_cents'] == 0 and offers['Canvas free RSVP']['price_text'] == 'Free'
          and offers['Canvas external admission']['price_cents'] is None and 'Private draft admission' not in offers
          and 'Private ticket fixture' not in offers,
          'Published commerce tools keep stable IDs, actual stock and distinct free and unknown prices')
    purchases = {row['order_id']: row for row in datasets['my_purchases']['rows']}
    reservations = datasets['my_tickets']['rows']
    check(set(purchases) == {'SF-CANVAS-OWNER'}
          and purchases['SF-CANVAS-OWNER']['product_id'] == merch['Small']['product_id']
          and purchases['SF-CANVAS-OWNER']['fulfillment_status'] == 'shipped'
          and len(reservations) == 1 and reservations[0]['quantity'] == 1
          and reservations[0]['offer_id'] == offers['Canvas free RSVP']['offer_id']
          and reservations[0]['show_id'] == offers['Canvas free RSVP']['show_id'],
          'Only linked account purchases and owned reservations appear, even when another order shares the email')
    secrets = ['private shipping address', 'private payment reference', 'private shipment number',
               'private-owner-ticket-code', 'owner-ticket-request', 'private-other-ticket-code',
               'fan-one@example.test', 'private canvas supplier', 'example.invalid/canvas-checkout']
    check(all(secret not in json.dumps(datasets) for secret in secrets)
          and all(value not in json.dumps(model_request) for value in secrets + ['My canvas jacket', 'SF-CANVAS-LEGACY', 'Canvas free RSVP']),
          'Commerce response excludes sensitive source fields and the model only receives tool definitions')
    scene_url = SCENE + '?id=' + str(scene['id'])
    other_status, _, other = request(second, base, scene_url)
    check(other_status == 404 and 'scene' not in other, 'Another account cannot read a canvas or its private dataset')
    status, _, switched = request(first, base, scene_url + '&current_track_id=album-second')
    current = switched['scene']['datasets']['personal_listening_stats']['current_rows']
    check(status == 200 and switched['scene']['dataset_track_id'] == 'album-second' and len(current) == 1
          and current[0]['track_id'] == 'album-second' and current[0]['listens'] == current[0]['completes'] == 0,
          'Current-track data excludes another account’s listens and represents an unplayed song with zero counts')
    status, _, hidden = request(first, base, scene_url + '&current_track_id=private-song')
    check(status == 200 and hidden['scene']['datasets']['personal_listening_stats']['current_rows'] == []
          and hidden['scene']['datasets']['published_catalog']['current_rows'] == [],
          'The current-track binding never exposes unpublished tracks')
    status, _, denied = request(second, base, scene_url + '&current_track_id=album-first')
    check(status == 404 and 'scene' not in denied, 'Current-track refresh requires ownership of the canvas')
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        stored = db.execute('SELECT html,css FROM agent_scene_history WHERE id=?', (scene['id'],)).fetchone()
        check(stored == (scene['html'], scene['css']) and 'Fixture Dawn' not in stored[0],
              'Only the model template is saved, with personal values supplied at open time')
        db.execute('''INSERT INTO listening_events(user_id,session_key,track_id,event_type,created_at)
            VALUES(1,'private-first-fixture','album-first','start',strftime('%Y-%m-%dT%H:%M:%SZ','now'))''')
        db.execute("UPDATE store_variants SET price_cents=5900 WHERE product_id='canvas-jacket' AND title='Small'")
        db.execute("UPDATE ticket_reservations SET status='cancelled' WHERE user_id=1 AND offer_id=?", (offer_id,))
    status, _, reopened = request(first, base, scene_url)
    receipt_status, _, receipt = request(first, base, SCENE + '?request_id=' + request_id)
    reopened_rows = {row['track_id']: row for row in reopened['scene']['datasets']['personal_listening_stats']['rows']}
    check(status == receipt_status == 200 and reopened['scene'] == receipt['scene']
          and reopened_rows['album-first']['listens'] == 2 and reopened['scene']['html'] == scene['html']
          and any(row['price_cents'] == 5900 for row in reopened['scene']['datasets']['published_merch']['rows'])
          and reopened['scene']['datasets']['my_tickets']['rows'][0]['status'] == 'cancelled',
          'Canvas history and receipt recovery refill the same design with current tool data')
    assert_settled_balance(site, 37, STARTING_BALANCE - 6 * MODEL_USAGE, calls + ['canvas-data-tools'], paid_calls=26)


def exercise_coding_model_selection(site, base, first, csrf):
    calls = (site / 'model-calls').read_text().splitlines()
    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_ai_save_provider('anthropic',['enabled'=>true,'model'=>'fixture-claude-chat','api_key'=>'fixture-canvas-secret']);
        sf_ai_save_routing(['preferred_llm'=>'openai','fallback_enabled'=>false,
            'coding_models'=>['openai'=>['fixture-openai-draw','fixture-openai-design'],
                'anthropic'=>['fixture-claude-draw','fixture-claude-design']]]);
    '''], cwd=site, check=True)
    status, _, available = request(first, base, SCENE + '?models=1')
    check(status == 200 and set(available) == {'ok', 'models'}
          and all(set(option) == {'provider', 'model'} for option in available['models'])
          and {(option['provider'], option['model']) for option in available['models']} == {
              ('openai', 'fixture-openai-draw'), ('openai', 'fixture-openai-design'),
              ('anthropic', 'fixture-claude-draw'), ('anthropic', 'fixture-claude-design'),
          }, 'The signed-in picker exposes only approved public model IDs from keyed, enabled providers')
    status, _, denied = request(browser(), base, SCENE + '?models=1')
    check(status == 401 and 'models' not in denied, 'Model options require the same sign-in as canvas generation')

    (site / 'model-mode').write_text('stream-coding-choice')
    build = {'canvas_kind': 'custom', 'track_id': 'album-first', 'brief': 'Build a blue canvas', 'request_id': 'c2' * 16,
             'coding_model': {'provider': 'anthropic', 'model': 'fixture-claude-design'}}
    with open_response(first, base, SCENE, method='POST', data=build, csrf=csrf, accept=NDJSON) as response:
        final = [json.loads(line) for line in response][-1]
    model_request = json.loads((site / 'model-request.json').read_text())
    check(final['type'] == 'result' and final['status'] == 201 and model_request['model'] == 'fixture-claude-design'
          and 'messages' in model_request, 'An explicit Claude choice reaches Anthropic even when OpenAI is the chat default')
    original = final['data']['scene']
    status, _, replayed = request(first, base, SCENE, method='POST', data=build, csrf=csrf)
    check(status == 200 and replayed['scene'] == original, 'Replaying the same selection returns the existing canvas')
    status, _, conflict = request(first, base, SCENE, method='POST', csrf=csrf, data={
        **build, 'coding_model': {'provider': 'openai', 'model': 'fixture-openai-design'},
    })
    check(status == 422 and conflict['error'] == 'invalid_scene_request', 'A different model cannot reuse the same build request ID')

    revision = {**build, 'brief': 'Make it brighter', 'request_id': 'c3' * 16, 'previous_id': original['id'],
                'context_track_id': 'album-second',
                'coding_model': {'provider': 'openai', 'model': 'fixture-openai-draw'}}
    with open_response(first, base, SCENE, method='POST', data=revision, csrf=csrf, accept=NDJSON) as response:
        revised = [json.loads(line) for line in response][-1]
    model_request = json.loads((site / 'model-request.json').read_text())
    check(revised['type'] == 'result' and revised['data']['scene']['previous_id'] == original['id']
          and model_request['model'] == 'fixture-openai-draw' and 'input' in model_request,
          'A revision uses the currently selected OpenAI model on the same saved canvas')
    prompt = json.loads(model_request['input'][0]['content'][0]['text'])
    check(revised['data']['scene']['track_id'] == original['track_id'] == 'album-first'
          and prompt['published_song']['track_id'] == 'album-first'
          and prompt['optional_playing_song']['track_id'] == 'album-second',
          'A revision gets current player context independently of the saved canvas source track')

    subprocess.run([PHP, '-r', '''
        require 'api/bootstrap.php';
        sf_ai_save_routing(['preferred_llm'=>'openai','fallback_enabled'=>false,
            'coding_models'=>['anthropic'=>['fixture-claude-draw']]]);
    '''], cwd=site, check=True)
    status, _, stale = request(first, base, SCENE, method='POST', csrf=csrf, data={**build, 'request_id': 'c4' * 16})
    check(status == 422 and stale['error'] == 'scene_coding_model_unavailable',
          'A model removed by Admin is rejected before provider usage')
    assert_settled_balance(site, 39, STARTING_BALANCE - 8 * MODEL_USAGE,
                           calls + ['stream-coding-choice'] * 2, paid_calls=28)


def exercise_admin_model_catalog(site, base, fan):
    endpoint = '/admin/api/ai-providers.php?coding_catalog='
    (site / 'model-mode').write_text('model-catalog')
    status, _, _ = request(browser(), base, endpoint + 'openai')
    denied, _, _ = request(fan, base, endpoint + 'openai')
    check(status == 401 and denied == 403 and not (site / 'catalog-calls').exists(),
          'The provider catalog calls no upstream API for a guest or fan')

    admin = browser()
    sign_in(admin, base, 'admin@example.test')
    status, _, before = request(fan, base, SCENE + '?models=1')
    assert status == 200
    status, _, openai = request(admin, base, endpoint + 'openai')
    check(status == 200 and openai == {'ok': True, 'models': [
        {'id': 'fixture-openai-discovered', 'label': 'fixture-openai-discovered'}]},
        'Admin OpenAI model discovery returns IDs without non-text models or credentials')
    status, _, claude = request(admin, base, endpoint + 'anthropic')
    check(status == 200 and claude == {'ok': True, 'models': [
        {'id': 'fixture-claude-first', 'label': 'First Claude'},
        {'id': 'fixture-claude-second', 'label': 'Second Claude'},
    ]}, 'Admin Anthropic model discovery includes the next page and display labels')
    status, _, after = request(fan, base, SCENE + '?models=1')
    check(status == 200 and before == after, 'Provider discovery leaves the public coding model allowlist unchanged')
    calls = (site / 'catalog-calls').read_text().splitlines()
    status, _, _ = request(admin, base, endpoint + 'unknown')
    check(status == 422 and len(calls) == 3 and calls == (site / 'catalog-calls').read_text().splitlines(),
          'An unsupported provider is rejected without an upstream call')
    (site / 'model-mode').write_text('model-catalog-error')
    status, _, failed = request(admin, base, endpoint + 'openai')
    check(status == 502 and not failed['ok'] and 'fixture-upstream-private-error' not in json.dumps(failed),
          'A provider catalog failure keeps the raw upstream response private')


def exercise_full_first_pass(site, base, first, csrf):
    subprocess.run([PHP, '-r', """
        require 'api/bootstrap.php';
        sf_token_adjust(1, 30000, 'admin_grant', 'long-brief-fixture');
    """], cwd=site, check=True)
    brief = '\n'.join(
        f'In region {region}, use a different shade of blue and space the controls evenly.'
        for region in range(40)
    )
    assert 1800 < len(brief.encode()) < 16000
    calls = (site / 'model-calls').read_text().splitlines()
    (site / 'model-mode').write_text('stream-full-first')
    status, _, preflight = request(first, base, SCENE, method='POST', csrf=csrf,
                                   data={'action': 'image_preflight', 'brief': brief, 'editing': False})
    check(status == 200 and preflight['preflight']['requested_count'] is None,
          'The normal canvas image preflight accepts the longer coding brief')
    status, _, _ = request(first, base, SCENE, method='POST', csrf=csrf,
                            data={'action': 'image_preflight', 'brief': brief, 'editing': True})
    check(status == 422, 'A dedicated image edit retains its brief limit')
    projects = '/api/canvas-projects.php'
    status, _, _ = request(first, base, projects, method='POST', csrf=csrf,
                            data={'action': 'preferences', 'ideas_enabled': False, 'auto_approve': True})
    assert status == 200
    status, _, prepared = request(first, base, projects, method='POST', csrf=csrf, data={
        'action': 'propose', 'request_id': '75' * 16, 'project': {'canvas_kind': 'custom', 'brief': brief},
    })
    proposal = prepared.get('proposal', {})
    check(status == 200 and proposal.get('status') == 'approved' and proposal['project']['brief'] == brief,
          'Project preparation retains the full longer coding brief through account approval')
    payload = {**proposal['project'], 'project_managed': True, 'proposal_id': proposal['id'], 'request_id': '74' * 16}
    with open_response(first, base, SCENE, method='POST', csrf=csrf, accept=NDJSON, data=payload) as response:
        events = [json.loads(line) for line in response]
    model_request = json.loads((site / 'model-full-first.json').read_text())
    model_prompt = json.loads(model_request['input'][0]['content'][0]['text'])
    terminal = events[-1]
    check(terminal['type'] == 'result' and terminal['status'] == 201
          and all(event['type'] != 'retry' for event in events)
          and model_request['max_output_tokens'] == 8192
          and model_prompt['design_brief'] == brief
          and terminal['data']['scene']['design_brief'] == brief
          and (site / 'model-calls').read_text().splitlines() == calls + ['stream-full-first'],
          'A long custom brief reaches the model intact and saves on the first call with its full reserved allowance')
    status, _, rejected = request(first, base, SCENE, method='POST', csrf=csrf,
                                  data={'canvas_kind': 'custom', 'brief': 'é' * 8001})
    check(status == 422 and rejected.get('error') == 'invalid_scene_request'
          and (site / 'model-calls').read_text().splitlines() == calls + ['stream-full-first'],
          'The custom brief byte limit applies before any provider call')


def run(site):
    shutil.copytree(ROOT / 'api', site / 'api')
    shutil.copytree(ROOT / 'assets/vendor/canvas', site / 'assets/vendor/canvas')
    (site / 'admin/api').mkdir(parents=True)
    for file in ('ai-providers.php', 'bootstrap.php'):
        shutil.copy2(ROOT / 'admin/api' / file, site / 'admin/api' / file)
    for name in ('config', 'data', 'storage', 'sessions'):
        (site / name).mkdir()
    shutil.copy2(ROOT / 'config/store.default.php', site / 'config/store.default.php')
    dbpath = site / 'fixture.sqlite'
    # Hex paths avoid PHP string interpolation and are portable across CI locations.
    (site / 'config/database.php').write_text(
        "<?php return ['driver'=>'sqlite','path'=>hex2bin('" + os.fsencode(dbpath).hex() + "')];\n"
    )
    (site / 'data/catalog.json').write_text(json.dumps([
        {'id': 'fixture-song', 'title': PUBLISHED_TITLE, 'duration': 120, 'state': 'published'},
        {'id': 'private-song', 'title': 'Private song', 'duration': 120, 'state': 'private'},
    ]))
    (site / 'data/releases.json').write_text('[]')
    hashed = subprocess.check_output(
        [PHP, '-r', "echo password_hash($argv[1], PASSWORD_BCRYPT, ['cost'=>4]);", PASSWORD], text=True
    )
    with sqlite3.connect(dbpath) as db:
        db.execute('CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)')
        db.execute('''CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,
            role TEXT,status TEXT,password_hash TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)''')
        db.executemany('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,?)', [
            (1, 'fan-one@example.test', 'First fan', 'user', 'active', hashed, 'now', 'now', None),
            (2, 'fan-two@example.test', 'Second fan', 'user', 'active', hashed, 'now', 'now', None),
            (3, 'admin@example.test', 'Fixture admin', 'admin', 'active', hashed, 'now', 'now', None),
        ])

    install_model_transport(site)
    with php_server(site, 'server.log') as (base, port):
        first, second = browser(), browser()
        status, _, body = request(first, base, SCENE)
        check(status == 401 and body['error'] == 'authentication_required', 'real route returns JSON for a guest')
        status, headers, body = request(first, base, SCENE, method='DELETE')
        check(status == 405 and body['error'] == 'method_not_allowed' and 'POST' in headers['Allow'],
              'unsupported method returns JSON and Allow')
        csrf = sign_in(first, base, 'fan-one@example.test')
        second_csrf = sign_in(second, base, 'fan-two@example.test')

        status, _, body = request(first, base, SCENE)
        check(status == 200 and body['scenes'] == [], 'signed-in account has empty private history')
        data = {'track_id': 'fixture-song', 'brief': 'A listening room'}
        status, _, body = request(first, base, SCENE, method='POST', data=data, csrf='invalid')
        check(status == 403 and body['error'] == 'csrf_failed', 'scene creation requires the account CSRF token')
        status, _, body = request(first, base, SCENE, method='POST', data=b'{', csrf=csrf)
        check(status == 400 and body['error'] == 'invalid_json', 'malformed request returns JSON')
        status, _, body = request(first, base, SCENE, method='POST', data={**data, 'request_id': 'A' * 32}, csrf=csrf)
        check(status == 422 and body.get('error') == 'invalid_scene_request', 'create requires a lowercase hex request ID when provided')
        status, _, body = request(first, base, SCENE + '?request_id[]=anything')
        check(status == 422 and body.get('error') == 'invalid_scene_request', 'recovery rejects malformed request IDs')
        for track_id in ('missing-song', 'private-song'):
            status, _, body = request(first, base, SCENE, method='POST', csrf=csrf,
                                      data={**data, 'track_id': track_id})
            check(status == 422 and body['error'] == 'invalid_scene_request', f'{track_id} is rejected before generation')
        status, _, body = request(first, base, SCENE, method='POST', data=data, csrf=csrf)
        check(status == 422 and body['error'] == 'scene_provider_required', 'missing text model returns a readable configuration error')

        with sqlite3.connect(dbpath) as db:
            scene_id = db.execute('''INSERT INTO agent_scene_history
                (user_id,track_id,title,html,css,created_at) VALUES(?,?,?,?,?,?)''',
                (1, 'fixture-song', 'Saved room title', '<h1>Private room</h1>', 'h1{color:white}', 'now')).lastrowid
        status, _, body = request(first, base, SCENE + '?id=' + str(scene_id))
        check(status == 200 and body['scene']['title'] == PUBLISHED_TITLE[:180]
              and 'Private room' in body['scene']['html'],
              'owner can reopen the room when the published title crosses a UTF-8 boundary')
        status, _, body = request(first, base, SCENE)
        check(status == 200 and body['scenes'][0]['id'] == scene_id and 'html' not in body['scenes'][0],
              'history lists only summary data')
        status, _, body = request(second, base, SCENE + '?id=' + str(scene_id))
        check(status == 404 and body['error'] == 'scene_not_found', 'another account cannot reopen the room')
        status, _, body = request(second, base, SCENE, method='POST', csrf=second_csrf,
                                  data={**data, 'previous_id': scene_id})
        check(status == 422 and body['error'] == 'invalid_scene_request', 'another account cannot revise the room')

        enable_model(site)
        original_id = exercise_gateway_recovery(site, base, port, first, second, csrf, data)
        exercise_standard_room(site, base, first, csrf, data)
        exercise_failed_revision(site, base, first, csrf, data, original_id)
        exercise_stream(site, base, first, csrf, data, original_id)
        exercise_stream_disconnect(site, base, first, csrf, data)
        exercise_canvas_builds(site, base, first, second, csrf, data['track_id'])
        exercise_catalog_shortcodes(site, base, first, second, csrf)
        exercise_agent_design(site, base, first, second, csrf)
        exercise_incomplete_custom_canvas(site, base, first, second, csrf)
        exercise_canvas_source_independence(site, base, first, second, csrf, original_id)
        exercise_terminal_stall(site, base, first, csrf)
        exercise_constrained_output_retry(site, base, first, csrf)
        exercise_visual_only_canvas(site, base, first, csrf)
        exercise_delete_canvas(site, base, first, second, csrf, second_csrf)
        exercise_player_canvas(site, base, first, csrf)
        exercise_canvas_timeout(site, base, first, csrf)
        exercise_canvas_cancel(site, base, first, second, csrf, second_csrf)
        exercise_canvas_data_tools(site, base, first, second, csrf)
        exercise_coding_model_selection(site, base, first, csrf)
        exercise_admin_model_catalog(site, base, first)
        exercise_full_first_pass(site, base, first, csrf)

        image_id = '72' * 16
        with sqlite3.connect(dbpath) as db:
            db.execute('''INSERT INTO agent_canvas_images(user_id,id,storage_key,title,mime,size_bytes,width,height,created_at)
                VALUES(?,?,?,?,?,?,?,?,?)''', (1, image_id, '1' * 48, 'Private image', 'image/png', 2048, 1024, 1024, 'now'))
            image_scene = db.execute('''INSERT INTO agent_scene_history(user_id,track_id,title,html,css,created_at,canvas_kind)
                VALUES(?,?,?,?,?,?,?)''', (1, '', 'Image canvas', f'<figure class="sf-image-{image_id}"></figure>', '', 'now', 'custom')).lastrowid
        edit = {'action': 'edit_image', 'previous_id': image_scene, 'image_id': image_id,
                'brief': 'Warm the lighting.', 'request_id': '73' * 16}
        status, _, body = request(first, base, SCENE, method='POST', csrf='invalid', data=edit)
        check(status == 403 and body['error'] == 'csrf_failed', 'image edits require the account CSRF token')
        status, _, body = request(second, base, SCENE, method='POST', csrf=second_csrf, data=edit)
        check(status == 422 and body['error'] == 'invalid_scene_request', 'image edits cannot read another account’s scene')
        status, headers, raw = raw_request(first, base, SCENE, method='POST', csrf=csrf, data=edit, accept=NDJSON)
        events = [json.loads(line) for line in raw.splitlines()]
        check(status == 200 and headers.get_content_type() == NDJSON and events[0] ==
              {'type': 'image', 'state': 'generating', 'index': 1, 'total': 1} and events[1]['type'] == 'error' and
              events[1]['status'] == 422 and events[1]['data']['reason'] == 'image_generation_disabled',
              'image edits use the image progress stream and skip the coding model')
        status, _, receipt = request(first, base, SCENE + '?request_id=' + edit['request_id'])
        check(status == 200 and receipt == {'ok': True, 'state': 'failed'}, 'a failed image edit has a recoverable request state')

        with sqlite3.connect(dbpath) as db:
            db.execute('DROP TABLE app_meta')
        status, _, body = request(first, base, SCENE)
        check(status == 503 and body['error'] == 'scene_unavailable', 'account bootstrap failure returns JSON')


if __name__ == '__main__':
    with tempfile.TemporaryDirectory(prefix='sf-listening-room-http-') as temp:
        run(Path(temp))
