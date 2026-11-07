"""Production sandbox coverage for exact lyric views, native timing and fresh authorizations."""
import base64
import hashlib
import os
import shutil
from pathlib import Path

from playwright.sync_api import expect, sync_playwright


ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://fixture.test/lyrics-dataset/'
LYRIC = "First verse\n\n" + '夜' * 4000 + '</script><img src="https://example.invalid/leak">'
PROGRAM = '''(()=>{
  const lyrics=JSON.parse(document.getElementById('sfCanvasLyrics').textContent);
  const status=document.querySelector('.delivery');
  status.textContent=JSON.stringify({rows:lyrics.rows.length,current:lyrics.current_rows.length,limited:lyrics.payload_truncated});
  const caption=document.querySelector('.caption');
  StonefellowAudio.subscribe(state=>{
    const ms=state.currentTime*1000;
    const cue=[...lyrics.current_rows,...lyrics.rows].find(row=>
      row.kind==='cue'&&row.track_id===state.trackId&&row.start_ms<=ms&&ms<row.end_ms);
    caption.textContent=cue?.text??'';
  });
})();'''
PROGRAM_HASH = base64.b64encode(hashlib.sha256(PROGRAM.encode()).digest()).decode()
FIXTURE = '''({lyric,js,hash})=>{
  const body={track_id:'fixed',kind:'text',position:1,text:lyric,start_ms:null,end_ms:null};
  const cue=(track_id,position,text)=>({track_id,kind:'cue',position,text,start_ms:(position-1)*1000,end_ms:position*1000});
  const rows=Array.from({length:135},(_,index)=>cue('fixed',index+1,'Cue '+(index+1)));
  const dataset=(rows,current_rows,payload_truncated=false)=>({
    fields:{track_id:'text',kind:'text',position:'number',text:'long_text',start_ms:'number',end_ms:'number'},
    filters:['track_id','kind','position'],requires_track_filter:true,rows,current_rows,payload_truncated
  });
  const story={fields:{title:'text',body_text:'long_text'},rows:[{title:'Published story',body_text:'Only a story.'}]};
  const dataRow='<p class="sf-data-row"><span class="sf-field-text"></span></p>';
  const notice='<p class="sf-data-truncated">Showing <span class="sf-field-rendered_count"></span> of '+
    '<span class="sf-field-matching_count"></span><span class="sf-field-delivery_status"></span></p>';
  const html='<article><section class="whole sf-dataset-published_lyrics sf-filter-track_id--fixed sf-filter-kind--text">'+dataRow+'</section>'+
    '<section class="cues sf-dataset-published_lyrics sf-filter-track_id--fixed sf-filter-kind--cue">'+dataRow+notice+'</section>'+
    '<section class="unscoped sf-dataset-published_lyrics">'+dataRow+'</section>'+
    '<div class="sf-dataset-published_lyrics sf-current-track sf-filter-kind--cue"></div>'+
    '<div class="sf-dataset-published_stories"><p class="sf-data-row"><span class="sf-field-title"></span></p></div>'+
    '<p class="caption"></p><p class="delivery"></p></article>';
  let track='fixed',currentTime=134.5;
  window.playCalls=0;window.reads=[];
  window.changeClock=seconds=>{currentTime=seconds;SFPublicAgentCanvas.syncPlayer();};
  window.changeTrack=id=>{track=id;currentTime=0.5;SFPublicAgentCanvas.syncPlayer();};
  const mounted=SFPublicAgentCanvas.mount(document.querySelector('#target'),{
    audio:document.querySelector('audio'),canUseScenes:()=>true,getSceneOwner:()=> 'fixture-owner',getTrackId:()=>track,
    onCanvasAudioState:()=>({trackId:track,currentTime,duration:180,playing:true}),onCanvasAudioPlay:()=>{playCalls++;},
    onSceneTrackData:async(id,trackId)=>{
      reads.push(trackId);
      if(trackId==='disabled')return {id};
      return {id,dataset_track_id:trackId,datasets:{published_lyrics:dataset(
        [{...body,text:'Corrected whole text'}],[cue(trackId,1,'Current song cue')],true)}};
    }
  });
  const shown=SFPublicAgentCanvas.showScene({id:1,canvas_kind:'custom',track_id:'',title:'Lyric check',
    agent_design:'hidden',css:'article{padding:16px} .whole{white-space:pre-wrap}',html,js,js_hash:hash,
    dataset_track_id:track,datasets:{published_stories:story,published_lyrics:dataset(
      [body,...rows,{...body,track_id:'other',text:'OUTSIDE_SCOPE'}],rows)}
  },{focus:false});
  window.originalFrame=document.querySelector('.sf-agent-scene-backdrop iframe');
  return !!mounted&&shown;
}'''


with sync_playwright() as playwright:
    options = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        options['executable_path'] = executable
    browser = playwright.chromium.launch(**options)
    for width in (375, 900):
        page = browser.new_page(viewport={'width': width, 'height': 850}, reduced_motion='reduce')
        page.set_default_timeout(6000)
        requests, errors = [], []
        page.on('request', lambda request: requests.append(request.url))
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.route(SITE, lambda route: route.fulfill(
            content_type='text/html',
            body='<!doctype html><html><body><audio></audio><div id="target"></div></body></html>',
        ))
        page.goto(SITE)
        page.add_style_tag(content=(ROOT / 'assets/css/public-agent-canvas.css').read_text())
        for script in ('canvas-audio-sdk.js', 'public-agent-canvas.js'):
            page.add_script_tag(content=(ROOT / 'assets/js' / script).read_text())
        assert page.evaluate(FIXTURE, {'lyric': LYRIC, 'js': PROGRAM, 'hash': PROGRAM_HASH})
        frame = page.locator('.sf-agent-scene-backdrop iframe').element_handle().content_frame()
        expect(frame.locator('.caption')).to_have_text('Cue 135')
        expect(frame.locator('.cues .sf-data-row')).to_have_count(120)
        expect(frame.locator('.cues .sf-data-truncated')).to_have_text('Showing 120 of 135')
        expect(frame.locator('.delivery')).to_have_text('{"rows":136,"current":135,"limited":false}')
        expect(frame.locator('.unscoped .sf-data-row')).to_have_count(0)
        expect(frame.locator('img')).to_have_count(0)
        expect(frame.get_by_text('OUTSIDE_SCOPE', exact=True)).to_have_count(0)
        expect(frame.get_by_text('Published story', exact=True)).to_have_count(1)
        assert frame.locator('.whole .sf-field-text').text_content() == LYRIC
        assert frame.evaluate('JSON.parse(document.querySelector("#sfCanvasLyrics").textContent).rows[0].text') == LYRIC
        print(f'PASS Chromium/{width} full authorized JS data, safe text, independent Story view, DOM mount limit, and exact lyric scope', flush=True)

        page.evaluate('changeClock(0.25)')
        expect(frame.locator('.caption')).to_have_text('Cue 1')
        page.evaluate('changeClock(136)')
        expect(frame.locator('.caption')).to_be_empty()
        page.evaluate('changeTrack("second")')
        expect(frame.locator('.caption')).to_have_text('Current song cue')
        expect(frame.locator('.whole .sf-field-text')).to_have_text('Corrected whole text')
        expect(frame.locator('.delivery')).to_have_text('{"rows":1,"current":1,"limited":true}')
        expect(frame.locator('.whole')).to_have_attribute('aria-description',
            'Lyrics reached the delivery limit. Filter by song, text or cue position to narrow the data.')
        assert page.evaluate('originalFrame===document.querySelector(".sf-agent-scene-backdrop iframe")&&playCalls===0')
        print(f'PASS Chromium/{width} playback clock and track changes select authored cues and refresh static text without starting playback', flush=True)

        page.evaluate('changeTrack("disabled")')
        expect(frame.locator('.whole .sf-field-text')).to_have_count(0)
        expect(frame.locator('.delivery')).to_have_text('{"rows":0,"current":0,"limited":false}')
        assert page.evaluate('reads') == ['second', 'disabled']
        assert requests == [SITE] and not errors, (requests, errors)
        print(f'PASS Chromium/{width} fresh policy denial clears old text and never executes lyric content', flush=True)
        page.close()
    browser.close()
