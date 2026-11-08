"""Exercise splash publication, asset access, and isolated revision rendering."""
import base64
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import random
import re
import socket
import sqlite3
import struct
import subprocess
import tempfile
import time
from urllib.error import HTTPError
from urllib.request import HTTPCookieProcessor, Request, build_opener
import zlib


ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("SF_TEST_PHP", "php")
PREFIX = "/stonefellow"
ADMIN = PREFIX + "/admin/api/pwa-splash.php"
SCRIPT = 'document.querySelector(".splash").textContent = "</script><script>escape</script>";'


def png(width, height, noise=False):
    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data))
    rng = random.Random(42)
    pixels = zlib.compress(b"".join(b"\0" + (rng.randbytes(width * 3) if noise else b"\x65\x15\x74" * width)
                                    for _ in range(height)))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", pixels) + chunk(b"IEND", b""))


def fixture(webroot):
    site = webroot / PREFIX.strip("/")
    for directory in ("config", "data", "storage/media"):
        (site / directory).mkdir(parents=True, exist_ok=True)
    for name in ("api", "admin", "pwa-splash-preview.php"):
        (site / name).symlink_to(ROOT / name)
    (site / "config/database.php").write_text("<?php return ['driver'=>'sqlite','path'=>__DIR__.'/test.sqlite'];")
    (site / "config/store.php").write_text("<?php return [];")
    with sqlite3.connect(site / "config/test.sqlite") as db:
        db.executescript("""
            CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,role TEXT,status TEXT,
                created_at TEXT,updated_at TEXT,last_login_at TEXT,password_hash TEXT);
            CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL);
            INSERT INTO users VALUES(1,'admin@example.test','Admin','admin','active','','','',''),
                (2,'customer@example.test','Customer','customer','active','','','',''),
                (3,'owner@example.test','Other admin','admin','active','','','','');
        """)
    (site / "storage/media/icon.png").write_bytes(png(192, 192))
    (site / "storage/media/art.png").write_bytes(png(48, 96))
    (site / "storage/media/slot.png").write_bytes(png(80, 120))
    (site / "storage/media/large-slot.png").write_bytes(png(400, 400, noise=True))
    (site / "storage/media/oversize-slot.png").write_bytes(png(480, 480, noise=True))
    (site / "login-fixture.php").write_text("""<?php
        require __DIR__.'/api/bootstrap.php';
        sf_auth_issue_persistent_session((int)$_GET['user']);
        echo 'ok';
    """)
    (site / "public-fixture.php").write_text("""<?php
        require __DIR__.'/api/bootstrap.php';
        require __DIR__.'/api/pwa-splash-core.php';
        sf_json_response(sf_pwa_splash_published());
    """)
    (webroot / "router.php").write_text("""<?php
        define('SF_ROOT', __DIR__.'/stonefellow');
        $path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (str_ends_with($path, '.php') && is_file(__DIR__.$path)) {
            $_SERVER['SCRIPT_NAME'] = $path;
            require __DIR__.$path;
            return true;
        }
        return false;
    """)
    subprocess.run([PHP, "-r", """
        define('SF_ROOT', getcwd());
        require 'api/bootstrap.php';
        require 'api/agent-scene-core.php';
        sf_media_register_existing_file('storage/media/icon.png', 'icon.png', 1);
        sf_media_register_existing_file('storage/media/art.png', 'art.png', 1);
        sf_media_register_existing_file('storage/media/slot.png', 'slot.png', 1);
        sf_media_register_existing_file('storage/media/large-slot.png', 'large-slot.png', 1);
        sf_media_register_existing_file('storage/media/oversize-slot.png', 'oversize-slot.png', 1);
        $scene=['track_id'=>'', 'title'=>'Welcome canvas', 'canvas_kind'=>'custom',
            'html'=>'<section class="splash">Canvas welcome</section>', 'css'=>'.splash{color:red}', 'js'=>$argv[1]];
        sf_agent_scene_history_save(1, $scene);
        sf_agent_scene_history_save(3, $scene);
    """, SCRIPT], cwd=site, check=True)


def request(client, origin, path, payload=None, csrf=None):
    headers = {"Content-Type": "application/json"} if payload is not None else {}
    if csrf:
        headers["X-CSRF-Token"] = csrf
    data = json.dumps(payload).encode() if payload is not None else None
    try:
        response = client.open(Request(origin + path, data=data, headers=headers), timeout=5)
    except HTTPError as error:
        response = error
    with response:
        data = response.read()
        if response.headers.get_content_type() == "application/json":
            data = json.loads(data)
        return response.status, data, response.headers


def exercise(origin, webroot):
    guest = build_opener()
    admin = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    customer = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    assert request(guest, origin, ADMIN)[0] == 401
    assert request(customer, origin, PREFIX + "/login-fixture.php?user=2")[0] == 200
    assert request(customer, origin, ADMIN)[0] == 403
    assert request(admin, origin, PREFIX + "/login-fixture.php?user=1")[0] == 200
    status, initial, _ = request(admin, origin, ADMIN)
    assert status == 200 and initial["draft"]["revision"] == initial["published"]["revision"] == 0
    assert not initial["hasUnpublishedChanges"]
    csrf = initial["csrfToken"]

    def update(action, revision, expected, **fields):
        status, body, _ = request(admin, origin, ADMIN, dict(action=action, revision=revision, **fields), csrf)
        assert status == expected, (status, body)
        return body

    assert request(admin, origin, ADMIN, {"action": "save", "revision": 0})[0] == 403
    update("save", 0, 422, settings={"iconAssetId": 2})
    update("import", 0, 422, surface="launch", canvasId=2)
    saved = update("save", 0, 200, settings={
        "iconAssetId": 1, "startupAssetId": 2, "backgroundColor": "#3a206f",
        "waiting": {"mode": "image", "assetId": 2, "title": "Waiting for the host"},
    })
    assert saved["draft"]["revision"] == 1 and saved["published"]["revision"] == 0 and saved["hasUnpublishedChanges"]
    assert saved["draft"]["waiting"]["assetUrl"].startswith(PREFIX + "/api/media.php?")
    assert request(guest, origin, saved["draft"]["iconUrl"])[0] == 404
    assert request(admin, origin, saved["draft"]["iconUrl"])[0] == 200
    update("save", 0, 409, settings={"backgroundColor": "#ffffff"})

    image_slot = {"name": "hero", "assetId": 3}
    for invalid_slots in ([image_slot, image_slot], [{"name": "Invalid", "assetId": 3}],
                          [{"name": "hero", "assetId": 5}],
                          [{"name": name, "assetId": 4} for name in ["one", "two", "three"]],
                          [{"name": "hero", "assetUrl": "https://example.test/image.png"}]):
        update("save", 1, 422, settings={"launch": {"imageSlots": invalid_slots}})
    site = webroot / PREFIX.strip("/")
    with sqlite3.connect(site / "config/test.sqlite") as db:
        db.execute("CREATE TABLE image_generation_jobs(id INTEGER PRIMARY KEY, status TEXT, media_asset_id INTEGER)")
        db.execute("INSERT INTO image_generation_jobs VALUES(1, 'awaiting_review', 3)")
        db.execute("UPDATE media_assets SET technical_json=? WHERE id=3",
                   (json.dumps({"source": "openai_image_generation", "image_job_id": 1}),))
        key = "site.pwa_splash"
        state = json.loads(db.execute("SELECT meta_value FROM app_meta WHERE meta_key=?", (key,)).fetchone()[0])
        for snapshot in state.values():
            for surface in ["launch", "waiting"]:
                del snapshot[surface]["imageSlots"]
        db.execute("UPDATE app_meta SET meta_value=? WHERE meta_key=?", (json.dumps(state), key))
    pending = update("save", 1, 422, settings={"launch": {"imageSlots": [image_slot]}})
    assert "Approve" in pending["message"]
    with sqlite3.connect(site / "config/test.sqlite") as db:
        db.execute("UPDATE image_generation_jobs SET status='ready' WHERE id=1")
    assert request(admin, origin, ADMIN)[1]["draft"]["launch"]["imageSlots"] == []
    imported = update("import", 1, 200, surface="launch", canvasId=1,
                      settings={"launch": {"imageSlots": [image_slot]}})
    assert imported["draft"]["launch"]["js"] == SCRIPT
    slot = imported["draft"]["launch"]["imageSlots"][0]
    assert slot["name"] == "hero" and slot["assetId"] == 3
    assert request(guest, origin, slot["assetUrl"])[0] == 404
    assert request(admin, origin, slot["assetUrl"])[0] == 200
    draft_shell = imported["draft"]["launch"]["previewUrl"]
    assert draft_shell == PREFIX + "/pwa-splash-preview.php?surface=launch&revision=2&draft=1&shell=1"
    assert request(guest, origin, draft_shell)[0] == 404
    status, shell, headers = request(admin, origin, draft_shell)
    assert status == 200 and b'sandbox="allow-scripts"' in shell and b"data:image" not in shell
    assert "frame-src " + origin + PREFIX + "/pwa-splash-preview.php" in headers["Content-Security-Policy"]
    draft_url = draft_shell.replace("&shell=1", "")
    assert request(guest, origin, draft_url)[0] == request(customer, origin, draft_url)[0] == 404
    status, preview, headers = request(admin, origin, draft_url)
    assert status == 200 and b"Canvas welcome" in preview and b"<script>escape</script>" not in preview
    media = json.loads(re.search(rb'id="sf-splash-media">(.*?)</script>', preview).group(1))
    assert media == {"hero": "data:image/png;base64," + base64.b64encode(png(80, 120)).decode()}
    assert base64.b64encode(hashlib.sha256(SCRIPT.encode()).digest()).decode() in headers["Content-Security-Policy"]
    assert "sandbox allow-scripts" in headers["Content-Security-Policy"]
    assert "default-src 'none'" in headers["Content-Security-Policy"]
    assert "no-store" in headers["Cache-Control"]
    assert request(guest, origin, draft_url.replace("&draft=1", ""))[0] == 404
    status, public, _ = request(guest, origin, PREFIX + "/public-fixture.php")
    assert status == 200 and public["revision"] == 0 and "js" not in public["launch"]

    update("publish", 2, 422, settings={"launch": {"html": "<script>invalid</script>"}})
    published = update("publish", 2, 200)
    assert published["draft"]["revision"] == published["published"]["revision"] == 3
    assert request(admin, origin, draft_url)[0] == 404
    live_url = published["published"]["launch"]["previewUrl"]
    status, preview, _ = request(guest, origin, live_url)
    assert status == 200 and media["hero"].encode() in preview
    assert request(guest, origin, slot["assetUrl"])[0] == 404
    slot_file = site / "storage/media/slot.png"
    slot_file.write_bytes(png(100, 100))
    assert request(guest, origin, live_url)[0] == 422
    slot_file.write_bytes(png(80, 120))
    assert request(guest, origin, published["published"]["startupUrl"])[0] == 200
    assert request(guest, origin, published["published"]["iconUrl"])[0] == 200
    public = request(guest, origin, PREFIX + "/public-fixture.php")[1]
    assert public["iconSize"] == 192 and public["iconMime"] == "image/png"
    assert public["launch"]["mode"] == "code" and public["waiting"]["mode"] == "image"
    assert "html" not in public["launch"] and "imageSlots" not in public["launch"] and public["backgroundColor"] == "#3a206f"

    update("save", 3, 200, settings={"launch": {"mode": "default"}})
    restored = update("restore", 4, 200)
    assert restored["draft"]["launch"]["mode"] == "code" and restored["published"]["revision"] == 3
    assert not restored["hasUnpublishedChanges"]
    update("publish", 5, 200, settings={
        "iconAssetId": 0, "startupAssetId": 0, "waiting": {"mode": "default", "assetId": 0},
    })
    assert request(guest, origin, live_url)[0] == 404
    assert request(guest, origin, published["published"]["iconUrl"])[0] == 404
    with sqlite3.connect(webroot / PREFIX.strip("/") / "config/test.sqlite") as db:
        db.execute("UPDATE app_meta SET meta_value='{}' WHERE meta_key='site.pwa_splash'")
    status, public, _ = request(guest, origin, PREFIX + "/public-fixture.php")
    assert status == 200 and public["revision"] == 0 and public["launch"]["mode"] == "default"
    assert request(admin, origin, ADMIN)[0] == 503


def main():
    with tempfile.TemporaryDirectory(prefix="stonefellow-splash-") as directory:
        webroot = Path(directory)
        fixture(webroot)
        with socket.socket() as port:
            port.bind(("127.0.0.1", 0))
            address = "127.0.0.1:" + str(port.getsockname()[1])
        origin = "http://" + address
        with tempfile.TemporaryFile() as logs:
            sessions = webroot / "sessions"
            sessions.mkdir()
            server = subprocess.Popen([PHP, "-d", "session.save_path=" + str(sessions), "-S", address, "router.php"],
                                      cwd=webroot, stdout=logs, stderr=logs)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(("127.0.0.1", int(address.split(":")[1])), timeout=0.1):
                            break
                    except OSError:
                        time.sleep(0.02)
                exercise(origin, webroot)
            except Exception:
                logs.seek(0)
                print(logs.read().decode(), end="")
                raise
            finally:
                server.terminate()
                server.wait(timeout=5)
    print("PASS: splash admin permissions, draft isolation, publication, restore, assets, and revision render")


if __name__ == "__main__":
    main()
