(() => {
  'use strict';
  const STORAGE_KEY='stonefellow.admin.navigation.categories.v1';
  function storageRead(){
    try{
      const raw=window.localStorage.getItem(STORAGE_KEY);
      if(!raw)return null;
      const value=JSON.parse(raw);
      return value&&typeof value==='object'&&!Array.isArray(value)?value:null;
    }catch(_){return null;}
  }
  function currentState(groups){
    return Object.fromEntries(groups.map(group=>[group.dataset.navCategory,group.open]));
  }
  function init(){
    const nav=document.getElementById('adminNav');
    if(!nav||nav.dataset.accordionsBound==='1')return;
    const groups=Array.from(nav.querySelectorAll('details[data-nav-category]'));
    if(!groups.length)return;
    const saved=storageRead();
    groups.forEach((group,index)=>{
      const key=group.dataset.navCategory;
      group.open=saved&&Object.prototype.hasOwnProperty.call(saved,key)
        ? saved[key]===true
        : index===0;
    });
    nav.dataset.accordionsBound='1';
    for(const group of groups){
      group.addEventListener('toggle',()=>{
        try{window.localStorage.setItem(STORAGE_KEY,JSON.stringify(currentState(groups)));}
        catch(_){/* Private browsing and disabled storage must not break navigation. */}
      });
    }
  }
  window.SFAdminNavAccordion={init,storageRead,currentState,storageKey:STORAGE_KEY};
  init();
})();
