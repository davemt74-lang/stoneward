/* Section 91B5 — Shared, browser-owned Stonefellow Agent Audio Player Skill.
 * A skill describes and validates a local command. It never authorizes a
 * server write, publishes catalog media, or bypasses browser autoplay rules.
 */
(() => {
  'use strict';
  const CAPABILITIES=Object.freeze([
    'play_track','pause_player','resume_player','next_track',
    'previous_track','player_status','set_player_volume'
  ]);
  const allowed=new Set(CAPABILITIES);
  const normalize=text=>String(text||'').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').replace(/\s+/g,' ').trim();
  function command(text){
    let words=normalize(text).replace(/^(?:(?:hey|hi|okay|ok)\s+)?stone\s*fellow(?:\s+|$)/,'').replace(/^please\s+/,'').trim();
    if(!words)return null;
    if(/^(?:what(?: s| is)? playing|what(?: s| is)? the current (?:song|track)|what (?:song|track) is (?:this|playing)|which (?:song|track) is playing|player status|music status|is (?:the )?(?:music|song|track|player) playing)$/.test(words))return {type:'player_status'};
    if(/^(?:pause|stop)(?: the)?(?: music| song| track| player| audio| playback| it)?$/.test(words))return {type:'pause_player'};
    if(/^(?:resume|unpause|continue)(?: playing)?(?: the)?(?: music| song| track| player| audio| playback| it)?$/.test(words))return {type:'resume_player'};
    if(/^(?:next|skip)(?: the| this)?(?: song| track| music| it)?$/.test(words))return {type:'next_track'};
    if(/^(?:previous|last|back)(?: song| track)?$/.test(words)||/^(?:go back|play the previous)(?: one| song| track)?$/.test(words))return {type:'previous_track'};
    if(/^(?:mute|silence)(?: the)?(?: music| player| audio| sound| volume)?$/.test(words))return {type:'set_player_volume',mode:'mute'};
    if(/^(?:unmute|turn (?:the )?(?:music|sound|volume) back on)(?: the)?(?: music| player| audio| sound)?$/.test(words))return {type:'set_player_volume',mode:'unmute'};
    const amount=words.match(/^(?:set |turn |change )?(?:the )?(?:music |player )?volume (?:to |at )?(\d{1,3})(?: percent)?$/);
    if(amount)return {type:'set_player_volume',mode:'set',percent:Math.max(0,Math.min(100,Number(amount[1])))};
    const direction=words.match(/^(?:(?:turn|raise|lower) )?(?:the )?(?:music |player )?volume (up|down)$/);
    if(direction)return {type:'set_player_volume',mode:'adjust',percent:direction[1]==='up'?10:-10};
    const requested=words.match(/^(?:play|put on|start|listen to)(?: the)?\s+(.+)$/)
      ||words.match(/^(?:change|switch) (?:the |this )?(?:songs?|tracks?|music) (?:to )?(.+)$/)
      ||words.match(/^switch to (.+)$/);
    if(!requested)return null;
    const query=requested[1].replace(/^(?:song|track|music)\s+(?:called\s+|named\s+)?/,'').trim();
    if(/^(?:(?:a|an|the) )?(?:album|release|playlist|listening session)(?:\s|$)/.test(query))return null;
    return {type:'play_track',query:['music','song','track','some music','something','something new','me something','me a song'].includes(query)?'':query};
  }
  function validate(raw){
    if(!raw||typeof raw!=='object'||!allowed.has(raw.type))return null;
    const type=raw.type,action={type};
    if(type==='play_track'){
      if(raw.track_id!==undefined){if(typeof raw.track_id!=='string'||raw.track_id.length>180)return null;action.track_id=raw.track_id}
      if(raw.query!==undefined){if(typeof raw.query!=='string'||raw.query.length>160)return null;action.query=raw.query}
    }
    if(type==='set_player_volume'){
      if(!['mute','unmute','set','adjust'].includes(raw.mode))return null;
      action.mode=raw.mode;
      if(raw.mode==='set'||raw.mode==='adjust'){
        if(typeof raw.percent!=='number'||!Number.isFinite(raw.percent))return null;
        action.percent=Math.max(raw.mode==='adjust'?-100:0,Math.min(100,raw.percent));
      }
    }
    return action;
  }
  /** The published player, not the language model, verifies named-song identity. */
  function resolvePublished(message,tracks,serverAction,resolution){
    // Song-section capture is not a track search, even when a spoken command
    // begins with 'listen to this verse'. Leave its server-approved action intact.
    if(['song_capture_start','song_capture_stop','song_capture_status','song_capture_transcribe'].includes(serverAction?.type))
      return {action:serverAction};
    // Album recommendations are server-selected, but the current browser must
    // independently confirm the exact published track and release before play.
    if(resolution?.method==='release_catalog'){
      const verified=(Array.isArray(tracks)?tracks:[]).filter(track=>
        track?.id===resolution.track_id&&track?.title===resolution.title&&
        track?.release===resolution.release&&!!track?.audio);
      if(resolution.status==='verified'&&serverAction?.type==='play_track'&&
        serverAction.track_id===resolution.track_id&&verified.length===1)
        return {action:{type:'play_track',track_id:verified[0].id}};
      return {action:{type:'none'},text:'The selected album track is not available in this player. I have not changed playback.'};
    }
    const requested=command(message);
    if(requested?.type!=='play_track'||!requested.query)return {action:serverAction};
    const query=normalize(requested.query);
    // The LLM may interpret an imperfect voice transcript, but only the
    // server can attest that the chosen ID belongs to its published catalog.
    // Confirm the same ID and title in this browser's live player catalog.
    if(resolution?.method==='llm_catalog'&&resolution.status==='verified'
      &&serverAction?.type==='play_track'&&serverAction.track_id===resolution.track_id){
      const verified=(Array.isArray(tracks)?tracks:[]).filter(track=>
        track?.id===resolution.track_id&&track?.title===resolution.title);
      if(verified.length===1)return {action:{type:'play_track',track_id:verified[0].id}};
      return {action:{type:'none'},text:'That published song is not available in this player. I have not changed playback.'};
    }
    if(['it','this','this one','that','that one','current','current song','current track','this song','this track'].includes(query))return {action:serverAction};
    const candidates=(Array.isArray(tracks)?tracks:[]).filter(track=>{
      const title=normalize(track?.title);
      return title&&title===query;
    });
    const longest=Math.max(0,...candidates.map(track=>normalize(track.title).length));
    const matches=candidates.filter(track=>normalize(track.title).length===longest);
    if(matches.length===1&&typeof matches[0].id==='string'&&matches[0].id!==''){
      return {action:{type:'play_track',track_id:matches[0].id}};
    }
    return {action:{type:'none'},text:matches.length>1
      ?'More than one published song matches “'+requested.query+'.” Which release did you mean?'
      :'I could not find a published song matching “'+requested.query+'.” I have not changed what is playing.'};
  }
  function create({invoke,getState=()=>({})}={}){
    if(typeof invoke!=='function')throw new Error('Audio Player Skill needs a local player adapter.');
    const state=()=>{
      const s=getState()||{};
      return {track_id:String(s.track_id||s.active_track_id||'').slice(0,180),
        title:String(s.title||'').slice(0,180),playing:!!(s.playing??s.player_is_playing),
        volume_percent:Math.max(0,Math.min(100,Number(s.volume_percent??s.player_volume_percent)||0))};
    };
    async function dispatch(raw){
      const action=validate(raw);if(!action)return null;
      try{
        const output=await invoke(action);
        if(output===null||output===undefined)return {accepted:true,ok:false,text:'The audio player did not complete that command.',action:action.type,state:state()};
        const text=typeof output==='string'?output:String(output.text||'The audio player did not return a result.');
        // A legacy adapter can return prose; only structured adapters attest
        // success. Never infer successful playback from the intended command.
        const ok=typeof output==='object'&&typeof output.ok==='boolean'?output.ok:null;
        return {accepted:true,ok,text,action:action.type,state:state()};
      }catch(_){return {accepted:true,ok:false,text:'The audio player could not complete that command.',action:action.type,state:state()}}
    }
    async function handle(text){const parsed=command(text);return parsed?dispatch(parsed):null}
    return {dispatch,handle,state,capabilities:CAPABILITIES};
  }
  window.StonefellowAgentAudioSkill=Object.freeze({version:'stonefellow.audio-player.v1',capabilities:CAPABILITIES,command,validate,resolvePublished,create});
})();
