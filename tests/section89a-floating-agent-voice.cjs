'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const app=read('assets/js/app.js');
const page=read('stonefellow-v120.php');
const css=read('assets/css/site.css');
const checks={
  'Composer-anchored, initially closed conversation popup':page.includes('id="agentChatPopup"')&&page.includes('class="agent-chat-popup"')&&page.includes('aria-label="Stonefellow conversation" hidden'),
  'Accessible open and close buttons':page.includes('id="agentChatToggle"')&&page.includes('aria-controls="agentChatPopup"')&&page.includes('id="agentChatClose"'),
  'Continuous visual companion':page.includes('class="agent-face"')&&page.includes('</main>\n  <!-- Keep the floating companion outside')&&css.includes('body.artist-home-active>.agent-stage')&&css.includes('companion-float'),
  'Voice indicator reflects actual microphone startup':app.includes("agent('connecting','Starting the microphone…')")&&app.includes('recognition.onstart=()=>{recognitionActive=true;'),
  'Final transcription routes once through canonical command':app.includes("if(!st.conversationOn||voiceTurnBusy||voicePlaybackPending||(typeof voiceSpeechGuard!=='undefined'&&voiceSpeechGuard?.blocked))return;")&&app.includes('if(finalReceived&&spoken)submitRecognizedVoiceTurn(spoken)')&&app.includes('void submitVoiceTurn(words)'),
  'Interim transcript has a dedicated accessible surface':page.includes('id="voiceTranscript"')&&page.includes('role="status" aria-live="polite"')&&app.includes("showTranscript(spoken?'You: '+spoken"),
  'Speech recognition never overwrites the typed chat draft':!app.includes('input.value=transcript.trim()')&&app.includes('const phrases=[];let finalReceived=false;'),
  'Player does not displace the chat popup from the chat bar':css.includes('body.has-player .agent-chat-popup{bottom:calc(100% + 12px)}'),
  'Reply gate waits for request and spoken output':app.includes('voiceTurnBusy=true;')&&app.includes('await command(spoken);await pendingReply')&&app.includes('finally{if(turn===voiceTurnGeneration){voiceTurnBusy=false;if(st.conversationOn)'),
  'Recognition cannot restart during processing or speaking':app.includes('||voiceTurnBusy)return;')&&app.includes("['THINKING','RESPONDING','SPEAKING']"),
  'Both typed and voiced questions appear in conversation':app.includes("popupEntry('user',text)")&&app.includes("popupEntry('assistant',answer)"),
  'Normal private chat endpoint remains in use':app.includes("secureJsonPost('agent-chat.php'")&&app.includes("api('agent-brain.php'"),
  'Configured ElevenLabs remains preferred with local browser speech fallback':app.includes("+'/agent-voice.php'")&&app.includes('const remote=await elevenLabsSpeak(text,false,sequence)')&&app.includes('window.speechSynthesis.speak(utterance)'),
  'Empty replies and network failures are visible':app.includes('returned no reply. Please try again.')&&app.includes('The live agent is unavailable right now.'),
  'No popup forced open on reply':!app.includes('await openChatPopup()')&&!app.includes('openChatPopup();await say'),
  'Popup is responsive and its messages scroll':css.includes('.agent-chat-popup-messages{flex:1;min-height:0;')&&css.includes('@media(max-width:720px){.chat-composer-shell'),
  'New chat clears persisted conversation ID':app.includes("localStorage.removeItem('stonefellow.agent.conversation')")
};
let failed=0;
for(const [name,ok] of Object.entries(checks)){console.log(`${ok?'PASS':'FAIL'} ${name}`);if(!ok)failed++}
if(failed)process.exit(1);
