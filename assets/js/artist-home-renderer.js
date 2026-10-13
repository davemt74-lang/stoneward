(() => {
'use strict';
  function renderSection(s,helpers){
    const {esc,time,homepageMoney,newsletterSignupHtml,homeTrackCard}=helpers;
    const k=s.section_key,d=s.data||{},ey='<div class="artist-home-eyebrow">'+esc(s.eyebrow||'STONEFELLOW')+'</div>',head='<h2>'+esc(s.heading||'')+'</h2>',body=s.body_text?'<p class="artist-home-copy">'+esc(s.body_text)+'</p>':'';
    if(k==='hero')return '<section class="artist-home-hero" '+(d.hero_image?'style="--artist-home-hero:url(\''+esc(d.hero_image)+'\')"':'')+'><div class="artist-home-hero-shade"></div><div class="artist-home-hero-copy">'+(d.logo?'<img class="artist-home-logo" src="'+esc(d.logo)+'" alt="Stonefellow">':ey)+'<h1>'+esc(s.heading||'Stonefellow')+'</h1>'+body+'<div class="artist-home-actions"><button class="buy-primary" data-home-view="'+esc(d.primary_view||'music')+'" data-home-event="hero_primary">'+esc(d.primary_label||'Listen now')+'</button><button class="text-control" data-home-view="'+esc(d.secondary_view||'releases')+'" data-home-event="hero_secondary">'+esc(d.secondary_label||'Explore releases')+'</button><button class="text-control" data-home-agent>Ask Stonefellow</button></div></div></section>';
    if(k==='featured_release')return '<section class="artist-home-section artist-home-featured-release">'+(d.cover?.url?'<button class="artist-home-cover" data-home-release="'+esc(d.id)+'"><img src="'+esc(d.cover.url)+'" alt="'+esc(d.cover.alt_text||d.title)+'"></button>':'<div class="artist-home-cover placeholder">SF</div>')+'<div>'+ey+head+body+'<h3>'+esc(d.title||'')+'</h3><p class="artist-home-meta">'+esc([d.type,d.release_date].filter(Boolean).join(' · '))+'</p>'+(d.description?'<p>'+esc(d.description)+'</p>':'')+'<div class="artist-home-actions"><button class="buy-primary" data-home-release="'+esc(d.id)+'">Open release</button>'+(d.dsp_links||[]).slice(0,3).map(x=>'<a class="text-control" data-home-dsp href="'+esc(x.url)+'" target="_blank" rel="noopener">'+esc(String(x.provider).replaceAll('_',' '))+' ↗</a>').join('')+'</div></div></section>';
    if(k==='featured_track')return '<section class="artist-home-section artist-home-track"><div>'+(d.artwork?.url?'<img class="artist-home-track-art" src="'+esc(d.artwork.url)+'" alt="'+esc(d.artwork.alt_text||d.title)+'">':'<div class="artist-home-track-art placeholder">SF</div>')+'</div><div>'+ey+head+body+'<h3>'+esc(d.title||'')+'</h3><p class="artist-home-meta">'+esc(d.release||'Stonefellow')+(d.duration?' · '+time(d.duration):'')+'</p>'+(d.story?'<p>'+esc(d.story).slice(0,320)+'</p>':'')+'<div class="artist-home-actions">'+(d.audio?.url?'<button class="buy-primary" data-home-play="'+esc(d.id)+'">▶ Play</button>':'<button class="buy-primary" disabled>Public audio not enabled</button>')+'<button class="text-control" data-home-track="'+esc(d.id)+'">Song story</button></div></div></section>';
    if(k==='latest_releases')return '<section class="artist-home-block">'+ey+head+body+'<div class="artist-home-release-grid">'+(Array.isArray(d)?d:[]).map(r=>'<button class="artist-home-release-card" data-home-release="'+esc(r.id)+'">'+(r.cover?.url?'<img src="'+esc(r.cover.url)+'" alt="'+esc(r.cover.alt_text||r.title)+'">':'<span class="artist-home-square placeholder">SF</span>')+'<strong>'+esc(r.title)+'</strong><small>'+esc([r.type,r.release_date].filter(Boolean).join(' · '))+'</small></button>').join('')+'</div></section>';
    if(k==='featured_video')return '<section class="artist-home-section artist-home-video">'+(d.poster?.url?'<button class="artist-home-video-poster" type="button" data-home-video="'+esc(d.slug)+'"><img src="'+esc(d.poster.url)+'" alt="'+esc(d.poster.alt_text||d.title)+'"><span>▶</span></button>':'<button class="artist-home-video-poster placeholder" type="button" data-home-video="'+esc(d.slug)+'"><span>▶</span></button>')+'<div>'+ey+head+body+'<h3>'+esc(d.title||'')+'</h3><p class="artist-home-meta">'+esc(String(d.kind||'video').replaceAll('_',' '))+(d.video?.duration?' · '+time(d.video.duration):'')+'</p>'+(d.description?'<p>'+esc(d.description).slice(0,320)+'</p>':'')+'<div class="artist-home-actions"><button class="buy-primary" type="button" data-home-video="'+esc(d.slug)+'">Watch video</button><button class="text-control" type="button" data-view="videos">All videos</button></div></div></section>';
    if(k==='upcoming_show')return '<section class="artist-home-section artist-home-show">'+(d.poster?.url?'<img class="artist-home-show-poster" src="'+esc(d.poster.url)+'" alt="'+esc(d.poster.alt_text||d.title)+'">':'<div class="artist-home-show-poster placeholder">LIVE</div>')+'<div>'+ey+head+body+'<h3>'+esc(d.title||'')+'</h3><p class="artist-home-meta">'+esc([d.date,d.venue,d.location].filter(Boolean).join(' · '))+'</p><div class="artist-home-actions"><button class="buy-primary" data-home-show="'+esc(d.id)+'">Show details</button>'+(d.ticket_url?'<a class="text-control" data-home-ticket href="'+esc(d.ticket_url)+'" target="_blank" rel="noopener">Tickets ↗</a>':'')+'</div></div></section>';
    if(k==='merch')return '<section class="artist-home-section artist-home-merch">'+(d.image?'<img class="artist-home-merch-image" src="'+esc(d.image)+'" alt="'+esc(d.title)+'">':'<div class="artist-home-merch-image placeholder">SF</div>')+'<div>'+ey+head+body+'<h3>'+esc(d.title||'')+'</h3><p class="artist-home-meta">'+homepageMoney(d.price_cents||0)+'</p>'+(d.description?'<p>'+esc(d.description).slice(0,280)+'</p>':'')+'<button class="buy-primary" data-home-store="'+esc(d.slug||d.id)+'">Open store</button></div></section>';
    if(k==='custom_blocks'){
      const tiles=Array.isArray(d)?d:[];
      return '<section class="artist-home-block artist-home-custom-blocks">'+ey+head+body+
        '<div class="artist-home-custom-grid">'+tiles.map(block=>{
          const item=block.data||{},type=block.type,wide=block.layout==='wide'?' wide':'',
            compact=block.layout==='compact'?' compact':'',title=block.heading||item.title||'Stonefellow',
            detail=block.body_text||item.description||'';
          let art='',cta='';
          if(type==='release'){
            art=item.cover?.url?'<img src="'+esc(item.cover.url)+'" alt="'+esc(item.cover.alt_text||title)+'">':'';
            cta='<button type="button" class="buy-primary" data-home-release="'+esc(item.id)+'">'+esc(block.button_label||'Explore release')+'</button>';
          }else if(type==='ticket'){
            art='<span class="artist-home-custom-ticket-mark">LIVE / VIP</span>';
            cta='<button type="button" class="buy-primary" data-home-ticket-offer="'+esc(item.id)+'">'+esc(block.button_label||'View tickets')+'</button>';
          }else if(type==='merch'){
            art=item.image?'<img src="'+esc(item.image)+'" alt="'+esc(item.title||title)+'">':'';
            cta='<button type="button" class="buy-primary" data-home-store="'+esc(item.slug||item.id)+'">'+esc(block.button_label||'Shop merch')+'</button>';
          }else if(type==='text'){
            cta='<button type="button" class="text-control" data-home-view="about">'+esc(block.button_label||'Our story')+'</button>';
          }
          const meta=type==='release'?[item.type,item.release_date].filter(Boolean).join(' · '):
            type==='ticket'?[item.show_date,item.venue,(item.price_cents>0?homepageMoney(item.price_cents):'RSVP / free'),(item.access?.allowed===false?'Access restrictions apply':'')].filter(Boolean).join(' · '):
            type==='merch'?[homepageMoney(item.price_cents||0),item.available===false?'Currently unavailable':''].filter(Boolean).join(' · '):'';
          return '<article class="artist-home-custom-card '+esc(type+wide+compact)+'">'+
            (art?'<div class="artist-home-custom-art">'+art+'</div>':'')+
            '<div class="artist-home-custom-copy"><small>'+esc(type==='ticket'?'Tickets + VIP':type==='merch'?'Merch':type==='release'?'Release':'Story')+'</small>'+
            '<h3>'+esc(title)+'</h3>'+(meta?'<p class="artist-home-meta">'+esc(meta)+'</p>':'')+
            (detail?'<p>'+esc(String(detail).slice(0,450))+'</p>':'')+
            '<div class="artist-home-custom-action">'+cta+'</div></div></article>';
        }).join('')+'</div></section>';
    }
    if(k==='artist_story')return '<section class="artist-home-section artist-home-story">'+(d.artist_photo?'<img class="artist-home-story-photo" src="'+esc(d.artist_photo)+'" alt="Stonefellow">':'')+'<div>'+ey+head+body+'<button class="text-control" data-home-view="'+esc(d.button_view||'about')+'" data-home-event="about_open">'+esc(d.button_label||'About Stonefellow')+'</button></div></section>';
    if(k==='newsletter')return '<section class="artist-home-block">'+ey+head+body+newsletterSignupHtml(true)+'</section>';
    if(k==='personalized'){const cont=d.continue_listening||[],fav=d.favorites?.tracks||[],suggestion=d.suggestion||{};return '<section class="artist-home-block artist-home-personalized">'+ey+head+body+(suggestion.title?'<div class="artist-home-personal-pick"><div><small>'+esc(suggestion.eyebrow||'AGENT PICK')+'</small><strong>'+esc(suggestion.title)+'</strong><p>'+esc(suggestion.text||'')+'</p></div><button class="text-control" data-home-personalized>Open</button></div>':'')+(cont.length?'<div class="home-card-grid compact-grid">'+cont.slice(0,4).map(x=>homeTrackCard(x,'resume')).join('')+'</div>':fav.length?'<div class="home-card-grid compact-grid">'+fav.slice(0,4).map(x=>homeTrackCard(x,'play')).join('')+'</div>':'')+'</section>';}
    return '';
  }

window.SFArtistHomeRenderer={renderSection};
})();
