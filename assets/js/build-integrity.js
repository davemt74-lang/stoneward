(() => {
  'use strict';
  // Not precached by pre-1.3.59 service workers, so this diagnostic can recover
  // a page where the old controller returned outdated app.js with updated CSS.
  const expected=document.querySelector('meta[name="stonefellow-build"]')?.content?.trim()||'';
  if(!expected)return;
  const jsVersion=String(window.STONEFELLOW_APP_BUILD||'').trim();
  const cssVersion=getComputedStyle(document.documentElement).getPropertyValue('--stonefellow-builder-css-build').replace(/['"]/g,'').trim();
  if(jsVersion===expected&&cssVersion===expected)return;
  const KEY='stonefellow.asset.recovery.attempted';
  const issue='Your Stonefellow update loaded mixed versions of the page and player. Repairing cached files…';
  const banner=document.createElement('div');
  banner.id='stonefellowAssetMismatch';banner.setAttribute('role','alert');
  banner.style.cssText='position:fixed;inset:0;z-index:2147483647;display:grid;place-content:center;gap:16px;padding:24px;background:#090807;color:#f2eee8;font:16px/1.5 system-ui,sans-serif;text-align:center';
  const message=document.createElement('p');message.textContent=issue;
  const button=document.createElement('button');button.type='button';button.textContent='Repair and reload Stonefellow';
  button.style.cssText='padding:13px 20px;border:1px solid #cca96e;background:#caa96d;color:#0b0906;font-weight:700;cursor:pointer';
  const detail=document.createElement('small');detail.textContent='Expected build '+expected+' · App '+(jsVersion||'outdated')+' · Styles '+(cssVersion||'outdated');
  banner.append(message,button,detail);document.body.appendChild(banner);
  let running=false;
  async function repair(){
    if(running)return;running=true;button.disabled=true;message.textContent=issue;
    try{
      if(navigator.serviceWorker?.getRegistrations){
        const registrations=await navigator.serviceWorker.getRegistrations();
        for(const registration of registrations){
          const script=registration.active?.scriptURL||registration.waiting?.scriptURL||registration.installing?.scriptURL||'';
          if(script.includes('/service-worker.js'))await registration.unregister();
        }
      }
      if(window.caches?.keys){
        for(const key of await window.caches.keys())if(key.startsWith('stonefellow-static-'))await window.caches.delete(key);
      }
      window.location.reload();
    }catch(e){
      console.error('Stonefellow static asset repair failed',e);
      message.textContent='Automatic repair was blocked. Refresh this page with Ctrl + Shift + R, or clear this site’s cached data.';
      button.disabled=false;running=false;
    }
  }
  button.addEventListener('click',()=>{void repair()});
  let tried=false;
  try{tried=sessionStorage.getItem(KEY)===expected;if(!tried)sessionStorage.setItem(KEY,expected);}
  catch(_){tried=true;} // Browsers that deny storage require an explicit click; no reload loop.
  if(!tried)void repair();
  else message.textContent='This page still has mixed-version files. Select Repair and reload Stonefellow.';
})();
