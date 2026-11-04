(() => {
  'use strict';

  // The Admin sketch mirrors the listener's cue without mounting the live Agent Canvas.
  function mount(host) {
    host.innerHTML = `
      <div class="sf-moment-admin-orb-preview" role="group" aria-label="Listener orb preview">
        <div class="sf-moment-admin-orb" aria-hidden="true"></div>
        <div class="sf-agent-song-cue" hidden>
          <span class="sf-agent-song-cue-spark" aria-hidden="true">✧</span>
          <span class="sf-agent-song-cue-copy"><span class="sf-agent-song-cue-stamp"></span><span class="sf-agent-song-cue-title"></span></span>
          <span class="sf-agent-song-cue-arrow" aria-hidden="true">›</span>
        </div>
      </div>
      <p class="sf-moment-admin-preview-caption" data-moment-cue-status role="status"></p>`;
    const cue = host.querySelector('.sf-agent-song-cue');
    const stamp = host.querySelector('.sf-agent-song-cue-stamp');
    const titleNode = host.querySelector('.sf-agent-song-cue-title');
    const status = host.querySelector('[data-moment-cue-status]');

    return function update({kind, atMs, title, story, photo, trackId, durationMs, visible} = {}) {
      const reference = kind === 'story' ? story : photo;
      let message = '';
      if (kind === 'note') message = 'Artist notes appear in the timeline without an orb cue.';
      else if (kind === 'lyric') message = 'Lyric segments appear in the timeline without an orb cue.';
      else if (kind !== 'story' && kind !== 'photo') message = 'Choose a story or photo to preview an orb cue.';
      else if (!trackId) message = 'Choose a song for this orb cue.';
      else if (!reference) message = kind === 'story' ? 'Choose a published story to preview its orb cue.' : 'Choose a public photo to preview its orb cue.';
      else if (kind === 'story' && story.entity_type === 'track' && String(story.entity_id) !== String(trackId)) {
        message = 'Choose a published story linked to this song, or a story without a song link.';
      } else if (!Number.isSafeInteger(atMs) || atMs < 0 || atMs > 86400000) {
        message = 'Enter a valid start time to preview the orb cue.';
      } else if (Number.isFinite(durationMs) && durationMs > 0 && atMs > durationMs) {
        message = 'Choose a start time within the song to preview the orb cue.';
      }

      cue.hidden = !!message || visible !== true;
      if (cue.hidden) {
        stamp.textContent = '';
        titleNode.textContent = '';
      } else {
        const seconds = Math.floor(atMs / 1000);
        const at = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
        const label = kind === 'story' ? 'Story' : 'Photo';
        const referenceTitle = reference.cue_title ?? reference.title ?? '';
        const cueTitle = String(title ?? '').trim() || String(referenceTitle).trim();
        stamp.textContent = at + ' · ' + label;
        titleNode.textContent = cueTitle.slice(0, 160) || (kind === 'story' ? 'Song story' : 'Song photo');
        message = 'Private preview of the ' + label.toLowerCase() + ' cue at ' + at + '.';
      }
      if (!message) message = 'Select Preview orb cue to see the current form values.';
      if (status.textContent !== message) status.textContent = message;
    };
  }

  window.SFSongMomentCuePreview = Object.freeze({mount});
})();
