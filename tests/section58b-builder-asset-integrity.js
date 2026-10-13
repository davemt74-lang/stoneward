'use strict';
/* Section 58B — verify mixed-version deployments recover, with no cache-by-query bypass. */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const root=require('node:path').join(__dirname,'..');
const sw=fs.readFileSync(root+'/service-worker.js','utf8');
const integrity=fs.readFileSync(root+'/assets/js/build-integrity.js','utf8');
const app=fs.readFileSync(root+'/assets/js/app.js','utf8');
const stylesheet=fs.readFileSync(root+'/assets/css/turntable-builder.css','utf8');
const html=fs.readFileSync(root+'/stonefellow-v120.php','utf8');
const versions={html:/define\('SF_BUILD', '([^']+)'\)/.exec(html)?.[1],script:/STONEFELLOW_APP_BUILD='([^']+)'/.exec(app)?.[1],
  css:/--stonefellow-builder-css-build:'([^']+)'/.exec(stylesheet)?.[1],serviceWorker:/const BUILD='([^']+)'/.exec(sw)?.[1]};
assert.ok(Object.values(versions).every(v=>v==='1.3.63'),JSON.stringify(versions));
assert.ok(html.includes('assets/js/build-integrity.js?v=<?=rawurlencode(SF_BUILD)?>'));
assert.ok(!sw.includes('c.match(req,{ignoreSearch:true})'));
console.log('PASS expected versions agree across HTML / JavaScript / CSS / offline worker');
class MockResponse {
  constructor(body,status=200){this.body=body;this.status=status;this.ok=status>=200&&status<300;this.type='basic';this.headers={get:()=>''};}
  clone(){return new MockResponse(this.body,this.status)}
  async text(){return this.body}
}
let offline=false;
const stored=new Map();
const cache={put:async(request,result)=>stored.set(request.url,result.clone()),match:async request=>stored.get(request.url),add:async request=>stored.set(request.url,new MockResponse('PRECACHED'))};
const caches={open:async()=>cache,keys:async()=>['stonefellow-static-v1.3.58','stonefellow-static-v1.3.61'],delete:async()=>true,match:async()=>null};
const handlers={};
const self={location:{origin:'https://stonefellow.test'},registration:{scope:'https://stonefellow.test/'},
  addEventListener:(name,cb)=>handlers[name]=cb,skipWaiting:async()=>{},clients:{claim:async()=>{}}};
const context={self,caches,URL,Request,Response:MockResponse,fetch:async req=>{if(offline)throw Error('offline');return new MockResponse('FRESH '+req.url)}};
vm.runInNewContext(sw,context,{filename:'service-worker.js'});
async function handled(url){
  let output=null;
  handlers.fetch({request:new Request(url),respondWith:p=>{output=p}});
  assert.ok(output,'Worker should intercept static assets');
  return await(await output).text();
}
(async()=>{
  const endpoint='https://stonefellow.test/assets/js/app.js?v=1.3.63';
  stored.set(endpoint,new MockResponse('STALE CODE'));
  assert.equal(await handled(endpoint),'FRESH '+endpoint,'network must win over stale cache');
  assert.equal(await(await cache.match(new Request(endpoint))).text(),'FRESH '+endpoint,'fresh response should overwrite cache');
  offline=true;
  assert.equal(await handled(endpoint),'FRESH '+endpoint,'only exact current key should work offline');
  const older='https://stonefellow.test/assets/js/app.js?v=1.3.58';
  assert.equal(await handled(older),'Static asset unavailable offline.','older cache key must not satisfy new asset');
  assert.match(sw,/const shellUrl=p=>/);
  assert.ok(sw.includes("'/assets/css/turntable-builder.css'"),'Expected static stylesheet covered');
  console.log('PASS version-exact cache keys, network priority, and offline fallback');

  let reloads=0,cleared=[],unregistered=0,banner=null;
  const session=new Map();
  function makeDOM(){return {
    baseURI:'https://stonefellow.test/',
    body:{appendChild:node=>{banner=node}},
    documentElement:{},querySelector:sel=>sel==='meta[name="stonefellow-build"]'?{content:'1.3.63'}:null,
    createElement:tag=>({tag,style:{},children:[],setAttribute(){},addEventListener(name,fn){this.listeners={...(this.listeners||{}),[name]:fn}},append(...nodes){this.children.push(...nodes)}})};
  }
  const cssStyle={getPropertyValue:()=>"'1.3.63'"};
  const browser={ST0:false,STONEFELLOW_APP_BUILD:'1.3.58',location:{reload:()=>reloads++},
    caches:{keys:async()=>['stonefellow-static-v1.3.58','unrelated-data'],delete:async key=>{cleared.push(key);return true}}};
  const browserCtx={window:browser,document:makeDOM(),getComputedStyle:()=>cssStyle,
    navigator:{serviceWorker:{getRegistrations:async()=>[{active:{scriptURL:'https://stonefellow.test/service-worker.js'},unregister:async()=>{unregistered++;return true}},
         {active:{scriptURL:'https://stonefellow.test/other-app/service-worker.js'},unregister:async()=>{throw Error('wrong unregister')}}]}},
    sessionStorage:{getItem:key=>session.get(key)||null,setItem:(key,val)=>session.set(key,val)},console,URL};
  vm.runInNewContext(integrity,browserCtx,{filename:'build-integrity.js'});
  // Promise chain includes service worker unregister + cache cleanup + reload
  await new Promise(resolve=>setImmediate(resolve));
  assert.ok(banner?.id==='stonefellowAssetMismatch');
  assert.equal(reloads,1);
  assert.equal(unregistered,1);
  assert.deepEqual(cleared,['stonefellow-static-v1.3.58']);
  console.log('PASS stale app auto-repairs only Stonefellow static cache and registration');

  let cleanBanner=null;
  vm.runInNewContext(integrity,{window:{STONEFELLOW_APP_BUILD:'1.3.63'},document:{
    querySelector:()=>({content:'1.3.63'}),documentElement:{},body:{appendChild:el=>{cleanBanner=el}},createElement(){throw Error('no banner on clean build')}},
    getComputedStyle:()=>cssStyle,console},{filename:'build-integrity.js'});
  assert.equal(cleanBanner,null);
  console.log('PASS matching app+CSS build renders normally without disrupting user data');
  console.log('Section 58B cache recovery: 4/4 checks passed');
})().catch(error=>{console.error(error);process.exitCode=1});
