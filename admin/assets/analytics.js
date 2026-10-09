(() => {
'use strict';

const funnel=(c,label,count,rate)=>`<div class="conversion-card"><small>${c.esc(label)}</small><strong>${Number(rate||0)}%</strong><span>${Number(count||0).toLocaleString()} users</span></div>`;

const trend=(c,rows)=>{
  if(!rows.length)return '<div class="empty">No daily listening data yet.</div>';
  const max=Math.max(1,...rows.flatMap(d=>[Number(d.listens||0),Number(d.completes||0),Number(d.skips||0)]));
  return `<div class="analytics-trend">${rows.map(d=>`<div class="analytics-day"><div class="analytics-bars"><i class="start" style="height:${Math.max(3,Math.round(Number(d.listens||0)/max*100))}%"></i><i class="complete" style="height:${Math.max(3,Math.round(Number(d.completes||0)/max*100))}%"></i><i class="skip" style="height:${Math.max(3,Math.round(Number(d.skips||0)/max*100))}%"></i></div><span>${c.esc(String(d.day||'').slice(5))}</span><small>${Number(d.listens||0)} / ${Number(d.completes||0)} / ${Number(d.skips||0)}</small></div>`).join('')}</div>`;
};

const notificationPanel=(c,n)=>{
  const types=n.types||[],users=n.users||[];
  return `<section class="panel notification-analytics-panel"><div class="panel-title"><div><h2>Notification re-engagement</h2><p>Delivery, engagement, and 24-hour post-click conversion</p></div></div>
    <div class="stats notification-analytics-stats">
      <div class="stat"><strong>${Number(n.delivered||0)}</strong><span>Delivered</span></div>
      <div class="stat"><strong>${Number(n.read_rate||0)}%</strong><span>Read rate</span></div>
      <div class="stat"><strong>${Number(n.click_rate||0)}%</strong><span>Click rate</span></div>
      <div class="stat"><strong>${Number(n.listen_conversion_rate||0)}%</strong><span>Click → listen</span></div>
      <div class="stat"><strong>${Number(n.purchase_conversion_rate||0)}%</strong><span>Click → purchase</span></div>
    </div>
    <div class="analytics-two-col">
      <div><h3>By notification type</h3>${types.length?`<div class="source-list">${types.map(x=>`<div><span>${c.esc(String(x.kind||'service').replaceAll('_',' '))}</span><strong>${Number(x.delivered||0)} sent</strong><em>${Number(x.click||0)} clicks</em></div>`).join('')}</div>`:'<div class="empty">No notification deliveries yet.</div>'}</div>
      <div><h3>Most engaged users</h3>${users.length?`<div class="source-list">${users.sort((a,b)=>Number(b.click||0)-Number(a.click||0)).slice(0,8).map(x=>`<div><span>${c.esc(x.display_name||x.email||('User '+x.user_id))}</span><strong>${Number(x.click||0)} clicks</strong><em>${Number(x.listen_conversions||0)} listens</em></div>`).join('')}</div>`:'<div class="empty">No user notification activity yet.</div>'}</div>
    </div>
  </section>`;
};

const searchPanel=(c,s)=>{
  const queries=s.top_queries||[],zero=s.zero_result_queries||[],results=s.top_results||[],filters=s.filter_usage||[];
  return `<section class="panel search-analytics-panel"><div class="panel-title"><div><h2>Catalog search & discovery</h2><p>What listeners look for, what they select, and where the catalog has gaps</p></div></div>
    <div class="stats search-analytics-stats">
      <div class="stat"><strong>${Number(s.searches||0)}</strong><span>Searches</span></div>
      <div class="stat"><strong>${Number(s.click_through_rate||0)}%</strong><span>Click-through rate</span></div>
      <div class="stat"><strong>${Number(s.zero_result_rate||0)}%</strong><span>Zero-result rate</span></div>
      <div class="stat"><strong>${Number(s.identified_users||0)}</strong><span>Identified users</span></div>
    </div>
    <div class="analytics-two-col">
      <div><h3>Top queries</h3>${queries.length?`<div class="source-list">${queries.slice(0,10).map(x=>`<div><span>${c.esc(x.query||'')}</span><strong>${Number(x.searches||0)} searches</strong><em>${Number(x.zero_results||0)} zero</em></div>`).join('')}</div>`:'<div class="empty">No search queries yet.</div>'}</div>
      <div><h3>Zero-result opportunities</h3>${zero.length?`<div class="source-list">${zero.slice(0,10).map(x=>`<div><span>${c.esc(x.query||'')}</span><strong>${Number(x.count||0)}</strong><em>no results</em></div>`).join('')}</div>`:'<div class="empty">No zero-result searches in this window.</div>'}</div>
    </div>
    <div class="analytics-two-col">
      <div><h3>Most selected results</h3>${results.length?`<div class="source-list">${results.slice(0,10).map(x=>`<div><span>${c.esc(x.title||x.id||'')}</span><strong>${Number(x.clicks||0)} clicks</strong><em>${c.esc(x.type||'result')}</em></div>`).join('')}</div>`:'<div class="empty">No selected search results yet.</div>'}</div>
      <div><h3>Filter usage</h3>${filters.length?`<div class="source-list">${filters.slice(0,10).map(x=>`<div><span>${c.esc(String(x.filter||'').replaceAll('_',' '))}</span><strong>${Number(x.uses||0)}</strong><em>uses</em></div>`).join('')}</div>`:'<div class="empty">No discovery filters used yet.</div>'}</div>
    </div>
  </section>`;
};

const inspector=(c,sel)=>{
  const u=sel.user||{},a=sel.analytics||{},n=sel.notification_analytics||{},search=sel.search_analytics||{},h=sel.history||[],activity=sel.activity||[],brain=sel.brain||[],fav=sel.favorites||{},pl=sel.playlists||[],builds=sel.builds||[],orders=sel.orders||[],cv=a.conversion||{};
  return `<section class="panel user-inspector"><div class="panel-title"><div><h2>${c.esc(u.display_name||u.email||'Customer')}</h2><p>${c.esc(u.email||'')} · joined ${c.esc(String(u.created_at||'').slice(0,10))}</p></div><button class="secondary" data-analytics-user="0" type="button">Close inspector</button></div>
    <div class="stats analytics-user-stats"><div class="stat"><strong>${Number(a.starts||0)}</strong><span>Starts</span></div><div class="stat"><strong>${Number(a.repeat_starts||0)}</strong><span>Repeat</span></div><div class="stat"><strong>${Number(a.completion_rate||0)}%</strong><span>Completion</span></div><div class="stat"><strong>${Number(a.skip_rate||0)}%</strong><span>Skip rate</span></div></div>
    <div class="analytics-commerce-strip"><div><span>Favorites</span><strong>${Number(a.favorites_added||0)}</strong></div><div><span>Playlists</span><strong>${Number(a.playlists_created||0)}</strong></div><div><span>Saved builds</span><strong>${Number(a.builds_created||0)}</strong></div><div><span>Paid orders</span><strong>${Number(a.paid_orders||0)}</strong></div><div><span>Revenue</span><strong>${c.money(a.revenue_cents||0)}</strong></div></div>
    <div class="conversion-grid compact">${funnel(c,'Favorite',cv.favorite_users,cv.favorite_rate)}${funnel(c,'Playlist',cv.playlist_users,cv.playlist_rate)}${funnel(c,'Saved build',cv.build_users,cv.build_rate)}${funnel(c,'Purchase',cv.purchase_users,cv.purchase_rate)}${funnel(c,'Custom media',cv.custom_media_users,cv.custom_media_rate)}</div>
    <div class="notification-user-summary"><strong>Notifications:</strong> ${Number(n.delivered||0)} delivered · ${Number(n.clicks||0)} clicked · ${Number(n.listen_conversions||0)} listen conversions · ${Number(n.purchase_conversions||0)} purchase conversions</div>
    <div class="notification-user-summary search-user-summary"><strong>Search:</strong> ${Number(search.searches||0)} searches · ${Number(search.clicks||0)} result clicks · ${Number(search.click_through_rate||0)}% CTR · ${Number(search.zero_result_searches||0)} zero-result searches</div>
    <div class="analytics-detail-grid">
      <div><h3>Listening history</h3>${h.length?h.slice(0,40).map(x=>`<div class="timeline-row"><strong>${c.esc(x.title||x.track_id)}</strong><span>${c.esc(x.event_type||'listen')} · ${c.esc((x.created_at||'').replace('T',' ').slice(0,19))}${x.position_seconds?' · '+c.time(x.position_seconds):''}</span></div>`).join(''):'<div class="empty">No listening history.</div>'}</div>
      <div><h3>Engagement</h3><div class="user-engagement-summary"><span>${(fav.tracks||[]).length} favorite tracks</span><span>${(fav.releases||[]).length} favorite releases</span><span>${pl.length} current playlists</span><span>${builds.length} saved builds</span><span>${orders.length} orders in window</span></div>${activity.length?activity.slice(0,24).map(x=>`<div class="timeline-row"><strong>${c.esc(x.title)}</strong><span>${c.esc(x.event_type||'activity')} · ${c.esc((x.created_at||'').replace('T',' ').slice(0,19))}</span></div>`).join(''):''}</div>
      <div><h3>Agent Brain</h3>${brain.length?brain.slice(0,30).map(x=>`<div class="timeline-row"><strong>${c.esc(x.route||x.phase)}</strong><span>${c.esc(x.request_excerpt||x.response_excerpt||'')}</span></div>`).join(''):'<div class="empty">No Agent Brain entries.</div>'}</div>
    </div>
  </section>`;
};

async function render(c,userId=0){
  const {canvas,head,api,app,esc,money,$,$$}=c;
  canvas.innerHTML=head('AUDIENCE + CONVERSION','Listening & Conversion Analytics','Measure listening quality, catalog discovery, notification re-engagement, purchases, and listener conversion.',`<select id="analyticsDays" class="table-input"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option><option value="365">1 year</option></select>`)+`<section class="panel"><div class="empty">Loading analytics…</div></section>`;
  try{
    const days=Number($('#analyticsDays')?.value||30),j=await api('analytics.php?days='+days+(userId?'&user_id='+userId:''));
    app.analytics=j;
    const a=j.overall||{},tracks=a.tracks||[],users=j.users||[],daily=a.daily||[],sel=j.selected,cv=a.conversion||{},sources=a.sources||[],notifications=j.notification_analytics||{},search=j.search_analytics||{};
    canvas.innerHTML=head('AUDIENCE + CONVERSION','Listening & Conversion Analytics','Measure listening quality, engagement, notification re-engagement, purchases, and listener conversion.',`<select id="analyticsDays" class="table-input"><option value="7" ${days===7?'selected':''}>7 days</option><option value="30" ${days===30?'selected':''}>30 days</option><option value="90" ${days===90?'selected':''}>90 days</option><option value="365" ${days===365?'selected':''}>1 year</option></select>`)+
    `<div class="stats analytics-primary-stats"><div class="stat"><strong>${Number(a.starts||0).toLocaleString()}</strong><span>Listens started</span></div><div class="stat"><strong>${Number(a.completion_rate||0)}%</strong><span>Completion rate</span></div><div class="stat"><strong>${Number(a.skip_rate||0)}%</strong><span>Skip rate</span></div><div class="stat"><strong>${Number(a.repeat_rate||0)}%</strong><span>Repeat rate</span></div></div>
    <section class="panel"><div class="panel-title"><h2>Listener conversion</h2><p>Actions after first listen inside this ${days}-day window</p></div><div class="conversion-grid">${funnel(c,'Favorite',cv.favorite_users,cv.favorite_rate)}${funnel(c,'Playlist',cv.playlist_users,cv.playlist_rate)}${funnel(c,'Saved build',cv.build_users,cv.build_rate)}${funnel(c,'Purchase',cv.purchase_users,cv.purchase_rate)}${funnel(c,'Custom media',cv.custom_media_users,cv.custom_media_rate)}</div><div class="analytics-commerce-strip"><div><span>Paid orders</span><strong>${Number(a.paid_orders||0)}</strong></div><div><span>Revenue</span><strong>${money(a.revenue_cents||0)}</strong></div><div><span>Favorites added</span><strong>${Number(a.favorites_added||0)}</strong></div><div><span>Playlists created</span><strong>${Number(a.playlists_created||0)}</strong></div><div><span>Builds created</span><strong>${Number(a.builds_created||0)}</strong></div></div></section>
    ${notificationPanel(c,notifications)}
    ${searchPanel(c,search)}
    <section class="panel"><div class="panel-title"><h2>Listening quality</h2><p>Starts / completions / skips by day</p></div><div class="analytics-legend"><span><i class="start"></i>Starts</span><span><i class="complete"></i>Completed</span><span><i class="skip"></i>Skipped</span></div>${trend(c,daily)}</section>
    <div class="analytics-two-col"><section class="panel"><div class="panel-title"><h2>Playback sources</h2><p>Where listening starts</p></div>${sources.length?`<div class="source-list">${sources.map(x=>`<div><span>${esc(String(x.source||'player').replaceAll('_',' '))}</span><strong>${Number(x.starts||0)}</strong><em>${Number(x.percent||0)}%</em></div>`).join('')}</div>`:'<div class="empty">No playback sources yet.</div>'}</section><section class="panel"><div class="panel-title"><h2>Engagement totals</h2><p>${days}-day window</p></div><div class="engagement-grid"><div><strong>${Number(a.repeat_starts||0)}</strong><span>Repeat starts</span></div><div><strong>${Number(a.repeat_listeners||0)}</strong><span>Repeat listeners</span></div><div><strong>${Number(a.sessions||0)}</strong><span>Sessions</span></div><div><strong>${Number(a.listeners||0)}</strong><span>Identified listeners</span></div></div></section></div>
    <section class="panel"><div class="panel-title"><h2>Track performance</h2><p>Listening, retention, saves, and conversion</p></div>${tracks.length?`<div class="table-scroll"><table class="data-table analytics-track-table"><thead><tr><th>Track</th><th>Starts</th><th>Repeat</th><th>Complete</th><th>Skip</th><th>Favorites</th><th>Digital</th><th>Build uses</th></tr></thead><tbody>${tracks.map(t=>`<tr><td><strong>${esc(t.title)}</strong><div class="file-list">${esc(t.release||'')}</div></td><td>${Number(t.listens||0)}</td><td>${Number(t.repeat_starts||0)}</td><td>${Number(t.completion_rate||0)}%</td><td>${Number(t.skip_rate||0)}%</td><td>${Number(t.favorites||0)}</td><td>${Number(t.digital_purchases||0)}</td><td>${Number(t.custom_build_uses||0)}</td></tr>`).join('')}</tbody></table></div>`:'<div class="empty">No listens have been recorded yet.</div>'}</section>
    <section class="panel"><div class="panel-title"><h2>Per-user engagement</h2><p>Listening + favorites + playlists + builds + purchases</p></div>${users.length?`<div class="table-scroll"><table class="data-table analytics-user-table"><thead><tr><th>User</th><th>Starts</th><th>Repeat</th><th>Complete</th><th>Skip</th><th>Fav</th><th>Playlists</th><th>Builds</th><th>Orders</th><th>Revenue</th><th></th></tr></thead><tbody>${users.map(u=>`<tr><td><strong>${esc(u.display_name||'User')}</strong><div class="file-list">${esc(u.email||'')}</div></td><td>${Number(u.listens||0)}</td><td>${Number(u.repeat_starts||0)}</td><td>${Number(u.completion_rate||0)}%</td><td>${Number(u.skip_rate||0)}%</td><td>${Number(u.favorites_added||0)}</td><td>${Number(u.playlists_created||0)}</td><td>${Number(u.builds_created||0)}</td><td>${Number(u.paid_orders||0)}</td><td>${money(u.revenue_cents||0)}</td><td><button class="secondary analytics-inspect" data-analytics-user="${u.user_id}" type="button">Inspect</button></td></tr>`).join('')}</tbody></table></div>`:'<div class="empty">No identified listener data yet.</div>'}</section>
    ${sel?inspector(c,sel):''}`;
    $('#analyticsDays').onchange=()=>render(c,userId);
    $$('[data-analytics-user]',canvas).forEach(b=>b.onclick=()=>render(c,Number(b.dataset.analyticsUser)));
  }catch(e){
    canvas.innerHTML=head('AUDIENCE + CONVERSION','Listening & Conversion Analytics','Measure listening quality, engagement, notification re-engagement, purchases, and listener conversion.')+`<div class="empty">${esc(e.message)}</div>`;
  }
}
window.StonefellowAdminAnalytics={render};
})();
