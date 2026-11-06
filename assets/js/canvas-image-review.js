(() => {
  'use strict';

  const imageId = /^(?:[a-f0-9]{32}|ref-[a-f0-9]{32})$/;
  let current = null;

  function button(label, className = '') {
    const element = document.createElement('button');
    element.type = 'button';
    element.textContent = label;
    element.className = className;
    return element;
  }

  function openDialog(options, render) {
    if (options.signal?.aborted) return Promise.resolve(null);
    const opener = current?.opener || document.activeElement;
    current?.finish(null, false);

    return new Promise(resolve => {
      const dialog = document.createElement('dialog');
      dialog.className = 'sf-canvas-image-review' + (options.compact ? ' sf-canvas-image-review-compact' : '');
      dialog.setAttribute('aria-labelledby', 'canvasImageReviewTitle');
      dialog.setAttribute('aria-describedby', 'canvasImageReviewDescription');
      dialog.innerHTML = '<form class="sf-canvas-image-review-panel">' +
        '<header><h2 id="canvasImageReviewTitle"></h2><p id="canvasImageReviewDescription"></p></header>' +
        '<div class="sf-canvas-image-review-body"></div><footer></footer></form>';
      dialog.querySelector('h2').textContent = options.title;
      dialog.querySelector('header p').textContent = options.description;
      const form = dialog.querySelector('form');
      let settled = false;

      function finish(value, restoreFocus = true) {
        if (settled) return;
        settled = true;
        options.signal?.removeEventListener('abort', aborted);
        window.removeEventListener('pagehide', leaving);
        if (current?.dialog === dialog) current = null;
        if (dialog.open) dialog.close();
        dialog.remove();
        if (restoreFocus && opener?.isConnected) opener.focus({preventScroll: true});
        resolve(value);
      }
      const aborted = () => finish(null);
      const leaving = () => finish(null, false);
      current = {dialog, opener, finish};
      form.addEventListener('submit', event => event.preventDefault());
      dialog.addEventListener('cancel', event => {
        event.preventDefault();
        finish(null);
      });
      dialog.addEventListener('close', () => finish(null));
      dialog.addEventListener('click', event => {
        if (event.target === dialog) finish(null);
      });
      dialog.addEventListener('keydown', event => event.stopPropagation());
      options.signal?.addEventListener('abort', aborted, {once: true});
      window.addEventListener('pagehide', leaving, {once: true});

      try {
        const focus = render({
          dialog, form, finish,
          body: dialog.querySelector('.sf-canvas-image-review-body'),
          footer: dialog.querySelector('footer'),
          active: () => !settled
        });
        if (options.signal?.aborted) {
          finish(null);
          return;
        }
        document.body.append(dialog);
        dialog.showModal();
        focus?.focus({preventScroll: true});
      } catch (_) {
        finish(null);
      }
    });
  }

  function choose(assets, options = {}) {
    const seen = new Set();
    const images = [];
    for (const asset of Array.isArray(assets) ? assets : []) {
      if (typeof asset?.id !== 'string' || !imageId.test(asset.id) || seen.has(asset.id)) continue;
      try {
        const path = options.imageUrl ? options.imageUrl(asset) : 'api/agent-canvas-image.php?id=' + encodeURIComponent(asset.id);
        const url = new URL(path, document.baseURI);
        if (!['http:', 'https:'].includes(url.protocol) || url.origin !== window.location.origin || url.username || url.password) continue;
        images.push({asset, url: url.href});
        seen.add(asset.id);
      } catch (_) {
        // An invalid image reference cannot become a selectable result.
      }
    }
    if (!images.length) return Promise.resolve(null);

    return openDialog({
      signal: options.signal,
      title: options.title || 'Choose an image',
      description: 'Compare the images and choose one to use on the canvas.'
    }, ({body, footer, form, finish, active}) => {
      const group = document.createElement('fieldset');
      group.className = 'sf-canvas-image-review-options' + (images.length > 2 ? ' sf-canvas-image-review-batch' : '');
      const legend = document.createElement('legend');
      legend.className = 'sf-canvas-image-review-sr';
      legend.textContent = 'Canvas image options';
      group.append(legend);
      const cancel = button('Cancel');
      const apply = button('Use selected image', 'sf-canvas-image-review-primary');
      apply.type = 'submit';
      apply.disabled = true;
      footer.append(cancel, apply);
      let selected = null;

      images.forEach(({asset, url}, position) => {
        const card = document.createElement('label');
        card.className = 'sf-canvas-image-review-card';
        const preview = document.createElement('div');
        preview.className = 'sf-canvas-image-review-preview';
        const image = document.createElement('img');
        image.alt = '';
        image.decoding = 'async';
        image.hidden = true;
        const status = document.createElement('span');
        status.textContent = 'Loading preview…';
        status.setAttribute('role', 'status');
        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'canvas-image-review';
        radio.value = asset.id;
        radio.disabled = true;
        const title = document.createElement('span');
        title.className = 'sf-canvas-image-review-card-title';
        const number = document.createElement('strong');
        const index = Number.isInteger(asset.index) && asset.index > 0 ? asset.index : position + 1;
        number.textContent = 'Option ' + index;
        const detail = document.createElement('span');
        detail.textContent = typeof asset.title === 'string' ? asset.title.trim().slice(0, 240) : '';
        title.append(number, detail);
        preview.append(image, status);
        card.append(preview, radio, title);
        group.append(card);
        image.addEventListener('load', () => {
          if (!active()) return;
          image.hidden = false;
          status.hidden = true;
          radio.disabled = false;
        });
        image.addEventListener('error', () => {
          if (!active()) return;
          image.hidden = true;
          status.hidden = false;
          status.textContent = 'Preview unavailable';
          radio.disabled = true;
          radio.checked = false;
          if (selected === asset.id) {
            selected = null;
            apply.disabled = true;
          }
        });
        radio.addEventListener('change', () => {
          if (!active() || radio.disabled) return;
          selected = asset.id;
          apply.disabled = false;
        });
        image.src = url;
      });
      body.append(group);
      cancel.addEventListener('click', () => finish(null));
      form.addEventListener('submit', () => {
        if (selected && !apply.disabled) finish(selected);
      });
      return cancel;
    });
  }

  function confirmGeneration(preflight, options = {}) {
    const remaining = preflight?.today_remaining;
    const perRequestMax = Number.isInteger(preflight?.max_count) ? Math.min(4, Math.max(1, preflight.max_count)) : 4;
    const enabled = preflight?.generation_enabled === true && preflight.daily_cap !== 0;
    const maxCount = enabled
      ? remaining === null ? perRequestMax : Math.min(perRequestMax, Math.max(0, Math.floor(Number(remaining) || 0)))
      : 0;
    const requestedCount = Math.min(perRequestMax, Math.max(1, Math.floor(Number(preflight?.requested_count) || 1)));

    return openDialog({
      signal: options.signal,
      compact: true,
      title: options.title || 'Create canvas images',
      description: 'Choose how many images to generate before continuing.'
    }, ({body, footer, form, finish}) => {
      const limit = document.createElement('p');
      limit.className = 'sf-canvas-image-review-limit';
      if (preflight?.generation_enabled !== true) limit.textContent = 'Image generation is currently disabled.';
      else if (preflight.daily_cap === 0) limit.textContent = 'Image generation is disabled for your account.';
      else if (remaining === null) limit.textContent = 'Your account has unlimited image generation.';
      else if (maxCount === 0) limit.textContent = 'Your daily image generation limit has been reached. The limit resets at midnight UTC.';
      else limit.textContent = Number(remaining).toLocaleString() + ' image attempts remaining today. The limit resets at midnight UTC.';
      const countLabel = document.createElement('label');
      countLabel.className = 'sf-canvas-image-review-count';
      const countTitle = document.createElement('span');
      countTitle.id = 'canvasImageReviewCountLabel';
      countTitle.textContent = 'Number of images';
      const count = document.createElement('select');
      count.name = 'image_count';
      count.setAttribute('aria-labelledby', countTitle.id);
      count.disabled = maxCount === 0;
      for (let value = 1; value <= Math.max(1, maxCount); value++) {
        const option = document.createElement('option');
        option.value = String(value);
        option.textContent = value + (value === 1 ? ' image' : ' images');
        count.append(option);
      }
      count.value = String(Math.min(requestedCount, Math.max(1, maxCount)));
      countLabel.append(countTitle, count);
      const reviewLabel = document.createElement('label');
      reviewLabel.className = 'sf-canvas-image-review-check';
      reviewLabel.hidden = perRequestMax < 2;
      const review = document.createElement('input');
      review.type = 'checkbox';
      review.name = 'review_images';
      review.checked = preflight?.review_images === true && Number(count.value) >= 2;
      const reviewTitle = document.createElement('span');
      reviewTitle.textContent = 'Choose one variation before applying';
      reviewLabel.append(review, reviewTitle);
      const summary = document.createElement('p');
      summary.className = 'sf-canvas-image-review-generation-summary';
      summary.setAttribute('aria-live', 'polite');
      const syncCount = () => {
        review.disabled = maxCount < 2 || Number(count.value) < 2;
        if (review.disabled) review.checked = false;
        summary.textContent = maxCount === 0 ? '' : 'This request will generate ' + count.value + (Number(count.value) === 1 ? ' image.' : ' images.');
      };
      syncCount();
      count.addEventListener('change', syncCount);
      body.append(limit, countLabel, reviewLabel, summary);
      const cancel = button('Cancel');
      const saved = button('Use saved images');
      const proceed = button('Continue', 'sf-canvas-image-review-primary');
      proceed.type = 'submit';
      proceed.disabled = maxCount === 0;
      footer.append(cancel, saved, proceed);
      cancel.addEventListener('click', () => finish(null));
      saved.addEventListener('click', () => finish({use_saved: true}));
      form.addEventListener('submit', () => {
        const chosenCount = Number(count.value);
        if (chosenCount >= 1 && chosenCount <= maxCount) finish({count: chosenCount, review: chosenCount >= 2 && review.checked});
      });
      return maxCount > 0 ? count : saved;
    });
  }

  window.SFCanvasImageReview = Object.freeze({choose, confirmGeneration});
})();
