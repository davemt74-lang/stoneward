(() => {
  'use strict';
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const status=$('#a11yStatus'),install=$('#installAppButton');let installPrompt=null,lastOpener=null;
  const announce=t=>{if(!status)return;status.textContent='';requestAnimationFrame(()=>status.textContent=t)};
  const visible=el=>!!el&&!el.hidden&&el.getClientRects().length>0;
  const focusables=root=>$$('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])',root).filter(visible);
  function activeDialog(){return $$('[role="dialog"][aria-modal="true"]',document).find(visible)||null}
  document.addEventListener('click',e=>{const b=e.target.closest('#notificationButton,#playerQueueButton,#menuButton,#chatQuickButton');if(b)lastOpener=b});
  document.addEventListener('keydown',e=>{
    const dialog=activeDialog();
    if(e.key==='Escape'){
      if(dialog?.id==='notificationDrawer')$('#notificationClose')?.click();
      else if(dialog?.id==='queueDrawer')$('#queueClose')?.click();
      else if($('#menuPanel')?.open){$('#menuPanel').open=false;$('#menuButton')?.focus()}
      else if(visible($('#chatQuickMenu')))$('#chatQuickButton')?.click();
      return;
    }
    if(e.key==='Tab'&&dialog){const items=focusables(dialog);if(!items.length)return;const first=items[0],last=items[items.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}}
  });
  for(const id of ['notificationDrawer','queueDrawer']){const el=$('#'+id);if(!el)continue;new MutationObserver(()=>{if(!el.hidden){requestAnimationFrame(()=>focusables(el)[0]?.focus())}else if(lastOpener&&document.contains(lastOpener)){lastOpener.focus();lastOpener=null}}).observe(el,{attributes:true,attributeFilter:['hidden']})}
  let navView=window.STONEFELLOW_INITIAL_ROUTE?.view||new URL(location.href).searchParams.get('view')||'home';
  function markCurrent(){const v=navView;$$('.menu-sheet [data-view]').forEach(a=>{if((a.dataset.view||'home')===v)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current')})}
  window.addEventListener('popstate',()=>setTimeout(markCurrent,0));document.addEventListener('stonefellow:navigation',e=>{navView=e.detail?.view||'home';setTimeout(markCurrent,0)});markCurrent();
  window.addEventListener('offline',()=>announce('Stonefellow is offline. Network features are unavailable.'));
  window.addEventListener('online',()=>announce('Stonefellow is back online.'));
  window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();installPrompt=e;if(install)install.hidden=false});
  install?.addEventListener('click',async()=>{if(!installPrompt)return;installPrompt.prompt();const choice=await installPrompt.userChoice;installPrompt=null;install.hidden=true;if(choice?.outcome==='accepted')announce('Stonefellow installation accepted.')});
  window.addEventListener('appinstalled',()=>{if(install)install.hidden=true;announce('Stonefellow installed.')});
  if('serviceWorker' in navigator&&(location.protocol==='https:'||location.hostname==='localhost'))window.addEventListener('load',()=>navigator.serviceWorker.register('service-worker.js',{scope:'./'}).catch(()=>{}));
})();