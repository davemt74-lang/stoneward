(() => {
  'use strict';

  const aliasPattern = /^[a-z][a-z0-9-]{0,31}$/;
  const productPattern = /^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/;
  const placePattern = /^[A-Za-z0-9_-]{1,100}$/;
  let current = null;
  let nextId = 0;

  const node = (tag, className, text) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };
  const button = text => {
    const element = node('button', '', text);
    element.type = 'button';
    return element;
  };
  const option = (value, text) => {
    const element = node('option', '', text);
    element.value = value;
    return element;
  };
  const label = (text, control) => {
    const element = node('label', 'sf-canvas-resource-search');
    element.append(node('span', '', text), control);
    return element;
  };
  const validProduct = id => typeof id === 'string' && id.length <= 64 && productPattern.test(id);
  const validPlace = id => typeof id === 'string' && placePattern.test(id);

  function open(options = {}) {
    if (typeof options.load !== 'function' || typeof options.search !== 'function' ||
        typeof options.resolvePlace !== 'function' || options.signal?.aborted) return Promise.resolve(null);
    const opener = current?.opener || document.activeElement;
    current?.close(null, false);

    return new Promise(resolve => {
      const prefix = 'canvasDataPicker-' + (++nextId);
      const dialog = node('dialog', 'sf-canvas-resource-picker sf-canvas-data-picker');
      const panel = node('section', 'sf-canvas-resource-picker-panel');
      const header = node('header');
      const title = node('h2', '', 'Choose live data');
      const description = node('p', '', 'Add named sources to your canvas draft. You can combine different sources and locations in one prompt.');
      title.id = prefix + '-title';
      description.id = prefix + '-description';
      dialog.setAttribute('aria-labelledby', title.id);
      dialog.setAttribute('aria-describedby', description.id);
      header.append(title, description);

      const body = node('div', 'sf-canvas-resource-picker-body');
      const status = node('p', 'sf-canvas-resource-status');
      status.setAttribute('role', 'status');
      const retry = button('Retry data sources');
      retry.hidden = true;
      const list = node('ul', 'sf-canvas-data-selected');
      list.setAttribute('aria-label', 'Selected data sources');
      const builder = node('div', 'sf-canvas-data-builder');
      builder.hidden = true;

      const product = node('select');
      const productDescription = node('p', 'sf-canvas-resource-status');
      const fieldDetails = node('details', 'sf-canvas-resource-account-fields');
      const fieldList = node('ul');
      fieldDetails.append(node('summary', '', 'Available fields'), fieldList);
      const placeSearch = node('form', 'sf-canvas-data-search');
      const query = node('input');
      query.type = 'search';
      query.maxLength = 100;
      query.placeholder = 'City, state or venue';
      const search = button('Search');
      search.type = 'submit';
      const searchStatus = node('p', 'sf-canvas-resource-status');
      searchStatus.setAttribute('role', 'status');
      placeSearch.append(label('Search for a city or venue', query), search);

      const place = node('select');
      const alias = node('input');
      alias.type = 'text';
      alias.maxLength = 32;
      alias.autocapitalize = 'off';
      alias.spellcheck = false;
      const aliasHelp = node('small', '', 'Start with a letter. Use lowercase letters, numbers and hyphens.');
      aliasHelp.id = prefix + '-alias-help';
      alias.setAttribute('aria-describedby', aliasHelp.id);
      const add = button('Add source');
      const entry = node('form', 'sf-canvas-data-entry');
      const locationLabel = label('Location', place);
      const entryStatus = node('p', 'sf-canvas-resource-status');
      entryStatus.setAttribute('role', 'status');
      add.type = 'submit';
      entry.append(locationLabel, label('Source name', alias), aliasHelp, entryStatus, add);
      builder.append(label('Data source', product), productDescription, fieldDetails, placeSearch, searchStatus, entry);
      body.append(status, retry, list, builder);

      const footer = node('footer');
      const count = node('span', 'sf-canvas-resource-count');
      count.setAttribute('aria-live', 'polite');
      const actions = node('div', 'sf-canvas-resource-actions');
      const cancel = button('Cancel');
      const attach = button('Attach sources');
      attach.className = 'sf-canvas-resource-primary';
      actions.append(cancel, attach);
      footer.append(count, actions);
      panel.append(header, body, footer);
      dialog.append(panel);

      const request = new AbortController();
      const products = new Map();
      const places = new Map();
      let searchResults = new Map();
      const selected = [];
      for (const source of Array.isArray(options.selectedSources) ? options.selectedSources : []) {
        if (source && typeof source.alias === 'string' && aliasPattern.test(source.alias) &&
            validProduct(source.product) && validPlace(source.location_id)) {
          selected.push({alias: source.alias, product: source.product, location_id: source.location_id});
        }
      }
      let maxSources = 4;
      let ready = false;
      let settled = false;
      let placeRequest = null;
      let previousSuggestion = '';

      function close(value = null, restoreFocus = true) {
        if (settled) return;
        settled = true;
        request.abort();
        placeRequest?.abort();
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

      const scopesFor = productId => products.get(productId)?.scopeOptions;
      const locationFor = (productId, locationId) => scopesFor(productId)?.get(locationId) || places.get(locationId);

      function sync() {
        count.textContent = selected.length + ' of ' + maxSources + ' sources';
        const aliases = new Set(selected.map(source => source.alias));
        const unusable = aliases.size !== selected.length || selected.some(source =>
          !products.has(source.product) || !locationFor(source.product, source.location_id));
        attach.disabled = !ready || unusable || selected.length > maxSources;
        add.disabled = !ready || selected.length >= maxSources || !place.value;
      }

      function drawSelected() {
        list.replaceChildren();
        selected.forEach((source, index) => {
          const location = locationFor(source.product, source.location_id);
          const metadata = products.get(source.product);
          const row = node('li', 'sf-canvas-data-source');
          const copy = node('span', 'sf-canvas-resource-row-copy');
          const title = node('strong', '', source.alias);
          const details = [metadata?.title || source.product, location?.title || source.location_id];
          if (ready && (!metadata || !location)) details.push('Unavailable');
          copy.append(title, node('small', '', details.join(' · ')));
          const remove = button('Remove');
          remove.setAttribute('aria-label', 'Remove ' + source.alias);
          remove.addEventListener('click', () => {
            selected.splice(index, 1);
            drawSelected();
            suggestName();
            (list.querySelector('button') || (builder.hidden ? attach : query)).focus();
          });
          row.append(copy, remove);
          list.append(row);
        });
        sync();
      }

      function rememberPlaces(result) {
        if (!result || !Array.isArray(result.items)) throw new Error('Location results were incomplete.');
        const matches = new Map();
        for (const item of result.items) {
          if (item && validPlace(item.id) && typeof item.title === 'string' && item.title.trim() &&
              ['city', 'venue'].includes(item.kind) && !matches.has(item.id)) {
            const value = {id: item.id, title: item.title.trim(), kind: item.kind};
            matches.set(value.id, value);
            places.set(value.id, value);
          }
        }
        return matches;
      }

      function suggestName() {
        if (alias.value && alias.value !== previousSuggestion) return;
        const chosen = locationFor(product.value, place.value);
        let suggestion = '';
        if (chosen && product.value) {
          const slug = chosen.title.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
          const base = (slug && /^[a-z]/.test(slug) ? slug : 'source') + '-' + product.value.split('.')[1];
          suggestion = base.slice(0, 32).replace(/-+$/g, '');
          for (let n = 2; selected.some(source => source.alias === suggestion); n++) {
            const suffix = '-' + n;
            suggestion = base.slice(0, 32 - suffix.length).replace(/-+$/g, '') + suffix;
          }
        }
        previousSuggestion = suggestion;
        alias.value = suggestion;
      }

      function drawProduct() {
        const metadata = products.get(product.value);
        productDescription.textContent = typeof metadata?.description === 'string' ? metadata.description : '';
        const parameterLabel = metadata?.parameters.location_id.label;
        locationLabel.firstChild.textContent = typeof parameterLabel === 'string' && parameterLabel.trim() ? parameterLabel : 'Location';
        entryStatus.textContent = '';
        fieldList.replaceChildren();
        for (const [name, type] of Object.entries(metadata?.fields || {})) {
          if (typeof type === 'string') {
            const item = node('li');
            item.append(node('code', '', name), node('small', '', ' · ' + type));
            fieldList.append(item);
          }
        }
        fieldDetails.hidden = !fieldList.childElementCount;
        drawPlaces(place.value);
      }

      function drawPlaces(selectedId) {
        const scopes = scopesFor(product.value);
        place.replaceChildren(option('', 'Choose a city or venue'));
        for (const scope of scopes?.values() || []) place.append(option(scope.id, scope.title));
        for (const match of searchResults.values()) {
          if (!scopes?.has(match.id)) place.append(option(match.id, match.title + (match.kind === 'venue' ? ' · Venue' : '')));
        }
        place.value = scopes?.has(selectedId) || searchResults.has(selectedId) ? selectedId : '';
        place.disabled = place.options.length === 1;
        suggestName();
        sync();
      }

      function resetPlaces() {
        searchResults.clear();
        drawPlaces(place.value);
      }

      async function findPlaces(value) {
        placeRequest?.abort();
        const pending = new AbortController();
        placeRequest = pending;
        resetPlaces();
        search.disabled = true;
        searchStatus.textContent = 'Searching public locations…';
        try {
          const result = await options.search(value, pending.signal);
          if (settled || pending.signal.aborted) return;
          const selectedLocationMissing = selected.some(source => !locationFor(source.product, source.location_id));
          const matches = rememberPlaces(result);
          searchResults = matches;
          if (selectedLocationMissing) drawSelected();
          drawPlaces(place.value);
          searchStatus.textContent = matches.size ? matches.size + ' locations found.' :
            value ? 'No matching public cities or venues.' : 'Search for a city or venue to find a location.';
        } catch (error) {
          if (!settled && !pending.signal.aborted) searchStatus.textContent = error.message || 'Locations could not load.';
        } finally {
          if (placeRequest === pending && !settled) search.disabled = false;
        }
      }

      async function load() {
        retry.hidden = true;
        ready = false;
        status.textContent = 'Loading live data sources…';
        sync();
        try {
          const result = await options.load(request.signal);
          if (settled) return;
          if (!result || !Array.isArray(result.products)) throw new Error('The source inventory was incomplete.');
          if (Number.isInteger(result.max_sources) && result.max_sources > 0 && result.max_sources <= 12) {
            maxSources = result.max_sources;
          }
          products.clear();
          product.replaceChildren();
          for (const item of result.products) {
            if (!item || item.enabled === false || !validProduct(item.id) || typeof item.title !== 'string' ||
                item.parameters?.location_id?.type !== 'public_place' || products.has(item.id)) continue;
            const scopeOptions = new Map();
            const offeredScopes = item.parameters.location_id.scope_options;
            for (const scope of Array.isArray(offeredScopes) ? offeredScopes : []) {
              if (scope?.kind === 'scope' && validPlace(scope.id) && typeof scope.title === 'string' &&
                  scope.title.trim() && !scopeOptions.has(scope.id)) {
                scopeOptions.set(scope.id, {id: scope.id, title: scope.title.trim()});
              }
            }
            products.set(item.id, {...item, scopeOptions});
            product.append(option(item.id, item.title));
          }
          const unresolved = selected.filter(source =>
            products.has(source.product) && !scopesFor(source.product)?.has(source.location_id));
          const ids = [...new Set(unresolved.map(source => source.location_id))].slice(0, maxSources);
          await Promise.allSettled(ids.map(async id => {
            const placeResult = await options.resolvePlace(id, request.signal);
            if (!settled) rememberPlaces({items: Array.isArray(placeResult?.items) ? placeResult.items.filter(item => item?.id === id) : []});
          }));
          if (settled) return;
          ready = true;
          builder.hidden = !products.size;
          status.textContent = products.size ? '' : 'Live data sources are currently unavailable.';
          drawProduct();
          drawSelected();
          if (products.size) {
            if (document.activeElement === cancel) query.focus({preventScroll: true});
            void findPlaces('');
          }
        } catch (error) {
          if (settled) return;
          status.textContent = error.message || 'Live data sources could not load.';
          retry.hidden = false;
        }
      }

      product.addEventListener('change', drawProduct);
      place.addEventListener('change', () => { suggestName(); sync(); });
      alias.addEventListener('input', () => { entryStatus.textContent = ''; });
      query.addEventListener('input', () => {
        placeRequest?.abort();
        search.disabled = false;
        searchStatus.textContent = '';
        resetPlaces();
      });
      placeSearch.addEventListener('submit', event => {
        event.preventDefault();
        const value = query.value.trim();
        if (value.length === 1) {
          searchStatus.textContent = 'Enter at least two characters to search.';
          return;
        }
        void findPlaces(value);
      });
      entry.addEventListener('submit', event => {
        event.preventDefault();
        if (add.disabled || !products.has(product.value) || !locationFor(product.value, place.value)) return;
        const name = alias.value.trim().toLowerCase();
        if (!aliasPattern.test(name)) {
          entryStatus.textContent = 'Enter a source name using a letter followed by letters, numbers or hyphens.';
          alias.focus();
          return;
        }
        if (selected.some(source => source.alias === name)) {
          entryStatus.textContent = 'Use a different name for each source.';
          alias.focus();
          return;
        }
        selected.push({alias: name, product: product.value, location_id: place.value});
        entryStatus.textContent = '';
        alias.value = '';
        suggestName();
        drawSelected();
        (add.disabled ? attach : alias).focus();
      });
      retry.addEventListener('click', () => void load());
      cancel.addEventListener('click', () => close());
      attach.addEventListener('click', () => { if (!attach.disabled) close(selected); });
      dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
      dialog.addEventListener('close', () => close());
      options.signal?.addEventListener('abort', aborted, {once: true});
      window.addEventListener('pagehide', leaving, {once: true});

      document.body.append(dialog);
      dialog.showModal();
      drawSelected();
      void load();
    });
  }

  window.SFCanvasDataPicker = Object.freeze({open});
})();
