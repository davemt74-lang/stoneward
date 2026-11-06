"""Read-only token meter through real authenticated PHP HTTP.

Uses a disposable site/database. No provider calls or real user records.
"""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('canvas_permissions_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv
PHP = os.environ.get('SF_TEST_PHP', 'php')
ENDPOINT = '/api/token-meter.php'
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


def sign_in(base, email):
    client = fixture.HTTPClient(base)
    csrf = client.get('/api/auth.php').json()['csrf']
    response = client.post('/api/auth.php', {'action': 'login', 'email': email, 'password': 'fixture-only-password'},
                           {'X-CSRF-Token': csrf})
    assert response.status == 200, response.json()
    return client, {'X-CSRF-Token': response.json()['csrf']}


def billing_snapshot(site):
    import sqlite3
    with sqlite3.connect(site / 'fixture.sqlite') as db:
        names=[r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")]
        selected=[n for n in names if n in ['user_token_balances','token_ledger','user_subscriptions','user_trial_history','subscription_packages','ai_token_reservations'] or n.startswith('billing_')]
        return {name:list(db.execute('SELECT * FROM '+name+' ORDER BY rowid')) for name in selected}


with tempfile.TemporaryDirectory(prefix='stoneward-token-meter-http-') as temporary:
    site=Path(temporary)
    fixture.seed(site)
    seeded=json.loads(fixture.cli(site, """
        require_once SF_ROOT.'/api/ai-token-reservations.php';
        sf_db()->exec("INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) SELECT 3,'admin@example.invalid','Admin',password_hash,'admin','active',created_at,updated_at FROM users WHERE id=1");
        sf_entitlements_ensure_schema();sf_billing_ensure_schema();
        $package=sf_packages(true)[0];sf_db()->prepare('UPDATE subscription_packages SET trial_days=7,trial_tokens=1000,monthly_tokens=2000 WHERE id=?')->execute([$package['id']]);
        sf_enroll_user(1,(int)$package['id']);sf_token_adjust(2,42,'admin_grant','fixture-other');
        $held=sf_ai_reserve_tokens(['id'=>1,'role'=>'user'],200,'fixture-reservation');
        echo json_encode(['held'=>$held]);
    """))
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1',0));port=reservation.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    with (site/'php.log').open('w+') as log:
        server=subprocess.Popen([PHP,'-d','session.save_path='+str(site/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(site)],stdout=log,stderr=log,start_new_session=True)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.02)
            guest=fixture.HTTPClient(base)
            owner,headers=sign_in(base,'owner@example.invalid');other,_=sign_in(base,'other@example.invalid');admin,_=sign_in(base,'admin@example.invalid')
            check(guest.get(ENDPOINT).status==401,'Token meter requires authentication')
            check(owner.post(ENDPOINT,{},headers).status==405,'Token meter permits GET only')
            snapshot=billing_snapshot(site)
            response=owner.get(ENDPOINT);meter=response.json()['meter']
            check(response.status==200 and meter['user_id']==1 and meter['available_tokens']==800,'HTTP meter returns current account net available balance')
            check(meter['held_tokens']==200 and meter['percentage_remaining']==80 and meter['allowance_tokens']==1000,'Held escrow is separate; current trial percentage has no double subtraction')
            check('no-store' in response.headers.get('cache-control',''),'Token meter response is private and not cached')
            check(billing_snapshot(site)==snapshot,'Meter GET changes no subscription, package, balance, ledger or reservation rows')
            check(owner.get(ENDPOINT+'?user_id=2').json()['meter']['user_id']==1,'Caller-supplied account selector cannot reveal another balance')
            second=other.get(ENDPOINT).json()['meter'];check(second['available_tokens']==42 and second['held_tokens']==0 and second['percentage_remaining'] is None,'Another account sees only its grant-only balance with no invented percentage')
            unlimited=admin.get(ENDPOINT).json()['meter'];check(unlimited['unlimited'] and unlimited['available_tokens'] is None and unlimited['percentage_remaining'] is None,'Administrator receives truthful unlimited state without fake percentage')
            key=seeded['held']
            fixture.cli(site,"require_once SF_ROOT.'/api/ai-token-reservations.php';sf_ai_finish_reservation(['id'=>1,'role'=>'user'],'"+key+"',50,[],'fixture-reservation',true);")
            settled=owner.get(ENDPOINT).json()['meter'];check(settled['available_tokens']==950 and settled['held_tokens']==0 and settled['percentage_remaining']==95,'Confirmed settlement refresh shows final charge and released escrow')
            fixture.cli(site,"sf_token_adjust(1,1050,'admin_grant','fixture-extra');")
            topped=owner.get(ENDPOINT).json()['meter'];check(topped['available_tokens']==2000 and topped['percentage_remaining']==200 and topped['bar_percentage']==100,'Extra grant can show above100% without concealing the exact balance')
            fixture.cli(site,"sf_db()->exec(\"UPDATE subscription_packages SET trial_tokens=999999,monthly_tokens=999999\");")
            check(owner.get(ENDPOINT).json()['meter']['allowance_tokens']==1000,'Mutable package settings do not rewrite the credited allowance denominator')
            fixture.cli(site,"sf_db()->exec(\"UPDATE user_subscriptions SET status='active',current_period_start='2000-01-01T00:00:00+00:00',current_period_end='2000-02-01T00:00:00+00:00',trial_started_at=NULL,trial_ends_at=NULL,provider='local' WHERE user_id=1\");sf_db()->exec('UPDATE subscription_packages SET monthly_price_cents=0');")
            expired_before=billing_snapshot(site);expired=owner.get(ENDPOINT).json()['meter']
            check(expired['available_tokens']==2000 and expired['percentage_remaining'] is None,'Expired free plan shows count without pretending a current allowance')
            check(billing_snapshot(site)==expired_before,'Expired free plan GET does not trigger renewal, grants or billing mutations')
            fixture.cli(site,"sf_db()->exec('DROP TABLE user_token_balances');")
            unavailable=owner.get(ENDPOINT)
            check(unavailable.status==503 and 'meter' not in unavailable.json(),'Missing balance storage returns unavailable instead of a false zero')
            import sqlite3
            with sqlite3.connect(site/'fixture.sqlite') as db:
                exists=db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='user_token_balances'").fetchone()
            check(exists is None,'Read-only meter does not recreate missing billing storage')
            print(f'{len(checks)} real HTTP token meter checks passed',flush=True)
        finally:
            os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
