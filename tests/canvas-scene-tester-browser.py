"""Exercise actual candidate JavaScript through the production canvas renderer and sandbox."""
import os
import shutil
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://fixture.test/canvas-test/'
checks = []


def check(value, message):
    assert value, message
    checks.append(message)
    print('PASS', message, flush=True)


SETUP = r'''() => {
  window.authorized=true;window.owner='owner-one';window.reads=[];window.events=[];window.sideEffects=[];
  window.liveAudio=document.createElement('audio');liveAudio.volume=.65;liveAudio.muted=false;
  document.body.append(liveAudio);
  SFPublicAgentCanvas.mount(document.querySelector('#target'),{
    canUseScenes:()=>authorized,getSceneOwner:()=>owner,onSceneCatalogPlay:id=>sideEffects.push(id)
  });
  SFPublicAgentCanvas.showScene({id:1,canvas_kind:'custom',track_id:'',title:'Saved live canvas',
    html:'<section class="saved">Existing canvas stays active</section>',css:'.saved{padding:90px 20px}'},{focus:false});
  window.originalLiveFrame=document.querySelector('.sf-agent-scene-backdrop iframe');
  window.scene=async(js='',changes={})=>{
    const bytes=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(js));
    return {id:2,canvas_kind:'custom',track_id:'',title:'Isolated candidate',html:'<section class="plot"><span class="label">Candidate</span></section>',
      css:'.plot{padding:90px 20px;width:100%;min-height:150px}',js,
      ...(js?{js_hash:btoa(String.fromCharCode(...new Uint8Array(bytes)))}:{}),...changes};
  };
  window.options=()=>({getOwner:()=>owner,canUse:()=>authorized,
    getState:()=>({trackId:'song-one',volume:liveAudio.volume,playing:false}),
    loadCatalog:async(filters,signal)=>{reads.push({kind:'catalog',filters,aborted:signal.aborted});return {tracks:[{
      track_id:'song-one',title:'Authoritative song',duration_seconds:100,release_ids:[],audio_url:'api/canvas-audio.php',source_available:true}],releases:[]};},
    loadAudio:async(id,signal)=>{reads.push({kind:'audio',id,aborted:signal.aborted});return {blob:new Blob(['audio fixture'],{type:'audio/mpeg'}),
      track:{track_id:id,title:'Authoritative song',duration_seconds:100,release_ids:[],source_available:true}};},
    onEvent:event=>events.push(event),
    prepareScene:(value,probe,previewProgram)=>{const candidate=SFPublicAgentCanvas.prepareSceneTest(value,{probe,previewProgram});window.lastCandidate=candidate;return candidate;}
  });
}'''


with sync_playwright() as playwright:
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    browser = playwright.chromium.launch(headless=True, executable_path=executable, args=['--no-sandbox'])
    for width in (390, 1280):
        prefix = f'Chromium/{width} '
        context = browser.new_context(viewport={'width': width, 'height': 850}, reduced_motion='reduce')
        page = context.new_page()
        if os.environ.get('SF_TEST_DEBUG'):
            page.on('console', lambda message: print('BROWSER', message.type, message.text, flush=True))
            page.on('pageerror', lambda error: print('BROWSER ERROR', str(error), flush=True))
        page.set_default_timeout(8000)
        page.route('**/*', lambda route: route.fulfill(content_type='text/html', body='''<!doctype html>
          <html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
          <body><div id="target"></div></body></html>''') if route.request.url == SITE else route.abort())
        page.goto(SITE)
        for name in ('public-agent-canvas.js', 'canvas-audio-sdk.js', 'vendor/acorn.js', 'canvas-preview-guard.js', 'canvas-scene-tester.js'):
            page.add_script_tag(content=(ROOT / 'assets/js' / name).read_text())
        page.evaluate(SETUP)
        if os.environ.get('SF_TEST_DEBUG'):
            page.evaluate("setInterval(()=>console.log(JSON.stringify({events:events.slice(-4),frames:document.querySelectorAll('iframe').length,reads})),1500)")
        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene(`
          document.querySelector('.label').textContent='Executed actual JavaScript';
          StonefellowAudio.catalog().then(catalog=>document.querySelector('.label').textContent=catalog.tracks[0].title);
        `),options())''')
        check(result['state'] == 'passed' and result['observed']['visible'] > 0, prefix + 'actual candidate JavaScript executes in the production sandbox')
        check(result['observed']['guarded'] and result['observed']['verification_complete'] and result['observed']['execution_guard_limit'] == 200000,
              prefix + 'preview evidence explicitly identifies counted instrumentation and its scope')
        check(any(row['id'] == 'audio_sdk' and row['status'] == 'passed' for row in result['checks']) and page.evaluate('reads.length') == 1,
              prefix + 'the production audio SDK reads the supplied authoritative catalog')
        check(page.evaluate('originalLiveFrame.isConnected&&originalLiveFrame===document.querySelector(".sf-agent-scene-backdrop iframe")'),
              prefix + 'testing preserves the current live canvas frame')
        check(page.evaluate('lastCandidate.frame.isConnected===false&&document.querySelector("meta[data-sf-canvas-frame-policy]").content==="frame-src \'none\'"'),
              prefix + 'test cleanup removes the isolated candidate and retains the parent frame policy')
        check(page.evaluate('lastCandidate.frame.getAttribute("sandbox")==="allow-scripts"&&lastCandidate.frame.getAttribute("allow").includes("microphone \'none\'")'),
              prefix + 'candidate permissions retain opaque script isolation and deny capture capabilities')

        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene("throw new ReferenceError('Private exception contents');"),options())''')
        check(result['state'] == 'failed' and any(row['code'] == 'runtime_error' for row in result['errors']),
              prefix + 'an actual initialization exception fails the browser check')
        check('Private exception contents' not in str(result), prefix + 'error evidence excludes authored exception contents')
        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene("missingCanvasSymbol();"),options())''')
        check(any(row.get('symbol') == 'missingCanvasSymbol' and row.get('line', 0) > 0 and row.get('column', 0) > 0 for row in result['errors']),
              prefix + 'runtime evidence retains a bounded missing symbol and numeric source location')
        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene("Promise.reject(new TypeError('Private rejected value'));"),options())''')
        check(result['state'] == 'failed' and any(row['code'] == 'unhandled_rejection' for row in result['errors']),
              prefix + 'an actual unhandled rejection is observed')
        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene('',{css:'.plot{width:2200px;height:300px}'}),options())''')
        if os.environ.get('SF_TEST_DEBUG'):
            print('OVERFLOW', result, flush=True)
        check(result['state'] == 'passed' and any(row['code'] == 'horizontal_scroll' for row in result['warnings']),
              prefix + 'DOM geometry reports horizontal overflow for review without imposing a layout')
        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene('',{
          html:'<section></section>',css:'body{background:radial-gradient(circle,#8948cc,#101021)}'
        }),options())''')
        check(result['state'] == 'passed', prefix + 'a CSS-only background can pass without adding visible widgets or text')
        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene("document.body.innerHTML='<div style=\"width:100px;height:100px;background:red\"></div>';"),options())''')
        check(result['state'] == 'passed' and not result['observed']['main'] and result['observed']['visible'] > 0,
              prefix + 'authored JavaScript can rebuild the document without retaining a main wrapper')

        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene(`
          for(const action of [()=>StonefellowAudio.play(),()=>StonefellowAudio.pause(),()=>StonefellowAudio.seek(50),()=>StonefellowAudio.setVolume(.1)])action().catch(()=>{});
          new Audio().play().catch(()=>{});
          const context=new AudioContext();context.resume().catch(()=>{});
          try{context.createOscillator().connect(context.destination);}catch(_){}
          document.querySelector('.plot').append(Object.assign(document.createElement('button'),{textContent:'Manual control'}));
        `),options())''')
        codes = {row['code'] for row in result['manual']}
        check({'host_play', 'host_pause', 'host_seek', 'host_setVolume', 'local_media_play', 'local_audio_resume', 'local_audio_output', 'interactive_controls'} <= codes,
              prefix + 'host and local audio effects are denied and interactive controls are reported for manual review')
        check(page.evaluate('sideEffects.length===0&&liveAudio.paused&&liveAudio.volume===.65&&!liveAudio.muted'),
              prefix + 'candidate code cannot play or modify the live player')

        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene('',{
          html:'<section class="sf-dataset-published_merch"><div class="sf-data-row"><span class="sf-field-title"></span></div></section>',
          datasets:{published_merch:{fields:{title:'text'},rows:[{title:'Real published shirt'}]}}
        }),options())''')
        check(any(row['id'] == 'dataset_published_merch' and row['detail'] == '1 rendered rows' for row in result['checks']),
              prefix + 'the actual production dataset binder contributes observable row evidence')
        private_preview = page.evaluate(r'''async()=>{
          const value=await scene(`
            const sentinel='Owner'+'_'+'Only';
            if(document.querySelector('main').textContent.includes(sentinel))throw new ReferenceError(sentinel+' is not defined');
          `,{
            html:'<section class="sf-dataset-published_merch"><div class="sf-data-row"><span class="sf-field-title"></span></div></section>'+
              '<section class="sf-dataset-my_purchases"><div class="sf-data-row"><span class="sf-field-item_label"></span></div><p class="sf-data-empty">No purchases here</p></section>'+
              '<section class="sf-dataset-personal_listening_stats sf-current-track"><div class="sf-data-row"><span class="sf-field-title"></span></div></section>',
            canvas_access:{permissions:[{id:'canvas.data.my_purchases',personal:true,granted:true}]},
            dataset_track_id:'song-one',
            datasets:{
              published_merch:{fields:{title:'text'},rows:[{title:'Public fixture'}]},
              my_purchases:{fields:{item_label:'text'},rows:[{item_label:'Owner_Only_Order'}]},
              personal_listening_stats:{personal:true,fields:{track_id:'text',title:'text'},filters:['track_id'],
                rows:[{track_id:'song-one',title:'Owner_Only_Listening'}],
                current_rows:[{track_id:'song-one',title:'Owner_Only_Current'}]}
            }
          });
          const report=await SFCanvasSceneTester.test(value,{...options(),
            prepareScene:(candidate,probe,previewProgram)=>{
              const prepared=SFPublicAgentCanvas.prepareSceneTest(candidate,{probe,previewProgram,currentTrackId:'song-one'});
              window.lastCandidate=prepared;return prepared;
            }
          });
          return {report,srcdoc:lastCandidate.frame.srcdoc,liveRows:value.datasets.personal_listening_stats.current_rows,
            orderRows:value.datasets.my_purchases.rows};
        }''')
        result = private_preview['report']
        check(result['state'] == 'passed' and any(row['code'] == 'personal_data' for row in result['manual']) and
              {row['id'] for row in result['checks'] if row.get('detail') == 'Personal rows withheld'} ==
              {'dataset_my_purchases', 'dataset_personal_listening_stats'},
              prefix + 'testing verifies private binding shape with manual populated review')
        check('Owner_Only' not in private_preview['srcdoc'] and 'Owner_Only' not in str(result) and
              'Public fixture' in private_preview['srcdoc'] and 'No purchases here' in private_preview['srcdoc'],
              prefix + 'private values stay outside the preview document and report while public rows render')
        check(private_preview['liveRows'][0]['title'] == 'Owner_Only_Current' and
              private_preview['orderRows'][0]['item_label'] == 'Owner_Only_Order',
              prefix + 'detached redaction preserves the authorized live scene data')
        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene('',{
          html:'<section class="sf-dataset-published_merch"><div class="sf-data-row"></div></section>',
          datasets:{published_merch:{state:'unavailable',fields:{title:'text'},rows:[]}}
        }),options())''')
        check(result['state'] == 'failed' and any(row['code'] == 'dataset_published_merch' for row in result['errors']),
              prefix + 'an unavailable declared data source is reported as a failed dependency')
        result = page.evaluate(r'''async()=>SFCanvasSceneTester.test(await scene("fetch('https://example.invalid/private').catch(()=>{});"),options())''')
        check(result['state'] == 'failed' and any(row['code'] == 'policy_connect-src' for row in result['errors']),
              prefix + 'the unchanged production CSP blocks authored network access and supplies failure evidence')
        result = page.evaluate(r'''async()=>{
          const id='abcdef0123456789abcdef0123456789';
          const png=Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='),value=>value.charCodeAt(0));
          return SFCanvasSceneTester.test(await scene('',{html:'<section class="sf-image-'+id+'"></section>',image_assets:[{id,title:'Authorized image'}]}),
            {...options(),onSceneImage:async()=>new Blob([png],{type:'image/png'})});
        }''')
        check(any(row['id'] == 'images' and row['status'] == 'passed' for row in result['checks']),
              prefix + 'authorized image blobs load through the actual production image bridge')
        result = page.evaluate(r'''async()=>{
          const id='abcdef0123456789abcdef0123456789';
          return SFCanvasSceneTester.test(await scene('',{html:'<section class="sf-image-'+id+'"></section>',image_assets:[{id,title:'Authorized image'}]}),
            {...options(),onSceneImage:async()=>{throw new Error('Private image source error');}});
        }''')
        check(result['state'] == 'failed' and any(row['code'] == 'image_unavailable' for row in result['errors']),
              prefix + 'a missing required image fails without forwarding private load errors')

        result = page.evaluate(r'''async()=>{
          const control=new AbortController();let requestSignal;
          const promise=SFCanvasSceneTester.test(await scene('StonefellowAudio.catalog().catch(()=>{});'),{
            ...options(),signal:control.signal,loadCatalog:(_,signal)=>{requestSignal=signal;return new Promise(()=>{});}
          });
          while(!requestSignal)await new Promise(resolve=>setTimeout(resolve,0));
          control.abort();const report=await promise;return {report,aborted:requestSignal.aborted,removed:!lastCandidate.frame.isConnected};
        }''')
        check(result['report']['state'] == 'canceled' and result['aborted'] and result['removed'],
              prefix + 'Stop aborts a pending read and removes the candidate without a build deadline')
        result = page.evaluate(r'''async()=>{
          const control=new AbortController();
          const promise=SFCanvasSceneTester.test(await scene('StonefellowAudio.catalog().catch(()=>{});'),{
            ...options(),signal:control.signal,loadCatalog:()=>new Promise(()=>{})
          });
          while(!lastCandidate.frame.isConnected)await new Promise(resolve=>setTimeout(resolve,0));
          const fake={channel:'stonefellow-canvas-test',version:1,nonce:'0'.repeat(32),documentId:'0'.repeat(32),type:'snapshot',sequence:1,
            observed:{main:true,elements:1,visible:1,width:390,scrollWidth:390}};
          dispatchEvent(new MessageEvent('message',{source:lastCandidate.frame.contentWindow,origin:'null',data:fake}));
          dispatchEvent(new MessageEvent('message',{source:window,origin:location.origin,data:fake}));
          await new Promise(resolve=>requestAnimationFrame(resolve));
          control.abort();return (await promise).state;
        }''')
        check(result == 'canceled', prefix + 'forged nonce and parent-window reports cannot complete the candidate check')
        result = page.evaluate(r'''async()=>{
          const original=await scene('while(true){}');const before=JSON.stringify(original);
          let timerRan=false;const timer=new Promise(resolve=>setTimeout(()=>{timerRan=true;resolve();},40));
          const report=await SFCanvasSceneTester.test(original,options());await timer;
          return {report,timerRan,unchanged:before===JSON.stringify(original),removed:!lastCandidate.frame.isConnected};
        }''')
        check(result['report']['state'] == 'incomplete' and result['report']['observed']['execution_budget_exhausted'] and
              not result['report']['observed']['verification_complete'] and result['timerRan'] and result['removed'],
              prefix + 'an actual synchronous infinite loop returns incomplete and leaves parent timers responsive')
        check(result['unchanged'] and page.evaluate('originalLiveFrame.isConnected'),
              prefix + 'instrumentation preserves the original saved candidate and current live canvas')
        result = page.evaluate(r'''async()=>{
          const control=new AbortController();let stopped=false;
          const report=await SFCanvasSceneTester.test(await scene('for(;;);'),{
            ...options(),signal:control.signal,onEvent:event=>{
              if(event.type==='manual'&&event.code==='sf_preview_budget'){control.abort();stopped=true;}
            }
          });return {state:report.state,stopped,removed:!lastCandidate.frame.isConnected};
        }''')
        check(result['state'] == 'canceled' and result['stopped'] and result['removed'],
              prefix + 'Stop remains responsive when a tight loop reaches the preview allowance')
        result = page.evaluate(r'''async()=>{
          const original=await scene('document.body.textContent="Unexpected execution";');original.js_hash='A'.repeat(43)+'=';
          return SFCanvasSceneTester.test(original,options());
        }''')
        check(result['state'] == 'failed' and any(row['code'] == 'invalid_candidate' for row in result['errors']),
              prefix + 'original source hash verification occurs before any transformed candidate runs')
        result = page.evaluate(r'''async()=>{
          const value=await scene('with(new Proxy(Object.create(Boolean),{has:Boolean,get:Object.getPrototypeOf})){while(true){}}');
          const original=JSON.stringify(value);let prepared=false;
          const report=await SFCanvasSceneTester.test(value,{...options(),prepareScene:()=>{prepared=true;throw new Error('Unexpected mount');}});
          await new Promise(resolve=>setTimeout(resolve,0));
          return {report,prepared,unchanged:original===JSON.stringify(value),live:originalLiveFrame.isConnected};
        }''')
        check(result['report']['state'] == 'incomplete' and not result['report']['observed']['verification_complete'] and
              result['report']['observed']['instrumentation_unsupported'] and not result['prepared'] and result['unchanged'] and result['live'] and
              any(row['code'] == 'sf_preview_unsupported' for row in result['report']['manual']) and not result['report']['errors'],
              prefix + 'dynamic scope guard bypass is rejected before execution with incomplete manual evidence')
        context.close()
    browser.close()
print(f'Canvas scene tester: {len(checks)} browser checks passed', flush=True)
