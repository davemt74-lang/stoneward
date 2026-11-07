"""Native sandbox coverage for live story fields, bounded views, and track controls."""
import os
import shutil
from pathlib import Path

from playwright.sync_api import expect, sync_playwright


ROOT = Path(__file__).resolve().parents[1]
RENDERER = (ROOT / 'assets/js/public-agent-canvas.js').read_text()
SITE = 'https://fixture.test/story-dataset/'
BODY = "The first paragraph.\n\n" + "🥁" * 4150 + '<img src="https://example.invalid/leak">'


with sync_playwright() as playwright:
    options = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        options['executable_path'] = executable
    browser = playwright.chromium.launch(**options)
    page = browser.new_page(viewport={'width': 900, 'height': 850}, reduced_motion='reduce')
    page.set_default_timeout(5000)
    requests = []
    page.on('request', lambda request: requests.append(request.url))
    page.route(SITE, lambda route: route.fulfill(
        content_type='text/html',
        body='<!doctype html><html><body><div id="target"></div></body></html>',
    ))
    page.goto(SITE)
    page.add_style_tag(content=(ROOT / 'assets/css/public-agent-canvas.css').read_text())
    page.add_script_tag(content=RENDERER)
    mounted = page.evaluate('''body=>{
      window.played=[];
      const rows=Array.from({length:125},(_,index)=>({
        story_id:String(index+1),title:'Story '+(index+1),summary:index===0?'🥁'.repeat(710):'',
        body_text:index===0?body:'',track_id:index===0?'public-song':''
      }));
      rows[124].body_text='Safe literal: <img src="https://example.invalid/leak">';
      const mounted=SFPublicAgentCanvas.mount(document.querySelector('#target'),{
        canUseScenes:()=>true,getSceneOwner:()=> 'story-test',
        onSceneCatalogPlay:id=>played.push(id)
      });
      const shown=SFPublicAgentCanvas.showScene({id:1,canvas_kind:'custom',track_id:'',title:'Story data check',
        agent_design:'hidden',css:'.stories{padding:130px 24px 0}',
        html:'<article class="stories"><section class="full sf-dataset-published_stories">'+
          '<div class="sf-data-row"><span class="sf-field-title"></span><p class="sf-field-summary"></p><p class="sf-field-body_text"></p>'+
          '<span class="sf-action-play-track"><span class="sf-field-title"></span></span></div>'+
          '<p class="sf-data-truncated">Showing <span class="sf-field-rendered_count"></span> of '+
          '<span class="sf-field-matching_count"></span> matching stories.</p></section>'+
          '<section class="late sf-dataset-published_stories sf-filter-story_id--125">'+
          '<div class="sf-data-row"><span class="sf-field-title"></span><p class="sf-field-body_text"></p></div>'+
          '<p class="sf-data-truncated">Unexpected clipping</p></section></article>',
        datasets:{published_stories:{fields:{story_id:'text',title:'text',summary:'long_text',
          body_text:'long_text',track_id:'text'},filters:['story_id'],actions:{play_track:{field:'track_id'}},rows}}
      },{focus:false});
      return mounted&&shown;
    }''', BODY)
    assert mounted
    frame = page.locator('.sf-agent-scene-backdrop iframe').element_handle().content_frame()
    frame.wait_for_load_state('load')
    expect(frame.locator('.full .sf-data-row')).to_have_count(120)
    expect(frame.locator('.full .sf-data-truncated')).to_have_text('Showing 120 of 125 matching stories.')
    expect(frame.locator('.full')).to_have_attribute('aria-description', 'Showing 120 of 125 matching stories.')
    expect(frame.locator('.late .sf-data-row')).to_have_count(1)
    expect(frame.locator('.late .sf-field-title')).to_have_text('Story 125')
    expect(frame.locator('.late .sf-data-truncated')).to_have_count(0)
    assert frame.locator('.full .sf-field-summary').first.text_content() == "🥁" * 640
    assert frame.locator('.full .sf-field-body_text').first.text_content() == BODY[:4000]
    expect(frame.locator('.late .sf-field-body_text')).to_have_text('Safe literal: <img src="https://example.invalid/leak">')
    expect(frame.locator('img')).to_have_count(0)
    assert requests == [SITE]
    print('PASS Story display reports truncation, reaches late IDs and binds safe Unicode text', flush=True)
    controls = frame.get_by_role('button', name='Play Story 1', exact=True)
    expect(frame.locator('button[data-sf-play-track]')).to_have_count(1)
    controls.focus()
    expect(controls).to_be_focused()
    controls.press('Enter')
    page.wait_for_function('played.length===1&&played[0]==="public-song"')
    print('PASS Native story track control routes its linked public identity', flush=True)
    browser.close()
