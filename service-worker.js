/* Stonefellow 1.3.312: version-exact, network-first static assets.
   Cache-first with ignoreSearch was serving stale app.js with new CSS after deployment. */
const BUILD='1.3.312';
const CACHE='stonefellow-static-v1.3.312';
const SHELL=['offline.html','assets/js/vendor/acorn.js','assets/js/canvas-preview-guard.js','assets/js/canvas-scene-tester.js','assets/js/canvas-build-workflow.js','assets/js/canvas-gallery.js','assets/css/canvas-gallery.css','assets/js/canvas-resource-picker.js','assets/js/canvas-data-picker.js','assets/css/canvas-resource-picker.css','assets/js/canvas-projects-ui.js','assets/css/canvas-projects.css','assets/vendor/canvas/color-thief.js','assets/js/media-artwork-palette.js','assets/js/canvas-library-runtime.js','assets/js/canvas-agent-theme.js','assets/js/canvas-music-picker.js','assets/css/canvas-music-picker.css','assets/js/canvas-audio-sdk.js','assets/js/canvas-location.js','assets/js/canvas-image-analysis.js','assets/css/canvas-image-analysis.css','assets/js/canvas-image-review.js','assets/css/canvas-image-review.css','assets/css/site.css','assets/css/agent-song-capture.css','assets/css/public-agent-canvas.css','assets/css/fan-vocal-studio.css','assets/css/song-moments.css','assets/images/agent-atmosphere-ember.webp','assets/images/agent-atmosphere-lunar.webp','assets/css/turntable-builder.css','assets/css/artist-world.css','assets/js/turntable-parts.js','assets/js/conversation-barge-in.js','assets/js/speech-segments.js','assets/js/agent-audio-player-skill.js','assets/js/agent-song-capture-skill.js','assets/js/agent-song-catalog-listening.js','assets/js/fan-vocal-studio.js','assets/js/song-moments.js','assets/js/agent-song-experience-ui.js','assets/js/public-agent-canvas.js','assets/js/player-playback-lifecycle.js','assets/js/app.js','assets/js/build-integrity.js','assets/js/artist-home-renderer.js','assets/js/library.js','assets/js/owned-music.js','assets/js/agent-brain.js','assets/js/agent-chat-canvas.js','assets/icons/stonefellow-pwa.svg'];
const scope=()=>self.registration.scope;
const abs=p=>new URL(p,scope()).href;
const shellUrl=p=>(p.startsWith('assets/css/')||p.startsWith('assets/js/')||p.startsWith('assets/vendor/'))?abs(p)+'?v='+BUILD:abs(p);
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

function jukeboxNotificationUrl(value) {
  if (typeof value !== 'string' || value.length > 300) return null;
  try {
    const url = new URL(value, scope());
    const page = new URL('jukebox.php', scope());
    const entries = [...url.searchParams];
    if (url.origin !== page.origin || url.pathname !== page.pathname || url.username || url.password || url.hash ||
        entries.length !== 1 || entries[0][0] !== 'id' || !/^[a-f0-9]{32}$/.test(entries[0][1])) return null;
    page.searchParams.set('id', entries[0][1]);
    return page;
  } catch {
    return null;
  }
}

self.addEventListener('push', event => {
  let payload;
  try { payload = event.data?.json(); } catch { return; }
  const url = jukeboxNotificationUrl(payload?.url);
  if (!url || typeof payload.title !== 'string' || !payload.title.trim() || typeof payload.body !== 'string') return;
  const sessionTag = 'jukebox:' + url.searchParams.get('id');
  const eventTag = typeof payload.tag === 'string' && payload.tag.startsWith(sessionTag + ':')
    && /^[a-z0-9:]{1,50}$/.test(payload.tag.slice(sessionTag.length + 1)) ? payload.tag : sessionTag;
  event.waitUntil(self.registration.showNotification(payload.title.slice(0, 100), {
    body: payload.body.slice(0, 240),
    icon: abs('assets/icons/stonefellow-pwa-192.png'),
    tag: 'stonefellow-' + eventTag,
    data: {url: url.href}
  }));
});

self.addEventListener('notificationclick', event => {
  const url = jukeboxNotificationUrl(event.notification.data?.url);
  if (!url) return;
  event.notification.close();
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
    for (const client of windows) {
      if (jukeboxNotificationUrl(client.url)?.href === url.href) {
        try { await client.focus(); return; } catch { /* The tab may have closed. */ }
      }
    }
    await self.clients.openWindow(url.href);
  })());
});

function isStatic(url){
  if(url.origin!==self.location.origin)return false;
  const path=url.pathname;
  return ['/assets/js/vendor/acorn.js','/assets/js/canvas-preview-guard.js','/assets/js/canvas-scene-tester.js','/assets/js/canvas-build-workflow.js','/assets/js/canvas-gallery.js','/assets/css/canvas-gallery.css','/assets/js/canvas-resource-picker.js','/assets/js/canvas-data-picker.js','/assets/css/canvas-resource-picker.css','/assets/js/canvas-projects-ui.js','/assets/css/canvas-projects.css','/assets/vendor/canvas/color-thief.js','/assets/js/media-artwork-palette.js','/assets/js/canvas-library-runtime.js','/assets/js/canvas-agent-theme.js','/assets/js/canvas-music-picker.js','/assets/css/canvas-music-picker.css','/assets/js/canvas-audio-sdk.js','/assets/js/canvas-location.js','/assets/js/canvas-image-analysis.js','/assets/css/canvas-image-analysis.css','/assets/js/canvas-image-review.js','/assets/css/canvas-image-review.css','/assets/css/site.css','/assets/css/agent-song-capture.css','/assets/css/public-agent-canvas.css','/assets/css/fan-vocal-studio.css','/assets/css/song-moments.css','/assets/css/turntable-builder.css','/assets/css/artist-world.css',
    '/assets/js/app.js','/assets/js/public-agent-canvas.js','/assets/js/fan-vocal-studio.js','/assets/js/song-moments.js','/assets/js/player-playback-lifecycle.js','/assets/js/conversation-barge-in.js','/assets/js/speech-segments.js','/assets/js/agent-audio-player-skill.js','/assets/js/agent-song-capture-skill.js','/assets/js/agent-song-catalog-listening.js','/assets/js/build-integrity.js','/assets/js/turntable-parts.js',
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
