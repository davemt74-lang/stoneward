(() => {
  'use strict';

  const validId = /^[A-Za-z0-9_-]{1,180}$/;
  let current = null;

  const node = (tag, className, text) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };
  const button = (text, className = '') => {
    const element = node('button', className, text);
    element.type = 'button';
    return element;
  };
  const duration = value => {
    const seconds = Math.max(0, Math.round(Number(value) || 0));
    return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
  };

  function open(options = {}) {
    if (typeof options.api !== 'function' || options.signal?.aborted) return Promise.resolve(null);
    const opener = current?.opener || document.activeElement;
    current?.close(null, false);

    return new Promise(resolve => {
      const dialog = node('dialog', 'sf-canvas-music-picker');
      dialog.setAttribute('aria-labelledby', 'canvasMusicPickerTitle');
      dialog.setAttribute('aria-describedby', 'canvasMusicPickerDescription');
      const panel = node('section', 'sf-canvas-music-picker-panel');
      const header = node('header');
      const title = node('h2', '', 'Choose canvas music');
      title.id = 'canvasMusicPickerTitle';
      const description = node('p', '', 'Choose songs or albums to make available to your canvas.');
      description.id = 'canvasMusicPickerDescription';
      const searchLabel = node('label', 'sf-canvas-music-search');
      const search = node('input');
      search.type = 'search';
      search.maxLength = 200;
      search.placeholder = 'Search songs or albums';
      searchLabel.append(node('span', '', 'Search music'), search);
      const tabs = node('div', 'sf-canvas-music-tabs');
      tabs.setAttribute('role', 'tablist');
      tabs.setAttribute('aria-label', 'Music categories');
      const songsTab = button('Songs');
      const albumsTab = button('Albums');
      const tabButtons = [songsTab, albumsTab];
      tabButtons.forEach((tab, index) => {
        tab.id = 'canvasMusicTab-' + index;
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', 'canvasMusicOptions');
        tabs.append(tab);
      });
      header.append(title, description, searchLabel, tabs);
      const body = node('div', 'sf-canvas-music-picker-body');
      const status = node('p', 'sf-canvas-music-status');
      status.setAttribute('role', 'status');
      const retry = button('Try again');
      retry.hidden = true;
      const results = node('div', 'sf-canvas-music-options');
      results.id = 'canvasMusicOptions';
      results.setAttribute('role', 'tabpanel');
      body.append(status, retry, results);
      const footer = node('footer');
      const count = node('span', 'sf-canvas-music-count');
      count.setAttribute('aria-live', 'polite');
      const actions = node('div', 'sf-canvas-music-actions');
      const cancel = button('Cancel');
      const all = button('Use entire catalog');
      const add = button('Add songs', 'sf-canvas-music-primary');
      actions.append(cancel, all, add);
      footer.append(count, actions);
      panel.append(header, body, footer);
      dialog.append(panel);
      const request = new AbortController();
      const selected = new Set();
      let tracks = [];
      let releases = [];
      let songMap = new Map();
      let releaseMap = new Map();
      let ready = false;
      let loading = false;
      let tab = 0;
      let settled = false;
      const rows = [];

      function close(value = null, restoreFocus = true) {
        if (settled) return;
        settled = true;
        request.abort();
        options.signal?.removeEventListener('abort', aborted);
        window.removeEventListener('pagehide', leaving);
        if (current?.dialog === dialog) current = null;
        if (dialog.open) dialog.close();
        dialog.remove();
        if (restoreFocus && opener?.isConnected) opener.focus({preventScroll: true});
        resolve(value);
      }
      const aborted = () => close();
      const leaving = () => close(null, false);
      current = {dialog, opener, close};

      function sync() {
        count.textContent = selected.size + (selected.size === 1 ? ' song selected' : ' songs selected');
        add.disabled = !ready || selected.size === 0;
        all.disabled = !ready || !tracks.some(track => track.source_available === true);
        for (const {input, ids} of rows) {
          const matches = ids.filter(id => selected.has(id)).length;
          input.checked = ids.length > 0 && matches === ids.length;
          input.indeterminate = matches > 0 && matches < ids.length;
        }
      }

      function render() {
        rows.length = 0;
        results.replaceChildren();
        tabButtons.forEach((button, index) => {
          button.setAttribute('aria-selected', String(index === tab));
          button.tabIndex = index === tab ? 0 : -1;
        });
        results.setAttribute('aria-labelledby', tabButtons[tab].id);
        if (!ready) { sync(); return; }
        const query = search.value.trim().toLocaleLowerCase();
        const items = tab === 0 ? tracks : releases;
        let shown = 0;
        for (const item of items) {
          const labels = tab === 0
            ? (item.release_ids || []).map(id => releaseMap.get(id)?.title || '')
            : item.track_ids.map(id => songMap.get(id)?.title || '');
          if (query && ![item.title, ...labels].join(' ').toLocaleLowerCase().includes(query)) continue;
          const ids = tab === 0 ? item.source_available === true ? [item.track_id] : []
            : item.track_ids.filter(id => songMap.get(id)?.source_available === true);
          const label = node('label', 'sf-canvas-music-row');
          const input = node('input');
          input.type = 'checkbox';
          input.disabled = ids.length === 0;
          input.value = tab === 0 ? item.track_id : item.release_id;
          const copy = node('span', 'sf-canvas-music-row-copy');
          const heading = node('strong', '', item.title || (tab === 0 ? 'Untitled song' : 'Untitled album'));
          heading.id = 'canvasMusicOption-' + shown;
          const detail = node('small');
          detail.id = 'canvasMusicDetail-' + shown;
          detail.textContent = tab === 0
            ? ids.length ? labels.filter(Boolean).join(' · ') || 'Song' : 'Hosted MP3 unavailable'
            : ids.length + (ids.length === 1 ? ' available song' : ' available songs') +
              (item.track_ids.length > ids.length ? ' · some MP3s unavailable' : '');
          input.setAttribute('aria-labelledby', heading.id);
          input.setAttribute('aria-describedby', detail.id);
          copy.append(heading, detail);
          label.append(input, copy);
          if (tab === 0) label.append(node('span', 'sf-canvas-music-duration', duration(item.duration_seconds)));
          input.addEventListener('change', () => {
            if (settled || input.disabled) return;
            for (const id of ids) {
              if (input.checked) selected.add(id);
              else selected.delete(id);
            }
            sync();
          });
          rows.push({input, ids});
          results.append(label);
          shown++;
        }
        if (!shown) results.append(node('p', 'sf-canvas-music-empty', query ? 'No music matches your search.' : tab === 0 ? 'No public songs are available yet.' : 'No public albums are available yet.'));
        status.textContent = '';
        sync();
      }

      async function load() {
        if (loading || settled) return;
        loading = true;
        retry.hidden = true;
        status.textContent = 'Loading public songs and albums…';
        try {
          const catalog = await options.api('canvas-audio.php?action=catalog', undefined, request.signal);
          if (settled) return;
          if (!Array.isArray(catalog?.tracks) || !Array.isArray(catalog?.releases)) throw new Error('The music catalog could not be read.');
          songMap = new Map();
          for (const track of catalog.tracks) {
            if (typeof track?.track_id !== 'string' || !validId.test(track.track_id) || songMap.has(track.track_id)) continue;
            songMap.set(track.track_id, {...track, release_ids: Array.isArray(track.release_ids) ? track.release_ids : []});
          }
          tracks = Array.from(songMap.values());
          releaseMap = new Map();
          for (const release of catalog.releases) {
            if (typeof release?.release_id !== 'string' || !validId.test(release.release_id) || releaseMap.has(release.release_id)) continue;
            const ids = Array.from(new Set((Array.isArray(release.track_ids) ? release.track_ids : [])
              .filter(id => typeof id === 'string' && validId.test(id))));
            releaseMap.set(release.release_id, {...release, track_ids: ids});
          }
          releases = Array.from(releaseMap.values());
          const initialIds = options.selection?.mode === 'all' ? tracks.map(track => track.track_id) :
            options.selection?.mode === 'selected' ? options.selection.track_ids : options.selectedIds;
          for (const id of Array.isArray(initialIds) ? initialIds : []) {
            if (songMap.get(id)?.source_available === true) selected.add(id);
          }
          ready = true;
          render();
        } catch (error) {
          if (settled) return;
          status.textContent = error.message || 'Public music could not be loaded.';
          retry.hidden = false;
          sync();
        } finally {
          loading = false;
        }
      }

      tabButtons.forEach((button, index) => {
        button.addEventListener('click', () => { tab = index; render(); });
        button.addEventListener('keydown', event => {
          if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
          event.preventDefault();
          tab = event.key === 'Home' ? 0 : event.key === 'End' ? 1 : 1 - tab;
          render();
          tabButtons[tab].focus();
        });
      });
      search.addEventListener('input', render);
      retry.addEventListener('click', () => void load());
      cancel.addEventListener('click', () => close());
      add.addEventListener('click', () => {
        if (!ready || !selected.size) return;
        const keepAll = options.selection?.mode === 'all' && tracks.filter(track => track.source_available === true)
          .every(track => selected.has(track.track_id));
        close(keepAll ? {mode: 'all'} : {mode: 'selected', track_ids: Array.from(selected)});
      });
      all.addEventListener('click', () => { if (!all.disabled) close({mode: 'all'}); });
      dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
      dialog.addEventListener('close', () => close());
      dialog.addEventListener('click', event => { if (event.target === dialog) close(); });
      dialog.addEventListener('keydown', event => event.stopPropagation());
      options.signal?.addEventListener('abort', aborted, {once: true});
      window.addEventListener('pagehide', leaving, {once: true});
      render();
      document.body.append(dialog);
      try {
        dialog.showModal();
        search.focus({preventScroll: true});
        void load();
      } catch (_) {
        close();
      }
    });
  }

  window.SFCanvasMusicPicker = Object.freeze({open});
})();
