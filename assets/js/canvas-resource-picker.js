(() => {
  'use strict';

  const kinds = Object.freeze({
    canvas: {title: 'Choose canvases', search: 'Search canvases', loading: 'Loading your canvases…', empty: 'No saved canvases are available yet.', fallback: 'Untitled canvas'},
    merch: {title: 'Choose merch', search: 'Search merch', loading: 'Loading public merch…', empty: 'No public merch is available yet.', fallback: 'Untitled merch'},
    playlists: {title: 'Choose playlists', search: 'Search playlists and tracks', loading: 'Loading available playlists…', empty: 'No playlists are available.', fallback: 'Untitled playlist'},
    tickets: {title: 'Choose ticket offers', search: 'Search ticket offers', loading: 'Loading public ticket offers…', empty: 'No public ticket offers are available yet.', fallback: 'Untitled ticket offer'}
  });
  let current = null;
  let nextId = 0;

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
  const textValue = value => typeof value === 'string' ? value.trim() : '';
  const imageUrl = value => {
    if (typeof value !== 'string' || !value.trim()) return '';
    try {
      const url = new URL(value, document.baseURI);
      return ['http:', 'https:'].includes(url.protocol) && url.origin === window.location.origin && !url.username && !url.password ? url.href : '';
    } catch (_) {
      return '';
    }
  };
  const validId = (kind, value) => typeof value === 'string' && (kind === 'canvas' ? /^[1-9][0-9]*$/ : kind==='playlists'?/^(?:own|artist)-[1-9][0-9]{0,18}$/:/^[A-Za-z0-9_-]+$/).test(value);

  function open(options = {}) {
    if (!options || typeof options !== 'object') return Promise.resolve(null);
    const config = Object.prototype.hasOwnProperty.call(kinds, options.kind) ? kinds[options.kind] : null;
    if (!config || typeof options.load !== 'function' || options.signal?.aborted) return Promise.resolve(null);
    const opener = current?.opener || document.activeElement;
    current?.close(null, false);

    return new Promise(resolve => {
      const prefix = 'canvasResourcePicker-' + (++nextId);
      const dialog = node('dialog', 'sf-canvas-resource-picker');
      dialog.setAttribute('aria-labelledby', prefix + '-title');
      dialog.setAttribute('aria-describedby', prefix + '-description');
      const panel = node('section', 'sf-canvas-resource-picker-panel');
      const header = node('header');
      const title = node('h2', '', config.title);
      title.id = prefix + '-title';
      const description = node('p', '', options.kind==='playlists'?'Choose your own or published artist playlists. Track order is preserved; the agent designs the experience you request. Selection does not play music or start a build.':'Choose items to add to your prompt. Adding selections does not start a build.');
      description.id = prefix + '-description';
      const searchLabel = node('label', 'sf-canvas-resource-search');
      const search = node('input');
      search.type = 'search';
      search.placeholder = config.search;
      searchLabel.append(node('span', '', config.search), search);
      header.append(title, description, searchLabel);
      const body = node('div', 'sf-canvas-resource-picker-body');
      const status = node('p', 'sf-canvas-resource-status');
      status.setAttribute('role', 'status');
      const retry = button('Try again');
      retry.hidden = true;
      const results = node('div', 'sf-canvas-resource-options');
      body.append(status, retry, results);
      const footer = node('footer');
      const count = node('span', 'sf-canvas-resource-count');
      count.setAttribute('aria-live', 'polite');
      const actions = node('div', 'sf-canvas-resource-actions');
      const cancel = button('Cancel');
      const add = button('Add selections', 'sf-canvas-resource-primary');
      actions.append(cancel, add);
      footer.append(count, actions);
      panel.append(header, body, footer);
      dialog.append(panel);

      const request = new AbortController();
      const selected = new Set();
      const rows = [];
      let items = [];
      let ready = false;
      let loading = false;
      let settled = false;

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
        count.textContent = selected.size + ' selected';
        const unavailable=options.kind==='playlists'&&items.some(item=>item.unavailable&&selected.has(item.id));
        add.disabled = !ready || loading || unavailable;
        for (const {input, id} of rows) input.checked = selected.has(id);
      }

      function render() {
        rows.length = 0;
        results.replaceChildren();
        if (!ready) { sync(); return; }
        const query = search.value.trim().toLocaleLowerCase();
        let shown = 0;
        for (const item of items) {
          if (query && !item.search.includes(query)) continue;
          const label = node('label', 'sf-canvas-resource-row');
          const input = node('input');
          input.type = 'checkbox';
          input.value = item.id;
          const copy = node('span', 'sf-canvas-resource-row-copy');
          const heading = node('strong', '', item.title);
          heading.id = prefix + '-option-' + shown;
          input.setAttribute('aria-labelledby', heading.id);
          copy.append(heading);
          if (item.detail) {
            const detail = node('small', '', item.detail);
            detail.id = prefix + '-detail-' + shown;
            input.setAttribute('aria-describedby', detail.id);
            copy.append(detail);
          }
          label.append(input);
          if (item.image) {
            const image = node('img', 'sf-canvas-resource-image');
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            image.addEventListener('error', () => image.remove(), {once: true});
            image.src = item.image;
            label.append(image);
          }
          label.append(copy);
          if (item.price) label.append(node('span', 'sf-canvas-resource-price', item.price));
          input.addEventListener('change', () => {
            if (settled || !ready) return;
            if (input.checked) selected.add(item.id);
            else selected.delete(item.id);
            sync();
          });
          rows.push({input, id: item.id});
          if(options.kind==='playlists'){
            const group=node('section','sf-canvas-resource-playlist');group.append(label);
            if(item.changed)group.append(node('p','sf-canvas-resource-status','Updated since your selected version. Adding selections uses the version shown here.'));
            if(item.unavailable)group.append(node('p','sf-canvas-resource-status','This selected playlist is unavailable. Remove it or choose another playlist before adding selections.'));
            else{
              const details=node('details'),summary=node('summary','',item.tracks.length+' ordered track'+(item.tracks.length===1?'':'s'));
              summary.style.cssText='min-height:44px;padding:8px 12px;cursor:pointer;box-sizing:border-box';
              const members=node('ol');members.style.cssText='padding-left:36px;overflow-wrap:anywhere';
              for(const track of item.tracks){
                const unavailable=!track.available;
                const line=node('li','',unavailable?'Unavailable track':track.title||'Untitled track');
                if(unavailable)line.append(node('small','',' · Not currently available'));
                else if(Number.isFinite(track.duration_seconds)&&track.duration_seconds>0){const seconds=Math.floor(track.duration_seconds);line.append(node('small','',' · '+Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0')));}
                members.append(line);
              }
              if(!item.tracks.length)members.append(node('li','','This playlist has no track entries.'));
              details.append(summary,members);group.append(details);
            }
            results.append(group);
          }else results.append(label);
          shown++;
        }
        if (!shown) results.append(node('p', 'sf-canvas-resource-empty', query ? 'No items match your search.' : config.empty));
        status.textContent = '';
        sync();
      }

      async function load() {
        if (loading || settled) return;
        loading = true;
        ready = false;
        retry.hidden = true;
        status.textContent = config.loading;
        results.replaceChildren();
        sync();
        try {
          const result = await options.load(request.signal);
          if (settled || request.signal.aborted) return;
          if (!Array.isArray(result?.items) || result.ok === false) throw new Error('The selection list could not be read.');
          const seen = new Set();
          items = [];
          for (const item of result.items) {
            if (!validId(options.kind, item?.id) || seen.has(item.id)) continue;
            seen.add(item.id);
            const title = textValue(item.title) || config.fallback;
            const metadata = ['description', 'category', 'show_title', 'show_date', 'venue', 'location', 'availability_label']
              .map(key => textValue(item[key])).filter(Boolean);
            const detail = Array.from(new Set(metadata)).join(' · ');
            const price = textValue(item.price_text);
            const playlist=options.kind==='playlists';
            const version=playlist&&typeof item.revision_hash==='string'&&/^[a-f0-9]{64}$/.test(item.revision_hash)?item.revision_hash:null;
            if(playlist&&!version)throw new Error('A playlist version could not be verified. Refresh the list.');
            if(playlist&&!Array.isArray(item.items))throw new Error('The ordered playlist entries could not be read. Refresh the list.');
            const tracks=playlist&&Array.isArray(item.items)?item.items.map(track=>({title:textValue(track.title),available:track.available===true,duration_seconds:track.duration_seconds})):[];
            const scope=playlist?(item.source==='own'||item.category==='own'?'My playlist':'Published artist playlist'):'';
            const playlistDetail=playlist?[scope,tracks.filter(track=>track.available).length+' of '+tracks.length+' tracks available',textValue(item.description)].filter(Boolean).join(' · '):detail;
            items.push({id: item.id, title, detail:playlistDetail, price, image: imageUrl(item.image_url),version,tracks,
              changed:playlist&&options.selectedVersions?.[item.id]&&options.selectedVersions[item.id]!==version,
              search: [title, playlistDetail, price,...tracks.filter(track=>track.available).map(track=>track.title)].join(' ').toLocaleLowerCase()});
          }
          selected.clear();
          for (const id of Array.isArray(options.selectedIds) ? options.selectedIds : []) {
            if(seen.has(id))selected.add(id);
            else if(options.kind==='playlists'&&validId(options.kind,id)){
              selected.add(id);items.push({id,title:'Unavailable selected playlist',detail:'Previously selected reference',search:id.toLocaleLowerCase(),unavailable:true,tracks:[]});
            }
          }
          if(options.kind==='playlists'&&options.signedIn===false)description.textContent='Sign in to choose your own playlists. Published artist playlists are available below. Selection does not play music or start a build.';
          ready = true;
          loading = false;
          render();
        } catch (error) {
          if (settled || request.signal.aborted) return;
          status.textContent = error?.message || 'The selection list could not be loaded.';
          retry.hidden = false;
        } finally {
          loading = false;
          if (!settled) sync();
        }
      }

      search.addEventListener('input', render);
      retry.addEventListener('click', () => void load());
      cancel.addEventListener('click', () => close());
      add.addEventListener('click', () => { if (ready && !loading && !settled && !add.disabled) close({ids: Array.from(selected),...(options.kind==='playlists'?{items:Array.from(selected,ref=>({ref,version:items.find(item=>item.id===ref).version}))}:{})}); });
      dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
      dialog.addEventListener('close', () => close());
      dialog.addEventListener('click', event => { if (event.target === dialog) close(); });
      dialog.addEventListener('keydown', event => event.stopPropagation());
      options.signal?.addEventListener('abort', aborted, {once: true});
      window.addEventListener('pagehide', leaving, {once: true});
      sync();
      if (options.signal?.aborted) { close(); return; }
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

  window.SFCanvasResourcePicker = Object.freeze({open});
})();
