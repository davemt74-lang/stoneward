"""Native listening playback atomic dedupe, rollback and privacy checks.

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
            subprocess.run([PHP, '-r', "(new PDO('mysql:host=127.0.0.1;port="+str(port)+"','root',''))->exec('CREATE DATABASE listening_playback CHARACTER SET utf8mb4');"], env=env, check=True, capture_output=True)
            import shutil
            app=work/'app'
            for folder in ['config','data','assets/js']:(app/folder).mkdir(parents=True,exist_ok=True)
            (app/'api').symlink_to(ROOT/'api',target_is_directory=True)
            shutil.copy2(ROOT/'config/store.default.php',app/'config/store.default.php')
            (app/'assets/js/app.js').write_text('/* native listening fixture application */')
            (app/'config/database.php').write_text("<?php return ['driver'=>'mysql','host'=>'127.0.0.1','port'=>"+str(port)+",'database'=>'listening_playback','username'=>'root','password'=>''];")
            (app/'data/catalog.json').write_text(json.dumps([{'id':'native-song','title':'Native song','state':'published'}]))
            for name in ['releases','shows']:(app/'data'/f'{name}.json').write_text('[]')
            env['SF_LISTEN_APP']=str(app)
            setup="""<?php declare(strict_types=1);define('SF_ROOT',getenv('SF_LISTEN_APP'));require SF_ROOT.'/api/bootstrap.php';require_once SF_ROOT.'/api/listening-playback-core.php';require_once SF_ROOT.'/api/agent-scene-history.php';
"""
            script=work/'probe.php'
            def probe(code):
                script.write_text(setup+code)
                result=subprocess.run([PHP,str(script)],env=env,capture_output=True,text=True)
                assert result.returncode==0,result.stdout+result.stderr
                return json.loads(result.stdout)
            probe("""
sf_db()->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(200),display_name VARCHAR(100),password_hash VARCHAR(255),role VARCHAR(12),status VARCHAR(20),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40) NULL) ENGINE=InnoDB");
sf_db()->exec("INSERT INTO users VALUES(1,'native@example.invalid','Native','fixture','user','active','now','now',NULL),(2,'other@example.invalid','Other','fixture','user','active','now','now',NULL)");
sf_listening_playback_schema();echo 'true';
""")
            session={'session_token':'a1'*16,'privacy_epoch':0,'history_enabled':True}
            def request(action,play,event,type='start',position=0,elapsed=0):
                return {'action':action,'play_id':play,'event_id':event,'event_type':type,'track_id':'native-song','position_seconds':position,'duration_seconds':120,'elapsed_played_seconds':elapsed,'playback_rate':1,'context':{'app':True},'playback_method':'main'}
            worker=work/'worker.php'
            worker.write_text(setup+"""
$input=json_decode(base64_decode($argv[1]),true);fgets(STDIN);
try{$r=sf_listening_playback_record($input['user'],$input['session'],$input['body'],$input['clock']);echo json_encode(['state'=>'ok','result'=>$r]);}
catch(SfListeningPlaybackConflict $e){echo json_encode(['state'=>'conflict','reason'=>$e->reason]);}
""")
            import base64
            def spawn(body,clock=2000000000000,user=None,session_value=None):
                payload={'user':user or {'id':1,'role':'user'},'session':session_value or session,'body':body,'clock':clock}
                encoded=base64.b64encode(json.dumps(payload).encode()).decode()
                return subprocess.Popen([PHP,str(worker),encoded],env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            def finish(children):
                for child in children:child.stdin.write('go\n');child.stdin.flush()
                results=[]
                for child in children:
                    output,error=child.communicate(timeout=15);assert child.returncode==0,output+error;results.append(json.loads(output))
                return results
            play='b1'*16;begin=request('begin',play,'c1'*16)
            result=finish([spawn(begin),spawn(begin)])
            check(all(r['state']=='ok' for r in result) and sum(r['result']['replayed'] for r in result)==1,
                  'Concurrent first begin workers commit one instance and replay one identical request')
            totals=probe("echo json_encode(['events'=>(int)sf_db()->query(\"SELECT COUNT(*) FROM listening_events WHERE event_type='start'\")->fetchColumn(),'crm'=>(int)sf_db()->query(\"SELECT COUNT(*) FROM fan_crm_events WHERE event_type='listen_start'\")->fetchColumn(),'activity'=>(int)sf_db()->query(\"SELECT COUNT(*) FROM user_activity WHERE event_type='listen_start'\")->fetchColumn()]);")
            check(totals=={'events':1,'crm':1,'activity':1},'Concurrent begin has one canonical row, account activity and CRM row')
            done=request('event',play,'d1'*16,'complete',120,120)
            result=finish([spawn(done,2000000120000),spawn(done,2000000120000)])
            check(all(r['state']=='ok' for r in result) and sum(r['result']['replayed'] for r in result)==1,
                  'Concurrent identical completion workers replay one canonical completion')
            again=request('event',play,'e1'*16,'complete',120,120)
            finish([spawn(again,2000000121000)])
            counts=probe("echo json_encode(['complete'=>(int)sf_db()->query(\"SELECT COUNT(*) FROM listening_events WHERE event_type='complete'\")->fetchColumn(),'time'=>(float)sf_db()->query('SELECT SUM(listened_seconds) FROM listening_events')->fetchColumn()]);")
            check(counts=={'complete':1,'time':120},'Repeated ended with a fresh event ID cannot duplicate completion or measured time')
            conflict=request('begin','f1'*16,'a2'*16);other_body=dict(conflict,position_seconds=2)
            result=finish([spawn(conflict),spawn(other_body)])
            check(sorted(r['state'] for r in result)==['conflict','ok'],'Concurrent conflicting payloads sharing an ID have one winner and one rejection')
            # The guest path cannot rely on an account lock; exercise its unique play/receipt keys.
            guest_body=request('begin','b2'*16,'c2'*16)
            guest_session={'session_token':'d2'*16,'privacy_epoch':0,'history_enabled':True}
            children=[]
            for _ in range(2):
                payload={'user':None,'session':guest_session,'body':guest_body,'clock':2000000000000}
                encoded=base64.b64encode(json.dumps(payload).encode()).decode()
                children.append(subprocess.Popen([PHP,str(worker),encoded],env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True))
            result=finish(children)
            check(all(r['state']=='ok' for r in result) and sum(r['result']['replayed'] for r in result)==1,
                  'Concurrent anonymous first begin also deduplicates without an account lock')
            rollback=probe("""
$before=(int)sf_db()->query('SELECT COUNT(*) FROM listening_events')->fetchColumn();
sf_db()->exec("CREATE TRIGGER fail_listen_receipt BEFORE INSERT ON listening_playback_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
$failed=false;try{sf_listening_playback_record(['id'=>1],['session_token'=>str_repeat('a1',16),'privacy_epoch'=>0,'history_enabled'=>true],['action'=>'begin','play_id'=>str_repeat('e2',16),'event_id'=>str_repeat('f2',16),'event_type'=>'start','track_id'=>'native-song','context'=>['app'=>true],'playback_method'=>'main'],2000000000000);}catch(PDOException $e){$failed=true;}
sf_db()->exec('DROP TRIGGER fail_listen_receipt');echo json_encode(['failed'=>$failed,'before'=>$before,'after'=>(int)sf_db()->query('SELECT COUNT(*) FROM listening_events')->fetchColumn(),'instances'=>(int)sf_db()->query("SELECT COUNT(*) FROM listening_playbacks WHERE play_id='".str_repeat('e2',16)."'")->fetchColumn()]);
""")
            check(rollback['failed'] and rollback['before']==rollback['after'] and rollback['instances']==0,
                  'Native receipt insertion failure rolls back canonical row and play instance')
            clearer=work/'clearer.php'
            clearer.write_text(setup+"""
sf_listening_playback_schema();sf_db()->beginTransaction();$q=sf_db()->query('SELECT id FROM users WHERE id=1 FOR UPDATE');$q->fetchAll();$q->closeCursor();
file_put_contents(SF_ROOT.'/holding-clear','ready');fgets(STDIN);$count=sf_listening_playback_forget(1);sf_db()->commit();
echo json_encode(['forgotten'=>$count,'epoch'=>sf_listening_playback_epoch(1),'plays'=>sf_listening_playback_export(1),'joined'=>(int)sf_db()->query('SELECT COUNT(*) FROM listening_events e JOIN listening_playbacks p ON p.play_id=e.play_id WHERE p.user_id=1')->fetchColumn()]);
""")
            controller=subprocess.Popen([PHP,str(clearer)],env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            for _ in range(300):
                if (app/'holding-clear').exists():break
                if controller.poll() is not None:
                    output,error=controller.communicate();raise AssertionError(output+error)
                time.sleep(.01)
            else:raise AssertionError('Privacy lock gate failed')
            pending=spawn(request('begin','e3'*16,'f3'*16));pending.stdin.write('go\n');pending.stdin.flush()
            controller.stdin.write('clear\n');controller.stdin.flush()
            output,error=controller.communicate(timeout=15);assert controller.returncode==0,output+error;forgotten=json.loads(output)
            output,error=pending.communicate(timeout=15);assert pending.returncode==0,output+error;pending_result=json.loads(output)
            check(pending_result['state']=='conflict' and pending_result['reason']=='privacy_changed',
                  'Worker racing a locked privacy clear revalidates the epoch after obtaining account lock')
            check(forgotten['forgotten']>0 and forgotten['epoch']==1 and forgotten['plays']==[] and forgotten['joined']==0,
                  'Native privacy clear strips identity and playback joins while retaining overall counts')
            late=request('begin','a3'*16,'b3'*16)
            result=finish([spawn(late)])
            check(result[0]['state']=='conflict' and result[0]['reason']=='privacy_changed',
                  'Late worker captured before privacy clear cannot recreate account-linked events')
            # Disabled accounts are revalidated under the transaction lock.
            probe("sf_db()->exec(\"UPDATE users SET status='closed' WHERE id=1\");echo 'true';")
            current_session=dict(session,privacy_epoch=1)
            result=finish([spawn(request('begin','c3'*16,'d3'*16),session_value=current_session)])
            check(result[0]['state']=='conflict','Closed account cannot record through a previously captured authenticated context')
            queued=probe("""
sf_db()->exec("UPDATE users SET status='active' WHERE id=1");$epoch=sf_listening_playback_epoch(1);
sf_listening_playback_record(['id'=>1],['session_token'=>str_repeat('a4',16),'privacy_epoch'=>$epoch,'history_enabled'=>true],['action'=>'begin','play_id'=>str_repeat('b4',16),'event_id'=>str_repeat('c4',16),'event_type'=>'start','track_id'=>'native-song','context'=>['app'=>true],'playback_method'=>'main'],2000000200000);
$source=sf_db()->query('SELECT * FROM listening_crm_outbox WHERE user_id=1 ORDER BY listening_event_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$a=sf_automation_save(['name'=>'Native queued source','status'=>'published','trigger_type'=>'crm_event','trigger'=>['events'=>['listen_start']],'steps'=>[['type'=>'add_tag','config'=>['tag'=>'withdrawn-native-tag']]],'cooldown_hours'=>0,'max_runs_per_contact'=>100],1);
$GLOBALS['sf_listening_automation_guard']=['user_id'=>1,'epoch'=>$epoch,'crm_event_id'=>(int)$source['crm_event_id']];
$r1=sf_automation_start((int)$a['id'],(int)$source['contact_id'],(int)$source['crm_event_id'],'native-claim');$r2=sf_automation_start((int)$a['id'],(int)$source['contact_id'],(int)$source['crm_event_id'],'native-unclaimed');unset($GLOBALS['sf_listening_automation_guard']);
$q=sf_db()->prepare('SELECT * FROM lifecycle_automation_runs WHERE id=?');$q->execute([$r1['id']]);$captured=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
echo json_encode(['claimed'=>$captured,'unclaimed_id'=>$r2['id']]);
""")
            claim_worker=work/'claim-worker.php'
            claim_worker.write_text(setup+"$run=json_decode(base64_decode($argv[1]),true);fgets(STDIN);echo json_encode(sf_listening_automation_claim($run,0));")
            encoded=base64.b64encode(json.dumps(queued['claimed']).encode()).decode()
            children=[subprocess.Popen([PHP,str(claim_worker),encoded],env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)]
            for child in children:child.stdin.write('go\n');child.stdin.flush()
            results=[]
            for child in children:
                output,error=child.communicate(timeout=15);assert child.returncode==0,output+error;results.append(json.loads(output))
            check(sorted(results)==['busy','claimed'],'Concurrent post-commit automation workers claim a listening-derived step once')
            env['SF_LISTEN_QUEUED_RUN']=str(queued['unclaimed_id'])
            withdrawn=probe("""
$q=sf_db()->prepare('SELECT * FROM lifecycle_automation_runs WHERE id=?');$q->execute([(int)getenv('SF_LISTEN_QUEUED_RUN')]);$old=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
sf_listening_playback_forget(1);$claim=sf_listening_automation_claim($old,0);sf_automation_continue((int)$old['id']);sf_listening_playback_drain_crm();
echo json_encode(['claim'=>$claim,'tags'=>sf_db()->query('SELECT tags_json FROM fan_contacts WHERE user_id=1')->fetchColumn(),'outbox'=>(int)sf_db()->query('SELECT COUNT(*) FROM listening_crm_outbox WHERE user_id=1')->fetchColumn()]);
""")
            check(withdrawn['claim']=='withdrawn' and 'withdrawn-native-tag' not in withdrawn['tags'] and withdrawn['outbox']==0,
                  'Queued intent selected before privacy clear cannot claim or recreate tags afterward')
            print(f'{len(checks)} native MariaDB listening checks passed',flush=True)
        finally:
            server.terminate();server.wait(timeout=10)
