"""Production workflow sidebar: evidence, candidate source and accessible tabs."""
import json
import os
from pathlib import Path
import shutil
import sys

sys.dont_write_bytecode = True
from playwright.sync_api import expect, sync_playwright

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
        context = browser.new_context(viewport={'width': width, 'height': 900}, reduced_motion='reduce', has_touch=width == 375)
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.set_content('''<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
          <body class="sf-agent-canvas-active"><main class="stage"><div id="target" class="dynamic-canvas"></div></main>
          <footer class="chat-footer"><form><input aria-label="Prompt"></form><div class="social-links"><a href="#social">Social link</a></div></footer>
          <audio id="audio"></audio></body></html>''')
        page.add_style_tag(content=(ROOT / 'assets/css/public-agent-canvas.css').read_text())
        page.add_style_tag(content='''body{margin:0;background:#10121a;color:white;font-family:system-ui}
          .chat-footer{position:fixed;bottom:0;left:12px;right:12px;z-index:12;background:#10121a}
          .chat-footer input{box-sizing:border-box;height:44px;width:100%}''')
        page.add_script_tag(content=(ROOT / 'assets/js/public-agent-canvas.js').read_text())
        page.evaluate('''() => {
          window.owner='one';window.calls={stops:0};window.stage=SFPublicAgentCanvas;
          stage.mount(document.querySelector('#target'),{audio:document.querySelector('audio'),getSceneOwner:()=>owner,
            canUseScenes:()=>true,onSceneStop:async()=>{calls.stops++;await new Promise(resolve=>window.releaseStop=resolve)}});
          window.requestA='a'.repeat(32);window.requestB='b'.repeat(32);
        }''')
        rail = page.locator('#sfAgentCodingRail')
        tabs = page.get_by_role('tablist', name='Canvas project workflow', exact=True)
        prefix = f'chromium/{width}: '
        check(not rail.is_visible(), prefix + 'the restored page starts with the entire code rail closed')
        page.evaluate('''stage.setSceneWorkflow({request_id:'s'.repeat(32),phase:'coding',silent:true,project:{brief:'Restored approved brief'},steps:[]});
          stage.setSceneCandidateCode({html:'<main>Restored checked source</main>',css:'main{color:linen}'});
          stage.setSceneWorkflow({request_id:'s'.repeat(32),phase:'applied',silent:true});''')
        check(not rail.is_visible() and page.get_by_role('button', name='Code', exact=True).is_visible(),
              prefix + 'silent startup workflow and source restoration keep the full code rail closed')
        page.get_by_role('button', name='Code', exact=True).click()
        check(rail.is_visible() and rail.locator('.sf-agent-coding-html').text_content() == '<main>Restored checked source</main>' and
              not rail.locator('.sf-agent-coding-stop').is_visible(),
              prefix + 'the footer Code button reopens restored workflow data without starting an agent')
        page.evaluate('stage.setSceneWorkflow(null);stage.setSceneCoding("idle")')
        page.evaluate('''stage.setSceneWorkflow({request_id:requestA,phase:'coding',project:{brief:'Approved <b>paper shapes</b>',
          coding_model:{provider:'openai',model:'fixture-model'},resources:{canvases:2,music:7,images:1,merch:3,tickets:4}},
          steps:[{id:'write-1',role:'coding',state:'running',summary:'Writing the approved source',active_tokens_used:11}],total_active_tokens_used:11})''')
        expect(rail).to_be_visible()
        expect(tabs.get_by_role('tab')).to_have_count(4)
        stop = rail.locator('.sf-agent-coding-stop')
        expect(stop).to_be_visible()
        tabs.get_by_role('tab', name='Project', exact=True).click()
        project = rail.get_by_role('tabpanel', name='Project', exact=True)
        check(project.is_visible() and project.get_by_text('Approved <b>paper shapes</b>', exact=True).is_visible() and project.locator('b').count() == 0 and
              'openai · fixture-model' in project.inner_text() and '11' in project.inner_text(),
              prefix + 'Project shows the approved brief, selected model, resource counts and actual tokens as plain text')
        tabs.get_by_role('tab', name='Project', exact=True).press('ArrowRight')
        expect(tabs.get_by_role('tab', name='Coding', exact=True)).to_be_focused()
        expect(tabs.get_by_role('tab', name='Coding', exact=True)).to_have_attribute('aria-selected', 'true')
        check(page.evaluate('''()=>[...document.querySelectorAll('[data-workflow-tab]')].every(tab=>{
          const panel=document.getElementById(tab.getAttribute('aria-controls'));
          return panel?.getAttribute('aria-labelledby')===tab.id&&tab.tabIndex===(tab.getAttribute('aria-selected')==='true'?0:-1);
        })'''), prefix + 'tabs use roving keyboard focus and matching accessible panels')
        page.evaluate('stage.setSceneCoding("streaming");stage.appendSceneCode("raw", "<script>window.mustNotRun=true</script>")')
        expect(rail.locator('.sf-agent-coding-raw')).to_have_text('<script>window.mustNotRun=true</script>')
        tabs.get_by_role('tab', name='Testing', exact=True).click()
        testing = rail.get_by_role('tabpanel', name='Testing', exact=True)
        check(testing.get_by_text('No testing evidence has been reported.', exact=True).is_visible() and 'Passed' not in testing.inner_text(),
              prefix + 'Testing displays absence of evidence without inventing passing results')
        page.evaluate('''stage.setSceneWorkflow({request_id:requestA,phase:'testing',steps:[
          {id:'test-1',role:'testing',state:'failed',summary:'Candidate checks completed',active_tokens_used:0,
           checks:[{name:'Structure',passed:true,detail:'One root found'},{name:'Playback',passed:false,detail:'Missing selected track'}],
           findings:[{severity:'error',description:'<img src=x> is literal evidence'}]}],total_active_tokens_used:11});stage.setSceneCoding('ready')''')
        check(testing.is_visible() and tabs.get_by_role('tab', name='Testing', exact=True).get_attribute('aria-selected') == 'true' and
              'Structure · One root found · Passed' in testing.inner_text() and 'Playback · Missing selected track · Failed' in testing.inner_text() and
              '<img src=x> is literal evidence' in testing.inner_text() and testing.locator('img').count() == 0 and stop.is_visible(),
              prefix + 'actual check evidence and findings preserve the selected tab and keep Stop active despite legacy ready state')
        page.evaluate('''stage.setSceneCandidateCode({html:'<main>Unapplied candidate</main>',css:'main{color:gold}',js:'window.mustNotRun=true'});''')
        check(page.locator('.sf-agent-scene-backdrop iframe').count() == 0 and page.evaluate('window.mustNotRun===undefined') and
              rail.locator('.sf-agent-coding-html').text_content() == '<main>Unapplied candidate</main>',
              prefix + 'candidate code is displayed as source without mounting or running a canvas')
        page.get_by_role('button', name='Code', exact=True).click()
        expect(rail).not_to_be_visible()
        check(page.evaluate('calls.stops===0'), prefix + 'hiding the code rail never stops ongoing testing')
        page.get_by_role('button', name='Code', exact=True).click()
        expect(testing).to_be_visible()
        page.evaluate('stage.setAgentHidden(true)')
        check(rail.is_visible() and testing.is_visible() and stop.is_visible(), prefix + 'hiding the orb leaves the workflow and overall Stop available')
        tabs.get_by_role('tab', name='Testing', exact=True).press('End')
        repair = rail.get_by_role('tabpanel', name='Repair', exact=True)
        expect(tabs.get_by_role('tab', name='Repair', exact=True)).to_be_focused()
        page.evaluate('''stage.setSceneWorkflow({request_id:requestA,phase:'repairing',steps:[
          {id:'repair-1',role:'repair',state:'running',summary:'Fixing the selected track binding',findings:['Playback binding is missing'],
           fixes:['Added the selected track ID'],checks:[{label:'Playback retest',status:'pending'}],active_tokens_used:17,
           usage:{provider:'openai',model:'fixture-reviewer',input_tokens:23,output_tokens:7}}],total_active_tokens_used:28})''')
        check(repair.is_visible() and 'Added the selected track ID' in repair.inner_text() and 'Playback retest · pending' in repair.inner_text() and
              'Model tokens: 23 input · 7 output' in repair.inner_text() and 'openai · fixture-reviewer' in repair.inner_text() and
              tabs.get_by_role('tab', name='Repair', exact=True).get_attribute('data-phase-active') == 'true' and stop.is_visible(),
              prefix + 'Repair shows supplied findings, fixes, retests and token usage with a phase indicator')
        stop.click()
        page.wait_for_function('typeof releaseStop==="function"')
        check(page.evaluate('calls.stops===1') and stop.get_attribute('aria-disabled') == 'true',
              prefix + 'one overall Stop delegates once and remains disabled while cancellation is pending')
        page.evaluate('releaseStop();stage.setSceneWorkflow({request_id:requestA,phase:"canceled"});stage.setSceneCoding("idle")')
        expect(stop).not_to_be_visible()
        check(repair.is_visible() and 'Added the selected track ID' in repair.inner_text(), prefix + 'terminal cancellation preserves actual workflow logs and the chosen tab')
        page.evaluate('''stage.setSceneWorkflow({request_id:requestB,phase:'coding',project:{brief:'The next approved request'},steps:[]});
          window.staleWorkflowAccepted=stage.setSceneWorkflow({request_id:requestA,phase:'repairing',project:{brief:'Stale private brief'}});''')
        check(not page.evaluate('staleWorkflowAccepted') and 'Stale private brief' not in rail.inner_text() and repair.is_visible(),
              prefix + 'a late phase update from an earlier request cannot overwrite the current project or tab')
        page.evaluate('''stage.setSceneWorkflow({request_id:requestB,phase:'testing',steps:Array.from({length:60},(_,index)=>({
          id:'check-'+index,role:'testing',state:'complete',summary:'Actual check '+index,checks:[{label:'Evidence '+index,detail:'Result details '.repeat(9),passed:true}]}))})''')
        tabs.get_by_role('tab', name='Testing', exact=True).click()
        geometry = rail.evaluate('''node=>({fits:node.scrollWidth<=node.clientWidth,
          scrollers:[node,...node.querySelectorAll('*')].filter(el=>['auto','scroll'].includes(getComputedStyle(el).overflowY)&&el.scrollHeight>el.clientHeight).length,
          bodyOverflow:getComputedStyle(node.querySelector('.sf-agent-coding-body')).overflowY,
          buttons:[...node.querySelectorAll('[role=tab]')].every(el=>el.getBoundingClientRect().height>=44)})''')
        check(geometry['fits'] and geometry['buttons'] and geometry['scrollers'] == (1 if width > 820 else 0) and
              geometry['bodyOverflow'] == ('auto' if width > 820 else 'visible'),
              prefix + 'long evidence uses one desktop content scroll and the mobile page scroll with usable tabs')
        page.screenshot(path=f'/tmp/sf292-workflow-{width}.png')
        page.evaluate('stage.setSceneWorkflow({request_id:requestB,phase:"needs_attention"})')
        check(not stop.is_visible() and 'Actual check 59' in rail.inner_text(), prefix + 'needs-attention keeps evidence without pretending the canvas was applied')
        page.evaluate('owner="two";stage.syncSceneAccess()')
        check(not rail.is_visible() and rail.locator('.sf-agent-workflow-brief').text_content() == 'No approved project has been supplied.' and
              rail.locator('.sf-agent-coding-html').text_content() == '', prefix + 'account changes clear private workflow metadata and candidate source')
        page.evaluate('stage.setSceneWorkflow({request_id:"c".repeat(32),phase:"coding",project:{brief:"A fresh project"}})')
        page.evaluate('stage.setSceneWorkflow(null);stage.setSceneCoding("idle")')
        check(not rail.is_visible() and not errors, prefix + 'an explicit reset clears the rail without browser errors: ' + repr(errors))
        context.close()
    browser.close()

print(json.dumps({'ok': True, 'checks': len(checks)}))
