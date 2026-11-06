"""Exercise production orb chrome and scene palette on desktop and touch.

Uses synthetic media state and a browser clock; no account, microphone,
provider or live application server is involved.
"""
import json
import os
import shutil
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


with sync_playwright() as playwright:
    launch = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        launch['executable_path'] = executable
    browser = playwright.chromium.launch(**launch)
    for width in (375, 1440):
        touch = width == 375
        context = browser.new_context(viewport={'width': width, 'height': 900},
                                      reduced_motion='reduce', has_touch=touch)
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.clock.install()
        page.set_content('''<!doctype html><html lang="en"><head>
          <meta name="viewport" content="width=device-width,initial-scale=1"></head>
          <body class="sf-agent-canvas-active"><main class="stage"><div id="target" class="dynamic-canvas"></div></main>
          <footer class="chat-footer"><div id="player" class="footer-player" hidden>
            <button id="playerToggle" type="button">Play</button>
            <input id="playerSeek" type="range" aria-label="Seek song"></div>
            <form id="chatForm"><input id="composer" aria-label="Chat with Stonefellow"></form>
            <div class="social-links"><a href="#social">Social link</a></div>
          </footer><audio id="audio"></audio></body></html>''')
        page.add_style_tag(content=(ROOT / 'assets/css/public-agent-canvas.css').read_text())
        page.add_style_tag(content='''body{margin:0;background:#111;color:#fff;font-family:system-ui}
          .chat-footer{position:fixed;bottom:0;left:20px;right:20px;z-index:12}
          #chatForm input{box-sizing:border-box;width:100%;height:44px}
          #player[hidden]{display:none!important}''')
        page.evaluate('''() => {
          window.fixture={capturing:false,lyric:null,playing:false,voiceStops:0,voiceStarts:0,conversationActive:false,voiceNeedsGesture:false};
          window.StonefellowSongExperience={snapshot:()=>fixture.lyric,active:()=>false};
          window.audio=document.querySelector('#audio');
          Object.defineProperty(audio,'paused',{configurable:true,get:()=>!fixture.playing});
          window.mountOptions={audio,player:document.querySelector('#player'),canUseScenes:()=>true,
            getSceneOwner:()=>1,getTrackId:()=>fixture.playing?'song-1':'',
            isCapturing:()=>fixture.capturing,isConversationActive:()=>fixture.conversationActive,
            needsVoiceGesture:()=>fixture.voiceNeedsGesture,
            onStopSpeaking:()=>fixture.voiceStops++,
            onStartChat:()=>{fixture.voiceStarts++;fixture.conversationActive=true;fixture.voiceNeedsGesture=false;SFPublicAgentCanvas.syncVoice()}};
          window.setHidden=hidden=>{
            document.body.classList.toggle('sf-agent-manually-hidden',hidden);
            SFPublicAgentCanvas.setAgentHidden(hidden);
          };
        }''')
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-agent-theme.js').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/public-agent-canvas.js').read_text())
        page.evaluate("SFPublicAgentCanvas.mount(document.querySelector('#target'),mountOptions)")
        prefix = f'chromium/{width}: '
        orb = page.locator('.sf-agent-sphere')
        nav = page.locator('.sf-agent-orbit-tools')
        live = page.locator('.sf-agent-live-feed')
        voice = page.locator('.sf-agent-chat-start')
        status = page.locator('.sf-agent-state')
        composer = page.locator('#composer')

        def expire():
            page.clock.fast_forward(5600)
            # Browser clock advances timers, while CSS fade completion follows
            # the document's animation timeline.
            page.wait_for_timeout(250)

        def reveal():
            if touch:
                orb.tap()
            else:
                orb.click()
            nav.wait_for(state='visible')

        def double_activate():
            if touch:
                box=orb.bounding_box()
                x,y=box['x']+box['width']/2,box['y']+box['height']/2
                page.touchscreen.tap(x,y)
                page.clock.fast_forward(100)
                page.touchscreen.tap(x,y)
            else:
                orb.dblclick(delay=100)

        def reset_voice():
            page.evaluate("fixture.conversationActive=false;SFPublicAgentCanvas.syncVoice();SFPublicAgentCanvas.setState('idle')")
            page.clock.fast_forward(1000)

        def collapse():
            composer.focus()
            page.mouse.move(0, 0)
            expire()

        check(orb.is_visible() and nav.is_visible() and live.is_visible() and voice.is_visible(),
              prefix + 'initial orb controls are available before any music is selected')
        expire()
        check(orb.is_visible() and not nav.is_visible() and not live.is_visible()
              and not voice.is_visible() and not status.is_visible(),
              prefix + 'idle hides the ring and attached chat controls with no song loaded: ' +
              repr(page.evaluate('''() => ({classes:document.querySelector('.sf-agent-floater').className,
                mode:document.querySelector('.sf-agent-floater').dataset.mode,
                nav:getComputedStyle(document.querySelector('.sf-agent-orbit-tools')).visibility,
                active:document.activeElement?.outerHTML.slice(0,180)})''')))
        check(composer.is_visible() and composer.is_enabled(),
              prefix + 'idle leaves the main chat composer usable')
        check(nav.evaluate('node=>node.inert') and live.evaluate('node=>node.inert'),
              prefix + 'collapsed controls leave keyboard navigation and pointer targeting')
        orb.hover()
        page.wait_for_timeout(70)
        check(not nav.is_visible() and page.evaluate('fixture.voiceStarts===0'),
              prefix + 'hover does not reveal controls or start listening')
        page.mouse.move(0, 0)
        reveal()
        check(nav.is_visible() and live.is_visible() and voice.is_visible()
              and not page.locator('.sf-agent-workspace').is_visible() and page.evaluate('fixture.voiceStarts===0'),
              prefix + 'single activation reveals controls without starting listening or opening workspace')
        page.clock.fast_forward(1000)
        reveal()
        check(page.evaluate('fixture.voiceStarts===0') and not page.locator('.sf-agent-workspace').is_visible(),
              prefix + 'a separate single activation keeps the existing controls visible')
        collapse()
        check(not nav.is_visible(), prefix + 'controls can return to idle before a double activation')
        double_activate()
        check(page.evaluate('fixture.voiceStarts===1&&fixture.conversationActive') and nav.is_visible()
              and not page.locator('.sf-agent-workspace').is_visible(),
              prefix + 'double activation reveals controls and starts the existing listening callback once')
        page.evaluate("SFPublicAgentCanvas.setState('listening')")
        collapse()
        check(not nav.is_visible() and page.evaluate('fixture.conversationActive&&fixture.voiceStarts===1&&fixture.voiceStops===0'),
              prefix + 'idle fade preserves active conversation and voice without restarting or stopping it')
        reveal()
        check(page.evaluate('fixture.conversationActive&&fixture.voiceStarts===1'),
              prefix + 'single activation restores controls while the existing conversation continues')
        page.clock.fast_forward(1000)
        double_activate()
        check(page.evaluate('fixture.conversationActive&&fixture.voiceStarts===1'),
              prefix + 'double activation is idempotent while listening is already active')
        reset_voice()
        double_activate()
        check(page.evaluate('fixture.voiceStarts===2&&fixture.conversationActive') and not page.locator('.sf-agent-workspace').is_visible(),
              prefix + 'double activation also starts listening with controls already visible')
        reset_voice()
        if touch:
            for _ in range(3):
                orb.tap()
                page.clock.fast_forward(80)
        else:
            orb.click(click_count=3, delay=80)
        check(page.evaluate('fixture.voiceStarts===3&&fixture.conversationActive'),
              prefix + 'triple activation starts listening once and never toggles it off')
        reset_voice()
        box=orb.bounding_box()
        x,y=box['x']+box['width']/2,box['y']+box['height']/2
        if touch:
            touch_session=context.new_cdp_session(page)
            touch_session.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':x,'y':y}]})
            touch_session.send('Input.dispatchTouchEvent',{'type':'touchMove','touchPoints':[{'x':x+40,'y':y+30}]})
            touch_session.send('Input.dispatchTouchEvent',{'type':'touchEnd','touchPoints':[]})
        else:
            page.mouse.move(x,y)
            page.mouse.down()
            page.mouse.move(x+40,y+30,steps=6)
            page.mouse.up()
        page.clock.fast_forward(100)
        reveal()
        check(page.evaluate('fixture.voiceStarts===3&&!fixture.conversationActive'),
              prefix + 'drag followed by a single activation does not become a double gesture')
        page.clock.fast_forward(1000)
        if touch:
            box=orb.bounding_box()
            x,y=box['x']+box['width']/2,box['y']+box['height']/2
            touch_session.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':x,'y':y}]})
            touch_session.send('Input.dispatchTouchEvent',{'type':'touchCancel','touchPoints':[]})
        else:
            orb.dispatch_event('pointercancel',{'pointerType':'mouse','pointerId':1})
        page.clock.fast_forward(100)
        reveal()
        check(page.evaluate('fixture.voiceStarts===3&&!fixture.conversationActive'),
              prefix + 'pointer cancellation does not count toward the next listening gesture')
        nav.locator('[data-orbit-action="library"]').focus()
        page.mouse.move(0, 0)
        expire()
        check(nav.is_visible() and nav.locator('[data-orbit-action="library"]').evaluate('node=>node===document.activeElement'),
              prefix + 'keyboard focus keeps the navigation available')
        composer.focus()
        expire()
        check(not nav.is_visible() and composer.evaluate('node=>node===document.activeElement'),
              prefix + 'typing in global chat does not prevent idle orb collapse')
        orb.focus()
        nav.wait_for(state='visible')
        check(nav.is_visible() and not nav.evaluate('node=>node.inert'),
              prefix + 'keyboard focus on orb reveals reachable navigation')
        orb.press('Enter')
        page.clock.fast_forward(1000)
        orb.press('Space')
        check(nav.is_visible() and page.evaluate('fixture.voiceStarts===3') and not page.locator('.sf-agent-workspace').is_visible(),
              prefix + 'keyboard Enter and Space reveal controls without accidental listening or workspace')
        voice.click()
        check(page.evaluate('fixture.voiceStarts===4&&fixture.conversationActive'),
              prefix + 'visible voice chat control remains the keyboard-accessible listening action')
        reset_voice()
        composer.focus()
        page.mouse.move(0, 0)
        page.evaluate('''() => {
          document.querySelector('#player').hidden=false;
          fixture.playing=true;audio.dispatchEvent(new Event('play'));
          SFPublicAgentCanvas.syncPlayer();
        }''')
        check(page.locator('#player').is_visible() and nav.is_visible(),
              prefix + 'track playback restores the same controls and existing player')
        expire()
        check(not nav.is_visible() and not page.locator('#player').is_visible() and orb.is_visible(),
              prefix + 'music player and attached controls collapse together')
        reveal()
        page.locator('#playerSeek').focus()
        page.mouse.move(0, 0)
        expire()
        check(page.locator('#player').is_visible() and nav.is_visible(),
              prefix + 'seeking keeps the shared controls visible')
        composer.focus()
        page.evaluate("fixture.capturing=true;SFPublicAgentCanvas.setState('recording')")
        expire()
        check(nav.is_visible() and nav.locator('[data-orbit-action="stop"]').is_visible(),
              prefix + 'active capture keeps its stop action available')
        page.evaluate("fixture.capturing=false;fixture.lyric={listening_mode:'catalog',phase:'capturing'};SFPublicAgentCanvas.setState('listening')")
        expire()
        check(page.locator('[data-agent-action="stop-lyrics"]').is_visible(),
              prefix + 'active lyric selection keeps its stop action available')
        page.evaluate("fixture.lyric=null;SFPublicAgentCanvas.setState('idle');SFPublicAgentCanvas.startGuide()")
        expire()
        check(page.locator('.sf-agent-guide-layer').is_visible() and nav.is_visible(),
              prefix + 'guided tour keeps real navigation visible')
        page.keyboard.press('Escape')
        composer.focus()
        page.evaluate("setHidden(true)")
        check(not orb.is_visible() and composer.is_visible() and page.locator('#player').is_visible(),
              prefix + 'explicit hide-agent keeps the global composer and footer player')
        page.evaluate("setHidden(false)")
        check(orb.is_visible(), prefix + 'show-agent restores the existing orb')
        composer.focus()
        page.evaluate("SFPublicAgentCanvas.setState('speaking')")
        expire()
        if touch:
            orb.tap()
        else:
            orb.click()
        check(page.evaluate('fixture.voiceStops===0&&fixture.voiceStarts===4'),
              prefix + 'single activation while speaking reveals controls without interrupting voice')
        page.clock.fast_forward(1000)
        double_activate()
        check(page.evaluate('fixture.voiceStops===1&&fixture.voiceStarts===5&&fixture.conversationActive'),
              prefix + 'double activation while speaking enables listening once through the existing callback')
        page.evaluate("SFPublicAgentCanvas.setState('idle')")
        page.evaluate('''() => {
          window.paletteScene={id:1,canvas_kind:'custom',track_id:'',title:'Palette fixture',
            html:'<section class="art"></section>',css:'.art{background:#101010;color:#eeeeee}',
            agent_palette:{accent:'#88ccaa',secondary:'#dcb380',surface:'#15110e'}};
          fixture.paletteShown=SFPublicAgentCanvas.showScene(paletteScene,{focus:false});
        }''')
        check(page.evaluate('fixture.paletteShown') and page.locator('.sf-agent-landing').first.evaluate(
            "node=>node.style.getPropertyValue('--sf-atmo-accent')==='#88ccaa'&&node.style.getPropertyValue('--sf-atmo-rgb')==='136,204,170'"),
              prefix + 'scene palette tints the existing orb and controls')
        check(page.locator('.sf-agent-ring').evaluate("node=>getComputedStyle(node).borderTopColor==='rgba(136, 204, 170, 0.31)'"),
              prefix + 'saved palette reaches actual orb ring CSS')
        page.evaluate('''() => {
          const {agent_palette,...scene}=paletteScene;
          const id='ref-'+'b'.repeat(32);
          scene.css='.art{background:var(--sf-image-'+id+')}';
          scene.image_assets=[{id,colors:['#568cbc','#101b27']}];
          SFPublicAgentCanvas.showScene(scene,{focus:false});
        }''')
        check(page.locator('.sf-agent-landing').first.evaluate("node=>node.style.getPropertyValue('--sf-atmo-accent')==='#568cbc'"),
              prefix + 'approved image inventory colors tint existing canvases without generation')
        page.evaluate('''() => {
          const {agent_palette,...scene}=paletteScene;
          scene.css='.art{background:#112233;color:#aaccff}';
          SFPublicAgentCanvas.showScene(scene,{focus:false});
        }''')
        check(page.locator('.sf-agent-landing').first.evaluate("node=>node.style.getPropertyValue('--sf-atmo-accent')==='#aaccff'"),
              prefix + 'code-only canvas colors provide an automatic fallback')
        page.evaluate('SFPublicAgentCanvas.closeScene(false)')
        check(page.locator('.sf-agent-landing').first.evaluate("node=>!node.style.getPropertyValue('--sf-atmo-accent')&&!node.hasAttribute('data-agent-palette')"),
              prefix + 'closing a canvas restores the saved base atmosphere')
        page.evaluate('fixture.voiceBeforeResume=fixture.voiceStarts;fixture.conversationActive=true;fixture.voiceNeedsGesture=true;SFPublicAgentCanvas.syncVoice()')
        page.clock.fast_forward(1000)
        double_activate()
        check(page.evaluate('fixture.voiceStarts===fixture.voiceBeforeResume+1&&fixture.conversationActive&&!fixture.voiceNeedsGesture'),
              prefix + 'double activation resumes a conversation that explicitly needs a new voice gesture')
        page.evaluate('SFPublicAgentCanvas.unmount()')
        expire()
        check(not page.locator('.sf-agent-landing').count() and page.locator('#player').evaluate('node=>node.parentElement.classList.contains("chat-footer")'),
              prefix + 'unmount clears timers and restores the authoritative player')
        check(not errors, prefix + 'production code has no browser errors: ' + repr(errors))
        context.close()
    browser.close()

print(json.dumps({'ok': True, 'checks': len(checks)}))
