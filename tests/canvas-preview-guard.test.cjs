const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {webcrypto}=require('node:crypto');
const root=path.resolve(__dirname,'..');
let checks=0;

function check(value,message){assert.ok(value,message);checks++;process.stdout.write('PASS '+message+'\n');}
function context(){
  const target=new EventTarget();
  const scope={crypto:webcrypto,TextEncoder,Event,Error,setTimeout,clearTimeout,btoa,
    dispatchEvent:event=>target.dispatchEvent(event),addEventListener:(...args)=>target.addEventListener(...args)};
  scope.window=scope;
  const result=vm.createContext(scope);
  for(const name of ['vendor/acorn.js','canvas-preview-guard.js'])
    vm.runInContext(fs.readFileSync(path.join(root,'assets/js',name),'utf8'),result);
  return result;
}
function run(source,limit=200000){
  const scope=context();const guard=scope.SFCanvasPreviewGuard.instrument(source,{limit});
  let exhausted=0;scope.addEventListener(guard.event,()=>exhausted++);
  vm.runInContext(guard.bootstrap,scope);
  let error=null;
  try{vm.runInContext(guard.js,scope,{timeout:2000});}catch(value){error=value;}
  return {scope,guard,error,get exhausted(){return exhausted;}};
}

const cases=[
  ['"use strict";var answer=(function(){return this===undefined})();',true,'program strict directives retain their effect'],
  ['function strict(){"use strict";return this===undefined;}var answer=strict();',true,'function checks follow their directive prologue'],
  ['var answer=0;for(var index=0;index<5;index++)answer+=index;',10,'non-block for bodies retain their iteration result'],
  ['var answer=0,index=0;while(index++<4)answer+=index;',10,'non-block while bodies retain their iteration result'],
  ['var answer=0;do answer++;while(answer<4);',4,'do-while bodies retain their iteration result'],
  ['var answer="";for(const key in {a:1,b:2})answer+=key;for(const value of [3,4])answer+=value;', 'ab34','for-in and for-of iteration order is retained'],
  ['var answer=0;outer:for(let a=0;a<4;a++)for(let b=0;b<4;b++){answer++;continue outer;}',4,'labeled continue still targets the original outer loop'],
  ['var answer=0;outer:for(let a=0;a<4;a++)while(true){answer++;break outer;}',1,'labeled break still exits its original loop'],
  ['var answer=(value=>({sum:value+1}))(4).sum;',5,'concise arrow object expressions retain their return value'],
  ['var answer=(first=>second=>first+second)(3)(4);',7,'nested concise arrows retain their lexical values'],
  ['var answer=Array.from((function*(){for(let index=0;index<4;index++)yield index;})()).join(",");','0,1,2,3','generator functions retain their yielded sequence'],
  ['class Parent{value(){return 4;}}class Child extends Parent{constructor(){super();}value(){return super.value()+2;}}var answer=new Child().value();',6,'constructor checks preserve super and class method behavior'],
  ['var object={get value(){return this.saved||3},set value(value){this.saved=value}};object.value=9;var answer=object.value;',9,'getters and setters retain their receiver'],
  ['var answer=(()=>{"use strict";return this})()===this;',true,'block arrows retain their lexical this'],
  ['#!/usr/bin/env node\nvar answer=3;',3,'hashbang position is preserved ahead of inserted declarations'],
  ['var answer="while(true){} // for (;;)".length>0; /* function f(){} */',true,'comments and string contents are parsed without false guard sites'],
  ['const window="private local";function Error(){return 8}var answer=Error()+window.length;',21,'authored global names do not replace the trusted bootstrap references'],
  ['var answer=0;for(let index=0;index<4;index++)if(index%2)answer++;else answer+=2;',6,'wrapping a single statement preserves dangling-else behavior'],
];
for(const [source,expected,message] of cases){const result=run(source);if(result.error)throw result.error;check(result.scope.answer===expected,message);}

for(const source of ['while(true){}','for(;;);','function spin(){return spin();}spin();','const spin=()=>spin();spin();']){
  const result=run(source,1000);
  check(result.error?.code==='sf_preview_budget'&&result.exhausted===1,'tight loops and recursion stop at the counted preview allowance');
}
const caught=run('var answer="";try{while(true){}}catch(error){answer=error.code;}',1000);
check(!caught.error&&caught.scope.answer==='sf_preview_budget'&&caught.exhausted===1,'caught guard failures still publish incomplete verification evidence');
const first=context().SFCanvasPreviewGuard.instrument('var answer=1;');
const second=context().SFCanvasPreviewGuard.instrument('var answer=1;');
check(first.helper!==second.helper&&first.event!==second.event,'guard references are unpredictable for each preview');
check(first.guarded&&first.limit===200000&&first.js.includes('var answer=1;'),'instrumentation retains the original program text and declares its operation allowance');
assert.throws(()=>context().SFCanvasPreviewGuard.instrument('while('),error=>error.name==='SyntaxError');
check(true,'invalid source syntax is rejected before mounting a candidate');
for(const source of ['with({value:1}){value;}',
  'with(new Proxy(Object.create(Boolean),{has:Boolean,get:Object.getPrototypeOf})){while(true){}}']){
  assert.throws(()=>context().SFCanvasPreviewGuard.instrument(source),error=>error.code==='sf_preview_unsupported');
  check(true,'dynamic with scopes cannot intercept the private preview guard');
}

(async()=>{
  const result=run('var answer=0;function work(){for(let index=0;index<600;index++)answer++;}work();setTimeout(()=>{work();answer+=1},0);',1000);
  await new Promise(resolve=>setTimeout(resolve,20));
  check(!result.error&&result.scope.answer===1201&&result.exhausted===0,'the trusted timer restores the allowance between JavaScript turns');
  const asyncResult=run('var answer=null;(async value=>await Promise.resolve(value+1))(4).then(value=>{answer=value});');
  await new Promise(resolve=>setTimeout(resolve,0));
  check(asyncResult.scope.answer===5,'async concise arrows retain awaited return values');
  const prepared=await context().SFCanvasPreviewGuard.prepare('var answer=1;');
  check(/^'sha256-[A-Za-z0-9+/]{43}='$/.test(prepared.hash),'the exact transformed program receives its own CSP hash');
  process.stdout.write('Canvas preview guard: '+checks+' checks passed\n');
})().catch(error=>{console.error(error);process.exitCode=1;});
