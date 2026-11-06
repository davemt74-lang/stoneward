"""Native project review UI and the production build gate with fake providers.

The account API is deterministic here; canvas-projects-http.py separately
checks real PHP authentication, CSRF, persistence and ownership.
"""
import json
import os
from pathlib import Path
import re
import shutil
import sys

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
APP = (ROOT / 'assets/js/app.js').read_text()
SHELL = (ROOT / 'stonefellow-v120.php').read_text()
MENU = re.search(r'<section id="menuCanvasProjects"[\s\S]*?</section>', SHELL).group(0)
start = APP.index('  async function requestListeningRoom(')
GATE = APP[start:APP.index('  function validCanvasImagePosition(', start)]
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


SETUP = r'''() => {
 window.owner='1';window.calls={get:[],post:[],build:[],code:[],images:[],present:[],execute:[],reply:[]};
 window.voice={active:true,starts:0,stops:0};
 const clone=value=>JSON.parse(JSON.stringify(value));
 const normalize=raw=>({action:'create',context_track_id:'',image_reference_ids:[],review_images:false,source_scene_ids:[],...clone(raw)});
 const stable=value=>JSON.stringify(value,(key,item)=>item&&typeof item==='object'&&!Array.isArray(item)?Object.fromEntries(Object.keys(item).sort().map(name=>[name,item[name]])):item);
 window.profiles=new Map();window.nextProposal=0;window.holdGet=false;window.holdApprove=false;window.holdSuggest=false;window.holdDiscuss=false;window.processingReplies=0;window.discussMode='reply';
 window.profile=id=>{if(!profiles.has(id))profiles.set(id,{preferences:{ideas_enabled:true,auto_approve:false},proposals:[],feedback:[]});return profiles.get(id)};
 window.getProjects=async signal=>{
  const captured=owner;calls.get.push(captured);
  const result=clone(profile(captured));
  if(holdGet){holdGet=false;await new Promise(resolve=>window.releaseGet=resolve)}
  return result;
 };
 window.postProjects=async(payload,signal)=>{
  const captured=owner,account=profile(captured);calls.post.push({owner:captured,...clone(payload)});
  if(payload.action==='propose'&&processingReplies>0){processingReplies--;return {state:'processing'}}
  if(payload.action==='preferences'){
   for(const key of ['ideas_enabled','auto_approve'])if(key in payload)account.preferences[key]=payload[key];
   return {preferences:clone(account.preferences)};
  }
  if(payload.action==='approve'&&holdApprove){holdApprove=false;await new Promise(resolve=>window.releaseApprove=resolve)}
  if(payload.action==='suggest'&&holdSuggest){holdSuggest=false;await new Promise(resolve=>window.releaseSuggest=resolve)}
  if(payload.action==='discuss'&&holdDiscuss){holdDiscuss=false;await new Promise(resolve=>window.releaseDiscuss=resolve)}
  if(payload.action==='feedback'){
   const matches=entry=>payload.proposal_id?entry.proposal_id===payload.proposal_id:entry.scene_id===payload.scene_id;
   account.feedback=account.feedback.filter(entry=>!matches(entry));
   const feedback=payload.preference==='clear'?null:{scene_id:payload.scene_id||null,proposal_id:payload.proposal_id||null,preference:payload.preference,reason:payload.reason||''};
   if(feedback)account.feedback.push(feedback);return {feedback:clone(feedback)};
  }
  if(payload.action==='discuss'){
   const reply='Owner '+captured+' discussion: '+payload.brief;
   if(discussMode==='reply')return {reply};
   const project=normalize({canvas_kind:'custom',track_id:'',brief:payload.brief,source_scene_ids:payload.source_scene_ids||[],
    ...(payload.artist_inputs?{artist_inputs:payload.artist_inputs}:{}),...(payload.music_inputs?{music_inputs:payload.music_inputs}:{})});
   const proposal={id:++nextProposal,status:account.preferences.auto_approve?'approved':'awaiting_approval',approval_mode:account.preferences.auto_approve?'automatic':null,
    title:'Discussed owner '+captured+' concept',concept:'A discussed concept for '+payload.brief,project,source_scene_ids:project.source_scene_ids,source:'discussion'};
   account.proposals.push(proposal);return {reply,proposal:clone(proposal)};
  }
  if(payload.action==='approve'||payload.action==='cancel'){
   const record=account.proposals.find(item=>item.id===payload.id);if(!record)throw Error('Not found');
   if(record.status==='canceled')throw Error('Project canceled');
   record.status=payload.action==='approve'?'approved':'canceled';
   if(payload.action==='approve')record.approval_mode='manual';
   return {preferences:clone(account.preferences),proposal:clone(record)};
  }
  if(payload.action==='propose'||payload.action==='suggest'){
   if(payload.action==='suggest'&&!account.preferences.ideas_enabled)throw Error('Ideas disabled');
   const project=normalize(payload.action==='suggest'?{canvas_kind:'custom',track_id:'',brief:'Owner '+captured+' history concept',source_scene_ids:[Number(captured)*10+1]}:payload.project);
   if(payload.reuse_id){const same=account.proposals.find(item=>item.id===payload.reuse_id&&stable(item.project)===stable(project)&&['approved','awaiting_approval'].includes(item.status));if(same)return {preferences:clone(account.preferences),proposal:clone(same)}}
   if(payload.replace_id){const old=account.proposals.find(item=>item.id===payload.replace_id);if(old)old.status='canceled'}
   const proposal={id:++nextProposal,status:account.preferences.auto_approve?'approved':'awaiting_approval',approval_mode:account.preferences.auto_approve?'automatic':null,
    title:'Owner '+captured+' project',concept:'A model-authored concept for owner '+captured,project,source_scene_ids:project.source_scene_ids,source:payload.action==='suggest'?'agent':'user'};
   account.proposals.push(proposal);return {preferences:clone(account.preferences),proposal:clone(proposal),active_tokens_used:7};
  }
  throw Error('Unexpected project action');
 };
 window.stage={setSceneImage(){},setSceneCoding:state=>calls.code.push(state)};
 window.canvasCodingModel=value=>value||null;window.recordCanvasDebug=()=>{};
 window.sendListeningRoomRequest=async build=>{
  calls.build.push(clone(build.payload));calls.code.push('generating');
  if(build.payload.image_count)calls.images.push(build.payload.image_count);
  return {state:'ready',scene:{id:1000+calls.build.length}};
 };
 window.createController=()=>SFCanvasProjects.create({getOwner:()=>owner,getHost:()=>document.querySelector('#chat'),get:getProjects,post:postProjects,
  onPresent:text=>calls.present.push(text),onReply:text=>calls.reply.push(text),onSuggest:()=>{void controller.suggest().catch(error=>window.lastError=error.name)},
  onExecute:proposal=>{calls.execute.push(clone(proposal));startBuild({...proposal.project,proposal_id:proposal.id})}});
 window.controller=createController();window.canvasProjects=()=>controller;
 window.startBuild=details=>{
  const captured=owner,request=new AbortController();window.buildRequest=request;
  const active=()=>captured===owner&&!request.signal.aborted;
  window.pendingBuild=requestListeningRoom(details,request,stage,active,()=>{}).then(result=>{
   if(request.sceneBuild?.payload.proposal_id)controller.complete(request.sceneBuild.payload.proposal_id);
   window.lastResult=result;return result;
  }).catch(error=>{window.lastError=error.name;return null});
  return window.pendingBuild;
 };
 window.switchOwner=value=>{owner=value;controller.setOwner()};
 controller.setOwner();
}'''

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
        page.set_content('''<!doctype html><html><body><details id="menuPanel" open><summary id="menuButton">Menu</summary>
          <details id="menuCodingModelField" open><summary>Coding Model</summary>
            <label><input type="radio" name="model" value="default" checked>Default model</label>
            <label><input type="radio" name="model" value="other">Other model</label></details>''' + MENU + '''</details>
          <main id="chat" aria-label="Agent Chat"></main></body></html>''')
        page.add_style_tag(content='''body{margin:0;padding:16px;font:16px/1.4 system-ui}label{display:block;margin:8px 0}
          button{min-height:44px;margin:3px;padding:5px 10px}textarea{box-sizing:border-box;width:100%}
          #chat{max-width:650px}.sf-canvas-project{margin:16px 0;padding:12px;border:1px solid #aaa;overflow-wrap:anywhere}''')
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-projects-ui.js').read_text())
        page.add_script_tag(content=GATE)
        page.evaluate(SETUP)
        ideas = page.locator('#menuCanvasIdeas')
        automatic = page.locator('#menuCanvasAutoApprove')
        menu = page.locator('#menuPanel')
        prefix = f'chromium/{width}: '
        expect(ideas).to_be_enabled()
        check(ideas.is_checked() and not automatic.is_checked(), prefix + 'ideas default on and automatic approval defaults off')
        check(page.evaluate('calls.post.length===0&&calls.build.length===0'), prefix + 'page load reads settings without planning or building')
        ideas.uncheck()
        expect(page.locator('#menuCanvasSuggest')).to_be_disabled()
        check(menu.get_attribute('open') is not None and page.locator('#menuCodingModelField').get_attribute('open') is not None,
              prefix + 'settings checkbox keeps both the menu and coding-model selector open')
        page.locator('input[value="other"]').check()
        check(menu.get_attribute('open') is not None, prefix + 'model selection remains usable alongside project preferences')
        page.evaluate('controller.destroy();controller=createController();controller.setOwner()')
        expect(ideas).to_be_enabled()
        check(not ideas.is_checked() and not automatic.is_checked(), prefix + 'saved project settings survive controller reload')
        ideas.check()
        page.evaluate("document.querySelector('#menuPanel').open=false")
        page.evaluate('''() => {
          window.draft={action:'create',canvas_kind:'custom',track_id:'',context_track_id:'song-2',previous_id:11,
            brief:'Paper forms move when dragged',coding_model:{provider:'anthropic',model:'claude-fixture'},
            image_reference_ids:['aa'.repeat(16)],image_count:2,review_images:true,
            music_inputs:{mode:'selected',track_ids:['song-9','song-2']},source_scene_ids:[11,12]};
          startBuild(draft);
        }''')
        card = page.locator('.sf-canvas-project').last
        expect(card.get_by_role('button', name='Approve project', exact=True)).to_be_enabled()
        check(page.evaluate('calls.build.length===0&&calls.code.length===0&&calls.images.length===0'),
              prefix + 'project concept waits in chat before code or image generation')
        page.evaluate('''() => {
          window.reviewed=structuredClone(profile(owner).proposals.at(-1).project);
          window.savedCard=document.querySelector('.sf-canvas-project');document.querySelector('#chat').replaceChildren();controller.attach();
          draft.brief='Unreviewed changed prompt';draft.coding_model.model='changed-model';draft.music_inputs.track_ids.push('unreviewed-song');
          draft.image_reference_ids.length=0;
        }''')
        check(card.evaluate('node=>node===window.savedCard'), prefix + 'chat rerender reattaches the same working approval card')
        page.evaluate('holdApprove=true')
        card.get_by_role('button', name='Approve project', exact=True).dblclick()
        page.wait_for_function('typeof releaseApprove==="function"')
        check(page.evaluate('calls.post.filter(row=>row.action==="approve").length===1&&calls.build.length===0'),
              prefix + 'double approval submits once while approval is pending')
        page.evaluate('releaseApprove();delete window.releaseApprove')
        page.wait_for_function('calls.build.length===1')
        check(page.evaluate('''() => {const {request_id,project_managed,proposal_id,...scope}=calls.build[0];
          return project_managed===true&&proposal_id===1&&/^[a-f0-9]{32}$/.test(request_id)&&JSON.stringify(scope)===JSON.stringify(reviewed)}'''),
              prefix + 'approved build preserves the complete reviewed model, image, music, source and revision scope')
        check(page.evaluate('calls.images.length===1&&calls.images[0]===2&&voice.active&&voice.starts===0&&voice.stops===0'),
              prefix + 'approved image count executes once without changing voice state')
        page.evaluate("void startBuild({canvas_kind:'custom',track_id:'',brief:'Original editable concept'})")
        card = page.locator('.sf-canvas-project').last
        expect(card.get_by_role('button', name='Revise', exact=True)).to_be_enabled()
        card.get_by_role('button', name='Revise', exact=True).click()
        card.get_by_role('textbox').fill('Reviewed replacement concept')
        page.evaluate("controller.handleCommand('/approve')")
        check(page.evaluate('calls.build.length===1&&calls.post.filter(row=>row.action==="approve").length===1'),
              prefix + 'chat approval cannot approve an old scope while its brief is being edited')
        card.get_by_role('button', name='Review revision', exact=True).click()
        revised = page.locator('.sf-canvas-project').last
        expect(revised.get_by_role('button', name='Approve project', exact=True)).to_be_enabled()
        check(page.evaluate('profile(owner).proposals[1].status==="canceled"&&calls.build.length===1'),
              prefix + 'reviewed edit cancels the old approval and waits for a new one')
        revised.get_by_role('button', name='Cancel', exact=True).click()
        page.wait_for_function('lastError==="AbortError"')
        page.evaluate("controller.handleCommand('/approve')")
        check(page.evaluate('calls.build.length===1'), prefix + 'canceled or replaced proposals cannot dispatch a stale build')
        page.evaluate("switchOwner('2')")
        expect(ideas).to_be_enabled()
        check(ideas.is_checked() and not automatic.is_checked() and not page.locator('.sf-canvas-project').count(),
              prefix + 'changing account removes old concepts and loads independent defaults')
        page.evaluate("holdSuggest=true;void(window.ideaRequest=controller.suggest().catch(error=>window.ideaError=error.name))")
        page.wait_for_function('typeof releaseSuggest==="function"')
        page.evaluate("switchOwner('3');releaseSuggest();delete window.releaseSuggest")
        expect(ideas).to_be_enabled()
        page.wait_for_function('ideaError==="AbortError"')
        check(not page.locator('.sf-canvas-project').count() and page.evaluate('calls.build.length===1'),
              prefix + 'a late idea response from a different account is discarded')
        page.evaluate("document.querySelector('#menuPanel').open=true")
        automatic.check()
        page.evaluate("document.querySelector('#menuPanel').open=false;void startBuild({canvas_kind:'custom',track_id:'',brief:'Explicitly automatic project'})")
        page.wait_for_function('calls.build.length===2')
        check(page.evaluate('profile("3").preferences.auto_approve===true&&profile("1").preferences.auto_approve===false'),
              prefix + 'explicit auto approval applies only to the account that enabled it')
        page.evaluate("document.querySelector('#menuPanel').open=true")
        automatic.uncheck()
        page.evaluate('''() => {
          const account=profile(owner),proposal={id:++nextProposal,status:'approved',approval_mode:'automatic',title:'Previously automatic',concept:'Saved concept',
            project:{action:'create',canvas_kind:'custom',track_id:'',context_track_id:'',brief:'Previously automatic',image_reference_ids:[],review_images:false,source_scene_ids:[]},source_scene_ids:[]};
          account.proposals.push(proposal);controller.refresh();document.querySelector('#menuPanel').open=false;
        }''')
        prior_auto = page.locator('.sf-canvas-project').last
        expect(prior_auto.get_by_role('button', name='Approve project', exact=True)).to_be_enabled()
        prior_auto.get_by_role('button', name='Approve project', exact=True).click()
        page.wait_for_function('calls.build.length===3')
        check(page.evaluate('profile(owner).proposals.at(-1).approval_mode==="manual"'),
              prefix + 'an old automatic approval becomes explicit manual approval after the setting is disabled')
        page.evaluate('''() => {
          window.processingStart={posts:calls.post.length,proposals:profile(owner).proposals.length,builds:calls.build.length};
          processingReplies=1;void startBuild({canvas_kind:'custom',track_id:'',brief:'Processing replay concept'});
        }''')
        page.wait_for_function('profile(owner).proposals.at(-1).project.brief==="Processing replay concept"')
        processing_card = page.locator('.sf-canvas-project').last
        expect(processing_card.get_by_role('button', name='Approve project', exact=True)).to_be_enabled()
        check(page.evaluate('''() => {const posts=calls.post.slice(processingStart.posts).filter(row=>row.action==='propose');
          return posts.length===2&&posts[0].request_id===posts[1].request_id&&profile(owner).proposals.length===processingStart.proposals+1&&calls.build.length===processingStart.builds}'''),
              prefix + 'processing replay keeps one request identity and creates one eventual proposal awaiting approval')
        processing_card.get_by_role('button', name='Approve project', exact=True).click()
        page.wait_for_function('calls.build.length===processingStart.builds+1')
        page.evaluate('''() => {
          window.abortStart={posts:calls.post.length,proposals:profile(owner).proposals.length,builds:calls.build.length};
          lastError='';processingReplies=10;void startBuild({canvas_kind:'custom',track_id:'',brief:'Aborted processing concept'});
        }''')
        page.wait_for_function('calls.post.length>abortStart.posts&&processingReplies===9')
        page.evaluate('buildRequest.abort()')
        page.wait_for_function('lastError==="AbortError"')
        page.wait_for_timeout(1200)
        check(page.evaluate('calls.post.length===abortStart.posts+1&&profile(owner).proposals.length===abortStart.proposals&&calls.build.length===abortStart.builds'),
              prefix + 'aborting processing stops replay and never presents or executes a proposal')
        page.evaluate('''() => {
          processingReplies=0;void startBuild({canvas_kind:'custom',track_id:'',brief:'A reviewable revision target'});
        }''')
        auto_revision = page.locator('.sf-canvas-project').last
        expect(auto_revision.get_by_role('button', name='Revise', exact=True)).to_be_enabled()
        auto_revision.get_by_role('button', name='Revise', exact=True).click()
        auto_revision.get_by_role('textbox').fill('Automatically approved replacement')
        page.evaluate("document.querySelector('#menuPanel').open=true")
        automatic.check()
        page.wait_for_function('profile(owner).preferences.auto_approve===true')
        page.evaluate('''() => {
          window.revisionStart={builds:calls.build.length,executions:calls.execute.length,approvals:calls.post.filter(row=>row.action==='approve').length,
            original:profile(owner).proposals.at(-1).id};document.querySelector('#menuPanel').open=false;
        }''')
        auto_revision.get_by_role('button', name='Review revision', exact=True).click()
        page.wait_for_function('calls.build.length===revisionStart.builds+1')
        check(page.evaluate('''calls.execute.length===revisionStart.executions&&calls.post.filter(row=>row.action==='approve').length===revisionStart.approvals&&
          profile(owner).proposals.find(row=>row.id===revisionStart.original).status==='canceled'&&
          calls.build.at(-1).brief==='Automatically approved replacement'&&profile(owner).proposals.at(-1).approval_mode==='automatic' '''),
              prefix + 'a revised auto-approved proposal continues the original waiter once with the new scope')
        page.evaluate("switchOwner('4')")
        expect(ideas).to_be_enabled()
        page.evaluate('''async() => {
          window.discussionStart={builds:calls.build.length,code:calls.code.length,images:calls.images.length,executions:calls.execute.length};
          discussMode='concept';window.discussed=await controller.discuss({brief:'Think about overlapping paper shapes',currentCanvasId:41,
            sourceSceneIds:[41,42],artistInputs:{merch_ids:['hoodie-a']},musicInputs:{mode:'selected',track_ids:['song-9']}});
        }''')
        discussed = page.locator('.sf-canvas-project').last
        expect(discussed.get_by_role('button', name='Approve project', exact=True)).to_be_enabled()
        check(page.evaluate('''discussed.proposal.status==='awaiting_approval'&&calls.build.length===discussionStart.builds&&
          calls.code.length===discussionStart.code&&calls.images.length===discussionStart.images&&calls.execute.length===discussionStart.executions&&
          calls.post.at(-1).action==='discuss'&&calls.post.at(-1).source_scene_ids.join(',')==='41,42'&&
          discussed.proposal.project.artist_inputs.merch_ids[0]==='hoodie-a'&&calls.reply.at(-1).includes('Think about overlapping paper shapes')'''),
              prefix + 'discussion can present an owner-scoped concept and source bindings without starting code or images')
        feedback = discussed.get_by_role('region', name='Canvas preference feedback', exact=True)
        feedback.get_by_role('button', name='Like', exact=True).click()
        expect(feedback.get_by_role('button', name='Like', exact=True)).to_have_attribute('aria-pressed', 'true')
        check(page.evaluate('calls.post.at(-1).action==="feedback"&&calls.post.at(-1).proposal_id===discussed.proposal.id&&calls.build.length===discussionStart.builds'),
              prefix + 'proposal feedback is separate from approval and cannot execute its concept')
        cancel_feedback_count = page.evaluate('calls.post.filter(row=>row.action==="feedback").length')
        discussed.get_by_role('button', name='Cancel', exact=True).click()
        expect(discussed.locator('.sf-project-state')).to_have_text('Project canceled.')
        check(page.evaluate('count=>calls.post.filter(row=>row.action==="feedback").length===count&&profile(owner).feedback.at(-1).preference==="like"', cancel_feedback_count),
              prefix + 'canceling a concept leaves explicit preference unchanged and does not infer dislike')
        page.evaluate('''async() => {
          profile(owner).preferences.auto_approve=true;await controller.refresh();discussMode='reply';
          window.replyOnlyStart={proposals:profile(owner).proposals.length,builds:calls.build.length,executions:calls.execute.length};
          window.replyOnly=await controller.discuss({brief:'Why does the movement feel busy?'});
        }''')
        check(page.evaluate('''!replyOnly.proposal&&replyOnly.reply.includes('Why does the movement feel busy?')&&
          profile(owner).proposals.length===replyOnlyStart.proposals&&calls.build.length===replyOnlyStart.builds&&calls.execute.length===replyOnlyStart.executions'''),
              prefix + 'a pure discussion reply never builds even when automatic project approval is enabled')

        page.evaluate("controller.feedbackForScene({id:41,title:'<b>Saved paper canvas</b>'})")
        scene_card = page.locator('.sf-canvas-feedback-card')
        expect(scene_card.get_by_role('heading', name='<b>Saved paper canvas</b>', exact=True)).to_be_visible()
        scene_feedback = scene_card.get_by_role('region', name='Canvas preference feedback', exact=True)
        scene_feedback.get_by_role('button', name='Like', exact=True).click()
        expect(scene_feedback.get_by_role('button', name='Like', exact=True)).to_have_attribute('aria-pressed', 'true')
        scene_feedback.get_by_text('Add a reason (optional)', exact=True).click()
        scene_feedback.get_by_role('textbox', name='What would you keep or change?', exact=True).fill('Keep the relaxed pace <b>please</b>')
        scene_feedback.get_by_role('button', name='Save reason', exact=True).click()
        page.wait_for_function('profile(owner).feedback.some(row=>row.scene_id===41&&row.reason==="Keep the relaxed pace <b>please</b>")')
        check(scene_card.locator('b').count() == 0 and page.evaluate('calls.build.length===replyOnlyStart.builds&&profile(owner).feedback.find(row=>row.scene_id===41).preference==="like"'),
              prefix + 'saved-canvas Like and an optional plain-text reason persist without changing its code')
        reason_posts = page.evaluate('calls.post.filter(row=>row.action==="feedback").length')
        scene_feedback.get_by_role('textbox').fill('✨' * 267)
        scene_feedback.get_by_role('button', name='Save reason', exact=True).click()
        expect(scene_feedback.get_by_text('Please shorten your feedback reason.', exact=True)).to_be_visible()
        check(page.evaluate('count=>calls.post.filter(row=>row.action==="feedback").length===count', reason_posts),
              prefix + 'a reason over the UTF-8 byte limit stays editable without sending an invalid preference write')
        scene_feedback.get_by_role('textbox').fill('Keep the relaxed pace <b>please</b>')
        scene_feedback.get_by_role('button', name='Dislike', exact=True).click()
        expect(scene_feedback.get_by_role('button', name='Dislike', exact=True)).to_have_attribute('aria-pressed', 'true')
        expect(scene_feedback.get_by_role('button', name='Like', exact=True)).to_have_attribute('aria-pressed', 'false')
        check(page.evaluate('profile(owner).feedback.find(row=>row.scene_id===41).reason==="Keep the relaxed pace <b>please</b>"'),
              prefix + 'an explicit Dislike replaces Like and preserves the supplied reason')
        scene_feedback.get_by_role('button', name='Clear feedback', exact=True).click()
        expect(scene_feedback.get_by_role('button', name='Dislike', exact=True)).to_have_attribute('aria-pressed', 'false')
        expect(scene_feedback.get_by_role('textbox')).to_have_value('')
        check(page.evaluate('!profile(owner).feedback.some(row=>row.scene_id===41)'), prefix + 'clear removes saved preference and its reason')
        scene_feedback.get_by_role('button', name='Like', exact=True).click()
        expect(scene_feedback.get_by_role('button', name='Like', exact=True)).to_have_attribute('aria-pressed', 'true')
        page.evaluate('controller.destroy();controller=createController();controller.setOwner()')
        expect(ideas).to_be_enabled()
        page.evaluate("controller.feedbackForScene({id:41,title:'Saved paper canvas'})")
        scene_feedback = page.locator('.sf-canvas-feedback-card').get_by_role('region', name='Canvas preference feedback', exact=True)
        expect(scene_feedback.get_by_role('button', name='Like', exact=True)).to_have_attribute('aria-pressed', 'true')
        check(page.evaluate('profile("4").feedback.some(row=>row.scene_id===41)'), prefix + 'reopening feedback reads the account’s saved preference')
        page.evaluate("holdDiscuss=true;void controller.discuss({brief:'Private owner four reply'}).catch(error=>window.lateDiscussError=error.name)")
        page.wait_for_function('typeof releaseDiscuss==="function"')
        page.evaluate("switchOwner('5');releaseDiscuss();delete window.releaseDiscuss")
        expect(ideas).to_be_enabled()
        page.wait_for_function('lateDiscussError==="AbortError"')
        check(not page.locator('.sf-canvas-project').count() and page.evaluate('!calls.reply.some(reply=>reply.includes("Private owner four reply"))'),
              prefix + 'changing account drops old feedback cards and a late private discussion reply')
        page.evaluate('''() => {
          window.refreshGuardStart={posts:calls.post.length,replies:calls.reply.length};
          holdGet=true;void controller.discuss({brief:'Private owner five brief'}).catch(error=>window.refreshDiscussError=error.name);
          void controller.feedbackForScene({id:51,title:'Private owner five title'});
        }''')
        page.wait_for_function('typeof releaseGet==="function"')
        page.evaluate("switchOwner('6');releaseGet();delete window.releaseGet")
        expect(ideas).to_be_enabled()
        page.wait_for_function('refreshDiscussError==="AbortError"')
        check(page.evaluate('calls.post.length===refreshGuardStart.posts&&calls.reply.length===refreshGuardStart.replies') and
              not page.locator('.sf-canvas-project').count() and 'Private owner five' not in page.locator('#chat').inner_text(),
              prefix + 'an account switch during refresh cannot carry the prior brief or scene title into the new account')
        page.evaluate("controller.feedbackForScene({id:41,title:'Owner six canvas'})")
        scene_feedback = page.locator('.sf-canvas-feedback-card').get_by_role('region', name='Canvas preference feedback', exact=True)
        expect(scene_feedback.get_by_role('button', name='Like', exact=True)).to_have_attribute('aria-pressed', 'false')
        check(page.evaluate('profile("6").feedback.length===0&&profile("4").feedback.some(row=>row.scene_id===41)'),
              prefix + 'the same canvas identifier never imports another account’s preference')
        scene_feedback.get_by_role('button', name='Like', exact=True).click()
        expect(scene_feedback.get_by_role('button', name='Like', exact=True)).to_have_attribute('aria-pressed', 'true')
        page.evaluate('''async() => {
          profile(owner).preferences={ideas_enabled:false,auto_approve:false};await controller.refresh();
          void startBuild({canvas_kind:'custom',track_id:'',brief:'Private project before history clear'});
        }''')
        expect(page.locator('.sf-canvas-project:not(.sf-canvas-feedback-card)').last.get_by_role('button', name='Approve project', exact=True)).to_be_enabled()
        page.evaluate('''() => {
          window.clearStart={builds:calls.build.length,replies:calls.reply.length};holdDiscuss=true;
          void controller.discuss({brief:'Private reply before history clear'}).catch(error=>window.clearDiscussError=error.name);
        }''')
        page.wait_for_function('typeof releaseDiscuss==="function"')
        page.evaluate('''() => {
          profile(owner).proposals=[];profile(owner).feedback=[];lastError='';controller.clearHistory();
        }''')
        page.wait_for_function('lastError==="AbortError"')
        check(not page.locator('.sf-canvas-project').count() and page.evaluate('calls.build.length===clearStart.builds'),
              prefix + 'clearing history removes pending approval and feedback cards and cancels their build waiter')
        page.evaluate('releaseDiscuss();delete window.releaseDiscuss')
        page.wait_for_function('clearDiscussError==="AbortError"')
        expect(ideas).to_be_enabled()
        page.evaluate("controller.attach();controller.feedbackForScene({id:41,title:'Fresh owner six feedback'})")
        fresh_feedback = page.locator('.sf-canvas-feedback-card').get_by_role('region', name='Canvas preference feedback', exact=True)
        expect(fresh_feedback.get_by_role('button', name='Like', exact=True)).to_have_attribute('aria-pressed', 'false')
        check(not ideas.is_checked() and not automatic.is_checked() and page.evaluate('calls.reply.length===clearStart.replies&&owner==="6"') and
              'before history clear' not in page.locator('#chat').inner_text(),
              prefix + 'a late reply cannot restore cleared history while the same owner reloads saved preferences and clean feedback')
        check(page.evaluate('voice.active&&voice.starts===0&&voice.stops===0') and not errors,
              prefix + 'project review leaves existing listening active and has no browser errors: ' + repr(errors))
        context.close()
    browser.close()

print(json.dumps({'ok': True, 'checks': len(checks)}))
