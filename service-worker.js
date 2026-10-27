/* Stonefellow 1.3.157: version-exact, network-first static assets.
   Cache-first with ignoreSearch was serving stale app.js with new CSS after deployment. */
const BUILD='1.3.157';
const CACHE='stonefellow-static-v1.3.157';
const SHELL=['offline.html','assets/css/site.css','assets/css/turntable-builder.css','assets/css/artist-world.css','assets/js/turntable-parts.js','assets/js/conversation-barge-in.js','assets/js/speech-segments.js','assets/js/app.js','assets/js/build-integrity.js','assets/js/artist-home-renderer.js','assets/js/library.js','assets/js/owned-music.js','assets/js/agent-brain.js','assets/js/agent-chat-canvas.js','assets/icons/stonefellow-pwa.svg'];
const scope=()=>self.registration.scope;
const abs=p=>new URL(p,scope()).href;
const shellUrl=p=>(p.startsWith('assets/css/')||p.startsWith('assets/js/'))?abs(p)+'?v='+BUILD:abs(p);
self.addEventListener('install',event=>{event.waitUntil((async()=>{
  const c=await caches.open(CACHE);
  for(const p of SHELL){try{await c.add(new Request(shellUrl(p),{cache:'reload'}))}catch(_){/* Precache best-effort; network-first fetch will recover. */}}
  await self.skipWaiting();
})())});
self.addEventListener('activate',event=>{event.waitUntil((async()=>{
  for(const k of await caches.keys())if(k!==CACHE&&k.startsWith('stonefellow-static-'))await caches.delete(k);
  await self.clients.claim();
})())});
self.addEventListener('message',event=>{if(event.data?.type==='SKIP_WAITING')self.skipWaiting()});
function isStatic(url){
  if(url.origin!==self.location.origin)return false;
  const path=url.pathname;
  return ['/assets/css/site.css','/assets/css/turntable-builder.css','/assets/css/artist-world.css',
    '/assets/js/app.js','/assets/js/conversation-barge-in.js','/assets/js/speech-segments.js','/assets/js/build-integrity.js','/assets/js/turntable-parts.js',
    '/assets/js/artist-home-renderer.js','/assets/js/library.js','/assets/js/owned-music.js',
    '/assets/js/agent-brain.js','/assets/js/agent-chat-canvas.js'].some(p=>path.endsWith(p))
    ||path.includes('/assets/icons/');
}
self.addEventListener('fetch',event=>{
  const req=event.request;if(req.method!=='GET')return;const url=new URL(req.url);
  if(req.mode==='navigate'){
    event.respondWith(fetch(req).catch(async()=>await caches.match(abs('offline.html'))||new Response('Stonefellow is offline.',{status:503,headers:{'Content-Type':'text/plain'}})));
    return;
  }
  if(!isStatic(url))return;
  event.respondWith((async()=>{
    const c=await caches.open(CACHE);
    // Fetch current version first, bypassing HTTP cache. Never allow an older ?v= to
    // satisfy a newer URL; only exact same-version responses are usable offline.
    try{
      const r=await fetch(new Request(req,{cache:'reload'}));
      if(r.ok&&r.type==='basic'&&!/no-store/i.test(r.headers.get('Cache-Control')||''))await c.put(req,r.clone());
      return r;
    }catch(_){
      return (await c.match(req))||new Response('Static asset unavailable offline.',{status:504,headers:{'Content-Type':'text/plain'}});
    }
  })());
});
