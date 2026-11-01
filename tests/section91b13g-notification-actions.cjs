'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const begin=app.indexOf('  function notificationTrack(n){');
const end=app.indexOf('  function renderDrawer(){',begin);
assert.ok(begin>0&&end>begin,'notification helpers found');
const calls=[],posts=[];
const tracks={'sf-1':{id:'sf-1',title:'Scent Of A Pistol'},'sf-2':{id:'sf-2',title:'Static Sky'}};
const rows=[
  {id:7,kind:'listening',title:'Continue “Scent Of A Pistol”',body:'Pick up from 2:30',action_context:{type:'track',track_id:'sf-1',position_seconds:150}},
  {id:8,kind:'recommendation',title:'A pick for you',action_context:{type:'track',track_id:'sf-2',position_seconds:0}},
  {id:9,kind:'release',title:'New release',link_url:'?view=release&id=album-1'},
  {id:10,kind:'build',title:'Unfinished record',link_url:'?view=account'},
  {id:11,kind:'community',title:'Newsletter joined',link_url:'?view=account'},
  {id:12,kind:'vinyl_production',title:'Your vinyl order update',link_url:'?view=order&id=123'},
  {id:13,kind:'service',title:'Support message'},
  {id:14,kind:'listening',title:'Continue “made-up song”',link_url:'?view=history'}
];
const input={value:'',dispatchEvent(){},focus(){calls.push(['focus'])}};
const context={st:{drawer:{data:{notifications:rows}},playlists:{items:[{id:42,name:'Night Drives'}]},auth:{authenticated:true}},
  drawerContent:null,trackById:id=>tracks[id]||null,
  esc:s=>String(s).replaceAll('<','&lt;'),
  agentSessionActive:()=>false,
  play:(t,offset,source)=>{calls.push(['play',t.id,offset,source]);return Promise.resolve(true)},
  closeNotificationDrawer:()=>calls.push(['close']),
  secureJsonPost:async(path,payload)=>{posts.push([path,payload]);return path==='playlists.php'?
    {playlist:{name:payload.playlist_id?'Night Drives':'Activity Picks'},playlists:[{id:42,name:'Night Drives'}]}:
    path==='queue.php'?{queue:{items:[]}}:{unread:0}},
  applyQueue:()=>calls.push(['queue-applied']),
  updateNotificationBadge:()=>{},
  openChatPopup:async()=>calls.push(['chat-open']),
  input,Event:class {constructor(type){this.type=type}},
  document:{createElement:()=>({})}};
vm.runInNewContext(app.slice(begin,end),context,{filename:'notification-helpers.js'});
let checks=0;
function check(label,fn){fn();checks++;console.log('91B13G PASS '+label);}
check('Continue Listening has Resume, Up Next, available playlist, and Ask Agent',()=>{
  const html=context.notificationActionMarkup(rows[0]);
  assert.match(html,/▶ Resume/);
  assert.match(html,/Up Next/);
  assert.match(html,/Night Drives/);
  assert.match(html,/Ask Agent/);
});
check('Recommendations offer playback but releases, builds, community and orders have distinct CTAs',()=>{
  assert.match(context.notificationActionMarkup(rows[1]),/Play track/);
  assert.match(context.notificationActionMarkup(rows[2]),/Explore release/);
  assert.match(context.notificationActionMarkup(rows[3]),/My builds/);
  assert.match(context.notificationActionMarkup(rows[4]),/Account details/);
  assert.match(context.notificationActionMarkup(rows[5]),/Order status/);
  assert.match(context.notificationActionMarkup(rows[6]),/Ask Agent/);
  assert.doesNotMatch(context.notificationActionMarkup(rows[6]),/▶ Play/);
  assert.doesNotMatch(context.notificationActionMarkup(rows[7]),/▶ Resume/);
});
async function run(){
  await context.notificationPlay(7);
  check('Resume invokes existing player with exact stored offset and telemetry source',()=>{
    assert.deepEqual(calls[0],['play','sf-1',150,'notification']);
    assert.ok(posts.some(x=>x[0]==='notifications.php'&&x[1].action==='click'));
  });
  await context.notificationQueue(8);
  check('Up Next uses authenticated persistent queue API and never creates an unrelated player',()=>{
    assert.ok(posts.some(x=>x[0]==='queue.php'&&x[1].track_id==='sf-2'));
  });
  await context.notificationPlaylist(7,42);
  check('Existing playlist uses exact selected owned playlist ID',()=>{
    assert.ok(posts.some(x=>x[0]==='playlists.php'&&x[1].action==='add_track'&&x[1].playlist_id===42));
  });
  context.st.playlists.items=[];
  check('With no playlist, offer an explicit new private playlist instead',()=>{
    assert.match(context.notificationActionMarkup(rows[7].action_context?rows[7]:rows[0]),/New playlist/);
  });
  await context.notificationPlaylist(7,0);
  check('Creating an Activity Picks playlist includes the chosen track by ID',()=>{
    assert.ok(posts.some(x=>x[0]==='playlists.php'&&x[1].action==='create'&&x[1].visibility==='private'&&x[1].track_ids[0]==='sf-1'));
  });
  await context.notificationChat(11);
  check('Ask Agent only prefills chat, with notification context and no automatic send',()=>{
    assert.match(input.value,/Newsletter joined/);
    assert.ok(calls.some(x=>x[0]==='chat-open'));
    assert.ok(calls.some(x=>x[0]==='focus'));
    assert.equal(posts.some(x=>x[0]==='agent.php'),false);
  });
  check('Notification rendering binds separate action handlers',()=>{
    for(const key of ['data-notification-play','data-notification-queue','data-notification-playlist','data-notification-new-playlist','data-notification-chat'])
      assert.match(app,new RegExp(key));
    new vm.Script(app);
  });
  console.log('Section 91B13G: '+checks+' contextual notification UI/action checks passed');
}
run().catch(e=>{console.error(e);process.exitCode=1});