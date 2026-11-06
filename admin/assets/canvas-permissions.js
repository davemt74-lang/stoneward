(() => {
  'use strict';
  let active=null;
  const node=(tag,text)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;return el;};
  function renderInto(host){
    active?.abort();const controller=new AbortController();active=controller;
    const api=window.STONEFELLOW_ADMIN_CONTEXT?.api;
    if(!host||!api)return;
    const root=node('section'), title=node('h1','Canvas permissions');
    const intro=node('p','Control which registered data permissions canvases may use. These switches do not grant user consent or change access to the shop, account pages or administrator tools.');
    const safety=node('p','Authentication, account isolation, CSRF checks and sandbox protections always remain enforced. Personal datasets also require the current viewer’s remembered canvas consent. Existing account-scoped authorization remains enforced.');
    const status=node('p','Loading permissions…');status.setAttribute('role','status');
    const search=node('input');search.type='search';search.placeholder='Search permissions';search.setAttribute('aria-label','Search permissions');
    const form=node('form'), rows=node('div'), pending=node('p'), save=node('button','Save permission changes'), refresh=node('button','Refresh');
    save.type='submit';refresh.type='button';save.disabled=true;
    const history=node('div');root.append(title,intro,safety,search,form,status,history);form.append(rows,pending,save,refresh);host.replaceChildren(root);
    let current=null,busy=false;const inputs=new Map();
    const alive=()=>!controller.signal.aborted&&root.isConnected;
    const update=()=>{let count=0;for(const [id,input] of inputs)if(input.checked!==current.capabilities.find(c=>c.permission_id===id).enabled)count++;pending.textContent=count?count+' pending change(s). Disabling applies to subsequent dataset deliveries; already displayed values cannot be recalled.':'No pending changes.';save.disabled=busy||!count;};
    function draw(data){current=data;inputs.clear();rows.replaceChildren();
      for(const card of data.capabilities){const label=node('label');label.style.cssText='display:block;padding:14px 0;border-bottom:1px solid #ffffff22';
        const input=node('input');input.type='checkbox';input.checked=card.enabled;input.disabled=busy;inputs.set(card.permission_id,input);
        const name=node('strong',' '+card.label);const detail=node('p',card.description);const scope=node('small',card.permission_id+' · Read · '+(card.requires_sign_in?'Signed-in account only':'Public artist data')+' · '+(card.requires_sign_in?'Account authorization and canvas consent required':'No personal data'));
        input.addEventListener('change',update,{signal:controller.signal});label.append(input,name,detail,scope);label.dataset.search=(card.label+' '+card.description+' '+card.permission_id).toLowerCase();rows.append(label);}
      history.replaceChildren(node('h2','Policy history'));
      for(const item of data.history)history.append(node('p','Revision '+item.revision+' · Admin #'+item.updated_by+' · '+item.updated_at+' · '+Object.entries(item.changes).map(([id,v])=>id+': '+(v.after?'enabled':'disabled')).join('; ')));
      if(!data.history.length)history.append(node('p','No policy changes recorded. Existing permissions remain enabled by default.'));
      status.textContent='Policy revision '+data.policy.revision;update();filter();}
    function filter(){for(const row of rows.children){row.hidden=!row.dataset.search.includes(search.value.toLowerCase());row.style.display=row.hidden?'none':'block';}}
    async function load(){busy=true;save.disabled=true;refresh.disabled=true;try{const data=await api('canvas-permissions.php');if(alive())draw(data);}catch(e){if(alive())status.textContent=e.message||'Could not load permissions.';}finally{busy=false;if(alive()){refresh.disabled=false;for(const input of inputs.values())input.disabled=false;if(current)update();}}}
    search.addEventListener('input',filter,{signal:controller.signal});refresh.addEventListener('click',load,{signal:controller.signal});
    form.addEventListener('submit',async event=>{event.preventDefault();if(busy||!current)return;const changes={};for(const [id,input] of inputs)if(input.checked!==current.capabilities.find(c=>c.permission_id===id).enabled)changes[id]=input.checked;if(!Object.keys(changes).length)return;
      busy=true;save.disabled=true;refresh.disabled=true;for(const input of inputs.values())input.disabled=true;
      try{const data=await api('canvas-permissions.php',{revision:current.policy.revision,changes});if(alive())draw(data);}catch(e){if(alive())status.textContent=e.message||'Save failed. Refresh and review before retrying.';}finally{busy=false;if(alive()){refresh.disabled=false;for(const input of inputs.values())input.disabled=false;update();}}
    },{signal:controller.signal});
    load();return root;
  }
  window.SFCanvasPermissionsAdmin={renderInto,destroy(){active?.abort();active=null;}};
})();
