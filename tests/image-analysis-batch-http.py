"""Verify durable batch analysis over real HTTP with four PHP workers and local vision only."""
import concurrent.futures
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import http.cookiejar
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get("SF_TEST_PHP","php")
PASSWORD="batch-fixture-password"
checks=0


def check(ok,label):
    global checks
    assert ok,label
    checks+=1
    print("PASS",label,flush=True)


class Response:
    def __init__(self,response):
        with response:
            self.status=response.status
            self.headers=response.headers
            self.raw=response.read()

    def json(self):
        assert self.headers.get_content_type()=="application/json",self.raw[:500]
        return json.loads(self.raw)

    def text(self):
        return self.raw.decode("utf-8",errors="replace")


class Client:
    def __init__(self,base):
        self.base=base
        self.cookies=http.cookiejar.CookieJar()
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self,method,path,data=None,headers=None):
        headers=dict(headers or {},Accept="application/json")
        if data is not None:
            data=json.dumps(data).encode()
            headers["Content-Type"]="application/json"
        request=urllib.request.Request(self.base+path,method=method,data=data,headers=headers)
        try:response=self.opener.open(request,timeout=12)
        except urllib.error.HTTPError as error:response=error
        return Response(response)

    def get(self,path):
        return self.request("GET",path)

    def post(self,path,data=None,headers=None):
        return self.request("POST",path,data,headers)

    def storage_state(self):
        return {"cookies":[{"name":cookie.name,"value":cookie.value} for cookie in self.cookies]}


def request(ctx,path,data=None,csrf=None,status=200):
    headers={"X-CSRF-Token":csrf} if csrf is not None else {}
    response=ctx.get(path) if data is None else ctx.post(path,data,headers)
    assert response.status==status,(path,response.status,response.text())
    result=response.json()
    assert result["ok"] is (status<300),(path,result)
    return result


def login(base,email):
    context=Client(base)
    init=request(context,"/api/auth.php")
    assert init["authenticated"] is False
    result=request(context,"/api/auth.php",{"action":"login","email":email,"password":PASSWORD},init["csrf"])
    assert result["authenticated"] is True
    return context,result["csrf"]


def cli(site,code):
    result=subprocess.run([PHP,"-r","define('SF_ROOT',$argv[1]);require SF_ROOT.'/api/bootstrap.php';"+code,str(site)],
                          capture_output=True,text=True)
    assert result.returncode==0,result.stdout+result.stderr
    return result.stdout


def install_fake(site):
    p=site/"api/story-inference-worker-core.php";source=p.read_text()
    start=source.index("function sf_inference_transport(");end=source.index("/** Supported executions:",start)
    fake=r'''function sf_inference_transport(string $url,array $headers,array $body,bool $multipart=false):array {
 if($url!=='https://api.openai.com/v1/responses')throw new RuntimeException('No external calls in this fixture.');
 $image=$body['input'][0]['content'][1]['image_url']??'';
 $bytes=base64_decode(substr($image,strpos($image,',')+1),true);
 if(!is_string($bytes)||!getimagesizefromstring($bytes))throw new RuntimeException('Expected verified pixels.');
 $entry=['sha256'=>hash('sha256',$bytes),'model'=>$body['model']??null];
 file_put_contents(SF_ROOT.'/storage/vision-calls.jsonl',json_encode($entry)."\n",FILE_APPEND|LOCK_EX);
 if(is_file(SF_ROOT.'/storage/block-vision')){
  file_put_contents(SF_ROOT.'/storage/vision-started','1');
  $deadline=microtime(true)+8;
  while(!is_file(SF_ROOT.'/storage/release-vision')&&microtime(true)<$deadline)usleep(10000);
  if(!is_file(SF_ROOT.'/storage/release-vision'))throw new RuntimeException('Fixture barrier expired.');
 }
 if(is_file(SF_ROOT.'/storage/fail-vision'))throw new RuntimeException('Fixture provider error.');
 $fields=['description'=>'A guitar and a concert poster.','visible_text'=>"STONEFELLOW\nLIVE 8PM",
  'objects'=>['guitar','poster'],'scene'=>'A concert stage.','style'=>'Photographic.',
  'colors'=>['blue'],'tags'=>['guitar','concert'],'uncertainties'=>[]];
 return ['output_text'=>json_encode(['analysis'=>$fields]),'usage'=>['input_tokens'=>101,'output_tokens'=>202]];
}
'''
    p.write_text(source[:start]+fake+source[end:])


def seed(site):
    return json.loads(cli(site,r"""
 require_once SF_ROOT.'/api/migrations.php';require_once SF_ROOT.'/api/agent-canvas-photo-core.php';
 require_once SF_ROOT.'/api/agent-canvas-image-analysis-core.php';
 sf_migration_run_all(1,'');sf_agent_canvas_image_analysis_schema();
 sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only-no-network','model'=>'gpt-4o-mini']);
 sf_enroll_user(2,null);sf_token_adjust(2,100000-sf_token_balance(2),'admin_grant','batch-fixture');
 $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
 $photos=[];$media=[];@mkdir(SF_ROOT.'/storage/media/library/image',0700,true);
 for($i=0;$i<5;$i++){
  $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).
   $chunk('IDAT',gzcompress(str_repeat("\0".random_bytes(384),128))).$chunk('IEND','');
  $photos[]=sf_agent_canvas_photo_store(2,$png,'photo'.$i.'.png','Private photo '.$i);
  $path='storage/media/library/image/batch'.$i.'.png';file_put_contents(SF_ROOT.'/'.$path,$png);
  $asset=sf_media_register_existing_file($path,'batch'.$i.'.png',1);
  $media[]=['id'=>(int)$asset['id'],'uuid'=>$asset['uuid'],'path'=>$path,'sha256'=>$asset['sha256']];
 }
 echo json_encode(['photos'=>$photos,'media'=>$media]);
 """))


def native_request(base,path,body,csrf,cookies):
    request=urllib.request.Request(base+path,data=json.dumps(body).encode(),method="POST",
                                   headers={"Content-Type":"application/json","X-CSRF-Token":csrf,"Cookie":cookies})
    try: response=urllib.request.urlopen(request,timeout=12)
    except urllib.error.HTTPError as error: response=error
    with response:
        raw=response.read()
        return response.status,json.loads(raw)


def run(base,db):
    site=db.parent/"site";fixture=seed(site);install_fake(site)
    admin,acsrf=login(base,"admin@example.test")
    listener,lcsrf=login(base,"listener@example.test")
    other,ocsrf=login(base,"other-admin@example.test")
    guest=Client(base)
    private="/api/image-analysis-batch.php";media="/admin/api/image-analysis-batch.php"
    single="/api/agent-canvas-image-analysis.php"
    def call(ctx,ep,data=None,csrf=None,status=200):
        return request(ctx,ep,data,csrf,status)
    def vision_count():
        log=site/"storage/vision-calls.jsonl"
        return len(log.read_text().splitlines()) if log.exists() else 0
    def balance():
        with sqlite3.connect(db) as connection:
            return connection.execute("SELECT balance FROM user_token_balances WHERE user_id=2").fetchone()[0]
    def get_job(ctx,ep,jid):
        return call(ctx,ep+"?id="+jid)["job"]
    def create(ctx,ep,csrf,jid,ids,mode="selected"):
        return call(ctx,ep,{"action":"create","request_id":jid,"mode":mode,"ids":ids},csrf)["job"]
    def act(ctx,ep,csrf,jid,action,**extra):
        return call(ctx,ep,{"action":action,"id":jid,**extra},csrf)["job"]


    photo_ids=[row["id"] for row in fixture["photos"]]
    media_ids=[row["id"] for row in fixture["media"]]
    def denied(ctx,ep,data=None,token=None):
        response=ctx.get(ep) if data is None else ctx.post(ep,data=data,headers={"X-CSRF-Token":token} if token else {})
        check(response.status in (401,403,404,409,422),"unauthorized or invalid batch request is rejected")
        result=response.json()
        check(result["ok"] is False and "job" not in result,"rejection exposes no private batch")
        return result
    def pending_key(item):
        return item["request_id"]
    def row_for(job,iid):
        return next(item for item in job["items"] if item["image_id"]==iid)
    def step(ctx,ep,csrf,job,item=None):
        extra={"request_id":pending_key(item)} if item and pending_key(item) else {}
        return act(ctx,ep,csrf,job["id"],"step",**extra)

    denied(guest,private)
    denied(guest,media)
    denied(listener,media)
    denied(listener,private,{"action":"create","request_id":"a"*32,"mode":"selected","ids":photo_ids[:2]})
    denied(listener,private,{"action":"create","request_id":"a"*32,"mode":"selected","ids":[media_ids[0]]},lcsrf)
    denied(listener,media,{"action":"create","request_id":"a"*32,"mode":"selected","ids":media_ids[:2]},lcsrf)
    job=create(listener,private,lcsrf,"b"*32,photo_ids[:2])
    check(job["status"]=="running" and job["counts"]["pending"]==2 and job["counts"]["total"]==2,
          "creating a selected private batch saves its two pending items")
    check(all(isinstance(item["request_id"],str) and len(item["request_id"])==32 and all(char in "0123456789abcdef" for char in item["request_id"]) for item in job["items"]),
          "each pending item exposes a stable request key for exact step recovery")
    replay=create(listener,private,lcsrf,"b"*32,photo_ids[:2])
    check(replay==job and vision_count()==0,"same create request recovers the same job without a provider call")
    denied(listener,private,{"action":"create","request_id":"b"*32,"mode":"selected","ids":[photo_ids[2]]},lcsrf)
    denied(other,private+"?id="+job["id"])
    denied(other,private,{"action":"pause","id":job["id"]},ocsrf)
    check(get_job(listener,private,job["id"])==job,"reload recovers the durable private batch")

    # Hold the local provider while another worker pauses or repeats this job.
    (site/"storage/block-vision").write_text("1")
    cookies="; ".join(cookie["name"]+"="+cookie["value"] for cookie in listener.storage_state()["cookies"])
    first_item=row_for(job,photo_ids[0])
    first_step={"action":"step","id":job["id"]}
    if pending_key(first_item):first_step["request_id"]=pending_key(first_item)
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
        future=executor.submit(native_request,base,private,first_step,lcsrf,cookies)
        for _ in range(400):
            if (site/"storage/vision-started").exists():break
            time.sleep(.01)
        else:raise RuntimeError("The first vision call never reached the fixture barrier")
        check(vision_count()==1,"the first step dispatches one local provider operation")
        concurrent_job=act(listener,private,lcsrf,job["id"],"step")
        check(vision_count()==1 and concurrent_job["counts"]["processing"]==1,
              "a concurrent step does not dispatch another image while one is processing")
        paused=act(listener,private,lcsrf,job["id"],"pause")
        check(paused["status"]=="paused","pause is persisted while the current image is still running")
        (site/"storage/release-vision").write_text("1")
        status,finished=future.result(timeout=10)
        check(status==200 and finished["job"]["status"]=="paused" and finished["job"]["counts"]["completed"]==1,
              "the current image completes once and leaves the remaining batch paused")
    for file in ("block-vision","release-vision","vision-started"):(site/"storage"/file).unlink(missing_ok=True)
    paused=get_job(listener,private,job["id"])
    check(paused["counts"]["pending"]==1 and paused["counts"]["processing"]==0 and vision_count()==1,
          "lost step response and reload retain exactly one completed image")
    repeated=call(listener,private,first_step,lcsrf)["job"]
    check(repeated["counts"]==paused["counts"] and vision_count()==1,"same-item step replay does not dispatch another paid operation")
    step(listener,private,lcsrf,paused)
    check(vision_count()==1,"a paused job cannot dispatch its pending image")
    resumed=act(listener,private,lcsrf,job["id"],"resume")
    second_item=row_for(resumed,photo_ids[1])
    finished=step(listener,private,lcsrf,resumed,second_item)
    check(finished["status"]=="completed" and finished["counts"]["completed"]==2 and vision_count()==2,
          "explicit resume dispatches the second image and completes the batch")
    repeated=step(listener,private,lcsrf,finished,second_item)
    check(repeated["counts"]==finished["counts"] and vision_count()==2,
          "replaying the completed item recovers progress without inference")
    check(balance()==99394,"only two completed image analyses debit the owner account")
    with sqlite3.connect(db) as connection:
        usage=connection.execute("SELECT user_id,delta,input_tokens,output_tokens FROM token_ledger WHERE entry_type='usage' AND source='image-analysis'").fetchall()
        check(usage==[(2,-303,101,202),(2,-303,101,202)],"provider usage is attributed only to the initiating listener")
        check(connection.execute("SELECT COUNT(*) FROM ai_token_reservations WHERE status='held'").fetchone()[0]==0,
              "completed batch items leave no token holds")

    # Change one analysis after the batch captured the version to approve.
    first=row_for(finished,photo_ids[0]);second=row_for(finished,photo_ids[1])
    changed=dict(first["analysis"],description="An independently reviewed first photo.")
    call(listener,single,{"action":"save","id":first["image_id"],"analysis_id":first["analysis_id"],
                         "revision":first["version"],"analysis":changed},lcsrf)
    approval=[{"image_id":item["image_id"],"analysis_id":item["analysis_id"],"version":item["version"],"analysis":item["analysis"]}
              for item in (first,second)]
    denied(other,private,{"action":"review","id":job["id"],"items":approval},ocsrf)
    reviewed=call(listener,private,{"action":"review","id":job["id"],"items":approval},lcsrf)
    results=reviewed["review_results"]
    check(len(results)==2 and results[0]["ok"] is False and results[1]["ok"] is True,
          "exact-version review reports a stale item and still approves the unchanged item")
    approved=call(listener,single+"?id="+second["image_id"])
    check(approved["analysis_status"]=="approved" and approved["image_analysis"]==second["analysis"],
          "batch approval saves only the selected unchanged draft")
    check(call(listener,single+"?id="+first["image_id"])["image_analysis"]["description"]==changed["description"] and vision_count()==2,
          "partial review preserves a newer edit and never repeats inference")

    stale=create(listener,private,lcsrf,"c"*32,photo_ids[2:4])
    source=fixture["photos"][2]
    original_path=Path(cli(site,"$file=sf_agent_canvas_image_file(2,'"+source["id"]+"');echo $file['path'];"))
    original_bytes=original_path.read_bytes();original_path.write_bytes(original_bytes[:-1]+bytes([original_bytes[-1]^1]))
    stale_item=row_for(stale,photo_ids[2])
    stale=step(listener,private,lcsrf,stale,stale_item)
    check(row_for(stale,photo_ids[2])["status"] in ("skipped","failed") and vision_count()==2,
          "a queued source change is rejected before a provider call")
    original_path.write_bytes(original_bytes)
    cli(site,"sf_db()->prepare('DELETE FROM agent_canvas_images WHERE user_id=? AND id=?')->execute([2,'"+photo_ids[3]+"']);")
    stale=step(listener,private,lcsrf,stale,row_for(stale,photo_ids[3]))
    check(stale["status"]=="completed" and vision_count()==2,
          "a deleted queued source becomes terminal without inference")
    step(listener,private,lcsrf,stale,stale_item)
    check(vision_count()==2,"restoring a changed source does not silently retry its terminal batch item")

    failed=create(listener,private,lcsrf,"d"*32,[photo_ids[4]])
    (site/"storage/fail-vision").write_text("1")
    failed_item=failed["items"][0];failed=step(listener,private,lcsrf,failed,failed_item)
    (site/"storage/fail-vision").unlink()
    check(failed["items"][0]["status"]=="failed" and vision_count()==3 and balance()==99394,
          "an uncertain provider operation becomes terminal and releases the refundable allowance")
    step(listener,private,lcsrf,failed,failed_item)
    check(vision_count()==3,"same-item replay cannot redispatch a failed or uncertain provider operation")

    admin_job=create(admin,media,acsrf,"e"*32,media_ids[:2])
    denied(other,media+"?id="+admin_job["id"])
    denied(other,media,{"action":"review","id":admin_job["id"],"items":[]},ocsrf)
    for item in list(admin_job["items"]):admin_job=step(admin,media,acsrf,admin_job,item)
    check(admin_job["status"]=="completed" and admin_job["counts"]["completed"]==2 and vision_count()==5,
          "the authenticated admin media batch analyzes its two selected library images")
    admin_approval=[{"image_id":item["image_id"],"analysis_id":item["analysis_id"],"version":item["version"],"analysis":item["analysis"]}
                    for item in admin_job["items"]]
    outcome=call(admin,media,{"action":"review","id":admin_job["id"],"items":admin_approval},acsrf)
    check(all(result["ok"] for result in outcome["review_results"]),"admin batch metadata approval uses the recorded exact versions")
    with sqlite3.connect(db) as connection:
        paid=connection.execute("SELECT user_id,delta,input_tokens,output_tokens FROM token_ledger WHERE entry_type='usage' AND source='image-analysis' AND user_id=1").fetchall()
        check(len(paid)==2 and all(row[0]==1 and row[1]==0 and row[2:]==(101,202) for row in paid),
              "admin usage is metered on the initiating admin account with its unlimited allowance")
        check(connection.execute("SELECT COUNT(*) FROM token_ledger WHERE entry_type='usage' AND user_id=3").fetchone()[0]==0,
              "another account receives no batch usage entries")
    missing=create(admin,media,acsrf,"f"*32,[],mode="missing")
    check(missing["counts"]["total"]==3 and vision_count()==5,
          "missing-analysis mode snapshots only remaining media images and performs no hidden calls")
    act(admin,media,acsrf,missing["id"],"cancel")
    check(vision_count()==5,"canceling pending missing-image work makes no provider operation")
    # A source can become unavailable after the charge and review were already committed.
    completed_file=Path(cli(site,"$f=sf_agent_canvas_image_file(2,'"+photo_ids[0]+"');echo $f['path'];"))
    original=completed_file.read_bytes();completed_file.write_bytes(original[:-1]+bytes([original[-1]^1]))
    changed_job=get_job(listener,private,job["id"])
    check(row_for(changed_job,photo_ids[0])["analysis"] is None and row_for(changed_job,photo_ids[0])["status"]=="skipped",
          "a completed source change removes stale analysis from the batch view")
    check(changed_job["usage"]["active_tokens_used"]==606 and changed_job["usage"]["input_tokens"]==202 and
          changed_job["usage"]["output_tokens"]==404,
          "source revocation preserves the paid usage receipt in batch totals")
    completed_file.write_bytes(original)

    credit_job=create(listener,private,lcsrf,"1"*32,[photo_ids[2]])
    cli(site,"sf_token_adjust(2,100-sf_token_balance(2),'admin_grant','fixture-credit-low');")
    before_calls=vision_count()
    credit_job=step(listener,private,lcsrf,credit_job,credit_job["items"][0])
    check(credit_job["status"]=="paused" and credit_job["counts"]["pending"]==1 and vision_count()==before_calls,
          "insufficient allowance pauses before claiming or dispatching its pending item")
    with sqlite3.connect(db) as connection:
        check(connection.execute("SELECT COUNT(*) FROM ai_token_reservations WHERE status='held'").fetchone()[0]==0,
              "credit preflight leaves no refundable token hold")
    cli(site,"sf_token_adjust(2,100000-sf_token_balance(2),'admin_grant','fixture-credit-restored');")
    credit_job=act(listener,private,lcsrf,credit_job["id"],"resume")
    credit_job=step(listener,private,lcsrf,credit_job,credit_job["items"][0])
    check(credit_job["status"]=="completed" and vision_count()==before_calls+1 and balance()==99697,
          "explicit resume after adding credits analyzes once and debits only actual usage")



def main():
    with tempfile.TemporaryDirectory(prefix="stoneward-v281-batch-") as temp:
        temp=Path(temp);site=temp/"site";site.mkdir()
        for name in ("api","admin","config"):shutil.copytree(ROOT/name,site/name)
        for name in ("data","storage","sessions","assets/js"):(site/name).mkdir(parents=True,exist_ok=True)
        for name in ("catalog","releases","shows"):(site/"data"/(name+".json")).write_text("[]")
        db=temp/"fresh.sqlite";(site/"config/database.php").write_text("<?php return ['driver'=>'sqlite','path'=>'"+str(db)+"'];")
        hashed=subprocess.check_output([PHP,"-r","echo password_hash($argv[1],PASSWORD_BCRYPT,['cost'=>4]);",PASSWORD],text=True)
        with sqlite3.connect(db) as connection:
            connection.execute("CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)")
            connection.execute("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)")
            connection.executemany("INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)",[
                (1,"admin@example.test","Admin",hashed,"admin","active","now","now"),
                (2,"listener@example.test","Listener",hashed,"user","active","now","now"),
                (3,"other-admin@example.test","Other admin",hashed,"admin","active","now","now")])
        with socket.socket() as sock:sock.bind(("127.0.0.1",0));port=sock.getsockname()[1]
        base="http://127.0.0.1:"+str(port);log=temp/"server.log"
        env=dict(os.environ,PHP_CLI_SERVER_WORKERS="4")
        with log.open("wb") as output:
            server=subprocess.Popen([PHP,"-d","session.save_path="+str(site/"sessions"),"-d","disable_functions=curl_init",
                                     "-d","allow_url_fopen=0","-S","127.0.0.1:"+str(port),"-t",str(site)],
                                    cwd=site,stdout=output,stderr=output,env=env,start_new_session=True)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(("127.0.0.1",port),timeout=.1):break
                    except OSError:time.sleep(.02)
                else:raise RuntimeError("Server did not start")
                run(base,db)
            except BaseException:
                print(log.read_text()[-9000:]);raise
            finally:
                os.killpg(server.pid,signal.SIGTERM);server.wait()
    print("PASS",checks,"batch HTTP checks; no external provider calls")


if __name__=="__main__":main()
