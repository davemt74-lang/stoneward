/* Section 91B12A — floating particle agent, based on the approved public prototype.
 * Presentation only: actual capture, transcript and reports are supplied by
 * StonefellowSongExperience. This module never records or uploads audio.
 */
(() => {
  'use strict';
  let current = null;
  const by = (root, selector) => root.querySelector(selector);
  const reduced = () => !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const songMomentKinds = Object.freeze({ story: 'Story', photo: 'Photo', comment: 'Listener comment' });
  const sceneCatalogTypes = Object.freeze({
    album:{label:'Album'},albums:{label:'Albums',action:'album',button:'View album'},
    merch:{label:'Merch',action:'merch',button:'View item'},
    shows:{label:'Shows',action:'show',button:'View show'},
    tickets:{label:'Tickets',action:'tickets',button:'View tickets'}
  });
  const spacePlanets = [
    {x:.21,y:.34,smallY:.19,size:.096,light:'#ffdbac',body:'#a36383',shadow:'#292243',ring:'253,198,161',tilt:-.28},
    {x:.79,y:.31,smallY:.21,size:.071,light:'#d7f5ff',body:'#547ac7',shadow:'#191d49',ring:'158,208,255',tilt:.35},
    {x:.72,y:.56,smallY:.60,size:.035,light:'#e6d2ff',body:'#856aa9',shadow:'#272249'}
  ];
  const sceneCodingLabels = Object.freeze({
    generating:'Generating the listening room…',
    streaming:'Receiving live model output…',
    ready:'Listening-room source ready.',
    error:'The listening room could not be generated.',
    cancelled:'Listening-room request cancelled.'
  });
  const sceneTag = /^(?:\/(?:section|article|header|footer|div|span|p|em|strong|h[1-3]|ul|ol|li|figure|figcaption|small|details|summary)>|(?:section|article|header|footer|div|span|p|em|strong|h[1-3]|ul|ol|li|figure|figcaption|small|details|summary|hr|br)(?: class="[a-zA-Z0-9_ -]{1,160}")?\s*\/?\s*>)/;
  const sceneAudioVariables = /\bvar\(\s*--sf-audio-(?:bass|mid|treble|level)(?=\s*[,\)])/;
  // Host bridges and authored JavaScript run only under hashes matching their exact source.
  const sceneAudioBridge = `(()=>{
  const names=['--sf-audio-bass','--sf-audio-mid','--sf-audio-treble','--sf-audio-level'];
  addEventListener('message',event=>{
    if(event.source!==parent)return;
    const levels=event.data?.stonefellowSceneAudio;
    if(!Array.isArray(levels)||levels.length!==names.length||levels.some(level=>!Number.isFinite(level)||level<0||level>1))return;
    for(let i=0;i<names.length;i++)document.documentElement.style.setProperty(names[i],String(levels[i]));
  });
})();`;
  const sceneAudioHash = "'sha256-ZjlkG1HA7krMUYXPwI20LZFU/DU/Netuv2Psjwrc2vc='";
  const scenePlayerSlot = '<div class="sf-host-playback"></div>';
  const sceneProgramRunner = `(()=>{
  const program=document.getElementById('sfCanvasProgram');
  const script=document.createElement('script');
  script.textContent=JSON.parse(program.textContent);
  program.remove();
  document.body.appendChild(script);
})();`;
  const sceneProgramHash = "'sha256-Xwi3qY9t97sS3/7KMvdGD/UyIqoonXDEGC/ge37UYaI='";
  const sceneTrackBridge = `(()=>{
  let revision=null;
  addEventListener('message',event=>{
    const state=event.data?.stonefellowSceneTrackState;
    if(event.source===parent&&Number.isSafeInteger(state?.revision))revision=state.revision;
  });
  document.addEventListener('click',event=>{
    const button=event.target.closest?.('button[data-sf-play-track]');
    if(!event.isTrusted||!button||revision===null)return;
    parent.postMessage({stonefellowSceneTrackAction:button.dataset.sfPlayTrack,revision},'*');
  });
})();`;
  const sceneTrackHash = "'sha256-xOZDZuxAbFeAsBdLXjsyCP66Zwpi63Az0JMQPn6zrpA='";
  const sceneImageBridge = `(()=>{
  const mounted=new Set();
  addEventListener('message',event=>{
    const images=event.data?.stonefellowSceneImages;
    if(event.source!==parent||!Array.isArray(images))return;
    for(const {id,title,blob} of images){
      if(!/^(?:ref-)?[a-f0-9]{32}$/.test(id)||mounted.has(id)||!(blob instanceof Blob))continue;
      const url=URL.createObjectURL(blob);
      mounted.add(id);
      document.documentElement.style.setProperty('--sf-image-'+id,'url("'+url+'")');
      for(const slot of document.querySelectorAll('.sf-image-'+id)){
        if(slot.children.length||slot.textContent.trim())continue;
        const image=document.createElement('img');
        image.className='sf-scene-image';
        image.alt=title;
        image.src=url;
        slot.append(image);
      }
    }
  });
})();`;
  const sceneImageHash = "'sha256-dEjqLzW68DmNPqEW05cX4lveoQPgYtgyB++pFy6gjak='";
  const sceneImageId = /^(?:ref-)?[a-f0-9]{32}$/;
  const sceneImageTypes = new Set(['image/png','image/webp','image/jpeg']);
  const sceneImageLimit = 4;
  const sceneImageMaxBytes = 16*1024*1024;
  const validSceneImageBlob=blob=>blob instanceof Blob&&sceneImageTypes.has(blob.type)&&blob.size>0&&blob.size<=sceneImageMaxBytes;
  function sceneImageAssets(scene){
    const assets=new Map();
    if(!Array.isArray(scene.image_assets))return assets;
    for(const asset of scene.image_assets){
      if(typeof asset?.id!=='string'||!sceneImageId.test(asset.id))continue;
      if(!(typeof scene.css==='string'&&scene.css.includes('--sf-image-'+asset.id))&&
        !(typeof scene.html==='string'&&scene.html.includes('sf-image-'+asset.id)))continue;
      assets.set(asset.id,typeof asset.title==='string'?asset.title.slice(0,180):'');
      if(assets.size===sceneImageLimit)break;
    }
    return assets;
  }
  function hasSceneCurrentTrackBinding(html,datasets){
    if(!datasets||!html.includes('sf-current-track'))return false;
    for(const match of html.matchAll(/<[a-z][a-z0-9]* class="([A-Za-z0-9_ -]{1,160})"\s*\/?\s*>/g)){
      const classes=match[1].split(' ');
      if(!classes.includes('sf-current-track'))continue;
      const ids=classes.flatMap(name=>name.match(/^sf-dataset-([a-z][a-z0-9_-]{0,63})$/)?.[1]??[]);
      const dataset=ids.length===1&&Object.hasOwn(datasets,ids[0])?datasets[ids[0]]:null;
      if(Array.isArray(dataset?.filters)&&dataset.filters.includes('track_id'))return true;
    }
    return false;
  }
  function bindSceneDatasets(html,datasets,currentTrackId,trackDatasets,includeLyricsData){
    if(!html.includes('sf-dataset-'))return html;
    const source=document.createElement('template');
    source.innerHTML=html;
    const marker='[class*="sf-dataset-"]';
    const lyrics=includeLyricsData?{declared:false,rows:new Set(),current_rows:new Set(),payload_truncated:false}:null;
    let remainingRows=240;
    for(const container of source.content.querySelectorAll(marker)){
      if(!source.content.contains(container))continue;
      const classes=Array.from(container.classList);
      const ids=classes.flatMap(name=>name.match(/^sf-dataset-([a-z][a-z0-9_-]{0,63})$/)?.[1]??[]);
      if(!ids.length)continue;
      const children=Array.from(container.children);
      const templates=children.filter(node=>node.classList.contains('sf-data-row'));
      const template=templates.length===1?templates[0]:null;
      const scoped=classes.includes('sf-current-track');
      const data=scoped?trackDatasets:datasets;
      const dataset=ids.length===1&&data&&Object.hasOwn(data,ids[0])?data[ids[0]]:null;
      const storyView=ids.length===1&&ids[0]==='published_stories';
      const lyricView=ids.length===1&&ids[0]==='published_lyrics';
      const fields=Object.entries(dataset?.fields??{}).filter(([name,type])=>
        /^[a-z][a-z0-9_-]{0,63}$/.test(name)&&(
          type==='text'||type==='number'||
          type==='long_text'&&(storyView&&(name==='summary'||name==='body_text')||lyricView&&name==='text')));
      const filters=classes.filter(name=>name.startsWith('sf-filter-'))
        .map(name=>name.match(/^sf-filter-([a-z][a-z0-9_-]{0,63}?)--([A-Za-z0-9_-]{1,80})$/));
      const filterFields=Array.isArray(dataset?.filters)?dataset.filters:[];
      const unavailable=dataset?.state==='unavailable';
      const sourceRows=scoped?(currentTrackId&&filterFields.includes('track_id')?dataset?.current_rows:null):dataset?.rows;
      const validTemplate=template&&!template.querySelector(marker);
      const scopeAllowed=!lyricView||scoped||!dataset?.requires_track_filter||filters.some(filter=>filter?.[1]==='track_id');
      const deliveryLimited=lyricView&&scopeAllowed&&dataset?.payload_truncated===true;
      const matchingRows=!unavailable&&scopeAllowed&&(validTemplate||lyrics&&lyricView)&&fields.length&&Array.isArray(sourceRows)
        ?sourceRows.filter(row=>row&&typeof row==='object'&&!Array.isArray(row)&&
          (!scoped||row.track_id===currentTrackId)&&filters.every(filter=>
          filter&&filterFields.includes(filter[1])&&Object.hasOwn(row,filter[1])&&row[filter[1]]!==null&&String(row[filter[1]])===filter[2]
        )):[];
      if(lyrics&&lyricView){
        lyrics.declared=true;
        lyrics.payload_truncated||=deliveryLimited;
        const programRows=scoped?lyrics.current_rows:lyrics.rows;
        for(const row of matchingRows)programRows.add(row);
      }
      const rows=validTemplate?matchingRows.slice(0,Math.min(120,remainingRows)):[];
      const renderLimited=(storyView||lyricView)&&validTemplate&&matchingRows.length>rows.length;
      const truncated=renderLimited||deliveryLimited;
      const deliveryStatus=deliveryLimited?'Lyrics reached the delivery limit. Filter by song, text or cue position to narrow the data.':'';
      if(truncated){
        const displayStatus=renderLimited?'Showing '+rows.length+' of '+matchingRows.length+(storyView?' matching stories.':' delivered lyric rows.'):'';
        const status=[displayStatus,deliveryStatus].filter(Boolean).join(' ');
        container.setAttribute('aria-description',status);
        container.setAttribute('title',status);
      }
      if(rows.length){
        const rendered=document.createDocumentFragment();
        rows.forEach((row,index)=>{
          const item=template.cloneNode(true);
          item.style.setProperty('--sf-row-index',String(index));
          item.style.setProperty('--sf-row-count',String(rows.length));
          const values=new Map();
          for(const [field,type] of fields){
            const value=Object.hasOwn(row,field)?row[field]:null;
            if(type==='number'&&Number.isFinite(value)){
              item.style.setProperty('--sf-value-'+field,String(value));
              values.set('sf-field-'+field,String(value));
            }else if(type==='text'&&typeof value==='string')values.set('sf-field-'+field,value.slice(0,300));
            else if(type==='long_text'&&typeof value==='string'){
              if(lyricView)values.set('sf-field-'+field,value);
              else{
                const limit=field==='summary'?640:4000;
                values.set('sf-field-'+field,Array.from(value.slice(0,limit*2)).slice(0,limit).join(''));
              }
            }
          }
          for(const target of item.querySelectorAll('[class*="sf-field-"]')){
            const field=Array.from(target.classList).find(name=>values.has(name));
            target.textContent=values.get(field)??'';
          }
          const action=dataset?.actions?.play_track;
          const trackId=action?.field==='track_id'&&typeof row.track_id==='string'&&/^[A-Za-z0-9_-]{1,160}$/.test(row.track_id)?row.track_id:'';
          if(trackId){
            for(const target of item.querySelectorAll('.sf-action-play-track')){
              if(target.parentElement.closest('.sf-action-play-track'))continue;
              const button=document.createElement('button');
              button.type='button';button.className=target.className;button.dataset.sfPlayTrack=trackId;
              button.setAttribute('aria-label','Play '+(typeof row.title==='string'?row.title:trackId));
              button.append(...target.childNodes);
              if(!button.textContent.trim())button.textContent='Play';
              target.replaceWith(button);
            }
          }
          rendered.append(item);
        });
        template.before(rendered);
        remainingRows-=rows.length;
      }
      const hasUnavailable=unavailable&&children.some(node=>node.classList.contains('sf-data-unavailable'));
      for(const node of children){
        if(node.classList.contains('sf-data-empty')&&(rows.length||hasUnavailable||truncated))node.remove();
        if(node.classList.contains('sf-data-unavailable')&&!unavailable)node.remove();
        if((storyView||lyricView)&&node.classList.contains('sf-data-truncated')){
          if(!truncated)node.remove();
          else{
            for(const field of node.querySelectorAll('.sf-field-rendered_count'))field.textContent=String(rows.length);
            for(const field of node.querySelectorAll('.sf-field-matching_count'))field.textContent=String(matchingRows.length);
            for(const field of node.querySelectorAll('.sf-field-delivery_status'))field.textContent=deliveryStatus;
          }
        }
      }
      for(const node of templates)node.remove();
    }
    if(lyrics?.declared){
      // The authored program receives complete matching public rows, including cues beyond the DOM mount budget.
      const data=document.createElement('script');
      data.type='application/json';data.id='sfCanvasLyrics';
      data.textContent=JSON.stringify({rows:[...lyrics.rows],current_rows:[...lyrics.current_rows],payload_truncated:lyrics.payload_truncated}).replace(/</g,'\\u003c');
      source.content.append(data);
    }
    return source.innerHTML;
  }
  function stripLegacyPlayer(html){
    if(typeof html!=='string'||!html.includes(scenePlayerSlot))return html;
    const template=document.createElement('template');template.innerHTML=html;
    for(const slot of template.content.querySelectorAll('div[class="sf-host-playback"]:empty')){
      const parent=slot.parentElement;
      const sole=parent?.tagName==='DIV'&&parent.children.length===1&&!parent.textContent.trim();
      if(sole)parent.remove();else slot.remove();
    }
    return template.innerHTML;
  }
  function sceneScript(scene){
    const js=scene.js??'';
    if(js==='')return {js:'',hash:'',sdk:null};
    const sdk=window.SFCanvasAudioSDK;
    if(typeof js!=='string'||new TextEncoder().encode(js).length>12000||
      typeof scene.js_hash!=='string'||!/^[A-Za-z0-9+/]{43}=$/.test(scene.js_hash)||
      typeof sdk?.source!=='string'||typeof sdk.createHost!=='function'||!/^'sha256-[A-Za-z0-9+/]{43}='$/.test(sdk.hash))return null;
    return {js,hash:"'sha256-"+scene.js_hash+"'",sdk};
  }
  function sceneDocument(html, css, scrollable, reactive, images, datasets, currentTrackId='', trackDatasets=null,script=null,probe=null) {
    if(typeof html!=='string'||!html.trim()||html.length>12000||typeof css!=='string'||css.length>12000||/[<\\]/.test(css))return null;
    // Treat every '<' as the start of a known tag. Text may contain escaped
    // entities, but authored elements can have only a class, never a URL.
    if(html.split('<').slice(1).some(part=>!sceneTag.test(part)))return null;
    const bound=bindSceneDatasets(script?.js?html:stripLegacyPlayer(html),datasets,currentTrackId,trackDatasets,!!script?.js);
    const trackActions=bound.includes('data-sf-play-track=');
    const scriptHashes=[reactive?sceneAudioHash:'',images?sceneImageHash:'',trackActions?sceneTrackHash:'',script?.js?script.sdk.hash:'',script?.js?sceneProgramHash:'',script?.hash,probe?.hash].filter(Boolean);
    const scriptPolicy=scriptHashes.length?'; script-src '+scriptHashes.join(' '):'';
    return `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; connect-src 'none'; media-src blob:; img-src blob:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'${scriptPolicy}">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">
<style>html{color-scheme:dark}body{margin:0;color:#fff8ee;background:#151322;font:16px/1.5 system-ui,sans-serif}*{box-sizing:border-box}main{min-height:100vh;overflow-wrap:anywhere}:where(main>:first-child){padding:76px clamp(14px,3vw,48px) 0}</style>
${trackActions?'<style>:where(.sf-action-play-track){appearance:none;background:transparent;border:0;padding:0;text-align:inherit}</style>':''}
${images?'<style>:where(.sf-scene-image){display:block;max-width:100%;height:auto}</style>':''}
<style>${css}</style>
<style>html,body{overflow:${scrollable||trackActions||script?.js?'auto':'hidden'}!important}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}</style>
${trackActions?'<style>.sf-action-play-track{font:inherit;color:inherit;cursor:pointer;min-height:44px;min-width:44px}.sf-action-play-track:focus-visible{outline:2px solid currentColor;outline-offset:2px}</style>':''}
${probe?`<script>${probe.source}</script>`:''}${reactive?`<script>${sceneAudioBridge}</script>`:''}${images?`<script>${sceneImageBridge}</script>`:''}</head>
<body><main>${bound}</main>${trackActions?`<script>${sceneTrackBridge}</script>`:''}${script?.js?`<script${probe?' data-sf-data-offline="true"':''}>${script.sdk.source}</script><script type="application/json" id="sfCanvasProgram">${JSON.stringify(script.js).replace(/</g,'\\u003c')}</script><script>${sceneProgramRunner}</script>`:''}</body></html>`;
  }
  /** Detached candidates use the production renderer and receive no live canvas controller. */
  function prepareSceneTest(scene,{probe,previewProgram=null,currentTrackId=''}={}){
    if(!scene||!['custom','listening_room'].includes(scene.canvas_kind)||typeof scene.title!=='string'||!scene.title.trim()||
      !probe||typeof probe.source!=='string'||probe.source.length>18000||/<\/script/i.test(probe.source)||
      typeof probe.hash!=='string'||!/^'sha256-[A-Za-z0-9+/]{43}='$/.test(probe.hash))return null;
    const custom=scene.canvas_kind==='custom';
    let script=sceneScript(scene);
    if(!script||(!custom&&script.js))return null;
    if(previewProgram!==null){
      if(!custom||!script.js||previewProgram.guarded!==true||typeof previewProgram.js!=='string'||previewProgram.js.length>128000||
        typeof previewProgram.hash!=='string'||!/^'sha256-[A-Za-z0-9+/]{43}='$/.test(previewProgram.hash))return null;
      script={...script,js:previewProgram.js,hash:previewProgram.hash};
    }
    const disclosure=custom&&typeof scene.html==='string'&&/<details[\s>]/.test(scene.html)&&/<summary[\s>]/.test(scene.html);
    const reactive=custom&&!reduced()&&typeof scene.css==='string'&&sceneAudioVariables.test(scene.css);
    const imageAssets=custom?sceneImageAssets(scene):new Map();
    const dynamic=custom&&typeof scene.html==='string'&&hasSceneCurrentTrackBinding(scene.html,scene.datasets);
    const trackId=dynamic&&typeof currentTrackId==='string'&&/^[A-Za-z0-9_-]{1,180}$/.test(currentTrackId)?currentTrackId:'';
    const trackDatasets=trackId&&scene.dataset_track_id===trackId?scene.datasets:null;
    const source=sceneDocument(scene.html,scene.css,disclosure,reactive,imageAssets.size>0,custom?scene.datasets:null,trackId,trackDatasets,script,probe);
    if(!source)return null;
    const frame=document.createElement('iframe');
    frame.setAttribute('sandbox','allow-scripts');
    frame.setAttribute('allow',"autoplay 'none'; camera 'none'; microphone 'none'; geolocation 'none'; display-capture 'none'; fullscreen 'none'; payment 'none'; usb 'none'; serial 'none'; hid 'none'; clipboard-read 'none'; clipboard-write 'none'");
    frame.setAttribute('referrerpolicy','no-referrer');
    frame.setAttribute('tabindex','-1');
    frame.setAttribute('aria-hidden','true');
    frame.setAttribute('inert','');
    frame.title='Isolated canvas code check';
    frame.srcdoc=source;
    return Object.freeze({frame,script,imageAssets,reactive,currentTrackId:trackId});
  }
  /** Explicit human review uses the production sandbox without a private account bridge. */
  async function prepareGalleryPreview(scene){
    let program=null,source='';
    if(scene?.js){
      if(typeof window.SFCanvasPreviewGuard?.prepare!=='function')throw new Error('The preview guard is unavailable.');
      const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(scene.js));
      if(btoa(String.fromCharCode(...new Uint8Array(digest)))!==scene.js_hash)throw new Error('The saved source hash does not match.');
      program=await window.SFCanvasPreviewGuard.prepare(scene.js);source=program.bootstrap;
    }
    const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(source));
    const hash="'sha256-"+btoa(String.fromCharCode(...new Uint8Array(digest)))+"'";
    const prepared=prepareSceneTest(scene,{probe:{source,hash},previewProgram:program});
    if(!prepared)throw new Error('This canvas cannot be previewed with the supported renderer.');
    prepared.frame.removeAttribute('inert');prepared.frame.removeAttribute('aria-hidden');
    prepared.frame.setAttribute('tabindex','0');prepared.frame.title='Submitted canvas visual preview';
    return Object.freeze({...prepared,guarded:!!program});
  }
  function sceneTrackIds(source){
    if(!source.includes('data-sf-play-track='))return new Set();
    const template=document.createElement('template');template.innerHTML=source;
    return new Set(Array.from(template.content.querySelectorAll('button[data-sf-play-track]'),button=>button.dataset.sfPlayTrack)
      .filter(id=>/^[A-Za-z0-9_-]{1,160}$/.test(id)));
  }
  function sceneArtwork(source){
    if(typeof source!=='string'||!(/^api\/media\.php\?id=[a-zA-Z0-9_-]{1,80}(?:&variant=(?:medium|thumb))?$/.test(source)||
      /^assets\/(?:[a-z0-9_-]+\/)*[a-z0-9_-][a-z0-9_.-]*\.(?:png|jpe?g|gif|webp|avif)$/i.test(source)))return '';
    try{
      const url=new URL(source,document.baseURI);
      return url.origin===window.location.origin?url.href:'';
    }catch(_){return '';}
  }
  const catalogText = (value,limit=180) => typeof value==='string'?value.trim().slice(0,limit):'';
  function catalogId(value){
    if(Number.isSafeInteger(value)&&value>0)return String(value);
    return typeof value==='string'&&/^[A-Za-z0-9_-]{1,180}$/.test(value)?value:'';
  }
  function catalogPrice(row){
    if(!Number.isSafeInteger(row.price_cents)||row.price_cents<0||
      typeof row.currency!=='string'||!/^[A-Z]{3}$/.test(row.currency))return '';
    return new Intl.NumberFormat(undefined,{style:'currency',currency:row.currency}).format(row.price_cents/100);
  }
  const html = `
    <section class="sf-agent-landing" aria-label="Stonefellow interactive Agent Canvas">
      <canvas class="sf-agent-mesh" aria-hidden="true"></canvas>
      <div class="sf-agent-scene-backdrop" hidden></div>
      <section class="sf-agent-scene" aria-labelledby="sfAgentSceneTitle" hidden>
        <header class="sf-agent-scene-header">
          <img class="sf-agent-scene-art" alt="" width="36" height="36" hidden>
          <div>
            <h2 id="sfAgentSceneTitle" tabindex="-1"></h2>
            <small class="sf-agent-scene-signal" role="status" hidden></small>
          </div>
        </header>
        <div class="sf-agent-scene-actions">
          <button type="button" data-agent-action="play-scene-track">Play song</button>
          <button type="button" data-agent-action="open-scene-story" hidden>Open Song Moment</button>
          <span class="sf-agent-scene-footer-actions" hidden>
            <button type="button" data-agent-action="toggle-scene-code" aria-expanded="false" aria-controls="sfAgentCodingRail" hidden>Code</button>
            <button type="button" data-agent-action="feedback-scene">Feedback</button>
            <button type="button" data-agent-action="canvas-access" hidden>Canvas access</button>
            <details class="sf-agent-scene-image-picker" hidden>
              <summary data-agent-action="edit-scene-images">Edit images</summary>
              <ul aria-label="Images in this canvas"></ul>
            </details>
            <button type="button" class="sf-agent-scene-close" data-agent-action="close-scene" aria-label="Close canvas">Close canvas</button>
          </span>
        </div>
      </section>
      <aside class="sf-agent-scene-catalog" aria-label="From the Stonefellow catalog" hidden></aside>
      <aside class="sf-agent-page-rail" hidden inert role="dialog" aria-modal="true" aria-label="Stonefellow page workspace">
        <header class="sf-agent-page-head">
          <div><small>STONEFELLOW / LIVE WORKSPACE</small><strong class="sf-agent-page-title">PAGE</strong></div>
          <button type="button" class="sf-agent-page-lyric-start" data-agent-action="new-lyric-section" hidden>New section</button>
          <button type="button" data-agent-action="close-page" aria-label="Close page and return to Stonefellow Agent">×</button>
        </header>
        <div class="sf-agent-page-viewport" tabindex="0" aria-label="Page content">
          <div id="sfAgentNativePage" class="sf-agent-page-content"></div>
        </div>
      </aside>
      <div class="sf-agent-floater" data-mode="idle" aria-label="Floating Stonefellow agent; drag to move">
        <div class="sf-agent-visual">
          <div class="sf-agent-presence">
          <div class="sf-agent-aura" aria-hidden="true"></div>
          <canvas class="sf-agent-cloud" aria-hidden="true"></canvas>
          <div class="sf-agent-ring" aria-hidden="true"></div>
          <div class="sf-agent-orbit" aria-hidden="true"></div>
          <div class="sf-agent-orbit secondary" aria-hidden="true"></div>
          <button class="sf-agent-sphere" type="button" aria-label="Show Stonefellow agent controls; double click or double tap to start listening" aria-expanded="true">
            <canvas class="sf-agent-points" aria-hidden="true"></canvas>
            <span class="sf-agent-core-fallback" aria-hidden="true"></span>
          </button>
          </div>
          <div class="sf-agent-orbit-tools" role="group" aria-label="Agent orb actions">
            <button type="button" data-agent-action="listen-interpret" data-orbit-action="listen" data-tip="Song Lyrics" aria-label="Song Lyrics">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h8l4 4v12H6zM14 4v5h4M9 12h6m-6 4h6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" data-agent-action="capture" data-orbit-action="record" data-tip="Record Studio" aria-label="Open vocal recording studio and song clip recorder">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7.1" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="3.8" fill="currentColor"/></svg>
            </button>
            <button type="button" data-agent-action="show-player" data-orbit-action="player" data-tip="Music Player" aria-label="Show music player">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18V5l11-2v13" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6.5" cy="18" r="2.5" fill="currentColor"/><circle cx="17.5" cy="16" r="2.5" fill="currentColor"/></svg>
            </button>
            <button type="button" data-agent-action="song-moments" data-orbit-action="moments" data-tip="Song Moments &amp; Timeline" aria-label="Open song moments timeline">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.6" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M7 12h10M12 7v10" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.5"/><circle cx="17.8" cy="8" r="2.4" fill="currentColor"/></svg>
            </button>
            <button type="button" data-agent-action="show-queue" data-orbit-action="playlist" data-tip="Playlist &amp; Queue" aria-label="Open playlist and song queue">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h12M4 11h12M4 16h8M17 12v7a2 2 0 1 1-2-2c.7 0 1.5.2 2 .6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" data-agent-action="music-library" data-orbit-action="library" data-tip="Songs &amp; Albums" aria-label="Open music library with songs and albums">
              <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="16" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M13 5l5-1 3 16-5 1zM7 8v8M16 8l2 9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>
            <button type="button" data-agent-action="vinyl-builder" data-orbit-action="vinyl" data-tip="Vinyl Record Builder" aria-label="Open vinyl record builder">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="6.3" fill="none" stroke="currentColor" stroke-width=".9"/><circle cx="12" cy="12" r="4.3" fill="none" stroke="currentColor" stroke-width=".9"/><circle cx="12" cy="12" r="2.2" fill="none" stroke="currentColor" stroke-width="1.3"/><circle cx="12" cy="12" r=".7" fill="currentColor"/></svg>
            </button>
            <button type="button" data-agent-action="commerce" data-orbit-action="commerce" data-tip="Merch &amp; Tickets" aria-label="Open merch, tickets and shopping cart">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h16l-1 11H5L4 9ZM8 9V6a4 4 0 0 1 8 0v3M4 15h16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/></svg>
            </button>
            <button type="button" data-agent-action="scene-history" data-orbit-action="scene-history" data-tip="Canvas History" aria-label="Canvas History" hidden>
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14v11H5zM8 19h8M8 9h2m4 0h2m-8 3h8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" data-agent-action="start-guide" data-orbit-action="guide" data-tip="Guided Tour" aria-label="Start guided tour of the Agent Canvas">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9.5 9a2.7 2.7 0 0 1 5.2 1c0 2-2.7 2.1-2.7 4.2M12 17.8v.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
            <button type="button" data-agent-action="stop-listening" data-orbit-action="stop" data-tip="Stop recording" aria-label="Stop recording" hidden>
              <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6.5" y="6.5" width="11" height="11" rx="1.5" fill="currentColor"/></svg>
            </button>
          </div>
          <button class="sf-agent-song-cue" type="button" data-agent-action="open-song-moment" aria-haspopup="dialog" hidden>
            <span class="sf-agent-song-cue-spark" aria-hidden="true">✧</span>
            <span class="sf-agent-song-cue-copy"><span class="sf-agent-song-cue-stamp"></span><span class="sf-agent-song-cue-title"></span></span>
            <span class="sf-agent-song-cue-arrow" aria-hidden="true">›</span>
          </button>
          <span class="sf-agent-song-cue-status sr-only" role="status" aria-atomic="true"></span>
        </div>
        <div class="sf-agent-state" role="status">AGENT READY</div>
        <div class="sf-agent-lyric-controls" hidden aria-label="Lyric listening controls">
          <button type="button" data-agent-action="stop-lyrics">Stop</button>
        </div>
        <div class="sf-agent-live-feed" aria-label="Live transcription and Agent Chat">
          <div class="sf-agent-live-transcript-slot"></div>
          <div class="sf-agent-live-preview"><span class="sf-agent-live-label">AGENT CHAT</span><span class="sf-agent-live-message">Ready for a conversation</span></div>
          <button type="button" class="sf-agent-live-open" data-agent-action="chat" aria-label="Open Agent Chat" title="Open Agent Chat">↗</button>
        </div>
        <div class="sf-agent-welcome-actions" hidden aria-label="Start listening with Stonefellow"></div>
        <div class="sf-agent-dock-stack">
          <div id="sfAgentPlayerDock" class="sf-agent-player-dock" aria-label="Agent music player">
            <div class="sf-agent-player-ready">MUSIC / PLAYER READY</div>
          </div>
          <button type="button" class="sf-agent-chat-start" data-agent-action="start-chat" aria-label="Start voice chat with Stonefellow">◉ Start Voice Chat</button>
        </div>
        <aside class="sf-agent-panel" hidden aria-label="Active song lyric capture and analysis">
          <div class="sf-agent-panel-head"><span>LYRIC / CAPTURE · AGENT PROCESS</span><button type="button" data-agent-action="close-panel" aria-label="Hide session panel">×</button></div>
          <div class="sf-agent-session"></div>
        </aside>
      </div>
      <aside id="sfAgentCodingRail" class="sf-agent-coding" aria-label="Coding Agent" hidden>
        <header class="sf-agent-coding-head">
          <h2>Coding Agent</h2>
          <button type="button" data-agent-action="hide-scene-coding" aria-label="Hide Coding Agent">×</button>
        </header>
        <p class="sf-agent-coding-status" role="status" aria-atomic="true" tabindex="-1"></p>
        <p class="sf-agent-workflow-phase" hidden></p>
        <button type="button" class="sf-agent-coding-stop" data-agent-action="stop-scene-build" hidden>Stop Agent</button>
        <div class="sf-agent-workflow-tabs" role="tablist" aria-label="Canvas project workflow">
          <button type="button" role="tab" id="sfAgentProjectTab" aria-controls="sfAgentProjectPanel" aria-selected="false" tabindex="-1" data-workflow-tab="project">Project</button>
          <button type="button" role="tab" id="sfAgentCodingTab" aria-controls="sfAgentCodingPanel" aria-selected="true" data-workflow-tab="coding">Coding</button>
          <button type="button" role="tab" id="sfAgentTestingTab" aria-controls="sfAgentTestingPanel" aria-selected="false" tabindex="-1" data-workflow-tab="testing">Testing</button>
          <button type="button" role="tab" id="sfAgentRepairTab" aria-controls="sfAgentRepairPanel" aria-selected="false" tabindex="-1" data-workflow-tab="repair">Repair</button>
        </div>
        <div class="sf-agent-coding-body">
        <section id="sfAgentProjectPanel" class="sf-agent-workflow-panel" role="tabpanel" aria-labelledby="sfAgentProjectTab" data-workflow-panel="project" tabindex="0" hidden>
          <h3>Approved project</h3><p class="sf-agent-workflow-brief">No approved project has been supplied.</p>
          <dl class="sf-agent-workflow-project-meta"></dl>
        </section>
        <section id="sfAgentCodingPanel" class="sf-agent-workflow-panel" role="tabpanel" aria-labelledby="sfAgentCodingTab" data-workflow-panel="coding" tabindex="0">
        <div class="sf-agent-workflow-coding-log"></div>
        <section class="sf-agent-coding-image" aria-labelledby="sfAgentCodingImageHeading" hidden>
          <h3 id="sfAgentCodingImageHeading">Create Image</h3>
          <p class="sf-agent-coding-image-status" role="status" aria-atomic="true"></p>
          <progress class="sf-agent-coding-image-progress" aria-label="Image generation progress" hidden></progress>
          <ul class="sf-agent-coding-image-previews" role="list" aria-label="Generated images" hidden></ul>
        </section>
        <details class="sf-agent-coding-source" hidden>
          <summary>Scene source</summary>
          <div class="sf-agent-coding-code">
            <div class="sf-agent-coding-live" hidden>
              <h3>Live model output</h3><pre><code class="sf-agent-coding-raw"></code></pre>
            </div>
            <div class="sf-agent-coding-validated">
              <p class="sf-agent-coding-candidate-note" hidden>Candidate code · not applied</p>
              <h3>HTML</h3><pre><code class="sf-agent-coding-html"></code></pre>
              <h3>CSS</h3><pre><code class="sf-agent-coding-css"></code></pre>
              <section class="sf-agent-coding-javascript" hidden><h3>JavaScript</h3><pre><code class="sf-agent-coding-js"></code></pre></section>
            </div>
          </div>
        </details>
        </section>
        <section id="sfAgentTestingPanel" class="sf-agent-workflow-panel" role="tabpanel" aria-labelledby="sfAgentTestingTab" data-workflow-panel="testing" tabindex="0" hidden>
          <h3>Testing evidence</h3><div class="sf-agent-workflow-testing-log"><p>No testing evidence has been reported.</p></div>
        </section>
        <section id="sfAgentRepairPanel" class="sf-agent-workflow-panel" role="tabpanel" aria-labelledby="sfAgentRepairTab" data-workflow-panel="repair" tabindex="0" hidden>
          <h3>Repair activity</h3><div class="sf-agent-workflow-repair-log"><p>No repair activity has been reported.</p></div>
        </section>
        </div>
      </aside>
      <dialog class="sf-agent-coding-image-dialog" aria-label="Generated image preview">
        <header>
          <h2 class="sf-agent-coding-image-title"></h2>
          <form method="dialog"><button type="button" class="sf-agent-coding-image-edit" hidden>Edit image</button><button type="button" class="sf-agent-coding-image-replace" hidden>Replace image</button><button type="submit" autofocus>Close preview</button></form>
        </header>
        <img class="sf-agent-coding-image-full" alt="" decoding="async">
      </dialog>
      <section class="sf-agent-workspace" aria-label="Expanded Agent Canvas" aria-hidden="true" inert>
        <div class="sf-agent-workspace-inner">
          <div class="sf-agent-workspace-head"><strong>STONEFELLOW / AGENT CANVAS</strong><button type="button" data-agent-action="close-workspace">Close canvas ×</button></div>
          <div class="sf-agent-workspace-body">
            <h2>What shall we explore?</h2>
            <p>The agent can bring music, lyrics, analysis and conversation into this space. Your current session stays here when you expand the canvas.</p>
            <div class="sf-agent-expanded-session"></div>
            <div class="sf-agent-tools"><button type="button" data-agent-action="chat">Open Agent Chat</button><button type="button" data-agent-action="music">Explore music</button><button type="button" data-agent-action="listen-interpret">Song Lyrics</button><button type="button" data-agent-action="capture">Record Studio</button><button type="button" data-agent-action="stop-listening" hidden>Stop recording</button></div>
          </div>
        </div>
      </section>
    </section>`;

  function mount(target, options = {}) {
    unmount();
    if (!target) return false;
    if(document.head?.appendChild&&!document.querySelector?.('meta[data-sf-canvas-frame-policy]')){
      const policy=document.createElement('meta');policy.httpEquiv='Content-Security-Policy';policy.content="frame-src 'none'";
      policy.dataset.sfCanvasFramePolicy='1';document.head.appendChild(policy);
    }
    target.innerHTML = html;
    const root = by(target, '.sf-agent-landing');
    const floater = by(root, '.sf-agent-floater');
    const visual = by(root, '.sf-agent-visual');
    const orbitTools = by(root, '.sf-agent-orbit-tools');
    const sphere = by(root, '.sf-agent-sphere');
    const songMomentCue = by(visual, '.sf-agent-song-cue');
    const songMomentStamp = by(songMomentCue, '.sf-agent-song-cue-stamp');
    const songMomentTitle = by(songMomentCue, '.sf-agent-song-cue-title');
    const songMomentStatus = by(visual, '.sf-agent-song-cue-status');
    const songMomentsTool = by(orbitTools, '[data-orbit-action="moments"]');
    const status = by(root, '.sf-agent-state');
    const lyricControls = by(root, '.sf-agent-lyric-controls');
    const lyricStop = by(lyricControls, '[data-agent-action="stop-lyrics"]');
    const liveHost = by(root, '.sf-agent-live-feed');
    const liveSlot = by(root, '.sf-agent-live-transcript-slot');
    const liveMessage = by(root, '.sf-agent-live-message');
    const welcomeActions = by(root, '.sf-agent-welcome-actions');
    const sceneRoot = by(root, '.sf-agent-scene');
    const sceneTitle = by(sceneRoot, '#sfAgentSceneTitle');
    const sceneArt = by(sceneRoot, '.sf-agent-scene-art');
    const sceneBackdrop = by(root, '.sf-agent-scene-backdrop');
    const sceneCatalog = by(root, '.sf-agent-scene-catalog');
    const sceneSignal = by(sceneRoot, '.sf-agent-scene-signal');
    const sceneStory = by(sceneRoot, '[data-agent-action="open-scene-story"]');
    const sceneImagePicker = by(sceneRoot, '.sf-agent-scene-image-picker');
    const sceneImagePickerLabel = by(sceneImagePicker, 'summary');
    const sceneImageChoices = by(sceneImagePicker, 'ul');
    const sceneFooterActions = by(sceneRoot, '.sf-agent-scene-footer-actions');
    const sceneClose = by(sceneFooterActions, '[data-agent-action="close-scene"]');
    const sceneCode = by(sceneFooterActions, '[data-agent-action="toggle-scene-code"]');
    const sceneFeedback = by(sceneFooterActions, '[data-agent-action="feedback-scene"]');
    const sceneAccess = by(sceneFooterActions, '[data-agent-action="canvas-access"]');
    const sceneSocialLinks = document.querySelector?.('.chat-footer .social-links');
    if(sceneSocialLinks)sceneSocialLinks.appendChild(sceneFooterActions);
    const sceneHistory = by(root, '[data-agent-action="scene-history"]');
    const codingRoot = by(root, '.sf-agent-coding');
    const codingStatus = by(codingRoot, '.sf-agent-coding-status');
    const codingStop = by(codingRoot, '[data-agent-action="stop-scene-build"]');
    const codingBody = by(codingRoot, '.sf-agent-coding-body');
    const workflowTabs = Array.from(codingRoot.querySelectorAll('[data-workflow-tab]'));
    const workflowPanels = Array.from(codingRoot.querySelectorAll('[data-workflow-panel]'));
    const workflowPhase = by(codingRoot, '.sf-agent-workflow-phase');
    const workflowBrief = by(codingRoot, '.sf-agent-workflow-brief');
    const workflowMeta = by(codingRoot, '.sf-agent-workflow-project-meta');
    const workflowLogs = {coding:by(codingRoot,'.sf-agent-workflow-coding-log'),testing:by(codingRoot,'.sf-agent-workflow-testing-log'),repair:by(codingRoot,'.sf-agent-workflow-repair-log')};
    const codingCandidateNote = by(codingRoot, '.sf-agent-coding-candidate-note');
    const codingSource = by(codingRoot, '.sf-agent-coding-source');
    const codingScroller = by(codingSource, '.sf-agent-coding-code');
    const codingLive = by(codingSource, '.sf-agent-coding-live');
    const codingLiveTitle = by(codingLive, 'h3');
    const codingValidated = by(codingSource, '.sf-agent-coding-validated');
    const codingRaw = by(codingLive, '.sf-agent-coding-raw');
    const codingHtml = by(codingSource, '.sf-agent-coding-html');
    const codingCss = by(codingSource, '.sf-agent-coding-css');
    const codingJavascript = by(codingSource, '.sf-agent-coding-javascript');
    const codingJs = by(codingSource, '.sf-agent-coding-js');
    const codingImageSection=by(codingRoot,'.sf-agent-coding-image');
    const codingImageStatus=by(codingRoot,'.sf-agent-coding-image-status');
    const codingImageProgress=by(codingRoot,'.sf-agent-coding-image-progress');
    const codingImageList=by(codingRoot,'.sf-agent-coding-image-previews');
    const codingImageDialog=by(root,'.sf-agent-coding-image-dialog');
    const codingImageTitle=by(root,'.sf-agent-coding-image-title');
    const codingImageFull=by(root,'.sf-agent-coding-image-full');
    const codingImageEdit=by(codingImageDialog,'.sf-agent-coding-image-edit');
    const codingImageReplace=by(codingImageDialog,'.sf-agent-coding-image-replace');
    let listeningScene=null,spaceScene=null,reactiveScene=null,sceneAudioHost=null,sceneTracks=null,sceneTrackRevision=0;
    let sceneAgentDesign,sceneAgentRevealed=false,manualAgentHidden=options.initialAgentHidden===true,agentPalette=null;
    let codingCode=null,codingImageBatch=null,codingImageModalTrigger=null;
    let codingVisible=false,codingDismissed=false;
    let workflow=null,workflowTab='coding';
    const workflowRequests=new Set();
    const mesh = by(root, '.sf-agent-mesh');
    const cloud = by(root, '.sf-agent-cloud');
    const face = by(root, '.sf-agent-points');
    const workspace = by(root, '.sf-agent-workspace');
    const pageRail = by(root, '.sf-agent-page-rail');
    const pageTitle = by(root, '.sf-agent-page-title');
    const lyricNew = by(pageRail, '[data-agent-action="new-lyric-section"]');
    const pageViewport = by(root, '.sf-agent-page-viewport');
    const pageHost = by(root, '.sf-agent-page-content');
    const lyricHost=document.createElement('div');lyricHost.className='sf-agent-lyric-workspace';lyricHost.hidden=true;
    pageViewport.appendChild(lyricHost);
    let pageView='',baseMode='idle',baseLabel='';
    let sceneHistoryView=null;
    const sceneDeletions=new Map();
    // The page is a true modal over the *unchanged* Agent Canvas. Keeping it
    // under the stage clipped it below the fixed header on mobile, and making
    // the entire landing fixed obscured the canvas instead of layering it.
    const pagePortal=typeof document.createElement==='function'&&document.body?.appendChild
      ?document.createElement('div'):root;
    if(pagePortal!==root){
      pagePortal.className='sf-agent-landing sf-agent-modal-layer';
      pagePortal.hidden=true;
      pagePortal.appendChild(pageRail);
      document.body.appendChild(pagePortal);
    }
    const panel = by(root, '.sf-agent-panel');
    const playerDock = by(root, '.sf-agent-player-dock');
    const chatStart = by(root, '.sf-agent-chat-start');
    const smallSession = by(root, '.sf-agent-session');
    const bigSession = by(root, '.sf-agent-expanded-session');
    const contexts = [mesh, cloud, face].map(c => c.getContext('2d'));
    // The mesh lives behind the entire shell, including the transparent
    // header and footer, instead of being cropped at the stage boundary.
    if(document.body?.appendChild)document.body.appendChild(mesh);
    if (contexts.some(c => !c)) root.classList.add('no-canvas');
    const motionOff = reduced();
    const pointer = {x:0,y:0,driftX:0,driftY:0};
    const gaze = {x:0,y:0,targetX:0,targetY:0};
    const dim = {w:1,h:1,dpr:1};
    const particles = Array.from({length:110}, (_, i) => ({a:i*2.39996,r:110+(i%10)*13,v:.25+(i%6)*.08,s:i*1.37}));
    const stars = Array.from({length:110}, (_, i) => ({x:((i*73.413+11)%100)/100,y:((i*39.127+7)%100)/100,r:i%11===0?1.6:.65,phase:i*1.717}));
    const presets=Object.freeze({
      ember:{label:'Ember',rgb:[255,183,110],mesh:false},
      mesh:{label:'Blue Mesh',rgb:[139,204,255],mesh:true},
      violet:{label:'Violet Signal',rgb:[205,170,255],mesh:true},
      lunar:{label:'Moonlit Horizon',rgb:[219,228,244],mesh:false}
    });
    const atmosphereKey='stonefellow.agent.atmosphere.v1';
    let atmosphere='ember';
    const pointCount=window.matchMedia?.('(max-width: 720px)').matches?1500:2900;
    // Fibonacci sphere: every point belongs to the same kinetic orb,
    // with no eyes, mouth or flattened face mask.
    const orbPoints=Array.from({length:pointCount},(_,i)=>{
      const y=1-2*(i+.5)/pointCount,r=Math.sqrt(Math.max(0,1-y*y)),a=i*2.399963229728653;
      return {x:Math.cos(a)*r,y,z:Math.sin(a)*r,seed:i*.913};
    });
    let mode='idle', raf=0, destroyed=false, dragging=null, hovered=false, suppressClick=false;
    let frameSize=0,lastFrame=0,workspaceTimer=0,pageTimer=0,pageFrame=0,playerTimer=0,remotePanelDismissed=false;
    const roam = {step:0,fromX:0,fromY:0,toX:0,toY:0,start:0,delay:0};
    const spots = [[.20,.24],[.80,.18],[.66,.74],[.13,.68],[.83,.49],[.47,.35]];
    const on = (node,event,fn,opts) => { node?.addEventListener(event,fn,opts); return () => node?.removeEventListener(event,fn,opts); };
    const cleanup = [];
    // Spotlight the actual controls rather than rendering a duplicate tutorial UI.
    // The layer is outside the stage so it also reaches header/footer controls.
    const guideLayer=document.createElement('div');
    guideLayer.className='sf-agent-guide-layer';guideLayer.hidden=true;
    guideLayer.innerHTML='<div class="sf-agent-guide-focus" aria-hidden="true"></div><section class="sf-agent-guide-card" role="dialog" aria-modal="true" aria-label="Stonefellow guided tour"><div class="sf-agent-guide-kicker">STONEFELLOW / GUIDED TOUR <span data-guide-progress></span></div><h2 data-guide-title></h2><p data-guide-description></p><div class="sf-agent-guide-controls"><button type="button" data-guide-close>Close</button><button type="button" data-guide-prev>Back</button><button type="button" data-guide-next>Next →</button></div></section>';
    document.body.appendChild(guideLayer);
    const guideFocus=by(guideLayer,'.sf-agent-guide-focus'),guideCard=by(guideLayer,'.sf-agent-guide-card');
    const guideSteps=[
      {selector:'.sf-agent-sphere',title:'Meet your Stonefellow Agent',description:'I’m Stonefellow. Click or tap the orb to show its controls. Double click or double tap to start listening. Listening and playback keep running when the controls fade. You can also drag the orb around the canvas.'},
      {selector:'[data-orbit-action="listen"]',title:'Song Lyrics',description:'Select part of a playing song and tap Stop to retrieve its saved lyrics. Review the words before asking about their meaning and songwriter context.'},
      {selector:'[data-orbit-action="record"]',title:'Record Studio',description:'Open the recording slideout, choose a USB microphone or your phone mic, and sing over the song. Preview your vocal mix and download it locally. The original song-only Record Clip remains a separate tab.'},
      {selector:'[data-orbit-action="moments"]',title:'Song Moments & Timeline',description:'Open the song timeline to browse published moments. As the song plays, tap the timestamp near the orb to open a story, photo or approved listener comment while the music continues.'},
      {selector:'[data-orbit-action="playlist"]',title:'Playlist & Queue',description:'Open the right-side playlist drawer to see what is playing next. Make a mix, choose a song, or save a temporary playlist for later.'},
      {selector:'[data-orbit-action="library"]',title:'Songs & Albums',description:'Browse Stonefellow songs and albums without leaving the agent canvas. Select a song to play or ask me about a release.'},
      {spacePreview:true,title:'Space EQ',description:'This sample canvas responds to music already playing. Continue the tour or close it to return to your backdrop.'},
      {selector:'[data-orbit-action="commerce"]',title:'Merch & Tickets',description:'Explore merchandise, show tickets, and your cart in the right-side commerce drawer.'},
      {selector:'[data-orbit-action="guide"]',title:'Guided Tour',description:'This question-mark button brings you back to the tour whenever you want a refresher.'},
      {selector:'.sf-agent-welcome-actions:not([hidden]),.sf-agent-chat-start:not([hidden])',title:'Start Voice Chat',description:'Start Voice Chat uses your microphone to talk with Stonefellow. You can also choose the latest release or a random mix.'},
      {selector:'#notificationButton',title:'Notifications & Activity',description:'See new music, listening reminders, and other activity. Notifications have actions, such as playing an unfinished song.'},
      {selector:'#menuButton',title:'Your Menu',description:'Use the top-right menu for your settings and other account or site actions.'},
      {selector:'#chatFooterRestore:not([hidden]),#chatForm',title:'Chat Controls',description:'Your chat bar starts hidden on desktop and mobile. Use the Chat control to show it, type a question, or use your microphone. On desktop, zzz hides the bar again.'}
    ];
    let guideIndex=-1,guideTarget=null,guideSpaceScene=null;
    const guideVisible=node=>!!node&&!node.hidden&&node.getClientRects().length>0&&getComputedStyle(node).visibility!=='hidden';
    function updateGuideLayout(){
      if(guideIndex<0||guideLayer.hidden||!guideTarget)return;
      const r=guideTarget.getBoundingClientRect(),vw=window.innerWidth,vh=window.innerHeight,pad=7;
      guideFocus.style.left=Math.max(4,r.left-pad)+'px';guideFocus.style.top=Math.max(4,r.top-pad)+'px';
      guideFocus.style.width=Math.max(22,Math.min(vw,r.right+pad)-Math.max(4,r.left-pad))+'px';
      guideFocus.style.height=Math.max(22,Math.min(vh,r.bottom+pad)-Math.max(4,r.top-pad))+'px';
      const cardWidth=Math.min(330,vw-24),cardHeight=guideCard.offsetHeight||220;
      const below=r.bottom+22,above=r.top-cardHeight-22;
      guideCard.style.left=Math.max(12,Math.min(vw-cardWidth-12,r.left+r.width/2-cardWidth/2))+'px';
      guideCard.style.top=(below+cardHeight<vh-14?below:above>12?above:Math.max(12,vh-cardHeight-12))+'px';
    }
    function showGuideStep(index,direction=1){
      let i=index;
      while(i>=0&&i<guideSteps.length){
        const step=guideSteps[i];
        const target=step.spacePreview?root:document.querySelector(step.selector);
        if(guideVisible(target)){
          setGuideSpacePreview(!!step.spacePreview);
          guideIndex=i;guideTarget=guideSpaceScene?.canvas||target;
          by(guideLayer,'[data-guide-progress]').textContent=(i+1)+' / '+guideSteps.length;
          by(guideLayer,'[data-guide-title]').textContent=step.title;
          by(guideLayer,'[data-guide-description]').textContent=step.description;
          by(guideLayer,'[data-guide-prev]').disabled=i===0;
          by(guideLayer,'[data-guide-next]').textContent=i===guideSteps.length-1?'Finish ✓':'Next →';
          updateGuideLayout();
          options.onTourStep?.({...step,index:i,total:guideSteps.length});
          return;
        }
        i+=direction;
      }
      closeGuide();
    }
    function startGuide(){
      if(destroyed||root.dataset.sceneAgentDesign==='hidden')return;
      showPlayer();
      if(guideIndex>=0){showGuideStep(0);return;}
      options.onTourOpen?.();
      guideLayer.hidden=false;document.body.classList.add('sf-agent-guide-open');
      showGuideStep(0);
      by(guideLayer,'[data-guide-next]').focus({preventScroll:true});
    }
    function closeGuide(announce=true){
      if(guideIndex<0)return;
      guideIndex=-1;guideTarget=null;guideLayer.hidden=true;
      setGuideSpacePreview(false);
      document.body.classList.remove('sf-agent-guide-open');
      if(announce)options.onTourClose?.();
    }
    cleanup.push(on(guideLayer,'click',e=>{
      if(e.target.closest('[data-guide-close]'))closeGuide();
      else if(e.target.closest('[data-guide-prev]'))showGuideStep(guideIndex-1,-1);
      else if(e.target.closest('[data-guide-next]'))guideIndex===guideSteps.length-1?closeGuide():showGuideStep(guideIndex+1);
    }));
    cleanup.push(on(window,'resize',updateGuideLayout));
    cleanup.push(on(window,'scroll',updateGuideLayout,{passive:true}));
    cleanup.push(on(window,'keydown',e=>{
      if(guideIndex<0)return;
      if(e.key==='Escape'){e.preventDefault();e.stopImmediatePropagation();closeGuide();}
      if(e.key==='ArrowRight'){e.preventDefault();showGuideStep(guideIndex+1);}
      if(e.key==='ArrowLeft'&&guideIndex>0){e.preventDefault();showGuideStep(guideIndex-1,-1);}
      if(e.key==='Tab'){
        const buttons=Array.from(guideCard.querySelectorAll('button:not([disabled])'));
        const currentIndex=buttons.indexOf(document.activeElement);
        if(currentIndex<0||e.shiftKey&&currentIndex===0||!e.shiftKey&&currentIndex===buttons.length-1){e.preventDefault();buttons[e.shiftKey?buttons.length-1:0]?.focus();}
      }
    },true));
    const audio = options.audio || null;
    // Move the authoritative player between the orb and its original footer
    // slot. The audio element and its listeners stay with the same controls.
    const player = options.player || null;
    const originalPlayerParent = player?.parentNode || null;
    const originalPlayerNext = player?.nextSibling || null;
    function placePlayer(inFooter){
      if(!player||!originalPlayerParent)return null;
      const parent=inFooter?originalPlayerParent:playerDock;
      if(player.parentNode===parent)return null;
      const focused=player.contains(document.activeElement)?document.activeElement:null;
      if(inFooter){
        const before=originalPlayerNext?.parentNode===originalPlayerParent?originalPlayerNext:null;
        originalPlayerParent.insertBefore(player,before);
      }else playerDock.insertBefore(player,playerDock.firstChild);
      return focused;
    }
    function restorePlayerFocus(focused){
      if(!focused||player.hidden)return;
      focused.focus({preventScroll:true});
      if(document.activeElement!==focused)by(player,'#playerToggle')?.focus({preventScroll:true});
    }
    const initialPlayerFocus=placePlayer(manualAgentHidden);
    // Keep one authoritative transcript node; move it into the orb's live
    // feed only while this canvas is mounted, and restore it on navigation.
    const voiceTranscript = document.getElementById?.('voiceTranscript') || null;
    const originalTranscriptParent = voiceTranscript?.parentNode || null;
    const originalTranscriptNext = voiceTranscript?.nextSibling || null;
    if(voiceTranscript && originalTranscriptParent && liveSlot)liveSlot.appendChild(voiceTranscript);
    function syncTranscript(){
      if(!liveHost)return;
      const visible=!!voiceTranscript&&!voiceTranscript.hidden;
      if(visible)liveHost.classList.add('has-live-transcript');
      else liveHost.classList.remove('has-live-transcript');
    }
    function showConversation(role,text){
      if(!liveMessage||!['user','assistant'].includes(role))return;
      const value=String(text||'').trim();if(!value)return;
      liveMessage.textContent=(role==='user'?'You: ':'Stonefellow: ')+value.slice(0,180);
      liveHost.classList.add('has-conversation');
    }
    function setWelcomeState(next){
      if(!welcomeActions)return;
      welcomeActions.replaceChildren();
      const mode=next?.mode;
      welcomeActions.hidden=mode!=='name'&&mode!=='choices';
      if(mode==='name'){
        const form=document.createElement('form');
        form.className='sf-agent-guest-name-form';
        form.innerHTML='<label for="sfGuestName">WHAT SHOULD I CALL YOU?</label><div><input id="sfGuestName" name="guest_name" type="text" autocomplete="given-name" maxlength="48" placeholder="Your first name" aria-label="Your name" required><button type="submit">Continue →</button></div><div class="sf-agent-guest-options"><button type="button" class="sf-agent-guest-skip" data-agent-action="skip-name">Skip for now</button><button type="button" class="sf-agent-guest-skip" data-agent-action="start-guide">Guided Tour</button></div>';
        welcomeActions.appendChild(form);
      }else if(mode==='choices'){
        const caption=document.createElement('span');caption.className='sf-agent-welcome-kicker';caption.textContent='START LISTENING';
        const latest=document.createElement('button');latest.type='button';latest.dataset.agentAction=next.latestAction==='play'?'welcome-play':'latest-release';latest.textContent=next.latestAction==='play'?'▶ Play':'♫ Latest Release';
        const playlist=document.createElement('button');playlist.type='button';playlist.dataset.agentAction=next.playlistAction==='save'?'save-playlist':next.playlistAction==='saved'?'open-saved-queue':'random-playlist';playlist.textContent=next.playlistAction==='save'?'▣ Save Playlist':next.playlistAction==='saved'?'✓ Saved · View':'≋ Make a Playlist';
        const tour=document.createElement('button');tour.type='button';tour.dataset.agentAction='start-guide';tour.textContent='✧ Guided Tour';
        welcomeActions.append(caption,latest,playlist,tour);
      }
    }
    if(options.initialConversation)showConversation(options.initialConversation.role,options.initialConversation.text);
    syncTranscript();
    const PLAYER_IDLE_MS=5200;
    const agentChrome=[orbitTools,status,lyricControls,liveHost,welcomeActions,by(root,'.sf-agent-dock-stack'),panel,songMomentCue];
    let orbActivation=null,orbPointerType='',agentPointerOver=false;
    function syncOrbControlLabel(){
      sphere.setAttribute('aria-label','Show Stonefellow agent controls; double click or double tap to start listening');
      sphere.setAttribute('aria-expanded',String(!floater.classList.contains('is-chrome-collapsed')&&!visual.hidden));
      sphere.title='Click or tap to show controls · Double click or double tap to listen';
    }
    function setAgentChromeCollapsed(collapsed){
      floater.classList.toggle('is-chrome-collapsed',collapsed);
      floater.classList.toggle('is-engaged',!collapsed);
      playerDock.classList.toggle('is-collapsed',collapsed);
      for(const node of agentChrome)if(node)node.inert=collapsed;
      if(collapsed&&!agentPointerOver&&document.activeElement===sphere&&!sphere.matches?.(':focus-visible'))hovered=false;
      syncOrbControlLabel();
    }
    function pausePlayerHide(){window.clearTimeout(playerTimer);playerTimer=0;}
    function schedulePlayerHide(){
      pausePlayerHide();
      if(root.dataset.sceneAgentDesign==='hidden')return;
      playerTimer=window.setTimeout(hidePlayer,PLAYER_IDLE_MS);
    }
    function showPlayer(){
      setAgentChromeCollapsed(false);
      schedulePlayerHide();
    }
    function hidePlayer(){
      if(destroyed)return;
      const focused=document.activeElement;
      const interacting=agentPointerOver||
        (floater.contains?.(focused)&&(focused!==sphere||sphere.matches?.(':focus-visible')))||
        workspace.classList.contains('open')||guideIndex>=0||dragging||
        mode==='recording'||!lyricControls.hidden||!!options.isVocalRecording?.();
      if(interacting){schedulePlayerHide();return;}
      setAgentChromeCollapsed(true);
      pausePlayerHide();
    }
    function syncPlayer() {
      const docked=!!player&&!player.hidden&&player.parentNode===playerDock;
      const changed=docked!==playerDock.classList.contains('has-track');
      playerDock.classList[docked?'add':'remove']('has-track');
      playerDock.hidden=!docked;
      if(docked&&changed)showPlayer();
      if(!playerTimer&&!floater.classList.contains('is-chrome-collapsed'))schedulePlayerHide();
      syncListeningButtons();
      updateDock();
      refreshCanvasAudio();
      void syncSceneTrackData();
    }
    function syncVoice(){
      if(!chatStart)return;
      const active=!!options.isConversationActive?.()&&!options.needsVoiceGesture?.();
      chatStart.hidden=active;
      const resume=!!options.needsVoiceGesture?.();
      chatStart.textContent=resume?'◉ Resume Voice Chat':'◉ Start Voice Chat';
      chatStart.setAttribute('aria-label',resume?'Resume voice chat with Stonefellow':'Start voice chat with Stonefellow');
    }
    const playerObserver = player && typeof MutationObserver === 'function'
      ? new MutationObserver(syncPlayer) : null;
    playerObserver?.observe(player, {attributes:true,attributeFilter:['hidden']});
    function state(next,label) {
      const map={playing:'music',recording:'recording',listening:'listening',thinking:'thinking',speaking:'speaking',responding:'speaking',music:'music'};
      baseMode=next;baseLabel=label||'';
      const lyric=window.StonefellowSongExperience?.snapshot?.();
      const hearing=lyric?.listening_mode==='catalog'&&['starting','capturing','transcribing'].includes(lyric.phase);
      mode=hearing?(lyric.phase==='transcribing'?'thinking':'listening'):map[next] || 'idle';
      if(mode==='recording'){remotePanelDismissed=false;showPlayer();}
      floater.dataset.mode=mode;
      status.textContent=hearing?(lyric.phase==='starting'?'CHECKING SAVED LYRICS':
        lyric.phase==='capturing'?'SELECTING SONG SECTION':'LOADING SAVED LYRICS'):
        label || ({idle:'AGENT READY',music:'MUSIC PLAYING',listening:'LISTENING',thinking:'THINKING',speaking:'RESPONDING',recording:'CAPTURING LYRICS'}[mode]);
      syncOrbControlLabel();
      syncListeningButtons();
    }
    function positionOrbitTools(){
      if(!orbitTools)return;
      const visible=Array.from(orbitTools.querySelectorAll('button')).filter(button=>button.dataset.orbitAction!=='stop');
      const count=visible.length, radius=visual.offsetWidth*.5+(visual.offsetWidth>175?78:64);
      const spread=count<2?0:Math.min(168,56+(count-2)*42);
      visible.forEach((button,index)=>{
        const deg=-90+(count<2?0:-spread/2+spread*index/(count-1));
        const rad=deg*Math.PI/180;
        button.style.left='calc(50% + '+(Math.cos(rad)*radius).toFixed(1)+'px)';
        button.style.top='calc(50% + '+(Math.sin(rad)*radius).toFixed(1)+'px)';
      });
    }
    function canReviewLyrics(lyric){
      if(lyric?.listening_mode!=='catalog'||!['draft','report','analyzing'].includes(lyric.phase))return false;
      const trackId=typeof options.getTrackId==='function'?options.getTrackId():lyric.track_id;
      return !!trackId&&String(trackId)===String(lyric.track_id);
    }
    function syncListeningButtons() {
      const capturing=!!options.isCapturing?.();
      const lyric=window.StonefellowSongExperience?.snapshot?.();
      const lyricBusy=lyric?.listening_mode==='catalog'&&['starting','capturing','transcribing'].includes(lyric.phase);
      const reviewable=canReviewLyrics(lyric);
      const playable=!!(audio && (audio.currentSrc || audio.src) && !audio.paused);
      for(const action of ['listen-interpret'])for(const button of root.querySelectorAll(`[data-agent-action="${action}"]`)){
        button.hidden=capturing&&!lyricBusy;
        button.disabled=!lyricBusy&&!reviewable&&(!playable||capturing);
        button.setAttribute('aria-pressed',String(lyricBusy));
        button.title=!playable&&!lyricBusy&&!reviewable?'Play a Stonefellow song to see its lyrics.':'Song Lyrics';
      }
      // The studio must be accessible before a song plays so a listener can
      // connect a Focusrite and review settings without starting capture.
      for(const button of root.querySelectorAll('[data-agent-action="capture"]')){
        button.hidden=false;button.disabled=lyricBusy;button.title=lyricBusy?'Finish the lyric selection before opening Record Studio.':'Record Studio · Vocal overdub or song clip';
      }
      for(const button of root.querySelectorAll('[data-agent-action="stop-listening"]'))button.hidden=!capturing||!!options.isVocalRecording?.()||lyricBusy;
      lyricControls.hidden=!lyricBusy;
      lyricStop.hidden=!lyricBusy;
      lyricStop.textContent=lyric?.phase==='transcribing'?'Stop waiting':'Stop';
      lyricStop.title=lyric?.phase==='transcribing'?'Discard this saved lyric lookup.':'Finish this lyric selection';
      lyricNew.hidden=pageView!=='lyrics'||!reviewable||lyric.phase==='analyzing';
      lyricNew.disabled=!playable||capturing;
      for(const button of root.querySelectorAll('[data-orbit-action="player"]'))button.hidden=!player||player.hidden;
      // The playlist tool belongs to the ring even before a mix is created.
      for(const button of root.querySelectorAll('[data-orbit-action="playlist"]'))button.hidden=false;
      positionOrbitTools();
      const stopSlot=by(orbitTools,'[data-orbit-action="stop"]'),listenSlot=by(orbitTools,'[data-orbit-action="listen"]');
      if(stopSlot&&listenSlot){stopSlot.style.left=listenSlot.style.left;stopSlot.style.top=listenSlot.style.top;}
      if(capturing||lyricBusy)showPlayer();
    }
    function session() {
      const active=!!window.StonefellowSongExperience?.active?.();
      if (!active) { panel.hidden=true; smallSession.replaceChildren(); bigSession.replaceChildren(); updateDock(); return; }
      const lyric=window.StonefellowSongExperience?.snapshot?.();
      if(lyric?.listening_mode==='catalog'){
        panel.hidden=true;smallSession.replaceChildren();bigSession.replaceChildren();
        // Updates never reopen a dismissed rail or replace another native page.
        if(pageView==='lyrics'&&!pageRail.hidden&&!pageRail.hasAttribute('inert'))window.StonefellowSongExperience.mount(lyricHost);
        state(baseMode,baseLabel);
      }else{
        if (!remotePanelDismissed) panel.hidden=false;
        window.StonefellowSongExperience.mount(smallSession);
        window.StonefellowSongExperience.mount(bigSession);
      }
      updateDock();
      syncListeningButtons();
    }
    function updateDock() {
      const half=floater.offsetWidth*.5;
      const availableRight=root.clientWidth-(floater.offsetLeft+half);
      const availableLeft=floater.offsetLeft-half;
      const room=(panel.offsetWidth||365)+20;
      floater.dataset.side=root.clientWidth<1160?'below':
        availableRight>=room?'right':availableLeft>=room?'left':availableRight>=availableLeft?'right':'left';
      floater.dataset.vertical=floater.offsetTop>root.clientHeight*.55?'above':'below';
    }
    function constrainPosition(x,y) {
      const stage=root.getBoundingClientRect(),vw=window.innerWidth||root.clientWidth,vh=window.innerHeight||root.clientHeight;
      const visualRect=visual.getBoundingClientRect();
      const groupRect=floater.getBoundingClientRect();
      const scale=Math.max(.01,floater.offsetWidth?groupRect.width/floater.offsetWidth:1);
      const anchorX=visualRect.left+visualRect.width*.5,anchorY=visualRect.top+visualRect.height*.5;
      const localX=visual.offsetLeft+visual.offsetWidth*.5-floater.offsetWidth*.5;
      const localY=visual.offsetTop+visual.offsetHeight*.5-floater.offsetHeight*.5;
      const radius=visual.offsetWidth*.5+64;
      let left=localX-radius,right=localX+radius;
      let top=localY-radius,bottom=localY+radius;
      // Faded controls retain their footprint, so a reveal never jumps away
      // from the pointer between the first and second tap.
      const controls=[...orbitTools.querySelectorAll('button'),status,lyricControls,liveHost,welcomeActions,by(root,'.sf-agent-dock-stack')];
      for(const node of controls){
        if(!node||node.hidden)continue;
        const rect=node.getBoundingClientRect?.();
        if(!rect?.width||!rect.height)continue;
        left=Math.min(left,localX+(rect.left-anchorX)/scale);right=Math.max(right,localX+((rect.right??rect.left+rect.width)-anchorX)/scale);
        top=Math.min(top,localY+(rect.top-anchorY)/scale);bottom=Math.max(bottom,localY+((rect.bottom??rect.top+rect.height)-anchorY)/scale);
      }
      const header=document.querySelector?.('.topbar')?.getBoundingClientRect();
      const footer=document.querySelector?.('.chat-footer')?.getBoundingClientRect();
      const restore=document.querySelector?.('#chatFooterRestore')?.getBoundingClientRect();
      const safeTop=Math.max(12,header?.bottom||74)+12;
      const safeBottom=Math.min(vh-12,footer?.height&&footer.top>safeTop?footer.top-12:vh-12,
        restore?.width&&restore.height&&restore.top>safeTop?restore.top-12:vh-12);
      const minX=14-stage.left-left,maxX=vw-14-stage.left-right;
      const minY=safeTop-stage.top-top,maxY=safeBottom-stage.top-bottom;
      const xx=minX>maxX?(minX+maxX)*.5:Math.max(minX,Math.min(maxX,x));
      const yy=minY>maxY?(minY+maxY)*.5:Math.max(minY,Math.min(maxY,y));
      return {x:xx,y:yy};
    }
    function position(x,y) {
      const next=constrainPosition(x,y);
      floater.style.left=next.x+'px'; floater.style.top=next.y+'px'; updateDock();
    }
    function nextSpot() {
      const spot=spots[roam.step++%spots.length];
      return constrainPosition(root.clientWidth*(.08+.84*spot[0]),root.clientHeight*(.08+.84*spot[1]));
    }
    function wander(time) {
      const focused=document.activeElement;
      const controlFocus=floater.contains?.(focused)&&(focused!==sphere||sphere.matches?.(':focus-visible'));
      if (visual.hidden||!codingRoot.hidden||!panel.hidden||!lyricControls.hidden||motionOff||dragging||controlFocus||agentPointerOver||hovered||guideIndex>=0||codingImageDialog.open||workspace.classList.contains('open')||root.classList.contains('has-native-page')){
        roam.start=0;return;
      }
      if(!roam.start){roam.fromX=floater.offsetLeft||root.clientWidth*.64;roam.fromY=floater.offsetTop||root.clientHeight*.46;const n=nextSpot();roam.toX=n.x;roam.toY=n.y;roam.start=time;}
      if(time<roam.delay)return;
      const pct=Math.max(0,Math.min(1,(time-Math.max(roam.start,roam.delay))/10500));
      const eased=(1-Math.cos(Math.PI*pct))*.5;
      position(roam.fromX+(roam.toX-roam.fromX)*eased,roam.fromY+(roam.toY-roam.fromY)*eased);
      if(pct>=1){roam.fromX=roam.toX;roam.fromY=roam.toY;const n=nextSpot();roam.toX=n.x;roam.toY=n.y;roam.start=time;roam.delay=0;}
    }
    function fit(canvas,ctx) {
      if (!ctx)return;
      const rect=canvas.getBoundingClientRect(),dpr=Math.min(window.devicePixelRatio||1,2);
      canvas.width=Math.max(1,Math.round((canvas.clientWidth||rect.width)*dpr));canvas.height=Math.max(1,Math.round((canvas.clientHeight||rect.height)*dpr));
      ctx.setTransform(dpr,0,0,dpr,0,0);
    }
    function resize() {
      dim.w=Math.max(1,root.clientWidth);dim.h=Math.max(1,root.clientHeight);
      floater.style.setProperty('--sf-orb-anchor-y',(visual.offsetTop+visual.offsetHeight*.5)+'px');
      for(let i=0;i<2;i++)fit([mesh,cloud][i],contexts[i]);
      const space=guideSpaceScene||spaceScene;
      if(space){fit(space.canvas,space.context);space.dirty=true;drawSceneAudio();}
      frameSize=0;
      positionOrbitTools();
      position(floater.style.left?floater.offsetLeft:dim.w*.64,floater.style.top?floater.offsetTop:dim.h*.46);
      roam.start=0;
    }
    function applyAtmosphere(value,persist=true){
      const name=Object.prototype.hasOwnProperty.call(presets,String(value||''))?String(value):null;
      if(!name)return false;
      atmosphere=name;
      root.dataset.atmosphere=name;
      pagePortal.dataset.atmosphere=name;
      document.body.dataset.sfAtmosphere=name;
      for(const button of document.querySelectorAll('[data-sf-atmosphere]')){
        button.setAttribute('aria-pressed',String(button.dataset.sfAtmosphere===name));
      }
      if(persist){try{window.localStorage?.setItem(atmosphereKey,name)}catch(_){}}
      if(motionOff){meshDraw(0);cloudDraw(0);orbDraw(0)}
      return name;
    }
    function setAgentPalette(scene){
      agentPalette=scene?window.SFCanvasAgentTheme?.resolve(scene)||null:null;
      for(const name of ['--sf-atmo-rgb','--sf-atmo-accent','--sf-atmo-text','--sf-atmo-border','--sf-agent-secondary','--sf-agent-surface'])root.style.removeProperty(name);
      if(agentPalette){
        root.dataset.agentPalette='1';
        const values={'--sf-atmo-rgb':agentPalette.rgb.join(','),'--sf-atmo-accent':agentPalette.accent,
          '--sf-atmo-text':agentPalette.text,'--sf-atmo-border':agentPalette.border,
          '--sf-agent-secondary':agentPalette.secondary,'--sf-agent-surface':agentPalette.surface};
        for(const [name,value]of Object.entries(values))root.style.setProperty(name,value);
      }else delete root.dataset.agentPalette;
      if(motionOff){cloudDraw(0);orbDraw(0);}
    }
    function meshDraw(t) {
      const ctx=contexts[0];if(!ctx)return;
      const w=mesh.clientWidth||dim.w,h=mesh.clientHeight||dim.h,m=motionOff?0:t*.00023;
      ctx.clearRect(0,0,w,h);
      if(!presets[atmosphere].mesh){
        // Photographic atmospheres preserve the scene without a drawn grid.
        // Ember has warm sparks; Lunar has sparse silver moonlit dust.
        const lunar=atmosphere==='lunar';
        for(const star of stars.slice(0,44)){
          const alpha=Math.max(.04,(lunar?0.07:0.13)+(lunar?0.09:0.16)*Math.sin(m*3+star.phase));
          ctx.fillStyle=(lunar?'rgba(222,233,247,':'rgba(255,184,110,')+alpha+')';
          ctx.beginPath();ctx.arc(star.x*w,star.y*h,star.r*.65,0,Math.PI*2);ctx.fill();
        }
        return;
      }
      const violet=atmosphere==='violet';
      const halo=ctx.createRadialGradient(w*.68,h*.47,0,w*.68,h*.47,Math.max(w,h)*.6);
      halo.addColorStop(0,violet?'#8a4ce82b':'#4e6fdb2b');halo.addColorStop(1,'#00000000');ctx.fillStyle=halo;ctx.fillRect(0,0,w,h);
      for(const s of stars){ctx.fillStyle=(violet?'rgba(226,191,255,':'rgba(190,219,252,')+Math.max(.10,.25+.31*Math.sin(m*3+s.phase))+')';ctx.beginPath();ctx.arc(s.x*w,s.y*h,s.r,0,Math.PI*2);ctx.fill();}
      const cols=24,rows=13,grid=[];
      for(let y=0;y<=rows;y++){const r=[];for(let x=0;x<=cols;x++){
        const u=x/cols,v=y/rows,weight=Math.max(0,1-Math.abs(u-.65)*1.8);
        r.push({x:w*(.04+u*.92)+Math.sin(x*.57+y*.42+m*2)*12*weight+Math.cos(y*.5+m)*9+pointer.driftX*.014,
          y:h*(-.07+v*1.16)+Math.sin(x*.44+y*.58+m*1.6)*15*weight+pointer.driftY*.01,weight});
      }grid.push(r);}
      ctx.lineWidth=.8;
      const link=(a,b,alpha)=>{ctx.strokeStyle=(violet?'rgba(175,130,241,':'rgba(104,172,241,')+Math.max(.035,alpha)+')';ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.stroke();};
      for(let y=0;y<=rows;y++)for(let x=0;x<=cols;x++){
        const p=grid[y][x],level=.16+.13*p.weight+.035*Math.sin((x+y)*.52-m*1.8);
        if(x<cols)link(p,grid[y][x+1],level);
        if(y<rows)link(p,grid[y+1][x],level);
        if(y<rows&&x<cols&&(x+y)%2===0)link(p,grid[y+1][x+1],level*.52);
        if((x+y)%3===0||p.weight>.8){ctx.fillStyle=(violet?'rgba(220,176,255,':'rgba(174,220,255,')+(.24+.26*p.weight)+')';ctx.beginPath();ctx.arc(p.x,p.y,p.weight>.7?1.65:1.1,0,Math.PI*2);ctx.fill();}
      }
    }
    function gazeAt(x,y) {
      const r=sphere.getBoundingClientRect(),dx=x-r.left-r.width/2,dy=y-r.top-r.height/2;
      const d=Math.max(1,Math.hypot(dx,dy)),f=Math.min(1,d/110);
      gaze.targetX=dx/d*12*f;gaze.targetY=dy/d*9*f;
      const s=root.getBoundingClientRect();pointer.driftX=x-s.left-s.width/2;pointer.driftY=y-s.top-s.height/2;
      if(motionOff){gaze.x=gaze.targetX;gaze.y=gaze.targetY;}
    }
    function spectrum(t) {
      return Array.from({length:32},(_,i)=>Math.max(.04,Math.min(.98,.30+Math.sin(t*.008+i*.73)*.28+Math.sin(t*.004+i*.31)*.22)));
    }
    function cloudDraw(t) {
      const ctx=contexts[1];if(!ctx||root.dataset.sceneAgentDesign)return;
      const w=cloud.clientWidth,h=cloud.clientHeight,cx=w/2,cy=h/2,sec=motionOff?0:t*.001;
      ctx.clearRect(0,0,w,h);
      const strong=mode==='music'||mode==='speaking',thinking=mode==='thinking';
      const tint=mode==='recording'?'255,118,155':(agentPalette?.rgb||presets[atmosphere].rgb).join(',');
      if(strong){const levels=spectrum(t),radius=visual.clientWidth*.5+30;
        for(let i=0;i<levels.length;i++){const angle=i/levels.length*Math.PI*2-Math.PI/2,amp=levels[i],inner=radius+8;
          ctx.strokeStyle='rgba('+tint+','+(.27+amp*.67)+')';ctx.lineWidth=2.2+amp*3;ctx.beginPath();ctx.moveTo(cx+Math.cos(angle)*inner,cy+Math.sin(angle)*inner);ctx.lineTo(cx+Math.cos(angle)*(inner+7+amp*50),cy+Math.sin(angle)*(inner+7+amp*50));ctx.stroke();
        }
      }
      for(const p of particles){const angle=p.a+sec*p.v*(thinking?5:strong?2.6:1),distance=(p.r+Math.sin(sec*.8+p.s)*9)*(thinking?.68:strong?1.16:1);
        const x=cx+Math.cos(angle)*distance,y=cy+Math.sin(angle)*distance*.72;
        ctx.fillStyle='rgba('+tint+','+(.43+.24*Math.sin(sec*1.7+p.s))+')';ctx.beginPath();ctx.arc(x,y,strong?2.6:2,0,Math.PI*2);ctx.fill();
      }
    }
    function orbDraw(t){
      const ctx=contexts[2];if(!ctx||visual.hidden)return;
      const square=root.dataset.sceneAgentDesign==='square';
      const size=Math.max(1,sphere.offsetWidth),dpr=Math.min(window.devicePixelRatio||1,2);
      if(size!==frameSize){frameSize=size;face.width=Math.round(size*dpr);face.height=Math.round(size*dpr);ctx.setTransform(dpr,0,0,dpr,0,0);}
      const c=size*.5,sec=motionOff?0:t*.001,base=agentPalette?.rgb||presets[atmosphere].rgb;
      const color=mode==='recording'?[255,129,150]:base;
      const rgb=color.join(','),r=size*.427;
      ctx.clearRect(0,0,size,size);
      const glow=ctx.createRadialGradient(c,c,r*.12,c,c,r*1.19);
      glow.addColorStop(0,'rgba('+rgb+',.075)');
      glow.addColorStop(.56,'rgba('+rgb+',.045)');
      glow.addColorStop(.86,'rgba('+rgb+',.12)');
      glow.addColorStop(1,'rgba('+rgb+',0)');
      ctx.fillStyle=glow;ctx.beginPath();ctx.arc(c,c,r*1.19,0,Math.PI*2);ctx.fill();
      const spin=sec*(mode==='thinking'?.72:mode==='music'?.37:.19);
      const sin=Math.sin(spin),cos=Math.cos(spin);
      const tilt=.15+gaze.y*.014,st=Math.sin(tilt),ct=Math.cos(tilt);
      const breath=motionOff?1:1+.025*Math.sin(sec*(mode==='speaking'?5.2:1.35));
      const hover=mode==='speaking'?.08:mode==='listening'?.04:0;
      for(const p of orbPoints){
        const rx=p.x*cos-p.z*sin,rz=p.x*sin+p.z*cos;
        const ry=p.y*ct-rz*st,depth=p.y*st+rz*ct;
        const perspective=1/(1-depth*.15);
        const shape=square?Math.hypot(rx,ry)/Math.max(Math.abs(rx),Math.abs(ry),Number.EPSILON):1;
        const x=c+(rx*shape+gaze.x*.002)*r*perspective*breath;
        const y=c+ry*shape*r*perspective*breath;
        const front=(depth+1)*.5,shimmer=.5+.5*Math.sin(sec*2.1+p.seed*9);
        const alpha=Math.min(.99,.12+front*.68+shimmer*.18+hover);
        const dot=(.45+front*.93+(shimmer>.98?.7:0))*(size/230);
        ctx.fillStyle='rgba('+rgb+','+alpha.toFixed(3)+')';
        ctx.fillRect(x-dot*.5,y-dot*.5,dot,dot);
      }
      // Quiet orbital trails knit the point field into the surrounding canvas.
      if(square)return;
      ctx.lineWidth=Math.max(.55,size/320);
      for(let i=0;i<3;i++){
        const wobble=sec*(.11+i*.065),wide=r*(1.23+i*.12);
        ctx.strokeStyle='rgba('+rgb+','+(.12+i*.042)+')';
        ctx.beginPath();ctx.ellipse(c,c,wide,r*(.33+i*.055),wobble+i*.86,0,Math.PI*2);ctx.stroke();
      }
    }
    function tick(t){
      if(destroyed)return;
      raf=requestAnimationFrame(tick);
      if(document.hidden||t-lastFrame<33)return;
      lastFrame=t;
      wander(t);
      gaze.x+=(gaze.targetX-gaze.x)*.17;gaze.y+=(gaze.targetY-gaze.y)*.17;
      if(!mesh.hidden)meshDraw(t);
      if(guideSpaceScene||spaceScene||reactiveScene)drawSceneAudio();
      cloudDraw(t);orbDraw(t);
      if(audio&&mode==='music'&&audio.paused)state('idle');
    }
    function openWorkspace(){
      if(workspace.classList.contains('open'))return;
      showPlayer();
      const from=sphere.getBoundingClientRect(),rect=root.getBoundingClientRect();
      workspace.style.setProperty('--sf-from-x',from.left-rect.left+'px');
      workspace.style.setProperty('--sf-from-y',from.top-rect.top+'px');
      workspace.style.setProperty('--sf-from-size',from.width+'px');
      workspace.classList.add('growing');workspace.removeAttribute('inert');workspace.setAttribute('aria-hidden','false');
      sphere.setAttribute('aria-expanded','true');
      requestAnimationFrame(()=>{if(workspace.getAttribute('aria-hidden')==='false')workspace.classList.add('open');});
      workspaceTimer=window.setTimeout(()=>by(workspace,'[data-agent-action="close-workspace"]')?.focus(),820);
      session();
    }
    function closeWorkspace(immediate=false){
      window.clearTimeout(workspaceTimer);
      workspace.classList.remove('open');workspace.setAttribute('aria-hidden','true');workspace.setAttribute('inert','');
      sphere.setAttribute('aria-expanded','false');
      if(immediate){workspace.classList.remove('growing');return;}
      workspaceTimer=window.setTimeout(()=>workspace.classList.remove('growing'),850);
      sphere.focus({preventScroll:true});
    }
    const pageLabels={playlist:'PLAYLIST',shows:'TOUR DATES',show:'LIVE SHOW',store:'MERCH STORE',membership:'MEMBERSHIP + VIP',tickets:'TICKETS + VIP',community:'FAN COMMUNITY',newsletter:'NEWSLETTER',music:'MUSIC LIBRARY',releases:'RELEASES',track:'SONG DETAILS',release:'RELEASE DETAILS',cart:'CART',account:'ACCOUNT',login:'SIGN IN',register:'CREATE ACCOUNT',about:'ABOUT STONEFELLOW',world:'STONEFELLOW WORLD',archive:'MUSIC ARCHIVE',search:'SEARCH',history:'LISTENING HISTORY',library:'MY LIBRARY',photos:'PHOTOS',videos:'VIDEOS',press:'PRESS',contact:'CONTACT',plans:'PLANS',privacy:'PRIVACY',terms:'TERMS',checkout:'CHECKOUT',order:'ORDER',team:'TEAM',capture:'CREATIVE CAPTURE','agent-brain':'AGENT BRAIN','agent-chat':'AGENT CHAT','scene-history':'CANVAS HISTORY','canvas-gallery':'CANVAS CATALOG'};
    function openPage(view){
      // The portal overlays the Agent Canvas without replacing, remounting,
      // or hiding it. Existing route renderers mount directly into pageHost.
      window.clearTimeout(pageTimer);
      cancelAnimationFrame(pageFrame);
      const alreadyOpen=pageRail.classList.contains('open');
      sceneHistoryView?.imageRequest?.abort();
      sceneHistoryView=null;
      pageView=view;pageRail.dataset.view=view;pageHost.hidden=view==='lyrics';lyricHost.hidden=view!=='lyrics';
      if(workspace.classList.contains('open'))closeWorkspace();
      if(!alreadyOpen){
        const from=sphere.getBoundingClientRect();
        pageRail.style.setProperty('--sf-page-from-x',from.left+'px');
        pageRail.style.setProperty('--sf-page-from-y',from.top+'px');
        pageRail.style.setProperty('--sf-page-from-size',from.width+'px');
      }
      pageTitle.textContent=(view==='lyrics'?'SONG LYRICS':pageLabels[view])||String(view||'PAGE').replaceAll('-',' ').toUpperCase();
      pageHost.replaceChildren();
      pageHost.innerHTML='<div class="sf-agent-page-loading" role="status">OPENING PAGE…</div>';
      pageViewport.scrollTop=0;
      pageRail.hidden=false;
      pageRail.removeAttribute('inert');
      if(pagePortal!==root){pagePortal.hidden=false;pagePortal.classList.add('has-native-page');}
      root.classList.add('has-native-page');
      document.body?.classList?.add('sf-agent-page-open');
      pageRail.classList.add('growing');
      if(!alreadyOpen){
        // Start from the orb, using the same expansion curve as Agent Canvas.
        pageFrame=window.requestAnimationFrame(()=>{
          pageFrame=window.requestAnimationFrame(()=>pageRail.classList.add('open'));
        });
      }
      pageTimer=window.setTimeout(()=>{
        if(!destroyed&&!pageRail.hidden&&!document.body?.classList?.contains('sf-song-moments-open'))
          by(pageRail,'[data-agent-action="close-page"]')?.focus({preventScroll:true});
      },alreadyOpen?0:800);
      return view==='lyrics'?lyricHost:pageHost;
    }
    function openLyrics(){
      const host=pageView==='lyrics'&&!pageRail.hidden&&!pageRail.hasAttribute('inert')?lyricHost:openPage('lyrics');
      window.StonefellowSongExperience?.mount?.(host);
      syncListeningButtons();
      return host;
    }
    function closePage(immediate=false){
      window.clearTimeout(pageTimer);
      cancelAnimationFrame(pageFrame);
      if(pageRail.hidden)return;
      options.onPageClose?.(pageView);
      sceneHistoryView?.imageRequest?.abort();
      sceneHistoryView=null;
      pageRail.setAttribute('inert','');
      pageRail.classList.remove('open');
      state(baseMode,baseLabel);
      const finish=()=>{
        pageRail.hidden=true;
        pageRail.classList.remove('growing');
        pageHost.replaceChildren();lyricHost.replaceChildren();
        pageView='';
        root.classList.remove('has-native-page');
        if(pagePortal!==root){pagePortal.classList.remove('has-native-page');pagePortal.hidden=true;}
        document.body?.classList?.remove('sf-agent-page-open');
        focusCanvas();
      };
      if(immediate||reduced())finish();
      else pageTimer=window.setTimeout(finish,770);
    }
    function dismissPage(){
      const history=pageView==='scene-history';
      closePage();
      if(!history)options.onClosePage?.();
    }
    function setSongMoment(moment){
      if(typeof options.onOpenMoment!=='function')return false;
      const kind=typeof moment?.kind==='string'&&Object.hasOwn(songMomentKinds,moment.kind)?songMomentKinds[moment.kind]:'';
      const valid=!!kind&&Number.isSafeInteger(moment.id)&&moment.id>0&&
        Number.isSafeInteger(moment.at_ms)&&moment.at_ms>=0&&(!moment.status||moment.status==='published');
      const id=valid?String(moment.id):'';
      if(songMomentCue.dataset.momentId!==id&&document.activeElement===songMomentCue)
        songMomentsTool.focus({preventScroll:true});
      songMomentCue.hidden=!valid;
      if(!valid){
        delete songMomentCue.dataset.momentId;
        songMomentCue.removeAttribute('aria-label');
        songMomentStamp.textContent='';songMomentTitle.textContent='';songMomentStatus.textContent='';
        return moment==null;
      }
      const seconds=Math.floor(moment.at_ms/1000);
      const at=Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');
      const title=String(moment.title||moment.story?.title||moment.photo?.title||'').trim().slice(0,160);
      const description='Song Moment at '+at+': '+kind+(title?' · '+title:'');
      songMomentCue.dataset.momentId=id;
      songMomentCue.setAttribute('aria-label','Open '+description);
      songMomentStamp.textContent=at+' · '+kind;
      songMomentTitle.textContent=title;
      songMomentTitle.hidden=!title;
      if(songMomentStatus.textContent!==description)songMomentStatus.textContent=description;
      return true;
    }
    function activeCodingImage(image){
      return !destroyed&&codingImageBatch?.images.get(image.id)===image&&options.canUseScenes?.()&&
        codingImageBatch.owner===options.getSceneOwner?.();
    }
    function clearSceneImage(){
      const previous=codingImageBatch;
      codingImageBatch=codingImageModalTrigger=null;
      codingImageList.hidden=codingImageProgress.hidden=codingImageSection.hidden=true;
      if(codingImageDialog.open)codingImageDialog.close();
      codingImageStatus.textContent=codingImageTitle.textContent='';
      codingImageList.replaceChildren();
      codingImageFull.removeAttribute('src');
      codingImageFull.alt='';
      for(const image of previous?.images.values()??[]){
        image.request.abort();
        if(image.url)URL.revokeObjectURL(image.url);
      }
    }
    function syncCodingImageStatus(batch){
      const {total,index,generating,editing,failure,images}=batch;
      let message;
      if(failure)message=failure;
      else if(generating)message=editing?'Editing image…':total===1?'Creating image…':`Creating image ${index} of ${total}…`;
      else{
        const entries=[...images.values()];
        const loading=entries.some(image=>image.state==='loading');
        const unavailable=entries.some(image=>image.state==='unavailable');
        if(loading)message=entries.length===1?'Loading image preview…':'Loading image previews…';
        else if(entries.length===1)message=unavailable?'Image saved. Preview unavailable.':'Image ready.';
        else message=unavailable?`${entries.length} images saved. Some previews are unavailable.`:`${entries.length} images ready.`;
      }
      codingImageSection.hidden=false;
      codingImageProgress.hidden=!generating;
      codingImageList.hidden=!images.size;
      codingImageList.classList.toggle('sf-agent-coding-image-batch',total>1);
      if(codingImageStatus.textContent!==message)codingImageStatus.textContent=message;
    }
    function newCodingImagePreview(id,title,total){
      const item=document.createElement('li');
      const button=document.createElement('button');
      const thumb=document.createElement('img');
      const caption=document.createElement('span');
      const unavailable=document.createElement('span');
      button.type='button';
      button.className='sf-agent-coding-image-open';
      button.dataset.imageId=id;
      button.title=title;
      button.setAttribute('aria-label',total===1?'Open image preview':'Open image preview: '+title);
      button.setAttribute('aria-haspopup','dialog');
      button.hidden=true;
      thumb.className='sf-agent-coding-image-thumbnail';
      thumb.alt='';
      thumb.decoding='async';
      caption.className='sf-agent-coding-image-caption';
      caption.textContent=title;
      unavailable.className='sf-agent-coding-image-unavailable';
      unavailable.textContent=title+' · Preview unavailable';
      unavailable.hidden=true;
      button.append(thumb,caption);
      item.append(button,unavailable);
      codingImageList.append(item);
      return {button,thumb,unavailable};
    }
    async function loadCodingImage(image){
      let blob;
      try{blob=await options.onSceneImage(image.id,image.request.signal);}
      catch(_){/* A saved image can remain in history if its preview is unavailable. */}
      if(!activeCodingImage(image))return null;
      if(!validSceneImageBlob(blob)){
        image.state='unavailable';
        image.unavailable.hidden=false;
        syncCodingImageStatus(codingImageBatch);
        return null;
      }
      image.url=URL.createObjectURL(blob);
      image.state='ready';
      image.thumb.src=image.url;
      image.button.hidden=false;
      syncCodingImageStatus(codingImageBatch);
      return blob;
    }
    function setSceneImage(next,asset,progress){
      if(!codingImageSection)return false;
      if(next==='clear'||!options.canUseScenes?.()){
        clearSceneImage();
        return next==='clear';
      }
      const owner=options.getSceneOwner?.();
      if(codingImageBatch&&codingImageBatch.owner!==owner)clearSceneImage();
      if(next==='failed'||next==='complete'){
        if(!codingImageBatch||(!codingImageBatch.generating&&
          (next==='complete'||codingImageBatch.images.size>=codingImageBatch.total)))return false;
        codingImageBatch.generating=false;
        if(next==='failed'){
          const message=(typeof asset==='string'&&asset.trim().slice(0,240))||'Image could not be generated.';
          const count=codingImageBatch.images.size;
          codingImageBatch.failure=count?`${message} ${count} ${count===1?'image was':'images were'} saved.`:message;
        }
        syncCodingImageStatus(codingImageBatch);
        return true;
      }
      if(next!=='generating'&&(next!=='saved'||typeof asset?.id!=='string'||!sceneImageId.test(asset.id)||
        typeof options.onSceneImage!=='function'))return false;
      const total=Number.isInteger(progress?.total)&&progress.total>=1&&progress.total<=sceneImageLimit?progress.total:1;
      const index=Number.isInteger(progress?.index)&&progress.index>=1&&progress.index<=total?progress.index:1;
      if(next==='generating'&&index===1&&codingImageBatch&&!codingImageBatch.generating)clearSceneImage();
      const batch=codingImageBatch??={owner,total,index,generating:false,images:new Map(),failure:''};
      batch.total=Math.max(batch.total,total);
      if(next==='generating'){
        batch.index=Math.max(batch.index,index);
        batch.generating=true;
        batch.editing=progress?.editing===true;
        batch.failure='';
        syncCodingImageStatus(batch);
        return true;
      }
      if(batch.images.has(asset.id))return true;
      if(batch.images.size>=batch.total)return false;
      const title=(typeof asset.title==='string'&&asset.title.trim().slice(0,180))||'Generated image';
      const image={state:'loading',id:asset.id,title,request:new AbortController(),...newCodingImagePreview(asset.id,title,batch.total)};
      batch.images.set(image.id,image);
      if(batch.images.size===batch.total)batch.generating=false;
      syncCodingImageStatus(batch);
      image.result=loadCodingImage(image);
      return true;
    }
    cleanup.push(on(codingImageList,'click',event=>{
      const button=event.target.closest?.('button[data-image-id]');
      const image=codingImageBatch?.images.get(button?.dataset.imageId);
      if(image?.state!=='ready'||!activeCodingImage(image)||codingImageDialog.open)return;
      codingImageModalTrigger=image;
      codingImageTitle.textContent=codingImageFull.alt=image.title;
      codingImageFull.src=image.url;
      codingImageEdit.hidden=!editableSceneImage(image.id);
      codingImageReplace.hidden=!editableSceneImage(image.id,'onReplaceSceneImage');
      codingImageDialog.showModal();
    }));
    cleanup.push(on(codingImageEdit,'click',()=>{
      const asset=editableSceneImage(codingImageModalTrigger?.id);
      if(!asset)return;
      const trigger=codingImageModalTrigger;
      codingImageModalTrigger=null;
      codingImageDialog.close();
      if(options.onEditSceneImage(asset)===false)trigger?.button.focus({preventScroll:true});
    }));
    cleanup.push(on(codingImageReplace,'click',()=>{
      const asset=editableSceneImage(codingImageModalTrigger?.id,'onReplaceSceneImage');
      if(!asset)return;
      const trigger=codingImageModalTrigger;
      codingImageModalTrigger=null;
      codingImageDialog.close();
      if(options.onReplaceSceneImage(asset)===false)trigger?.button.focus({preventScroll:true});
    }));
    cleanup.push(on(codingImageDialog,'click',event=>{
      if(event.target===codingImageDialog)codingImageDialog.close();
    }));
    cleanup.push(on(codingImageDialog,'keydown',event=>{
      if(event.key==='Escape'||event.key==='Tab')event.stopPropagation();
    }));
    cleanup.push(on(codingImageDialog,'close',()=>{
      if(codingImageDialog.open)return;
      codingImageFull.removeAttribute('src');
      const image=codingImageModalTrigger;
      codingImageModalTrigger=null;
      if(image&&activeCodingImage(image)&&!image.button.hidden)image.button.focus({preventScroll:true});
    }));
    const workflowPhases=Object.freeze({coding:'Coding',testing:'Testing',reviewing:'Reviewing',needs_repair:'Needs repair',repairing:'Repairing',repair:'Repairing',ready:'Ready',needs_attention:'Needs attention',applied:'Applied',canceled:'Canceled',failed:'Failed'});
    const activeWorkflow=()=>!!workflow&&['coding','testing','reviewing','needs_repair','repairing','repair'].includes(workflow.phase);
    const workflowRole=role=>['testing','tester','reviewing','reviewer'].includes(role)?'testing':['repair','repairing','repairer'].includes(role)?'repair':'coding';
    const workflowText=(tag,text,className)=>{const node=document.createElement(tag);node.textContent=String(text);if(className)node.className=className;return node;};
    const workflowNumber=value=>typeof value==='number'&&Number.isFinite(value)&&value>=0?value:null;
    function selectWorkflowTab(name,focus=false){
      if(!['project','coding','testing','repair'].includes(name))return;
      workflowTab=name;
      for(const tab of workflowTabs){
        const selected=tab.dataset.workflowTab===name;
        tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;
        if(selected&&focus)tab.focus({preventScroll:true});
      }
      for(const panel of workflowPanels)panel.hidden=panel.dataset.workflowPanel!==name;
    }
    function renderWorkflow(){
      if(!workflowPhase||!workflowBrief||!workflowMeta)return;
      workflowPhase.hidden=!workflow;
      workflowPhase.textContent=workflow?workflowPhases[workflow.phase]:'';
      const project=workflow?.project||{};
      workflowBrief.textContent=typeof project.brief==='string'&&project.brief.trim()?project.brief:'No approved project has been supplied.';
      workflowMeta.replaceChildren();
      const meta=(label,value)=>{workflowMeta.appendChild(workflowText('dt',label));workflowMeta.appendChild(workflowText('dd',value));};
      const model=project.coding_model||project.model;
      if(typeof model==='string')meta('Model',model);
      else if(model&&typeof model==='object'&&(model.provider||model.model))meta('Model',[model.provider,model.model].filter(value=>typeof value==='string').join(' · '));
      const resources=project.resources&&typeof project.resources==='object'&&!Array.isArray(project.resources)?project.resources:null;
      if(resources){
        for(const [label,value] of Object.entries(resources))if(workflowNumber(value)!==null||typeof value==='string')meta(label.replaceAll('_',' '),value);
      }else{
        for(const [label,value] of [['Canvases',project.source_scene_ids],['Images',project.image_reference_ids],['Merch',project.artist_inputs?.merch_ids],['Tickets',project.artist_inputs?.ticket_ids]]){
          if(Array.isArray(value))meta(label,value.length);
        }
        if(project.music_inputs?.mode==='all')meta('Music','Entire catalog');
        else if(Array.isArray(project.music_inputs?.track_ids))meta('Songs',project.music_inputs.track_ids.length);
      }
      const total=workflowNumber(workflow?.total_active_tokens_used);
      meta('Active Tokens used',total===null?'Not reported yet':total.toLocaleString());
      for(const [role,host] of Object.entries(workflowLogs)){
        if(!host)continue;
        host.replaceChildren();
        const steps=(workflow?.steps||[]).filter(step=>workflowRole(step.role)===role);
        if(!steps.length){
          if(role!=='coding')host.appendChild(workflowText('p',role==='testing'?'No testing evidence has been reported.':'No repair activity has been reported.'));
          continue;
        }
        const list=document.createElement('ol');list.className='sf-agent-workflow-steps';
        for(const step of steps){
          const item=document.createElement('li');
          if(typeof step.id==='string')item.dataset.stepId=step.id;
          item.appendChild(workflowText('h4',step.summary||step.id||step.role||'Agent step'));
          if(typeof step.state==='string')item.appendChild(workflowText('p',step.state,'sf-agent-workflow-step-state'));
          for(const [field,label] of [['checks','Checks'],['findings','Findings'],['fixes','Fixes']]){
            if(!Array.isArray(step[field])||!step[field].length)continue;
            item.appendChild(workflowText('h5',label));
            const evidence=document.createElement('ul');
            for(const entry of step[field]){
              let text=typeof entry==='string'?entry:'';
              if(entry&&typeof entry==='object'){
                const parts=[entry.label||entry.name||entry.id,entry.summary||entry.message||entry.detail||entry.description];
                if(typeof entry.passed==='boolean')parts.push(entry.passed?'Passed':'Failed');
                else if(typeof entry.state==='string'||typeof entry.status==='string')parts.push(entry.state||entry.status);
                if(typeof entry.severity==='string')parts.push(entry.severity);
                text=parts.filter(value=>typeof value==='string'&&value.trim()).join(' · ');
              }
              if(text)evidence.appendChild(workflowText('li',text));
            }
            if(evidence.childElementCount)item.appendChild(evidence);
          }
          const tokens=workflowNumber(step.active_tokens_used);
          if(tokens!==null)item.appendChild(workflowText('small','Active Tokens used: '+tokens.toLocaleString()));
          if(step.usage&&typeof step.usage==='object'){
            const input=workflowNumber(step.usage.input_tokens),output=workflowNumber(step.usage.output_tokens);
            const model=[step.usage.provider,step.usage.model].filter(value=>typeof value==='string').join(' · ');
            if(model)item.appendChild(workflowText('small',model));
            if(input!==null&&output!==null)item.appendChild(workflowText('small','Model tokens: '+input.toLocaleString()+' input · '+output.toLocaleString()+' output'));
          }
          list.appendChild(item);
        }
        host.appendChild(list);
      }
      for(const tab of workflowTabs){
        const name=tab.dataset.workflowTab;
        const active=workflow&&(name==='coding'&&workflow.phase==='coding'||name==='testing'&&['testing','reviewing'].includes(workflow.phase)||name==='repair'&&['needs_repair','repairing','repair'].includes(workflow.phase));
        tab.dataset.phaseActive=String(!!active);
      }
      if(codingCandidateNote)codingCandidateNote.hidden=!workflow||workflow.phase==='applied'||!codingCode;
      selectWorkflowTab(workflowTab);
    }
    function syncCodingStop(){
      codingStop.hidden=!options.onSceneStop||!(activeWorkflow()||!workflow&&['streaming','generating'].includes(codingRoot.dataset.state));
    }
    function setSceneWorkflow(update){
      if(update===null){
        workflow=null;workflowRequests.clear();delete codingRoot.dataset.workflowPhase;renderWorkflow();syncCodingStop();syncCodingVisibility();return true;
      }
      if(!options.canUseScenes?.()||!update||typeof update.request_id!=='string'||!update.request_id||!Object.hasOwn(workflowPhases,update.phase))return false;
      const fresh=workflow?.request_id!==update.request_id;
      if(fresh&&(update.phase!=='coding'||workflowRequests.has(update.request_id)))return false;
      if(!fresh&&workflow.owner!==options.getSceneOwner?.())return false;
      if(fresh){
        workflowRequests.add(update.request_id);
        workflow={request_id:update.request_id,owner:options.getSceneOwner?.(),steps:[]};
        codingCode=null;codingHtml.textContent=codingCss.textContent=codingJs.textContent=codingRaw.textContent='';
        codingJavascript.hidden=true;codingLive.hidden=true;codingValidated.hidden=false;codingSource.hidden=true;
        codingVisible=update.silent!==true;codingDismissed=false;
      }
      workflow={...workflow,...update,owner:workflow.owner};
      if(!Array.isArray(workflow.steps))workflow.steps=[];
      workflow.steps=workflow.steps.filter(step=>step&&typeof step==='object');
      codingRoot.dataset.workflowPhase=workflow.phase;
      if(!codingRoot.dataset.state)codingRoot.dataset.state='generating';
      if(typeof update.summary==='string'&&update.summary.trim())codingStatus.textContent=update.summary;
      else codingStatus.textContent=workflowPhases[workflow.phase];
      renderWorkflow();syncCodingStop();syncCodingVisibility();return true;
    }
    function setSceneCandidateCode(scene){
      if(!options.canUseScenes?.()||!scene||typeof scene.html!=='string'||typeof scene.css!=='string'||scene.js!==undefined&&typeof scene.js!=='string')return false;
      codingCode={html:scene.html,css:scene.css,js:scene.js||''};
      codingHtml.textContent=codingCode.html;codingCss.textContent=codingCode.css;codingJs.textContent=codingCode.js;
      codingJavascript.hidden=!codingCode.js;codingLive.hidden=true;codingValidated.hidden=false;codingSource.hidden=false;
      if(codingCandidateNote)codingCandidateNote.hidden=!workflow||workflow.phase==='applied';
      if(codingVisible&&!codingDismissed&&window.matchMedia?.('(min-width:821px)').matches)codingSource.open=true;
      return true;
    }
    for(const tab of workflowTabs){
      cleanup.push(on(tab,'click',()=>selectWorkflowTab(tab.dataset.workflowTab)));
      cleanup.push(on(tab,'keydown',event=>{
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
        event.preventDefault();event.stopPropagation();
        const index=workflowTabs.indexOf(tab),next=event.key==='Home'?0:event.key==='End'?workflowTabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+workflowTabs.length)%workflowTabs.length;
        selectWorkflowTab(workflowTabs[next].dataset.workflowTab,true);
      }));
    }
    function syncCodingVisibility(){
      const available=!!codingRoot.dataset.state||!!workflow;
      codingRoot.hidden=!available||!codingVisible;
      root.classList[codingRoot.hidden?'remove':'add']('has-scene-coding');
      sceneCode.hidden=!available;
      sceneCode.setAttribute('aria-expanded',String(!codingRoot.hidden));
      sceneClose.hidden=!listeningScene;
      sceneFeedback.hidden=!listeningScene||typeof options.onSceneFeedback!=='function';
      sceneFooterActions.hidden=!listeningScene&&!available;
    }
    function setSceneCoding(next,message){
      const clear=next==='idle'||!options.canUseScenes?.();
      if(!clear&&!Object.hasOwn(sceneCodingLabels,next))return false;
      if(clear&&workflow&&options.canUseScenes?.()&&!activeWorkflow()){
        syncCodingStop();syncCodingVisibility();return true;
      }
      const streaming=!clear&&next==='streaming';
      if(!clear&&next==='generating'&&!['generating','streaming'].includes(codingRoot.dataset.state)){
        codingDismissed=false;codingVisible=true;
      }else if(!clear&&streaming&&!codingRoot.dataset.state&&!codingDismissed)codingVisible=true;
      const stopWasFocused=document.activeElement===codingStop;
      codingStop.hidden=clear||!options.onSceneStop||(!activeWorkflow()&&!streaming&&next!=='generating');
      const unfinished=!clear&&next==='error'&&!!codingRaw.textContent;
      const showRaw=streaming||unfinished;
      if(!showRaw||(streaming&&codingRoot.dataset.state!=='streaming'))codingRaw.textContent='';
      codingLive.hidden=!showRaw;
      if(codingLiveTitle)codingLiveTitle.textContent=unfinished?'Unfinished model output':'Live model output';
      codingValidated.hidden=showRaw;
      if(clear){
        setSceneWorkflow(null);
        setSceneImage('clear');
        codingRoot.hidden=true;
        codingSource.hidden=true;
        codingSource.open=false;
        codingStatus.textContent=codingHtml.textContent=codingCss.textContent=codingJs.textContent='';
        codingJavascript.hidden=true;codingVisible=false;codingDismissed=false;
        codingCode=null;
        delete codingRoot.dataset.state;
        root.classList.remove('has-scene-coding');
        syncCodingVisibility();
        roam.start=0;
        return next==='idle';
      }
      if(codingRoot.hidden&&codingVisible)codingSource.open=!!window.matchMedia?.('(min-width: 821px)').matches;
      codingRoot.dataset.state=next;
      syncCodingStop();
      syncCodingVisibility();
      codingStatus.textContent=(typeof message==='string'&&message.trim().slice(0,240))||sceneCodingLabels[next];
      if(stopWasFocused&&codingStop.hidden)codingStatus.focus({preventScroll:true});
      codingSource.hidden=showRaw?!codingRaw.textContent:!codingCode;
      if(codingCode&&!showRaw){
        codingHtml.textContent=codingCode.html;
        codingCss.textContent=codingCode.css;
        codingJs.textContent=codingCode.js||'';
        codingJavascript.hidden=!codingCode.js;
        (codingBody||codingScroller).scrollTop=0;
      }
      return true;
    }
    function appendSceneCode(kind,text){
      if(kind!=='raw'||typeof text!=='string'||codingRoot.dataset.state!=='streaming'||!options.canUseScenes?.())return false;
      const delta=text.slice(0,96000-codingRaw.textContent.length);
      if(!delta)return false;
      const scroller=codingBody||codingScroller;
      const follow=scroller.scrollHeight-scroller.scrollTop-scroller.clientHeight<24;
      codingRaw.textContent+=delta;
      codingSource.hidden=false;
      if(follow&&codingSource.open)scroller.scrollTop=scroller.scrollHeight;
      return true;
    }
    async function stopSceneBuild(){
      if(codingStop.hidden||codingStop.getAttribute('aria-disabled')==='true')return;
      codingStop.setAttribute('aria-disabled','true');
      codingStop.textContent='Stopping…';
      try{
        const message=await options.onSceneStop?.();
        if(message&&!codingStop.hidden)codingStatus.textContent=message;
      }finally{
        codingStop.removeAttribute('aria-disabled');
        codingStop.textContent='Stop Agent';
      }
    }
    function sceneSignalText(text){
      if(sceneSignal.textContent!==text)sceneSignal.textContent=text;
    }
    function createSpaceScene(canvas){
      return {canvas,context:canvas.getContext('2d'),levels:new Float32Array(24),tap:null,dirty:true};
    }
    function currentSceneAudio(){
      return guideSpaceScene||spaceScene||reactiveScene;
    }
    function releaseSpaceAudio(tap){
      if(!tap)return;
      try{tap.source?.disconnect();}catch(_){}
      for(const track of tap.stream?.getTracks?.()||[])track.stop();
      if(tap.context?.state!=='closed')void tap.context?.close?.().catch?.(()=>{});
    }
    function setGuideSpacePreview(show){
      if(show===!!guideSpaceScene)return;
      if(show){
        const canvas=document.createElement('canvas');
        canvas.className='sf-agent-scene-backdrop sf-agent-space-eq sf-agent-guide-eq-preview';
        canvas.setAttribute('aria-hidden','true');
        guideLayer.prepend(canvas);
        guideSpaceScene=createSpaceScene(canvas);
        fit(canvas,guideSpaceScene.context);
        const background=spaceScene||reactiveScene;
        if(background?.tap){
          releaseSpaceAudio(background.tap);
          background.tap=null;
          background.levels.fill(0);
          background.dirty=true;
        }
      }else{
        releaseSpaceAudio(guideSpaceScene.tap);
        guideSpaceScene.canvas.remove();
        guideSpaceScene=null;
        if(spaceScene){
          fit(spaceScene.canvas,spaceScene.context);
          spaceScene.dirty=true;
        }
      }
      syncSceneAudio();drawSceneAudio();
    }
    function sceneAudioSource(){
      const source=audio?.currentSrc||'';
      return source&&(!audio.src||audio.src===source)?source:'';
    }
    function syncSceneAudio(){
      const space=currentSceneAudio();
      if(!space||motionOff||(!space.context&&!space.frame))return;
      const setSignal=text=>{if(space===spaceScene||space===reactiveScene)sceneSignalText(text);};
      const source=sceneAudioSource();
      if(space.tap&&(space.tap.url!==source||
        !space.tap.stream.getAudioTracks().some(track=>track.readyState!=='ended'))){
        releaseSpaceAudio(space.tap);space.tap=null;
        space.levels.fill(0);space.dirty=true;
      }
      if(!source||audio.paused||audio.ended){
        setSignal('Play music to activate');
        space.levels.fill(0);space.dirty=true;drawSceneAudio();
        return;
      }
      if(space.tap)return;
      const capture=audio.captureStream||audio.mozCaptureStream;
      const Context=window.AudioContext||window.webkitAudioContext;
      if(typeof capture!=='function'||!Context){
        setSignal('Still view · audio analysis unavailable');
        return;
      }
      const tap={url:source};
      try{
        tap.stream=capture.call(audio);
        if(!tap.stream?.getAudioTracks?.().some(track=>track.readyState!=='ended')){
          releaseSpaceAudio(tap);setSignal('Waiting for playback audio');
          return;
        }
        // A captured stream is a read-only branch. Leave the player and the
        // Vocal Studio's permanent MediaElementSource wiring untouched.
        tap.context=new Context();
        tap.analyser=tap.context.createAnalyser();
        tap.analyser.fftSize=2048;
        tap.analyser.smoothingTimeConstant=.68;
        tap.source=tap.context.createMediaStreamSource(tap.stream);
        tap.source.connect(tap.analyser);
        tap.samples=new Uint8Array(tap.analyser.frequencyBinCount);
        const low=Math.max(1,Math.floor(45*tap.analyser.fftSize/tap.context.sampleRate));
        const high=Math.min(tap.samples.length-1,Math.floor(13500*tap.analyser.fftSize/tap.context.sampleRate));
        tap.bands=Array.from({length:space.levels.length+1},(_,i)=>Math.floor(low*(high/low)**(i/space.levels.length)));
        space.tap=tap;
        if(tap.context.state==='suspended')void tap.context.resume().catch(()=>{
          if(space.tap===tap)setSignal('Still view · audio analysis unavailable');
        });
        if(tap.context.state!=='running')setSignal('Waiting for playback audio');
      }catch(_){
        if(space.tap===tap)space.tap=null;
        releaseSpaceAudio(tap);
        setSignal('Still view · audio analysis unavailable');
      }
    }
    function drawSceneAudio(){
      const space=currentSceneAudio();
      if(!space?.context&&!space?.frame)return;
      const tap=space.tap;
      const active=tap?.context.state==='running'&&!audio.paused&&!audio.ended&&
        tap.url===sceneAudioSource();
      if(!space.dirty&&!active)return;
      space.dirty=false;
      if(active){
        if(space===spaceScene||space===reactiveScene)sceneSignalText('Reacting to playback');
        tap.analyser.getByteFrequencyData(tap.samples);
        for(let i=0;i<space.levels.length;i++){
          let peak=0;
          const from=tap.bands[i],to=Math.max(from+1,tap.bands[i+1]);
          for(let bin=from;bin<to;bin++)peak=Math.max(peak,tap.samples[bin]);
          const level=(audio.muted?0:peak)*audio.volume/255;
          space.levels[i]+=(level-space.levels[i])*(level>space.levels[i]?.5:.22);
        }
      }
      let bass=0,mids=0,highs=0;
      for(let i=0;i<space.levels.length;i++){
        if(i<9)bass=Math.max(bass,space.levels[i]);
        else if(i<17)mids=Math.max(mids,space.levels[i]);
        else highs=Math.max(highs,space.levels[i]);
      }
      if(space.frame){
        if(!space.ready)return;
        const levels=[bass,mids,highs,Math.max(bass,mids,highs)].map(level=>Math.round(level*1000)/1000);
        if(levels.every((level,i)=>level===space.sentLevels?.[i]))return;
        space.frame.contentWindow?.postMessage({stonefellowSceneAudio:levels},'*');
        space.sentLevels=levels;
        return;
      }
      const {canvas,context:ctx}=space,w=canvas.clientWidth,h=canvas.clientHeight;
      if(!w||!h)return;
      ctx.clearRect(0,0,w,h);
      ctx.fillStyle='#dceaff';
      ctx.globalAlpha=.39+highs*.56;
      for(const star of stars){
        const size=star.r*(1+highs*.45);
        ctx.fillRect(w*star.x/100,h*.7*star.y/100,size,size);
      }
      ctx.globalAlpha=1;
      drawSpacePlanets(ctx,w,h,bass,mids);
      const horizon=h*.67,floor=h*.77;
      ctx.strokeStyle='#6d93e245';
      ctx.lineWidth=1;
      ctx.beginPath();
      for(let line=-9;line<=9;line++){
        ctx.moveTo(w/2+line*w*.025,horizon);ctx.lineTo(w/2+line*w*.14,h);
      }
      for(let row=0;row<8;row++){
        const y=horizon+(h-horizon)*(row/7)**1.8;
        ctx.moveTo(0,y);ctx.lineTo(w,y);
      }
      ctx.stroke();
      const glow=ctx.createLinearGradient(0,floor-h*.29,0,floor);
      glow.addColorStop(0,'#b8ddff');
      glow.addColorStop(.52,'#758dff');
      glow.addColorStop(1,'#3851ad7a');
      ctx.fillStyle=glow;
      const step=w*.92/(space.levels.length*2),barWidth=Math.max(1,step*.56);
      for(let i=0;i<space.levels.length;i++){
        const height=h*(.006+.018*Math.sin((i+1)*1.8)**2+.28*space.levels[i]);
        ctx.fillRect(w/2+step*(i+.5),floor-height,barWidth,height);
        ctx.fillRect(w/2-step*(i+.5)-barWidth,floor-height,barWidth,height);
      }
    }
    function drawSpacePlanets(ctx,w,h,bass,mids){
      const scale=Math.min(w,h),compact=w<720;
      for(const planet of spacePlanets){
        const x=w*planet.x,y=h*(compact?planet.smallY:planet.y);
        const r=scale*planet.size*(1+bass*.23);
        const orbit=planet.ring&&{
          width:r*1.78,height:r*(.34+mids*.13),tilt:planet.tilt+mids*.09
        };
        if(orbit){
          ctx.strokeStyle='rgba('+planet.ring+','+(.24+mids*.46)+')';
          ctx.lineWidth=1.1+mids*2;
          ctx.beginPath();ctx.ellipse(x,y,orbit.width,orbit.height,orbit.tilt,0,Math.PI*2);ctx.stroke();
        }
        const surface=ctx.createRadialGradient(x-r*.4,y-r*.4,r*.06,x,y,r);
        surface.addColorStop(0,planet.light);
        surface.addColorStop(.57,planet.body);
        surface.addColorStop(1,planet.shadow);
        ctx.fillStyle=surface;
        ctx.beginPath();ctx.arc(x,y,r,0,Math.PI*2);ctx.fill();
        if(orbit){
          // Redraw the near half after the planet, which occludes the far half.
          ctx.beginPath();ctx.ellipse(x,y,orbit.width,orbit.height,orbit.tilt,0,Math.PI);ctx.stroke();
        }
      }
    }
    function syncSceneCue(){
      const cue=listeningScene?.cue;
      const at=Number(audio?.currentTime)*1000;
      const active=!!cue&&!!audio&&!audio.paused&&!audio.ended&&!audio.seeking&&
        String(options.getTrackId?.()||'')===listeningScene.trackId&&at>=cue.start_ms&&at<cue.end_ms;
      root.classList[active?'add':'remove']('has-scene-lyric-cue');
    }
    function syncScenePlayback(){
      syncSceneAudio();
      syncSceneCue();
      refreshCanvasAudio();
    }
    function refreshCanvasAudio(){
      sceneAudioHost?.refresh();
    }
    function destroyCanvasAudio(){
      sceneAudioHost?.destroy();sceneAudioHost=null;
    }
    function startCanvasAudio(frame,script){
      destroyCanvasAudio();
      if(!script?.js)return;
      const context=Object.freeze(listeningScene?.galleryId?{gallery_id:listeningScene.galleryId}:{scene_id:listeningScene?.id});
      sceneAudioHost=script.sdk.createHost({frame,audio,
        getOwner:()=>options.getSceneOwner?.(),
        canUse:()=>!destroyed&&listeningScene?.layer===frame&&!!options.canUseScenes?.(),
        loadCatalog:(filters,signal)=>options.onCanvasAudioCatalog?.(filters,signal),
        loadAudio:(trackId,signal)=>options.onCanvasAudioSource?.(trackId,signal),
        dataAliases:listeningScene?.dataAliases,
        dataLocationAliases:listeningScene?.dataLocationAliases,
        dataProximitySources:listeningScene?.dataProximitySources,
        loadData:(alias,signal,locationId)=>options.onCanvasDataRead?.(alias,context,signal,locationId),
        observeIndependent:(media,track,settings)=>options.onCanvasIndependentAudioObserve?.(media,track,context,settings),
        play:trackId=>options.onCanvasAudioPlay?.(trackId),
        pause:()=>options.onCanvasAudioPause?.(),
        seek:position=>options.onCanvasAudioSeek?.(position),
        setVolume:value=>options.onCanvasAudioVolume?.(value),
        onDiagnostic:detail=>options.onCanvasAudioDiagnostic?.(detail),
        ...(typeof options.onCanvasAudioState==='function'?{getState:()=>options.onCanvasAudioState()}:{}),
      });
    }
    function receiveSceneTrack(event){
      if(!event.isTrusted||event.origin!=='null'||!sceneTracks?.ready||
        event.source!==sceneTracks.frame.contentWindow||!options.canUseScenes?.())return;
      const id=event.data?.stonefellowSceneTrackAction;
      if(event.data?.revision!==sceneTracks.revision||!sceneTracks.ids.has(id))return;
      options.onSceneCatalogPlay?.(id);
    }
    function postSceneTrackState(){
      if(sceneTracks?.ready)sceneTracks.frame.contentWindow?.postMessage({stonefellowSceneTrackState:{revision:sceneTracks.revision}},'*');
    }
    function catalogButton(label,title,callback){
      const button=document.createElement('button');
      button.type='button';button.textContent=label;
      button.setAttribute('aria-label',label+' '+title);
      button.addEventListener('click',()=>{
        if(listeningScene&&sceneCatalog.contains(button)&&options.canUseScenes?.())callback();
      });
      return button;
    }
    function appendCatalogArtwork(target,value){
      const source=sceneArtwork(value);
      if(!source)return;
      const image=document.createElement('img');
      image.alt='';image.width=40;image.height=40;image.loading='lazy';
      image.referrerPolicy='no-referrer';image.src=source;
      target.appendChild(image);
    }
    function renderSceneCatalog(blocks){
      sceneCatalog.replaceChildren();
      for(const block of Array.isArray(blocks)?blocks.slice(0,6):[]){
        const type=block?.type;
        if(typeof type!=='string'||!Object.hasOwn(sceneCatalogTypes,type))continue;
        const group=sceneCatalogTypes[type],title=catalogText(block.title)||group.label;
        const section=document.createElement('details');
        section.open=!sceneCatalog.childElementCount;
        const heading=document.createElement('summary');
        appendCatalogArtwork(heading,block.artwork);
        const name=document.createElement('strong');name.textContent=title;heading.appendChild(name);
        section.appendChild(heading);
        const list=document.createElement('ul');
        for(const row of Array.isArray(block.items)?block.items.slice(0,32):[]){
          const label=catalogText(row?.title);
          if(!label)continue;
          const item=document.createElement('li');
          appendCatalogArtwork(item,row.artwork);
          const copy=document.createElement('div');copy.className='sf-agent-catalog-copy';
          const itemTitle=document.createElement('strong');itemTitle.textContent=label;copy.appendChild(itemTitle);
          const facts=[catalogText(row.subtitle,260)];
          if(type==='shows'&&!facts[0])facts.push(catalogText(row.date),catalogText(row.venue),catalogText(row.location));
          if(type==='tickets'){
            facts.push(catalogText(row.show_title),catalogText(row.date),catalogText(row.venue),catalogText(row.location),
              catalogText(row.offer_type).replaceAll('_',' '),
              row.price_cents===null?'See ticket provider for price':catalogPrice(row));
          }
          if(type==='merch')facts.push(catalogPrice(row));
          if((type==='merch'||type==='tickets')&&row.available===false)facts.push('Unavailable');
          if(type==='album'&&Number.isSafeInteger(row.duration_seconds)&&row.duration_seconds>0){
            const seconds=row.duration_seconds;
            facts.push(Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0'));
          }
          const description=facts.filter(Boolean).join(' · ');
          if(description){
            const detail=document.createElement('small');detail.textContent=description;copy.appendChild(detail);
          }
          if(type==='merch'&&Array.isArray(row.variants)){
            const variants=row.variants.filter(variant=>variant?.available!==false)
              .map(variant=>catalogText(variant?.title,60)).filter(Boolean).slice(0,5);
            if(variants.length){
              const choices=document.createElement('small');choices.textContent='Options: '+variants.join(', ');copy.appendChild(choices);
            }
          }
          item.appendChild(copy);
          const id=catalogId(type==='album'?(row.track_id??row.id):type==='merch'?(row.slug||row.id):type==='tickets'?row.show_id:row.id);
          if(id&&type==='album'&&typeof options.onSceneCatalogPlay==='function')
            item.appendChild(catalogButton('Play',label,()=>options.onSceneCatalogPlay(id)));
          else if(id&&group.action&&typeof options.onSceneCatalogOpen==='function')
            item.appendChild(catalogButton(group.button,label,()=>options.onSceneCatalogOpen(group.action,id)));
          list.appendChild(item);
        }
        if(list.childElementCount)section.appendChild(list);
        else{
          const note=document.createElement('p');note.textContent='Nothing currently listed.';section.appendChild(note);
        }
        const albumId=type==='album'?catalogId(block.album_id):'';
        if(albumId&&typeof options.onSceneCatalogOpen==='function')
          section.appendChild(catalogButton('View album',title,()=>options.onSceneCatalogOpen('album',albumId)));
        sceneCatalog.appendChild(section);
      }
      sceneCatalog.hidden=!sceneCatalog.childElementCount;
      root.classList[sceneCatalog.hidden?'remove':'add']('has-scene-catalog');
    }
    function focusCanvas(){
      const target=listeningScene?sceneTitle:visual.hidden?document.querySelector?.('#menuButton'):sphere;
      target?.focus({preventScroll:true});
    }
    function syncSceneAgentDesign(){
      const hidden=manualAgentHidden||(sceneAgentDesign==='hidden'&&!sceneAgentRevealed);
      const square=!hidden&&sceneAgentDesign==='square';
      const wasHidden=root.dataset.sceneAgentDesign==='hidden';
      const focused=document.activeElement;
      const moveFocus=hidden&&!wasHidden&&focused&&!player?.contains(focused)&&(
        (!manualAgentHidden&&focused.closest?.('.chat-footer,.sf-chat-footer-restore,#menuAgentChat,#myAgentCanvasForm'))||
        floater.contains(focused)||
        workspace.contains(focused)||guideLayer.contains(focused));
      if(hidden||square)root.dataset.sceneAgentDesign=hidden?'hidden':'square';
      else delete root.dataset.sceneAgentDesign;
      visual.hidden=hidden;
      status.hidden=hidden;
      if(!hidden&&wasHidden)showPlayer();
      if(hidden!==wasHidden){
        document.body?.classList?.[hidden?'add':'remove']('sf-agent-hidden-by-scene');
        if(hidden){
          closeGuide(false);
          closeWorkspace(true);
          roam.start=0;
        }
        if(!destroyed){
          const playerFocus=placePlayer(hidden);
          syncPlayer();
          restorePlayerFocus(playerFocus);
        }
        options.onAgentVisibilityChange?.(hidden);
      }
      if(moveFocus)focusCanvas();
      syncOrbControlLabel();
      if(!hidden&&!square)fit(cloud,contexts[1]);
      if(!hidden&&wasHidden)positionOrbitTools();
      if(motionOff){cloudDraw(0);orbDraw(0);}
    }
    function setSceneAgentDesign(design){
      sceneAgentDesign=design;
      sceneAgentRevealed=false;
      syncSceneAgentDesign();
    }
    function setAgentHidden(hidden){
      manualAgentHidden=hidden;
      sceneAgentRevealed=!hidden;
      syncSceneAgentDesign();
      return true;
    }
    function currentSceneTrackId(){
      return String(options.getTrackId?.()??'');
    }
    function editableSceneImage(id,callback='onEditSceneImage'){
      const images=listeningScene?.images;
      if(destroyed||typeof id!=='string'||!sceneImageId.test(id)||typeof options[callback]!=='function'||
        !images?.editable||!options.canUseScenes?.()||images.owner!==options.getSceneOwner?.()||!images.assets.has(id))return null;
      return {id,title:images.assets.get(id)||'Canvas image',sceneId:listeningScene.id};
    }
    function editSceneImage(id){
      const asset=editableSceneImage(id);
      if(!asset)return;
      const wasOpen=sceneImagePicker.open;
      sceneImagePicker.open=false;
      if(options.onEditSceneImage(asset)===false&&wasOpen)sceneImagePickerLabel.focus({preventScroll:true});
    }
    function replaceSceneImage(id){
      const asset=editableSceneImage(id,'onReplaceSceneImage');
      if(!asset)return;
      const wasOpen=sceneImagePicker.open;
      sceneImagePicker.open=false;
      if(options.onReplaceSceneImage(asset)===false&&wasOpen)sceneImagePickerLabel.focus({preventScroll:true});
    }
    function renderSceneImageChoices(){
      const images=listeningScene?.images;
      sceneImagePicker.open=false;
      sceneImagePicker.hidden=!images?.editable;
      sceneImageChoices.replaceChildren();
      if(images?.editable)for(const [id,title] of images.assets){
        const item=document.createElement('li');
        for(const [callback,action,label] of [['onEditSceneImage','sceneImageEdit','Edit'],['onReplaceSceneImage','sceneImageReplace','Replace']]){
          if(!editableSceneImage(id,callback))continue;
          const button=document.createElement('button');
          button.type='button';button.dataset[action]=id;
          button.textContent=label+' '+(title||'canvas image');
          if(action==='sceneImageReplace')button.title='Choose an existing image';
          item.append(button);
        }
        sceneImageChoices.append(item);
      }
      codingImageEdit.hidden=!editableSceneImage(codingImageModalTrigger?.id);
      codingImageReplace.hidden=!editableSceneImage(codingImageModalTrigger?.id,'onReplaceSceneImage');
    }
    function postSceneImages(images){
      if(images?.ready&&images.loaded.size){
        images.layer.contentWindow?.postMessage({stonefellowSceneImages:[...images.loaded.values()]},'*');
      }
    }
    async function loadSceneImage(images,id,title){
      try{
        const {signal}=images.request;
        const preview=codingImageBatch?.images.get(id);
        let blob=preview&&activeCodingImage(preview)?await preview.result:null;
        if(signal.aborted||listeningScene?.images!==images||!options.canUseScenes?.())return;
        if(!blob)blob=await options.onSceneImage(id,signal,images.scene);
        if(signal.aborted||listeningScene?.images!==images||!options.canUseScenes?.()||!validSceneImageBlob(blob))return;
        images.loaded.set(id,{id,title,blob});
        postSceneImages(images);
      }catch(_){/* Keep the authored fallback while an image is unavailable. */}
    }
    function updateSceneTrackData(data,trackId,trackDatasets=null){
      const images=listeningScene.images;
      const source=sceneDocument(data.html,data.css,data.disclosure,data.reactive,!!images,data.datasets,trackId,trackDatasets,data.script);
      if(!source||source===data.layer.srcdoc)return;
      // The same frame, audio tap and canvas shell survive a track-data change.
      destroyCanvasAudio();
      if(sceneTracks?.frame===data.layer){
        sceneTracks.ready=false;
        sceneTracks.ids=sceneTrackIds(source);
        sceneTracks.revision=++sceneTrackRevision;
      }
      if(reactiveScene?.frame===data.layer){
        reactiveScene.ready=false;
        reactiveScene.sentLevels=null;
      }
      if(images)images.ready=false;
      data.layer.style.visibility='hidden';
      data.layer.srcdoc=source;
      startCanvasAudio(data.layer,data.script);
    }
    async function syncSceneTrackData(){
      const data=listeningScene?.trackData;
      if(!data||!options.canUseScenes?.())return;
      const trackId=currentSceneTrackId();
      if(data.trackId===trackId)return;
      data.request?.abort();
      data.trackId=trackId;
      data.request=null;
      updateSceneTrackData(data,trackId);
      if(!trackId||!(Number.isSafeInteger(data.id)&&data.id>0||typeof data.id==='string'&&/^[a-f0-9]{32}$/.test(data.id))||typeof options.onSceneTrackData!=='function')return;
      const request=new AbortController();
      data.request=request;
      try{
        const scene=await options.onSceneTrackData(data.id,trackId,request.signal);
        if(!request.signal.aborted&&listeningScene?.trackData===data&&options.canUseScenes?.()&&
          currentSceneTrackId()===trackId&&(scene?.gallery_id||scene?.id)===data.id){
          const currentMatches=scene.dataset_track_id===trackId;
          const refreshLyrics=Object.hasOwn(data.datasets??{},'published_lyrics')||scene.datasets?.published_lyrics;
          if(refreshLyrics){
            const updated={...data.datasets};
            if(scene.datasets?.published_lyrics)updated.published_lyrics=scene.datasets.published_lyrics;
            else delete updated.published_lyrics;
            data.datasets=updated;
          }
          if(currentMatches||refreshLyrics)updateSceneTrackData(data,trackId,currentMatches?scene.datasets:null);
        }
      }catch(_){/* Keep the authored empty state when current-track data cannot be loaded. */}
      finally{if(data.request===request)data.request=null;}
    }
    function showScene(scene,{standard=false,focus=true}={}){
      const kind=scene?.canvas_kind,space=kind==='space_eq',custom=kind==='custom';
      const room=!space&&!custom;
      const builtInLabel=standard&&!space?(custom?'Built-in catalog layout':'Built-in layout'):'';
      const trackId=typeof scene?.track_id==='string'?scene.track_id.trim():'';
      if(destroyed||!options.canUseScenes?.()||(room&&!trackId)||trackId.length>180||
        (scene?.track_id!=null&&typeof scene.track_id!=='string')||typeof scene?.title!=='string'||!scene.title.trim())return false;
      const sourceAvailable=room&&scene.song_title!==null;
      const disclosure=custom&&typeof scene.html==='string'&&/<details[\s>]/.test(scene.html)&&/<summary[\s>]/.test(scene.html);
      const script=sceneScript(scene);
      if(!script||(!custom&&script.js))return false;
      const building=['generating','streaming'].includes(codingRoot.dataset.state);
      const reactive=custom&&!motionOff&&typeof scene.css==='string'&&sceneAudioVariables.test(scene.css);
      const dynamic=custom&&typeof scene.html==='string'&&hasSceneCurrentTrackBinding(scene.html,scene.datasets);
      const currentTrackId=dynamic?currentSceneTrackId():'';
      const trackDatasets=currentTrackId&&scene.dataset_track_id===currentTrackId?scene.datasets:null;
      const imageAssets=custom&&typeof options.onSceneImage==='function'?sceneImageAssets(scene):null;
      const hasImages=!!imageAssets?.size;
      const owner=options.getSceneOwner?.();
      const editableImages=hasImages&&!!owner&&Number.isSafeInteger(scene.id)&&scene.id>0&&
        (typeof options.onEditSceneImage==='function'||typeof options.onReplaceSceneImage==='function');
      const source=space?'':sceneDocument(scene.html,scene.css,disclosure,reactive,hasImages,custom?scene.datasets:null,currentTrackId,trackDatasets,script);
      if(!space&&!source)return false;
      const trackIds=source?sceneTrackIds(source):new Set();
      const trackActions=custom&&typeof options.onSceneCatalogPlay==='function'&&scene.html.includes('sf-action-play-track')&&
        Object.values(scene.datasets||{}).some(dataset=>dataset?.actions?.play_track?.field==='track_id');
      const interactive=disclosure||!!script.js||trackActions;
      listeningScene?.trackData?.request?.abort();
      listeningScene?.images?.request.abort();
      destroyCanvasAudio();
      const layer=document.createElement(space?'canvas':'iframe');
      if(space){
        layer.className='sf-agent-space-eq';
        layer.setAttribute('aria-hidden','true');
      }else{
        layer.setAttribute('sandbox',reactive||script.js||hasImages||trackActions?'allow-scripts':'');
        layer.setAttribute('allow',"geolocation 'none'");
        layer.setAttribute('referrerpolicy','no-referrer');
        layer.setAttribute('tabindex',interactive?'0':'-1');
        if(interactive)layer.className='sf-agent-scene-interactive';
        layer.title=custom?(standard?'Built-in catalog canvas background':'Custom canvas background'):
          standard?'Built-in listening room background':'Agent-authored listening room background';
        layer.srcdoc=source;
      }
      const moment=scene.story;
      const story=sourceAvailable&&Number.isSafeInteger(moment?.id)&&moment.id>0&&Number.isSafeInteger(moment.at_ms)&&moment.at_ms>=0
        ?Object.freeze({id:moment.id,at_ms:moment.at_ms,title:String(moment.title||'').slice(0,180),
          summary:String(moment.summary||'').slice(0,1000),story_slug:String(moment.story_slug||'').slice(0,160)}) : null;
      const timing=scene.lyric_cue;
      const cue=custom&&trackId&&Number.isSafeInteger(timing?.start_ms)&&Number.isSafeInteger(timing?.end_ms)&&
        timing.start_ms>=0&&timing.end_ms>timing.start_ms?{start_ms:timing.start_ms,end_ms:timing.end_ms}:null;
      releaseSpaceAudio((spaceScene||reactiveScene)?.tap);
      spaceScene=space?createSpaceScene(layer):null;
      reactiveScene=reactive?{frame:layer,levels:new Float32Array(24),tap:null,dirty:true,ready:false}:null;
      sceneTracks=trackActions?{frame:layer,revision:++sceneTrackRevision,ready:false,ids:trackIds}:null;
      if(sceneTracks){
        const controls=sceneTracks;
        layer.addEventListener('load',()=>{
          if(sceneTracks!==controls)return;
          controls.ready=true;postSceneTrackState();
        });
      }
      if(reactiveScene){
        const audioPreview=reactiveScene;
        layer.addEventListener('load',()=>{
          if(reactiveScene!==audioPreview)return;
          audioPreview.ready=true;
          audioPreview.dirty=true;
          drawSceneAudio();
        });
      }
      const trackData=dynamic?{id:scene.gallery_id||scene.id,html:scene.html,css:scene.css,disclosure,reactive,script,layer,datasets:scene.datasets,
        trackId:!currentTrackId||trackDatasets?currentTrackId:null,request:null}:null;
      if(trackData)layer.addEventListener('load',()=>{layer.style.visibility='';});
      const images=hasImages?{scene,layer,owner,editable:editableImages,assets:imageAssets,ready:false,loaded:new Map(),request:new AbortController()}:null;
      if(images)layer.addEventListener('load',()=>{
        if(listeningScene?.images!==images)return;
        images.ready=true;
        postSceneImages(images);
      });
      const sources=scene.artist_inputs?.data_sources;
      const dataSources=custom&&Array.isArray(sources)?sources
        .filter(source=>typeof source?.alias==='string'&&/^[a-z][a-z0-9-]{0,31}$/.test(source.alias)):[];
      const dataAliases=dataSources.filter(source=>source.product!=='location.proximity').map(source=>source.alias);
      const dataLocationAliases=dataSources.filter(source=>source.location_id==='published-shows'&&
        typeof source.product==='string'&&source.product.startsWith('weather.')).map(source=>source.alias);
      const dataProximitySources=dataSources.filter(source=>source.product==='location.proximity');
      listeningScene={id:scene.id,galleryId:scene.gallery_id,trackId,story,cue,trackData,images,layer,script,owner,dataAliases,dataLocationAliases,dataProximitySources};
      renderSceneImageChoices();
      renderSceneCatalog(scene.catalog_blocks);
      const artwork=sourceAvailable?sceneArtwork(scene.artwork):'';
      sceneArt.hidden=!artwork;
      if(artwork)sceneArt.src=artwork;
      else sceneArt.removeAttribute('src');
      sceneTitle.textContent=space?'Space EQ':custom?'Custom canvas':'Canvas 1 · Listening Room';
      sceneTitle.setAttribute('aria-label',sceneTitle.textContent+(space?'':(custom?': ':' for ')+scene.title.slice(0,200)));
      const sourceUnavailable=room&&!sourceAvailable;
      sceneSignal.hidden=!space&&!reactive&&!builtInLabel&&!sourceUnavailable;
      sceneSignalText(space||reactive?(motionOff||(space&&!spaceScene.context)?'Still view':'Play music to activate'):
        sourceUnavailable?'Source song unavailable':builtInLabel);
      sceneStory.hidden=!story||typeof options.onSceneStory!=='function';
      if(story){
        const seconds=Math.floor(story.at_ms/1000);
        const at=Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');
        sceneStory.textContent='Story at '+at;
        sceneStory.setAttribute('aria-label','Open Song Moment at '+at+(story.title?' · '+story.title:''));
      }else{
        sceneStory.textContent='Open Song Moment';
        sceneStory.removeAttribute('aria-label');
      }
      by(sceneRoot,'[data-agent-action="play-scene-track"]').hidden=!sourceAvailable||typeof options.onScenePlay!=='function';
      sceneBackdrop.replaceChildren(layer);
      startCanvasAudio(layer,script);
      sceneBackdrop.hidden=false;
      mesh.hidden=true;
      sceneRoot.hidden=false;
      sceneFooterActions.hidden=false;
      sceneFeedback.hidden=!Number.isSafeInteger(scene.id)||scene.id<1;
      if(sceneAccess)sceneAccess.hidden=typeof options.onSceneAccess!=='function'||(!Number.isSafeInteger(scene.id)&&!scene.gallery_id);
      root.classList.add('has-listening-scene');
      root.classList[interactive?'add':'remove']('has-scene-interactive');
      setAgentPalette(scene);
      setSceneAgentDesign(scene.agent_design);
      syncSceneCue();
      if(space){
        setSceneCoding('idle');
        fit(spaceScene.canvas,spaceScene.context);
        syncSceneAudio();drawSceneAudio();
      }else{
        codingCode={html:script.js?scene.html:stripLegacyPlayer(scene.html),css:scene.css,js:script.js};
        if(!building||!focus){codingVisible=false;codingDismissed=false;}
        setSceneCoding('ready',builtInLabel?builtInLabel+' source. No Active Tokens were used.':undefined);
        if(reactive)syncSceneAudio();
      }
      void syncSceneTrackData();
      if(images)for(const [id,title] of imageAssets)void loadSceneImage(images,id,title);
      if(focus)sceneTitle.focus({preventScroll:true});
      options.onSceneMounted?.(scene);
      return true;
    }
    function closeScene(focus=true){
      if(!listeningScene)return false;
      listeningScene.trackData?.request?.abort();
      listeningScene.images?.request.abort();
      listeningScene=null;
      renderSceneImageChoices();
      releaseSpaceAudio((spaceScene||reactiveScene)?.tap);
      spaceScene=null;
      reactiveScene=null;
      destroyCanvasAudio();
      sceneTracks=null;
      sceneRoot.hidden=true;
      sceneFooterActions.hidden=true;
      sceneBackdrop.hidden=true;
      sceneBackdrop.replaceChildren();
      mesh.hidden=false;
      if(motionOff)meshDraw(0);
      sceneArt.removeAttribute('src');
      sceneTitle.textContent='';
      sceneTitle.removeAttribute('aria-label');
      sceneStory.removeAttribute('aria-label');
      sceneSignal.hidden=true;sceneSignalText('');
      renderSceneCatalog(null);
      root.classList.remove('has-listening-scene');root.classList.remove('has-scene-interactive');root.classList.remove('has-scene-lyric-cue');
      setAgentPalette();
      setSceneAgentDesign();
      setSceneCoding('idle');
      if(focus){
        focusCanvas();
        options.onSceneClose?.();
      }
      return true;
    }
    function syncSceneAccess(){
      const allowed=!!options.canUseScenes?.();
      if(workflow&&workflow.owner!==options.getSceneOwner?.()){setSceneWorkflow(null);setSceneCoding('idle');}
      sceneHistory.hidden=!allowed||options.canManageScenes?.()===false||typeof options.onOpenSceneHistory!=='function';
      if(codingImageBatch&&codingImageBatch.owner!==options.getSceneOwner?.())setSceneImage('clear');
      if(sceneHistoryView&&sceneHistoryView.owner!==options.getSceneOwner?.())closeSceneHistory();
      if(listeningScene?.images?.editable&&listeningScene.images.owner!==options.getSceneOwner?.())closeScene(false);
      if(sceneAudioHost&&listeningScene?.owner!==options.getSceneOwner?.())closeScene(false);
      if(!allowed){
        if(!closeScene(false))setSceneCoding('idle');
        closeSceneHistory();
      }
    }
    function closeSceneHistory(){
      if(pageView!=='scene-history'||pageRail.hidden)return false;
      closePage(true);
      return true;
    }
    function showSceneHistory(rows){
      if(destroyed||!options.canUseScenes?.()||!Array.isArray(rows))return false;
      const host=openPage('scene-history');
      const intro=document.createElement('p');intro.className='sf-agent-scene-history-intro';
      intro.textContent='Reopen or delete your saved canvases.';
      const list=document.createElement('ul');list.className='sf-agent-scene-history';
      const status=document.createElement('p');status.className='sf-agent-scene-history-feedback';status.setAttribute('role','status');
      const empty=document.createElement('p');empty.className='sf-agent-scene-history-empty';empty.textContent='No saved canvases yet.';
      const view={rows:new Map(),list,status,empty,confirm:null,owner:options.getSceneOwner?.(),imageRequest:null};
      sceneHistoryView=view;
      for(const row of rows.slice(0,100)){
        if(!Number.isSafeInteger(row?.id)||row.id<1||view.rows.has(row.id)||sceneDeletions.get(row.id)==='deleted')continue;
        const entry=document.createElement('li'),details=document.createElement('div');
        const title=document.createElement('strong');title.textContent=String(row.title||'Canvas').slice(0,200);
        details.appendChild(title);
        const when=new Date(String(row.created_at||'').slice(0,64));
        if(Number.isFinite(when.getTime())){
          const time=document.createElement('time');time.dateTime=when.toISOString();
          time.textContent=when.toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'});details.appendChild(time);
        }
        const open=document.createElement('button');open.type='button';open.textContent='Open';
        open.dataset.agentAction='restore-scene';open.dataset.sceneId=String(row.id);
        open.setAttribute('aria-label','Open '+title.textContent);
        entry.appendChild(details);entry.appendChild(open);
        const record={entry,open};
        view.rows.set(row.id,record);
        if(typeof options.onDeleteScene==='function')addSceneHistoryDelete(view,record,row.id,title.textContent);
        if(row.canvas_kind==='custom'&&typeof options.onSubmitScene==='function'){
          const submit=document.createElement('button');submit.type='button';submit.textContent='Submit';
          submit.setAttribute('aria-label','Submit '+title.textContent+' to the Canvas Catalog');
          submit.addEventListener('click',()=>{if(activeSceneHistory(view))options.onSubmitScene(row.id);});entry.appendChild(submit);
        }
        list.appendChild(entry);
      }
      list.hidden=view.rows.size===0;
      empty.hidden=!list.hidden;
      host.replaceChildren(intro,list,status,empty);
      addSceneHistoryImageFolder(host,view);
      return true;
    }
    function addSceneHistoryImageFolder(host,view){
      if(typeof options.onLoadSceneImages!=='function'||typeof options.getSceneImageUrl!=='function')return;
      const folder=document.createElement('details');folder.className='sf-agent-scene-image-folder';
      const summary=document.createElement('summary');summary.textContent='Generated Images';
      const hint=document.createElement('p');hint.textContent='Copy an image reference and paste it into your next canvas request.';
      const status=document.createElement('p');status.setAttribute('role','status');
      const more=document.createElement('button');more.type='button';more.hidden=true;
      const list=document.createElement('ul');
      const shown=new Set();
      let loaded=false,cursor=null;
      const load=async()=>{
        if((loaded&&!cursor)||view.imageRequest||!activeSceneHistory(view))return;
        const request=new AbortController();
        view.imageRequest=request;
        status.textContent='Loading generated images…';
        more.disabled=true;
        try{
          const page=await options.onLoadSceneImages(cursor,request.signal);
          if(request.signal.aborted||!activeSceneHistory(view))return;
          if(!Array.isArray(page?.images))throw new Error('Invalid image inventory.');
          for(const image of page.images){
            if(!sceneImageId.test(image?.id||'')||shown.has(image.id))continue;
            const url=options.getSceneImageUrl(image.id);
            if(url){
              addSceneHistoryImage(list,status,view,image,url);
              shown.add(image.id);
            }
          }
          cursor=typeof page.next_cursor==='string'&&page.next_cursor.length<=128&&page.next_cursor!==cursor?page.next_cursor:null;
          loaded=true;
          status.textContent=shown.size?'':'Your generated images will appear here.';
          more.textContent='Load older images';
          more.hidden=!cursor;
        }catch(_){
          if(request.signal.aborted||!activeSceneHistory(view))return;
          status.textContent='Generated images could not be loaded.';
          more.textContent='Try again';
          more.hidden=false;
        }finally{
          if(view.imageRequest===request)view.imageRequest=null;
          more.disabled=false;
        }
      };
      more.addEventListener('click',()=>void load());
      folder.addEventListener('toggle',()=>{if(folder.open&&!loaded)void load();});
      folder.append(summary,hint,list,more,status);
      host.insertBefore(folder,view.list);
    }
    function addSceneHistoryImage(list,status,view,image,url){
      const title=typeof image.title==='string'&&image.title.trim()?image.title.slice(0,160):'Generated image';
      const row=document.createElement('li');
      const preview=document.createElement('img');preview.alt='';preview.loading='lazy';preview.decoding='async';preview.src=url;
      const name=document.createElement('strong');name.textContent=title;
      const copy=document.createElement('button');copy.type='button';copy.textContent='Copy reference';
      copy.setAttribute('aria-label','Copy reference for '+title);
      const reference=document.createElement('input');reference.type='text';reference.readOnly=true;reference.hidden=true;
      reference.value='{image-'+image.id+'}';
      reference.setAttribute('aria-label','Image reference for '+title);
      copy.addEventListener('click',async()=>{
        if(!activeSceneHistory(view))return;
        let copied=false;
        try{
          if(navigator.clipboard?.writeText){
            await navigator.clipboard.writeText(reference.value);
            copied=true;
          }
        }catch(_){/* Select the reference below if clipboard access is unavailable. */}
        if(!activeSceneHistory(view))return;
        if(!copied){
          reference.hidden=false;
          reference.focus({preventScroll:true});
          reference.select();
          try{copied=!!document.execCommand?.('copy');}catch(_){/* Keep it selected for manual copying. */}
        }
        status.textContent=copied?'Image reference copied.':'Image reference selected. Use your device’s copy command.';
      });
      row.append(preview,name,copy,reference);
      list.appendChild(row);
    }
    function addSceneHistoryDelete(view,record,id,title){
      const remove=document.createElement('button');remove.type='button';remove.textContent='Delete';
      remove.setAttribute('aria-label','Delete '+title);
      const confirmation=document.createElement('div');confirmation.className='sf-agent-scene-history-confirm';confirmation.hidden=true;
      const question=document.createElement('p');question.id='sfAgentCanvasDelete'+id;
      question.textContent='Permanently delete “'+title+'”?';
      const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancel';
      const confirm=document.createElement('button');confirm.type='button';confirm.textContent='Delete canvas';
      cancel.setAttribute('aria-describedby',question.id);confirm.setAttribute('aria-describedby',question.id);
      confirmation.appendChild(question);confirmation.appendChild(cancel);confirmation.appendChild(confirm);
      record.entry.appendChild(remove);record.entry.appendChild(confirmation);
      record.setPending=pending=>{
        record.open.disabled=remove.disabled=cancel.disabled=confirm.disabled=pending;
        confirm.textContent=pending?'Deleting…':'Delete canvas';
      };
      record.setPending(sceneDeletions.has(id));
      const dismiss=(focus=true)=>{
        if(confirmation.hidden||sceneDeletions.get(id)==='pending')return false;
        confirmation.hidden=true;
        view.confirm=null;
        if(focus)remove.focus({preventScroll:true});
        return true;
      };
      remove.addEventListener('click',()=>{
        if(!activeSceneHistory(view)||sceneDeletions.has(id))return;
        if(view.confirm&&!view.confirm(false))return;
        view.status.textContent='';confirmation.hidden=false;
        view.confirm=dismiss;cancel.focus({preventScroll:true});
      });
      cancel.addEventListener('click',()=>dismiss());
      confirm.addEventListener('click',async()=>{
        if(!activeSceneHistory(view)||confirmation.hidden||sceneDeletions.has(id))return;
        sceneDeletions.set(id,'pending');record.setPending(true);
        let error;
        try{await options.onDeleteScene(id);sceneDeletions.set(id,'deleted');}
        catch(failure){error=failure||new Error('Unable to delete this canvas.');sceneDeletions.delete(id);}
        const current=sceneHistoryView,affected=current?.rows.get(id);
        if(!affected||!activeSceneHistory(current))return;
        affected.setPending(false);
        const moveFocus=affected.entry.contains(document.activeElement)||
          (current===view&&document.activeElement===document.body);
        if(error){
          current.status.textContent=error.message||'Unable to delete this canvas.';
          if(moveFocus&&current===view)confirm.focus({preventScroll:true});
          return;
        }
        const records=[...current.rows.values()],index=records.indexOf(affected);
        const next=records[index+1]??records[index-1];
        if(current===view)dismiss(false);
        current.rows.delete(id);affected.entry.remove();
        current.list.hidden=current.rows.size===0;current.empty.hidden=!current.list.hidden;
        current.status.textContent='Canvas deleted.';
        if(moveFocus)(next&&!next.open.disabled?next.open:by(pageRail,'[data-agent-action="close-page"]'))?.focus({preventScroll:true});
      });
    }
    function activeSceneHistory(view){
      return !destroyed&&sceneHistoryView===view&&pageView==='scene-history'&&options.canUseScenes?.()&&
        view.owner===options.getSceneOwner?.();
    }
    function handleAction(event){
      const button=event.target.closest?.('[data-agent-action]');if(!button)return;
      const action=button.dataset.agentAction;
      if(root.dataset.sceneAgentDesign==='hidden'&&(action==='chat'||action==='start-chat'))return;
      if(action==='chat')options.onOpenChat?.();
      if(action==='start-chat')options.onStartChat?.();
      if(action==='latest-release')options.onWelcomeAction?.('latest-release');
      if(action==='welcome-play')options.onWelcomeAction?.('welcome-play');
      if(action==='random-playlist')options.onWelcomeAction?.('random-playlist');
      if(action==='save-playlist')options.onWelcomeAction?.('save-playlist');
      if(action==='open-saved-queue')options.onWelcomeAction?.('open-saved-queue');
      if(action==='skip-name')options.onSkipGuestName?.();
      if(action==='start-guide')startGuide();
      if(action==='capture')options.onStartCapture?.();
      if(action==='song-moments')options.onOpenMoments?.();
      if(action==='open-song-moment'&&!button.hidden&&button.dataset.momentId)
        options.onOpenMoment?.(Number(button.dataset.momentId),button);
      if(action==='listen-interpret'){
        const lyric=window.StonefellowSongExperience?.snapshot?.();
        if(lyric?.listening_mode==='catalog'&&(['starting','capturing','transcribing'].includes(lyric.phase)||canReviewLyrics(lyric)))openLyrics();
        else options.onListenInterpret?.();
      }
      if(action==='new-lyric-section'&&!lyricNew.disabled)options.onListenInterpret?.();
      if(action==='stop-lyrics'&&!lyricStop.disabled)options.onStopListening?.();
      if(action==='stop-listening')options.onStopListening?.();
      if(action==='show-player')showPlayer();
      if(action==='show-queue')options.onOpenQueue?.();
      if(action==='music-library')options.onOpenLibrary?.();
      if(action==='vinyl-builder')options.onOpenRecordBuilder?.();
      if(action==='commerce')options.onOpenCommerce?.();
      if(action==='music'){closeWorkspace();options.onNavigate?.('music');}
      if(action==='close-panel'){panel.hidden=true;remotePanelDismissed=true;updateDock();}
      if(action==='close-workspace')closeWorkspace();
      if(action==='close-page')dismissPage();
      if(action==='close-scene')closeScene();
      if(action==='canvas-access'&&!button.hidden)options.onSceneAccess?.();
      if(action==='stop-scene-build')void stopSceneBuild();
      if(action==='hide-scene-coding'){
        codingVisible=false;codingDismissed=true;syncCodingVisibility();
        focusCanvas();
      }
      if(action==='play-scene-track'&&listeningScene&&!button.hidden)options.onScenePlay?.(listeningScene.trackId);
      if(action==='open-scene-story'&&listeningScene?.story&&!button.hidden)options.onSceneStory?.(listeningScene.trackId,listeningScene.story);
      if(action==='scene-history'&&!button.hidden&&options.canUseScenes?.())options.onOpenSceneHistory?.();
      if(action==='restore-scene'&&pageView==='scene-history'&&options.canUseScenes?.()&&pageHost.contains(button)){
        const id=Number(button.dataset.sceneId);
        if(Number.isSafeInteger(id)&&id>0&&!sceneDeletions.has(id))options.onRestoreScene?.(id);
      }
    }
    cleanup.push(on(root,'click',handleAction));
    cleanup.push(on(sceneClose,'click',event=>{event.stopPropagation();closeScene();}));
    cleanup.push(on(sceneCode,'click',event=>{event.stopPropagation();codingVisible=!codingVisible;codingDismissed=!codingVisible;syncCodingVisibility();}));
    cleanup.push(on(sceneFeedback,'click',event=>{event.stopPropagation();if(listeningScene&&options.canUseScenes?.())options.onSceneFeedback?.(listeningScene.id);}));
    cleanup.push(on(sceneImageChoices,'click',event=>{
      const button=event.target.closest?.('button[data-scene-image-edit],button[data-scene-image-replace]');
      if(!event.isTrusted||!button)return;
      if(button.dataset.sceneImageReplace)replaceSceneImage(button.dataset.sceneImageReplace);
      else editSceneImage(button.dataset.sceneImageEdit);
    }));
    cleanup.push(on(root,'submit',event=>{
      if(!event.target?.matches?.('.sf-agent-guest-name-form'))return;
      event.preventDefault();
      const name=event.target.elements?.guest_name?.value||'';
      options.onGuestName?.(name);
    }));
    if(pagePortal!==root)cleanup.push(on(pagePortal,'click',handleAction));
    cleanup.push(on(window,'keydown',e=>{
      if(pageRail.hidden||e.defaultPrevented||document.body?.classList?.contains('sf-song-moments-open'))return;
      if(e.key==='Escape'){
        e.preventDefault();
        if(pageView!=='scene-history'||!sceneHistoryView?.confirm?.())dismissPage();
        return;
      }
      if(e.key==='Tab'){
        const focusables=Array.from(pageRail.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'))
          .filter(node=>!node.hidden&&node.getClientRects().length);
        if(!focusables.length){e.preventDefault();return;}
        const first=focusables[0],last=focusables[focusables.length-1];
        if(e.shiftKey&&(document.activeElement===first||!pageRail.contains(document.activeElement))){e.preventDefault();last.focus();}
        else if(!e.shiftKey&&(document.activeElement===last||!pageRail.contains(document.activeElement))){e.preventDefault();first.focus();}
      }
    }));
    cleanup.push(on(playerDock,'pointerenter',pausePlayerHide));
    cleanup.push(on(playerDock,'pointerleave',schedulePlayerHide));
    cleanup.push(on(playerDock,'focusin',pausePlayerHide));
    cleanup.push(on(playerDock,'focusout',schedulePlayerHide));
    cleanup.push(on(playerDock,'click',schedulePlayerHide));
    cleanup.push(on(visual,'pointerenter',event=>{if(event.pointerType==='touch')return;if(!floater.classList.contains('is-chrome-collapsed'))pausePlayerHide();}));
    cleanup.push(on(visual,'pointerleave',schedulePlayerHide));
    cleanup.push(on(sphere,'pointerdown',event=>{
      orbPointerType=event.pointerType||'mouse';
      if(event.isPrimary===false)orbActivation=null;
    }));
    cleanup.push(on(sphere,'click',event=>{
      if(suppressClick||!event.isTrusted){suppressClick=false;orbActivation=null;return;}
      showPlayer();
      if(event.detail===0){orbActivation=null;return;}
      const now=performance.now(),type=event.pointerType||orbPointerType||'mouse';
      const same=orbActivation&&now-orbActivation.at<=450&&type===orbActivation.type&&
        Math.hypot(event.clientX-orbActivation.x,event.clientY-orbActivation.y)<=24;
      orbActivation={at:now,type,x:event.clientX,y:event.clientY,count:same?orbActivation.count+1:1};
      if(orbActivation.count===2){
        if(mode==='speaking')options.onStopSpeaking?.();
        if(!options.isConversationActive?.()||options.needsVoiceGesture?.())options.onStartChat?.();
      }
    }));
    cleanup.push(on(sphere,'dblclick',event=>{event.preventDefault();}));
    cleanup.push(on(floater,'pointerdown',e=>{
      if(visual.hidden)return;
      if(e.target.closest?.('.sf-agent-live-feed'))return;
      if(e.target.closest('.sf-agent-dock-stack,.sf-agent-panel'))return;
      if(e.target.closest('button,input')&&e.target!==sphere)return;
      dragging={id:e.pointerId,x:e.clientX,y:e.clientY,left:floater.offsetLeft,top:floater.offsetTop,moved:false};
      (e.target===sphere?sphere:floater).setPointerCapture?.(e.pointerId);floater.classList.add('dragging');
    }));
    cleanup.push(on(floater,'pointermove',e=>{
      if(!dragging||dragging.id!==e.pointerId)return;
      const dx=e.clientX-dragging.x,dy=e.clientY-dragging.y;
      if(Math.hypot(dx,dy)>6){dragging.moved=true;orbActivation=null;}
      position(dragging.left+dx,dragging.top+dy);
    }));
    cleanup.push(on(floater,'pointerup',e=>{
      if(!dragging||dragging.id!==e.pointerId)return;
      suppressClick=dragging.moved;dragging=null;floater.classList.remove('dragging');
      roam.start=0;roam.delay=performance.now()+3000;
    }));
    cleanup.push(on(floater,'pointercancel',()=>{dragging=null;orbActivation=null;floater.classList.remove('dragging');}));
    // Pause travel on both the face and its attached player/lyric controls.
    // Restart the path from the current position, never jump to an old waypoint.
    cleanup.push(on(floater,'pointerenter',event=>{if(event.pointerType==='touch')return;agentPointerOver=true;hovered=true;if(!floater.classList.contains('is-chrome-collapsed'))pausePlayerHide();}));
    cleanup.push(on(floater,'pointerleave',()=>{agentPointerOver=false;hovered=false;roam.start=0;roam.delay=0;schedulePlayerHide();}));
    cleanup.push(on(floater,'focusin',()=>{hovered=true;showPlayer();}));
    cleanup.push(on(floater,'focusout',e=>{if(!floater.contains(e.relatedTarget)){hovered=false;roam.start=0;roam.delay=0;schedulePlayerHide();}}));
    cleanup.push(on(window,'pointermove',e=>gazeAt(e.clientX,e.clientY),{passive:true}));
    cleanup.push(on(window,'message',receiveSceneTrack));
    cleanup.push(on(window,'keydown',e=>{
      if(e.key!=='Escape'||e.defaultPrevented||document.body?.classList?.contains('sf-song-moments-open'))return;
      if(sceneImagePicker.open&&sceneImagePicker.contains(document.activeElement)){
        e.preventDefault();sceneImagePicker.open=false;sceneImagePickerLabel.focus({preventScroll:true});
      }else if(workspace.classList.contains('open'))closeWorkspace();
      else if(manualAgentHidden&&options.onShowAgent){
        e.preventDefault();options.onShowAgent();
      }else if(listeningScene&&(root.dataset.sceneAgentDesign==='hidden'||sceneRoot.contains(document.activeElement)||sceneFooterActions.contains(document.activeElement))){
        e.preventDefault();closeScene();
      }
    }));
    cleanup.push(on(audio,'play',()=>{state('music');showPlayer();syncScenePlayback();}));
    cleanup.push(on(audio,'pause',()=>{if(mode==='music')state('idle');syncScenePlayback();}));
    for(const event of ['playing','ended','loadstart','emptied','seeking','seeked'])
      cleanup.push(on(audio,event,syncScenePlayback));
    for(const event of ['loadedmetadata','durationchange'])cleanup.push(on(audio,event,refreshCanvasAudio));
    cleanup.push(on(audio,'timeupdate',()=>{syncSceneCue();refreshCanvasAudio();}));
    cleanup.push(on(window,'resize',resize));
    const resizeObserver=typeof ResizeObserver==='function'?new ResizeObserver(resize):null;
    resizeObserver?.observe(root);
    resizeObserver?.observe(floater);
    const footerBounds=document.querySelector?.('.chat-footer');
    if(footerBounds)resizeObserver?.observe(footerBounds);
    let selectedAtmosphere='ember';try{selectedAtmosphere=window.localStorage?.getItem(atmosphereKey)||'ember'}catch(_){}
    applyAtmosphere(selectedAtmosphere,false);
    resize();state(audio&&!audio.paused?'music':'idle');session();syncPlayer();syncVoice();syncSceneAccess();
    if(manualAgentHidden)syncSceneAgentDesign();
    restorePlayerFocus(initialPlayerFocus);
    if(!motionOff)raf=requestAnimationFrame(tick);
    else {meshDraw(0);cloudDraw(0);orbDraw(0);}
    current={root,state,session,syncPlayer,syncVoice,syncPlaylist:syncListeningButtons,openPage,openLyrics,closePage,startGuide,syncTranscript,showConversation,setWelcomeState,setSongMoment,showScene,closeScene,setAgentHidden,sceneActive:()=>!!listeningScene,showSceneHistory,closeSceneHistory,syncSceneAccess,setSceneCoding,setSceneWorkflow,setSceneCandidateCode,prepareSceneTest,setSceneImage,appendSceneCode,setAtmosphere:applyAtmosphere,getAtmosphere:()=>atmosphere,destroy(){
      destroyed=true;cancelAnimationFrame(raf);cancelAnimationFrame(pageFrame);window.clearTimeout(pageTimer);window.clearTimeout(workspaceTimer);pausePlayerHide();
      sceneHistoryView?.imageRequest?.abort();
      manualAgentHidden=false;
      if(!closeScene(false)){
        setSceneAgentDesign();
        setSceneCoding('idle');
      }
      closeGuide(false);guideLayer.remove();
      document.body?.classList?.remove('sf-agent-page-open');
      resizeObserver?.disconnect();playerObserver?.disconnect();cleanup.forEach(fn=>fn());
      restorePlayerFocus(placePlayer(true));
      if(voiceTranscript && originalTranscriptParent){
        const before = originalTranscriptNext?.parentNode===originalTranscriptParent ? originalTranscriptNext : null;
        originalTranscriptParent.insertBefore(voiceTranscript,before);
      }
      if(mesh.parentNode!==root)mesh.remove();
      if(pagePortal!==root)pagePortal.remove();
      sceneFooterActions.remove();
      delete document.body.dataset.sfAtmosphere;
      root.remove();
    }};
    return true;
  }
  function unmount(){if(current){current.destroy();current=null;}}
  function applyCommand(text){
    const value=String(text||'').trim().toLowerCase();
    const match=value.match(/^(?:(?:stonefellow|agent)[, ]+)?(?:set|switch|change|use)(?: the)? (?:agent )?(?:atmosphere|environment|theme)(?: (?:to|as))? (ember|blue mesh|mesh|violet signal|violet|lunar|moonlit horizon|moonlight)[.!]?$/);
    if(!match)return null;
    const named=match[1]==='blue mesh'?'mesh':match[1]==='violet signal'?'violet':['moonlit horizon','moonlight'].includes(match[1])?'lunar':match[1];
    return current?.setAtmosphere(named)||null;
  }
  window.SFPublicAgentCanvas=Object.freeze({mount,unmount,applyCommand,setAtmosphere:name=>current?.setAtmosphere(name)||false,getAtmosphere:()=>current?.getAtmosphere(),listAtmospheres:()=>['ember','mesh','violet','lunar'],setState:(mode,label)=>current?.state(mode,label),syncSongExperience:()=>current?.session(),syncPlayer:()=>current?.syncPlayer(),syncPlaylist:()=>current?.syncPlaylist(),syncVoice:()=>current?.syncVoice(),syncTranscript:()=>current?.syncTranscript(),showConversation:(role,text)=>current?.showConversation(role,text),setWelcomeState:state=>current?.setWelcomeState(state),setSongMoment:moment=>current?.setSongMoment(moment)||false,showScene:(scene,options)=>current?.showScene(scene,options)||false,closeScene:(focus=true)=>current?.closeScene(focus)||false,setAgentHidden:hidden=>current?.setAgentHidden(hidden)||false,sceneActive:()=>current?.sceneActive()||false,showSceneHistory:rows=>current?.showSceneHistory(rows)||false,closeSceneHistory:()=>current?.closeSceneHistory()||false,syncSceneAccess:()=>current?.syncSceneAccess(),setSceneCoding:(state,text)=>current?.setSceneCoding(state,text)||false,setSceneWorkflow:update=>current?.setSceneWorkflow(update)||false,setSceneCandidateCode:scene=>current?.setSceneCandidateCode(scene)||false,prepareSceneTest,prepareGalleryPreview,setSceneImage:(state,asset,progress)=>current?.setSceneImage(state,asset,progress)||false,appendSceneCode:(kind,text)=>current?.appendSceneCode(kind,text)||false,startGuide:()=>current?.startGuide(),openPage:view=>current?.openPage(view),openLyrics:()=>current?.openLyrics(),closePage:(immediate=false)=>current?.closePage(immediate),active:()=>!!current});
})();
