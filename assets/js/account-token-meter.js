/* Read-only account balance display. Tokens stay authoritative on the server. */
(() => {
  'use strict';
  let current=null;
  const format=value=>new Intl.NumberFormat(undefined,{maximumFractionDigits:0}).format(value);
  const percent=value=>new Intl.NumberFormat(undefined,{maximumFractionDigits:2}).format(value)+'%';
  function create(options={}){
    const root=options.root||document.getElementById('menuTokenMeter');
    if(!root)return null;
    let owner='',open=false,request=null,dirty=true,disposed=false,sequence=0;
    const title=root.querySelector('[data-token-title]'),count=root.querySelector('[data-token-count]'),percentage=root.querySelector('[data-token-percentage]'),
      basis=root.querySelector('[data-token-basis]'),held=root.querySelector('[data-token-held]'),bar=root.querySelector('[data-token-bar]'),status=root.querySelector('[data-token-status]');
    if(!title||!count||!percentage||!basis||!held||!bar||!status)return null;
    function clear(){count.textContent='';percentage.textContent='';basis.textContent='';held.textContent='';held.hidden=true;bar.hidden=true;bar.removeAttribute('value');status.textContent='';}
    function setOwner(){
      const next=String(options.getOwner?.()||'');
      if(next===owner)return false;
      owner=next;sequence++;request?.abort();request=null;dirty=true;clear();root.hidden=!owner;
      if(owner){status.textContent='Open the menu to check your tokens.';if(open)void refresh();}
      return true;
    }
    function validMeter(meter){
      if(!meter||String(meter.user_id)!==owner||typeof meter.unlimited!=='boolean'||typeof meter.allowance_label!=='string')return false;
      if(meter.unlimited)return meter.percentage_remaining===null&&meter.bar_percentage===null;
      if(!Number.isSafeInteger(meter.available_tokens)||meter.available_tokens<0||!Number.isSafeInteger(meter.held_tokens)||meter.held_tokens<0)return false;
      if(meter.allowance_tokens===null)return meter.percentage_remaining===null&&meter.bar_percentage===null;
      return Number.isSafeInteger(meter.allowance_tokens)&&meter.allowance_tokens>0&&Number.isFinite(meter.percentage_remaining)&&meter.percentage_remaining>=0&&Number.isFinite(meter.bar_percentage)&&meter.bar_percentage>=0&&meter.bar_percentage<=100;
    }
    function draw(meter){
      if(!validMeter(meter))throw Error('Your token balance could not be verified.');
      title.textContent='Active Tokens';
      count.textContent=meter.unlimited?'Unlimited':format(meter.available_tokens)+' available';
      percentage.textContent=meter.unlimited?'':meter.percentage_remaining===null?'Percentage unavailable':percent(meter.percentage_remaining)+' remaining';
      basis.textContent=meter.allowance_label;
      held.hidden=meter.unlimited||meter.held_tokens===0;
      held.textContent=held.hidden?'':format(meter.held_tokens)+' held for current work (already excluded from available)';
      bar.hidden=meter.unlimited||meter.bar_percentage===null;
      if(!bar.hidden){bar.value=meter.bar_percentage;bar.setAttribute('aria-valuetext',percent(meter.percentage_remaining)+' of '+meter.allowance_label);}
      else bar.removeAttribute('value');
      status.textContent='';root.hidden=false;
    }
    async function refresh(){
      if(disposed||!owner||!open)return;
      if(request){dirty=true;return;}
      dirty=false;const captured=owner,epoch=sequence,read=new AbortController();request=read;
      status.textContent=count.textContent?'Updating tokens…':'Loading tokens…';
      try{
        const response=await options.read(read.signal);
        if(disposed||read.signal.aborted||request!==read||epoch!==sequence||captured!==String(options.getOwner?.()||''))return;
        draw(response?.meter);
      }catch(error){
        if(!disposed&&!read.signal.aborted&&request===read&&epoch===sequence&&captured===String(options.getOwner?.()||'')){
          clear();status.textContent='Token balance unavailable. Reopen the menu to retry.';
        }
      }finally{
        if(request===read){request=null;if(!disposed&&dirty&&open&&captured===owner)void refresh();}
      }
    }
    function setOpen(value){
      open=!!value;const changedOwner=setOwner();
      if(open&&owner&&!changedOwner)void refresh();
      // A closed menu has no timer or polling. A pending read may finish only
      // for its captured account; reopening always requests a fresh snapshot.
    }
    function changed(){dirty=true;const changedOwner=setOwner();if(open&&owner&&!changedOwner)void refresh();}
    const focus=()=>{const changedOwner=setOwner();if(open&&owner&&!changedOwner)void refresh();};
    const visibility=()=>{if(document.visibilityState==='visible')focus();};
    window.addEventListener('focus',focus);document.addEventListener('visibilitychange',visibility);
    setOwner();
    return {setOwner,setOpen,changed,refresh,destroy(){disposed=true;sequence++;request?.abort();request=null;clear();root.hidden=true;window.removeEventListener('focus',focus);document.removeEventListener('visibilitychange',visibility);}};
  }
  window.SFTokenMeter=Object.freeze({create(options){current?.destroy();current=create(options);return current;},notifyUsage(){current?.changed();},setOwner(){current?.setOwner();},setOpen(value){current?.setOpen(value);}});
})();
