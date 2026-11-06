(() => {
  'use strict';
  const defaultLimit=200000;
  const loopTypes=new Set(['WhileStatement','DoWhileStatement','ForStatement','ForInStatement','ForOfStatement']);
  const functionTypes=new Set(['FunctionDeclaration','FunctionExpression','ArrowFunctionExpression']);
  const randomId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');

  function directiveEnd(body,fallback){
    let position=fallback;
    for(const statement of body){
      if(statement.type!=='ExpressionStatement'||typeof statement.directive!=='string')break;
      position=statement.end;
    }
    return position;
  }

  /** Original source remains intact. Insertions are used only by the isolated preview. */
  function instrument(source,{limit=defaultLimit}={}){
    if(typeof source!=='string'||new TextEncoder().encode(source).length>12000)
      throw new TypeError('Choose a saved canvas program for preview.');
    if(!Number.isSafeInteger(limit)||limit<1000||limit>1000000)throw new TypeError('Choose a valid preview operation allowance.');
    if(typeof window.acorn?.parse!=='function')throw new Error('The local JavaScript parser is unavailable.');
    const tree=window.acorn.parse(source,{ecmaVersion:'latest',sourceType:'script',allowHashBang:true});
    const nodes=[];const names=new Set();const pending=[{node:tree,depth:0}];
    while(pending.length){
      const entry=pending.pop();const node=entry.node;
      if(node.type==='WithStatement'){
        const error=new Error('Dynamic scope prevents the preview guard from verifying this program.');
        error.code='sf_preview_unsupported';
        throw error;
      }
      nodes.push(entry);
      if(node.type==='Identifier')names.add(node.name);
      for(const value of Object.values(node)){
        if(Array.isArray(value)){
          for(const child of value)if(child&&typeof child.type==='string')pending.push({node:child,depth:entry.depth+1});
        }else if(value&&typeof value.type==='string')pending.push({node:value,depth:entry.depth+1});
      }
    }
    let helper;
    do{helper='__sfPreviewCheck_'+randomId();}while(names.has(helper));
    const slot='sf-preview-check-'+randomId();
    const event='sf-preview-budget-'+randomId();
    const changes=[];let sites=0;
    const insert=(position,text,depth=0,closing=false)=>changes.push({position,text,depth,closing});
    const call=helper+'();';
    for(const {node,depth} of nodes){
      if(loopTypes.has(node.type)){
        const body=node.body;
        if(body.type==='BlockStatement')insert(body.start+1,call,depth);
        else{
          insert(body.start,'{'+call,depth);
          insert(body.end,'}',depth,true);
        }
        sites++;
      }
      if(functionTypes.has(node.type)){
        const body=node.body;
        if(body.type==='BlockStatement')insert(directiveEnd(body.body,body.start+1),';'+call,depth);
        else{
          insert(body.start,'('+helper+'(),',depth);
          insert(body.end,')',depth,true);
        }
        sites++;
      }
    }
    // The trusted probe creates this function before authored global declarations run.
    const prefix=';const '+helper+'=this['+JSON.stringify(slot)+'];delete this['+JSON.stringify(slot)+'];\n';
    insert(directiveEnd(tree.body,tree.body[0]?.start??source.length),prefix,-1);
    changes.sort((a,b)=>b.position-a.position||Number(a.closing)-Number(b.closing)||a.depth-b.depth);
    let transformed=source;
    for(let index=0;index<changes.length;){
      const position=changes[index].position;const group=[];
      while(index<changes.length&&changes[index].position===position)group.push(changes[index++]);
      group.sort((a,b)=>{
        if(a.closing!==b.closing)return a.closing?-1:1;
        return a.closing?b.depth-a.depth:a.depth-b.depth;
      });
      transformed=transformed.slice(0,position)+group.map(change=>change.text).join('')+transformed.slice(position);
    }
    // Reparse to catch insertion conflicts before any candidate is mounted.
    window.acorn.parse(transformed,{ecmaVersion:'latest',sourceType:'script',allowHashBang:true});
    const bootstrap=`(()=>{
      const allowance=${limit};
      const timer=setTimeout.bind(window),dispatch=dispatchEvent.bind(window),NativeEvent=Event,NativeError=Error,define=Object.defineProperty;
      let remaining=allowance,scheduled=false,reported=false;
      const check=()=>{
        if(!scheduled){scheduled=true;timer(()=>{remaining=allowance;scheduled=false;reported=false;},0);}
        if(remaining-->0)return;
        if(!reported){reported=true;dispatch(new NativeEvent(${JSON.stringify(event)}));}
        const error=new NativeError('Canvas preview exceeded its synchronous operation allowance.');
        define(error,'code',{value:'sf_preview_budget'});
        throw error;
      };
      define(window,${JSON.stringify(slot)},{value:check,configurable:true});
    })();`;
    return Object.freeze({js:transformed,bootstrap,event,helper,limit,sites,guarded:true});
  }

  async function prepare(source,options){
    const result=instrument(source,options);
    const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(result.js));
    const hash="'sha256-"+btoa(String.fromCharCode(...new Uint8Array(digest)))+"'";
    return Object.freeze({...result,hash});
  }

  window.SFCanvasPreviewGuard=Object.freeze({instrument,prepare,defaultLimit});
})();
