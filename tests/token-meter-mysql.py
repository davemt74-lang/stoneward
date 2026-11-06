"""Native token meter read-only privileges and deterministic settlement snapshot race.

Set SF_MARIADB_ROOT to an extracted MariaDB installation containing usr/sbin/mariadbd,
usr/bin/mariadb-install-db and usr/share/mysql. This launches only a temporary local
loopback-only database. No deployment database is contacted.
"""
import json
import os
import pwd
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
RUN_USER = pwd.getpwuid(os.geteuid()).pw_name
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
                                  '--skip-test-db', '--user='+RUN_USER], env=env, capture_output=True, text=True)
    assert initialized.returncode == 0, initialized.stdout + initialized.stderr
    with (work / 'server.log').open('w+') as log:
        server = subprocess.Popen([str(BIN), '--no-defaults', '--basedir=' + str(RUNTIME / 'usr'),
                                   '--datadir=' + str(data), '--socket=', '--bind-address=127.0.0.1', '--port='+str(port),
                                   '--pid-file=' + str(work / 'db.pid'), '--user='+RUN_USER, '--innodb-use-native-aio=0'],
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
            subprocess.run([PHP, '-r', "(new PDO('mysql:host=127.0.0.1;port="+str(port)+"','root',''))->exec('CREATE DATABASE token_meter CHARACTER SET utf8mb4');"], env=env, check=True, capture_output=True)
            env['SF_METER_PORT']=str(port)
            env['SF_METER_REPO']=str(ROOT)
            env['SF_METER_WORK']=str(work)
            import secrets
            env['SF_METER_READ_PASS']=secrets.token_hex(24)
            setup="""<?php declare(strict_types=1);
$pdo=new PDO('mysql:host=127.0.0.1;port='.getenv('SF_METER_PORT').';dbname=token_meter;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function sf_db():PDO{return $GLOBALS['pdo'];}
require getenv('SF_METER_REPO').'/api/token-meter-core.php';
"""
            script=work/'probe.php'
            def probe(code):
                script.write_text(setup+code)
                result=subprocess.run([PHP,str(script)],env=env,capture_output=True,text=True)
                assert result.returncode==0,result.stdout+result.stderr
                return json.loads(result.stdout)
            seed=probe("""
$pdo->exec("CREATE TABLE users(id BIGINT PRIMARY KEY,role VARCHAR(10),status VARCHAR(10)) ENGINE=InnoDB");
$pdo->exec("INSERT INTO users VALUES(1,'user','active'),(2,'user','active')");
$pdo->exec('CREATE TABLE user_token_balances(user_id BIGINT PRIMARY KEY,balance BIGINT) ENGINE=InnoDB');
$pdo->exec('INSERT INTO user_token_balances VALUES(1,800),(2,42)');
$pdo->exec('CREATE TABLE ai_token_reservations(user_id BIGINT,reserved BIGINT,status VARCHAR(12)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO ai_token_reservations VALUES(1,200,'held'),(2,900,'held')");
$pdo->exec('CREATE TABLE user_subscriptions(user_id BIGINT,status VARCHAR(20),trial_started_at VARCHAR(40),trial_ends_at VARCHAR(40),current_period_start VARCHAR(40),current_period_end VARCHAR(40),provider VARCHAR(20),provider_subscription_id VARCHAR(80),last_invoice_id VARCHAR(80)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO user_subscriptions VALUES(1,'active',NULL,NULL,'2040-01-01T00:00:00+00:00','2040-02-01T00:00:00+00:00','local','','')");
$pdo->exec('CREATE TABLE token_ledger(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT,delta BIGINT,entry_type VARCHAR(40),source VARCHAR(120),created_at VARCHAR(40)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO token_ledger(user_id,delta,entry_type,source,created_at) VALUES(1,1000,'monthly_grant','fixture','2040-01-01T00:00:00+00:00')");
echo json_encode(sf_token_meter(['id'=>1],strtotime('2040-01-15T00:00:00+00:00')));
""")
            check(seed['available_tokens']==800 and seed['held_tokens']==200 and seed['percentage_remaining']==80,
                  'Native meter reads exact net balance, held escrow and actual allowance')
            probe("$pdo->exec(\"CREATE USER 'meter_read'@'127.0.0.1' IDENTIFIED BY \".$pdo->quote(getenv('SF_METER_READ_PASS')));$pdo->exec(\"GRANT SELECT ON token_meter.* TO 'meter_read'@'127.0.0.1'\");echo 'true';")
            readonly=work/'readonly.php'
            readonly.write_text(setup.replace("'root',''", "'meter_read',getenv('SF_METER_READ_PASS')")+"echo json_encode(sf_token_meter(['id'=>1],strtotime('2040-01-15T00:00:00+00:00')));")
            r=subprocess.run([PHP,str(readonly)],env=env,capture_output=True,text=True)
            assert r.returncode==0,r.stdout+r.stderr
            check(json.loads(r.stdout)['available_tokens']==800,'Meter succeeds using a database account with SELECT-only privileges')
            # Start a reader under READ COMMITTED; production core must obtain a fresh RR snapshot.
            reader=work/'reader.php'
            reader.write_text("""<?php declare(strict_types=1);
class MeterProbeStatement extends PDOStatement {
    protected function __construct(){}
    public function fetchColumn(int $column=0):mixed {
        $value=parent::fetchColumn($column);
        if(str_starts_with($this->queryString,'SELECT balance FROM')){
            file_put_contents(getenv('SF_METER_WORK').'/read-balance','ready');
            $until=microtime(true)+10;
            while(!file_exists(getenv('SF_METER_WORK').'/resume-read')){if(microtime(true)>$until)throw new RuntimeException('Fixture reader gate timed out');usleep(10000);}
        }
        return $value;
    }
}
$pdo=new PDO('mysql:host=127.0.0.1;port='.getenv('SF_METER_PORT').';dbname=token_meter;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_STATEMENT_CLASS=>[MeterProbeStatement::class]]);
function sf_db():PDO{return $GLOBALS['pdo'];}
require getenv('SF_METER_REPO').'/api/token-meter-core.php';
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
echo json_encode(sf_token_meter(['id'=>1],strtotime('2040-01-15T00:00:00+00:00')));
""")
            child=subprocess.Popen([PHP,str(reader)],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            try:
                for _ in range(500):
                    if (work/'read-balance').exists():break
                    if child.poll() is not None:
                        output,error=child.communicate();raise AssertionError(output+error)
                    time.sleep(.01)
                else:raise AssertionError('Reader gate did not open')
                probe("""
$pdo->beginTransaction();$pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetchAll();
$pdo->exec('UPDATE user_token_balances SET balance=950 WHERE user_id=1');
$pdo->exec("UPDATE ai_token_reservations SET status='settled' WHERE user_id=1");$pdo->commit();echo 'true';
""")
                (work/'resume-read').write_text('go')
                output,error=child.communicate(timeout=10);assert child.returncode==0,output+error
                old=json.loads(output)
                check(old['available_tokens']==800 and old['held_tokens']==200,
                      'Settlement between balance and hold reads cannot produce a mixed meter snapshot')
                fresh=probe("echo json_encode(sf_token_meter(['id'=>1],strtotime('2040-01-15T00:00:00+00:00')));")
                check(fresh['available_tokens']==950 and fresh['held_tokens']==0 and fresh['percentage_remaining']==95,
                      'Next independent request sees settled charge and released hold together')
            finally:
                if child.poll() is None:child.terminate();child.wait(timeout=5)
            other=probe("echo json_encode(sf_token_meter(['id'=>2],strtotime('2040-01-15T00:00:00+00:00')));")
            check(other['available_tokens']==42 and other['held_tokens']==900 and other['percentage_remaining'] is None,
                  'Native current-account snapshot excludes another account values and allowance')
            print(f'{len(checks)} native MariaDB token meter checks passed',flush=True)
        finally:
            server.terminate()
            server.wait(timeout=10)
