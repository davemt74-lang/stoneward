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

const libraryPanel=(c,l)=>{
  const tracks=l.top_tracks||[],releases=l.top_releases||[];
  return `<section class="panel library-analytics-panel"><div class="panel-title"><div><h2>Customer libraries</h2><p>Saved music, playlists, owned media, builds, and mixed Collections</p></div></div>
    <div class="stats library-analytics-stats">
      <div class="stat"><strong>${Number(l.users_with_library||0)}</strong><span>Users with library</span></div>
      <div class="stat"><strong>${Number(l.favorites||0)}</strong><span>Favorites</span></div>
      <div class="stat"><strong>${Number(l.playlists||0)}</strong><span>Playlists</span></div>
      <div class="stat"><strong>${Number(l.purchases||0)}</strong><span>Owned items</span></div>
      <div class="stat"><strong>${Number(l.collections||0)}</strong><span>Collections</span></div>
    </div>
    <div class="analytics-two-col">
      <div><h3>Most-saved tracks</h3>${tracks.length?`<div class="source-list">${tracks.map(x=>`<div><span>${c.esc(x.title)}</span><strong>${Number(x.saves||0)} saves</strong><em>track</em></div>`).join('')}</div>`:'<div class="empty">No saved tracks yet.</div>'}</div>
      <div><h3>Most-saved releases</h3>${releases.length?`<div class="source-list">${releases.map(x=>`<div><span>${c.esc(x.title)}</span><strong>${Number(x.saves||0)} saves</strong><em>release</em></div>`).join('')}</div>`:'<div class="empty">No saved releases yet.</div>'}</div>
    </div>
  </section>`;
};

const inspector=(c,sel)=>{
  const u=sel.user||{},a=sel.analytics||{},n=sel.notification_analytics||{},search=sel.search_analytics||{},libraryStats=sel.library_analytics||{},library=sel.library||{},h=sel.history||[],activity=sel.activity||[],brain=sel.brain||[],fav=sel.favorites||{},pl=sel.playlists||[],builds=sel.builds||[],orders=sel.orders||[],cv=a.conversion||{};
  return `<section class="panel user-inspector"><div class="panel-title"><div><h2>${c.esc(u.display_name||u.email||'Customer')}</h2><p>${c.esc(u.email||'')} · joined ${c.esc(String(u.created_at||'').slice(0,10))}</p></div><button class="secondary" data-analytics-user="0" type="button">Close inspector</button></div>
    <div class="stats analytics-user-stats"><div class="stat"><strong>${Number(a.starts||0)}</strong><span>Starts</span></div><div class="stat"><strong>${Number(a.repeat_starts||0)}</strong><span>Repeat</span></div><div class="stat"><strong>${Number(a.completion_rate||0)}%</strong><span>Completion</span></div><div class="stat"><strong>${Number(a.skip_rate||0)}%</strong><span>Skip rate</span></div></div>
    <div class="analytics-commerce-strip"><div><span>Favorites</span><strong>${Number(a.favorites_added||0)}</strong></div><div><span>Playlists</span><strong>${Number(a.playlists_created||0)}</strong></div><div><span>Saved builds</span><strong>${Number(a.builds_created||0)}</strong></div><div><span>Paid orders</span><strong>${Number(a.paid_orders||0)}</strong></div><div><span>Revenue</span><strong>${c.money(a.revenue_cents||0)}</strong></div></div>
    <div class="conversion-grid compact">${funnel(c,'Favorite',cv.favorite_users,cv.favorite_rate)}${funnel(c,'Playlist',cv.playlist_users,cv.playlist_rate)}${funnel(c,'Saved build',cv.build_users,cv.build_rate)}${funnel(c,'Purchase',cv.purchase_users,cv.purchase_rate)}${funnel(c,'Custom media',cv.custom_media_users,cv.custom_media_rate)}</div>
    <div class="notification-user-summary"><strong>Notifications:</strong> ${Number(n.delivered||0)} delivered · ${Number(n.clicks||0)} clicked · ${Number(n.listen_conversions||0)} listen conversions · ${Number(n.purchase_conversions||0)} purchase conversions</div>
    <div class="notification-user-summary search-user-summary"><strong>Search:</strong> ${Number(search.searches||0)} searches · ${Number(search.clicks||0)} result clicks · ${Number(search.click_through_rate||0)}% CTR · ${Number(search.zero_result_searches||0)} zero-result searches</div>
    <div class="notification-user-summary library-user-summary"><strong>My Library:</strong> ${Number(library.summary?.tracks||0)} tracks · ${Number(library.summary?.releases||0)} releases · ${Number(library.summary?.playlists||0)} playlists · ${Number(library.summary?.purchases||0)} owned items · ${Number(libraryStats.collections||0)} collections</div>
    ${(library.collections||[]).length?`<div class="admin-library-collections">${library.collections.slice(0,8).map(x=>`<div><strong>${c.esc(x.name)}</strong><span>${Number(x.item_count||0)} items</span></div>`).join('')}</div>`:''}
    <div class="analytics-detail-grid">
      <div><h3>Listening history</h3>${h.length?h.slice(0,40).map(x=>`<div class="timeline-row"><strong>${c.esc(x.title||x.track_id)}</strong><span>${c.esc(x.event_type||'listen')} · ${c.esc((x.created_at||'').replace('T',' ').slice(0,19))}${x.position_seconds?' · '+c.time(x.position_seconds):''}</span></div>`).join(''):'<div class="empty">No listening history.</div>'}</div>
      <div><h3>Engagement</h3><div class="user-engagement-summary"><span>${(fav.tracks||[]).length} favorite tracks</span><span>${(fav.releases||[]).length} favorite releases</span><span>${pl.length} current playlists</span><span>${builds.length} saved builds</span><span>${orders.length} orders in window</span></div>${activity.length?activity.slice(0,24).map(x=>`<div class="timeline-row"><strong>${c.esc(x.title)}</strong><span>${c.esc(x.event_type||'activity')} · ${c.esc((x.created_at||'').replace('T',' ').slice(0,19))}</span></div>`).join(''):''}</div>
      <div><h3>Agent Brain</h3>${brain.length?brain.slice(0,30).map(x=>`<div class="timeline-row"><strong>${c.esc(x.route||x.phase)}</strong><span>${c.esc(x.request_excerpt||x.response_excerpt||'')}</span></div>`).join(''):'<div class="empty">No Agent Brain entries.</div>'}</div>
    </div>
  </section>`;
};

const growthPanel=(c,g,days)=>{
  const r=g.recorded_revenue||{},crm=g.crm||{},campaigns=g.campaigns||{},merch=g.merch||{},membership=g.membership||{},tickets=g.ticketing||{},automation=g.automation||{},care=g.care||{},opps=g.opportunities||[],supporters=g.top_supporters||[],cohorts=crm.cohorts||[],daily=g.daily||[];
  const money=c.money,esc=c.esc,maxRevenue=Math.max(1,...daily.map(x=>Number(x.order_revenue_cents||0))),maxActivity=Math.max(1,...daily.map(x=>Number(x.new_contacts||0)+Number(x.ticket_qty||0)+Number(x.campaign_entries||0)));
  const rows=(campaigns.campaigns||[]).slice(0,10);
  return `<section class="growth-intelligence">
    <div class="stats growth-primary-stats">
      <div class="stat"><strong>${money(r.net_cents||0)}</strong><span>Recorded net revenue</span></div>
      <div class="stat"><strong>${Number(r.paid_orders||0)}</strong><span>Paid orders · ${days}d</span></div>
      <div class="stat"><strong>${Number(crm.new_contacts||0)}</strong><span>New CRM contacts</span></div>
      <div class="stat"><strong>${Number(membership.active_members||0)}</strong><span>Active members</span></div>
    </div>
    <div class="notice growth-note"><strong>Revenue accounting:</strong> recorded revenue = Stonefellow paid orders + paid membership invoices − confirmed refunds. Campaign revenue is attribution to those orders, not additional revenue. Internal ticket reservations measure demand/check-in; external ticket checkout is not counted as revenue because Stonefellow does not own that payment record.</div>
    <section class="panel"><div class="panel-title"><div><h2>Business pulse</h2><p>Recorded money and direct fan activity across the selected window.</p></div></div>
      <div class="analytics-commerce-strip"><div><span>Order revenue</span><strong>${money(r.order_revenue_cents||0)}</strong></div><div><span>Membership revenue</span><strong>${money(r.membership_revenue_cents||0)}</strong></div><div><span>Refunds</span><strong>${money(r.refund_cents||0)}</strong></div><div><span>Campaign attributed</span><strong>${money(campaigns.attributed_revenue_cents||0)}</strong></div><div><span>Merch units</span><strong>${Number(merch.units||0)}</strong></div></div>
      ${daily.length?`<div class="growth-chart">${daily.map(d=>`<div data-tip="${esc(d.day)} · ${money(d.order_revenue_cents||0)} · ${Number(d.new_contacts||0)} new fans"><i class="orders" style="height:${Math.max(2,Math.round(Number(d.order_revenue_cents||0)/maxRevenue*100))}%"></i><i style="height:${Math.max(2,Math.round((Number(d.new_contacts||0)+Number(d.ticket_qty||0)+Number(d.campaign_entries||0))/maxActivity*100))}%"></i></div>`).join('')}</div>`:'<div class="empty">No cross-system activity in this window yet.</div>'}
    </section>
    <div class="growth-grid">
      <section class="panel"><div class="panel-title"><div><h2>Fan growth</h2><p>CRM acquisition, consent and lifecycle mix.</p></div></div>
        <div class="analytics-commerce-strip"><div><span>Total CRM contacts</span><strong>${Number(crm.contacts||0)}</strong></div><div><span>New contacts</span><strong>${Number(crm.new_contacts||0)}</strong></div><div><span>Newsletter</span><strong>${Number(crm.newsletter||0)}</strong></div><div><span>New opt-ins</span><strong>${Number(crm.new_newsletter||0)}</strong></div></div>
        <div class="growth-cohorts">${cohorts.slice(-10).map(x=>`<div class="growth-cohort"><strong>${esc(x.week)}</strong><span>${Number(x.contacts||0)} contacts</span><span>${Number(x.newsletter||0)} newsletter</span><span>${Number(x.customer_or_member||0)} customer/member</span></div>`).join('')||'<div class="empty">No new-contact cohorts yet.</div>'}</div>
      </section>
      <section class="panel"><div class="panel-title"><div><h2>Campaign performance</h2><p>Participation, conversion and order attribution.</p></div></div>
        ${rows.length?`<div class="growth-metric-list">${rows.map(x=>`<div class="growth-metric-row"><span><strong>${esc(x.name)}</strong><small>${esc(x.goal)} · ${esc(x.status)}</small></span><strong>${Number(x.conversion_rate||0)}%</strong><em>${Number(x.participants||0)} fans · ${money(x.attributed_revenue_cents||0)}</em></div>`).join('')}</div>`:'<div class="empty">No campaign participation in this window.</div>'}
      </section>
      <section class="panel"><div class="panel-title"><div><h2>Merch + membership</h2><p>Direct support beyond listening.</p></div></div>
        <div class="analytics-commerce-strip"><div><span>Merch revenue</span><strong>${money(merch.revenue_cents||0)}</strong></div><div><span>Merch units</span><strong>${Number(merch.units||0)}</strong></div><div><span>Active members</span><strong>${Number(membership.active_members||0)}</strong></div><div><span>Member invoices</span><strong>${Number(membership.invoices||0)}</strong></div></div>
        <div class="growth-metric-list">${(merch.products||[]).slice(0,8).map(x=>`<div class="growth-metric-row"><span>${esc(x.title)}</span><strong>${Number(x.units||0)} units</strong><em>${money(x.revenue_cents||0)}</em></div>`).join('')||'<div class="empty">No merch sales in this window.</div>'}</div>
      </section>
      <section class="panel"><div class="panel-title"><div><h2>Tickets + lifecycle</h2><p>Fan demand, attendance and automation health.</p></div></div>
        <div class="analytics-commerce-strip"><div><span>Reserved guests</span><strong>${Number(tickets.reserved_qty||0)}</strong></div><div><span>Checked in</span><strong>${Number(tickets.checked_in_qty||0)}</strong></div><div><span>Check-in rate</span><strong>${Number(tickets.checkin_rate||0)}%</strong></div><div><span>Automation complete</span><strong>${Number(automation.completion_rate||0)}%</strong></div></div>
        <div class="growth-metric-list"><div class="growth-metric-row"><span>Automation runs</span><strong>${Number(automation.runs||0)}</strong><em>${Number(automation.failed||0)} failed · ${Number(automation.waiting||0)} waiting</em></div><div class="growth-metric-row"><span>Support cases opened</span><strong>${Number(care.opened_cases||0)}</strong><em>${Number(care.open_cases||0)} currently open</em></div><div class="growth-metric-row"><span>High-priority cases</span><strong>${Number(care.high_priority_cases||0)}</strong><em>${Number(care.refund_requests||0)} refund requests</em></div></div>
      </section>
    </div>
    <section class="panel"><div class="panel-title"><div><h2>Top supporters</h2><p>Recorded Stonefellow order + membership value; ticket reservations are shown separately and do not inflate revenue.</p></div></div>
      ${supporters.length?`<div class="table-scroll"><table class="data-table growth-fan-table"><thead><tr><th>Fan</th><th>Recorded value</th><th>Orders</th><th>Membership</th><th>Ticket qty</th></tr></thead><tbody>${supporters.slice(0,20).map(x=>`<tr><td><strong>${esc(x.name||x.email||('User '+x.user_id))}</strong><small>${esc(x.email||'')}</small></td><td>${money(x.recorded_value_cents||0)}</td><td>${Number(x.orders||0)}</td><td>${esc(x.membership_tier||'—')}</td><td>${Number(x.ticket_qty||0)}</td></tr>`).join('')}</tbody></table></div>`:'<div class="empty">No recorded supporter value yet.</div>'}
    </section>
    <section class="panel"><div class="panel-title"><div><h2>What needs attention</h2><p>Deterministic thresholds over current operational data—not generated guesses.</p></div></div><div class="growth-opportunities">${opps.map(o=>`<div class="growth-opportunity ${esc(o.severity||'watch')}"><small>${esc(o.area||'Operations')}</small><div><strong>${esc(o.title||'Opportunity')}</strong><span>${esc(o.detail||'')}</span></div></div>`).join('')}</div></section>
    <section class="panel"><div class="panel-title"><div><h2>Admin Agent brief</h2><p>The same read-only metrics are condensed into a grounded context block for the Admin Agent.</p></div></div><div class="growth-agent-brief">${esc(g.agent_brief||'No business brief is available yet.')}</div></section>
  </section>`;
};

async function render(c,userId=0){
  const {canvas,head,api,app,esc,money,$,$$}=c;
  canvas.innerHTML=head('AUDIENCE + CONVERSION','Listening & Conversion Analytics','Measure listening quality, catalog discovery, notification re-engagement, purchases, and listener conversion.',`<select id="analyticsDays" class="table-input"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option><option value="365">1 year</option></select>`)+`<section class="panel"><div class="empty">Loading analytics…</div></section>`;
  try{
    const days=Number($('#analyticsDays')?.value||30),j=await api('analytics.php?days='+days+(userId?'&user_id='+userId:''));
    app.analytics=j;
    const a=j.overall||{},growth=j.growth||{},tracks=a.tracks||[],users=j.users||[],daily=a.daily||[],sel=j.selected,cv=a.conversion||{},sources=a.sources||[],notifications=j.notification_analytics||{},search=j.search_analytics||{},library=j.library_analytics||{};
    canvas.innerHTML=head('BUSINESS + FAN INTELLIGENCE','Performance Intelligence','Recorded revenue, fan growth, campaign attribution, merch, membership, tickets, lifecycle automation, customer care, and listening quality.',`<select id="analyticsDays" class="table-input"><option value="7" ${days===7?'selected':''}>7 days</option><option value="30" ${days===30?'selected':''}>30 days</option><option value="90" ${days===90?'selected':''}>90 days</option><option value="365" ${days===365?'selected':''}>1 year</option></select>`)+
    `<div class="stats analytics-primary-stats"><div class="stat"><strong>${Number(a.starts||0).toLocaleString()}</strong><span>Listens started</span></div><div class="stat"><strong>${Number(a.completion_rate||0)}%</strong><span>Completion rate</span></div><div class="stat"><strong>${Number(a.skip_rate||0)}%</strong><span>Skip rate</span></div><div class="stat"><strong>${Number(a.repeat_rate||0)}%</strong><span>Repeat rate</span></div></div>
    <section class="panel"><div class="panel-title"><h2>Listener conversion</h2><p>Actions after first listen inside this ${days}-day window</p></div><div class="conversion-grid">${funnel(c,'Favorite',cv.favorite_users,cv.favorite_rate)}${funnel(c,'Playlist',cv.playlist_users,cv.playlist_rate)}${funnel(c,'Saved build',cv.build_users,cv.build_rate)}${funnel(c,'Purchase',cv.purchase_users,cv.purchase_rate)}${funnel(c,'Custom media',cv.custom_media_users,cv.custom_media_rate)}</div><div class="analytics-commerce-strip"><div><span>Paid orders</span><strong>${Number(a.paid_orders||0)}</strong></div><div><span>Revenue</span><strong>${money(a.revenue_cents||0)}</strong></div><div><span>Favorites added</span><strong>${Number(a.favorites_added||0)}</strong></div><div><span>Playlists created</span><strong>${Number(a.playlists_created||0)}</strong></div><div><span>Builds created</span><strong>${Number(a.builds_created||0)}</strong></div></div></section>
    ${notificationPanel(c,notifications)}
    ${searchPanel(c,search)}
    ${libraryPanel(c,library)}
    <section class="panel"><div class="panel-title"><h2>Listening quality</h2><p>Starts / completions / skips by day</p></div><div class="analytics-legend"><span><i class="start"></i>Starts</span><span><i class="complete"></i>Completed</span><span><i class="skip"></i>Skipped</span></div>${trend(c,daily)}</section>
    <div class="analytics-two-col"><section class="panel"><div class="panel-title"><h2>Playback sources</h2><p>Where listening starts</p></div>${sources.length?`<div class="source-list">${sources.map(x=>`<div><span>${esc(String(x.source||'player').replaceAll('_',' '))}</span><strong>${Number(x.starts||0)}</strong><em>${Number(x.percent||0)}%</em></div>`).join('')}</div>`:'<div class="empty">No playback sources yet.</div>'}</section><section class="panel"><div class="panel-title"><h2>Engagement totals</h2><p>${days}-day window</p></div><div class="engagement-grid"><div><strong>${Number(a.repeat_starts||0)}</strong><span>Repeat starts</span></div><div><strong>${Number(a.repeat_listeners||0)}</strong><span>Repeat listeners</span></div><div><strong>${Number(a.sessions||0)}</strong><span>Sessions</span></div><div><strong>${Number(a.listeners||0)}</strong><span>Identified listeners</span></div></div></section></div>
    <section class="panel"><div class="panel-title"><h2>Track performance</h2><p>Listening, retention, saves, and conversion</p></div>${tracks.length?`<div class="table-scroll"><table class="data-table analytics-track-table"><thead><tr><th>Track</th><th>Starts</th><th>Repeat</th><th>Complete</th><th>Skip</th><th>Favorites</th><th>Digital</th><th>Build uses</th></tr></thead><tbody>${tracks.map(t=>`<tr><td><strong>${esc(t.title)}</strong><div class="file-list">${esc(t.release||'')}</div></td><td>${Number(t.listens||0)}</td><td>${Number(t.repeat_starts||0)}</td><td>${Number(t.completion_rate||0)}%</td><td>${Number(t.skip_rate||0)}%</td><td>${Number(t.favorites||0)}</td><td>${Number(t.digital_purchases||0)}</td><td>${Number(t.custom_build_uses||0)}</td></tr>`).join('')}</tbody></table></div>`:'<div class="empty">No listens have been recorded yet.</div>'}</section>
    <section class="panel"><div class="panel-title"><h2>Per-user engagement</h2><p>Listening + favorites + playlists + builds + purchases</p></div>${users.length?`<div class="table-scroll"><table class="data-table analytics-user-table"><thead><tr><th>User</th><th>Starts</th><th>Repeat</th><th>Complete</th><th>Skip</th><th>Fav</th><th>Playlists</th><th>Builds</th><th>Orders</th><th>Revenue</th><th></th></tr></thead><tbody>${users.map(u=>`<tr><td><strong>${esc(u.display_name||'User')}</strong><div class="file-list">${esc(u.email||'')}</div></td><td>${Number(u.listens||0)}</td><td>${Number(u.repeat_starts||0)}</td><td>${Number(u.completion_rate||0)}%</td><td>${Number(u.skip_rate||0)}%</td><td>${Number(u.favorites_added||0)}</td><td>${Number(u.playlists_created||0)}</td><td>${Number(u.builds_created||0)}</td><td>${Number(u.paid_orders||0)}</td><td>${money(u.revenue_cents||0)}</td><td><button class="secondary analytics-inspect" data-analytics-user="${u.user_id}" type="button">Inspect</button></td></tr>`).join('')}</tbody></table></div>`:'<div class="empty">No identified listener data yet.</div>'}</section>
    ${sel?inspector(c,sel):''}`;
    $('#analyticsDays').onchange=()=>render(c,userId);
    $$('[data-analytics-user]',canvas).forEach(b=>b.onclick=()=>render(c,Number(b.dataset.analyticsUser)));
  }catch(e){
    canvas.innerHTML=head('BUSINESS + FAN INTELLIGENCE','Performance Intelligence','Recorded revenue, fan growth, campaign attribution, commerce, membership, ticketing, lifecycle automation, customer care, and listening quality.')+`<div class="empty">${esc(e.message)}</div>`;
  }
}
window.StonefellowAdminAnalytics={render};
})();
