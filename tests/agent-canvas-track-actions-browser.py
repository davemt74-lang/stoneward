"""Exercise full album rows and trusted track actions in the production canvas sandbox."""
import base64
import hashlib
import os
import re
import shutil
from pathlib import Path

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
RENDERER = (ROOT / 'assets/js/public-agent-canvas.js').read_text()
BRIDGE = re.search(r'const sceneTrackBridge = `([\s\S]*?)`;', RENDERER).group(1)
BRIDGE_HASH = "'sha256-" + base64.b64encode(hashlib.sha256(BRIDGE.encode()).digest()).decode() + "'"
assert re.search(r'const sceneTrackHash = "([^"]+)";', RENDERER).group(1) == BRIDGE_HASH
SITE = 'https://fixture.test/track-actions/'
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


FIXTURE = '''() => {
  window.played=[];window.receivedTrackActions=[];window.trackReads=[];
  window.authorized=true;window.currentTrack='origin-track';
  window.albumRows=Array.from({length:8},(_,index)=>({
    track_id:'fixture-track-'+(index+1),title:'Synthetic track '+(index+1),
    release_id:'albumA',position:index+1,duration_seconds:100+index
  }));
  window.dataset={state:'available',fields:{track_id:'text',title:'text',release_id:'text',position:'number',duration_seconds:'number'},
    filters:['release_id'],actions:{play_track:{field:'track_id'}},
    rows:[...albumRows,{track_id:'foreign-album-track',title:'Foreign release track',release_id:'albumB',position:1,duration_seconds:99}]};
  window.currentData=id=>({state:'available',fields:{track_id:'text',title:'text'},filters:['track_id'],rows:[],
    current_rows:[{track_id:id,title:'Current '+id}]});
  window.canvasScene={id:71,canvas_kind:'custom',track_id:'',title:'Synthetic album playlist',agent_design:'hidden',
    html:'<article class="playlist-room"><h1>Synthetic album playlist</h1>'+
      '<section class="album-playlist sf-dataset-published_release_tracks sf-filter-release_id--albumA">'+
        '<div class="sf-data-row"><span class="sf-field-position"></span><span class="sf-action-play-track"><span class="sf-field-title"></span></span></div>'+
        '<p class="sf-data-empty">No published tracks</p></section>'+
      '<section class="current-row sf-dataset-playing_track sf-current-track"><p class="sf-data-row"><span class="sf-field-title"></span></p></section>'+
      '<span class="loose-action sf-action-play-track">Unbound action</span></article>',
    css:'.playlist-room{padding:270px 16px 20px}.playlist-room h1{margin:0 0 12px;font-size:20px}.album-playlist .sf-data-row{display:flex;align-items:center;gap:8px;margin:3px 0}.sf-action-play-track{border:1px solid #bcd;padding:4px 10px;border-radius:6px;background:#27384c}.current-row{font-size:12px}',
    datasets:{published_release_tracks:dataset,playing_track:currentData(currentTrack)},dataset_track_id:currentTrack};
  window.addEventListener('message',event=>{
    if(event.data?.stonefellowSceneTrackAction)receivedTrackActions.push(event.data);
  });
  window.changeSong=id=>{currentTrack=id;SFPublicAgentCanvas.syncPlayer();};
  window.mountResult=SFPublicAgentCanvas.mount(document.querySelector('#target'),{
    canUseScenes:()=>authorized,getSceneOwner:()=> 'fixture-owner',getTrackId:()=>currentTrack,
    onSceneCatalogPlay:id=>{played.push(id);changeSong(id);},
    onSceneTrackData:async(id,trackId,signal)=>{
      trackReads.push({id,trackId,aborted:signal.aborted});
      return {id,dataset_track_id:trackId,datasets:{playing_track:currentData(trackId)}};
    }
  });
  window.showResult=SFPublicAgentCanvas.showScene(canvasScene,{focus:false});
  window.originalFrame=document.querySelector('.sf-agent-scene-backdrop iframe');
}'''


with sync_playwright() as p:
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    launch = {'headless': True, 'args': ['--no-sandbox']}
    if executable:
        launch['executable_path'] = executable
    browser = p.chromium.launch(**launch)
    for width in (375, 900):
        prefix = f'Chromium/{width} '
        context = browser.new_context(viewport={'width': width, 'height': 850}, reduced_motion='reduce')
        page = context.new_page()
        page.set_default_timeout(5000)
        errors, requests = [], []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.add_init_script('''window.fixtureTrackRevisions=[];addEventListener('message',event=>{
          if(event.data?.stonefellowSceneTrackState)fixtureTrackRevisions.push(event.data.stonefellowSceneTrackState.revision);
        });''')

        def respond(route):
            requests.append(route.request.url)
            if route.request.url == SITE:
                route.fulfill(content_type='text/html', body='''<!doctype html><html lang="en"><head>
                  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
                  <body class="sf-agent-canvas-active"><main class="stage"><div id="target" class="dynamic-canvas"></div></main></body></html>''')
            else:
                route.fulfill(status=204)

        context.route('**/*', respond)
        page.goto(SITE)
        for name in ('assets/css/site.css', 'assets/css/public-agent-canvas.css'):
            page.add_style_tag(content=(ROOT / name).read_text())
        page.add_script_tag(content=RENDERER)
        page.evaluate(FIXTURE)
        check(page.evaluate('mountResult&&showResult'), prefix + 'production renderer opens the synthetic album canvas')
        iframe = page.locator('.sf-agent-scene-backdrop iframe')
        frame = iframe.element_handle().content_frame()
        controls = frame.locator('.album-playlist button[data-sf-play-track]')
        expect(controls).to_have_count(8)
        expect(frame.locator('.current-row')).to_have_text('Current origin-track')
        frame.wait_for_function('fixtureTrackRevisions.length>0')
        check(controls.evaluate_all('(buttons)=>buttons.map(button=>button.dataset.sfPlayTrack)') ==
              [f'fixture-track-{index}' for index in range(1, 9)], prefix + 'all eight published album tracks keep their ordered bindings')
        check(controls.evaluate_all('(buttons)=>buttons.every(button=>button.tagName==="BUTTON"&&button.type==="button"&&button.tabIndex===0&&button.getAttribute("aria-label"))'),
              prefix + 'authorized action markers become named native keyboard buttons')
        check(frame.locator('.loose-action').evaluate('(node)=>node.tagName==="SPAN"&&!node.hasAttribute("data-sf-play-track")') and
              frame.get_by_text('Foreign release track').count() == 0, prefix + 'unbound action markers and tracks from other releases receive no playback binding')
        check(frame.locator('script').all_text_contents() == [BRIDGE] and BRIDGE_HASH in
              frame.locator('meta[http-equiv="Content-Security-Policy"]').get_attribute('content'),
              prefix + 'the sandbox executes only the fixed bridge with its exact CSP hash')

        for position in (1, 5, 8):
            frame.get_by_role('button', name=f'Play Synthetic track {position}', exact=True).click()
            page.wait_for_function('(expected)=>played.at(-1)===expected', arg=f'fixture-track-{position}')
            expect(frame.locator('.current-row')).to_have_text(f'Current fixture-track-{position}')
            expect(controls).to_have_count(8)
            frame.wait_for_function('fixtureTrackRevisions.length>0')
        check(page.evaluate('played') == ['fixture-track-1', 'fixture-track-5', 'fixture-track-8'],
              prefix + 'clicking first fifth and last rows routes their actual track IDs')
        check(page.evaluate('originalFrame===document.querySelector(".sf-agent-scene-backdrop iframe")&&SFPublicAgentCanvas.sceneActive()'),
              prefix + 'song changes update current-track data without replacing or closing the canvas frame')

        for position, key in ((2, 'Enter'), (7, 'Space')):
            control = frame.get_by_role('button', name=f'Play Synthetic track {position}', exact=True)
            control.focus()
            control.press(key)
            page.wait_for_function('(expected)=>played.at(-1)===expected', arg=f'fixture-track-{position}')
            expect(frame.locator('.current-row')).to_have_text(f'Current fixture-track-{position}')
        check(page.evaluate('played.slice(-2)') == ['fixture-track-2', 'fixture-track-7'],
              prefix + 'Enter and Space activate the same authorized track actions')
        prior_revision = frame.evaluate('fixtureTrackRevisions.at(-1)')
        page.evaluate('changeSong("outside-playing-track")')
        expect(frame.locator('.current-row')).to_have_text('Current outside-playing-track')
        frame.wait_for_function('(prior)=>fixtureTrackRevisions.at(-1)>prior', arg=prior_revision)
        check(controls.count() == 8 and page.evaluate('SFPublicAgentCanvas.sceneActive()'),
              prefix + 'an external song change preserves the complete album playlist')

        current_revision = frame.evaluate('fixtureTrackRevisions.at(-1)')
        before = page.evaluate('played.length')
        frame.evaluate('''({revision,stale})=>{
          parent.postMessage({stonefellowSceneTrackAction:'guessed-track',revision},'*');
          parent.postMessage({stonefellowSceneTrackAction:'foreign-album-track',revision},'*');
          parent.postMessage({stonefellowSceneTrackAction:'fixture-track-1',revision:stale},'*');
        }''', {'revision': current_revision, 'stale': prior_revision})
        expected_messages = page.evaluate('receivedTrackActions.length')
        page.wait_for_function('(count)=>receivedTrackActions.length>=count', arg=max(8, expected_messages))
        check(page.evaluate('played.length') == before, prefix + 'guessed foreign and obsolete-revision messages have no playback side effects')

        page.evaluate('''revision=>{
          window.dispatchEvent(new MessageEvent('message',{origin:'null',source:originalFrame.contentWindow,
            data:{stonefellowSceneTrackAction:'fixture-track-1',revision}}));
          const foreign=document.createElement('iframe');foreign.id='foreign-frame';foreign.sandbox='allow-scripts';foreign.srcdoc='<p>Unrelated frame</p>';document.body.append(foreign);
        }''', current_revision)
        foreign = page.locator('#foreign-frame').element_handle().content_frame()
        foreign.wait_for_load_state()
        message_count = page.evaluate('receivedTrackActions.length')
        foreign.evaluate("revision=>parent.postMessage({stonefellowSceneTrackAction:'fixture-track-1',revision},'*')", current_revision)
        page.wait_for_function('(count)=>receivedTrackActions.length>count', arg=message_count)
        check(page.evaluate('played.length') == before, prefix + 'synthetic events and real messages from another frame are rejected')

        page.evaluate('authorized=false')
        message_count = page.evaluate('receivedTrackActions.length')
        frame.evaluate("revision=>parent.postMessage({stonefellowSceneTrackAction:'fixture-track-1',revision},'*')", current_revision)
        page.wait_for_function('(count)=>receivedTrackActions.length>count', arg=message_count)
        check(page.evaluate('played.length') == before, prefix + 'revoked scene access blocks an otherwise valid track message')
        page.evaluate('authorized=true')
        frame.get_by_role('button', name='Play Synthetic track 8', exact=True).click()
        page.wait_for_function('played.length===6&&played.at(-1)==="fixture-track-8"')
        check(True, prefix + 'the complete playlist remains usable after rejected requests')
        check(not errors, prefix + 'browser reports no renderer exceptions')
        check(not any('/api/' in request for request in requests), prefix + 'the isolated renderer delegates playback without issuing provider or API requests')
        context.close()
    browser.close()
print(f'Canvas track actions: {len(checks)} browser checks passed', flush=True)
