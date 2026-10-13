(() => {
  'use strict';
  const context=window.STONEFELLOW_ADMIN_CONTEXT;
  const canvas=context?.canvas;
  if(!canvas)return;

  /*
   * Existing handlers remain authoritative: buttons and fully-bound editor
   * DOM nodes are moved, never cloned, re-rendered or intercepted at the API.
   * New modules can opt into the same shell without changing persistence.
   */
  const createActions={
    templates:['newTemplate'],releases:['newRelease'],shows:['newShow'],
    knowledge:['newKnowledgeFolder'],packages:['newPackage'],
    media:['mediaUploadLibrary','galleryNew','videoHubNew'],
    campaigns:['newCampaign'],products:['newProduct'],
    membership:['newMemberContent'],ticketing:['newTicketOffer'],
    fulfillment:['newShipment'],automations:['newSegment','newAutomation'],
    press:['pressKitNew','pressContactNew','pressCoverageNew'],
    rights:['rightsPartyNew'],calendar:['newOperatingPlan','newMilestone']
  };
  // Only standalone editor views with an existing canonical Back action.
  // Already-modal editors (Story, Gallery, Video, Shipments, Press contacts)
  // are excluded so their established confirmations continue untouched.
  const editors=[
    ['templateForm','backTemplates','templates'],
    ['trackForm','backCatalog','catalog'],
    ['releaseForm','backReleases','releases'],
    ['showForm','backShows','shows'],
    ['packageForm','backPackages','packages'],
    ['campaignMetaForm','campaignBack','campaigns'],
    ['productForm','productsBack','products'],
    ['membershipContentForm','membershipBack','membership'],
    ['ticketOfferForm','ticketBack','ticketing'],
    ['segmentForm','segmentBack','automations'],
    ['automationForm','automationBack','automations'],
    ['pressKitForm','pressBack','press'],
    ['operatingPlanForm','planCancel','calendar']
  ];
  let upgrading=false,lastView='',returnId='';
  const getView=()=>String(context.app?.view||'');
  const visible=el=>Boolean(el?.isConnected&&!el.closest('[hidden]')&&getComputedStyle(el).display!=='none');
  function addHeader(){
    if(canvas.querySelector('.admin-unified-form-overlay'))return;
    if(['settings','agent-chat','brain'].includes(getView()))return;
    let header=canvas.querySelector(':scope > .canvas-head');
    if(!header&&canvas.children.length&&canvas.textContent.trim()){
      header=document.createElement('div');
      header.className='canvas-head admin-unified-generated-header';
      const wrapper=document.createElement('div');
      const eyebrow=document.createElement('div');eyebrow.className='eyebrow';eyebrow.textContent='STONEFELLOW ADMIN';
      const title=document.createElement('h1');
      title.textContent=document.getElementById('viewTitle')?.textContent||'Admin';
      wrapper.append(eyebrow,title);
      header.append(wrapper);
      canvas.prepend(header);
    }
    if(!header)return;
    const view=getView(),ids=createActions[view]||[];
    let actions=header.querySelector(':scope > .actions');
    for(const id of ids){
      const el=canvas.querySelector('#'+id);
      if(!visible(el)||el.closest('.canvas-head,.media-modal,.story-modal,.admin-unified-form-overlay'))continue;
      if(!actions){actions=document.createElement('div');actions.className='actions admin-unified-header-actions';header.append(actions);}
      actions.append(el); // maintain existing click listeners and state
    }
    header.classList.add('admin-unified-page-header');
  }
  function markEditor(){
    if(canvas.querySelector('.admin-unified-form-overlay'))return;
    const view=getView();
    const match=editors.find(([id,back,scope])=>{
      if(view!==scope)return false;
      return canvas.querySelector('#'+id)&&canvas.querySelector('#'+back);
    });
    if(!match)return;
    const [formId,backId]=match;
    // A standalone route is converted into a focused form section using
    // its existing DOM; no new form, database route or permissions.
    const overlay=document.createElement('div');
    overlay.className='admin-unified-form-overlay';
    overlay.setAttribute('role','dialog');
    overlay.setAttribute('aria-modal','true');
    overlay.setAttribute('aria-label','Admin editor');
    const shell=document.createElement('div');
    shell.className='admin-unified-form-shell';
    const bar=document.createElement('div');
    bar.className='admin-unified-form-bar';
    const name=document.createElement('strong');
    name.textContent=document.getElementById('viewTitle')?.textContent||'Edit record';
    const close=document.createElement('button');
    close.type='button';close.className='secondary';close.textContent='Close editor';
    close.setAttribute('aria-label','Close editor and return to list');
    bar.append(name,close);
    const content=document.createElement('div');
    content.className='admin-unified-form-content';
    const elements=Array.from(canvas.childNodes);
    for(const element of elements)content.append(element);
    shell.append(bar,content);
    overlay.append(shell);
    canvas.append(overlay);
    document.body.classList.add('admin-unified-form-open');
    const existingBack=content.querySelector('#'+backId);
    let dirty=false;
    content.addEventListener('input',e=>{if(e.target.closest('form'))dirty=true;});
    content.addEventListener('change',e=>{if(e.target.closest('form'))dirty=true;});
    content.addEventListener('submit',()=>{dirty=false;},true);
    function dismiss(){
      if(dirty&&!window.confirm('Discard unsaved changes and close this editor?'))return;
      dirty=false;
      if(existingBack?.isConnected)existingBack.click();
      else context.openView(view);
      // Editors may rerender asynchronously: cleanup runs after the next paint.
      if(!canvas.querySelector('.admin-unified-form-overlay'))
        document.body.classList.remove('admin-unified-form-open');
    }
    close.addEventListener('click',dismiss);
    overlay.addEventListener('click',e=>{if(e.target===overlay)dismiss();});
    overlay.addEventListener('keydown',e=>{
      if(e.key==='Escape'){e.preventDefault();dismiss();return;}
      if(e.key!=='Tab')return;
      const fields=Array.from(overlay.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]'))
        .filter(el=>el.getClientRects().length&& !el.closest('[hidden]'));
      if(!fields.length)return;
      if(e.shiftKey&&document.activeElement===fields[0]){e.preventDefault();fields.at(-1).focus();}
      else if(!e.shiftKey&&document.activeElement===fields.at(-1)){e.preventDefault();fields[0].focus();}
    });
    const focus=formId==='trackForm'?'#trackForm input':'#'+formId+' input:not([type=hidden])';
    (overlay.querySelector(focus)||close).focus({preventScroll:true});
  }
  function apply(){
    if(upgrading)return;
    upgrading=true;
    try{
      const view=getView();
      if(lastView!==view||!canvas.querySelector('.admin-unified-form-overlay')){
        document.body.classList.remove('admin-unified-form-open');
      }
      lastView=view;
      markEditor();
      addHeader();
    }finally{upgrading=false;}
  }
  const observer=new MutationObserver(()=>apply());
  observer.observe(canvas,{childList:true,subtree:true});
  apply();
  window.SFAdminUnifiedUI={
    inspect:()=>({view:getView(),overlay:!!canvas.querySelector('.admin-unified-form-overlay'),
      header:!!canvas.querySelector('.admin-unified-page-header')}),
    supportedActions:createActions,editorForms:editors
  };
})();