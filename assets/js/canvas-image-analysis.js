(() => {
  'use strict';

  const privateImageId = /^[a-f0-9]{32}$/;
  const fields = [
    {key: 'description', label: 'Detailed description', max: 4000, rows: 4, wide: true},
    {key: 'visible_text', label: 'Text in the image', max: 12000, rows: 4, wide: true},
    {key: 'scene', label: 'Scene', max: 1000, rows: 2},
    {key: 'style', label: 'Visual style', max: 500, rows: 2},
    {key: 'objects', label: 'Objects', items: 30, itemMax: 120},
    {key: 'colors', label: 'Colors', items: 16, itemMax: 80},
    {key: 'tags', label: 'Tags', items: 30, itemMax: 80},
    {key: 'uncertainties', label: 'Uncertainties', items: 12, itemMax: 240}
  ];
  let current = null;
  let dialogNumber = 0;

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function button(text, className = '') {
    const node = element('button', className, text);
    node.type = 'button';
    return node;
  }

  function newRequestId() {
    return Array.from(window.crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
  }

  function imageUrl(image, options) {
    if (!privateImageId.test(image?.id || '')) return '';
    try {
      const path = options.imageUrl ? options.imageUrl(image) : 'api/agent-canvas-image.php?id=' + image.id;
      const url = new URL(path, document.baseURI);
      return ['http:', 'https:'].includes(url.protocol) && url.origin === window.location.origin && !url.username && !url.password ? url.href : '';
    } catch (_) {
      return '';
    }
  }

  function notifySaved(options, value) {
    try { options.onSaved?.(value); } catch (_) { /* The saved result stays available when its caller has closed. */ }
  }

  function dialog(title, description, options, render) {
    if (typeof options.api !== 'function' || options.signal?.aborted) return Promise.resolve(null);
    const parent = options.nested ? current : null;
    let root = current;
    while (root?.parent) root = root.parent;
    const opener = parent ? document.activeElement : root?.opener || document.activeElement;
    if (!parent) while (current) current.close(null, false);

    return new Promise(resolve => {
      const node = element('dialog', 'sf-image-analysis');
      const number = ++dialogNumber;
      node.setAttribute('aria-labelledby', 'imageAnalysisTitle-' + number);
      node.setAttribute('aria-describedby', 'imageAnalysisDescription-' + number);
      const panel = element('section', 'sf-image-analysis-panel');
      const header = element('header');
      const heading = element('h2', '', title);
      heading.id = 'imageAnalysisTitle-' + number;
      const introduction = element('p', '', description);
      introduction.id = 'imageAnalysisDescription-' + number;
      const closeButton = button('Close');
      const headingText = element('div');
      headingText.append(heading, introduction);
      header.append(headingText, closeButton);
      const body = element('div', 'sf-image-analysis-body');
      const status = element('p', 'sf-image-analysis-status');
      status.setAttribute('role', 'status');
      panel.append(header, body, status);
      node.append(panel);
      const request = new AbortController();
      let settled = false;

      function close(result = null, restoreFocus = true) {
        if (settled) return;
        settled = true;
        request.abort();
        options.signal?.removeEventListener('abort', aborted);
        window.removeEventListener('pagehide', leaving);
        if (current?.node === node) current = parent?.active() ? parent : null;
        if (node.open) node.close();
        node.remove();
        if (restoreFocus && opener?.isConnected) opener.focus({preventScroll: true});
        resolve(result);
      }
      const aborted = () => close();
      const leaving = () => close(null, false);
      current = {node, opener, close, parent, active: () => !settled};
      closeButton.addEventListener('click', () => close());
      node.addEventListener('cancel', event => { event.preventDefault(); close(); });
      node.addEventListener('close', () => close());
      node.addEventListener('click', event => { if (event.target === node) close(); });
      node.addEventListener('keydown', event => event.stopPropagation());
      options.signal?.addEventListener('abort', aborted, {once: true});
      window.addEventListener('pagehide', leaving, {once: true});
      const active = () => !settled;
      const api = (path, payload) => options.api(path, payload, request.signal);
      try {
        render({node, body, status, api, active, signal: request.signal});
        if (options.signal?.aborted) { close(); return; }
        document.body.append(node);
        node.showModal();
        closeButton.focus({preventScroll: true});
      } catch (_) {
        close();
      }
    });
  }

  function renderPreferences(host, records, personalizationEnabled, context, options) {
    host.replaceChildren();
    if (!personalizationEnabled) host.append(element('p', 'sf-image-analysis-hint', 'Personalization is off. Your confirmed details are saved and inactive.'));
    for (const [state, title, empty] of [
      ['pending', 'Suggestions to review', 'No photo suggestions are waiting for review.'],
      ['accepted', 'Confirmed preferences', 'You have no confirmed photo preferences.']
    ]) {
      const section = element('section', 'sf-image-profile-section');
      section.append(element('h3', '', title));
      const items = records.filter(record => record.status === state);
      if (!items.length) section.append(element('p', 'sf-image-analysis-hint', empty));
      for (const record of items) {
        const card = element('article', 'sf-image-profile-card');
        const source = imageUrl({id: record.source_id}, options);
        if (source) {
          const thumbnail = element('img', 'sf-image-profile-photo');
          thumbnail.alt = 'Source photo';
          thumbnail.src = source;
          thumbnail.loading = 'lazy';
          thumbnail.addEventListener('error', () => thumbnail.remove());
          card.append(thumbnail);
        }
        const content = element('div');
        content.append(element('small', 'sf-image-profile-category', String(record.category || 'Preference').replaceAll('_', ' ')));
        content.append(element('h4', '', record.value || ''));
        content.append(element('p', 'sf-image-profile-state', state === 'accepted' ? 'Confirmed by you' : 'Possible preference · needs your confirmation'));
        content.append(element('strong', 'sf-image-profile-evidence-label', 'Photo evidence'));
        content.append(element('p', 'sf-image-profile-evidence', record.evidence || 'No additional evidence supplied.'));
        const actions = element('div', 'sf-image-analysis-actions');
        for (const [action, label] of state === 'pending' ? [['accept', 'Accept'], ['dismiss', 'Dismiss']] : [['remove', 'Remove']]) {
          const control = button(label, action === 'accept' ? 'sf-image-analysis-primary' : '');
          control.addEventListener('click', async () => {
            if (!context.active()) return;
            context.setBusy?.(true);
            for (const other of host.querySelectorAll('button')) other.disabled = true;
            context.status.textContent = 'Saving your choice…';
            try {
              const result = await context.api('image-profile-actions.php', {action, id: record.id});
              if (!context.active()) return;
              const next = records.map(item => item.id === record.id ? result.suggestion : item);
              renderPreferences(host, next, personalizationEnabled, context, options);
              context.status.textContent = action === 'accept' ? 'Preference confirmed.' : action === 'dismiss' ? 'Suggestion dismissed.' : 'Preference removed.';
              notifySaved(options, {suggestions: next, personalization_enabled: personalizationEnabled});
            } catch (error) {
              if (context.active()) {
                context.status.textContent = error.message || 'Your choice could not be saved.';
                for (const other of host.querySelectorAll('button')) other.disabled = false;
              }
            } finally {
              if (context.active()) context.setBusy?.(false);
            }
          });
          actions.append(control);
        }
        content.append(actions);
        card.append(content);
        section.append(card);
      }
      host.append(section);
    }
  }

  function openPreferences(options = {}) {
    return dialog('Photo preferences', 'Review suggestions from photos you marked as your own. Confirm details that describe you to personalize your agent.', options, context => {
      const host = element('div', 'sf-image-profile-list');
      const refresh = button('Refresh preferences');
      context.body.append(host, refresh);
      let busy = false;
      context.setBusy = value => { busy = value; refresh.disabled = value; };
      const load = async () => {
        if (busy) return;
        context.setBusy(true);
        context.status.textContent = 'Loading your photo preferences…';
        try {
          const result = await context.api('image-profile-actions.php');
          if (!context.active()) return;
          renderPreferences(host, Array.isArray(result.suggestions) ? result.suggestions : [], result.personalization_enabled === true, context, options);
          context.status.textContent = '';
        } catch (error) {
          if (context.active()) context.status.textContent = error.message || 'Your photo preferences could not be loaded.';
        } finally {
          if (context.active()) context.setBusy(false);
        }
      };
      refresh.addEventListener('click', () => void load());
      void load();
    });
  }

  function open(image, options = {}) {
    if (!privateImageId.test(image?.id || '')) return Promise.resolve(null);
    return dialog('Analyze image', 'Review the image description, visible text and details before saving them for your agent.', options, context => {
      const layout = element('div', 'sf-image-analysis-layout');
      const preview = element('aside', 'sf-image-analysis-preview');
      const picture = element('img');
      picture.alt = typeof image.title === 'string' ? image.title : 'Private image';
      const url = imageUrl(image, options);
      if (url) picture.src = url;
      const unavailable = element('p', 'sf-image-analysis-hint', 'Image preview unavailable.');
      unavailable.hidden = Boolean(url);
      picture.hidden = !url;
      picture.addEventListener('error', () => { picture.hidden = true; unavailable.hidden = false; });
      preview.append(picture, unavailable, element('h3', '', image.title || 'Private image'));
      const main = element('div', 'sf-image-analysis-main');
      const tools = element('div', 'sf-image-analysis-tools');
      const analyze = button('Analyze image', 'sf-image-analysis-primary');
      const refresh = button('Reload analysis');
      const reset = button('Reset analysis');
      reset.hidden = true;
      tools.append(analyze, refresh, reset);
      const usage = element('p', 'sf-image-analysis-hint', 'Analyzing this image uses your account’s AI allowance. Saving reviewed details uses the analysis already received.');
      const profileLabel = element('label', 'sf-image-analysis-check');
      const suggestProfile = element('input');
      suggestProfile.type = 'checkbox';
      profileLabel.append(suggestProfile, element('span', '', 'Suggest profile details from this photo'));
      profileLabel.hidden = true;
      const state = element('p', 'sf-image-analysis-state');
      state.setAttribute('role', 'status');
      const form = element('form', 'sf-image-analysis-fields');
      const inputs = new Map();
      for (const field of fields) {
        const label = element('label', field.wide ? 'sf-image-analysis-field sf-image-analysis-wide' : 'sf-image-analysis-field');
        const text = element('span', '', field.label);
        text.id = 'imageAnalysisField-' + field.key;
        const input = element('textarea');
        input.name = field.key;
        input.setAttribute('aria-labelledby', text.id);
        input.rows = field.rows || 3;
        input.maxLength = field.max || field.items * (field.itemMax + 1);
        if (field.items) label.append(element('small', '', 'One item per line'));
        label.prepend(text);
        label.append(input);
        input.addEventListener('input', () => input.setCustomValidity(''));
        inputs.set(field.key, input);
        form.append(label);
      }
      const save = button('Save reviewed analysis', 'sf-image-analysis-primary');
      save.type = 'submit';
      const actions = element('div', 'sf-image-analysis-actions sf-image-analysis-wide');
      actions.append(save);
      form.append(actions);
      const preferences = element('div', 'sf-image-profile-list');
      preferences.hidden = true;
      main.append(tools, usage, profileLabel, state, form, preferences);
      layout.append(preview, main);
      context.body.append(layout);
      let latest = null;
      let metadata = {...image};
      let loaded = false;
      let busy = false;
      let pending = null;

      function syncControls() {
        const blocked = ['processing', 'unknown'].includes(latest?.analysis_status);
        analyze.disabled = busy || !loaded || blocked;
        refresh.disabled = busy;
        reset.hidden = !blocked;
        reset.disabled = busy || !blocked || !latest?.analysis_id;
        suggestProfile.disabled = busy || pending !== null;
        for (const input of inputs.values()) input.disabled = busy;
        save.disabled = busy || !latest?.analysis_id || !['draft', 'approved'].includes(latest.analysis_status);
      }
      context.setBusy = value => { busy = value; syncControls(); };

      function applyResult(result) {
        latest = result;
        loaded = true;
        metadata = {...metadata, ...(result.image || {})};
        profileLabel.hidden = !(metadata.source_kind === 'uploaded_photo' && metadata.is_own_photo === true);
        if (profileLabel.hidden) suggestProfile.checked = false;
        for (const field of fields) {
          const value = result.image_analysis?.[field.key];
          const input = inputs.get(field.key);
          input.value = field.items ? Array.isArray(value) ? value.join('\n') : '' : typeof value === 'string' ? value : '';
          input.setCustomValidity('');
        }
        const labels = {
          draft: 'Review these details, then save the analysis for your agent.',
          approved: 'Your reviewed analysis is saved.',
          processing: 'Analysis is running. Reload to check progress. Reset closes this request before you start a new analysis.',
          unknown: 'The provider outcome is uncertain. Reload to check its status, or reset before starting a new analysis.',
          failed: 'The previous analysis did not finish. You can analyze the image again.',
          stale: 'This image has changed. Analyze its current version.',
          canceled: 'This analysis was reset. Choose Analyze image to start a new request.'
        };
        state.textContent = labels[result.analysis_status] || 'Choose Analyze image to describe this image.';
      }

      async function loadPreferences() {
        try {
          const result = await context.api('image-profile-actions.php');
          if (!context.active()) return;
          preferences.hidden = false;
          const records = Array.isArray(result.suggestions) ? result.suggestions : [];
          renderPreferences(preferences, records.filter(record => record.source_id === image.id), result.personalization_enabled === true, context, {...options, onSaved: undefined});
        } catch (error) {
          if (context.active()) context.status.textContent = error.message || 'Photo preferences could not be loaded.';
        }
      }

      async function load() {
        if (busy) return;
        busy = true;
        syncControls();
        context.status.textContent = 'Loading image analysis…';
        try {
          const result = await context.api('agent-canvas-image-analysis.php?id=' + image.id);
          if (!context.active()) return;
          pending = null;
          applyResult(result);
          context.status.textContent = '';
          if (result.profile_suggestions?.length) void loadPreferences();
        } catch (error) {
          if (context.active()) context.status.textContent = error.message || 'Image analysis could not be loaded.';
        } finally {
          busy = false;
          if (context.active()) syncControls();
        }
      }

      analyze.addEventListener('click', async () => {
        if (busy || analyze.disabled) return;
        busy = true;
        syncControls();
        context.status.textContent = 'Analyzing your image…';
        const suggest = !profileLabel.hidden && suggestProfile.checked;
        if (!pending || pending.suggest !== suggest) {
          pending = {requestId: newRequestId(), suggest};
        }
        try {
          const result = await context.api('agent-canvas-image-analysis.php', {action: 'analyze', id: image.id,
            request_id: pending.requestId, ...(suggest ? {suggest_profile: true} : {})});
          if (!context.active()) return;
          applyResult(result);
          pending = null;
          context.status.textContent = ['draft', 'approved'].includes(result.analysis_status) ? 'Analysis received.' : '';
          if (result.profile_suggestions?.length) void loadPreferences();
        } catch (error) {
          if (context.active()) context.status.textContent = error.message || 'Image analysis could not finish. Retry to check this request.';
        } finally {
          busy = false;
          if (context.active()) syncControls();
        }
      });

      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || save.disabled) return;
        const analysis = {};
        for (const field of fields) {
          const input = inputs.get(field.key);
          const value = input.value;
          analysis[field.key] = field.items ? value.split(/\r?\n/).map(item => item.trim()).filter(Boolean) : value;
          const tooLong = field.items
            ? analysis[field.key].some(item => new TextEncoder().encode(item).length > field.itemMax)
            : new TextEncoder().encode(value).length > field.max;
          const tooMany = field.items && analysis[field.key].length > field.items;
          input.setCustomValidity(tooMany ? 'Use up to ' + field.items + ' entries.' : tooLong ? 'Shorten this entry before saving.' : '');
          if (!input.reportValidity()) return;
        }
        busy = true;
        syncControls();
        context.status.textContent = 'Saving reviewed analysis…';
        try {
          const result = await context.api('agent-canvas-image-analysis.php', {action: 'save', id: image.id,
            analysis_id: latest.analysis_id, revision: latest.revision, analysis});
          if (!context.active()) return;
          applyResult(result);
          context.status.textContent = 'Reviewed analysis saved.';
          notifySaved(options, {...metadata, image_analysis: result.image_analysis});
        } catch (error) {
          if (context.active()) context.status.textContent = error.message || 'Your reviewed analysis could not be saved.';
        } finally {
          busy = false;
          if (context.active()) syncControls();
        }
      });
      reset.addEventListener('click', async () => {
        if (busy || reset.disabled) return;
        busy = true;
        syncControls();
        context.status.textContent = 'Resetting image analysis…';
        try {
          const result = await context.api('agent-canvas-image-analysis.php', {action: 'reset', id: image.id, analysis_id: latest.analysis_id});
          if (!context.active()) return;
          pending = null;
          applyResult(result);
          context.status.textContent = 'Analysis reset. Start a new analysis when you’re ready.';
        } catch (error) {
          if (context.active()) context.status.textContent = error.message || 'Image analysis could not be reset.';
        } finally {
          busy = false;
          if (context.active()) syncControls();
        }
      });
      refresh.addEventListener('click', () => void load());
      void load();
    });
  }

  function openBatch(options = {}) {
    const selectedIds = Array.from(new Set((Array.isArray(options.selectedIds) ? options.selectedIds : [])
      .filter(id => typeof id === 'string' && privateImageId.test(id)))).slice(0, 200);
    return dialog('Batch image analysis', 'Analyze your private images, then review the saved drafts before approving their details.', options, context => {
      context.node.classList.add('sf-image-analysis-batch');
      const creation = element('div', 'sf-image-analysis-actions');
      const selected = button('Analyze ' + selectedIds.length + (selectedIds.length === 1 ? ' selected image' : ' selected images'), 'sf-image-analysis-primary');
      selected.hidden = selectedIds.length === 0;
      const missing = button('Analyze missing images');
      creation.append(selected, missing);
      const hint = element('p', 'sf-image-analysis-hint', 'Each image uses your account’s AI allowance. Closing this panel stops further images while the current image may finish. Resume saved progress when you’re ready. Profile suggestions are available when analyzing one photo.');
      const toolbar = element('div', 'sf-image-batch-toolbar');
      const historyLabel = element('label', 'sf-image-batch-history');
      const historyTitle = element('span', '', 'Saved batches');
      const history = element('select');
      history.setAttribute('aria-label', 'Saved batches');
      historyLabel.append(historyTitle, history);
      const refresh = button('Refresh progress');
      toolbar.append(historyLabel, refresh);
      const overview = element('div', 'sf-image-batch-overview');
      const controls = element('div', 'sf-image-analysis-actions');
      const resume = button('Resume batch', 'sf-image-analysis-primary');
      const pause = button('Pause batch');
      const cancel = button('Cancel batch');
      controls.append(resume, pause, cancel);
      const reviewBar = element('div', 'sf-image-batch-review-bar');
      const selectAll = button('Select reviewable drafts');
      const approve = button('Approve selected analyses', 'sf-image-analysis-primary');
      const selectionStatus = element('span');
      selectionStatus.setAttribute('aria-live', 'polite');
      reviewBar.append(selectAll, approve, selectionStatus);
      const list = element('div', 'sf-image-batch-list');
      context.body.append(creation, hint, toolbar, overview, controls, reviewBar, list);
      let job = null;
      let jobs = [];
      let loaded = false;
      let loading = false;
      let updating = false;
      let stepping = false;
      let driving = false;
      let actionEpoch = 0;
      let readEpoch = 0;
      let controlPending = null;
      let pendingCreate = null;
      const checked = new Map();

      const reviewable = item => item.status === 'completed' && item.analysis_status === 'draft' &&
        privateImageId.test(item.analysis_id || '') && Number.isInteger(item.version) && item.analysis;
      const unavailable = () => !loaded || loading || updating || stepping || driving;

      function syncControls() {
        const busy = unavailable();
        selected.disabled = busy;
        missing.disabled = busy;
        refresh.disabled = loading || updating || stepping || driving;
        history.disabled = busy || jobs.length === 0;
        controls.hidden = !job;
        resume.disabled = busy || !['running', 'paused'].includes(job?.status) || !(job.counts?.pending > 0) || job.counts?.processing > 0;
        pause.disabled = !job || updating || job.status !== 'running';
        cancel.disabled = !job || updating || !['running', 'paused'].includes(job.status);
        reviewBar.hidden = !job;
        selectAll.disabled = busy || !(job?.items || []).some(reviewable);
        approve.disabled = busy || checked.size === 0;
        selectionStatus.textContent = checked.size + ' selected';
        const heading = overview.querySelector('h3');
        if (heading) heading.textContent = job.status === 'running' && !driving
          ? job.counts?.processing > 0 ? 'Waiting for the current image' : 'Batch ready to resume'
          : 'Batch ' + job.status;
        for (const input of list.querySelectorAll('input')) input.disabled = busy;
        for (const control of list.querySelectorAll('button')) control.disabled = busy;
      }

      function renderHistory() {
        history.replaceChildren();
        if (!jobs.length) {
          const option = element('option', '', 'No saved batches');
          option.value = '';
          history.append(option);
        }
        for (const saved of jobs) {
          const label = String(saved.created_at || 'Saved batch').replace('T', ' ').slice(0, 19) + ' · ' + saved.status;
          const option = element('option', '', label);
          option.value = saved.id;
          history.append(option);
        }
        history.value = job?.id || '';
      }

      function renderItems() {
        list.replaceChildren();
        if (!job?.items?.length) {
          list.append(element('p', 'sf-image-analysis-hint', job ? 'This batch contains no images to analyze.' : 'Choose selected images or start a batch for images with missing analysis.'));
          return;
        }
        for (const item of job.items) {
          const card = element('article', 'sf-image-batch-item');
          const title = item.title || 'Private image';
          const heading = element('div', 'sf-image-batch-item-heading');
          if (reviewable(item)) {
            const label = element('label', 'sf-image-batch-select');
            const input = element('input');
            input.type = 'checkbox';
            label.append(input, element('span', '', 'Approve ' + title));
            input.addEventListener('change', () => {
              if (input.checked) checked.set(item.image_id, {
                image_id: item.image_id, analysis_id: item.analysis_id, version: item.version,
                analysis: JSON.parse(JSON.stringify(item.analysis))
              });
              else checked.delete(item.image_id);
              syncControls();
            });
            heading.append(label);
          } else heading.append(element('h3', '', title));
          heading.append(element('span', 'sf-image-batch-item-status', item.status === 'completed' ? 'Draft ready for review' : item.status));
          card.append(heading);
          const url = imageUrl({id: item.image_id, title}, options);
          if (url) {
            const preview = element('img', 'sf-image-batch-thumbnail');
            preview.alt = '';
            preview.src = url;
            preview.loading = 'lazy';
            preview.addEventListener('error', () => preview.remove());
            card.append(preview);
          }
          const content = element('div', 'sf-image-batch-item-content');
          if (item.analysis) {
            const details = element('details');
            details.append(element('summary', '', 'Review analysis'));
            const data = element('dl');
            for (const field of fields) {
              const value = item.analysis[field.key];
              if (!value || Array.isArray(value) && !value.length) continue;
              data.append(element('dt', '', field.label), element('dd', '', Array.isArray(value) ? value.join(', ') : value));
            }
            details.append(data);
            content.append(details);
          }
          if (item.error) content.append(element('p', 'sf-image-analysis-hint', String(item.error)));
          if (item.status !== 'pending') {
            const edit = button(item.status === 'completed' ? 'Edit details' : 'Open analysis');
            edit.addEventListener('click', async () => {
              if (unavailable()) return;
              if (job.status === 'running' && !await changeStatus('pause')) return;
              await open({id: item.image_id, title}, {...options, nested: true, signal: context.signal});
              if (context.active()) {
                await loadJob(job.id);
                refresh.focus({preventScroll: true});
              }
            });
            content.append(edit);
          }
          card.append(content);
          list.append(card);
        }
      }

      function renderOverview() {
        overview.replaceChildren();
        if (!job) return;
        const counts = job.counts || {};
        overview.append(element('h3', '', 'Batch ' + job.status));
        const metrics = element('div', 'sf-image-batch-counts');
        for (const [key, label] of [['total', 'Images'], ['pending', 'Waiting'], ['processing', 'Processing'],
          ['completed', 'Drafts'], ['failed', 'Failed'], ['skipped', 'Skipped'], ['reviewed', 'Approved']]) {
          const stat = element('span');
          stat.append(element('strong', '', Number(counts[key] || 0).toLocaleString()), element('span', '', label));
          metrics.append(stat);
        }
        const progress = element('progress');
        progress.max = Math.max(1, Number(counts.total || 0));
        progress.value = ['completed', 'failed', 'skipped', 'reviewed'].reduce((sum, key) => sum + Number(counts[key] || 0), 0);
        progress.setAttribute('aria-label', 'Image analysis progress');
        overview.append(metrics, progress);
        const usage = job.usage || {};
        overview.append(element('p', 'sf-image-analysis-hint', Number(usage.active_tokens_used || 0).toLocaleString() + ' Active Tokens used · ' +
          Number(usage.input_tokens || 0).toLocaleString() + ' input tokens · ' + Number(usage.output_tokens || 0).toLocaleString() + ' output tokens.'));
        if (job.remaining_count > 0) overview.append(element('p', 'sf-image-analysis-hint', Number(job.remaining_count).toLocaleString() + ' additional images remain outside this batch.'));
      }

      function applyJob(next) {
        job = next || null;
        checked.clear();
        if (job) jobs = [job, ...jobs.filter(saved => saved.id !== job.id)];
        renderHistory();
        renderOverview();
        renderItems();
        syncControls();
      }

      async function loadJob(id, quiet = false) {
        const epoch = actionEpoch;
        const read = ++readEpoch;
        loading = true;
        syncControls();
        if (!quiet) context.status.textContent = 'Loading saved batch progress…';
        try {
          const result = await context.api('image-analysis-batch.php' + (id ? '?id=' + encodeURIComponent(id) : ''));
          if (!context.active() || epoch !== actionEpoch || read !== readEpoch) return;
          if (Array.isArray(result.jobs)) jobs = result.jobs;
          loaded = true;
          applyJob(result.job);
          if (!quiet) context.status.textContent = !job ? 'No saved image analysis batches yet.' :
            job.counts?.pending > 0 && ['running', 'paused'].includes(job.status) ? 'Saved progress loaded. Resume when you’re ready to continue.' : 'Saved progress loaded. Review the saved results.';
        } catch (error) {
          if (context.active()) context.status.textContent = error.message || 'Saved batch progress could not be loaded.';
        } finally {
          if (read === readEpoch) loading = false;
          if (context.active()) syncControls();
        }
      }

      async function drive() {
        driving = true;
        syncControls();
        while (context.active() && driving && job?.status === 'running') {
          const item = (job.items || []).find(entry => entry.status === 'pending');
          if (!item || job.counts?.processing > 0 || !privateImageId.test(item.request_id || '')) {
            driving = false;
            context.status.textContent = job.counts?.processing > 0 ? 'An image is still processing. Refresh progress before resuming.' : 'This batch has no pending images to analyze.';
            break;
          }
          const epoch = actionEpoch;
          stepping = true;
          syncControls();
          context.status.textContent = 'Analyzing ' + (item.title || 'one image') + '…';
          try {
            const result = await context.api('image-analysis-batch.php', {action: 'step', id: job.id, request_id: item.request_id});
            if (!context.active()) return;
            if (epoch !== actionEpoch) {
              await controlPending;
              if (context.active()) await loadJob(job.id, true);
              break;
            }
            applyJob(result.job);
            if (job?.status !== 'running' || job.counts?.processing > 0) {
              driving = false;
              const message = {paused: 'Batch paused. Resume when you’re ready.', canceled: 'Batch canceled.'}[job?.status];
              if (message) context.status.textContent = message;
              else if (job?.counts?.processing > 0) context.status.textContent = 'An image is still processing. Refresh progress before resuming.';
            }
            else if (job.items?.some(entry => entry.request_id === item.request_id && entry.status === 'pending')) {
              driving = false;
              context.status.textContent = 'This image has not advanced. Refresh saved progress before resuming.';
            }
          } catch (error) {
            driving = false;
            if (context.active()) {
              await loadJob(job.id, true);
              context.status.textContent = 'The batch stopped. ' + (error.message || 'The connection was interrupted.') + ' Check saved progress before resuming.';
            }
          } finally {
            stepping = false;
            if (context.active()) syncControls();
          }
        }
        driving = false;
        if (context.active()) {
          if (job?.status === 'completed') context.status.textContent = 'Batch analysis finished. Review the saved results.';
          syncControls();
        }
      }

      async function changeStatus(action) {
        if (!job || updating) return false;
        driving = false;
        actionEpoch++;
        updating = true;
        syncControls();
        context.status.textContent = action === 'pause' ? 'Pausing after the current image…' : action === 'cancel' ? 'Canceling the remaining batch…' : 'Resuming saved batch…';
        const request = context.api('image-analysis-batch.php', {action, id: job.id});
        const pending = request.catch(() => null);
        controlPending = pending;
        try {
          const result = await request;
          if (!context.active()) return false;
          applyJob(result.job);
          context.status.textContent = action === 'pause' ? 'Batch paused. An in-flight image may still finish.' : action === 'cancel' ? 'Batch canceled. Any in-flight result remains saved.' : 'Batch resumed.';
          return true;
        } catch (error) {
          if (context.active()) {
            await loadJob(job.id, true);
            context.status.textContent = error.message || 'The batch status could not be changed. Refresh saved progress.';
          }
          return false;
        } finally {
          updating = false;
          if (controlPending === pending) controlPending = null;
          if (context.active()) syncControls();
        }
      }

      async function create(mode) {
        if (unavailable()) return;
        updating = true;
        actionEpoch++;
        syncControls();
        context.status.textContent = 'Preparing your image analysis batch…';
        if (!pendingCreate || pendingCreate.mode !== mode) pendingCreate = {mode, requestId: newRequestId()};
        try {
          const result = await context.api('image-analysis-batch.php', {action: 'create', request_id: pendingCreate.requestId,
            mode, ids: mode === 'selected' ? selectedIds : []});
          if (!context.active()) return;
          pendingCreate = null;
          applyJob(result.job);
          updating = false;
          if (job?.status === 'running') await drive();
        } catch (error) {
          if (context.active()) {
            await loadJob(null, true);
            context.status.textContent = error.message || 'The batch could not be created. Refresh saved batches before trying again.';
          }
        } finally {
          updating = false;
          if (context.active()) syncControls();
        }
      }

      selected.addEventListener('click', () => void create('selected'));
      missing.addEventListener('click', () => void create('missing'));
      pause.addEventListener('click', () => void changeStatus('pause'));
      cancel.addEventListener('click', () => void changeStatus('cancel'));
      resume.addEventListener('click', async () => { if (await changeStatus('resume') && context.active()) await drive(); });
      refresh.addEventListener('click', () => void loadJob(job?.id));
      history.addEventListener('change', () => void loadJob(history.value));
      selectAll.addEventListener('click', () => {
        for (const input of list.querySelectorAll('input')) {
          input.checked = true;
          input.dispatchEvent(new Event('change'));
        }
      });
      approve.addEventListener('click', async () => {
        if (unavailable() || !checked.size) return;
        updating = true;
        actionEpoch++;
        syncControls();
        context.status.textContent = 'Approving the selected analysis versions…';
        try {
          const result = await context.api('image-analysis-batch.php', {action: 'review', id: job.id, items: Array.from(checked.values())});
          if (!context.active()) return;
          applyJob(result.job);
          const results = Array.isArray(result.review_results) ? result.review_results : [];
          const approved = results.filter(item => item.ok === true).length;
          const failed = results.length - approved;
          context.status.textContent = approved + ' analyses approved.' + (failed ? ' ' + failed + ' changed or could not be approved. Review their latest details.' : '');
          if (approved) notifySaved(options, {job, review_results: results});
        } catch (error) {
          if (context.active()) {
            await loadJob(job.id, true);
            context.status.textContent = error.message || 'The selected versions could not be approved. Check saved progress.';
            if (job?.counts?.reviewed > 0) notifySaved(options, {job});
          }
        } finally {
          updating = false;
          if (context.active()) syncControls();
        }
      });
      syncControls();
      void loadJob();
    });
  }

  window.SFCanvasImageAnalysis = Object.freeze({open, openPreferences, openBatch});
})();
