/* Pixel-derived palettes for trusted media surfaces. */
(() => {
  'use strict';
  const version = 'color-thief@3.5.0/sf1';
  const maxBytes = 24 * 1024 * 1024;
  const supported = /^image\/(?:jpeg|png|webp|gif|avif)$/i;
  const hexColor = /^#[a-f0-9]{6}$/;
  const cache = new Map();

  function validPalette(value) {
    return value?.version === version && Array.isArray(value.colors) && value.colors.length <= 6 &&
      value.colors.every(color => typeof color === 'string' && hexColor.test(color)) &&
      (value.status === 'ready' ? value.colors.length > 0 : value.status === 'insufficient_pixels' && value.colors.length === 0);
  }

  function validBlob(blob) {
    return blob instanceof Blob && blob.size > 0 && blob.size <= maxBytes && supported.test(blob.type);
  }

  async function fingerprint(blob) {
    if (!globalThis.crypto?.subtle) return '';
    const digest = await globalThis.crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
    return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
  }

  function paletteFromImage(image) {
    const width = image.naturalWidth, height = image.naturalHeight;
    if (!width || !height) throw new Error('Artwork cannot be decoded.');
    const scale = Math.min(1, 128 / Math.max(width, height));
    const sample = document.createElement('canvas');
    sample.width = Math.max(1, Math.round(width * scale));
    sample.height = Math.max(1, Math.round(height * scale));
    const context = sample.getContext('2d', {willReadFrequently: true});
    if (!context) throw new Error('Artwork canvas is unavailable.');
    context.drawImage(image, 0, 0, sample.width, sample.height);
    const data = context.getImageData(0, 0, sample.width, sample.height);
    const pixels = data.data;
    let opaque = 0;
    const buckets = new Set();
    for (let i = 0; i < pixels.length; i += 4) {
      if (pixels[i + 3] < 125) continue;
      opaque++;
      buckets.add((pixels[i] >> 4) * 256 + (pixels[i + 1] >> 4) * 16 + (pixels[i + 2] >> 4));
    }
    if (opaque < Math.max(12, pixels.length / 200) || buckets.size < 2) return [];
    const result = window.ColorThief.getPaletteSync(data, {colorCount: 6, quality: 1, ignoreWhite: false});
    return [...new Set((result ?? []).map(color => color.hex().toLowerCase()).filter(color => hexColor.test(color)))].slice(0, 6);
  }

  async function extractPixels(blob, sha256) {
    const url = URL.createObjectURL(blob);
    try {
      const image = new Image();
      const loaded = new Promise((resolve, reject) => { image.onload = resolve; image.onerror = reject; });
      image.src = url;
      await loaded;
      const colors = Object.freeze(paletteFromImage(image));
      return Object.freeze({version, sha256, status: colors.length ? 'ready' : 'insufficient_pixels', colors});
    } finally { URL.revokeObjectURL(url); }
  }

  async function extract(blob) {
    if (!validBlob(blob) || typeof window.ColorThief?.getPaletteSync !== 'function') return null;
    try {
      const sha256 = await fingerprint(blob);
      if (!sha256) return await extractPixels(blob, '');
      const existing = cache.get(sha256);
      if (existing) return await existing;
      const extraction = extractPixels(blob, sha256);
      cache.set(sha256, extraction);
      if (cache.size > 32) cache.delete(cache.keys().next().value);
      try { return await extraction; }
      catch (error) {
        if (cache.get(sha256) === extraction) cache.delete(sha256);
        throw error;
      }
    } catch (_) { return null; }
  }

  async function fetchBlob(url, signal) {
    try {
      const target = new URL(url, document.baseURI);
      if (target.origin !== location.origin) return null;
      const response = await fetch(target, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal});
      if (!response.ok || !supported.test((response.headers.get('content-type') ?? '').split(';', 1)[0]) ||
        Number(response.headers.get('content-length')) > maxBytes) return null;
      const blob = await response.blob();
      return validBlob(blob) ? blob : null;
    } catch (_) { return null; }
  }

  async function forArtwork(url, signal) {
    try {
      const target = new URL(url, document.baseURI);
      if (target.origin !== location.origin) return null;
      if (target.pathname.endsWith('/api/media.php')) {
        const paletteUrl = new URL(target);
        paletteUrl.searchParams.set('palette', '1');
        const response = await fetch(paletteUrl, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal});
        if (!response.ok) return null;
        if (response.headers.get('content-type')?.includes('application/json')) {
          const data = await response.json();
          if (validPalette(data.palette)) return data.palette;
        }
      }
      const blob = await fetchBlob(target, signal);
      return signal?.aborted ? null : await extract(blob);
    } catch (_) { return null; }
  }

  window.SFArtworkPalette = Object.freeze({validPalette, extract, fetchBlob, forArtwork});
})();
