"""Production orb travel, adaptive presence and viewport bounds in Chromium.

Uses the real CSS, markup, animation-frame loop and trusted pointer events.
Media/conversation state is synthetic; no microphone, provider or server.
"""
import json
import math
import os
from pathlib import Path
import re
import shutil

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SHELL = (ROOT / 'stonefellow-v120.php').read_text()
PLAYER = re.search(r'<div id="footerPlayer"[\s\S]*?</audio>\s*</div>', SHELL).group(0)
checks = []
widths = tuple(int(value) for value in os.environ.get('SF_ORB_MOTION_WIDTHS', '375,1440').split(','))


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
    for width in widths:
        for reduced in (False, True):
            touch = width == 375
            context = browser.new_context(viewport={'width': width, 'height': 900}, has_touch=touch,
                                          reduced_motion='reduce' if reduced else 'no-preference')
            page = context.new_page()
            errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.clock.install()
            page.set_content('''<!doctype html><html lang="en"><head>
              <meta name="viewport" content="width=device-width,initial-scale=1"></head>
              <body class="sf-agent-canvas-active"><header class="topbar"><button id="menuButton">Menu</button></header>
              <main class="stage"><div id="target" class="dynamic-canvas"></div></main>
              <footer class="chat-footer">''' + PLAYER + '''
                <div class="chat-composer-shell"><form id="chatForm" class="chatbar">
                  <input id="composer" aria-label="Chat with Stonefellow"></form></div>
                <div class="social-links"><a href="#social">Social link</a></div>
              </footer></body></html>''')
            for file in ('assets/css/site.css', 'assets/css/public-agent-canvas.css'):
                page.add_style_tag(content=(ROOT / file).read_text())
            page.add_style_tag(content='''body{margin:0;overflow:hidden}.topbar{position:fixed;inset:0 0 auto;height:74px;box-sizing:border-box;z-index:30}
              .chat-footer{position:fixed;bottom:0;left:20px;right:20px;z-index:20}
              #chatForm{display:block}#composer{box-sizing:border-box;width:100%;height:44px}
              .social-links{min-height:24px}.footer-player[hidden]{display:none!important}''')
            page.evaluate('''() => {
              window.fixture={active:false,starts:0,stops:0,playing:false};
              window.audio=document.querySelector('#persistentAudio');
              Object.defineProperty(audio,'paused',{configurable:true,get:()=>!fixture.playing});
              window.StonefellowSongExperience={snapshot:()=>null,active:()=>false};
              const footer=document.querySelector('.chat-footer');
              const measure=()=>document.documentElement.style.setProperty('--sf-agent-dock-bottom',Math.ceil(footer.getBoundingClientRect().height)+18+'px');
              new ResizeObserver(measure).observe(footer);measure();
              window.mountOptions={audio,player:document.querySelector('#footerPlayer'),canUseScenes:()=>true,
                getSceneOwner:()=>1,isConversationActive:()=>fixture.active,
                onStartChat:()=>{fixture.starts++;fixture.active=true;SFPublicAgentCanvas.syncVoice()},
                onStopSpeaking:()=>fixture.stops++};
            }''')
            for file in ('assets/js/canvas-agent-theme.js', 'assets/js/public-agent-canvas.js'):
                page.add_script_tag(content=(ROOT / file).read_text())
            page.evaluate("SFPublicAgentCanvas.mount(document.querySelector('#target'),mountOptions)")
            page.evaluate("document.querySelector('#footerPlayer').hidden=false;SFPublicAgentCanvas.syncPlayer()")
            prefix = f'chromium/{width}/' + ('reduced: ' if reduced else 'motion: ')
            orb = page.locator('.sf-agent-sphere')
            nav = page.locator('.sf-agent-orbit-tools')
            composer = page.locator('#composer')
            check(orb.evaluate('node=>{const group=node.closest(".sf-agent-floater");return group&&[".sf-agent-presence",".sf-agent-orbit-tools",".sf-agent-live-feed",".sf-agent-dock-stack"].every(selector=>group.contains(document.querySelector(selector)))}'),
                  prefix + 'orb, navigation and attached controls share one scaling group')

            def advance(milliseconds, settle=False, fast=False):
                if fast or milliseconds >= 5000:
                    page.clock.fast_forward(milliseconds)
                else:
                    page.clock.run_for(milliseconds)
                if settle:
                    page.wait_for_timeout(30 if reduced else 650)
                    page.clock.run_for(40)

            def point():
                box = orb.bounding_box()
                return {'x': box['x'] + box['width'] / 2, 'y': box['y'] + box['height'] / 2,
                        'width': box['width'], 'height': box['height']}

            def distance(a, b):
                return math.hypot(a['x'] - b['x'], a['y'] - b['y'])

            def size_ratio():
                return orb.evaluate('node=>node.getBoundingClientRect().width/document.querySelector(".sf-agent-visual").offsetWidth')

            def blur():
                composer.focus()
                page.mouse.move(0, 0)

            def activate():
                box = point()
                if touch:
                    page.touchscreen.tap(box['x'], box['y'])
                else:
                    page.mouse.click(box['x'], box['y'])

            def safe_bounds(label):
                data = page.evaluate('''() => {
                  const root=document.querySelector('#target>.sf-agent-landing');
                  const header=document.querySelector('.topbar').getBoundingClientRect();
                  const footer=document.querySelector('.chat-footer').getBoundingClientRect();
                  const selectors=['.sf-agent-sphere','.sf-agent-orbit-tools button:not([hidden])',
                    '.sf-agent-live-feed','.sf-agent-chat-start','.sf-agent-state','#footerPlayer:not([hidden])'];
                  const rects=selectors.flatMap(selector=>Array.from(root.querySelectorAll(selector))).filter(node=>{
                    const css=getComputedStyle(node);return !node.hidden&&node.getClientRects().length&&css.visibility!=='hidden'&&css.display!=='none';
                  }).map(node=>{const r=node.getBoundingClientRect();return {label:node.className||node.id,left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height}});
                  return {header:header.bottom,footer:footer.top,width:innerWidth,rects};
                }''')
                good = all(rect['left'] >= -1 and rect['right'] <= data['width'] + 1
                           and rect['top'] >= data['header'] - 1 and rect['bottom'] <= data['footer'] + 1
                           for rect in data['rects'])
                check(good, prefix + label + (': ' + json.dumps(data) if not good else ''))

            def controls_scale(label, scale=1):
                values = nav.locator('button:not([hidden])').evaluate_all('nodes=>nodes.map(node=>{const r=node.getBoundingClientRect();return {width:r.width,height:r.height}})')
                attached=page.locator('.sf-agent-live-feed,.sf-agent-dock-stack,#footerPlayer').evaluate_all('nodes=>nodes.map(node=>{const r=node.getBoundingClientRect();return {width:r.width/node.offsetWidth,height:r.height/node.offsetHeight}})')
                check(values and all(abs(value['width'] - 44*scale) < .3 and abs(value['height'] - 44*scale) < .3 for value in values)
                      and attached and all(abs(value['width']-scale)<.02 and abs(value['height']-scale)<.02 for value in attached),
                      prefix + label + ' scales navigation, chat and player together to ' + str(scale))

            advance(5600, settle=True)
            check(abs(size_ratio() - .5) < .02, prefix + 'idle sphere uses half the base presence size')
            controls_scale('idle', .5)
            initial = point()
            advance(2600)
            moved = point()
            check(distance(initial, moved) < .5 if reduced else distance(initial, moved) > 2,
                  prefix + 'basic canvas honors travel preference: ' + str(distance(initial, moved)))
            safe_bounds('basic canvas stays clear of header and footer after travel')
            page.evaluate("SFPublicAgentCanvas.setState('thinking')")
            advance(80, settle=True)
            check(abs(size_ratio() - .75) < .02, prefix + 'thinking sphere uses three-quarter presence size')
            controls_scale('thinking', .75)
            page.evaluate("SFPublicAgentCanvas.setState('recording')")
            advance(80, settle=True)
            check(abs(size_ratio() - 1) < .03, prefix + 'engaged recording sphere uses full presence size')
            controls_scale('engaged')
            page.evaluate("SFPublicAgentCanvas.setState('listening')")
            advance(80, settle=True)
            samples = []
            for delay in (113, 173, 257, 311, 431):
                page.wait_for_timeout(delay)
                samples.append(point()['width'])
            span = max(samples) - min(samples)
            check(span < .2 if reduced else span > .3,
                  prefix + 'listening pulse honors motion preference: ' + str(samples))
            check(.93 <= size_ratio() <= 1.1, prefix + 'listening pulse stays near full presence size')
            page.evaluate("SFPublicAgentCanvas.setState('idle')")
            blur()
            advance(5600, settle=True)
            before_reveal = point()
            activate()
            advance(80, settle=True)
            check(nav.is_visible() and abs(size_ratio() - 1) < .03,
                  prefix + 'trusted single activation reveals controls and engages the full orb')
            check(distance(before_reveal, point()) < 4, prefix + 'revealing controls keeps the tap target in place')
            controls_scale('revealed')
            safe_bounds('revealed full orb and controls remain inside the viewport')
            if not touch:
                composer.focus()
                advance(80, settle=True)
            before = point()
            advance(2400, fast=True)
            check(distance(before, point()) < .5, prefix + 'pointer engagement pauses travel')
            nav.locator('[data-orbit-action="record"]').focus()
            page.mouse.move(0, 0)
            advance(80, settle=True)
            before = point()
            advance(2400, fast=True)
            check(distance(before, point()) < .5, prefix + 'focused navigation pauses travel')
            blur()
            advance(80)
            start = point()
            advance(2600)
            check(distance(start, point()) < .5 if reduced else distance(start, point()) > 2,
                  prefix + 'travel resumes after pointer and keyboard focus leave')
            start=point()
            if touch:
                drag_session=context.new_cdp_session(page)
                drag_session.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':start['x'],'y':start['y']}]})
                drag_session.send('Input.dispatchTouchEvent',{'type':'touchMove','touchPoints':[{'x':start['x']+35,'y':start['y']+25}]})
            else:
                page.mouse.move(start['x'],start['y'])
                page.mouse.down()
                page.mouse.move(start['x']+35,start['y']+25,steps=4)
            composer.focus()
            advance(80,settle=True)
            start=point()
            advance(2400, fast=True)
            after=point()
            check(distance(start,after)<.5,prefix+'holding a trusted drag pauses automatic travel: '+str({'before':start,'after':after}))
            if touch:
                drag_session.send('Input.dispatchTouchEvent',{'type':'touchEnd','touchPoints':[]})
            else:
                page.mouse.up()
            blur()
            advance(3200)
            page.evaluate("document.querySelector('#footerPlayer').hidden=false;fixture.playing=true;SFPublicAgentCanvas.syncPlayer();SFPublicAgentCanvas.setState('music')")
            advance(80,settle=True)
            safe_bounds('loaded player and voice controls fit the available shell')
            page.evaluate("SFPublicAgentCanvas.showScene({id:8,canvas_kind:'custom',title:'Authored artwork',html:'<section class=\"art\"></section>',css:'.art{background:#123456;min-height:100vh}'},{focus:false})")
            blur()
            advance(80, settle=True)
            start = point()
            advance(3000)
            check(distance(start, point()) < .5 if reduced else distance(start, point()) > 2,
                  prefix + 'authored custom canvas keeps the same roaming behavior')
            safe_bounds('custom canvas travel remains within shell bounds')
            for name, open_action, close_action in (
                ('guided tour', 'SFPublicAgentCanvas.startGuide()', "document.querySelector('[data-guide-close]').click()"),
                ('native page', "SFPublicAgentCanvas.openPage('music')", 'SFPublicAgentCanvas.closePage(true)'),
                ('coding sidebar', "SFPublicAgentCanvas.setSceneCoding('generating')", "SFPublicAgentCanvas.setSceneCoding('idle')"),
                ('image preview', "document.querySelector('.sf-agent-coding-image-dialog').showModal()", "document.querySelector('.sf-agent-coding-image-dialog').close()"),
            ):
                page.evaluate(open_action)
                advance(80, settle=True)
                start = point()
                advance(8400)
                check(distance(start, point()) < .5, prefix + name + ' pauses travel')
                page.evaluate(close_action)
                blur()
                advance(80)
                resumed = point()
                delta=distance(start,resumed)
                check(delta < 4, prefix + name + ' resumes without a jump: ' + str(delta))
            activate()
            advance(80, settle=True)
            orb.focus()
            page.set_viewport_size({'width': 320 if touch else 900, 'height': 780})
            advance(160, settle=True)
            controls_scale('resized')
            safe_bounds('resize clamps the full orb and revealed controls while travel is paused')
            check(page.evaluate('fixture.starts===0&&fixture.stops===0&&!fixture.active'),
                  prefix + 'travel and presentation state changes never start or stop conversation')
            page.evaluate('SFPublicAgentCanvas.unmount()')
            advance(120)
            check(not page.locator('.sf-agent-floater').count() and not errors,
                  prefix + 'teardown cancels animation work without browser errors: ' + repr(errors))
            context.close()
    browser.close()

print(json.dumps({'ok': True, 'checks': len(checks), 'widths': widths}))
