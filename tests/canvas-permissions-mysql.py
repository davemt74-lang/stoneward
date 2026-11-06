"""Native MariaDB/MySQL dialect and independent-worker policy concurrency checks.

Set SF_MARIADB_ROOT to an extracted MariaDB installation containing usr/sbin/mariadbd,
usr/bin/mariadb-install-db and usr/share/mysql. This launches only a temporary local
loopback-only database. No deployment database is contacted.
"""
import json
import os
from pathlib import Path
import subprocess
import socket as net_socket
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SF_TEST_PHP', 'php')
RUNTIME = Path(os.environ['SF_MARIADB_ROOT'])
BIN = RUNTIME / 'usr/sbin/mariadbd'
INSTALL = RUNTIME / 'usr/bin/mariadb-install-db'
CLIENT = RUNTIME / 'usr/bin/mariadb'
env = os.environ.copy()
env['LD_LIBRARY_PATH'] = str(RUNTIME / 'usr/lib/x86_64-linux-gnu') + ':' + env.get('LD_LIBRARY_PATH', '')
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


with tempfile.TemporaryDirectory(prefix='sf-permissions-mysql-') as temporary:
    work = Path(temporary)
    data = work / 'data'
    with net_socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    initialized = subprocess.run([str(INSTALL), '--no-defaults', '--basedir=' + str(RUNTIME / 'usr'),
                                  '--datadir=' + str(data), '--auth-root-authentication-method=normal',
                                  '--skip-test-db', '--user=root'], env=env, capture_output=True, text=True)
    assert initialized.returncode == 0, initialized.stdout + initialized.stderr
    with (work / 'server.log').open('w+') as log:
        server = subprocess.Popen([str(BIN), '--no-defaults', '--basedir=' + str(RUNTIME / 'usr'),
                                   '--datadir=' + str(data), '--socket=', '--bind-address=127.0.0.1', '--port='+str(port),
                                   '--pid-file=' + str(work / 'db.pid'), '--user=root', '--innodb-use-native-aio=0'],
                                  env=env, stdout=log, stderr=log)
        try:
            for _ in range(200):
                ping = subprocess.run([PHP, '-r', "new PDO('mysql:host=127.0.0.1;port="+str(port)+"','root','');"], env=env, capture_output=True)
                if ping.returncode == 0:
                    break
                if server.poll() is not None:
                    log.seek(0)
                    raise AssertionError(log.read())
                time.sleep(.025)
            else:
                log.seek(0)
                raise AssertionError('MariaDB startup timed out\n'+log.read()+'\n'+ping.stderr.decode())
            subprocess.run([PHP, '-r', "(new PDO('mysql:host=127.0.0.1;port="+str(port)+"','root',''))->exec('CREATE DATABASE canvas_permissions CHARACTER SET utf8mb4');"], env=env, check=True, capture_output=True)
            setup = """<?php
declare(strict_types=1);
$pdo=new PDO('mysql:host=127.0.0.1;port='.getenv('SF_PERMISSION_PORT').';dbname=canvas_permissions;charset=utf8mb4','root','');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function sf_db():PDO{return $GLOBALS['pdo'];}
require getenv('SF_PERMISSION_REPO').'/api/canvas-capabilities.php';
"""
            env['SF_PERMISSION_PORT'] = str(port)
            env['SF_PERMISSION_REPO'] = str(ROOT)
            script = work / 'probe.php'
            def probe(code):
                script.write_text(setup + code)
                result = subprocess.run([PHP, str(script)], env=env, capture_output=True, text=True)
                assert result.returncode == 0, result.stdout + result.stderr
                return json.loads(result.stdout)
            first = probe("echo json_encode(sf_canvas_permission_policy());")
            check(first['revision'] == 1 and first['disabled'] == [], 'MySQL default policy reads preserve legacy behavior without schema')
            initialized = probe("echo json_encode(sf_canvas_permission_admin_view());")
            check(len(initialized['capabilities']) == 7, 'MySQL creates and reads seven capability rows through native SQL')
            engine = probe("echo json_encode(sf_db()->query(\"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='canvas_permission_policy'\")->fetchColumn());")
            check(engine == 'InnoDB', 'Native policy table uses transactional InnoDB')
            saved = probe("echo json_encode(sf_canvas_permission_save(['id'=>7,'role'=>'admin'],1,['canvas.data.my_purchases'=>false]));")
            check(saved['policy']['revision'] == 2 and len(saved['history']) == 1,
                  'MySQL policy and before/after audit commit together')
            outer = probe("""
sf_db()->exec('CREATE TABLE outer_probe(id INTEGER PRIMARY KEY) ENGINE=InnoDB');
sf_db()->beginTransaction();sf_db()->exec('INSERT INTO outer_probe VALUES(1)');
$rejected=false;try{sf_canvas_permission_save(['id'=>7,'role'=>'admin'],2,['canvas.data.my_tickets'=>false]);}catch(RuntimeException $e){$rejected=true;}
$active=sf_db()->inTransaction();if($active)sf_db()->rollBack();
echo json_encode(['rejected'=>$rejected,'active'=>$active,'rows'=>(int)sf_db()->query('SELECT COUNT(*) FROM outer_probe')->fetchColumn(),'policy'=>sf_canvas_permission_policy()]);
""")
            check(outer['rejected'] and outer['active'] and outer['rows'] == 0 and outer['policy']['revision'] == 2,
                  'Native MySQL nested write rejects before DDL and preserves caller rollback')
            worker = work / 'worker.php'
            worker.write_text(setup + """
fgets(STDIN);
try{$result=sf_canvas_permission_save(['id'=>(int)$argv[1],'role'=>'admin'],2,[$argv[2]=>false]);echo json_encode(['state'=>'saved','revision'=>$result['policy']['revision']]);}
catch(UnexpectedValueException $e){echo json_encode(['state'=>'conflict']);}
""")
            workers = [subprocess.Popen([PHP, str(worker), str(actor), permission], env=env,
                                       stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                       for actor, permission in [(8, 'canvas.data.my_tickets'), (9, 'canvas.data.personal_listening_stats')]]
            for child in workers:
                child.stdin.write('go\n')
                child.stdin.flush()
            results = []
            for child in workers:
                output, error = child.communicate(timeout=10)
                assert child.returncode == 0, output + error
                results.append(json.loads(output))
            check(sorted(r['state'] for r in results) == ['conflict', 'saved'],
                  'Two independent MySQL workers with one revision produce exactly one save and one conflict')
            final = probe("echo json_encode(sf_canvas_permission_admin_view());")
            check(final['policy']['revision'] == 3 and len(final['history']) == 2 and len(final['policy']['disabled']) == 2,
                  'Concurrent save creates one revision and matching audit without lost-update or partial state')
            changed = final['history'][0]
            check(changed['updated_by'] in [8, 9] and len(changed['changes']) == 1,
                  'Concurrent audit records only the winning authenticated actor/change')
            rollback = probe("""
sf_db()->exec("CREATE TRIGGER reject_policy_audit BEFORE INSERT ON canvas_permission_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture audit failure'");
$failed=false;try{sf_canvas_permission_save(['id'=>7,'role'=>'admin'],3,['canvas.data.published_catalog'=>false]);}catch(PDOException $e){$failed=true;}
$state=sf_canvas_permission_admin_view();
sf_db()->exec('DROP TRIGGER reject_policy_audit');
echo json_encode(['failed'=>$failed,'state'=>$state]);
""")
            check(rollback['failed'] and rollback['state']['policy']['revision'] == 3 and
                  len(rollback['state']['history']) == 2 and
                  'canvas.data.published_catalog' not in rollback['state']['policy']['disabled'],
                  'MySQL audit failure rolls back the policy update without a partial revision')
            # Exercise consent through the real application bootstrap, on a separate database.
            subprocess.run([PHP, '-r', "(new PDO('mysql:host=127.0.0.1;port="+str(port)+"','root',''))->exec('CREATE DATABASE canvas_access CHARACTER SET utf8mb4');"], env=env, check=True, capture_output=True)
            app = work / 'app'
            (app / 'config').mkdir(parents=True)
            (app / 'api').symlink_to(ROOT / 'api', target_is_directory=True)
            (app / 'config/database.php').write_text("<?php return ['driver'=>'mysql','host'=>'127.0.0.1','port'=>"+str(port)+",'database'=>'canvas_access','username'=>'root','password'=>''];")
            app_setup = "<?php declare(strict_types=1);define('SF_ROOT',getenv('SF_PERMISSION_APP'));require SF_ROOT.'/api/bootstrap.php';require_once SF_ROOT.'/api/agent-scene-core.php';require_once SF_ROOT.'/api/canvas-access-core.php';\n"
            env['SF_PERMISSION_APP'] = str(app)
            app_script = work / 'consent-probe.php'
            def app_probe(code):
                app_script.write_text(app_setup + code)
                result = subprocess.run([PHP, str(app_script)], env=env, capture_output=True, text=True)
                assert result.returncode == 0, result.stdout + result.stderr
                return json.loads(result.stdout)
            seed = app_probe("""
sf_db()->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL) ENGINE=InnoDB");
sf_db()->exec("INSERT INTO users VALUES(1,'active'),(2,'active')");
sf_db()->exec("CREATE TABLE user_privacy_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED,event_type VARCHAR(60)) ENGINE=InnoDB");
$scene=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Native consent lens','html'=>'<section class=\"sf-dataset-my_purchases\"></section>','css'=>'']);
echo json_encode(['scene'=>$scene,'access'=>sf_canvas_access_summary(['id'=>1],['scene_id'=>$scene['id']])]);
""")
            consent_context = {'scene_id': seed['scene']['id']}
            env['SF_PERMISSION_SCENE'] = str(seed['scene']['id'])
            env['SF_PERMISSION_FINGERPRINT'] = seed['access']['fingerprint']
            consent = app_probe("""
$a=sf_canvas_access_change(['id'=>1],['scene_id'=>(int)getenv('SF_PERMISSION_SCENE')],'grant',getenv('SF_PERMISSION_FINGERPRINT'),['canvas.data.my_purchases']);
echo json_encode(['access'=>$a,'saved'=>sf_canvas_access_saved(1),'other'=>sf_canvas_access_saved(2)]);
""")
            check(not consent['access']['requires_consent'] and len(consent['saved']) == 1 and consent['other'] == [],
                  'Native MySQL consent grant persists only for the current account')
            engines = app_probe("echo json_encode(sf_db()->query(\"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('canvas_access_state','canvas_access_grants')\")->fetchAll(PDO::FETCH_COLUMN));")
            check(engines == ['InnoDB', 'InnoDB'], 'Both consent tables use transactional InnoDB')
            env['SF_PERMISSION_FINGERPRINT'] = consent['access']['fingerprint']
            consent_worker = work / 'consent-worker.php'
            consent_worker.write_text(app_setup + """
fgets(STDIN);
try{$result=sf_canvas_access_change(['id'=>1],['scene_id'=>(int)getenv('SF_PERMISSION_SCENE')],$argv[1],getenv('SF_PERMISSION_FINGERPRINT'),['canvas.data.my_purchases']);echo json_encode(['state'=>'saved','action'=>$argv[1],'requires_consent'=>$result['requires_consent']]);}
catch(SfCanvasAccessConflict $e){echo json_encode(['state'=>'conflict','action'=>$argv[1]]);}
""")
            workers = [subprocess.Popen([PHP, str(consent_worker), action], env=env,
                                       stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                       for action in ['grant', 'revoke']]
            for child in workers:
                child.stdin.write('go\n')
                child.stdin.flush()
            results = []
            for child in workers:
                output, error = child.communicate(timeout=10)
                assert child.returncode == 0, output + error
                results.append(json.loads(output))
            check(sorted(r['state'] for r in results) == ['conflict', 'saved'],
                  'Concurrent native grant/revoke using one fingerprint has one winner and one stale conflict')
            winner = next(r for r in results if r['state'] == 'saved')
            current = app_probe("echo json_encode(sf_canvas_access_summary(['id'=>1],['scene_id'=>(int)getenv('SF_PERMISSION_SCENE')]));")
            check(current['requires_consent'] == (winner['action'] == 'revoke'),
                  'Native grant/revoke final state matches the serialized winner')
            env['SF_PERMISSION_FINGERPRINT'] = current['fingerprint']
            revoked = app_probe("""
$c=['scene_id'=>(int)getenv('SF_PERMISSION_SCENE')];$a=sf_canvas_access_change(['id'=>1],$c,'revoke',getenv('SF_PERMISSION_FINGERPRINT'),['canvas.data.my_purchases']);
$replayed=false;try{sf_canvas_access_change(['id'=>1],$c,'grant',getenv('SF_PERMISSION_FINGERPRINT'),['canvas.data.my_purchases']);}catch(SfCanvasAccessConflict $e){$replayed=true;}
echo json_encode(['replayed'=>$replayed,'access'=>sf_canvas_access_summary(['id'=>1],$c)]);
""")
            check(revoked['replayed'] and revoked['access']['requires_consent'],
                  'Native stale Allow replay cannot restore access after revoke')
            env['SF_PERMISSION_FINGERPRINT'] = revoked['access']['fingerprint']
            rollback = app_probe("""
sf_db()->exec("CREATE TRIGGER reject_access_revision BEFORE INSERT ON canvas_access_state FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture consent failure'");
$failed=false;try{sf_canvas_access_change(['id'=>1],['scene_id'=>(int)getenv('SF_PERMISSION_SCENE')],'grant',getenv('SF_PERMISSION_FINGERPRINT'),['canvas.data.my_purchases']);}catch(PDOException $e){$failed=true;}
$summary=sf_canvas_access_summary(['id'=>1],['scene_id'=>(int)getenv('SF_PERMISSION_SCENE')]);sf_db()->exec('DROP TRIGGER reject_access_revision');
echo json_encode(['failed'=>$failed,'summary'=>$summary,'saved'=>sf_canvas_access_saved(1)]);
""")
            check(rollback['failed'] and rollback['summary']['requires_consent'] and rollback['saved'] == [] and
                  rollback['summary']['fingerprint'] == revoked['access']['fingerprint'],
                  'Native consent state failure atomically rolls back grant and revision')
            print(f'{len(checks)} native MariaDB permission and consent checks passed', flush=True)
        finally:
            server.terminate()
            server.wait(timeout=10)
