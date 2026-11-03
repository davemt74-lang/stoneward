/* Section 91B17P — public Vocal Studio. Microphone takes and local mixes are
 * never uploaded. The existing player and song-section recorder stay separate.
 */
(() => {
  'use strict';
  const MAX_TAKE_MS = 90000;
  const MAX_TAKE_BYTES = 60 * 1024 * 1024;
  const types = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/webm'];
  const clamp = (value, low, high) => Math.max(low, Math.min(high, Number(value) || 0));
  const formatTime = seconds => `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;
  const safeName = value => String(value || 'Stonefellow').replace(/[^a-z0-9_-]+/gi, '-').slice(0, 75) || 'Stonefellow';

  function create({audio, getTrack, drawer, backdrop, mediaDevices = navigator.mediaDevices,
    AudioContextClass = window.AudioContext || window.webkitAudioContext,
    Recorder = window.MediaRecorder, onBeforeRecord = () => {}, onCanRecord = () => true,
    onClose = () => {}, onFeedback = () => {}} = {}) {
    if (!audio || !drawer || !backdrop || typeof getTrack !== 'function') throw new Error('Vocal Studio requires the real player and drawer.');
    const query = selector => drawer.querySelector(selector);
    const deviceSelect = query('#vocalMicSelect');
    const monitorBar = query('#vocalMicMeter');
    const meterText = query('#vocalMicLabel');
    const feedback = query('#vocalStudioStatus');
    const connectButton = query('#vocalMicConnect');
    const startButton = query('#vocalStart');
    const stopButton = query('#vocalStop');
    const songLabel = query('#vocalSong');
    const timerLabel = query('#vocalTimer');
    const results = query('#vocalResults');
    const mixPreview = query('#vocalMixPreview');
    const vocalPreview = query('#vocalDryPreview');
    const mixDownload = query('#vocalMixDownload');
    const vocalDownload = query('#vocalDryDownload');
    const inputLevel = query('#vocalInputLevel');
    const songLevel = query('#vocalSongLevel');
    const closeButton = query('#vocalStudioClose');
    let micStream = null;
    let micSource = null;
    let analyser = null;
    let audioContext = null;
    let songSource = null;
    let songIsRerouted = false;
    let meterFrame = 0;
    let currentTake = null;
    let lastTake = null;
    let lastUrls = [];
    let returnFocus = null;
    let connectedDeviceId = '';
    let connecting = false;
    let disposed = false;
    let message = 'Choose your microphone, then play a song and record your vocal.';
    const supported = () => !!(mediaDevices?.getUserMedia && mediaDevices?.enumerateDevices && AudioContextClass && Recorder && window.isSecureContext);

    function report(text) {
      message = String(text || '');
      feedback.textContent = message;
      onFeedback(message);
    }
    function trackMatches(take) {
      return String(getTrack()?.id || '') === take.trackId && String(audio.currentSrc || audio.src || '') === take.source;
    }
    function isRecording() { return !!currentTake; }
    function render() {
      const track = getTrack();
      songLabel.textContent = track?.id ? `${track.title || 'Song'} · ${audio.paused ? 'Paused' : 'Playing'}` : 'Load a song from the music library';
      const active = isRecording();
      connectButton.disabled = active || connecting || !supported();
      connectButton.textContent = connecting ? 'Connecting…' : micStream ? 'Reconnect microphone' : 'Enable microphone';
      deviceSelect.disabled = active || connecting;
      startButton.disabled = active || connecting || !supported();
      stopButton.disabled = !active;
      inputLevel.disabled = !active;
      songLevel.disabled = !active;
      drawer.querySelectorAll?.('[data-vocal-tab]')?.forEach(tab => { tab.disabled = active; });
      query('#vocalStudioRecording').hidden = !active;
      drawer.classList.toggle('is-recording', active);
      if (!active) timerLabel.textContent = lastTake ? formatTime(lastTake.seconds) : '0:00';
    }
    async function devices() {
      if (!mediaDevices?.enumerateDevices) return [];
      let devicesList = [];
      try { devicesList = (await mediaDevices.enumerateDevices()).filter(device => device.kind === 'audioinput'); }
      catch (_) { report('Unable to list input devices. You can still try the default microphone.'); }
      const previous = connectedDeviceId || deviceSelect.value;
      deviceSelect.replaceChildren();
      const auto = new Option('System default microphone', '');
      deviceSelect.add(auto);
      devicesList.forEach((device, index) => {
        const name = String(device.label || `Microphone ${index + 1}`).slice(0, 150);
        deviceSelect.add(new Option(name, device.deviceId));
      });
      if (previous && devicesList.some(device => device.deviceId === previous)) deviceSelect.value = previous;
      else deviceSelect.value = '';
      meterText.textContent = micStream ? `Connected · ${micStream.getAudioTracks()[0]?.label || 'Microphone'}` : 'Permission is requested only when you enable a microphone.';
      return devicesList;
    }
    function stopMeter() {
      cancelAnimationFrame(meterFrame); meterFrame = 0;
      monitorBar.style.width = '0%';
    }
    function animateMeter() {
      if (!analyser || !micStream || drawer.hidden || disposed) { stopMeter(); return; }
      const data = new Uint8Array(analyser.fftSize);
      analyser.getByteTimeDomainData(data);
      let energy = 0;
      for (const sample of data) { const value = (sample - 128) / 128; energy += value * value; }
      monitorBar.style.width = `${Math.min(100, Math.round(Math.sqrt(energy / data.length) * 420))}%`;
      meterFrame = requestAnimationFrame(animateMeter);
    }
    async function prepareAudioContext() {
      if (!audioContext || audioContext.state === 'closed') audioContext = new AudioContextClass();
      if (audioContext.state === 'suspended') await audioContext.resume();
      return audioContext;
    }
    function releaseMic() {
      stopMeter();
      try { micSource?.disconnect(); } catch (_) {}
      micSource = null; analyser = null;
      micStream?.getTracks?.().forEach(track => track.stop());
      micStream = null;
      connectedDeviceId = '';
      render();
    }
    async function connectMic() {
      if (isRecording() || connecting) return false;
      if (!supported()) {
        report(window.isSecureContext ? 'This browser does not support the required audio recording APIs.' : 'Microphone access requires HTTPS. Open Stonefellow over a secure connection.');
        return false;
      }
      connecting = true; render();
      const requestedId = deviceSelect.value;
      // One explicit permission request for each connect / device change; do
      // not loop or ask repeatedly after a denial, especially on iOS.
      try {
        releaseMic();
        const constraints = {echoCancellation:false, noiseSuppression:false, autoGainControl:false};
        if (requestedId) constraints.deviceId = {exact:requestedId};
        const stream = await mediaDevices.getUserMedia({audio:constraints, video:false});
        if (disposed) { stream.getTracks().forEach(track => track.stop()); return false; }
        micStream = stream;
        const context = await prepareAudioContext();
        micSource = context.createMediaStreamSource(stream);
        analyser = context.createAnalyser();
        analyser.fftSize = 512;
        micSource.connect(analyser); // No connection to speakers: no feedback.
        connectedDeviceId = requestedId;
        stream.getAudioTracks()[0]?.addEventListener('ended', () => {
          if (currentTake) void stop('Microphone disconnected. The take ended.');
          releaseMic(); report('Microphone disconnected. Select an input to reconnect.');
        }, {once:true});
        await devices();
        if (!drawer.hidden) animateMeter();
        report(`Microphone ready: ${stream.getAudioTracks()[0]?.label || 'system input'}. Use headphones to avoid feedback.`);
        return true;
      } catch (error) {
        releaseMic();
        const name = String(error?.name || '');
        report(name === 'NotAllowedError' || name === 'PermissionDeniedError'
          ? 'Microphone access was denied. Check browser/site permissions; press Enable microphone only when ready to retry.'
          : name === 'NotFoundError' || name === 'OverconstrainedError'
          ? 'That microphone is unavailable. Connect your Focusrite and choose another input.'
          : `Microphone connection failed: ${error?.message || 'Check the selected interface.'}`);
        return false;
      } finally { connecting = false; render(); }
    }
    function makeRecorder(stream) {
      const mimeType = types.find(type => Recorder.isTypeSupported?.(type));
      return new Recorder(stream, mimeType ? {mimeType} : undefined);
    }
    function capture(recorder) {
      const chunks = []; let bytes = 0; let resolveFinish;
      const finished = new Promise(resolve => { resolveFinish = resolve; });
      recorder.ondataavailable = event => {
        if (!event.data?.size) return;
        bytes += event.data.size;
        if (bytes <= MAX_TAKE_BYTES) chunks.push(event.data);
        else if (recorder.state !== 'inactive') recorder.stop();
      };
      recorder.onerror = () => { if (recorder.state !== 'inactive') recorder.stop(); };
      recorder.onstop = () => resolveFinish(bytes && bytes <= MAX_TAKE_BYTES
        ? new Blob(chunks, {type:recorder.mimeType || chunks[0]?.type || 'audio/webm'}) : null);
      return finished;
    }
    function connectMusic(context, destination, gain) {
      if (!songSource && typeof (audio.captureStream || audio.mozCaptureStream) === 'function') {
        try {
          const stream = (audio.captureStream || audio.mozCaptureStream).call(audio);
          if (stream?.getAudioTracks?.().length) {
            songSource = context.createMediaStreamSource(stream);
            songIsRerouted = false;
          }
        } catch (_) { /* Safari may expose captureStream but provide no tracks. */ }
      }
      if (!songSource) {
        // Safari / iPhone fallback. Keep this context alive after the take;
        // a MediaElementAudioSource cannot safely be detached back to native
        // output once the element has been connected to a Web Audio graph.
        songSource = context.createMediaElementSource(audio);
        songSource.connect(context.destination);
        songIsRerouted = true;
      }
      songSource.connect(gain);
      gain.connect(destination);
    }
    function disconnectMix(take) {
      try { take.micGain?.disconnect(); } catch (_) {}
      try { take.songGain?.disconnect(); } catch (_) {}
      try { songSource?.disconnect(take.songGain); } catch (_) {}
      if (!songIsRerouted) { try { songSource?.disconnect(); } catch (_) {} songSource = null; }
    }
    async function start() {
      if (currentTake || connecting) return false;
      if (!onCanRecord()) {
        report('Finish the active Song Clip or lyric listening session before recording your vocals.');
        return false;
      }
      const track = getTrack();
      if (!track?.id || !track.audio || !audio.src || audio.paused || audio.ended) {
        report('Start playing a Stonefellow song first, then press Start vocal take.'); return false;
      }
      if (!micStream && !await connectMic()) return false;
      try {
        onBeforeRecord();
        const context = await prepareAudioContext();
        if (audio.paused || audio.ended) { report('Music stopped. Resume playback, then start the take.'); return false; }
        const destination = context.createMediaStreamDestination();
        const micGain = context.createGain();
        const songGain = context.createGain();
        micGain.gain.value = clamp(inputLevel.value, 0, 200) / 100;
        songGain.gain.value = clamp(songLevel.value, 0, 200) / 100;
        micSource.connect(micGain);
        micGain.connect(destination);
        connectMusic(context, destination, songGain);
        const mixed = makeRecorder(destination.stream);
        const vocal = makeRecorder(micStream);
        const take = {trackId:String(track.id),trackName:String(track.title || 'Stonefellow'),
          source:String(audio.currentSrc || audio.src || ''),startPosition:Number(audio.currentTime) || 0,
          startedAt:Date.now(),destination,mixed,vocal,micGain,songGain,timer:0,clock:0,stopping:false};
        const mixedFinished = capture(mixed);
        const vocalFinished = capture(vocal);
        take.finished = Promise.all([mixedFinished,vocalFinished]);
        try { mixed.start(500); vocal.start(500); }
        catch (error) {
          if (mixed.state !== 'inactive') mixed.stop();
          if (vocal.state !== 'inactive') vocal.stop();
          disconnectMix(take);
          throw error;
        }
        currentTake = take;
        // Never keep recording indefinitely in a background tab.
        take.timer = setTimeout(() => { void stop('90-second take limit reached.'); }, MAX_TAKE_MS);
        take.clock = setInterval(() => { timerLabel.textContent = formatTime((Date.now() - take.startedAt) / 1000); }, 250);
        report(`Recording vocals over “${take.trackName}”. Only this device holds the recordings.`);
        render();
        return true;
      } catch (error) {
        report(`Could not start the vocal mix: ${error?.message || 'Recording is unavailable.'}`);
        return false;
      }
    }
    function cleanupPreview() {
      mixPreview.pause(); vocalPreview.pause();
      mixPreview.removeAttribute('src'); vocalPreview.removeAttribute('src');
      mixDownload.removeAttribute('href'); vocalDownload.removeAttribute('href');
      lastUrls.forEach(url => URL.revokeObjectURL(url)); lastUrls = [];
      results.hidden = true;
    }
    async function stop(reason = '') {
      const take = currentTake;
      if (!take || take.stopping) return false;
      take.stopping = true;
      clearTimeout(take.timer); clearInterval(take.clock);
      for (const recorder of [take.mixed,take.vocal]) {
        if (recorder.state !== 'inactive') try { recorder.stop(); } catch (_) {}
      }
      const [mixed, vocal] = await take.finished;
      disconnectMix(take);
      if (currentTake !== take) return false;
      currentTake = null;
      const seconds = Math.min(MAX_TAKE_MS / 1000, (Date.now() - take.startedAt) / 1000);
      if (!mixed?.size || !vocal?.size || seconds < 0.3) {
        report('The take was not captured. Check microphone input and browser recording support.'); render(); return false;
      }
      cleanupPreview();
      lastTake = {trackId:take.trackId,trackName:take.trackName,seconds,mixed,vocal,
        startPosition:take.startPosition,createdAt:new Date().toISOString()};
      const mixUrl = URL.createObjectURL(mixed),vocalUrl = URL.createObjectURL(vocal);
      lastUrls.push(mixUrl,vocalUrl);
      mixPreview.src = mixUrl; vocalPreview.src = vocalUrl;
      mixDownload.href = mixUrl; vocalDownload.href = vocalUrl;
      const extension = blob => blob.type.includes('mp4') ? 'm4a' : 'webm';
      mixDownload.download = `${safeName(take.trackName)}-vocal-mix.${extension(mixed)}`;
      vocalDownload.download = `${safeName(take.trackName)}-voice-only.${extension(vocal)}`;
      results.hidden = false;
      report((reason ? `${reason} ` : '') + `Take ready · ${formatTime(seconds)}. Preview the mix or download either file. Nothing has been published.`);
      render(); return true;
    }
    function open() {
      if (disposed) return;
      returnFocus = document.activeElement;
      backdrop.hidden = false; drawer.hidden = false;
      document.body.classList.add('sf-vocal-studio-open');
      void devices();
      if (micStream) animateMeter();
      render();
      closeButton.focus({preventScroll:true});
    }
    function close() {
      const ending = !!currentTake;
      if (ending) void stop('Closed recording panel.').finally(() => { if (drawer.hidden) releaseMic(); });
      drawer.hidden = true; backdrop.hidden = true;
      document.body.classList.remove('sf-vocal-studio-open');
      if (!ending) releaseMic();
      onClose();
      returnFocus?.focus?.({preventScroll:true});
    }
    function handlePlaybackChange(reason) {
      if (currentTake && (!trackMatches(currentTake) || audio.paused || audio.ended || reason === 'seek'))
        void stop(reason === 'seek' ? 'The song position changed, so the take stopped.' : 'Song playback changed, so the take stopped.');
      render();
    }
    const onDeviceChange = () => { void devices(); if (!drawer.hidden) report('Audio devices changed. Choose the input you want to use.'); };
    const onPageHide = () => { if (currentTake) void stop('Recording ended as the page closed.'); releaseMic(); };
    const onKeydown = event => { if (event.key === 'Escape' && !drawer.hidden) { event.preventDefault(); close(); } };
    const onMicConnect = () => { void connectMic(); };
    const onMicChange = () => { if (!currentTake && micStream) void connectMic(); };
    const onStart = () => { void start(); };
    const onStop = () => { void stop(); };
    const onInput = () => { if (currentTake) currentTake.micGain.gain.value = clamp(inputLevel.value,0,200)/100; };
    const onSong = () => { if (currentTake) currentTake.songGain.gain.value = clamp(songLevel.value,0,200)/100; };
    const onSeek = () => { handlePlaybackChange('seek'); };
    const onChange = () => { handlePlaybackChange('playback'); };
    mediaDevices?.addEventListener?.('devicechange', onDeviceChange);
    window.addEventListener('pagehide',onPageHide);
    document.addEventListener('keydown',onKeydown);
    for (const event of ['play','pause','ended','loadstart']) audio.addEventListener(event,onChange);
    audio.addEventListener('seeking',onSeek);
    closeButton.addEventListener('click',close);
    backdrop.addEventListener('click',close);
    connectButton.addEventListener('click',onMicConnect);
    deviceSelect.addEventListener('change',onMicChange);
    startButton.addEventListener('click',onStart);
    stopButton.addEventListener('click',onStop);
    inputLevel.addEventListener('input',onInput);
    songLevel.addEventListener('input',onSong);
    report(message);
    render();
    return Object.freeze({open,close,start,stop,devices,disconnectMic:()=>{if(!currentTake)releaseMic();},status:() => ({active:isRecording(),connected:!!micStream,last:lastTake,
      message}),destroy:() => {
      disposed = true; close(); cleanupPreview();
      mediaDevices?.removeEventListener?.('devicechange',onDeviceChange);
      window.removeEventListener('pagehide',onPageHide);document.removeEventListener('keydown',onKeydown);
      for (const event of ['play','pause','ended','loadstart']) audio.removeEventListener(event,onChange);
      audio.removeEventListener('seeking',onSeek);
      closeButton.removeEventListener('click',close);backdrop.removeEventListener('click',close);
      connectButton.removeEventListener('click',onMicConnect);deviceSelect.removeEventListener('change',onMicChange);
      startButton.removeEventListener('click',onStart);stopButton.removeEventListener('click',onStop);
      inputLevel.removeEventListener('input',onInput);songLevel.removeEventListener('input',onSong);
      // A rerouted element must retain its output connection until unload.
      if (!songIsRerouted) void audioContext?.close?.();
    }});
  }
  window.StonefellowVocalStudio = Object.freeze({create, MAX_TAKE_MS});
})();
