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
  const momentLabels = Object.freeze({story:'Story', photo:'Photo', comment:'Listener comment'});
  const storyRoute = /^story\/([a-zA-Z0-9_-]{1,160})$/;
  const photoRoute = /^api\/media\.php\?id=M[A-F0-9]{20}&variant=thumb$/;
  function sectionContext(entries, onAction) {
    if (!Array.isArray(entries)) return null;
    const moments = entries.filter(item => item && Object.hasOwn(momentLabels, item.kind) &&
      Number.isInteger(item.id) && item.id > 0 && Number.isInteger(item.at_ms) && item.at_ms >= 0 &&
      typeof item.title === 'string' && typeof item.text === 'string').slice(0, 9);
    if (!moments.length) return null;
    const panel = el('details', 'song-experience-context');
    panel.append(el('summary', '', 'Song context for this section (' + moments.length + ')'));
    const list = el('ul', 'song-experience-context-list');
    for (const moment of moments) {
      const item = el('li', 'song-experience-context-item');
      const label = momentLabels[moment.kind];
      const copy = el('div', 'song-experience-context-copy');
      copy.append(el('small', '', clock(moment.at_ms) + ' · ' + label));
      const story = moment.kind === 'story' && typeof moment.href === 'string' && moment.href.match(storyRoute);
      if (story) {
        const link = el('a', '', moment.title || 'Read the story');
        link.href = moment.href;
        link.addEventListener('click', event => {
          if (!onAction || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
          event.preventDefault();
          onAction('story', story[1]);
        });
        copy.append(link);
      } else if (moment.title && moment.title !== label) copy.append(el('strong', '', moment.title));
      if (moment.text) copy.append(el('p', 'song-experience-copy', moment.text));
      if (moment.kind === 'photo' && typeof moment.image_url === 'string' && photoRoute.test(moment.image_url)) {
        const photo = el('img', 'song-experience-context-photo');
        photo.loading = 'lazy';
        photo.alt = typeof moment.alt === 'string' ? moment.alt : (moment.title || 'Song moment photo');
        photo.src = moment.image_url;
        item.append(photo);
      }
      item.append(copy);
      list.append(item);
    }
    panel.append(list);
    return panel;
  }
  const editors = new WeakMap();
  function mount(target, experience, onAction) {
    if (!target) return;
    target.replaceChildren();
    target.hidden = !experience;
    if (!experience) return;
    const state = experience;
    const savedLyrics = state.lyrics_source === 'saved-catalog-lyrics';
    const card = el('section', 'song-experience-card');
    card.setAttribute('aria-label', 'Song listening, transcript and analysis');
    const eyebrow = el('div', 'song-experience-kicker', 'SONG LISTENING SESSION');
    const heading = el('h3', '', state.title || 'Current song section');
    const meta = el('p', 'song-experience-meta', clock(state.start_ms) + ' – ' + (state.end_ms == null ? 'Live' : clock(state.end_ms)));
    const phaseLabels = {
      starting: 'Starting lyric listening…', stopping: 'Stopping lyric listening…', unavailable: 'Song lyric listening unavailable',
      capturing: 'Listening now', captured: 'Recorded · ready to transcribe',
      transcribing: 'Creating an unverified lyric draft…', draft: 'Unverified transcript ready',
      analyzing: 'Reviewing lyrics and Stonefellow song notes…', report: 'Lyric interpretation ready',
      discarded: 'Listening ended without a usable excerpt'
    };
    const phase = el('p', 'song-experience-phase'+(state.live_enabled?' song-experience-live':''),
      state.phase==='capturing'&&state.listening_mode==='catalog'?(savedLyrics?'Selecting song section · Stop retrieves saved lyrics':'Selecting song section · Stop converts it into text'):
      savedLyrics&&state.phase==='transcribing'?'Loading saved song lyrics…':
      savedLyrics&&state.phase==='draft'?'Saved lyric selection ready':
      state.phase==='capturing'&&state.live_enabled ? (state.auto_interpret?'Listening · live lyrics → review before interpretation':'Listening · provisional live lyrics') :
        phaseLabels[state.phase] || 'Session captured');
    phase.setAttribute('role', 'status');
    card.append(eyebrow, heading, meta, phase);
    if (state.transcript) {
      const panel = el('section', 'song-experience-transcript');
      const titleRow = el('div', 'song-experience-transcript-heading');
      const visibleDraft = state.corrected || state.transcript;
      const draftKey = [state.track_id, state.start_ms, state.end_ms].join('|');
      let editor = editors.get(target);
      if (editor && editor.key !== draftKey) { editors.delete(target); editor = null; }
      const canEdit = !!state.canAnalyze && !['capturing', 'transcribing', 'analyzing', 'discarded'].includes(state.phase);
      if (!canEdit && editor) { editors.delete(target); editor = null; }
      const isEditing = !!editor;
      titleRow.append(el('h4', '', savedLyrics&&!state.corrected ? (state.review_status==='approved'?'Saved lyrics · administrator approved':'Saved lyric draft · unverified') : state.corrected ? 'Listener-edited draft · unverified' :
        state.canAnalyze ? 'Automated lyric draft · unverified' : 'Live lyric preview · provisional, unverified'));
      if (canEdit) {
        const editButton = el('button', 'song-experience-edit-icon', isEditing ? '×' : '✎');
        editButton.type = 'button';
        editButton.title = isEditing ? 'Cancel lyric editing' : 'Correct lyrics before interpretation';
        editButton.setAttribute('aria-label', isEditing ? 'Cancel lyric editing' : 'Edit lyric draft');
        editButton.addEventListener('click', () => {
          if (isEditing) editors.delete(target);
          else editors.set(target, {key:draftKey, text:visibleDraft});
          mount(target, state, onAction);
        });
        titleRow.append(editButton);
      }
      panel.append(titleRow);
      if(savedLyrics&&state.lyric_segments?.length){
        const ranges=state.lyric_segments.slice(0,8).map(segment=>clock(segment.start_ms)+'–'+clock(segment.end_ms));
        panel.append(el('small','','Saved lyric blocks: '+ranges.join(', ')+(state.lyric_segments.length>8?'…':'')));
      }
      if (isEditing) {
        const form = el('form', 'song-experience-lyric-editor');
        const label = el('label', '', 'Correct the recognized lyric words');
        const textarea = el('textarea', 'song-experience-edit-textarea');
        textarea.rows = 5; textarea.maxLength = 4200;
        textarea.value = editor.text;
        textarea.addEventListener('input', () => {editor.text = textarea.value;});
        label.append(textarea);
        const error = el('small', 'song-experience-edit-error'); error.hidden = true;
        const buttons = el('div', 'song-experience-edit-actions');
        const save = el('button', '', 'Save lyric correction'); save.type = 'submit';
        const cancel = el('button', '', 'Cancel'); cancel.type = 'button';
        cancel.addEventListener('click', () => {editors.delete(target);mount(target,state,onAction);});
        buttons.append(save,cancel);
        form.append(label,error,buttons);
        form.addEventListener('submit', event => {
          event.preventDefault();
          const corrected = textarea.value.trim();
          if (corrected.length < 3 || corrected.length > 4200) {
            error.textContent = 'Enter 3–4,200 characters of corrected lyrics.';
            error.hidden = false;
            return;
          }
          editors.delete(target);
          onAction?.('edit', corrected);
        });
        panel.append(form);
      } else panel.append(el('p', 'song-experience-copy', visibleDraft));
      if (state.corrected) panel.append(el('small', '', 'Original machine draft retained in this session.'));
      panel.append(el('small', '', savedLyrics ? 'Saved song lyrics are reused for this selection. Block timing is approximate unless manually aligned. Nothing is saved to Agent Brain automatically.' : 'Automated words can be wrong. Nothing is saved to Agent Brain automatically.'));
      if (state.live_enabled) panel.append(el('small', '',
        state.listening_mode==='recognition' ? 'Listening directly to the player. No song clip is being recorded.' :
           (Number(state.segments_completed)||0)+' section(s) transcribed. The final full-clip pass will replace these provisional words.'));
      card.append(panel);
      const context = state.phase === 'discarded' ? null : sectionContext(state.section_context, onAction);
      if (context) card.append(context);
    }
    for (const [index, report] of (state.reports || []).entries()) {
      const panel = el('section', 'song-experience-report');
      panel.append(el('h4', '', 'Lyric interpretation ' + (index + 1)));
      panel.append(el('p', 'song-experience-copy', report.text));
      const details = el('details', 'song-experience-provenance');
      details.append(el('summary', '', 'Sources & interpretation metadata'));
      if (report.question) details.append(el('small', '', 'Question: ' + report.question));
      if (Array.isArray(report.sources) && report.sources.length)
        details.append(el('small', 'song-experience-sources', 'Published sources: ' + report.sources.join(' · ')));
      else details.append(el('small', 'song-experience-sources', 'No song-specific published songwriter notes matched.'));
      if (Number.isInteger(report.shared_note_count) && report.shared_note_count > 0)
        details.append(el('small', '', 'Shared listener notes available to this interpretation: ' + report.shared_note_count));
      details.append(el('small', '', report.draft_status==='approved_catalog_lyrics'?'Interpretation of reviewed song lyrics; no live web sources were searched.':'Interpretation of an unverified draft; no live web sources were searched.'));
      panel.append(details);
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
      else if (!state.live_enabled&&state.listening_mode!=='catalog') {
        button('Start live transcription', 'live');
      }
    }
    if(state.phase==='starting'&&state.listening_mode==='catalog')button('Stop', 'stop');
    if(state.phase==='transcribing'&&state.listening_mode==='catalog')button('Stop waiting', 'stop');
    if (state.phase === 'captured' && !['recognition','catalog'].includes(state.listening_mode)) button('Transcribe excerpt', 'transcribe');
    if (state.transcript && !['transcribing', 'analyzing', 'discarded'].includes(state.phase)) {
      button(state.reports?.length ? 'Interpret again' : (state.auto_interpret ? 'Review & interpret lyrics' : 'Interpret lyrics'), 'analyze', !state.canAnalyze);
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
      card.append(el('small', 'song-experience-consent', (savedLyrics?'Interpret / Ask Agent sends this saved lyric selection and question to your configured AI provider.':'Interpret / Ask Agent sends the unverified excerpt and question to your configured AI provider.')+' Review & interpret submits only after you select it; follow-up requests remain manual.'));
    }
    target.append(card);
  }
  window.SFSongExperienceUI = Object.freeze({ mount });
})();
