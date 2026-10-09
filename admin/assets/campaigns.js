(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const {api,canvas,head,say,esc,slug}=ctx;
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const nodeTypes={
    trigger:['Trigger','Entry point'],audience:['Audience','CRM eligibility'],condition:['Condition','Branch by fan state'],
    wait:['Wait','Pause the journey'],email:['Email','Admin-approved send'],crm_tag:['CRM Tag','Update fan profile'],
    agent_message:['Agent Message','In-app Agent action'],offer_download:['Free Download','Song entitlement'],
    offer_discount:['Discount','Merch/order discount'],offer_vip:['VIP Offer','Show/ticket access'],
    offer_exclusive:['Exclusive','Private content'],redirect:['Redirect','Open a destination'],
    conversion:['Conversion','Mark success'],exit:['Exit','End journey']
  };
  const state={summary:{},items:[],segments:[],editor:null};

  function defaultGraph(){
    return {nodes:[
      {id:'trigger_1',type:'trigger',x:60,y:140,config:{label:'Campaign entry'}},
      {id:'audience_1',type:'audience',x:310,y:140,config:{newsletter_only:false,linked_accounts_only:false,purchase_required:false,stages:[],tags:[]}},
      {id:'exit_1',type:'exit',x:570,y:140,config:{label:'Complete'}}
    ],edges:[
      {from:'trigger_1',to:'audience_1',label:''},
      {from:'audience_1',to:'exit_1',label:'eligible'}
    ]};
  }
  function draft(){
    return {id:0,slug:'',name:'',goal:'fan_acquisition',status:'draft',headline:'',body_text:'',artwork:'',starts_at:'',ends_at:'',audience:{},landing:{form_title:'Join this campaign',cta:'Continue',success_message:'You’re in.',require_name:false,newsletter_label:'Send me Stonefellow news and offers'},graph:defaultGraph()};
  }
  function templateDraft(kind){
    const c=draft(),g=c.graph,a=g.nodes.find(function(n){return n.type==='audience'}),exit=g.nodes.find(function(n){return n.type==='exit'});let offer=null;
    if(kind==='newsletter'){c.name='Newsletter Signup';c.goal='newsletter_growth';c.headline='Join the Stonefellow newsletter';c.body_text='Get Stonefellow music, release, show and project updates.';c.landing.form_title='Join the list';}
    if(kind==='download'){c.name='Free Song Download';c.goal='free_download';c.headline='Get a free Stonefellow song';offer={id:'offer_download_1',type:'offer_download',x:570,y:140,config:{title:'Free song download',description:'Enter your email to unlock the download.',cta:'Get the song',track_id:'',inventory:0}};}
    if(kind==='discount'){c.name='Merch Discount';c.goal='merch_sales';c.headline='Stonefellow merch offer';offer={id:'offer_discount_1',type:'offer_discount',x:570,y:140,config:{title:'Merch discount',description:'Claim a Stonefellow store discount.',cta:'Claim discount',percent_off:10,amount_off_cents:0,inventory:0}};}
    if(kind==='vip'){c.name='VIP Ticket Offer';c.goal='vip';c.headline='Stonefellow VIP access';offer={id:'offer_vip_1',type:'offer_vip',x:570,y:140,config:{title:'VIP ticket offer',description:'Claim VIP or presale access for this Stonefellow show.',cta:'Claim VIP access',show_id:'',url:'',access_code:'',inventory:0}};}
    if(offer){exit.x=830;g.nodes.push(offer);g.edges=g.edges.filter(function(e){return !(e.from===a.id&&e.to===exit.id)});g.edges.push({from:a.id,to:offer.id,label:'eligible'},{from:offer.id,to:exit.id,label:''});}
    c.slug=slug(c.name);return c;
  }
  function badge(v){return '<span class="badge '+(v==='published'?'good':'')+'">'+esc(String(v||'draft').toUpperCase())+'</span>'}
  async function loadList(){
    const j=await api('campaigns.php');
    state.summary=j.summary||{};state.items=j.campaigns||[];state.segments=j.segments||[];
    return j;
  }
  async function renderCampaigns(){
    try{await loadList()}catch(e){canvas.innerHTML=head('CAMPAIGNS','Unavailable',e.message);return}
    const s=state.summary,rows=state.items;
    canvas.innerHTML=head('FAN ACQUISITION + PROMOTION','Campaigns','Build newsletter acquisition, free downloads, discounts, VIP offers, exclusives and governed fan journeys.','<button class="primary" id="newCampaign" type="button">New campaign</button>')+
      '<div class="stats"><div class="stat"><strong>'+Number(s.campaigns||0)+'</strong><span>Campaigns</span></div><div class="stat"><strong>'+Number(s.active||0)+'</strong><span>Published</span></div><div class="stat"><strong>'+Number(s.participants||0)+'</strong><span>Participants</span></div><div class="stat"><strong>'+Number(s.redemptions||0)+'</strong><span>Redemptions</span></div></div><section class="campaign-starters"><button data-campaign-template="newsletter"><strong>Newsletter Signup</strong><span>Grow opted-in CRM audience</span></button><button data-campaign-template="download"><strong>Free Song</strong><span>Email capture + download</span></button><button data-campaign-template="discount"><strong>Merch Discount</strong><span>Claimable store code</span></button><button data-campaign-template="vip"><strong>VIP Tickets</strong><span>Show access or presale</span></button></section>'+
      '<section class="panel"><div class="panel-title"><div><h2>Campaign library</h2><p>Draft, schedule, publish, pause and analyze direct-to-fan campaigns.</p></div></div>'+
      (rows.length?'<table class="data-table"><thead><tr><th>Campaign</th><th>Goal</th><th>Status</th><th>Window</th><th>Version</th><th></th></tr></thead><tbody>'+rows.map(function(c){return '<tr><td><strong>'+esc(c.name)+'</strong><div class="file-list">/campaign/'+esc(c.slug)+'</div></td><td>'+esc(c.goal)+'</td><td>'+badge(c.status)+'</td><td>'+esc((c.starts_at||'anytime').replace('T',' ').slice(0,16))+' → '+esc((c.ends_at||'open').replace('T',' ').slice(0,16))+'</td><td>v'+Number(c.version||1)+'</td><td><button class="secondary" type="button" data-campaign-open="'+c.id+'">Open</button></td></tr>'}).join('')+'</tbody></table>':'<div class="empty">No campaigns yet. Create the first one.</div>')+'</section>';
    $('#newCampaign').onclick=function(){openEditor(0)};$('[data-campaign-template]',canvas).forEach(function(b){b.onclick=function(){state.editor={campaign:templateDraft(b.dataset.campaignTemplate),analytics:{},participants:[],events:[],runs:[],tab:'builder',selected:null,connectFrom:null,simulation:[]};renderEditor()}});$('[data-campaign-open]',canvas).forEach(function(b){b.onclick=function(){openEditor(Number(b.dataset.campaignOpen))}});
  }
  async function openEditor(id,tab){
    try{
      if(id){
        const j=await api('campaigns.php?id='+encodeURIComponent(id));
        state.editor={campaign:j.campaign,analytics:j.analytics||{},participants:j.participants||[],events:j.events||[],runs:j.message_runs||[],tab:tab||'builder',selected:null,connectFrom:null,simulation:[]};
      }else state.editor={campaign:draft(),analytics:{},participants:[],events:[],runs:[],tab:tab||'builder',selected:null,connectFrom:null,simulation:[]};
      renderEditor();
    }catch(e){say(e.message)}
  }
  function captureMeta(){
    const e=state.editor,c=e&&e.campaign,f=$('#campaignMetaForm');
    if(!c||!f)return;
    const d=new FormData(f);
    c.name=String(d.get('name')||'').trim();c.slug=String(d.get('slug')||'').trim();c.goal=String(d.get('goal')||'fan_acquisition');
    c.headline=String(d.get('headline')||'').trim();c.body_text=String(d.get('body_text')||'').trim();c.artwork=String(d.get('artwork')||'').trim();
    c.starts_at=String(d.get('starts_at')||'');c.ends_at=String(d.get('ends_at')||'');
  }
  function captureLanding(){
    const e=state.editor,c=e&&e.campaign,f=$('#campaignLandingForm');
    if(!c||!f)return;
    const d=new FormData(f);c.landing=Object.assign({},c.landing||{},{
      form_title:String(d.get('form_title')||''),cta:String(d.get('cta')||''),success_message:String(d.get('success_message')||''),
      require_name:d.get('require_name')==='on',newsletter_label:String(d.get('newsletter_label')||'')
    });
  }
  function audienceFromGraph(c){
    const n=(c.graph&&c.graph.nodes||[]).find(function(x){return x.type==='audience'});
    return n&&n.config?n.config:{};
  }
  function switchTab(tab){captureMeta();captureLanding();state.editor.tab=tab;renderEditor()}
  function renderEditor(){
    const e=state.editor;if(!e)return renderCampaigns();
    const c=e.campaign,tab=e.tab||'builder',saved=Number(c.id)>0;
    const actions='<button class="secondary" id="campaignBack" type="button">Campaigns</button><button class="secondary" id="campaignValidate" type="button">Validate</button><button class="secondary" id="campaignSimulate" type="button">Simulate</button><button class="primary" id="campaignSave" type="button">Save</button>'+
      (saved?'<button class="secondary" id="campaignDuplicate" type="button">Duplicate</button><button class="'+(c.status==='published'?'secondary':'primary')+'" id="campaignPublish" type="button">'+(c.status==='published'?'Pause':'Publish')+'</button>':'');
    canvas.innerHTML=head('CAMPAIGN BUILDER',saved?c.name:'New campaign','Drag nodes onto the canvas, connect the journey, configure the fan experience, then validate before publishing.',actions)+
      '<form id="campaignMetaForm" class="campaign-meta-grid">'+
      '<label class="field">Name<input name="name" value="'+esc(c.name||'')+'" required></label>'+
      '<label class="field">Slug<input name="slug" value="'+esc(c.slug||'')+'" placeholder="new-single"></label>'+
      '<label class="field">Goal<select name="goal">'+['fan_acquisition','newsletter_growth','free_download','merch_sales','ticket_sales','vip','release_promotion','loyalty','win_back','custom'].map(function(x){return '<option value="'+x+'" '+(c.goal===x?'selected':'')+'>'+x.replaceAll('_',' ')+'</option>'}).join('')+'</select></label>'+
      '<label class="field">Starts<input name="starts_at" type="datetime-local" value="'+esc((c.starts_at||'').slice(0,16))+'"></label>'+
      '<label class="field">Ends<input name="ends_at" type="datetime-local" value="'+esc((c.ends_at||'').slice(0,16))+'"></label>'+
      '<label class="field span2">Public headline<input name="headline" value="'+esc(c.headline||'')+'"></label>'+
      '<label class="field span2">Artwork / image path<input name="artwork" value="'+esc(c.artwork||'')+'"></label>'+
      '<label class="field span3">Public campaign copy<textarea name="body_text">'+esc(c.body_text||'')+'</textarea></label></form>'+
      (saved?'<div class="campaign-public-link"><span>'+badge(c.status)+'</span><code>/campaign/'+esc(c.slug)+'</code><button class="secondary" type="button" id="copyCampaignUrl">Copy URL</button></div>':'')+
      '<div class="campaign-tabs">'+[['builder','Builder'],['landing','Landing Page'],['participants','Participants'],['analytics','Analytics']].map(function(x){return '<button type="button" data-campaign-tab="'+x[0]+'" class="'+(tab===x[0]?'active':'')+'">'+x[1]+'</button>'}).join('')+'</div>'+
      '<div id="campaignTabBody">'+(tab==='builder'?builderHtml(e):tab==='landing'?landingHtml(c):tab==='participants'?participantsHtml(e):analyticsHtml(e))+'</div>'+
      '<div id="campaignSimulation" class="campaign-simulation">'+((e.simulation||[]).length?'<strong>Simulation path</strong>'+e.simulation.map(function(x){return '<span>'+esc(x)+'</span>'}).join(''):'')+'</div>';
    $('#campaignBack').onclick=function(){ctx.openView('campaigns')};
    $('#campaignValidate').onclick=validateGraph;$('#campaignSimulate').onclick=simulateGraph;$('#campaignSave').onclick=function(){saveCampaign(null)};
    const pub=$('#campaignPublish');if(pub)pub.onclick=publishCampaign;
    const dup=$('#campaignDuplicate');if(dup)dup.onclick=duplicateCampaign;
    const copy=$('#copyCampaignUrl');if(copy)copy.onclick=function(){const base=location.href.replace(/admin\/.*$/,'');navigator.clipboard&&navigator.clipboard.writeText(base+'campaign/'+c.slug);say('Campaign URL copied.')};
    $$('[data-campaign-tab]',canvas).forEach(function(b){b.onclick=function(){switchTab(b.dataset.campaignTab)}});
    if(tab==='builder')bindBuilder();if(tab==='landing'){const f=$('#campaignLandingForm');if(f)f.oninput=captureLanding}
  }
  function builderHtml(e){
    const g=e.campaign.graph||defaultGraph(),selected=(g.nodes||[]).find(function(n){return n.id===e.selected})||null;
    return '<section class="campaign-builder"><aside class="campaign-palette"><div class="eyebrow">NODE PALETTE</div><p>Drag a node onto the canvas.</p>'+
      Object.entries(nodeTypes).map(function(entry){return '<button type="button" draggable="true" data-campaign-palette="'+entry[0]+'"><strong>'+esc(entry[1][0])+'</strong><span>'+esc(entry[1][1])+'</span></button>'}).join('')+
      '</aside><div class="campaign-canvas-wrap"><div class="campaign-canvas-toolbar"><span>'+g.nodes.length+' nodes · '+g.edges.length+' connections</span>'+(e.connectFrom?'<strong>Connecting from '+esc(e.connectFrom)+' — click a target node</strong>':'<span>Drag nodes to reposition. Use ⤴ to connect.</span>')+'</div><div id="campaignFlowCanvas" class="campaign-flow-canvas">'+wiresHtml(g)+(g.nodes||[]).map(function(n){return nodeHtml(n,e)}).join('')+'</div></div><aside class="campaign-inspector">'+inspectorHtml(selected,e)+'</aside></section>';
  }
  function wiresHtml(g){
    const map={};(g.nodes||[]).forEach(function(n){map[n.id]=n});
    return '<svg class="campaign-wires" viewBox="0 0 1200 760" preserveAspectRatio="none">'+(g.edges||[]).map(function(edge){
      const a=map[edge.from],b=map[edge.to];if(!a||!b)return'';
      const x1=Number(a.x)+190,y1=Number(a.y)+45,x2=Number(b.x),y2=Number(b.y)+45,m=Math.max(45,Math.abs(x2-x1)*.45);
      return '<path d="M '+x1+' '+y1+' C '+(x1+m)+' '+y1+', '+(x2-m)+' '+y2+', '+x2+' '+y2+'"/><text x="'+((x1+x2)/2)+'" y="'+((y1+y2)/2-7)+'">'+esc(edge.label||'')+'</text>';
    }).join('')+'</svg>';
  }
  function nodeHtml(n,e){
    const meta=nodeTypes[n.type]||[n.type,''],label=n.config&&((n.config.title||n.config.label))||meta[0];
    return '<article class="campaign-node '+(e.selected===n.id?'selected ':'')+(e.connectFrom===n.id?'connecting':'')+'" draggable="true" data-campaign-node="'+esc(n.id)+'" style="left:'+Number(n.x||0)+'px;top:'+Number(n.y||0)+'px"><div class="campaign-node-type">'+esc(meta[0])+'</div><strong>'+esc(label)+'</strong><small>'+esc(n.id)+'</small><button class="campaign-port" type="button" data-connect-from="'+esc(n.id)+'" title="Connect from this node">⤴</button></article>';
  }
  function input(name,label,val,type){return '<label class="field">'+label+'<input name="'+name+'" type="'+(type||'text')+'" value="'+esc(val==null?'':val)+'"></label>'}
  function textarea(name,label,val){return '<label class="field">'+label+'<textarea name="'+name+'">'+esc(val||'')+'</textarea></label>'}
  function checkbox(name,label,val){return '<label class="check-field"><input name="'+name+'" type="checkbox" '+(val?'checked':'')+'>'+label+'</label>'}
  function nodeFields(n,c){
    if(n.type==='audience')return checkbox('newsletter_only','Newsletter subscribers only',!!c.newsletter_only)+checkbox('linked_accounts_only','Linked accounts only',!!c.linked_accounts_only)+checkbox('purchase_required','Previous purchasers only',!!c.purchase_required)+input('stages','CRM stages',(c.stages||[]).join(', '))+input('tags','Required CRM tags',(c.tags||[]).join(', '));
    if(n.type==='condition')return input('field','Field',c.field||'marketing_opt_in')+input('operator','Operator',c.operator||'equals')+input('value','Value',c.value||'true');
    if(n.type==='wait')return input('hours','Wait hours',c.hours||24,'number');
    if(n.type==='email')return input('subject','Subject',c.subject||'')+textarea('body','Email body',c.body||'');
    if(n.type==='crm_tag')return input('tag','CRM tag',c.tag||'campaign-engaged');
    if(n.type==='agent_message')return textarea('message','In-app Agent message',c.message||'')+checkbox('respect_auto_engage','Require fan proactive-Agent permission',c.respect_auto_engage!==false);
    if(n.type==='offer_download')return input('title','Offer title',c.title||'Free song download')+textarea('description','Description',c.description||'')+'<label class="field">Track<select name="track_id"><option value="">Choose track</option>'+((ctx.app.catalog||[]).map(function(t){return '<option value="'+esc(t.id)+'" '+(c.track_id===t.id?'selected':'')+'>'+esc(t.title)+'</option>'}).join(''))+'</select></label>'+input('cta','CTA',c.cta||'Get the song')+input('inventory','Claim limit',c.inventory||0,'number')+input('expires_at','Expires',String(c.expires_at||'').slice(0,16),'datetime-local');
    if(n.type==='offer_discount')return input('title','Offer title',c.title||'Merch discount')+textarea('description','Description',c.description||'')+input('percent_off','Percent off',c.percent_off||0,'number')+input('amount_off_cents','Fixed cents off',c.amount_off_cents||0,'number')+input('cta','CTA',c.cta||'Claim discount')+input('inventory','Claim limit',c.inventory||0,'number')+input('expires_at','Expires',String(c.expires_at||'').slice(0,16),'datetime-local');
    if(n.type==='offer_vip')return input('title','Offer title',c.title||'VIP ticket offer')+textarea('description','Description',c.description||'')+'<label class="field">Show<select name="show_id"><option value="">Choose show</option>'+((ctx.app.shows||[]).map(function(s){return '<option value="'+esc(s.id)+'" '+(c.show_id===s.id?'selected':'')+'>'+esc(s.title||s.venue||s.id)+'</option>'}).join(''))+'</select></label>'+input('url','Ticket / VIP URL',c.url||'')+input('access_code','Access code',c.access_code||'')+input('cta','CTA',c.cta||'Claim VIP access')+input('inventory','Claim limit',c.inventory||0,'number');
    if(n.type==='offer_exclusive')return input('title','Exclusive title',c.title||'Exclusive access')+textarea('description','Description',c.description||'')+input('url','Private URL',c.url||'')+input('access_code','Access code',c.access_code||'')+input('cta','CTA',c.cta||'Unlock access')+input('inventory','Claim limit',c.inventory||0,'number');
    if(n.type==='redirect')return input('url','Destination URL',c.url||'')+input('label','Label',c.label||'Continue');
    return input('label','Label',c.label||((nodeTypes[n.type]||[n.type])[0]));
  }
  function inspectorHtml(n,e){
    if(!n)return '<div class="eyebrow">INSPECTOR</div><h3>Select a node</h3><p>Click any node to edit its action and relationships.</p>';
    const cfg=n.config||{},out=(e.campaign.graph.edges||[]).filter(function(x){return x.from===n.id});
    return '<div class="eyebrow">NODE INSPECTOR</div><h3>'+esc((nodeTypes[n.type]||[n.type])[0])+'</h3><form id="campaignNodeForm" data-node-id="'+esc(n.id)+'">'+nodeFields(n,cfg)+'<div class="actions"><button class="primary" type="submit">Apply node</button>'+(n.type!=='trigger'?'<button class="danger" id="campaignDeleteNode" type="button">Delete</button>':'')+'</div></form>'+
      (n.type==='email'&&Number(e.campaign.id)>0?'<div class="campaign-approval"><strong>Consequential action</strong><p>Email never sends merely because this node exists. Admin confirmation is required for every send.</p><button class="secondary" id="campaignSendEmail" type="button">Send approved email now</button></div>':'')+
      '<div class="campaign-edge-list"><strong>Outgoing connections</strong>'+(out.length?out.map(function(x,i){return '<div><span>'+esc(x.to)+(x.label?' · '+esc(x.label):'')+'</span><button type="button" data-edge-delete="'+i+'">×</button></div>'}).join(''):'<small>No outgoing connections.</small>')+'</div>';
  }
  function bindBuilder(){
    const e=state.editor,g=e.campaign.graph||(e.campaign.graph=defaultGraph()),flow=$('#campaignFlowCanvas');
    $$('[data-campaign-palette]',canvas).forEach(function(b){b.ondragstart=function(ev){ev.dataTransfer.setData('campaign/type',b.dataset.campaignPalette)}});
    $$('[data-campaign-node]',flow).forEach(function(n){
      n.ondragstart=function(ev){if(ev.target.closest('button')){ev.preventDefault();return}ev.dataTransfer.setData('campaign/node',n.dataset.campaignNode)};
      n.onclick=function(ev){
        if(ev.target.closest('[data-connect-from]'))return;
        const id=n.dataset.campaignNode;
        if(e.connectFrom&&e.connectFrom!==id){
          const source=g.nodes.find(function(x){return x.id===e.connectFrom}),label=source&&source.type==='condition'?(prompt('Branch label, for example yes or no','yes')||''):'';
          if(!g.edges.some(function(x){return x.from===e.connectFrom&&x.to===id&&x.label===label}))g.edges.push({from:e.connectFrom,to:id,label:label});
          e.connectFrom=null;e.selected=id;renderEditor();return;
        }
        e.selected=id;renderEditor();
      };
    });
    $$('[data-connect-from]',flow).forEach(function(b){b.onclick=function(ev){ev.stopPropagation();e.connectFrom=b.dataset.connectFrom;renderEditor()}});
    flow.ondragover=function(ev){ev.preventDefault()};
    flow.ondrop=function(ev){
      ev.preventDefault();const rect=flow.getBoundingClientRect(),x=Math.max(10,Math.round(ev.clientX-rect.left-90)),y=Math.max(10,Math.round(ev.clientY-rect.top-35)),nodeId=ev.dataTransfer.getData('campaign/node'),type=ev.dataTransfer.getData('campaign/type');
      if(nodeId){const n=g.nodes.find(function(z){return z.id===nodeId});if(n){n.x=x;n.y=y;e.selected=n.id}}
      else if(type&&nodeTypes[type]){let id=type+'_'+Date.now().toString(36);while(g.nodes.some(function(z){return z.id===id}))id+='_x';const n={id:id,type:type,x:x,y:y,config:{label:nodeTypes[type][0]}};if(type==='audience')n.config={newsletter_only:false,linked_accounts_only:false,purchase_required:false,stages:[],tags:[]};g.nodes.push(n);e.selected=id}
      renderEditor();
    };
    const form=$('#campaignNodeForm');if(form)form.onsubmit=function(ev){ev.preventDefault();applyNode(form)};
    const del=$('#campaignDeleteNode');if(del)del.onclick=deleteNode;
    $$('[data-edge-delete]',canvas).forEach(function(b){b.onclick=function(){const out=g.edges.filter(function(x){return x.from===e.selected}),edge=out[Number(b.dataset.edgeDelete)];g.edges=g.edges.filter(function(x){return x!==edge});renderEditor()}});
    const send=$('#campaignSendEmail');if(send)send.onclick=sendEmail;
  }
  function applyNode(form){
    const e=state.editor,g=e.campaign.graph,n=g.nodes.find(function(x){return x.id===form.dataset.nodeId});if(!n)return;
    const d=new FormData(form),cfg=Object.assign({},n.config||{});
    d.forEach(function(v,k){cfg[k]=v});
    ['newsletter_only','linked_accounts_only','purchase_required','respect_auto_engage'].forEach(function(k){cfg[k]=d.get(k)==='on'});
    ['inventory','percent_off','amount_off_cents','hours'].forEach(function(k){if(Object.prototype.hasOwnProperty.call(cfg,k))cfg[k]=Number(cfg[k]||0)});
    ['stages','tags'].forEach(function(k){if(Object.prototype.hasOwnProperty.call(cfg,k))cfg[k]=String(cfg[k]||'').split(',').map(function(x){return x.trim()}).filter(Boolean)});
    n.config=cfg;if(n.type==='audience')e.campaign.audience=Object.assign({},cfg);renderEditor();
  }
  function deleteNode(){const e=state.editor,g=e.campaign.graph,id=e.selected;if(!id)return;g.nodes=g.nodes.filter(function(x){return x.id!==id});g.edges=g.edges.filter(function(x){return x.from!==id&&x.to!==id});e.selected=null;e.connectFrom=null;renderEditor()}
  function landingHtml(c){
    const l=c.landing||{};
    return '<section class="panel"><div class="panel-title"><h2>Landing page & form</h2><p>Every published campaign receives a public /campaign/{slug} experience.</p></div><form id="campaignLandingForm" class="form-grid">'+
      '<label class="field span2">Form title<input name="form_title" value="'+esc(l.form_title||'Join this campaign')+'"></label><label class="field">CTA<input name="cta" value="'+esc(l.cta||'Continue')+'"></label>'+
      '<label class="field span3">Success message<textarea name="success_message">'+esc(l.success_message||'You’re in.')+'</textarea></label>'+
      '<label class="check-field span3"><input name="require_name" type="checkbox" '+(l.require_name?'checked':'')+'> Require fan name</label>'+
      '<label class="field span3">Newsletter consent label<input name="newsletter_label" value="'+esc(l.newsletter_label||'Send me Stonefellow news and offers')+'"></label></form>'+
      '<div class="notice"><strong>Consent boundary:</strong> providing an email to receive an offer is not newsletter consent. Marketing remains an explicit checkbox.</div></section>';
  }
  function participantsHtml(e){
    const rows=e.participants||[];
    return '<section class="panel"><div class="panel-title"><h2>Participants</h2><p>'+rows.length+' recent participants</p></div>'+(rows.length?'<table class="data-table"><thead><tr><th>Fan</th><th>State</th><th>Newsletter</th><th>Source</th><th>Entered</th></tr></thead><tbody>'+rows.map(function(p){return '<tr><td><strong>'+esc(p.name||'Fan')+'</strong><div class="file-list">'+esc(p.email)+'</div></td><td>'+esc(p.state)+'</td><td>'+(Number(p.marketing_opt_in)?'opted in':'—')+'</td><td>'+esc(p.source)+'</td><td>'+esc((p.entered_at||'').replace('T',' ').slice(0,19))+'</td></tr>'}).join('')+'</tbody></table>':'<div class="empty">No participants yet.</div>')+'</section>';
  }
  function analyticsHtml(e){
    const a=e.analytics||{},events=a.events||{},offers=a.offers||[];
    return '<div class="stats"><div class="stat"><strong>'+Number(a.participants||0)+'</strong><span>Participants</span></div><div class="stat"><strong>'+Number(a.converted||0)+'</strong><span>Converted</span></div><div class="stat"><strong>'+Number(a.attributed_orders||0)+'</strong><span>Attributed orders</span></div><div class="stat"><strong>$'+(Number(a.attributed_revenue_cents||0)/100).toFixed(2)+'</strong><span>Attributed revenue</span></div></div>'+
      '<section class="campaign-analytics-grid"><div class="panel"><div class="panel-title"><h2>Event funnel</h2></div>'+(Object.keys(events).length?Object.entries(events).map(function(x){return '<div class="metric-row"><span>'+esc(x[0].replaceAll('_',' '))+'</span><strong>'+Number(x[1])+'</strong></div>'}).join(''):'<div class="empty">No campaign events yet.</div>')+'</div>'+
      '<div class="panel"><div class="panel-title"><h2>Offer activity</h2></div>'+(offers.length?offers.map(function(x){return '<div class="metric-row"><span>'+esc(x.entitlement_type)+' · '+esc(x.status)+'</span><strong>'+Number(x.c)+'</strong></div>'}).join(''):'<div class="empty">No offer claims yet.</div>')+'</div></section>';
  }
  async function validateGraph(){captureMeta();captureLanding();try{const r=await api('campaigns.php',{action:'validate_graph',graph:state.editor.campaign.graph});say('Campaign graph is valid: '+r.graph.nodes.length+' nodes and '+r.graph.edges.length+' connections.')}catch(e){say(e.message)}}
  function simulateGraph(){
    const e=state.editor,g=e.campaign.graph||{},trigger=(g.nodes||[]).find(function(n){return n.type==='trigger'});
    if(!trigger){e.simulation=['Simulation stopped: no Trigger node.'];return renderEditor()}
    const map={};g.nodes.forEach(function(n){map[n.id]=n});const seen=new Set(),queue=[trigger.id],path=[];
    while(queue.length&&path.length<80){const id=queue.shift();if(seen.has(id)){path.push('Cycle detected at '+id);continue}seen.add(id);const n=map[id];if(!n)continue;let note=(nodeTypes[n.type]||[n.type])[0];if(n.type==='email')note+=' — explicit Admin send approval';if(n.type==='wait')note+=' — pauses '+Number(n.config&&n.config.hours||0)+'h';if(n.type.indexOf('offer_')===0)note+=' — creates entitlement on claim';path.push(note);g.edges.filter(function(x){return x.from===id}).forEach(function(x){queue.push(x.to)})}
    e.simulation=path.length?path:['No reachable nodes.'];renderEditor();
  }
  async function saveCampaign(forceStatus){
    captureMeta();captureLanding();const e=state.editor,c=e.campaign;if(!c.slug)c.slug=slug(c.name);c.audience=audienceFromGraph(c);if(forceStatus)c.status=forceStatus;
    try{const j=await api('campaigns.php',{action:'save',campaign:c});e.campaign=j.campaign;e.analytics=j.analytics||e.analytics;await loadList();say('Campaign saved as '+e.campaign.status+'.');renderEditor()}catch(x){say(x.message)}
  }
  async function publishCampaign(){
    const c=state.editor.campaign;
    if(c.status==='published'){if(!confirm('Pause this campaign? Public entry and offers stop immediately.'))return;return saveCampaign('paused')}
    if(!confirm('Publish this campaign? Public landing pages and offer claims become active. Email nodes still require a separate Admin send approval.'))return;
    saveCampaign('published');
  }
  async function duplicateCampaign(){
    const c=state.editor.campaign;if(!c.id)return;
    try{const j=await api('campaigns.php',{action:'duplicate',id:c.id});await loadList();say('Campaign duplicated as a draft.');openEditor(j.campaign.id)}catch(e){say(e.message)}
  }
  async function sendEmail(){
    const e=state.editor,c=e.campaign,node=(c.graph.nodes||[]).find(function(x){return x.id===e.selected});if(!node||node.type!=='email'||!c.id)return;
    if(!confirm('Send this campaign email now to the eligible opted-in audience? This cannot be undone.'))return;
    try{const j=await api('campaigns.php',{action:'send_email',id:c.id,node_id:node.id,confirmed:true});say('Campaign email run complete: '+j.result.sent+' sent, '+j.result.failed+' failed.');openEditor(c.id,'analytics')}catch(x){say(x.message)}
  }
  window.SFCampaignAdmin={renderCampaigns:renderCampaigns,openEditor:openEditor};
})();
