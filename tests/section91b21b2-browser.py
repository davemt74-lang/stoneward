"""Real Chromium MP3 playback and production player functions; injected MediaError, no paid services."""
from pathlib import Path
import base64, importlib.util, json, os, subprocess, tempfile
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('media_http',ROOT/'tests/section91b21b2-media-http.py');http=importlib.util.module_from_spec(spec);spec.loader.exec_module(http)
app=(ROOT/'assets/js/app.js').read_text()
functions=app[app.index('  function playbackEndedEarly(){'):app.index('  async function stepTrack(')]
handlers=app[app.index("  audio.addEventListener('loadedmetadata',"):app.index("  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&chatPopup")]
setup="""
const audio=document.querySelector('audio'),playerCurrent=document.createElement('span'),playerDuration=document.createElement('span'),playerSeek=document.createElement('input');
const footerPlayer=document.querySelector('section'),playerPlaybackStatus=document.querySelector('p');
const st={track:null,playing:false,playSource:'player',lastProgressAt:0,queue:{items:[]},playlistPlayback:{}};
const playbackLifecycle=window.StonefellowPlaybackLifecycle.create();let playbackWantsAudio=false,playbackBufferTimer=null,pendingNetworkRecovery=null;
const songCaptureSkill=null,songListeningSkill=null;
const track={id:'fixture-song',audio:'api/media.php?id=song',duration:240,title:'Four-minute test audio'};
const time=n=>String(Math.floor(n));
function updateFooterPlayer(){}function updateQueueUi(){}function clearPlaylistPlayback(){}function syncConversationMusic(){}function scheduleSpeechContextSync(){}function agent(){}
function recordListen(event){window.receipts.push(event)}function agentSessionActive(){return false}function isTemporaryPlaylist(){return false}
window.receipts=[];window.playPositions=[];audio.addEventListener('play',()=>window.playPositions.push(audio.currentTime));
"""
with tempfile.TemporaryDirectory(prefix='sf-mobile-browser-') as directory:
 base=Path(directory);http.make_fixture(base)
 subprocess.run(['ffmpeg','-v','error','-f','lavfi','-i','sine=frequency=220:sample_rate=44100','-t','240','-ac','1','-b:a','64k',str(base/'storage/media/song.mp3')],check=True)
 html='<meta name="viewport" content="width=device-width, initial-scale=1"><title>Playback regression fixture</title><section><p></p><audio preload="metadata"></audio><button id="start">Play test song</button></section><script>'+(ROOT/'assets/js/player-playback-lifecycle.js').read_text()+setup+functions+handlers+"document.querySelector('button').onclick=()=>play(track);</script>"
 (base/'index.html').write_text(html)
 with sync_playwright() as pw:
  executable=os.environ.get('CHROMIUM_EXECUTABLE','/usr/bin/chromium')
  browser=pw.chromium.launch(executable_path=executable if Path(executable).exists() else None,headless=True,args=['--no-sandbox','--autoplay-policy=no-user-gesture-required'])
  try:
   page=browser.new_page(viewport={'width':375,'height':812},is_mobile=True,has_touch=True)
   errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
   page.set_content(html)
   page.evaluate("data=>{const bytes=Uint8Array.from(atob(data),c=>c.charCodeAt(0));track.audio=URL.createObjectURL(new Blob([bytes],{type:'audio/mpeg'}));}",base64.b64encode((base/'storage/media/song.mp3').read_bytes()).decode())
   page.click('#start');page.wait_for_function('audio.duration>239 && !audio.paused')
   http.check(True,'real four-minute MP3 starts in mobile-width Chromium')
   page.evaluate('audio.currentTime=60');page.wait_for_function('!audio.seeking && audio.currentTime>=60')
   checkpoint=page.evaluate('rememberPlaybackPosition();playbackLifecycle.position(playbackLifecycle.token())')
   page.evaluate("Object.defineProperty(audio,'error',{configurable:true,value:{code:2}});recoverNetworkPlayback();delete audio.error;")
   page.wait_for_function('pendingNetworkRecovery===null && !audio.paused && audio.currentTime>=59.5',timeout=15000)
   values=page.evaluate('window.playPositions')
   http.check(len(values)==2 and values[1]>=checkpoint-.35,'actual audio reload and seek resume at quarter-song checkpoint, never zero')
   page.evaluate('audio.playbackRate=16');page.wait_for_function('audio.ended',timeout=20000)
   http.check(page.evaluate('audio.currentTime')>239,'recovered audio reaches the real full-track end')
   http.check(page.evaluate("receipts.filter(e=>e==='complete').length")==1,'one completion receipt only after the full file ends')
   page.wait_for_timeout(500)
   http.check(page.evaluate('audio.paused') and len(page.evaluate('playPositions'))==2,'finished track stays stopped without automatic restart')
   page.click('#start');page.wait_for_function('!audio.paused');page.evaluate('audio.currentTime=45');page.wait_for_function('!audio.seeking && audio.currentTime>=45')
   page.evaluate("rememberPlaybackPosition();Object.defineProperty(audio,'error',{configurable:true,value:{code:2}});recoverNetworkPlayback();delete audio.error;invalidatePlayback();audio.pause();")
   before=len(page.evaluate('playPositions'));page.wait_for_timeout(500)
   http.check(page.evaluate('audio.paused') and len(page.evaluate('playPositions'))==before,'explicit pause cancels real-browser recovery')
   http.check(not errors,'no uncaught browser errors during recovery, completion or cancel')
   print(json.dumps({'engine':'Chromium','viewport':375,'duration':page.evaluate('audio.duration'),'recovered_play_positions':values,'page_errors':errors}),flush=True)
  finally:browser.close()
print(f'91B21B2 browser: {http.checks} checks passed')
