/* Section 91B11B — A single, browser-private song transcript and analysis card
 * used by the floating conversation and the full My Agent Chat canvas.
 * No provider calls or persistence are initiated by rendering this component.
 */
(() => {
  'use strict';
  const el = (tag, className, value) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (value !== undefined) node.textContent = String(value);
    return node;
  };
  const clock = ms => {
    const seconds = Math.max(0, Math.floor((Number(ms) || 0) / 1000));
    return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
  };
  function mount(target, experience, onAction) {
    if (!target) return;
    target.replaceChildren();
    target.hidden = !experience;
    if (!experience) return;
    const state = experience;
    const card = el('section', 'song-experience-card');
    card.setAttribute('aria-label', 'Song listening, transcript and analysis');
    const eyebrow = el('div', 'song-experience-kicker', 'SONG LISTENING SESSION');
    const heading = el('h3', '', state.title || 'Current song section');
    const meta = el('p', 'song-experience-meta', clock(state.start_ms) + ' – ' + (state.end_ms == null ? 'Live' : clock(state.end_ms)));
    const phaseLabels = {
      capturing: 'Listening now', captured: 'Recorded · ready to transcribe',
      transcribing: 'Creating an unverified lyric draft…', draft: 'Unverified transcript ready',
      analyzing: 'Reviewing lyrics and Stonefellow song notes…', report: 'Lyric interpretation ready',
      discarded: 'Listening ended without a usable excerpt'
    };
    const phase = el('p', 'song-experience-phase'+(state.live_enabled?' song-experience-live':''),
      state.phase==='capturing'&&state.live_enabled ? (state.auto_interpret?'Listening · live lyrics → interpretation on Stop':'Listening · provisional live lyrics') :
        phaseLabels[state.phase] || 'Session captured');
    phase.setAttribute('role', 'status');
    card.append(eyebrow, heading, meta, phase);
    if (state.transcript) {
      const panel = el('section', 'song-experience-transcript');
      panel.append(el('h4', '', state.corrected ? 'Listener-edited draft · unverified' :
        state.canAnalyze?'Automated lyric draft · unverified':'Live lyric preview · provisional, unverified'));
      panel.append(el('p', 'song-experience-copy', state.corrected || state.transcript));
      if (state.corrected) panel.append(el('small', '', 'Original machine draft retained in this session.'));
      panel.append(el('small', '', 'Automated words can be wrong. Nothing is saved to Agent Brain automatically.'));
      if (state.live_enabled) panel.append(el('small', '',
        state.listening_mode==='recognition' ? 'Listening directly to the player. No song clip is being recorded.' :
           (Number(state.segments_completed)||0)+' section(s) transcribed. The final full-clip pass will replace these provisional words.'));
      card.append(panel);
    }
    for (const [index, report] of (state.reports || []).entries()) {
      const panel = el('section', 'song-experience-report');
      panel.append(el('h4', '', 'Lyric interpretation ' + (index + 1) + (report.question ? ' · ' + report.question : '')));
      panel.append(el('p', 'song-experience-copy', report.text));
      if (Array.isArray(report.sources) && report.sources.length)
        panel.append(el('small', 'song-experience-sources', 'Songwriter context: ' + report.sources.join(' · ')));
      else panel.append(el('small', 'song-experience-sources', 'No song-specific published songwriter notes were matched; any suggestions about intent are interpretation.'));
      panel.append(el('small', '', 'Interpretation of an unverified draft and approved Stonefellow context. No live web sources were searched.'));
      card.append(panel);
    }
    if (state.message) card.append(el('p', 'song-experience-note', state.message));
    if (state.live_error) card.append(el('p', 'song-experience-note', state.live_error));
    const actions = el('div', 'song-experience-actions');
    const button = (label, action, disabled = false) => {
      const b = el('button', '', label);
      b.type = 'button'; b.disabled = disabled;
      b.addEventListener('click', () => onAction?.(action));
      actions.append(b);
    };
    if (state.phase === 'capturing') {
      button('Stop listening', 'stop');
      if (state.listening_mode==='record_clip') card.append(el('small', 'song-experience-consent',
        'Record Clip keeps a browser-local audio excerpt. It does not automatically transcribe or interpret.'));
      else if (!state.live_enabled) {
        button('Start live transcription', 'live');
      }
    }
    if (state.phase === 'captured' && state.listening_mode !== 'recognition') button('Transcribe excerpt', 'transcribe');
    if (state.transcript && !['transcribing', 'analyzing', 'discarded'].includes(state.phase)) {
      button(state.reports?.length ? 'Interpret again' : 'Interpret lyrics', 'analyze', !state.canAnalyze);
      button(state.saved_note_id ? 'Saved privately #' + state.saved_note_id : 'Save private note', 'save', !state.canAnalyze || !!state.saved_note_id);
    }
    if (state.phase === 'transcribing' || state.phase === 'analyzing') {
      actions.append(el('span', 'song-experience-wait', 'This can take a moment. Your player keeps working.'));
    }
    button('Open My Agent Chat', 'open');
    card.append(actions);
    if (state.transcript && state.canAnalyze && !['transcribing', 'analyzing'].includes(state.phase)) {
      const form = el('form', 'song-experience-followup');
      const label = el('label', '', 'Ask about this lyric section');
      const input = el('input');
      input.type = 'text'; input.maxLength = 700; input.required = true;
      input.placeholder = 'What does that line mean?';
      label.append(input);
      const ask = el('button', '', 'Ask Agent'); ask.type = 'submit';
      form.append(label, ask);
      form.addEventListener('submit', event => {
        event.preventDefault();
        const question = input.value.trim();
        if (question) onAction?.('ask', question);
      });
      card.append(form);
      card.append(el('small', 'song-experience-consent', 'Interpret / Ask Agent sends the unverified excerpt and question to your configured AI provider. Listen & Interpret authorizes this once when selected; follow-up requests remain manual.'));
    }
    target.append(card);
  }
  window.SFSongExperienceUI = Object.freeze({ mount });
})();
