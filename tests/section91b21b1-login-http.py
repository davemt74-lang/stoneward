"""Production login controllers + real session/DB/limiter code under a fixture-only bootstrap.
Unrelated billing/lifecycle migrations and upgrade execution are stubbed; no provider calls.
"""
from pathlib import Path
import http.cookiejar, json, re, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT = Path(__file__).resolve().parents[1]
checks = 0

def check(ok, label):
    global checks
    assert ok, label
    checks += 1
    print('PASS', label, flush=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

with tempfile.TemporaryDirectory(prefix='sf-login-http-') as folder:
    site = Path(folder)
    for name in ['api', 'admin/api', 'config', 'storage', 'sessions']:
        (site/name).mkdir(parents=True, exist_ok=True)
    for name in ['api/auth.php', 'admin/login.php', 'upgrade.php']:
        shutil.copy2(ROOT/name, site/name)
    dbpath = site/'test.sqlite'
    (site/'config/database.php').write_text("<?php return ['driver'=>'sqlite','path'=>" + repr(str(dbpath)) + "];\n")
    # Original PHP bootstrap helpers, with unrelated subsystems intentionally inert.
    boot = """<?php
if(!defined('SF_ROOT'))define('SF_ROOT',dirname(__DIR__));
require_once SOURCE.'/api/database.php';
require_once SOURCE.'/api/operations.php';
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
function sf_customer_lifecycle_ensure_schema():void{}
function sf_entitlements_ensure_schema():void{}
function sf_billing_ensure_schema():void{}
function sf_json_response(array $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($data);exit;}
function sf_request_json():array{return json_decode(file_get_contents('php://input'),true)?:[];}
""".replace('SOURCE', repr(str(ROOT)))
    (site/'api/bootstrap.php').write_text(boot)
    admin = (ROOT/'admin/api/bootstrap.php').read_text()
    admin_functions = admin[admin.index('function sf_admin_session()'):admin.index('function sf_admin_slug(')]
    (site/'admin/api/bootstrap.php').write_text("<?php require_once __DIR__.'/../../api/bootstrap.php';\n" + admin_functions)
    (site/'api/migrations.php').write_text("""<?php
const SF_DB_SCHEMA_TARGET='fixture';
function sf_migration_status():array{return ['target'=>'fixture','current'=>true,'pending'=>0,'drift'=>0,'migrations'=>[]];}
function sf_migration_core_preflight():array{return ['ok'=>true,'checks'=>[]];}
function sf_upgrade_recent_runs(int $limit):array{return [];}
""")
    password_hash = subprocess.check_output(['php', '-r', "echo password_hash('valid-test-password',PASSWORD_BCRYPT,['cost'=>4]);"], text=True)
    db = sqlite3.connect(dbpath)
    db.execute('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,role TEXT,status TEXT,password_hash TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT,email_verified_at TEXT,email_verification_sent_at TEXT)')
    db.executemany('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,?,?,?)', [
        (1,'admin@example.test','Admin','admin','active',password_hash,'now','now',None,None,None),
        (2,'fan@example.test','Fan','user','active',password_hash,'now','now',None,None,None)])
    db.commit()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    log=open(site/'server.log','w+')
    server=subprocess.Popen(['php','-d',f'session.save_path={site}/sessions','-d','display_errors=0','-S',f'127.0.0.1:{port}','-t',str(site)], stdout=log, stderr=log)
    def browser():
        jar=http.cookiejar.CookieJar()
        return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect()),jar
    def request(client,path,data=None,headers=None):
        req=urllib.request.Request(base+path,data=data,headers=headers or {})
        try: response=client.open(req,timeout=15)
        except urllib.error.HTTPError as exc: response=exc
        with response:
            return response.status,dict(response.headers),response.read().decode()
    def signin(client,surface,email='admin@example.test',password='wrong',valid_csrf=True):
        path={'public':'/api/auth.php','admin':'/admin/login.php','upgrade':'/upgrade.php'}[surface]
        status,headers,text=request(client,path)
        if surface=='public':
            csrf=json.loads(text)['csrf'] if valid_csrf else 'invalid'
            return request(client,path,json.dumps({'action':'login','email':email,'password':password}).encode(),{'Content-Type':'application/json','X-CSRF-Token':csrf})
        csrf=re.search(r'name="csrf" value="([^"]+)"',text).group(1) if valid_csrf else 'invalid'
        return request(client,path,urllib.parse.urlencode({'action':'login','email':email,'password':password,'csrf':csrf}).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
    def clear():
        db.execute('DELETE FROM auth_attempts');db.execute('DELETE FROM auth_login_locks');db.commit()
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:time.sleep(.05)
        client,jar=browser()
        for surface in ['public','admin','upgrade']:
            status,headers,text=signin(client,surface,valid_csrf=False)
            check('expired' in text or 'csrf_failed' in text, surface+' rejects missing/invalid CSRF')
        check(db.execute("SELECT count(*) FROM sqlite_master WHERE name='auth_attempts'").fetchone()[0]==0,'CSRF failures never reach admission or create attempts')
        for i in range(8):
            status,headers,text=signin(client,['public','admin','upgrade'][i%3])
            check(status==401 and 'Invalid email or password.' in text,'Mixed-surface invalid login '+str(i+1))
        for surface in ['public','admin','upgrade']:
            status,headers,text=signin(client,surface,password='valid-test-password')
            check(status==429 and int(headers.get('Retry-After','0'))>0,surface+' sends HTTP 429 with Retry-After')
            check('Too many sign-in attempts.' in text and 'no-store' in headers.get('Cache-Control',''),surface+' presents retry guidance without caching')
        check(db.execute('SELECT count(*) FROM auth_attempts').fetchone()[0]==8,'HTTP throttling does not extend failure history')
        db.execute("UPDATE auth_attempts SET created_at='2000-01-01T00:00:00+00:00'");db.commit()
        status,headers,text=signin(client,'public',password='valid-test-password')
        check(status==200 and json.loads(text).get('authenticated') is True,'Public login recovers after cooldown')
        check(any(c.name=='stonefellow_auth' for c in jar),'Public response sets a persistent login cookie')
        clear();client,jar=browser();status,headers,text=signin(client,'admin',password='valid-test-password')
        check(status==303 and headers.get('Location')=='index.php','Admin login retains its existing redirect')
        check(any(c.name=='stonefellow_auth' for c in jar),'Admin response sets a persistent login cookie')
        clear();client,jar=browser();status,headers,text=signin(client,'upgrade',password='valid-test-password')
        check(status==200 and 'Migration plan' in text,'Upgrade login renders authenticated view')
        check(any(c.name=='stonefellow_auth' for c in jar),'Upgrade response now sets a persistent login cookie')
        db.execute('DELETE FROM user_sessions');db.commit()
        status,headers,text=request(client,'/upgrade.php')
        check('Administrator authentication' in text and 'Migration plan' not in text,'Revoked upgrade cookie loses access despite its PHP session')
        for surface in ['admin','upgrade']:
            clear();client,jar=browser();status,headers,text=signin(client,surface,'fan@example.test','valid-test-password')
            check(status==401 and not any(c.name=='stonefellow_auth' for c in jar),'Fan credentials cannot create '+surface+' login')
        clear();client,jar=browser();status,headers,text=signin(client,'upgrade',password='valid-test-password')
        csrf=re.search(r'name="csrf" value="([^"]+)"',text).group(1)
        status,headers,text=request(client,'/upgrade.php',urllib.parse.urlencode({'action':'logout','csrf':csrf}).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
        check('Upgrade session signed out.' in text and not any(c.name=='stonefellow_auth' for c in jar),'Upgrade logout clears persistent login cookie')
        check(db.execute('SELECT count(*) FROM user_sessions').fetchone()[0]==0,'Upgrade logout revokes its server-side token')
        clear();db.execute("CREATE TRIGGER fail_login BEFORE INSERT ON auth_attempts BEGIN SELECT RAISE(ABORT,'fixture-secret-error'); END");db.commit()
        for surface in ['public','admin','upgrade']:
            client,jar=browser();status,headers,text=signin(client,surface,password='valid-test-password')
            check(status==503 and 'temporarily unavailable' in text,surface+' fails closed on persistence failure')
            check('fixture-secret-error' not in text and not any(c.name=='stonefellow_auth' for c in jar),surface+' failure leaks no storage detail or authenticated cookie')
        print(f'91B21B1 HTTP: {checks} assertions passed')
    finally:
        server.terminate()
        try:server.wait(timeout=5)
        except subprocess.TimeoutExpired:server.kill();server.wait()
        log.seek(0);logs=log.read();log.close();db.close()
        if 'Fatal error' in logs:print(logs)
