"""Canvas image clicks belong to authored code; editing uses deliberate host controls."""
import base64
import hashlib
import os
from pathlib import Path
import shutil

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://fixture.test/image-interactions/'
IMAGE = 'a' * 32
PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='
PROGRAM = '''document.addEventListener('click',event=>{
  if(event.target.matches('.sf-scene-image')){
    document.body.dataset.imageClicks=String(Number(document.body.dataset.imageClicks||0)+1);
    document.body.dataset.trusted=String(event.isTrusted);
  }
});'''
HASH = base64.b64encode(hashlib.sha256(PROGRAM.encode()).digest()).decode()
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


with sync_playwright() as p:
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    launch = {'headless': True, 'args': ['--no-sandbox']}
    if executable:
        launch['executable_path'] = executable
    browser = p.chromium.launch(**launch)
    for width in (375, 1440):
        prefix = f'Chromium/{width} '
        context = browser.new_context(viewport={'width': width, 'height': 850}, reduced_motion='reduce')
        page = context.new_page()
        page.set_default_timeout(5000)
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))

        def respond(route):
            if route.request.url == SITE:
                route.fulfill(content_type='text/html', body='''<!doctype html><html lang="en"><head>
                  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
                  <body class="sf-agent-canvas-active"><main class="stage"><div id="target" class="dynamic-canvas"></div></main>
                  <footer class="chat-footer"><div class="social-links"></div></footer></body></html>''')
            else:
                route.fulfill(status=204)

        context.route('**/*', respond)
        page.goto(SITE)
        for name in ('assets/css/site.css', 'assets/css/public-agent-canvas.css'):
            page.add_style_tag(content=(ROOT / name).read_text())
        for name in ('assets/js/canvas-audio-sdk.js', 'assets/js/public-agent-canvas.js'):
            page.add_script_tag(content=(ROOT / name).read_text())
        page.evaluate('''({id,png})=>{
          window.edits=[];window.replacements=[];
          window.imageScene={id:71,canvas_kind:'custom',track_id:'',title:'Interactive image',agent_design:'hidden',
            html:'<article><figure class="sf-image-'+id+'"></figure></article>',
            css:'article{padding:100px 20px}.sf-scene-image{width:100px;height:100px;image-rendering:pixelated}',
            image_assets:[{id,title:'Selected artwork'}]};
          window.mountResult=SFPublicAgentCanvas.mount(document.querySelector('#target'),{
            canUseScenes:()=>true,getSceneOwner:()=> 'fixture-owner',audio:document.createElement('audio'),
            onSceneImage:async()=>new Blob([Uint8Array.from(atob(png),c=>c.charCodeAt(0))],{type:'image/png'}),
            onEditSceneImage:asset=>{edits.push(asset);return false;},
            onReplaceSceneImage:asset=>{replacements.push(asset);return false;}
          });
          window.showResult=SFPublicAgentCanvas.showScene(imageScene,{focus:false});
        }''', {'id': IMAGE, 'png': PNG})
        check(page.evaluate('mountResult&&showResult'), prefix + 'production renderer opens the image canvas')
        iframe = page.locator('.sf-agent-scene-backdrop iframe')
        frame = iframe.element_handle().content_frame()
        image = frame.locator('.sf-scene-image')
        expect(image).to_have_count(1)
        frame.wait_for_function('document.querySelector("img").complete&&document.querySelector("img").naturalWidth>0')
        check(image.evaluate('(node)=>node.alt==="Selected artwork"&&!node.hasAttribute("role")&&!node.hasAttribute("tabindex")&&!node.title'),
              prefix + 'loaded image has descriptive alt text and no automatic edit role or tab stop')
        check(iframe.evaluate('(node)=>!node.classList.contains("sf-agent-scene-interactive")&&getComputedStyle(node).pointerEvents==="none"'),
              prefix + 'decorative image alone does not capture page interactions')

        page.evaluate('''({program,hash})=>{
          SFPublicAgentCanvas.showScene({...imageScene,id:72,js:program,js_hash:hash},{focus:false});
        }''', {'program': PROGRAM, 'hash': HASH})
        iframe = page.locator('.sf-agent-scene-backdrop iframe')
        frame = iframe.element_handle().content_frame()
        image = frame.locator('.sf-scene-image')
        expect(image).to_have_count(1)
        frame.wait_for_function('document.querySelector("img").complete&&document.querySelector("img").naturalWidth>0')
        check(iframe.evaluate('(node)=>node.classList.contains("sf-agent-scene-interactive")&&getComputedStyle(node).pointerEvents==="auto"'),
              prefix + 'authored JavaScript keeps its canvas interactive')
        image.click()
        expect(frame.locator('body')).to_have_attribute('data-image-clicks', '1')
        expect(frame.locator('body')).to_have_attribute('data-trusted', 'true')
        check(page.evaluate('edits.length===0&&replacements.length===0'),
              prefix + 'a real image click reaches authored code without opening host editing')
        image.click(click_count=3)
        expect(frame.locator('body')).to_have_attribute('data-image-clicks', '4')
        check(page.evaluate('edits.length===0&&replacements.length===0'),
              prefix + 'repeated image clicks remain authored interactions')
        frame.evaluate('''id=>{
          parent.postMessage({stonefellowSceneImageEdit:id},'*');
          parent.postMessage({stonefellowSceneImageReplace:id},'*');
        }''', IMAGE)
        page.wait_for_timeout(75)
        check(page.evaluate('edits.length===0&&replacements.length===0'),
              prefix + 'the obsolete iframe edit message channel has no host action')

        picker = page.locator('.sf-agent-scene-image-picker')
        picker.locator('summary').click()
        picker.get_by_role('button', name='Edit Selected artwork', exact=True).click()
        check(page.evaluate('edits.length===1&&edits[0].id==="'+IMAGE+'"&&edits[0].sceneId===72&&replacements.length===0'),
              prefix + 'footer Edit images explicitly edits the selected current image')
        picker.locator('summary').click()
        picker.get_by_role('button', name='Replace Selected artwork', exact=True).click()
        check(page.evaluate('replacements.length===1&&replacements[0].id==="'+IMAGE+'"&&replacements[0].sceneId===72&&edits.length===1'),
              prefix + 'footer replacement remains a separate deliberate action')
        check(not errors, prefix + 'renderer and isolated scripts report no browser exceptions')
        context.close()
    browser.close()
print(f'Canvas image interactions: {len(checks)} browser checks passed', flush=True)
