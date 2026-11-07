"""Complete playlist contracts on native MariaDB, including cold approval transaction safety.

SF_MARIADB_ROOT points to an extracted local MariaDB runtime. The disposable server
binds loopback only and never contacts deployment storage.
"""
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SF_TEST_PHP', 'php')
RUNTIME = Path(os.environ['SF_MARIADB_ROOT'])
RUN_USER = pwd.getpwuid(os.geteuid()).pw_name
env = os.environ.copy()
env['LD_LIBRARY_PATH'] = str(RUNTIME / 'usr/lib/x86_64-linux-gnu') + ':' + env.get('LD_LIBRARY_PATH', '')
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


with tempfile.TemporaryDirectory(prefix='stonefellow-playlists-native-') as temporary:
    work = Path(temporary)
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    initialized = subprocess.run([str(RUNTIME / 'usr/bin/mariadb-install-db'), '--no-defaults', '--basedir=' + str(RUNTIME / 'usr'),
                                  '--datadir=' + str(work / 'data'), '--auth-root-authentication-method=normal', '--skip-test-db', '--user=' + RUN_USER], env=env, capture_output=True, text=True)
    assert initialized.returncode == 0, initialized.stdout + initialized.stderr
    with (work / 'server.log').open('w+') as log:
        server = subprocess.Popen([str(RUNTIME / 'usr/sbin/mariadbd'), '--no-defaults', '--basedir=' + str(RUNTIME / 'usr'),
                                   '--datadir=' + str(work / 'data'), '--socket=', '--bind-address=127.0.0.1', '--port=' + str(port),
                                   '--pid-file=' + str(work / 'db.pid'), '--user=' + RUN_USER, '--innodb-use-native-aio=0'], env=env, stdout=log, stderr=log)
        try:
            dsn = 'mysql:host=127.0.0.1;port=' + str(port)
            for _ in range(200):
                ping = subprocess.run([PHP, '-r', 'new PDO($argv[1],"root","");', dsn], env=env, capture_output=True)
                if ping.returncode == 0:
                    break
                if server.poll() is not None:
                    log.seek(0)
                    raise AssertionError(log.read())
                time.sleep(.025)
            else:
                raise AssertionError('Disposable MariaDB did not start')
            subprocess.run([PHP, '-r', '(new PDO($argv[1],"root",""))->exec("CREATE DATABASE canvas_playlists CHARACTER SET utf8mb4");', dsn], env=env, check=True, capture_output=True)
            config = {'driver': 'mysql', 'host': '127.0.0.1', 'port': port, 'database': 'canvas_playlists', 'username': 'root', 'password': ''}
            env['SF_TEST_PLAYLIST_DB_CONFIG'] = json.dumps(config)
            result = subprocess.run([PHP, str(ROOT / 'tests/canvas-playlists.php')], env=env, capture_output=True, text=True)
            assert result.returncode == 0, result.stdout + result.stderr
            matched = re.search(r'Canvas playlists: (\d+) checks passed \(mysql\)', result.stdout)
            assert matched, result.stdout
            core_checks = int(matched[1])
            check(core_checks >= 50, 'All ' + str(core_checks) + ' selection, full-membership, authorization and publication contracts pass on native MariaDB')

            app = work / 'app'
            for folder in ['config', 'data']:
                (app / folder).mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / 'config/store.default.php', app / 'config/store.default.php')
            (app / 'config/database.php').write_text("<?php return ['driver'=>'mysql','host'=>'127.0.0.1','port'=>" + str(port) + ",'database'=>'canvas_playlists','username'=>'root','password'=>''];")
            (app / 'data/catalog.json').write_text(json.dumps([
                {'id': 'native-song', 'title': 'Native public song', 'state': 'published', 'audio': 'api/media.php?id=does-not-exist'},
                {'id': 'native-other', 'title': 'Other public song', 'state': 'published', 'audio': 'api/media.php?id=does-not-exist'}]))
            for name in ['releases', 'shows']:
                (app / 'data' / (name + '.json')).write_text('[]')
            env['SF_PLAYLIST_APP'] = str(app)
            env['SF_PLAYLIST_REPO'] = str(ROOT)
            script = work / 'probe.php'
            setup = """<?php declare(strict_types=1);
define('SF_ROOT',getenv('SF_PLAYLIST_APP'));require getenv('SF_PLAYLIST_REPO').'/api/bootstrap.php';
require_once getenv('SF_PLAYLIST_REPO').'/api/canvas-playlists-core.php';
"""

            def probe(source):
                script.write_text(setup + source)
                result = subprocess.run([PHP, str(script)], env=env, capture_output=True, text=True)
                assert result.returncode == 0, result.stdout + result.stderr
                return json.loads(result.stdout)

            def start(name, source, settings=None):
                child_script = work / (name + '.php')
                child_script.write_text(setup + source)
                return subprocess.Popen([PHP, str(child_script)], env={**env, **(settings or {})}, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

            def marker(path, child):
                for _ in range(1000):
                    if path.exists():
                        return
                    if child.poll() is not None:
                        output, error = child.communicate()
                        raise AssertionError(output + error)
                    time.sleep(.005)
                raise AssertionError('Fixture gate did not open: ' + path.name)

            def finish(child):
                output, error = child.communicate(timeout=10)
                assert child.returncode == 0, output + error
                return json.loads(output)

            gate = """
class PlaylistGateStatement extends PDOStatement {
    protected function __construct(){}
    public function execute(?array $params=null):bool {
        $match=str_starts_with($this->queryString,getenv('SF_PLAYLIST_GATE_SQL'));
        if($match&&getenv('SF_PLAYLIST_GATE_WHEN')==='before')$this->gate();
        $result=parent::execute($params);
        if($match&&getenv('SF_PLAYLIST_GATE_WHEN')==='after')$this->gate();
        return $result;
    }
    private function gate():void {
        static $used=false;if($used)return;$used=true;
        file_put_contents(getenv('SF_PLAYLIST_GATE_MARKER'),json_encode(['connection_id'=>(int)sf_db()->query('SELECT CONNECTION_ID()')->fetchColumn()]));$until=microtime(true)+10;
        while(!is_file(getenv('SF_PLAYLIST_GATE_RELEASE'))){if(microtime(true)>$until)throw new RuntimeException('Fixture gate timed out.');usleep(5000);}
    }
}
sf_canvas_playlists_ensure_schema();sf_db()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PlaylistGateStatement::class]);
"""

            def gating(name, sql, when):
                return {'SF_PLAYLIST_GATE_SQL': sql, 'SF_PLAYLIST_GATE_WHEN': when,
                        'SF_PLAYLIST_GATE_MARKER': str(work / (name + '.ready')),
                        'SF_PLAYLIST_GATE_RELEASE': str(work / (name + '.release'))}

            def waits_for_lock(settings, child):
                connection_id = json.loads(Path(settings['SF_PLAYLIST_GATE_MARKER']).read_text())['connection_id']
                for _ in range(100):
                    count = probe("echo json_encode((int)sf_db()->query('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id=" + str(connection_id) + "')->fetchColumn());")
                    if count:
                        return True
                    if child.poll() is not None:
                        return False
                    time.sleep(.005)
                return False

            cold = probe("""
$pdo=sf_db();$pdo->beginTransaction();$pdo->exec("INSERT INTO users VALUES(88,'user','active')");
$compiled=sf_canvas_playlists_compile(88,['items'=>[]]);$active=$pdo->inTransaction();if($active)$pdo->rollBack();
echo json_encode(['active'=>$active,'rolled_back'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE id=88')->fetchColumn()===0,'scope'=>$compiled['track_ids']]);
""")
            check(cold['active'] and cold['rolled_back'] and cold['scope'] == [], 'A cold nested empty compiler preserves its caller transaction and rollback without lazy schema DDL')
            seeded = probe("""
sf_canvas_playlists_ensure_schema();$playlist=sf_playlist_create(1,'Native artist candidate','','public','user',['native-song','native-song']);
$raw=sf_canvas_playlists_raw(sf_playlist_row($playlist['id']));$hash=sf_canvas_playlists_hash($raw);
echo json_encode(['id'=>$playlist['id'],'hash'=>$hash,'publication_hash'=>sf_canvas_playlists_public_version($hash,0)]);
""")
            env['SF_PLAYLIST_NATIVE_ID'] = str(seeded['id'])
            env['SF_PLAYLIST_NATIVE_HASH'] = seeded['hash']
            env['SF_PLAYLIST_NATIVE_PUBLICATION_HASH'] = seeded['publication_hash']
            cold_media = probe("""
$pdo=sf_db();$pdo->beginTransaction();$pdo->exec("INSERT INTO users VALUES(89,'user','active')");$rejected=false;
try{sf_canvas_playlists_compile(1,['items'=>[['ref'=>'own-'.getenv('SF_PLAYLIST_NATIVE_ID'),'version'=>getenv('SF_PLAYLIST_NATIVE_HASH')]]]);}catch(RuntimeException){$rejected=true;}
$active=$pdo->inTransaction();if($active)$pdo->rollBack();
echo json_encode(['rejected'=>$rejected,'active'=>$active,'rolled_back'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE id=89')->fetchColumn()===0]);
""")
            check(cold_media['rejected'] and cold_media['active'] and cold_media['rolled_back'], 'Cold nested media resolution fails safely before a legacy media helper could implicitly commit approval')
            warm = probe("""
sf_canvas_playlists_ensure_schema();$pdo=sf_db();$pdo->beginTransaction();$pdo->exec("INSERT INTO users VALUES(90,'user','active')");
$compiled=sf_canvas_playlists_compile(1,['items'=>[['ref'=>'own-'.getenv('SF_PLAYLIST_NATIVE_ID'),'version'=>getenv('SF_PLAYLIST_NATIVE_HASH')]]]);
$active=$pdo->inTransaction();if($active)$pdo->rollBack();
echo json_encode(['active'=>$active,'rolled_back'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE id=90')->fetchColumn()===0,'entries'=>count($compiled['manifest']['playlists'][0]['items']),'scope'=>$compiled['track_ids']]);
""")
            check(warm['active'] and warm['rolled_back'] and warm['entries'] == 2 and warm['scope'] == [], 'Prewarmed nested media compilation preserves repeats, deny-all scope and caller rollback')
            rollback = probe("""
sf_canvas_playlists_ensure_schema();$pdo=sf_db();$pdo->beginTransaction();
$result=sf_canvas_playlists_moderate(['id'=>9],(int)getenv('SF_PLAYLIST_NATIVE_ID'),'publish',getenv('SF_PLAYLIST_NATIVE_PUBLICATION_HASH'));
$active=$pdo->inTransaction();if($active)$pdo->rollBack();
$q=$pdo->prepare('SELECT COUNT(*) FROM canvas_artist_playlists WHERE playlist_id=?');$q->execute([(int)getenv('SF_PLAYLIST_NATIVE_ID')]);
echo json_encode(['active'=>$active,'published_inside'=>$result['status']==='published','rolled_back'=>(int)$q->fetchColumn()===0]);
""")
            check(rollback['active'] and rollback['published_inside'] and rollback['rolled_back'], 'Nested artist publication does not escape its caller atomic transaction')
            deleted = probe("""
sf_canvas_playlists_ensure_schema();sf_canvas_playlists_moderate(['id'=>9],(int)getenv('SF_PLAYLIST_NATIVE_ID'),'publish',getenv('SF_PLAYLIST_NATIVE_PUBLICATION_HASH'));
sf_db()->prepare('DELETE FROM user_playlists WHERE id=?')->execute([(int)getenv('SF_PLAYLIST_NATIVE_ID')]);
$q=sf_db()->prepare('SELECT COUNT(*) FROM canvas_artist_playlists WHERE playlist_id=?');$q->execute([(int)getenv('SF_PLAYLIST_NATIVE_ID')]);echo json_encode((int)$q->fetchColumn()===0);
""")
            check(deleted, 'Native foreign keys remove artist publication when its source playlist is deleted')

            # Pause the actual legacy CRUD in the middle of its child writes.
            # The compiler must wait on the parent and then reject its old frozen input.
            for action, sql in [('add', 'INSERT INTO user_playlist_tracks'), ('remove', 'DELETE FROM user_playlist_tracks'), ('reorder', 'UPDATE user_playlist_tracks SET position=')]:
                race = probe("""
sf_canvas_playlists_ensure_schema();$p=sf_playlist_create(1,'Atomic legacy mutation','','private','user',['native-song','native-other','native-song']);
$raw=sf_canvas_playlists_raw(sf_playlist_row($p['id']));echo json_encode(['id'=>$p['id'],'hash'=>sf_canvas_playlists_hash($raw),'entries'=>array_column($raw['items'],'item_id')]);
""")
                mutation = {'add': "sf_playlist_add_track(1,$id,'native-other');", 'remove': 'sf_playlist_remove_item(1,$id,$entries[1]);',
                            'reorder': 'sf_playlist_reorder(1,$id,array_reverse($entries));'}[action]
                writer_settings = gating(action + '-writer', sql, 'after')
                writer = start(action + '-writer', gate + '$id=' + str(race['id']) + ';$entries=' + json.dumps(race['entries']) + ';' + mutation + "echo 'true';", writer_settings)
                reader = None
                try:
                    marker(Path(writer_settings['SF_PLAYLIST_GATE_MARKER']), writer)
                    reader_settings = gating(action + '-reader', 'SELECT * FROM user_playlists WHERE id=?', 'before')
                    reader = start(action + '-reader', gate + """
$rejected=false;try{sf_canvas_playlists_compile(1,['items'=>[['ref'=>'own-""" + str(race['id']) + """','version'=>'""" + race['hash'] + """']]]);}catch(InvalidArgumentException){$rejected=true;}
echo json_encode($rejected);
""", reader_settings)
                    marker(Path(reader_settings['SF_PLAYLIST_GATE_MARKER']), reader)
                    Path(reader_settings['SF_PLAYLIST_GATE_RELEASE']).touch()
                    check(waits_for_lock(reader_settings, reader), 'Database proves compiler waits while legacy ' + action + ' holds its parent-first transaction')
                    Path(writer_settings['SF_PLAYLIST_GATE_RELEASE']).touch()
                    assert finish(writer)
                    check(finish(reader), 'Compiler rejects stale membership after the complete legacy ' + action + ' commits')
                    after = probe("$raw=sf_canvas_playlists_raw(sf_playlist_row(" + str(race['id']) + "));echo json_encode(['items'=>$raw['items'],'hash'=>sf_canvas_playlists_hash($raw)]);")
                    expected = (['native-song', 'native-other', 'native-song', 'native-other'] if action == 'add' else
                                ['native-song', 'native-song'] if action == 'remove' else ['native-song', 'native-other', 'native-song'])
                    expected_ids = (race['entries'] if action == 'add' else [race['entries'][0], race['entries'][2]] if action == 'remove' else list(reversed(race['entries'])))
                    actual_ids = [entry['item_id'] for entry in after['items']]
                    check([entry['track_id'] for entry in after['items']] == expected and [entry['position'] for entry in after['items']] == list(range(len(expected))) and
                          (actual_ids[:3] == expected_ids if action == 'add' else actual_ids == expected_ids) and after['hash'] != race['hash'],
                          'Legacy ' + action + ' exposes one complete final membership and order')
                finally:
                    Path(writer_settings['SF_PLAYLIST_GATE_RELEASE']).touch()
                    if writer.poll() is None:
                        writer.kill();writer.wait(timeout=5)
                    if reader is not None and reader.poll() is None:
                        Path(reader_settings['SF_PLAYLIST_GATE_RELEASE']).touch();reader.kill();reader.wait(timeout=5)

            # The inverse schedule: a live compiler holds a shared parent lock;
            # a legacy reorder must wait and cannot interleave child positions.
            race = probe("""
sf_canvas_playlists_ensure_schema();$p=sf_playlist_create(1,'Compiler first','','private','user',['native-song','native-other']);
$raw=sf_canvas_playlists_raw(sf_playlist_row($p['id']));echo json_encode(['id'=>$p['id'],'hash'=>sf_canvas_playlists_hash($raw),'entries'=>array_column($raw['items'],'item_id')]);
""")
            reader_settings = gating('compiler-first', 'SELECT * FROM user_playlists WHERE id=?', 'after')
            reader = start('compiler-first', gate + "echo json_encode(sf_canvas_playlists_compile(1,['items'=>[['ref'=>'own-" + str(race['id']) + "','version'=>'" + race['hash'] + "']]]));", reader_settings)
            writer = None
            try:
                marker(Path(reader_settings['SF_PLAYLIST_GATE_MARKER']), reader)
                writer_settings = gating('reorder-second', 'SELECT * FROM user_playlists WHERE id=? AND user_id=?', 'before')
                writer = start('reorder-second', gate + '$entries=' + json.dumps(race['entries']) + ';sf_playlist_reorder(1,' + str(race['id']) + ",$entries=array_reverse($entries));echo 'true';", writer_settings)
                marker(Path(writer_settings['SF_PLAYLIST_GATE_MARKER']), writer)
                Path(writer_settings['SF_PLAYLIST_GATE_RELEASE']).touch()
                check(waits_for_lock(writer_settings, writer), 'Database proves a legacy reorder waits while the compiler holds its current parent lock')
                Path(reader_settings['SF_PLAYLIST_GATE_RELEASE']).touch()
                compiled = finish(reader)
                assert finish(writer)
                check([entry['track_id'] for entry in compiled['manifest']['playlists'][0]['items']] == ['native-song', 'native-other'], 'Compiler-first snapshot contains the complete pre-reorder version')
            finally:
                Path(reader_settings['SF_PLAYLIST_GATE_RELEASE']).touch()
                if reader.poll() is None:
                    reader.kill();reader.wait(timeout=5)
                if writer is not None and writer.poll() is None:
                    Path(writer_settings['SF_PLAYLIST_GATE_RELEASE']).touch();writer.kill();writer.wait(timeout=5)

            # A caller may already have an old RR snapshot. Authorization and
            # publication reads must still see revocation committed afterwards.
            for revocation in ['publication', 'account']:
                state = probe("""
sf_canvas_playlists_ensure_schema();$p=sf_playlist_create(1,'Revocation current read','','public','user',['native-song']);
$raw=sf_canvas_playlists_raw(sf_playlist_row($p['id']));$review=sf_canvas_playlists_public_version(sf_canvas_playlists_hash($raw),0);
$published=sf_canvas_playlists_moderate(['id'=>9],$p['id'],'publish',$review);echo json_encode(['id'=>$p['id'],'version'=>$published['revision_hash']]);
""")
                ready = work / (revocation + '-old-snapshot.ready')
                release = work / (revocation + '-old-snapshot.release')
                reader = start(revocation + '-old-snapshot', """
sf_canvas_playlists_ensure_schema();$pdo=sf_db();$pdo->beginTransaction();$pdo->query('SELECT id FROM users WHERE id=1')->fetchColumn();
file_put_contents('""" + str(ready) + """','ready');$until=microtime(true)+10;while(!is_file('""" + str(release) + """')){if(microtime(true)>$until)throw new RuntimeException('Fixture gate timed out.');usleep(5000);}
$rejected=false;try{sf_canvas_playlists_compile(1,['items'=>[['ref'=>'artist-""" + str(state['id']) + """','version'=>'""" + state['version'] + """']]]);}catch(InvalidArgumentException){$rejected=true;}
$active=$pdo->inTransaction();$pdo->rollBack();echo json_encode(['rejected'=>$rejected,'active'=>$active]);
""")
                try:
                    marker(ready, reader)
                    if revocation == 'publication':
                        probe("sf_canvas_playlists_ensure_schema();sf_canvas_playlists_moderate(['id'=>9]," + str(state['id']) + ",'withdraw','" + state['version'] + "');echo 'true';")
                    else:
                        probe("sf_db()->exec(\"UPDATE users SET status='suspended' WHERE id=1\");echo 'true';")
                    release.touch()
                    revoked = finish(reader)
                    check(revoked['rejected'] and revoked['active'], 'Current compiler reads detect committed ' + revocation + ' revocation inside an older RR approval snapshot')
                finally:
                    release.touch()
                    if reader.poll() is None:
                        reader.kill();reader.wait(timeout=5)
                    if revocation == 'account':
                        probe("sf_db()->exec(\"UPDATE users SET status='active' WHERE id=1\");echo 'true';")

            subprocess.run([PHP, '-r', '(new PDO($argv[1],"root",""))->exec("CREATE DATABASE canvas_playlist_integration CHARACTER SET utf8mb4");', dsn], env=env, check=True, capture_output=True)
            config['database'] = 'canvas_playlist_integration'
            integration_env = {**env, 'SF_TEST_PLAYLIST_DB_CONFIG': json.dumps(config)}
            integrated = subprocess.run([PHP, str(ROOT / 'tests/canvas-playlists-integration.php')], env=integration_env, capture_output=True, text=True)
            assert integrated.returncode == 0, integrated.stdout + integrated.stderr
            check('checks passed' in integrated.stdout, 'Native MariaDB also passes the real project, provider-context, history and gallery integration suite')
        finally:
            server.terminate()
            server.wait(timeout=10)

print('Canvas playlists native MariaDB:', len(checks), 'checks passed plus', core_checks, 'core contracts', flush=True)
