"""Production media route on an isolated synthetic bootstrap: ranges, cache/auth and session concurrency."""
from pathlib import Path
import contextlib, http.cookiejar, os, shutil, signal, socket, subprocess, tempfile, time
import urllib.request, urllib.error
ROOT=Path(os.environ.get('SF_PLAYBACK_SOURCE_ROOT',Path(__file__).resolve().parents[1]))
checks=0
def check(condition,label):
 global checks
 assert condition,label
 checks+=1;print('PASS '+label,flush=True)
def make_fixture(base):
 (base/'api').mkdir();(base/'storage/media').mkdir(parents=True)
 for name in ['media.php','media-stream-range.php']:shutil.copy2(ROOT/'api'/name,base/'api'/name)
 # All authorization substitutes are confined to this disposable test root, never application code.
 (base/'api/bootstrap.php').write_text('''<?php
declare(strict_types=1);define('SF_ROOT',dirname(__DIR__));
session_name('sf_stream_fixture');session_start();
function sf_media_ensure_schema():void{}
function sf_clean_text($s,$n):string{return substr((string)$s,0,$n);}
function sf_media_asset($id):?array{if(!in_array($id,['fixture','private','song']))return null;$path=$id==='song'?'song.mp3':'bytes.bin';return ['id'=>$id==='private'?2:1,'stored_path'=>'storage/media/'.$path,'variants'=>[],'mime_type'=>$id==='song'?'audio/mpeg':'application/octet-stream','extension'=>$id==='song'?'mp3':'bin','original_name'=>$path];}
function sf_media_protected_master_asset($id):bool{return $id===2;}
function sf_current_user():?array{return ($_GET['viewer']??'')==='admin'?['id'=>1,'role'=>'admin']:null;}
function sf_db(){return new class{function prepare($sql){return new class{function execute($a){}function fetchAll(){if(($_GET['links']??'')==='none')return [];return [['entity_type'=>($_GET['links']??'')==='member'?'member_content':'track','entity_id'=>'song','public_visible'=>1,'download_allowed'=>0]];}};}};}
function sf_membership_content_get($id){return ['id'=>$id];}
function sf_membership_content_access($uid,$content){return ['allowed'=>($_GET['member']??'')==='1'];}
''')
 (base/'probe.php').write_text("<?php session_name('sf_stream_fixture');session_start();echo 'session-free';session_write_close();")
 data=bytes(range(256))*256
 with (base/'storage/media/bytes.bin').open('wb') as f:
  for _ in range(1024):f.write(data)  # 64MiB: cannot fit the paused response in socket buffers.
 return data
@contextlib.contextmanager
def server(base):
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 log=(base/'php.log').open('w');env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4')
 proc=subprocess.Popen(['php','-d','session.save_path='+str(base),'-S',f'127.0.0.1:{port}','-t',str(base)],stdout=log,stderr=log,env=env,start_new_session=True)
 url=f'http://127.0.0.1:{port}'
 try:
  for _ in range(100):
   try:urllib.request.urlopen(url+'/probe.php',timeout=.3).close();break
   except (OSError,urllib.error.URLError):time.sleep(.05)
  else:raise RuntimeError('Fixture server did not start')
  yield url
 finally:
  os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=5);log.close()
def fetch(url,range_=None):
 request=urllib.request.Request(url,headers={'Range':range_} if range_ else {})
 try:
  with urllib.request.urlopen(request,timeout=4) as r:return r.status,r.headers,r.read()
 except urllib.error.HTTPError as e:return e.code,e.headers,e.read()
def run_http(base,url):
 size=(base/'storage/media/bytes.bin').stat().st_size
 for header,start,length in [('bytes=0-1',0,2),('bytes=100-356',100,257),('bytes=-257',size-257,257),('bytes='+str(size-128)+'-',size-128,128)]:
  status,headers,body=fetch(url+'/api/media.php?id=fixture',header)
  check(status==206 and len(body)==length and body==bytes(i%256 for i in range(start,start+length)),header+' exact byte identity')
  check(headers.get('Content-Range')==f'bytes {start}-{start+length-1}/{size}' and headers.get('Content-Length')==str(length),'range length and whole-file size agree')
 status,h,b=fetch(url+'/api/media.php?id=fixture','bytes='+str(size)+'-')
 check(status==416 and h.get('Content-Range')==f'bytes */{size}','out-of-bounds range rejects without a false success')
 check(fetch(url+'/api/media.php?id=private','bytes=0-1')[0]==404,'protected master denied to guest')
 check(fetch(url+'/api/media.php?id=fixture&links=member','bytes=0-1')[0]==404,'unentitled membership media denied')
 status,h,b=fetch(url+'/api/media.php?id=fixture&links=member&member=1','bytes=0-1')
 check(status==206 and 'private, no-store' in h.get('Cache-Control',''),'member-only response never public-cacheable')
 status,h,b=fetch(url+'/api/media.php?id=fixture&viewer=admin','bytes=0-1')
 check(status==206 and 'private, no-store' in h.get('Cache-Control',''),'Admin preview remains private')
 status,h,b=fetch(url+'/api/media.php?id=fixture','bytes=0-1')
 check('public, max-age=3600' in h.get('Cache-Control','') and 'no-transform' in h.get('Cache-Control',''),'intentionally public bytes retain public no-transform policy')
 check(fetch(url+'/api/media.php?id=fixture&download=1','bytes=0-1')[0]==403,'stream fixes do not grant download permission')
 opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 opener.open(url+'/probe.php',timeout=2).close()
 stream=opener.open(url+'/api/media.php?id=fixture',timeout=3)
 try:
  stream.read(1024);time.sleep(.15)
  start=time.monotonic()
  with opener.open(url+'/probe.php',timeout=2) as r:result=r.read()
  elapsed=time.monotonic()-start
  check(result==b'session-free' and elapsed<1.5,'same-session request completes while long audio response is still open')
  with opener.open(urllib.request.Request(url+'/api/media.php?id=fixture',headers={'Range':'bytes=16777216-16777217'}),timeout=2) as r:
   check(r.status==206 and r.read()==b'\x00\x01','overlapping quarter-file range succeeds with same session cookie')
 finally:stream.close()
if __name__=='__main__':
 with tempfile.TemporaryDirectory(prefix='sf-mobile-http-') as tmp:
  base=Path(tmp);make_fixture(base)
  with server(base) as url:run_http(base,url)
 print(f'91B21B2 media HTTP: {checks} checks passed')
