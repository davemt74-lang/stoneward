/* Resolve approved, exact library pins on the host before launching the opaque canvas. */
(()=>{
  'use strict';
  const scriptURL=document.currentScript?.src;

  async function resolve(locks,{signal}={}){
    const base=scriptURL?new URL('../../',scriptURL):new URL('./',document.baseURI);
    if(base.origin!==location.origin)throw new Error('Invalid canvas library root.');
    const options={credentials:'same-origin',mode:'same-origin',redirect:'error',cache:'no-store',signal};
    const response=await fetch(new URL('api/canvas-libraries.php',base),options);
    if(!response.ok)throw new Error('Canvas library inventory is unavailable.');
    const manifest=await response.json();
    if(manifest?.ok!==true||!Array.isArray(manifest.libraries))throw new Error('Invalid canvas library inventory.');

    return Object.freeze(await Promise.all(locks.map(async lock=>{
      const pin=manifest.libraries.find(item=>item?.id===lock.id&&item.version===lock.version&&item.sha256===lock.sha256);
      const path='assets/vendor/canvas/'+lock.id+'.js';
      if(!pin||pin.path!==path||!Number.isSafeInteger(pin.bytes)||pin.bytes<1||pin.bytes>2*1024*1024)
        throw new Error('The requested canvas library pin is unavailable.');
      const bundle=await fetch(new URL(path,base),options);
      if(!bundle.ok||Number(bundle.headers.get('content-length'))>pin.bytes)throw new Error('Canvas library source is unavailable.');
      const body=await bundle.arrayBuffer();
      if(body.byteLength!==pin.bytes)throw new Error('Canvas library size does not match.');
      const digest=await crypto.subtle.digest('SHA-256',body);
      if(btoa(String.fromCharCode(...new Uint8Array(digest)))!==lock.sha256)throw new Error('Canvas library hash does not match.');
      const source=new TextDecoder('utf-8',{fatal:true,ignoreBOM:true}).decode(body);
      // The bytes must survive HTML script parsing unchanged for the exact CSP hash to match.
      if(/^\uFEFF|[\r\x00]|<\/script|<!--/i.test(source))throw new Error('Canvas library cannot be inlined safely.');
      return Object.freeze({source,hash:"'sha256-"+lock.sha256+"'"});
    })));
  }

  window.SFCanvasLibraryRuntime=Object.freeze({resolve});
})();
