'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');

function extract(source,name){
  const begin=source.search(new RegExp('(?:async )?function '+name+'\\('));
  assert.ok(begin>=0,`${name} is missing`);
  const opening=source.indexOf('{',begin);let depth=0;
  for(let i=opening;i<source.length;i++){
    if(source[i]==='{')depth++;
    if(source[i]==='}'&&!--depth)return source.slice(begin,i+1);
  }
  throw Error('Unbalanced function '+name);
}

function shellContract(){
  const php=read('admin/index.php'),css=read('admin/assets/admin.css'),endpoint=read('admin/api/profile-photo.php');
  for(const id of ['adminProfileToggle','adminProfileDropdown','adminHeaderAvatar','adminProfileAvatar','adminProfileChangePhoto','adminProfilePhotoInput','adminProfilePhotoStatus']){
    assert.match(php,new RegExp('id="'+id+'"'),`missing ${id}`);
  }
  assert.match(php,/aria-expanded="false" aria-controls="adminProfileDropdown"/);
  assert.match(php,/href="\.\.\/\?view=account"/,'account settings must be a working route');
  assert.match(php,/data-profile-view="settings"/);
  assert.match(php,/data-profile-view="users"/);
  assert.match(php,/data-profile-view="brain"/);
  assert.match(php,/data-profile-view="operations"/);
  assert.match(php,/href="\.\.\/" target="_blank"/,'View Site stays available in dropdown');
  assert.match(php,/href="logout\.php"/,'Logout must remain available');
  assert.doesNotMatch(php,/class="admin-user-menu"/,'duplicate exposed name/site/logout group must be removed');
  assert.match(css,/\.admin-topbar-actions\{margin-left:auto/,'right controls must group together');
  assert.match(css,/\.admin-profile-dropdown\{position:absolute/,'dropdown stays visually attached to avatar');
  assert.match(css,/@media\(max-width:480px\)/,'small screens must use a compact avatar');
  assert.match(endpoint,/sf_admin_require_auth\(\$method==='POST'\)/,'only authenticated Admin can read or upload a profile image');
  assert.match(endpoint,/move_uploaded_file\(\$tmp,\$staging\)/);
  assert.match(endpoint,/is_uploaded_file\(\$tmp\)/);
  assert.match(endpoint,/getimagesize\(\$tmp\)/);
  assert.match(endpoint,/FILEINFO_MIME_TYPE/);
  assert.match(endpoint,/Cache-Control: private, no-store/);
  assert.match(endpoint,/storage\/admin-profile-photos/,'photo must stay outside public web assets');
  assert.match(read('.htaccess'),/RewriteRule \^storage\/ - \[F,L,NC\]/,'storage stays blocked from direct web access');
}

async function interactionContract(){
  const elements={},events={},views=[],uploads=[];
  function element(id){
    return elements[id]={id,hidden:false,disabled:false,value:'',files:[],src:'',listeners:{},attrs:{},classList:{toggle(name,flag){this[name]=flag}},
      addEventListener(name,listener){this.listeners[name]=listener},
      setAttribute(name,value){this.attrs[name]=value},
      focus(){this.focused=true},
      click(){this.clicked=true}};
  }
  const shell=element('#adminProfileShell'),toggle=element('#adminProfileToggle'),menu=element('#adminProfileDropdown');
  const change=element('#adminProfileChangePhoto'),input=element('#adminProfilePhotoInput'),status=element('#adminProfilePhotoStatus');
  const avatar=element('#adminHeaderAvatar'),largeAvatar=element('#adminProfileAvatar');
  menu.hidden=true;status.hidden=true;
  shell.contains=target=>[shell,toggle,menu,change,input].includes(target);
  const context={
    $:id=>elements[id],document:{addEventListener:(type,cb)=>{events[type]=cb}},
    openView:view=>views.push(view),Date,
    FormData:class{constructor(){this.values=[]}append(key,value){this.values.push([key,value])}},
    uploadApi:async(route,body)=>{uploads.push({route,body});return {ok:true,avatar_url:'api/profile-photo.php?v=new-photo'}}
  };
  vm.createContext(context);
  vm.runInContext(extract(read('admin/assets/admin.js'),'bindAdminProfileMenu')+';bindAdminProfileMenu();',context);
  toggle.listeners.click();
  assert.equal(menu.hidden,false,'avatar opens the menu');
  assert.equal(toggle.attrs['aria-expanded'],'true');
  events.pointerdown({target:{}});
  assert.equal(menu.hidden,true,'outside pointer closes the menu');
  toggle.listeners.click();
  const viewTarget={closest:selector=>selector==='button[data-profile-view]'?{dataset:{profileView:'settings'}}:null};
  menu.listeners.click({target:viewTarget});
  assert.equal(views[0],'settings','Admin Settings opens an actual Admin view');
  assert.equal(menu.hidden,true);assert.equal(toggle.focused,true);
  toggle.listeners.click();let escapePrevented=false;
  events.keydown({key:'Escape',preventDefault:()=>{escapePrevented=true}});
  assert.equal(escapePrevented,true);assert.equal(menu.hidden,true,'Escape closes the dropdown');
  toggle.listeners.click();change.listeners.click();
  assert.equal(input.clicked,true,'Change Photo opens the file picker');
  input.files=[{type:'image/svg+xml',size:2000,name:'unsafe.svg'}];
  await input.listeners.change();
  assert.equal(uploads.length,0,'client rejects unsupported file type');
  assert.equal(status.classList.error,true);
  input.files=[{type:'image/png',size:12000,name:'portrait.png'}];
  await input.listeners.change();
  assert.equal(uploads.length,1,'photo is uploaded once');
  assert.equal(uploads[0].route,'profile-photo.php');
  assert.equal(uploads[0].body.values[0][0],'photo');
  assert.equal(avatar.src,'api/profile-photo.php?v=new-photo');
  assert.equal(largeAvatar.src,avatar.src,'header and dropdown images stay in sync');
  assert.equal(change.disabled,false,'upload control recovers after request');
  assert.equal(status.classList.error,false);
}

Promise.resolve().then(shellContract).then(interactionContract)
  .then(()=>console.log('Section 90Y Admin profile: responsive header, navigation, upload, keyboard dismissal and security checks PASS'))
  .catch(error=>{console.error(error);process.exitCode=1});
