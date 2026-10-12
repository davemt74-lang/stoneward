const CACHE='stonefellow-static-v1.3.39';
const SHELL=['offline.html','assets/css/site.css','assets/js/app.js','assets/js/library.js','assets/icons/stonefellow-pwa.svg'];
const scope=()=>self.registration.scope;
const abs=p=>new URL(p,scope()).href;
self.addEventListener('install',event=>{event.waitUntil((async()=>{const c=await caches.open(CACHE);for(const p of SHELL){try{await c.add(new Request(abs(p),{cache:'reload'}))}catch(_){}}await self.skipWaiting()})())});
self.addEventListener('activate',event=>{event.waitUntil((async()=>{for(const k of await caches.keys())if(k!==CACHE&&k.startsWith('stonefellow-static-'))await caches.delete(k);await self.clients.claim()})())});
self.addEventListener('message',event=>{if(event.data?.type==='SKIP_WAITING')self.skipWaiting()});
function isStatic(url){return url.origin===self.location.origin&&(/\/assets\/css\/site\.css$/.test(url.pathname)||/\/assets\/js\/(app|library)\.js$/.test(url.pathname)||/\/assets\/icons\//.test(url.pathname))}
self.addEventListener('fetch',event=>{
  const req=event.request;if(req.method!=='GET')return;const url=new URL(req.url);
  if(req.mode==='navigate'){event.respondWith(fetch(req).catch(async()=>await caches.match(abs('offline.html'))||new Response('Stonefellow is offline.',{status:503,headers:{'Content-Type':'text/plain'}})));return}
  if(!isStatic(url))return;
  event.respondWith((async()=>{const c=await caches.open(CACHE),cached=await c.match(req);const network=fetch(req).then(async r=>{if(r.ok&&r.type==='basic'&&!/no-store/i.test(r.headers.get('Cache-Control')||''))await c.put(req,r.clone());return r}).catch(()=>null);return cached||(await network)||new Response('',{status:504})})());
});
