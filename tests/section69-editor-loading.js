'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const src=fs.readFileSync(path.join(__dirname,'../assets/js/turntable-parts.js'),'utf8');
const css=fs.readFileSync(path.join(__dirname,'../assets/css/turntable-builder.css'),'utf8');
const api=fs.readFileSync(path.join(__dirname,'../api/turntable-modules.php'),'utf8');
assert.match(css,/\.turntable-editor-dialog\{position:fixed;inset:0!important;margin:0!important;/);
assert.match(css,/width:100vw!important;max-width:100vw!important;height:100dvh!important/);
assert.match(css,/\.turntable-editor-workspace\{display:grid;grid-template-columns/);
assert.match(css,/@media\(max-width:1023px\)\{\.turntable-editor-preview\{display:none!important/);
assert.match(src,/\[data-player-preview\]/);
assert.match(src,/setTimeout\(\(\)=>controller\.abort\(\),8000\)/);
assert.match(api,/sf_turntable_catalog\(\)/);
assert.match(api,/catch \(Throwable \$e\)/);
const from=src.indexOf('  const BUILTIN_MODULES='),to=src.indexOf('  function sideForTrack(',from);
assert.ok(from>0&&to>from);
let fetches=0,rerenders=0;
const keys={base:'base-studio',platter:'platter-classic',arm:'arm-studio',label:'label-classic',effect:'effect-none'};
const sandbox={DEFAULTS:keys,window:{STONEFELLOW_CONFIG:{apiBase:'api'}},AbortController,Promise,setTimeout,clearTimeout,
 fetch:async()=>{fetches++;return {ok:false,status:500,json:async()=>{throw new SyntaxError('bad JSON')}};},
 renderPartList(){rerenders++;},drawTrack(){}};
vm.runInNewContext(src.slice(from,to)+'\nthis.api={load,getModules,defaults,invalidate,getState:()=>({catalogLoaded,catalogLoading,catalogError})};',sandbox);
(async()=>{
 assert.equal(sandbox.api.getModules().length,5,'Free modules available before server response');
 assert.deepEqual(Object.keys(sandbox.api.defaults()).sort(),Object.keys(keys).sort());
 await assert.rejects(()=>sandbox.api.load(),/invalid JSON/);
 assert.equal(sandbox.api.getModules().length,5,'Free modules remain after an error');
 assert.equal(sandbox.api.getState().catalogLoading,false,'Loading flag clears after error');
 assert.match(sandbox.api.getState().catalogError,/invalid JSON/);
 assert.ok(rerenders>=1,'Error triggers UI refresh');
 sandbox.fetch=async()=>({ok:true,status:200,json:async()=>({ok:true,defaults:keys,modules:[{id:'base-studio',slot:'base',price_cents:0},{id:'base-premium',slot:'base',price_cents:1200}],owned:Object.values(keys)})});
 await sandbox.api.load();
 assert.equal(sandbox.api.getModules().length,2);
 assert.equal(sandbox.api.getState().catalogLoaded,true);
 assert.equal(sandbox.api.getState().catalogLoading,false);
 assert.ok(fetches>=1);
 console.log('Section 69 fullscreen/catalog recovery: 15/15 checks green');
})().catch(e=>{console.error(e);process.exitCode=1});
