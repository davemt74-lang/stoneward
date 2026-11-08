/* Host-owned, account-bound canvas permission review; never runs authored code. */
(() => {
  'use strict';
  const element=(tag,text,className)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=String(text);if(className)node.className=className;return node;};
  const context=scene=>typeof scene?.gallery_id==='string'&&/^[a-f0-9]{32}$/.test(scene.gallery_id)?{gallery_id:scene.gallery_id}:
    Number.isSafeInteger(scene?.id)&&scene.id>0?{scene_id:scene.id}:null;
  const key=value=>value?.gallery_id?'gallery:'+value.gallery_id:value?.scene_id?'scene:'+value.scene_id:'';
  const abortError=()=>new DOMException('Canvas access review ended.','AbortError');
  function create(options){
    let active=null,owner=String(options.getOwner?.()||'');
    const reviewed=new Set();
    function close(){const view=active;if(!view)return;active=null;view.request.abort();view.root.remove();document.body.classList.remove('sf-canvas-access-open');
      for(const [node,wasInert] of view.background)if(node.isConnected)node.inert=wasInert;
      if(view.focus?.isConnected)view.focus.focus({preventScroll:true});}
    function setOwner(){const next=String(options.getOwner?.()||'');if(next!==owner){owner=next;reviewed.clear();close();}}
    const valid=view=>active===view&&!view.request.signal.aborted&&view.owner===String(options.getOwner?.()||'')&&(view.managing||view.scene===options.getScene?.());
    function validate(access,expected){
      if(!access||key(access.context)!==key(expected)||!/^[a-f0-9]{64}$/.test(access.fingerprint||'')||typeof access.signed_in!=='boolean'||typeof access.requires_consent!=='boolean'||!Array.isArray(access.permissions))throw Error('Canvas access details were incomplete. Reopen the review.');
      for(const row of access.permissions)if(typeof row.id!=='string'||!/^canvas\.data\.[a-z][a-z0-9_]{0,100}$/.test(row.id)||typeof row.label!=='string'||typeof row.description!=='string'||typeof row.personal!=='boolean'||typeof row.enabled!=='boolean'||typeof row.granted!=='boolean')throw Error('Canvas permission details were incomplete.');
      if(access.libraries!==undefined&&(!Array.isArray(access.libraries)||access.libraries.some(row=>typeof row?.label!=='string'||typeof row.enabled!=='boolean')))throw Error('Canvas library details were incomplete.');
      return access;
    }
    async function show(scene=options.getScene?.(),{automatic=false}={}){
      setOwner();const selected=context(scene);if(!selected||scene!==options.getScene?.()||options.isBusy?.())return false;
      const supplied=scene.canvas_access;
      if(automatic){
        if(active?.managing)return false;
        if(!supplied?.requires_consent||!supplied?.signed_in||!supplied.fingerprint)return false;
        const reviewKey=owner+'|'+key(selected)+'|'+supplied.fingerprint;
        if(reviewed.has(reviewKey))return false;reviewed.add(reviewKey);
      }
      close();
      const root=element('div',undefined,'sf-canvas-access-overlay'),panel=element('section',undefined,'sf-canvas-access-panel');
      panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');panel.setAttribute('aria-label','What this canvas uses');panel.tabIndex=-1;
      const heading=element('h2','What this canvas uses'),description=element('p','Review the tools and data this experience requests. Your personal data stays unavailable until you allow its listed access.'),status=element('p','Loading access details…','sf-canvas-access-status');status.setAttribute('role','status');
      const list=element('div',undefined,'sf-canvas-access-list'),actions=element('div',undefined,'sf-canvas-access-actions'),allow=element('button','Allow selected access'),done=element('button','Continue without personal data'),refresh=element('button','Refresh details');
      for(const button of [allow,done,refresh])button.type='button';allow.disabled=true;refresh.hidden=true;
      actions.append(allow,done,refresh);panel.append(heading,description,list,status,actions);root.append(panel);
      const view={root,panel,request:new AbortController(),owner,scene,context:selected,focus:document.activeElement,access:null,busy:false,choices:new Map(),background:Array.from(document.body.children,node=>[node,node.inert])};active=view;for(const [node] of view.background)node.inert=true;document.body.append(root);document.body.classList.add('sf-canvas-access-open');
      function pending(){return Array.from(view.choices).filter(([,input])=>input.checked).map(([id])=>id);}
      function update(){allow.disabled=view.busy||!pending().length;}
      async function change(action,ids){
        if(!valid(view)||view.busy||!view.access)return;view.busy=true;allow.disabled=true;refresh.disabled=true;
        for(const node of list.querySelectorAll('input,button'))node.disabled=true;
        status.textContent=action==='grant'?'Saving your access choices…':'Revoking this access…';
        try{
          const response=await options.write({action,context:view.context,fingerprint:view.access.fingerprint,permission_ids:ids},view.request.signal);
          if(!valid(view))throw abortError();validate(response.access,view.context);reviewed.add(view.owner+'|'+key(view.context)+'|'+response.access.fingerprint);
          await options.onRefresh?.(view.scene,view.request.signal);
          if(!valid(view)){if(active===view)close();return;}
          draw(response.access);status.textContent=action==='grant'?'Your choices are saved for this canvas.':'Access revoked for this canvas.';
        }catch(error){if(valid(view)&&error.name!=='AbortError'){status.textContent=error.message||'Access could not be updated. Refresh the review before retrying.';refresh.hidden=false;}}
        finally{view.busy=false;if(valid(view)){refresh.disabled=false;for(const node of list.querySelectorAll('input,button'))node.disabled=false;update();}}
      }
      function draw(access){
        view.access=validate(access,view.context);reviewed.add(view.owner+'|'+key(view.context)+'|'+view.access.fingerprint);view.choices.clear();list.replaceChildren();
        description.textContent=access.title?'Requested by “'+access.title+'”. Only the listed personal-data access is remembered.':'Only the listed personal-data access is remembered.';
        for(const permission of access.permissions){
          const row=element('div',undefined,'sf-canvas-access-item'),label=element('strong',permission.label),detail=element('p',permission.description);
          const state=element('small',!permission.enabled?'Disabled by administrator':permission.device_consent?'Asks to check your device location':!permission.personal?'Public artist data':permission.granted?'Allowed for your account':!access.signed_in?'Sign in to use your own data':'Needs your permission');
          row.append(label,detail,state);
          if(permission.personal&&access.signed_in){
            if(permission.granted){const revoke=element('button','Revoke '+permission.label);revoke.type='button';revoke.addEventListener('click',()=>void change('revoke',[permission.id]),{signal:view.request.signal});row.append(revoke);}
            else if(permission.enabled){const choice=element('label',undefined,'sf-canvas-access-choice'),input=element('input');input.type='checkbox';input.checked=true;view.choices.set(permission.id,input);choice.append(input,document.createTextNode(' Allow '+permission.label));input.addEventListener('change',update,{signal:view.request.signal});row.append(choice);}
          }
          list.append(row);
        }
        for(const library of access.libraries||[]){
          const row=element('div',undefined,'sf-canvas-access-item');
          row.append(element('strong',library.label),element('small',library.enabled?'Local script library':'Disabled by administrator'));
          list.append(row);
        }
        if(!access.permissions.length&&!access.libraries?.length)list.append(element('p','No registered tools or data permissions requested.'));
        if(!access.signed_in&&access.permissions.some(p=>p.personal)){const signIn=element('button','Sign in');signIn.type='button';signIn.addEventListener('click',()=>{close();options.onSignIn?.();},{signal:view.request.signal});list.append(signIn);}
        done.textContent=access.requires_consent?'Continue without personal data':'Done';
        allow.hidden=!view.choices.size;status.textContent=access.requires_consent?'You can continue without allowing personal data.':'Existing grants are reused. You can revoke them here.';refresh.hidden=true;update();panel.focus({preventScroll:true});
      }
      async function load(){
        if(!valid(view))return;status.textContent='Loading access details…';allow.disabled=true;refresh.disabled=true;
        try{const response=await options.read(view.context,view.request.signal);if(valid(view))draw(response.access);}
        catch(error){if(valid(view)&&error.name!=='AbortError'){status.textContent=error.message||'Access details are unavailable. Personal data remains protected.';refresh.hidden=false;}}
        finally{if(valid(view))refresh.disabled=false;}
      }
      allow.addEventListener('click',()=>void change('grant',pending()),{signal:view.request.signal});
      done.addEventListener('click',close,{signal:view.request.signal});refresh.addEventListener('click',load,{signal:view.request.signal});
      root.addEventListener('click',event=>{if(event.target===root)close();},{signal:view.request.signal});
      root.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();close();return;}if(event.key!=='Tab')return;
        const focusable=Array.from(panel.querySelectorAll('button,input')).filter(node=>!node.disabled&&!node.hidden&&node.getClientRects().length);if(!focusable.length){event.preventDefault();panel.focus();return;}
        const first=focusable[0],last=focusable.at(-1);if(event.shiftKey&&(document.activeElement===first||document.activeElement===panel)){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
      },{signal:view.request.signal});panel.focus({preventScroll:true});await load();return true;
    }
    async function manage(){
      setOwner();if(!owner){options.onSignIn?.();return false;}close();
      const root=element('div',undefined,'sf-canvas-access-overlay'),panel=element('section',undefined,'sf-canvas-access-panel');
      panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');panel.setAttribute('aria-label','Canvas data permissions');panel.tabIndex=-1;
      const status=element('p','Loading saved permissions…','sf-canvas-access-status');status.setAttribute('role','status');
      const list=element('div'),done=element('button','Done'),refresh=element('button','Refresh');done.type=refresh.type='button';
      const actions=element('div',undefined,'sf-canvas-access-actions');actions.append(done,refresh);
      panel.append(element('h2','Canvas data permissions'),element('p','Review or revoke your saved personal-data grants, including grants for canvases that are no longer available.'),list,status,actions);root.append(panel);
      const view={root,panel,request:new AbortController(),owner,managing:true,scene:options.getScene?.(),focus:document.activeElement,background:Array.from(document.body.children,node=>[node,node.inert])};
      active=view;for(const [node] of view.background)node.inert=true;document.body.append(root);document.body.classList.add('sf-canvas-access-open');let busy=false;
      function draw(grants){
        if(!Array.isArray(grants))throw Error('Saved permissions were incomplete. Refresh to try again.');
        list.replaceChildren();
        for(const grant of grants){
          if(typeof grant.context_key!=='string'||typeof grant.permission_id!=='string'||typeof grant.label!=='string')throw Error('Saved permission details were incomplete.');
          const row=element('div',undefined,'sf-canvas-access-item'),revoke=element('button','Revoke '+grant.label);revoke.type='button';
          row.append(element('strong',grant.label),element('small',grant.context_key),element('small',grant.granted_at?'Allowed '+grant.granted_at:''),revoke);list.append(row);
          revoke.addEventListener('click',async()=>{
            if(!valid(view)||busy)return;busy=true;for(const button of list.querySelectorAll('button'))button.disabled=true;refresh.disabled=true;status.textContent='Revoking saved permission…';
            const current=options.getScene?.();
            try{const response=await options.write({action:'revoke_saved',context_key:grant.context_key,permission_ids:[grant.permission_id]},view.request.signal);
              if(!valid(view))return;draw(response.grants);await options.onRefresh?.(current,view.request.signal);if(valid(view)){status.textContent='Saved permission revoked.';panel.focus({preventScroll:true});}}
            catch(error){if(valid(view)&&error.name!=='AbortError')status.textContent=error.message||'Permission could not be revoked. Refresh to try again.';}
            finally{busy=false;if(valid(view)){refresh.disabled=false;for(const button of list.querySelectorAll('button'))button.disabled=false;}}
          },{signal:view.request.signal});
        }
        if(!grants.length)list.append(element('p','No saved personal-data permissions.'));
        status.textContent=grants.length+' saved permission(s).';
      }
      async function load(){if(!valid(view)||busy)return;busy=true;refresh.disabled=true;status.textContent='Loading saved permissions…';try{const response=await options.readMine(view.request.signal);if(valid(view))draw(response.grants);}catch(error){if(valid(view)&&error.name!=='AbortError')status.textContent=error.message||'Saved permissions are unavailable.';}finally{busy=false;if(valid(view))refresh.disabled=false;}}
      done.addEventListener('click',close,{signal:view.request.signal});refresh.addEventListener('click',load,{signal:view.request.signal});root.addEventListener('click',event=>{if(event.target===root)close();},{signal:view.request.signal});
      root.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();close();return;}if(event.key!=='Tab')return;const buttons=Array.from(panel.querySelectorAll('button')).filter(node=>!node.disabled&&node.getClientRects().length);const first=buttons[0],last=buttons.at(-1);if(event.shiftKey&&(document.activeElement===first||document.activeElement===panel)){event.preventDefault();last?.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}},{signal:view.request.signal});
      panel.focus({preventScroll:true});await load();return true;
    }
    return {show,manage,close,setOwner,sceneMounted(scene){setTimeout(()=>{if(scene===options.getScene?.())void show(scene,{automatic:true});},0);},destroy(){close();reviewed.clear();}};
  }
  window.SFCanvasAccess=Object.freeze({create});
})();
