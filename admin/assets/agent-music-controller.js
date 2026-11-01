/* Section 91B4 — Browser-owned Admin Agent music transport.
 * Only same-origin catalog previews play; private masters and remote URLs do
 * not enter this player. Player commands are local and need no AI approval.
 */
(() => {
  'use strict';
  const norm=text=>String(text||'').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').replace(/\s+/g,' ').trim();
  function create({audio,tracks,baseUrl,onState=()=>{}}) {
    if(!audio||typeof tracks!=='function')throw new Error('Admin music transport unavailable.');
    let current=null,level=0.7,lastAudible=0.7,speaking=false,conversation=false,probe=false;
    const resolveSource=track=>{
      const raw=String(track?.audio||'').trim();if(!raw||raw.startsWith('//'))return null;
      try{
        const reference=raw.startsWith('/')?new URL(raw,new URL(baseUrl).origin):
          /^https?:\/\//i.test(raw)?new URL(raw):new URL('../'+raw.replace(/^\.\//,''),baseUrl);
        return reference.origin===new URL(baseUrl).origin&&/^https?:$/.test(reference.protocol)?reference.href:null;
      }catch(_){return null}
    };
    const catalog=()=>tracks().filter(t=>t&&t.id&&t.title&&resolveSource(t));
    const state=()=>({track_id:current?.id||'',title:current?.title||'',playing:!!current&&!audio.paused,volume_percent:Math.round(level*100),has_track:!!current});
    const publish=()=>onState(state());
    const syncVolume=()=>{audio.volume=probe?0:Math.max(0,Math.min(level,speaking?0.16:conversation?0.40:level))};
    const duck=(voiceActive,conversationActive,probeActive=false)=>{speaking=!!voiceActive;conversation=!!conversationActive;probe=!!probeActive;syncVolume();publish()};
    const setVolume=percent=>{const requested=Number(percent);if(!Number.isFinite(requested))return null;level=Math.max(0,Math.min(1,requested/100));if(level>0)lastAudible=level;syncVolume();publish();return Math.round(level*100)};
    const play=async track=>{
      if(!track)return 'No playable Stonefellow song was found.';
      const source=resolveSource(track);
      if(!source)return 'That song has no same-site audio preview available.';
      if(current?.id!==track.id||audio.src!==source){audio.pause();audio.src=source;audio.load?.()}
      current=track;publish();
      try{await audio.play();syncVolume();publish();return 'Playing “'+track.title+'.”'}
      catch(_){publish();return 'The browser blocked playback. Click Play in the Admin music bar to enable audio.'}
    };
    const pause=()=>{if(!current)return 'No song is loaded.';audio.pause();publish();return 'Paused “'+current.title+'.”'};
    const stop=()=>{if(!current)return 'No song is loaded.';audio.pause();try{audio.currentTime=0}catch(_){}publish();return 'Stopped “'+current.title+'.”'};
    const resume=async()=>current?audio.paused?play(current):'“'+current.title+'” is already playing.':'No song is loaded. Ask me to play a Stonefellow track.';
    const step=async direction=>{
      const available=catalog();if(!available.length)return 'No playable Stonefellow songs are available.';
      const index=available.findIndex(t=>t.id===current?.id);
      const next=available[(index<0?direction>0?0:available.length-1:index+direction+available.length)%available.length];
      return play(next);
    };
    const status=()=>current?'“'+current.title+'” is '+(audio.paused?'paused':'playing')+'. Music volume is '+Math.round(level*100)+' percent.':'No song is loaded in the Admin player.';
    const sharedSkill=window.StonefellowAgentAudioSkill?.create({
      getState:state,
      invoke:async action=>{
        switch(action.type){
          case 'player_status':return status();
          case 'pause_player':return pause();
          case 'stop_player':return stop();
          case 'load_temporary_playlist':return 'Temporary playlists are available in the public Stonefellow Agent Canvas.';
          case 'resume_player':return resume();
          case 'next_track':return step(1);
          case 'previous_track':return step(-1);
          case 'set_player_volume':{
            const target=action.mode==='mute'?0:action.mode==='unmute'?Math.round(lastAudible*100):
              action.mode==='adjust'?Math.round(level*100)+action.percent:action.percent;
            const actual=setVolume(target);
            return actual===null?'I could not change the music volume.':'Music volume is '+actual+' percent.';
          }
          case 'play_track':{
            const available=catalog();
            const query=norm(action.query||'');
            const matching=action.track_id?available.find(t=>t.id===action.track_id):
              !query?available[0]:available.find(t=>norm(t.title)===query)||available.find(t=>norm(t.title).includes(query));
            return matching?play(matching):'I could not find a playable Stonefellow song'+(query?' named “'+query+'”':'')+'.';
          }
        }
        return null;
      }
    });
    async function handle(message){
      // All standard audio commands share the same parsed contract as the
      // public Agent. Preserve old Admin phrases as a safe compatibility path.
      const sharedReply=await sharedSkill?.handle(message);
      if(sharedReply)return sharedReply.text;
      const cleaned=norm(message).replace(/^(?:hey|hi|okay|ok)?\s*stone\s*fellow\s*/, '').replace(/^please\s+/,'');
      if(!cleaned)return null;
      if(/^(?:what(?: s| is)? (?:playing|the current song)|what song is (?:this|playing)|player status|music status)$/.test(cleaned))return status();
      if(/^pause(?: the)? (?:music|song|track|player|audio)$/.test(cleaned))return pause();
      if(/^stop(?: the)? (?:music|song|track|player|audio)$/.test(cleaned))return stop();
      if(/^(?:resume|unpause|continue)(?: the)? (?:music|song|track|player|audio)$/.test(cleaned))return resume();
      if(/^(?:next|skip)(?: the)?(?: song| track| music)?$/.test(cleaned))return step(1);
      if(/^(?:previous|last|back)(?: song| track)?$/.test(cleaned))return step(-1);
      if(/^(?:mute|silence)(?: the)? (?:music|player|audio|sound)$/.test(cleaned)){setVolume(0);return 'Music muted.'}
      if(/^(?:unmute)(?: the)? (?:music|player|audio|sound)$/.test(cleaned)){return 'Music volume is '+setVolume(Math.round(lastAudible*100))+' percent.'}
      const percent=cleaned.match(/^(?:set |turn |change )?(?:the )?(?:music |player )?volume (?:to |at )?(\d{1,3})(?: percent)?$/);
      if(percent)return 'Music volume is '+setVolume(Math.min(100,Number(percent[1])))+' percent.';
      if(/^(?:turn )?(?:the )?(?:music |player )?volume (?:up|down)$/.test(cleaned)){
        const direction=cleaned.endsWith('up')?10:-10;
        return 'Music volume is '+setVolume(Math.round(level*100)+direction)+' percent.';
      }
      const requested=cleaned.match(/^(?:play|put on|start)(?: the)?\s+(.+)$/);
      if(!requested)return null;
      const query=requested[1].replace(/^(?:song|track|music)\s+(?:called\s+|named\s+)?/,'').trim();
      const available=catalog();
      if(['music','song','track','some music','something','something new'].includes(query)||!query)
        return play(available[0]);
      const matching=available.find(t=>norm(t.title)===query)||available.find(t=>norm(t.title).includes(query));
      return matching?play(matching):'I could not find a playable Stonefellow song named “'+query+'.”';
    }
    audio.addEventListener?.('ended',()=>{publish();});
    audio.addEventListener?.('play',publish);
    audio.addEventListener?.('pause',publish);
    publish();
    return {handle,dispatch:action=>sharedSkill?.dispatch(action),play,pause,stop,resume,step,status,state,duck,setVolume,get current(){return current}};
  }
  window.StonefellowAdminMusic={create};
})();
